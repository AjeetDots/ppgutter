<?php

namespace FluentFormPro\Payments\PaymentMethods\PayPal;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class PayPalSettings
{
    public static function getSettings()
    {
        $defaults = [
            'paypal_email'    => '',
            'payment_mode'    => 'test',
            'is_active'       => 'no',
            'connection_mode' => 'standard',
            'test_client_id'  => '',
            'test_secret_key' => '',
            'live_client_id'  => '',
            'live_secret_key' => '',
            'webhook_id'      => '',
        ];

        $settings = get_option('fluentform_payment_settings_paypal', []);

        $settings = wp_parse_args($settings, $defaults);

        return $settings;
    }

    public static function getPayPalEmail($formId = false)
    {
        if ($formId) {
            $formPaymentSettings = PaymentHelper::getFormSettings($formId, 'admin');
            if (ArrayHelper::get($formPaymentSettings, 'paypal_account_type') == 'custom') {
                $payPalId =  ArrayHelper::get($formPaymentSettings, 'custom_paypal_id');
                if($payPalId) {
                    return $payPalId;
                }
            }
        }

        $settings = self::getSettings();
        return $settings['paypal_email'];
    }

    public static function isLive($formId = false)
    {
        if ($formId) {
            $formPaymentSettings = PaymentHelper::getFormSettings($formId, 'admin');
            if (ArrayHelper::get($formPaymentSettings, 'paypal_account_type') == 'custom') {
                return ArrayHelper::get($formPaymentSettings, 'custom_paypal_mode')  == 'live';
            }
        }

        $settings = self::getSettings();
        return $settings['payment_mode'] == 'live';
    }

    public static function getPaypalRedirect($ssl_check = false, $ipn = false)
    {
        $protocol = 'http://';
        if (is_ssl() || !$ssl_check) {
            $protocol = 'https://';
        }

        $isLive = self::isLive();

        // Check the current payment mode
        if ($isLive) {
            // Live mode
            if ($ipn) {
                $paypal_uri = 'https://ipnpb.paypal.com/cgi-bin/webscr';
            } else {
                $paypal_uri = $protocol . 'www.paypal.com/cgi-bin/webscr';
            }
        } else {
            // Test mode
            if ($ipn) {
                $paypal_uri = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';
            } else {
                $paypal_uri = $protocol . 'www.sandbox.paypal.com/cgi-bin/webscr';
            }
        }
        $paypal_uri = apply_filters_deprecated(
            'fluentform_paypal_url',
            [
                $paypal_uri,
                $ssl_check,
                $ipn,
                $isLive
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/paypal_url',
            'Use fluentform/paypal_url instead of fluentform_paypal_url.'
        );
        return apply_filters('fluentform/paypal_url', $paypal_uri, $ssl_check, $ipn, $isLive);
    }

    public static function useOrdersApi($formId = false)
    {
        $settings = self::getSettings();
        if (ArrayHelper::get($settings, 'connection_mode') !== 'api') {
            return false;
        }

        // Forms with custom PayPal account must use legacy flow
        // since API credentials are global, not per-form
        if ($formId) {
            $formSettings = PaymentHelper::getFormSettings($formId, 'admin');
            if (ArrayHelper::get($formSettings, 'paypal_account_type') == 'custom') {
                return false;
            }
        }

        $keys = self::getApiKeys($formId);
        return !empty($keys['client_id']) && !empty($keys['secret_key']);
    }

    public static function getApiKeys($formId = false)
    {
        $settings = self::getSettings();
        $isLive = self::isLive($formId);
        $prefix = $isLive ? 'live' : 'test';

        return [
            'client_id'  => ArrayHelper::get($settings, $prefix . '_client_id', ''),
            'secret_key' => ArrayHelper::get($settings, $prefix . '_secret_key', ''),
        ];
    }

    public static function getWebhookId()
    {
        $settings = self::getSettings();
        return ArrayHelper::get($settings, 'webhook_id', '');
    }
}
