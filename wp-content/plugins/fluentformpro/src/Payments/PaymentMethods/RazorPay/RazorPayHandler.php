<?php

namespace FluentFormPro\Payments\PaymentMethods\RazorPay;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

class RazorPayHandler extends BasePaymentMethod
{
    public function __construct()
    {
        parent::__construct('razorpay');
    }

    public function init()
    {
        add_filter('fluentform/payment_method_settings_validation_' . $this->key, array($this, 'validateSettings'), 10, 2);

        if(!$this->isEnabled()) {
            return;
        }

        add_filter('fluentform/transaction_data_' . $this->key, array($this, 'modifyTransaction'), 10, 1);

        add_filter('fluentform/available_payment_methods', [$this, 'pushPaymentMethodToForm']);

        (new RazorPayProcessor())->init();
    }

    public function pushPaymentMethodToForm($methods)
    {
        $methods[$this->key] = [
            'title' => __('RazorPay', 'fluentformpro'),
            'enabled' => 'yes',
            'method_value' => $this->key,
            'settings' => [
                'option_label' => [
                    'type' => 'text',
                    'template' => 'inputText',
                    'value' => 'Pay with RazorPay',
                    'label' => __('Method Label', 'fluentformpro')
                ]
            ]
        ];

        return $methods;
    }

    public function validateSettings($errors, $settings)
    {
        if(ArrayHelper::get($settings, 'is_active') == 'no') {
            return [];
        }

        $mode = ArrayHelper::get($settings, 'payment_mode');
        if (!$mode) {
            $errors['payment_mode'] = __('Please select Payment Mode', 'fluentformpro');
        }

        if ($mode == 'test') {
            if (!ArrayHelper::get($settings, 'test_api_key')) {
                $errors['test_api_key'] = __('Please provide Test API Key', 'fluentformpro');
            }

            if (!ArrayHelper::get($settings, 'test_api_secret')) {
                $errors['test_api_secret'] = __('Please provide Test API Secret', 'fluentformpro');
            }
        } elseif ($mode == 'live') {
            if (!ArrayHelper::get($settings, 'live_api_key')) {
                $errors['live_api_key'] = __('Please provide Live API Key', 'fluentformpro');
            }

            if (!ArrayHelper::get($settings, 'live_api_secret')) {
                $errors['live_api_secret'] = __('Please provide Live API Secret', 'fluentformpro');
            }
        }

        return $errors;
    }

    public function modifyTransaction($transaction)
    {
        if ($transaction->charge_id) {
            $transaction->action_url =  'https://dashboard.razorpay.com/app/payments/'.$transaction->charge_id;
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
            'label' => 'RazorPay',
            'fields' => [
                [
                    'settings_key' => 'is_active',
                    'type' => 'yes-no-checkbox',
                    'label' => __('Status', 'fluentformpro'),
                    'checkbox_label' => __('Enable RazorPay Payment Method', 'fluentformpro'),
                ],
                [
                    'settings_key' => 'payment_mode',
                    'type' => 'input-radio',
                    'label' => __('Payment Mode', 'fluentformpro'),
                    'options' => [
                        'test' => __('Test Mode', 'fluentformpro'),
                        'live' => __('Live Mode', 'fluentformpro')
                    ],
                    'info_help' => __('Select the payment mode. for testing purposes you should select Test Mode otherwise select Live mode.', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'test_payment_tips',
                    'type' => 'html',
                    'html' => __('<h2>Your Test API Credentials</h2><p>If you use the test mode</p>', 'fluentformpro')
                ],
                [
                    'settings_key' => 'test_api_key',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'placeholder' => __('Test API Key', 'fluentformpro'),
                    'label' => __('Test API Key', 'fluentformpro'),
                    'inline_help' => __('Provide your test api key for your test payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'test_api_secret',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'placeholder' => __('Test API Secret', 'fluentformpro'),
                    'label' => __('Test API Secret', 'fluentformpro'),
                    'inline_help' => __('Provide your test api secret for your test payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'live_payment_tips',
                    'type' => 'html',
                    'html' => __('<h2>Your Live API Credentials</h2><p>If you use the live mode</p>', 'fluentformpro')
                ],
                [
                    'settings_key' => 'live_api_key',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'label' => __('Live API Key', 'fluentformpro'),
                    'placeholder' => __('Live API Key', 'fluentformpro'),
                    'inline_help' => __('Provide your live api key for your live payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'live_api_secret',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'placeholder' => __('Live API Secret', 'fluentformpro'),
                    'label' => __('Live API Secret', 'fluentformpro'),
                    'inline_help' => __('Provide your live api secret for your live payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'checkout_type',
                    'type' => 'input-radio',
                    'label' => __('Checkout Style Type', 'fluentformpro'),
                    'options' => [
                        'modal' => __('Modal Checkout Style', 'fluentformpro'),
                        'hosted' => __('Hosted to razorpay.com', 'fluentformpro')
                    ],
                    'info_help' => __('Select which type of checkout style you want.', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'theme_color',
                    'type' => 'input-color',
                    'label' => __('Modal Theme Color', 'fluentformpro'),
                    'info_help' => __('Choose Razorpay checkout brand theme color. Leave empty for Razorpay default theme.', 'fluentformpro'),
                ],
                [
                    'settings_key' => 'notifications_tips',
                    'type' => 'html',
                    'html' => __('<h2>RazorPay Notifications </h2><p>Select if you want to enable SMS and Email Notification from razorpay</p>', 'fluentformpro')
                ],
                [
                    'settings_key' => 'notifications',
                    'type' => 'input-checkboxes',
                    'label' => __('RazorPay Notifications', 'fluentformpro'),
                    'options' => [
                        'sms' => __('SMS', 'fluentformpro'),
                        'email' => __('Email', 'fluentformpro')
                    ],
                    'info_help' => '',
                    'check_status' => 'yes'
                ],
                [
                    'type' => 'html',
                    'html' => __('<p>  <a target="_blank" rel="noopener" href="https://wpmanageninja.com/docs/fluent-form/payment-settings/how-to-integrate-razorpay-with-wp-fluent-forms/">Please read the documentation</a> to learn how to setup <b>RazorPay Payment </b> Gateway. </p>', 'fluentformpro')
                ]
            ]
        ];
    }

    public function getGlobalSettings()
    {
        return RazorPaySettings::getSettings();
    }
}
