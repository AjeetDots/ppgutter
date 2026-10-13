<?php

namespace FluentFormPro\FluentUpdater;

use FluentForm\Framework\Support\Arr;

defined('ABSPATH') or die;

class LicenseSettings
{
    private static $instance;

    /** @var FluentLicensing|null */
    private $licensing;

    /** @var bool */
    private $routesRegistered = false;

    /**
     * @param FluentLicensing $licensing
     * @return self
     */
    public function register($licensing)
    {
        if (self::$instance) {
            return self::$instance;
        }

        $this->licensing = $licensing;
        add_filter('fluentform/global_settings_components', [$this, 'addLicenseComponent']);
        add_action('admin_init', [$this, 'registerLicenseNotices']);

        if (defined('FLUENTFORM_VERSION') && version_compare(FLUENTFORM_VERSION, '6.2.13', '>=')) {
            // New Free owns the Vue renderer; Pro supplies its protected REST API.
            // Not here: touching the router during plugins_loaded builds the
            // framework Request before wp_magic_quotes() slashes $_POST. Free
            // declares its own routes inside rest_api_init; priority 9 keeps this
            // declaration ahead of the framework's flush at 10.
            add_action('rest_api_init', [$this, 'registerLicenseRoutes'], 9);
        } else {
            // Old Free has no Vue renderer, so keep its historical PHP page usable.
            require_once __DIR__ . '/LegacyLicensePage.php';
            (new LegacyLicensePage($this))->register();
        }

        self::$instance = $this;

        return $this;
    }

    /**
     * @return self|null
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * Register the REST API consumed by Free's Vue license screen.
     *
     * @return void
     */
    public function registerLicenseRoutes()
    {
        if ($this->routesRegistered || !function_exists('wpFluentForm')) {
            return;
        }

        $router = wpFluentForm('router');

        if (!$router || !method_exists($router, 'group')) {
            return;
        }

        require_once __DIR__ . '/LicenseController.php';
        require_once __DIR__ . '/LicensePolicy.php';

        $this->routesRegistered = true;

        $router->prefix('license')
            ->namespace(__NAMESPACE__)
            ->withPolicy(LicensePolicy::class)
            ->group(function ($router) {
                $router->get('/', 'LicenseController@status');
                $router->post('/', 'LicenseController@activate');
                $router->delete('/', 'LicenseController@deactivate');
            });
    }

    /**
     * Supply the Vue screen with an allowlisted license payload.
     *
     * @param bool $verify
     * @return array
     */
    public function provideLicenseStatus($verify = false)
    {
        $result = $this->licensing->getStatus((bool)$verify);

        if (is_wp_error($result)) {
            $license = $this->formatLicenseData($this->licensing->getStatus());
            $license['error_message'] = __(
                'The License status could not be refreshed. Your saved License was preserved.',
                'fluentformpro'
            );

            return $license;
        }

        return $this->formatLicenseData($result);
    }

    /**
     * @param string $licenseKey
     * @return array|\WP_Error
     */
    public function activateLicense($licenseKey)
    {
        $licenseKey = is_scalar($licenseKey) ? sanitize_text_field((string)$licenseKey) : '';

        if (!$licenseKey) {
            return new \WP_Error(
                'license_key_missing',
                __('Please enter a License key.', 'fluentformpro')
            );
        }

        $result = $this->licensing->activate($licenseKey);

        if (is_wp_error($result)) {
            return new \WP_Error(
                'license_activation_failed',
                __('License activation was not confirmed. Check the key and try again.', 'fluentformpro')
            );
        }

        return [
            'message'      => __('Your License was activated successfully.', 'fluentformpro'),
            'license_data' => $this->formatLicenseData($result),
        ];
    }

    /**
     * @return array|\WP_Error
     */
    public function deactivateLicense()
    {
        $result = $this->licensing->deactivate();

        if (is_wp_error($result)) {
            return new \WP_Error(
                'license_deactivation_failed',
                __('The License could not be deactivated. Your saved License was preserved.', 'fluentformpro')
            );
        }

        return [
            'message'      => __('Your License was deactivated successfully.', 'fluentformpro'),
            'license_data' => $this->formatLicenseData($this->licensing->getStatus()),
        ];
    }

