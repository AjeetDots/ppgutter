<?php

use FluentForm\Framework\Support\Arr;

defined('ABSPATH') or die;

/**
 * Compatibility facade for the interfaces owned by FluentForm Free.
 *
 * Free 6.0.0 through the current release calls this global class from WP-CLI.
 * The underlying License engine moves to FluentCart while the supported Free
 * activation and status calls keep their historical global names and shapes.
 */
class FluentFormAddOnChecker
{
    /**
     * @var \FluentFormPro\FluentUpdater\FluentLicensing
     */
    private $licenseManager;

    /**
     * @var self|null
     */
    private static $_instance = null;

    /**
     * @param \FluentFormPro\FluentUpdater\FluentLicensing $licenseManager
     */
    public function __construct($licenseManager)
    {
        if (!$licenseManager instanceof \FluentFormPro\FluentUpdater\FluentLicensing) {
            throw new \InvalidArgumentException('A registered FluentForm Pro License manager is required.');
        }

        $this->licenseManager = $licenseManager;
        self::$_instance = $this;
    }

    /**
     * @return self|null
     */
    public static function getInstance()
    {
        return self::$_instance;
    }

    /**
     * Preserve the activation array/object shape read by Free's WP-CLI command.
     *
     * @param string $licenseKey
     * @return array|\WP_Error
     */
    public function tryActivateLicense($licenseKey)
    {
        $result = $this->licenseManager->activate($licenseKey);

        if (is_wp_error($result)) {
            return $result;
        }

        $response = (object)$result;
        $response->license = Arr::get($result, 'status', '');
        $response->expires = Arr::get($result, 'expires', '');

        return [
            'message'  => __('Fluent Forms Pro was successfully activated.', 'fluentformpro'),
            'response' => $response,
            'status'   => $response->license,
        ];
    }

    /**
     * Preserve the status object shape read by Free's WP-CLI command.
     *
     * @return object|false|\WP_Error
     */
    public function getRemoteLicense()
    {
        if (!$this->licenseManager->getCurrentLicenseKey()) {
            return false;
        }

        $result = $this->licenseManager->getStatus(true);

        if (is_wp_error($result)) {
            return $result;
        }

        $response = (object)$result;
        $response->license = Arr::get($result, 'status', '');
        $response->expires = Arr::get($result, 'expires', '');

        return $response;
    }
}
