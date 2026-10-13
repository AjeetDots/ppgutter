<?php

namespace FluentFormPro\Payments\PaymentMethods\AuthorizeNet;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class AuthorizeNetHandler extends BasePaymentMethod
{
    public function __construct()
    {
        parent::__construct('authorizenet');
    }

    public function init()
    {
        add_filter('fluentform/payment_method_settings_validation_' . $this->key, array($this, 'validateSettings'), 10,
            2);
        add_filter('fluentform/payment_method_settings_save_' . $this->key, array($this, 'sanitizeGlobalSettings'), 10,
            1);

        if (!$this->isEnabled()) {
            return;
        }

        add_filter('fluentform/transaction_data_' . $this->key, array($this, 'modifyTransaction'), 10, 1);
        add_filter('fluentform/available_payment_methods', [$this, 'pushPaymentMethodToForm']);

        (new AuthorizeNetProcessor())->init();
    }

    public function pushPaymentMethodToForm($methods)
    {
        $methods[$this->key] = [
            'title'        => __('Authorize.Net', 'fluentformpro'),
            'enabled'      => 'yes',
            'method_value' => $this->key,
            'settings'     => [
                'option_label' => [
                    'type'     => 'text',
                    'template' => 'inputText',
                    'value'    => 'Pay with Card (Authorize.Net)',
                    'label'    => __('Method Label', 'fluentformpro')
                ]
            ]
        ];

        return $methods;
    }

    public function validateSettings($errors, $settings)
    {
        if (ArrayHelper::get($settings, 'is_active') == 'no') {
            return [];
        }

        $mode = ArrayHelper::get($settings, 'payment_mode');
        if (!$mode) {
            $errors['payment_mode'] = __('Please select Payment Mode', 'fluentformpro');
        }

        if ($mode == 'test') {
            if (!ArrayHelper::get($settings, 'test_api_key')) {
                $errors['test_api_key'] = __('Please provide Test API Login ID', 'fluentformpro');
            }

            if (!ArrayHelper::get($settings, 'test_api_secret')) {
                $errors['test_api_secret'] = __('Please provide Test Transaction Key', 'fluentformpro');
            }
        } elseif ($mode == 'live') {
            if (!ArrayHelper::get($settings, 'live_api_key')) {
                $errors['live_api_key'] = __('Please provide Live API Login ID', 'fluentformpro');
            }

            if (!ArrayHelper::get($settings, 'live_api_secret')) {
                $errors['live_api_secret'] = __('Please provide Live Transaction Key', 'fluentformpro');
            }
        }

        // Validate transaction type
        $transactionType = ArrayHelper::get($settings, 'transaction_type');
        if (!in_array($transactionType, ['auth_capture', 'auth_only'])) {
            $errors['transaction_type'] = __('Please select a valid Transaction Type', 'fluentformpro');
        }

        return $errors;
    }

    public function sanitizeGlobalSettings($settings)
    {
        return AuthorizeNetSettings::updateSettings($settings);
    }

    public function modifyTransaction($transaction)
    {
        if ($transaction->charge_id) {
            if (AuthorizeNetSettings::isLive()) {
                $transaction->action_url = 'https://account.authorize.net/';
            } else {
                $transaction->action_url = 'https://sandbox.authorize.net/';
            }
        }

        return $transaction;
    }

    public function isEnabled()
    {
        $settings = $this->getGlobalSettings();
        return $settings['is_active'] == 'yes';
    }

    public function getGlobalFields()
    {
        return [
            'label'  => 'Authorize.Net',
            'fields' => [
                [
                    'settings_key'   => 'is_active',
                    'type'           => 'yes-no-checkbox',
                    'label'          => __('Status', 'fluentformpro'),
                    'checkbox_label' => __('Enable Authorize.Net Payment Method', 'fluentformpro'),
                ],
                [
                    'settings_key' => 'payment_mode',
                    'type'         => 'input-radio',
                    'label'        => __('Payment Mode', 'fluentformpro'),
                    'options'      => [
                        'test' => __('Test Mode', 'fluentformpro'),
                        'live' => __('Live Mode', 'fluentformpro')
                    ],
                    'info_help'    => __('Select the payment mode. For testing purposes you should select Test Mode otherwise select Live mode.',
                        'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'transaction_type',
                    'type'         => 'input-radio',
                    'label'        => __('Transaction Type', 'fluentformpro'),
                    'options'      => [
                        'auth_capture' => __('Authorize and Capture', 'fluentformpro'),
                        'auth_only'    => __('Authorize Only', 'fluentformpro')
                    ],
                    'info_help'    => __('Authorize and Capture will immediately charge the card. Authorize Only will only authorize the payment for later capture.',
                        'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'test_payment_tips',
                    'type'         => 'html',
                    'html'         => __('<h2>Your Test API Credentials</h2><p>For test mode, use your Authorize.Net sandbox credentials</p>',
                        'fluentformpro')
                ],
                [
                    'settings_key' => 'test_api_key',
                    'type'         => 'input-text',
                    'data_type'    => 'password',
                    'placeholder'  => __('Test API Login ID', 'fluentformpro'),
                    'label'        => __('Test API Login ID', 'fluentformpro'),
                    'inline_help'  => __('Provide your test API Login ID for test payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'test_api_secret',
                    'type'         => 'input-text',
                    'data_type'    => 'password',
                    'placeholder'  => __('Test Transaction Key', 'fluentformpro'),
                    'label'        => __('Test Transaction Key', 'fluentformpro'),
                    'inline_help'  => __('Provide your test Transaction Key for test payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'live_payment_tips',
                    'type'         => 'html',
                    'html'         => __('<h2>Your Live API Credentials</h2><p>For live mode, use your production Authorize.Net credentials</p>',
                        'fluentformpro')
                ],
                [
                    'settings_key' => 'live_api_key',
                    'type'         => 'input-text',
                    'data_type'    => 'password',
                    'label'        => __('Live API Login ID', 'fluentformpro'),
                    'placeholder'  => __('Live API Login ID', 'fluentformpro'),
                    'inline_help'  => __('Provide your live API Login ID for live payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'live_api_secret',
                    'type'         => 'input-text',
                    'data_type'    => 'password',
                    'placeholder'  => __('Live Transaction Key', 'fluentformpro'),
                    'label'        => __('Live Transaction Key', 'fluentformpro'),
                    'inline_help'  => __('Provide your live Transaction Key for live payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'webhook_signature_key',
                    'type'         => 'input-text',
                    'data_type'    => 'password',
                    'placeholder'  => __('Enter your Webhook Signature Key', 'fluentformpro'),
                    'label'        => __('Webhook Signature Key (Required)', 'fluentformpro'),
                    'inline_help'  => __('Required: Enter the signature key from your Authorize.Net webhook configuration. This key is shown once when you create a webhook in your Authorize.Net dashboard.', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'webhook_configuration',
                    'type'         => 'html',
                    'html'         => AuthorizeNetSettings::getWebhookInstructions()
                ],
                [
                    'type' => 'html',
                    'html' => __('<p><a target="_blank" rel="noopener" href="https://fluentforms.com/docs/how-to-integrate-authorize-net-with-fluent-forms/">Please read the documentation</a> to learn how to setup <b>Authorize.Net Payment</b> Gateway.</p>',
                        'fluentformpro')
                ]
            ]
        ];
    }

    public function getGlobalSettings()
    {
        return AuthorizeNetSettings::getSettings();
    }
}
