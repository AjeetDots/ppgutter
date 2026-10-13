<?php

namespace FluentFormPro\FluentUpdater;

use FluentForm\Framework\Support\Arr;

defined('ABSPATH') or die;

class FluentLicensing
{
    private static $instance;

    private $config = [];

    public $settingsKey = '';

    /**
     * Informational fields the licensing API may return, mapped to the keys we
     * persist them under, so the UI can show plan, seat and customer details
     * without a remote round trip.
     */
    private $metaFields = [
        'product_title'         => 'product_title',
        'subscription_status'   => 'subscription_status',
        'customer_name'         => 'customer_name',
        'customer_email_masked' => 'customer_email_masked',
        'manage_url'            => 'manage_url',
        'grace_period_days'     => 'grace_period_days',
    ];

    public function register($config = [])
    {
        if (self::$instance) {
            return self::$instance;
        }

        if (empty($config['basename']) || empty($config['version']) || empty($config['api_url'])) {
            throw new \Exception('Invalid configuration provided for FluentLicensing. Please provide basename, version, and api_url.');
        }

        $this->config = $config;
        $baseName = Arr::get($config, 'basename', plugin_basename(__FILE__));

        $slug = Arr::get($config, 'slug', Arr::get(explode('/', $baseName), 0));
        $this->config['slug'] = (string)$slug;

        $this->settingsKey = Arr::get($config, 'settings_key', '__' . $this->config['slug'] . '_sl_info');

        if (empty($config['store_url'])) {
            $this->config['store_url'] = $this->config['api_url'];
        }

        if (empty($config['purchase_url'])) {
            $this->config['purchase_url'] = $this->config['store_url'];
        }

        $config = $this->config;

        if (empty($config['license_key']) && empty($config['license_key_callback'])) {
            $config['license_key_callback'] = function () {
                return $this->getCurrentLicenseKey();
            };
        }

        if (empty($config['site_url'])) {
            $config['site_url'] = $this->siteUrl();
        }

        if (!class_exists('\\' . __NAMESPACE__ . '\PluginUpdater')) {
            require_once __DIR__ . '/PluginUpdater.php';
        }

        new PluginUpdater($config);

        self::$instance = $this;

        return self::$instance;
    }

    public function getConfig($key)
    {
        return Arr::get($this->config, $key, '');
    }

