<?php

namespace FluentFormPro\Services;

use FluentForm\App\Http\Controllers\AdminNoticeController;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Client for the fluentform-connect-worker service, which holds the OAuth client secrets. Wire contract: docs/CONTRACT.md there.
class RemoteAuth
{
    const IDENTITY_OPTION = '_fluentform_auth_identity';

    const REFRESH_SUPPORTED = ['google/sheets'];

    const FAILURE_PREFIX = 'ff_auth_failure_';

    const CLOCK_SKEW_OPTION = 'fluentform_auth_clock_skew';

    const CLOCK_OFFSET_OPTION = 'fluentform_auth_clock_offset';

    const SERVICE_URL = 'https://connect.fluentforms.com';

    public static function serviceUrl()
    {
        if (defined('FF_AUTH_SERVICE_URL') && FF_AUTH_SERVICE_URL) {
            return rtrim(FF_AUTH_SERVICE_URL, '/');
        }

        return self::SERVICE_URL;
    }

    public static function prepareAuth($provider, $flow, $returnUrl)
    {
        $flowId = wp_generate_uuid4();
        $verifier = wp_generate_password(32, false);
        $resultKey = self::randomBase64Url(32);

        set_transient(
            self::transientKey($provider, $flow, $flowId),
            [
                'verifier'   => $verifier,
                'result_key' => $resultKey,
            ],
            15 * MINUTE_IN_SECONDS
        );

        $query = http_build_query([
            'return_url' => $returnUrl,
            'flow_id'    => $flowId,
            'verifier'   => $verifier,
            'result_key' => $resultKey,
            'email'      => wp_get_current_user()->user_email,
        ]);

        return self::serviceUrl() . '/connect/' . rawurlencode($provider) . '/' . rawurlencode($flow) . '?' . $query;
    }

    /**
     * Verify and open the worker's sealed connect result.
     *
     * @return array|\WP_Error
     */
    public static function consumeAuth($provider, $flow, $flowId, $payload)
    {
        if (!$flowId || !$payload) {
            return self::reconnectError(__('Missing auth result data.', 'fluentformpro'));
        }

        $transientKey = self::transientKey($provider, $flow, $flowId);
        $prepared = get_transient($transientKey);
        delete_transient($transientKey); // one-shot, whatever happens below

        if (empty($prepared['verifier']) || empty($prepared['result_key'])) {
            return self::reconnectError(__('Your connection attempt has expired. Please try again.', 'fluentformpro'));
        }

        $result = self::open($payload, $prepared['result_key']);
        if (!is_array($result)) {
            return self::reconnectError(__('Could not verify the response from the connection service.', 'fluentformpro'));
        }

        $isOurs = ArrayHelper::get($result, 'verifier') === $prepared['verifier']
            && ArrayHelper::get($result, 'provider') === $provider
            && ArrayHelper::get($result, 'flow') === $flow;

        if (!$isOurs) {
            return self::reconnectError(__('This connection attempt could not be verified. Please try again.', 'fluentformpro'));
        }

        if (empty($result['ok'])) {
            return self::reconnectError(self::describeError(ArrayHelper::get($result, 'error')));
        }

        // The site keeps one identity, bound to its Google wrapped token; a Notion connect must not replace it.
        if (in_array($provider . '/' . $flow, self::REFRESH_SUPPORTED, true) && !self::maybeStoreIdentity($result)) {
            return self::reconnectError(self::missingKeysMessage());
        }

        return $result;
    }

    /**
     * Get a new access token through the worker's signed refresh route.
     *
     * @return array|\WP_Error
     */
    public static function refreshAuth($provider, $flow, $wrappedRefreshToken)
    {
        if (!in_array($provider . '/' . $flow, self::REFRESH_SUPPORTED, true)) {
            return self::unsupportedError();
        }

        if (empty($wrappedRefreshToken)) {
            return self::reconnectError(__('Missing refresh token. Please reconnect your account.', 'fluentformpro'));
        }

        $identity = self::bridgeIdentity();
        if (empty($identity['identity_id']) || empty($identity['site_secret'])) {
            return self::reconnectError(__('Your connection needs to be re-established. Please reconnect your account.', 'fluentformpro'));
        }

        $path = '/internal/refresh/' . rawurlencode($provider) . '/' . rawurlencode($flow);
        $body = wp_json_encode(['wrapped_refresh_token' => $wrappedRefreshToken]);
        $response = self::post($path, $body, self::signatureHeaders($path, $body, $identity));

        if (self::isClockSkew($response) && self::learnClockOffset($response)) {
            $response = self::post($path, $body, self::signatureHeaders($path, $body, $identity));
        }

        return self::tokenResult($response);
    }

