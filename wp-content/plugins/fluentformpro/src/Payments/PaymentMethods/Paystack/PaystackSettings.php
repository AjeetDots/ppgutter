<?php

namespace FluentFormPro\Payments\PaymentMethods\Paystack;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class PaystackSettings
{
    public static function getSettings()
    {
        $defaults = [
            'is_active' => 'no',
            'payment_mode' => 'test',
            'checkout_type' => 'modal',
            'test_api_key' => '',
            'test_api_secret' => '',
            'live_api_key' => '',
            'live_api_secret' => '',
            'payment_channels' => []
        ];

        return wp_parse_args(get_option('fluentform_payment_settings_paystack', []), $defaults);
    }

    public static function isLive($formId = false)
    {
        $settings = self::getSettings();
        return $settings['payment_mode'] == 'live';
    }

    public static function getApiKeys($formId = false)
    {
        $isLive = self::isLive($formId);
        $settings = self::getSettings();

        if($isLive) {
            return [
                'api_key' => $settings['live_api_key'],
                'api_secret' => $settings['live_api_secret'],
            ];
        }

        return [
            'api_key' => $settings['test_api_key'],
            'api_secret' => $settings['test_api_secret'],
        ];
    }
}