    /**
     * @return self
     * @throws \Exception
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            throw new \Exception('Licensing is not registered. Please call register() method first.');
        }

        return self::$instance;
    }

    public function activate($licenseKey = '')
    {
        if (!$licenseKey) {
            return new \WP_Error('license_key_missing', __('License key is required for activation.', 'fluentformpro'));
        }

        $response = $this->apiRequest('activate_license', [
            'license_key'      => $licenseKey,
            'platform_version' => get_bloginfo('version'),
            'server_version'   => PHP_VERSION,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $saveData = [
            'license_key'     => $licenseKey,
            'status'          => Arr::get($response, 'status', 'valid'),
            'variation_id'    => Arr::get($response, 'variation_id', ''),
            'variation_title' => Arr::get($response, 'variation_title', ''),
            'expires'         => Arr::get($response, 'expiration_date', ''),
            'activation_hash' => Arr::get($response, 'activation_hash', ''),
            'last_checked'    => gmdate('Y-m-d H:i:s'),
        ];

        $saveData = $this->mergeRemoteMeta($saveData, $response, true);

        $this->writeOption($this->settingsKey, $saveData);

        return $saveData;
    }

    public function deactivate()
    {
        $deactivated = $this->apiRequest('deactivate_license', [
            'license_key' => $this->getCurrentLicenseKey(),
        ]);

        $this->deleteOption($this->settingsKey);

        return $deactivated;
    }

    public function getStatus($remoteFetch = false)
    {
        $currentLicense = $this->readOption($this->settingsKey, []);

        if (!$currentLicense || !is_array($currentLicense) || empty($currentLicense['license_key'])) {
            $currentLicense = $this->migrateLegacyLicense();

            if (!$currentLicense) {
                return [
                    'license_key'     => '',
                    'status'          => 'unregistered',
                    'variation_id'    => '',
                    'variation_title' => '',
                    'expires'         => '',
                    'activation_hash' => '',
                ];
            }
        }

        if (!$remoteFetch) {
            return $currentLicense;
        }

        $remoteStatus = $this->apiRequest('check_license', [
            'license_key'     => Arr::get($currentLicense, 'license_key', ''),
            'activation_hash'  => Arr::get($currentLicense, 'activation_hash', ''),
            'item_id'          => $this->getConfig('item_id'),
            'site_url'         => $this->siteUrl(),
            'platform_version' => get_bloginfo('version'),
            'server_version'   => PHP_VERSION,
        ]);

        if (is_wp_error($remoteStatus)) {
            return $remoteStatus;
        }

        $status = Arr::get($remoteStatus, 'status', 'unregistered');
        $errorType = Arr::get($remoteStatus, 'error_type', '');

        if (!empty($currentLicense['status'])) {
            $currentLicense['status'] = $status;

            if (!empty($remoteStatus['expiration_date'])) {
                $currentLicense['expires'] = sanitize_text_field($remoteStatus['expiration_date']);
            }

            if (!empty($remoteStatus['variation_id'])) {
                $currentLicense['variation_id'] = sanitize_text_field($remoteStatus['variation_id']);
            }

            if (!empty($remoteStatus['variation_title'])) {
                $currentLicense['variation_title'] = sanitize_text_field($remoteStatus['variation_title']);
            }

            // Drop meta the store no longer reports (a lifetime upgrade has no
            // subscription, a downgraded plan has a smaller seat count) so the
            // UI never shows a value the license does not carry anymore.
            $currentLicense = $this->mergeRemoteMeta($currentLicense, $remoteStatus, true);

            $currentLicense['last_checked'] = gmdate('Y-m-d H:i:s');

            $this->writeOption($this->settingsKey, $currentLicense);
        } else {
            $currentLicense['status'] = 'error';
        }

        $currentLicense['renew_url'] = Arr::get($remoteStatus, 'renew_url', '');
        $currentLicense['is_expired'] = Arr::get($remoteStatus, 'is_expired', false);

        if ($errorType) {
            $currentLicense['error_type'] = $errorType;
            $currentLicense['error_message'] = Arr::get($remoteStatus, 'message', '');
        }

        return $currentLicense;
    }

    public function getCurrentLicenseKey()
    {
        $status = $this->getStatus();
        return Arr::get($status, 'license_key', '');
    }

    /**
     * One-time copy of the old plain-string license into the structured option
     * this system reads, done on first read. The next remote check backfills the
     * rest and corrects the status; the legacy options are removed once copied.
     *
     * @return array|null the seeded license, or null when there is nothing to migrate
     */
    private function migrateLegacyLicense()
    {
        $oldLicenseKey = $this->readOption('_ff_fluentform_pro_license_key');

        if (!$oldLicenseKey) {
            return null;
        }

        $license = [
            'license_key'     => trim($oldLicenseKey),
            'status'          => $this->readOption('_ff_fluentform_pro_license_status') ?: 'unregistered',
            'variation_id'    => '',
            'variation_title' => '',
            'expires'         => '',
            'activation_hash' => '',
        ];

        $this->writeOption($this->settingsKey, $license);
        $this->deleteOption('_ff_fluentform_pro_license_key');
        $this->deleteOption('_ff_fluentform_pro_license_status');

        return $license;
    }

    /**
     * @param array $licenseData
     * @param array $response
     * @param bool $pruneMissing when true, locally stored meta absent from the
     *                           response is removed instead of kept stale.
     * @return array
     */
    private function mergeRemoteMeta($licenseData, $response, $pruneMissing = false)
    {
        foreach ($this->metaFields as $remoteKey => $localKey) {
            $value = Arr::get($response, $remoteKey);

            if ($value === null || $value === '') {
                if ($pruneMissing) {
                    unset($licenseData[$localKey]);
                }
                continue;
            }

            if ($localKey === 'manage_url') {
                $licenseData[$localKey] = esc_url_raw($value);
                continue;
            }

            $licenseData[$localKey] = sanitize_text_field($value);
        }

        return $licenseData;
    }