    /**
     * One-time upgrade of a connection saved before the worker existed (raw refresh token in the option).
     *
     * @return array|\WP_Error
     */
    public static function migrateGoogleAuth($rawRefreshToken, $siteUrl)
    {
        $body = wp_json_encode([
            'refresh_token' => $rawRefreshToken,
            'site_url'      => $siteUrl,
        ]);

        return self::tokenResult(self::post('/internal/migrate/google', $body));
    }

    // Cancel and "Back to website" return without ffauth_* params; they still land on the settings page.
    public static function handleReturn($marker, $provider, $flow, $settingsUrl, callable $persist)
    {
        if (!isset($_REQUEST[$marker]) || !self::canManageIntegrations()) {
            return;
        }

        if (isset($_REQUEST['ffauth_flow_id'], $_REQUEST['ffauth_payload'])) {
            $result = self::consumeAuth(
                $provider,
                $flow,
                sanitize_text_field(wp_unslash($_REQUEST['ffauth_flow_id'])),
                sanitize_text_field(wp_unslash($_REQUEST['ffauth_payload']))
            );

            if (is_wp_error($result)) {
                self::rememberFailure($result);
            } else {
                $persist($result);
            }
        }

        wp_safe_redirect($settingsUrl);
        exit();
    }

    // Same rule as the free plugin's GlobalIntegrationPolicy, which lets the user start the connect.
    private static function canManageIntegrations()
    {
        if (current_user_can('manage_options')
            || current_user_can('fluentform_full_access')
            || current_user_can('fluentform_settings_manager')) {
            return true;
        }

        // Legacy role-level Fluent Forms access (a WP role key); granular permissions don't count.
        $roleCapability = Acl::getCurrentUserCapability();

        return $roleCapability && !in_array($roleCapability, Acl::getPermissionSet(), true);
    }

    // Free's global-settings reader serves any _fluentform_* option; the site secret must not be one of them.
    public static function hideIdentityOption($deniedKeys)
    {
        $deniedKeys[] = self::IDENTITY_OPTION;

        return $deniedKeys;
    }

    // The return handler redirects right away; the settings panel shows this on its next load.
    public static function rememberFailure(\WP_Error $error)
    {
        set_transient(self::FAILURE_PREFIX . get_current_user_id(), $error->get_error_message(), MINUTE_IN_SECONDS);
    }

    public static function pullFailure()
    {
        $key = self::FAILURE_PREFIX . get_current_user_id();
        $message = get_transient($key);
        delete_transient($key);

        return is_string($message) ? $message : '';
    }

    public static function resetClockState()
    {
        delete_option(self::CLOCK_OFFSET_OPTION);
        delete_option(self::CLOCK_SKEW_OPTION);
    }

    // Refreshes run in the background, so a clock problem can only reach the admin as a notice.
    public static function showClockSkewNotice()
    {
        if ('yes' !== get_option(self::CLOCK_SKEW_OPTION) || !self::canManageIntegrations()) {
            return;
        }

        $notice = new AdminNoticeController();
        $notice->addNotice([
            'name'    => 'ff_auth_clock_skew',
            'title'   => '',
            'message' => __('Fluent Forms could not refresh your Google Sheets connection because this server\'s clock is out of sync. Please ask your host to turn on time synchronization (NTP).', 'fluentformpro'),
            'links'   => [
                [
                    'href'     => admin_url('admin.php?page=fluent_forms_settings#general-google_sheet-settings'),
                    'btn_text' => __('Google Sheets Settings', 'fluentformpro'),
                    'btn_atts' => 'class="mr-1 el-button--success el-button--mini"',
                ],
                [
                    'href'     => admin_url('admin.php?page=fluent_forms'),
                    'btn_text' => __('Dismiss', 'fluentformpro'),
                    'btn_atts' => 'class="text-button el-button--mini ff_nag_cross" data-notice_type="temp" data-notice_name="ff_auth_clock_skew"',
                ],
            ],
        ]);
        $notice->showNotice();
    }

    // HTTP

    private static function post($path, $body, $headers = [])
    {
        return wp_remote_post(self::serviceUrl() . $path, [
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'body'    => $body,
            'timeout' => 30,
        ]);
    }

    private static function signatureHeaders($path, $body, $identity)
    {
        $timestamp = (string) (time() + (int) get_option(self::CLOCK_OFFSET_OPTION, 0));

        return [
            'X-FFAUTH-Identity'  => $identity['identity_id'],
            'X-FFAUTH-Timestamp' => $timestamp,
            'X-FFAUTH-Signature' => hash_hmac('sha256', $timestamp . '.POST.' . $path . '.' . $body, $identity['site_secret']),
        ];
    }

