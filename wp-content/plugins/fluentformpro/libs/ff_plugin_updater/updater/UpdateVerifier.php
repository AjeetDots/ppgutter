<?php

namespace FluentFormPro\FluentUpdater;

use FluentForm\Framework\Support\Arr;

defined('ABSPATH') or die;

/**
 * Verifies an update package against a signed release manifest before WordPress
 * is allowed to unzip it.
 *
 * The manifest is a small JSON blob signed with an Ed25519 key that lives offline
 * and never touches the licensing server, the CDN or CI. It rides along on the
 * update object next to the package URL, so the licensing API hands over both the
 * download and the proof - but only the offline key can mint that proof, so an
 * attacker who takes the API or the download host still cannot ship code.
 *
 * Four checks, each closing a distinct attack:
 *
 *   signature  a package we never built, served by a compromised API or CDN
 *   slug       a genuine package for another product replayed as this one
 *   version    an older signed release with a known vulnerability, served again
 *   sha256     the bytes on disk differing from the bytes that were signed
 *
 * The manifest is checked before the download starts, so a forged one costs a
 * signature verification rather than a multi-megabyte transfer.
 */
class UpdateVerifier
{
    /**
     * Trusted Ed25519 public keys compiled into the plugin.
     * Never load trust keys from runtime or network configuration. For rotation,
     * ship both keys before changing the signer, then remove the old key later.
     *
     * @var array<string, string> key id => 64-character lowercase hex public key
     */
    const TRUSTED_KEYS = [
        'wpmn-pub-key' => '0b0c3b880d064696f2636d8c72d8e4094303fc6b7907b24ea25a1745f23463c0',
    ];

    /** @var string plugin basename, e.g. fluent-community-pro/fluent-community-pro.php */
    private $basename;

    /** @var string slug as it appears in the signed manifest */
    private $slug;

    /** @var string currently installed version */
    private $version;

    /** @var callable|null returns a fresh, uncached update object from the licensing API */
    private $freshFetch;

    /** @var callable|null drops this plugin's cached version info */
    private $flushCache;

    /** @var bool one uncached retry per request, no more */
    private $refetched = false;

    public function __construct($basename, $slug, $version, $freshFetch = null, $flushCache = null)
    {
        $this->basename = $basename;
        $this->slug = $slug;
        $this->version = $version;
        $this->freshFetch = $freshFetch;
        $this->flushCache = $flushCache;
    }

    /**
     * Is there a usable release key compiled in?
     *
     * The caller decides whether verification is switched on; this answers whether
     * it is even possible. Arming the gate with no key would fail every update on
     * the site, so the two questions are asked separately and both must be yes.
     *
     * @return bool
     */
    public static function hasTrustedKeys()
    {
        return (bool) self::trustedKeys();
    }

    /**
     * Can this server check an Ed25519 signature at all?
     *
     * Normally yes: WordPress bundles sodium_compat, so the function exists even
     * with no libsodium extension. But core loads that polyfill only when
     * sodium_crypto_box() is missing, so a host that has the extension AND lists
     * sodium_crypto_sign_verify_detached in disable_functions gets neither the
     * extension function nor the polyfill.
     *
     * On such a server verification is impossible, not failing. Refusing every
     * update would leave the site unable to install anything ever again,
     * including the security fixes this is meant to protect - strictly worse than
     * where it stands today. So the gate simply does not arm, and updates behave
     * as they did before signing existed.
     *
     * This is safe to fail open on because it cannot be reached from the network:
     * it depends on PHP configuration, so anyone who can flip it already has
     * server access and has no need of the update channel.
     *
     * @return bool
     */
    public static function isSupported()
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Hook the download gate.
     *
     * Registered on every request rather than only in the admin, because cron and
     * WP-CLI run updates too and an unguarded path is the one that gets used.
     *
     * @return void
     */
    public function register()
    {
        add_filter('upgrader_pre_download', [$this, 'verifyPackage'], 10, 4);
    }