    public function getLicenseMessages()
    {
        $licenseDetails = $this->getStatus();

        if (is_wp_error($licenseDetails) || !is_array($licenseDetails)) {
            return false;
        }

        $status = Arr::get($licenseDetails, 'status', 'unregistered');

        // A record only becomes 'expired' after a successful remote check, and nothing
        // schedules one. A lapsed license on a site that cannot reach the API would
        // otherwise stay 'valid' - and silent - forever, which is the case this notice
        // exists for. An empty expiry date means lifetime, so it is left alone.
        if ($status === 'expired' || ($status === 'valid' && $this->hasLapsed($licenseDetails))) {
            return [
                'message'         => $this->getExpireMessage($licenseDetails),
                'type'            => 'in_app',
                'license_details' => $licenseDetails,
            ];
        }

        if ($status === 'disabled') {
            return [
                'message'         => \sprintf(
                    /* translators: 1: plugin name, 2: link to the license screen. */
                    __('The license for %1$s has been disabled. Please contact support for assistance. %2$s', 'fluentformpro'),
                    $this->getConfig('plugin_title'),
                    $this->licenseScreenLink(__('Manage License', 'fluentformpro'))
                ),
                'type'            => 'global',
                'license_details' => $licenseDetails,
            ];
        }

        if ($status !== 'valid') {
            // A stored key that has not been confirmed needs verifying, not activating.
            // Legacy migrations land here with a key already saved, and the license
            // screen words it the same way.
            if (Arr::get($licenseDetails, 'license_key')) {
                $message = \sprintf(
                    /* translators: 1: plugin name, 2: link to the license screen. */
                    __('The %1$s license needs to be verified. %2$s', 'fluentformpro'),
                    $this->getConfig('plugin_title'),
                    $this->licenseScreenLink(__('Click here to verify', 'fluentformpro'))
                );
            } else {
                $message = \sprintf(
                    /* translators: 1: plugin name, 2: link to the license screen. */
                    __('The %1$s license needs to be activated. %2$s', 'fluentformpro'),
                    $this->getConfig('plugin_title'),
                    $this->licenseScreenLink(__('Click here to activate', 'fluentformpro'))
                );
            }

            return [
                'message'         => $message,
                'type'            => 'global',
                'license_details' => $licenseDetails,
            ];
        }

        return false;
    }

    /**
     * Whether a license carries an expiry date that has already passed and is not
     * covered by its grace period.
     *
     * @param array $licenseData
     * @return bool
     */
    private function hasLapsed($licenseData)
    {
        $expiresAt = $this->expiryTimestamp($licenseData);

        return $expiresAt && $expiresAt < time() && !$this->isWithinGracePeriod($licenseData);
    }

    /**
     * Whether a lapsed license is still covered by its store-granted grace period.
     *
     * Single source of truth for the license screen and the notices, so the two can
     * never disagree about whether a license has actually expired.
     *
     * @param array $licenseData
     * @return bool
     */
    public function isWithinGracePeriod($licenseData)
    {
        if (Arr::get($licenseData, 'status') !== 'valid') {
            return false;
        }

        $expiresAt = $this->expiryTimestamp($licenseData);

        if (!$expiresAt || $expiresAt >= time()) {
            return false;
        }

        // Grace is a store entitlement; never invent it locally.
        $graceDays = (int)Arr::get($licenseData, 'grace_period_days', 0);

        return $graceDays > 0 && ($expiresAt + ($graceDays * DAY_IN_SECONDS)) > time();
    }

    /**
     * @param array $licenseData
     * @return int|false
     */
    private function expiryTimestamp($licenseData)
    {
        $expires = trim((string)Arr::get($licenseData, 'expires', ''));

        return $expires ? strtotime($expires) : false;
    }