    private static function isClockSkew($response)
    {
        if (is_wp_error($response) || 401 !== (int) wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return 'clock_skew' === ArrayHelper::get((array) $data, 'error');
    }

    // The worker's Date header is its clock; signing with this server's offset from it survives a drifting clock.
    private static function learnClockOffset($response)
    {
        $date = wp_remote_retrieve_header($response, 'date');
        $workerTime = strtotime(is_array($date) ? (string) reset($date) : (string) $date);

        if (!$workerTime) {
            return false;
        }

        update_option(self::CLOCK_OFFSET_OPTION, $workerTime - time(), 'no');

        return true;
    }

    private static function tokenResult($response)
    {
        if (is_wp_error($response)) {
            return new \WP_Error('ff_auth_http', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : [];

        if (200 !== $status || empty($data['ok']) || empty($data['token'])) {
            return self::refreshError($status, ArrayHelper::get($data, 'error', ''));
        }

        if (!self::maybeStoreIdentity($data)) {
            return new \WP_Error('ff_auth_failed', self::missingKeysMessage());
        }

        if (get_option(self::CLOCK_SKEW_OPTION)) {
            delete_option(self::CLOCK_SKEW_OPTION);
        }

        return [
            'access_token'          => ArrayHelper::get($data, 'token.access_token'),
            'expires_in'            => ArrayHelper::get($data, 'token.expires_in'),
            'wrapped_refresh_token' => ArrayHelper::get($data, 'wrapped_refresh_token'),
        ];
    }

    // Errors

    private static function refreshError($status, $code)
    {
        if ('clock_skew' === $code) {
            update_option(self::CLOCK_SKEW_OPTION, 'yes', 'no');

            return new \WP_Error('ff_auth_failed', __('This server\'s clock is out of sync, so the connection could not be refreshed.', 'fluentformpro'));
        }

        // Match the worker's code, not a bare 502: a proxy or Cloudflare 502 must not disconnect the site.
        if (in_array($code, ['reconnect_required', 'invalid_token', 'token_identity_mismatch'], true)) {
            return self::reconnectError(__('Your connection has expired. Please reconnect your account.', 'fluentformpro'));
        }

        if (422 === $status) {
            return self::unsupportedError();
        }

        return new \WP_Error('ff_auth_failed', __('Could not refresh the connection. Please try again later.', 'fluentformpro'));
    }

    private static function reconnectError($message)
    {
        return new \WP_Error('ff_auth_reconnect', $message);
    }

    private static function unsupportedError()
    {
        return new \WP_Error('ff_auth_unsupported', __('This connection cannot be refreshed automatically.', 'fluentformpro'));
    }

    private static function describeError($code)
    {
        if ('access_denied' === $code) {
            return __('You did not complete the authorization.', 'fluentformpro');
        }

        return __('The connection could not be completed. Please try again.', 'fluentformpro');
    }

    // Identity

    private static function bridgeIdentity()
    {
        $stored = get_option(self::IDENTITY_OPTION);
        $stored = is_array($stored) ? $stored : [];
        $siteSecret = ArrayHelper::get($stored, 'site_secret', '');

        return [
            'identity_id' => ArrayHelper::get($stored, 'identity_id', ''),
            'site_secret' => $siteSecret ? PaymentHelper::decryptKey($siteSecret) : '',
        ];
    }

    // False only when there is an identity to store but it can't be encrypted; it is never stored in plaintext.
    private static function maybeStoreIdentity($data)
    {
        $identityId = ArrayHelper::get($data, 'identity_id');
        $siteSecret = ArrayHelper::get($data, 'site_secret');

        if (!$identityId || !$siteSecret) {
            return true;
        }

        $encrypted = PaymentHelper::encryptKey($siteSecret);
        if (!$encrypted) {
            return false;
        }

        update_option(self::IDENTITY_OPTION, [
            'identity_id' => $identityId,
            'site_secret' => $encrypted,
        ], 'no');

        return true;
    }

    private static function missingKeysMessage()
    {
        return __('This site is missing its WordPress security keys (LOGGED_IN_KEY and LOGGED_IN_SALT in wp-config.php), so the connection cannot be stored securely.', 'fluentformpro');
    }

    // Flow state

    private static function transientKey($provider, $flow, $flowId)
    {
        return 'ff_auth_' . $provider . '_' . $flow . '_' . get_current_user_id() . '_' . sanitize_key($flowId);
    }

    private static function randomBase64Url($bytes)
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    // Crypto -- mirrors the worker's seal(): base64url(iv[12] || ciphertext || tag[16]), AES-256-GCM, key = sha256(secret).

    private static function open($sealed, $key)
    {
        $raw = self::base64UrlDecode($sealed);
        if (false === $raw || strlen($raw) < 12 + 16) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ciphertext = substr($raw, 12, -16);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $plaintext) {
            return null;
        }

        $decoded = json_decode($plaintext, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function base64UrlDecode($value)
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        return base64_decode($padded, true);
    }
}