    public function addLicenseComponent($components)
    {
        if (!\FluentForm\App\Modules\Acl\Acl::hasPermission('fluentform_full_access')) {
            return $components;
        }

        $components['license_page'] = [
            'path'  => '/license_page',
            'title' => __('License', 'fluentformpro'),
            'query' => [
                'component' => 'license_page',
            ],
        ];

        return $components;
    }

    /**
     * Views in Free that print fluentform/dashboard_notices inline.
     *
     * On these the dashboard entry and the menu render would both print the same
     * banner, so only one of them is registered.
     */
    private static $dashboardNoticePages = [
        'fluent_forms',
        'fluent_forms_all_entries',
        'fluent_forms_reports',
    ];

    /**
     * Register the license notices.
     *
     * Routed by screen rather than broadcast, because Free calls
     * remove_all_actions('admin_notices') on its own pages (app/Hooks/actions.php).
     * Relying on admin_notices there works only while Pro happens to register after
     * Free - an ordering accident, not a contract - so Fluent Forms screens use the
     * menu hooks Free itself uses for its critical notices, and everywhere else in
     * wp-admin uses the notice hooks. Network and user admin are separate hooks; the
     * license is network-scoped, so the super admin is the one person who must see it.
     *
     * @return void
     */
    public function registerLicenseNotices()
    {
        if (!defined('FLUENTFORM_VERSION') || !$this->canManageLicense()) {
            return;
        }

        $licenseMessage = $this->licensing->getLicenseMessages();
        $message = (string)Arr::get($licenseMessage, 'message', '');

        // Whitespace-only survives a plain falsy check and renders an empty red box.
        if (trim(strip_tags($message)) === '') {
            return;
        }

        $licenseDetails = Arr::get($licenseMessage, 'license_details', []);
        $isExpired = Arr::get($licenseDetails, 'status') === 'expired';

        if ($isExpired) {
            // Historical public payload. Third parties inspect, suppress, replace and
            // style this keyed notice, so it is kept even though the menu render would
            // otherwise cover the same screens.
            add_filter('fluentform/dashboard_notices', function ($notices) use ($message) {
                // A filter with no documented return contract; a prior callback handing
                // back a string used to destroy this warning outright.
                if (!is_array($notices)) {
                    $notices = [];
                }

                $notices['license_expire'] = [
                    'type'     => 'error',
                    'message'  => $message,
                    'closable' => false,
                ];

                return $notices;
            });
        }

        $render = function () use ($message) {
            printf(
                '<div class="fluentform-admin-notice notice notice-error"><p>%1$s</p></div>',
                wp_kses_post($message)
            );
        };

        if (!\FluentForm\App\Helpers\Helper::isFluentAdminPage()) {
            add_action('admin_notices', $render);
            add_action('network_admin_notices', $render);
            add_action('user_admin_notices', $render);

            return;
        }

        // The dashboard entry above already prints on these views.
        if ($isExpired && in_array($this->currentAdminPage(), self::$dashboardNoticePages, true)) {
            return;
        }

        add_action('fluentform/global_menu', $render);
        add_action('fluentform/after_form_menu', $render);
    }

    /**
     * Who may be shown the license state.
     *
     * On multisite the license is stored network-wide, so it belongs to the super
     * admin. Acl::hasPermission() grants through manage_options, which every subsite
     * administrator holds on their own site - they would get a network-wide nag
     * pointing at a screen where they cannot act.
     *
     * @return bool
     */
    private function canManageLicense()
    {
        if (is_multisite()) {
            return is_super_admin();
        }

        return \FluentForm\App\Modules\Acl\Acl::hasPermission('fluentform_full_access');
    }