    /**
     * @param string $text Already translated.
     * @return string
     */
    private function licenseScreenLink($text)
    {
        return '<a href="' . esc_url($this->getConfig('activate_url')) . '">' . esc_html($text) . '</a>';
    }

    /**
     * Returns unwrapped text. The caller owns the markup its channel needs.
     *
     * @param array $licenseData
     * @return string
     */
    private function getExpireMessage($licenseData)
    {
        $link = '<a href="' . esc_url($this->getConfig('activate_url')) . '"><b>'
            . esc_html__('Click Here to Renew Your License', 'fluentformpro') . '</b></a>';

        $expiresAt = $this->expiryTimestamp($licenseData);

        // A migrated legacy license stores no expiry date. Saying nothing beats
        // asserting it expired in 1970.
        if (!$expiresAt) {
            return \sprintf(
                /* translators: 1: plugin name, 2: renewal link. */
                __('Your %1$s license has expired. %2$s', 'fluentformpro'),
                $this->getConfig('plugin_title'),
                $link
            );
        }

        return \sprintf(
            /* translators: 1: plugin name, 2: expiry date, 3: renewal link. */
            __('Your %1$s license expired on %2$s. %3$s', 'fluentformpro'),
            $this->getConfig('plugin_title'),
            '<b>' . wp_date('d M Y', $expiresAt) . '</b>',
            $link
        );
    }

    private function apiRequest($action, $data = [])
    {
        $url = $this->config['api_url'];
        $fullUrl = add_query_arg(['fluent-cart' => $action], $url);

        $defaults = [
            'item_id'         => $this->config['item_id'],
            'current_version' => $this->config['version'],
            'site_url'        => $this->siteUrl(),
        ];

        $payload = wp_parse_args($data, $defaults);

        $response = wp_remote_post($fullUrl, [
            'timeout' => 15,
            'body'    => $payload,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $responseCode = wp_remote_retrieve_response_code($response);

        if (200 !== $responseCode) {
            $errorData = wp_remote_retrieve_body($response);
            $message = 'API request failed with status code: ' . $responseCode;
            if (!empty($errorData)) {
                $decodedData = json_decode($errorData, true);
                if ($decodedData) {
                    $errorData = $decodedData;
                }

                if (!empty($errorData['message'])) {
                    $message = (string)$errorData['message'];
                }
            }
            return new \WP_Error('api_error', $message, $errorData);
        }

        $responseData = json_decode(wp_remote_retrieve_body($response), true);

        if ($responseData) {
            return $responseData;
        }

        return new \WP_Error('api_error', 'API request returned an empty or not JSON response.', []);
    }

    public function getRenewUrl()
    {
        $licenseKey = $this->getCurrentLicenseKey();
        if (empty($licenseKey)) {
            return $this->getConfig('purchase_url');
        }

        return add_query_arg([
            'license_key' => $licenseKey,
            'fluent-cart' => 'renew_license',
        ], $this->getConfig('store_url'));
    }

    /**
     * Read and write the license from the same tenancy scope on every request:
     * network options when the plugin is network-active, per-site otherwise.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function readOption($key, $default = false)
    {
        if ($this->usesNetworkStorage()) {
            return get_network_option(get_main_network_id(), $key, $default);
        }

        return get_option($key, $default);
    }

    private function writeOption($key, $value)
    {
        if ($this->usesNetworkStorage()) {
            return update_network_option(get_main_network_id(), $key, $value);
        }

        return update_option($key, $value, false);
    }

    private function deleteOption($key)
    {
        if ($this->usesNetworkStorage()) {
            return delete_network_option(get_main_network_id(), $key);
        }

        return delete_option($key);
    }

    private function usesNetworkStorage()
    {
        return !empty($this->config['network_wide']) && is_multisite();
    }

    public function siteUrl()
    {
        return $this->usesNetworkStorage() ? network_site_url() : home_url();
    }
}
