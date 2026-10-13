<?php

namespace FluentFormPro\Payments\PaymentMethods\Square;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

class SquareHandler extends BasePaymentMethod
{
    public function __construct()
    {
        parent::__construct('square');
    }
    
    public function init()
    {
        add_filter('fluentform/payment_method_settings_validation_'.$this->key, array($this, 'validateSettings'), 10, 2);
        
        if (!$this->isEnabled()) {
            return;
        }
        
        add_filter('fluentform/transaction_data_' . $this->key, array($this, 'modifyTransaction'), 10, 1);
        
        add_filter('fluentform/available_payment_methods', [$this, 'pushPaymentMethodToForm']);

        add_action('fluentform/rendering_payment_method_' . $this->key, [$this, 'enqueueAssets']);

        add_action('fluentform/process_payment_' . $this->key, [$this, 'routeSquareProcessor'], 10, 6);

        add_filter('fluentform/editor_init_element_payment_method', [$this, 'addInlineOption'], 10, 2);

        (new SquareProcessor())->init();
        (new SquareInlineProcessor())->init();
    }

    public function addInlineOption($element, $form)
    {
        if (!isset($element['settings']['payment_methods']['square']['settings']['embedded_checkout'])) {
            $element['settings']['payment_methods']['square']['settings']['embedded_checkout'] = [
                'type'     => 'checkbox',
                'template' => 'inputYesNoCheckbox',
                'value'    => 'no',
                'label'    => __('Embedded Checkout', 'fluentformpro')
            ];
        }

        return $element;
    }
    
    public function pushPaymentMethodToForm($methods)
    {
        $methods[$this->key] = [
            'title' => __('Square', 'fluentformpro'),
            'enabled' => 'yes',
            'method_value' => $this->key,
            'settings' => [
                'option_label' => [
                    'type' => 'text',
                    'template' => 'inputText',
                    'value' => 'Pay with Square',
                    'label' => __('Method Label', 'fluentformpro')
                ],
                'embedded_checkout' => [
                    'type'     => 'checkbox',
                    'template' => 'inputYesNoCheckbox',
                    'value'    => 'no',
                    'label'    => __('Embedded Checkout', 'fluentformpro')
                ],
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
            if (!ArrayHelper::get($settings, 'test_application_id')) {
                $errors['test_application_id'] = __('Please provide Test Application ID', 'fluentformpro');
            }

            if (!ArrayHelper::get($settings, 'test_location_id')) {
                $errors['test_location_id'] = __('Please provide Test Location ID', 'fluentformpro');
            }
            
            if (!ArrayHelper::get($settings, 'test_access_key')) {
                $errors['test_access_key'] = __('Please provide Test Access Secret', 'fluentformpro');
            }
        } elseif ($mode == 'live') {
            if (!ArrayHelper::get($settings, 'live_application_id')) {
                $errors['live_application_id'] = __('Please provide Live Application ID', 'fluentformpro');
            }

            if (!ArrayHelper::get($settings, 'live_location_id')) {
                $errors['live_location_id'] = __('Please Live Location ID', 'fluentformpro');
            }
            
            if (!ArrayHelper::get($settings, 'live_access_key')) {
                $errors['live_access_key'] = __('Please provide Live Access Key', 'fluentformpro');
            }
        }
        
        return $errors;
    }
    
    public function modifyTransaction($transaction)
    {
        $path = $transaction->payment_mode === 'test' ? 'https://squareupsandbox.com/' : 'https://squareup.com/';
        if ($transaction->charge_id) {
            $transaction->action_url = $path . 'dashboard/sales/transactions/' . $transaction->charge_id;
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
            'label' => 'Square',
            'fields' => [
                [
                    'settings_key' => 'is_active',
                    'type' => 'yes-no-checkbox',
                    'label' => __('Status', 'fluentformpro'),
                    'checkbox_label' => __('Enable Square Payment Method', 'fluentformpro'),
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
                    'settings_key' => 'test_application_id',
                    'type' => 'input-text',
                    'data_type' => 'text',
                    'placeholder' => __('Test Application ID', 'fluentformpro'),
                    'label' => __('Test Application ID', 'fluentformpro'),
                    'inline_help' => __('Provide your sandbox application ID for your test payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'test_access_key',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'placeholder' => __('Test Access Key', 'fluentformpro'),
                    'label' => __('Test Access Key', 'fluentformpro'),
                    'inline_help' => __('Provide your test api secret for your test payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'test_location_id',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'placeholder' => __('Test Location ID', 'fluentformpro'),
                    'label' => __('Test Location ID', 'fluentformpro'),
                    'inline_help' => __('Provide your test location id for your test payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
               
                [
                    'settings_key' => 'live_payment_tips',
                    'type' => 'html',
                    'html' => __('<h2>Your Live API Credentials</h2><p>If you use the live mode</p>', 'fluentformpro')
                ],
                [
                    'settings_key' => 'live_application_id',
                    'type' => 'input-text',
                    'data_type' => 'text',
                    'placeholder' => __('Live Application ID', 'fluentformpro'),
                    'label' => __('Live Application ID', 'fluentformpro'),
                    'inline_help' => __('Provide your live application ID for your live payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'live_access_key',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'placeholder' => __('Live Access Key', 'fluentformpro'),
                    'label' => __('Live Access Key', 'fluentformpro'),
                    'inline_help' => __('Provide your live live access key for your live payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'live_location_id',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'label' => __('Live Location ID', 'fluentformpro'),
                    'placeholder' => __('Live Location ID', 'fluentformpro'),
                    'inline_help' => __('Provide your live api key for your live payments', 'fluentformpro'),
                    'check_status' => 'yes'
                ],
                [
                    'type' => 'html',
                    'html' => __('<p>  <a target="_blank" rel="noopener" href="https://wpmanageninja.com/docs/fluent-form/payment-settings/how-to-integrate-square-with-wp-fluent-forms/">Please read the documentation</a> to learn how to setup <b>Square Payment </b> Gateway. </p>', 'fluentformpro')
                ]
            ]
        ];
    }
    
    public function getGlobalSettings()
    {
        return SquareSettings::getSettings();
    }

    public function enqueueAssets()
    {
        $url = 'https://sandbox.web.squarecdn.com/v1/square.js';
        if (SquareSettings::isLive()) {
            $url = 'https://web.squarecdn.com/v1/square.js';
        }
        wp_enqueue_script('square-web-sdk', $url, ['jquery'], '1.0', true);
    }

    public function routeSquareProcessor($submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable = 0)
    {
        $processor = ArrayHelper::get($methodSettings, 'settings.embedded_checkout.value', 'hosted') === 'yes' ? 'inline' : 'hosted';

        do_action('fluentform/process_payment_square_' . $processor, $submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable);
    }
}
