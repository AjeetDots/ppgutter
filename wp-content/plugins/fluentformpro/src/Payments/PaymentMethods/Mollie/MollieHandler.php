<?php

namespace FluentFormPro\Payments\PaymentMethods\Mollie;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

class MollieHandler extends BasePaymentMethod
{
    public function __construct()
    {
        parent::__construct('mollie');
    }

    public function init()
    {
        add_filter('fluentform/payment_method_settings_validation_' . $this->key, array($this, 'validateSettings'), 10, 2);

        if(!$this->isEnabled()) {
            return;
        }

        add_filter('fluentform/transaction_data_' . $this->key, array($this, 'modifyTransaction'), 10, 1);

        add_filter(
            'fluentform/available_payment_methods',
            [$this, 'pushPaymentMethodToForm']
        );

        (new MollieProcessor())->init();
    }

    public function pushPaymentMethodToForm($methods)
    {
        $methods[$this->key] = [
            'title' => __('Mollie', 'fluentformpro'),
            'enabled' => 'yes',
            'method_value' => $this->key,
            'settings' => [
                'option_label' => [
                    'type' => 'text',
                    'template' => 'inputText',
                    'value' => 'Pay with Mollie',
                    'label' => __('Method Label', 'fluentformpro')
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
                $errors['test_api_key'] = __('Mollie Test API Key is required', 'fluentformpro');
            }
        } elseif ($mode == 'live') {
            if (!ArrayHelper::get($settings, 'live_api_key')) {
                $errors['live_api_key'] = __('Mollie Live API Key is required', 'fluentformpro');
            }
        }

        return $errors;
    }

    public function modifyTransaction($transaction)
    {
        if (is_array($transaction->payment_note) && $transactionUrl = ArrayHelper::get($transaction->payment_note, '_links.dashboard.href')) {
            $transaction->action_url =  $transactionUrl;
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
            'label' => 'Mollie',
            'fields' => [
                [
                    'settings_key' => 'is_active',
                    'type' => 'yes-no-checkbox',
                    'label' => __('Status', 'fluentformpro'),
                    'checkbox_label' => __('Enable Mollie Payment Method', 'fluentformpro'),
                ],
                [
                    'settings_key' => 'payment_mode',
                    'type' => 'input-radio',
                    'label' => __('Payment Mode', 'fluentformpro'),
                    'options' => [
                        'test' => __('Test Mode', 'fluentformpro'),
                        'live' => __('Live Mode', 'fluentformpro'),
                    ],
                    'info_help' => __('Select the payment mode. for testing purposes you should select Test Mode otherwise select Live mode.', 'fluentformpro'),
                    'check_status' => 'yes'
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
                    'settings_key' => 'live_api_key',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'label' => __('Live API Key', 'fluentformpro'),
                    'placeholder' => __('Live API Key', 'fluentformpro'),
                    'inline_help' => __('Provide your live api key for your live payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'type' => 'html',
                    'html' => __('<p>  <a target="_blank" rel="noopener" href="https://wpmanageninja.com/docs/fluent-form/payment-settings/how-to-integrate-mollie-with-wp-fluent-forms/">Please read the documentation</a> to learn how to setup <b>Mollie Payment </b> Gateway. </p>', 'fluentformpro')
                ],
            ]
        ];
    }

    public function getGlobalSettings()
    {
        return MollieSettings::getSettings();
    }
}
