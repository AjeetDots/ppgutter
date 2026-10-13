<?php

defined('ABSPATH') or die('No script kittays please!');

require_once __DIR__ . '/updater/FluentLicensing.php';
require_once __DIR__ . '/updater/FluentFormAddOnChecker.php';
require_once __DIR__ . '/updater/LicenseSettings.php';

$fluentFormProLicensing = (new \FluentFormPro\FluentUpdater\FluentLicensing())->register([
    'version'        => FLUENTFORMPRO_VERSION,
    'basename'       => plugin_basename(FLUENTFORMPRO_DIR_FILE),
    'slug'           => 'fluentformpro',
    'plugin_title'   => 'Fluent Forms Pro',
    'api_url'        => 'https://fluentapi.wpmanageninja.com/',
    'store_url'      => 'https://wpmanageninja.com/',
    'purchase_url'   => 'https://fluentforms.com/pricing/',
    'account_url'    => 'https://wpmanageninja.com/account/',
    'support_url'    => 'https://wpmanageninja.com/account/support-tickets/',
    'activate_url'   => admin_url('admin.php?page=fluent_forms_settings&component=license_page'),
    'item_id'        => 7560866,
    'settings_key'   => '__fluentformpro_license',
    'network_wide'   => true,
    'show_check_update' => true,
    // Enforce signed packages by default. A site owner can bypass the gate
    // locally when repairing an installation or diagnosing update delivery.
    'verify_updates' => !(defined('FLUENTFORMPRO_SKIP_UPDATE_VERIFY') && FLUENTFORMPRO_SKIP_UPDATE_VERIFY),
]);

new FluentFormAddOnChecker($fluentFormProLicensing);

(new \FluentFormPro\FluentUpdater\LicenseSettings())->register($fluentFormProLicensing);

if (!function_exists('fluentFormProActivateLicense')) {
    function fluentFormProActivateLicense($licenseKey)
    {
        return \FluentFormAddOnChecker::getInstance()->tryActivateLicense($licenseKey);
    }
}
