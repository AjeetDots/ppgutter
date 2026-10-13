<?php

namespace FluentFormPro\Payments\PaymentMethods\AuthorizeNet;

use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class AuthorizeNetSettings
{
    public static function getSettings()
    {
        $defaults = [
            'test_api_key'          => '',
            'test_api_secret'       => '',
            'live_api_key'          => '',
            'live_api_secret'       => '',
            'payment_mode'          => 'test',
            'is_active'             => 'no',
            'transaction_type'      => 'auth_capture',
            'is_encrypted'          => 'no',
            'webhook_signature_key' => ''
        ];

        $settings = get_option('fluentform_payment_settings_authorizenet', []);
        return wp_parse_args($settings, $defaults);
    }

    public static function updateSettings($data)
    {
        $settings = self::getSettings();
        $settings = wp_parse_args($data, $settings);
        update_option('fluentform_payment_settings_authorizenet', $settings);
        return self::getSettings();
    }

    public static function getApiCredentials($formId = false)
    {
        $settings = self::getSettings();

        if ($settings['payment_mode'] == 'live') {
            return [
                'api_login_id'    => $settings['live_api_key'],
                'transaction_key' => $settings['live_api_secret']
            ];
        }

        return [
            'api_login_id'    => $settings['test_api_key'],
            'transaction_key' => $settings['test_api_secret']
        ];
    }

    public static function isLive($formId = false)
    {
        $settings = self::getSettings();
        return $settings['payment_mode'] == 'live';
    }

    public static function getMode($formId = false)
    {
        return static::isLive($formId) ? 'live' : 'test';
    }

    public static function getApiEndpoint($formId = false)
    {
        if (self::isLive($formId)) {
            return 'https://api.authorize.net/xml/v1/request.api';
        }
        return 'https://apitest.authorize.net/xml/v1/request.api';
    }

    public static function getTransactionType($formId = false)
    {
        $settings = self::getSettings();
        return ArrayHelper::get($settings, 'transaction_type', 'auth_capture');
    }

    public static function getWebhookUrl()
    {
        return rest_url('fluentform/v1/authorizenet/webhook');
    }

    public static function getWebhookSignatureKey()
    {
        $settings = self::getSettings();
        return ArrayHelper::get($settings, 'webhook_signature_key', '');
    }

    public static function getWebhookInstructions()
    {
        $webhookUrl = self::getWebhookUrl();
        $webhookEvents = [
            'net.authorize.payment.authcapture.created',
            'net.authorize.payment.authorization.created',
            'net.authorize.payment.capture.created',
            'net.authorize.payment.priorAuthCapture.created',
            'net.authorize.payment.void.created',
            'net.authorize.payment.refund.created',
            'net.authorize.payment.fraud.approved',
            'net.authorize.payment.fraud.declined'
        ];

        $eventsList = '';
        foreach ($webhookEvents as $event) {
            $eventsList .= "<li><code>{$event}</code></li>";
        }

        return "
            <div class='ff_payment_sub_section' style='margin-top: 20px;'>
                <h2>Required Authorize.Net Webhook Setup</h2>
                <p>In order for Authorize.Net to function completely for payments, you must configure your Authorize.Net webhooks. Visit your <a href='https://account.authorize.net/' target='_blank' rel='noopener'>account dashboard</a> to configure them.</p>

                <div class='ff_connect_require' style='margin-top: 15px;'>
                    <p style='margin: 0 0 8px 0;'><strong>⚠️ Important: Webhook Signature Key Required</strong></p>
                    <p style='margin: 0;'>You must enter the <strong>Webhook Signature Key</strong> in the field above. This key is used to verify that webhook requests are genuinely from Authorize.Net.</p>
                </div>

                <h3 style='font-size: 16px; margin-top: 20px; margin-bottom: 10px;'>Step-by-Step Instructions:</h3>
                <ol style='margin-left: 20px;'>
                    <li style='margin-bottom: 15px;'>
                        <strong>- Create a Webhook in Authorize.Net:</strong>
                        <ul style='margin-left: 20px; margin-top: 8px;'>
                            <li>Go to <a href='https://sandbox.authorize.net/' target='_blank' rel='noopener'>Sandbox Dashboard</a> (for testing) or <a href='https://account.authorize.net/' target='_blank' rel='noopener'>Production Dashboard</a> (for live)</li>
                            <li>Navigate to <strong>Account → Webhooks</strong></li>
                            <li>Click <strong>Add Endpoint</strong></li>
                            <li>Enter the webhook URL: <code style='background: #fff; padding: 2px 6px; border-radius: 3px;'>{$webhookUrl}</code></li>
                            <li>Subscribe to the events listed below</li>
                            <li>Click <strong>Save</strong></li>
                        </ul>
                    </li>
                    <li style='margin-bottom: 15px;'>
                        <strong>- Get the Signature Key:</strong>
                        <ul style='margin-left: 20px; margin-top: 8px;'>
                            <li>After creating the webhook, Authorize.Net will display a <strong>Signature Key</strong></li>
                            <li><strong>Copy this key</strong> - it will only be shown once!</li>
                            <li>Paste it in the <strong>Webhook Signature Key</strong> field above</li>
                            <li>Click <strong>Save Settings</strong></li>
                        </ul>
                    </li>
                    <li style='margin-bottom: 15px;'>
                        <strong>- Test the Webhook:</strong>
                        <ul style='margin-left: 20px; margin-top: 8px;'>
                            <li>In Authorize.Net, click <strong>Test</strong> next to your webhook</li>
                            <li>It should show a success message</li>
                        </ul>
                    </li>
                </ol>

                <div style='margin-top: 20px;'>
                    <p><strong>Required Webhook Events:</strong></p>
                    <ul style='margin-left: 20px;'>{$eventsList}</ul>
                </div>
            </div>
        ";
    }
}