    /**
     * Verify the release manifest, download the package ourselves, and hand
     * WordPress a file it has already been proven safe to unzip.
     *
     * Returning a WP_Error aborts the update before anything is extracted.
     *
     * @param bool|string|\WP_Error $reply
     * @param string $package The package URL WordPress is about to download.
     * @param \WP_Upgrader|null $upgrader
     * @param array $hookExtra
     * @return bool|string|\WP_Error Path to the verified file, or an error.
     */
    public function verifyPackage($reply, $package, $upgrader = null, $hookExtra = [])
    {
        if (false !== $reply) {
            return $reply; // Another filter already resolved the download.
        }

        if (empty($hookExtra['plugin']) || $hookExtra['plugin'] !== $this->basename) {
            return $reply; // Not this plugin.
        }

        // A local path rather than a URL means an admin uploading a zip by hand.
        // The download gate never sees those, and refusing them would break manual
        // installs and repair flows. Note that plain http URLs are NOT waved
        // through here: for our own plugin, anything remote must be verified.
        if (!is_string($package) || !preg_match('#^https?://#i', $package)) {
            return $reply;
        }

        // Can this install verify anything at all? Asked here rather than at
        // registration because the answer only matters during an update, and
        // registration runs on every request.
        //
        // Both answers mean the same thing: no trust decision is available. A key
        // table that is empty or malformed is a build that never had one compiled
        // in, and a server without the Ed25519 primitive cannot check a signature
        // however good it is. Refusing on either would leave the site unable to
        // install anything ever again, security fixes included, which is worse
        // than the unsigned updates this replaces. So step aside and let
        // WordPress download as it did before signing existed.
        if (!self::hasTrustedKeys() || !self::isSupported()) {
            return $reply;
        }

        $manifest = $this->verifyManifest($this->updateEntry());

        // Nothing usable on the stored entry. It may have been written by something
        // other than our own updater, or cached before the API served proofs, so
        // ask the API directly before refusing.
        if (is_wp_error($manifest) && $this->canRefetch()) {
            $manifest = $this->verifyManifest($this->fetchFresh());
        }

        if (is_wp_error($manifest)) {
            return $manifest;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        // WordPress normally emits this itself in download_package(). Since we are
        // taking over the download, say so, or the screen sits silent.
        if (is_object($upgrader) && isset($upgrader->skin) && is_object($upgrader->skin)) {
            $upgrader->skin->feedback('downloading_package', $package);
        }

        $file = download_url($package, 300);

        if (is_wp_error($file)) {
            return $file;
        }

        $actual = hash_file('sha256', $file);

        if (is_string($actual) && hash_equals($manifest['sha256'], $actual)) {
            return $file;
        }

        // The download always serves the current release, so a site working from a
        // cached update check will fetch bytes that its cached manifest predates -
        // routine every time a release lands, not evidence of tampering. Ask once
        // for the current manifest and re-check the file already in hand. No second
        // download, and the bytes still have to be signed for this plugin.
        if (is_string($actual) && $this->canRefetch()) {
            $current = $this->verifyManifest($this->fetchFresh());

            if (!is_wp_error($current) && hash_equals($current['sha256'], $actual)) {
                // The cached response described a release that has been superseded.
                // Drop it so the plugins screen stops advertising the old version.
                if (is_callable($this->flushCache)) {
                    call_user_func($this->flushCache);
                }

                return $file;
            }
        }

        @unlink($file);

        return $this->reject('hash_mismatch', 'The downloaded update does not match its signature, so it was not installed. Please try again, and contact support if this keeps happening.');
    }

    /**
     * Check the signed manifest attached to the update object.
     *
     * Everything here is cheap and offline, so it runs before the download.
     *
     * @param object|null $update
     * @return array|\WP_Error The decoded manifest, or the reason it was refused.
     */
    private function verifyManifest($update)
    {
        $bytes = $this->proofBytes($update);

        if (is_wp_error($bytes)) {
            return $bytes;
        }

        $manifest = json_decode($bytes['manifest'], true);

        if (!is_array($manifest) || empty($manifest['key_id']) || !is_string($manifest['key_id'])) {
            return $this->reject('manifest_format', 'The update manifest is malformed, so the update was not installed.');
        }

        // Verify the bytes exactly as they arrived, before trusting any field inside
        // them. Never decode and re-encode first: any change in key order, escaping
        // or whitespace produces different bytes and a signature that will not verify.
        if (!$this->verifySignature($bytes['manifest'], $bytes['signature'], $manifest['key_id'])) {
            return $this->reject('bad_signature', 'This update is not signed by WPManageNinja, so it was not installed. Please contact support before installing it by hand.');
        }

        // A genuine, correctly signed package for a different product, replayed as
        // an update for this one.
        if (Arr::get($manifest, 'slug') !== $this->slug) {
            return $this->reject('slug_mismatch', 'This update package is for a different plugin, so it was not installed.');
        }

        if (empty($manifest['version']) || !is_string($manifest['version'])) {
            return $this->reject('manifest_format', 'The update manifest has no version, so the update was not installed.');
        }

        // The signed release must never be OLDER than the one being advertised.
        // That is the attack: a compromised API offers "9.9.0 available" so everyone
        // clicks, then ships a genuinely signed 2.5.0 with a known hole - newer than
        // what is installed, so the downgrade check below waves it through.
        //
        // Newer than advertised is fine, and normal: the download always serves the
        // current release, so a cached entry advertising 2.7.8 legitimately resolves
        // to a signed 2.7.9 the moment that ships. Requiring equality here would
        // block every site with a cached update check each time a release lands.
        if (!empty($update->new_version) && version_compare($manifest['version'], $update->new_version, '<')) {
            return $this->reject('version_mismatch', 'The offered update is older than the version advertised, so it was not installed.');
        }

        // Reject strictly older releases only. Equal versions stay installable so
        // reinstall and repair flows keep working, and replaying the version that is
        // already running is not an attack.
        if ($this->version && version_compare($manifest['version'], $this->version, '<')) {
            return $this->reject('downgrade', sprintf(
                'The server offered version %1$s, which is older than the installed %2$s, so it was not installed.',
                $manifest['version'],
                $this->version
            ));
        }

        if (empty($manifest['sha256']) || !is_string($manifest['sha256']) || !preg_match('/^[a-f0-9]{64}$/i', $manifest['sha256'])) {
            return $this->reject('manifest_format', 'The update manifest has no valid checksum, so the update was not installed.');
        }

        $manifest['sha256'] = strtolower($manifest['sha256']);

        return $manifest;
    }

    /**
     * Pull the raw manifest and signature off the update object.
     *
     * @param object|null $update
     * @return array|\WP_Error ['manifest' => raw bytes, 'signature' => base64]
     */
    private function proofBytes($update)
    {
        $manifest = isset($update->manifest) ? $update->manifest : '';
        $signature = isset($update->signature) ? $update->signature : '';

        if (!is_string($manifest) || !is_string($signature) || $manifest === '' || $signature === '') {
            return $this->reject('missing_signature', 'This update was served without a signature, so it was not installed. Please try again later, and contact support if this keeps happening.');
        }

        $decoded = base64_decode($manifest, true);

        if ($decoded === false || $decoded === '') {
            return $this->reject('manifest_encoding', 'The update manifest could not be read, so the update was not installed.');
        }

        return ['manifest' => $decoded, 'signature' => $signature];
    }

    /**
     * Verify a detached Ed25519 signature over the exact bytes given.
     *
     * @param string $message Exact signed bytes.
     * @param string $sigBase64 Base64 of the 64 byte detached signature.
     * @param string $keyId Key id named by the manifest.
     * @return bool
     */
    private function verifySignature($message, $sigBase64, $keyId)
    {
        // Checked at registration too. Repeated here because a false from this
        // method reads as "not signed by us", and on a server that cannot verify
        // at all that would be the wrong conclusion to act on.
        if (!self::isSupported()) {
            return false;
        }

        $keys = self::trustedKeys();

        $key = Arr::get($keys, $keyId);

        if (!$key) {
            return false;
        }

        $signature = base64_decode($sigBase64, true);

        // 64 literal rather than SODIUM_CRYPTO_SIGN_BYTES: on a partially
        // disabled sodium the function can exist while the constant does not,
        // and an undefined constant is a fatal on PHP 8.
        if ($signature === false || strlen($signature) !== 64) {
            return false;
        }

        // hex2bin() is core PHP, unlike sodium_hex2bin(), so this needs one
        // fewer thing to be present on the host.
        $publicKey = hex2bin($key);

        if ($publicKey === false || strlen($publicKey) !== 32) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\Exception $e) {
            return false;
        } catch (\Error $e) {
            return false;
        }
    }