    /**
     * @return string
     */
    private function currentAdminPage()
    {
        if (function_exists('wpFluentForm')) {
            return (string)wpFluentForm('request')->get('page');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
        return isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    }

    /**
     * Return an allowlisted browser payload. Neither the License key nor its
     * activation hash can enter HTML, JavaScript, logs, or an API response.
     *
     * @param array $data
     * @return array
     */
    private function formatLicenseData($data)
    {
        $licenseKey = (string)Arr::get($data, 'license_key', '');
        $activationHash = (string)Arr::get($data, 'activation_hash', '');
        $redact = function ($value) use ($licenseKey, $activationHash) {
            $value = sanitize_text_field($value);

            foreach ([$licenseKey, $activationHash] as $credential) {
                if ($credential !== '') {
                    $value = str_replace($credential, '[redacted]', $value);
                }
            }

            return $value;
        };
        $safeUrl = function ($value) use ($licenseKey, $activationHash) {
            $value = (string)$value;

            foreach ([$licenseKey, $activationHash] as $credential) {
                if ($credential !== '' && strpos($value, $credential) !== false) {
                    return '';
                }
            }

            return esc_url_raw($value);
        };

        $expires = $redact(Arr::get($data, 'expires', ''));
        $lastChecked = $redact(Arr::get($data, 'last_checked', ''));
        $isLifetime = strtolower($expires) === 'lifetime';
        $expiresHuman = $expires;
        $lastCheckedHuman = '';
        $daysRemaining = null;
        $inGracePeriod = false;
        $gracePeriodDays = (int)Arr::get($data, 'grace_period_days', 0);

        if ($isLifetime) {
            $expiresHuman = __('Lifetime', 'fluentformpro');
        } elseif ($expires && ($expiresAt = strtotime($expires))) {
            $expiresHuman = wp_date(get_option('date_format'), $expiresAt);
            $daysRemaining = (int)floor(($expiresAt - time()) / DAY_IN_SECONDS);

            $inGracePeriod = $this->licensing->isWithinGracePeriod($data);
        }

        if ($lastChecked && ($lastCheckedAt = strtotime($lastChecked))) {
            $lastCheckedHuman = sprintf(
                /* translators: %s: human-readable time difference, for example "2 minutes". */
                __('%s ago', 'fluentformpro'),
                human_time_diff($lastCheckedAt)
            );
        }

        $safe = [
            'status'                => $redact(Arr::get($data, 'status', 'unregistered')),
            'expires'               => $expires,
            'expires_human'         => $expiresHuman,
            'is_lifetime'           => $isLifetime,
            'is_expired'            => !$isLifetime && $daysRemaining !== null && $daysRemaining < 0 && !$inGracePeriod,
            'days_remaining'        => $daysRemaining,
            'in_grace_period'       => $inGracePeriod,
            'grace_period_days'     => $inGracePeriod ? $gracePeriodDays : null,
            'last_checked_human'    => $lastCheckedHuman,
            'variation_title'       => $redact(Arr::get($data, 'variation_title', '')),
            'product_title'         => $redact(
                Arr::get($data, 'product_title') ?: $this->licensing->getConfig('plugin_title')
            ),
            'subscription_status'   => $redact(Arr::get($data, 'subscription_status', '')),
            'customer_name'         => $redact(Arr::get($data, 'customer_name', '')),
            'customer_email_masked' => $redact(Arr::get($data, 'customer_email_masked', '')),
            'manage_url'            => $safeUrl(Arr::get($data, 'manage_url', '')),
            'renew_url'             => $safeUrl($this->licensing->getRenewUrl()),
            'purchase_url'          => $safeUrl($this->licensing->getConfig('purchase_url')),
            'account_url'           => $safeUrl($this->licensing->getConfig('account_url')),
            'support_url'           => $safeUrl($this->licensing->getConfig('support_url')),
            'license_key_masked'    => $this->maskLicenseKey($licenseKey),
        ];

        return array_filter($safe, function ($value) {
            return $value !== '' && $value !== null;
        });
    }

    /**
     * @param string $licenseKey
     * @return string
     */
    private function maskLicenseKey($licenseKey)
    {
        if ($licenseKey === '') {
            return '';
        }

        $length = strlen($licenseKey);

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($licenseKey, 0, 4)
            . str_repeat('•', min($length - 8, 20))
            . substr($licenseKey, -4);
    }
}
