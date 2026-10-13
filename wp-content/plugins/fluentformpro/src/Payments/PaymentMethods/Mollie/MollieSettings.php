<?php

namespace FluentFormPro\Payments\PaymentMethods\Mollie;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class MollieSettings
{
    public static function getSettings()
    {
        $defaults = [
            'is_active' => 'no',
            'payment_mode' => 'test',
            'test_api_key' => '',
            'live_api_key' => ''
        ];

        return wp_parse_args(get_option('fluentform_payment_settings_mollie', []), $defaults);
    }

    public static function isLive($formId = false)
    {
        $settings = self::getSettings();
        return $settings['payment_mode'] == 'live';
    }

    public static function getApiKey($formId = false)
    {
        $isLive = self::isLive($formId);
        $settings = self::getSettings();

        if($isLive) {
            return $settings['live_api_key'];
        }

        return $settings['test_api_key'];
    }
}