    /**
     * The trusted keys, minus any malformed entry.
     *
     * Filtering here means a placeholder or a mistyped key leaves verification
     * dormant rather than blocking every update on the site.
     *
     * @return array<string, string>
     */
    private static function trustedKeys()
    {
        $keys = [];

        foreach (self::TRUSTED_KEYS as $keyId => $publicKey) {
            if (is_string($publicKey) && preg_match('/^[a-f0-9]{64}$/i', $publicKey)) {
                $keys[$keyId] = strtolower($publicKey);
            }
        }

        return $keys;
    }

    /**
     * The update object our own updater put into the transient.
     *
     * @return object|null
     */
    private function updateEntry()
    {
        $transient = get_site_transient('update_plugins');

        if (is_object($transient) && isset($transient->response[$this->basename]) && is_object($transient->response[$this->basename])) {
            return $transient->response[$this->basename];
        }

        return null;
    }

    /** One uncached call to the licensing API per request, and only if we can. */
    private function canRefetch()
    {
        return !$this->refetched && is_callable($this->freshFetch);
    }

    /**
     * Ask the licensing API directly, bypassing every cache.
     *
     * @return object|null
     */
    private function fetchFresh()
    {
        $this->refetched = true;

        $fresh = call_user_func($this->freshFetch);

        return is_object($fresh) ? $fresh : null;
    }

    /**
     * @param string $code
     * @param string $message
     * @return \WP_Error
     */
    private function reject($code, $message)
    {
        // A hook rather than stored state: support tooling can listen, and a blocked
        // update needs no bookkeeping of its own because the upgrader already shows
        // this message exactly where the user clicked Update.
        do_action($this->slug . '/update_verification_failed', $this->slug, $code, $message);

        return new \WP_Error('fluent_sl_' . $code, $message);
    }
}
