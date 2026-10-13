<?php

namespace FluentFormPro\Integrations\SMSNotification;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Twilio',
            'sms_notification',
            '_fluentform_sms_notification_settings',
            'sms_notification_feed',
            25
        );

        $this->logo = fluentFormMix('img/integrations/twilio.png');

        $this->description = 'Send SMS in real time when a form is submitted with Twilio.';


        $this->registerAdminHooks();

        // add_filter('fluentform/notifying_async_sms_notification', '__return_false');
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('SMS Provider Settings (Twilio)', 'fluentformpro'),
            'menu_description' => __('Please Provide your Twilio Settings here', 'fluentformpro'),
            'valid_message'    => __('Your Twilio API Key is valid', 'fluentformpro'),
            'invalid_message'  => __('Your Twilio API Key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'senderNumber' => [
                    'type'        => 'text',
                    'placeholder' => __('Twilio Number', 'fluentformpro'),
                    'label_tips'  => __("Enter your twillo sender number", 'fluentformpro'),
                    'label'       => __('Number From', 'fluentformpro'),
                ],
                'accountSID'   => [
                    'type'        => 'text',
                    'placeholder' => __('Account SID', 'fluentformpro'),
                    'label_tips'  => __("Enter Twilio Account SID. This can be found from twillio", 'fluentformpro'),
                    'label'       => __('Account SID', 'fluentformpro'),
                ],
                'authToken'    => [
                    'type'        => 'password',
                    'placeholder' => __('Auth Token', 'fluentformpro'),
                    'label_tips'  => __("Enter Twilio API Auth Token. This can be found from twillio", 'fluentformpro'),
                    'label'       => __('Auth Token', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your Twilio API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Twilio', 'fluentformpro'),
                'data'                => [
                    'authToken' => ''
                ],
                'show_verify'         => true
            ]
        ];
    }

    public function getGlobalSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);
        if (!$globalSettings) {
            $globalSettings = [];
        }
        $defaults = [
            'senderNumber' => '',
            'accountSID'   => '',
            'authToken'    => '',
            'provider'     => 'twillio'
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (!$settings['authToken']) {
            $integrationSettings = [
                'senderNumber' => '',
                'accountSID'   => '',
                'authToken'    => '',
                'provider'     => 'twillio',
                'status'       => false
            ];
            // Update the reCaptcha details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');
            wp_send_json_success([
                'message' => __('Your settings has been updated', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        // Verify API key now
        try {

            if (empty($settings['senderNumber'])) {
                //prevent saving integration without the sender number
                throw new \Exception('Sender number is required');

            }
            $integrationSettings = [
                'senderNumber' => sanitize_textarea_field($settings['senderNumber']),
                'accountSID'   => sanitize_text_field($settings['accountSID']),
                'authToken'    => sanitize_text_field($settings['authToken']),
                'provider'     => 'twillio',
                'status'       => false
            ];
            update_option($this->optionKey, $integrationSettings, 'no');

            $api = new TwilioApi($settings['authToken'], $settings['accountSID']);
            $result = $api->auth_test();

            if (!empty($result['error'])) {
                throw new \Exception($result['message']);
            }
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }

        // Integration key is verified now, Proceed now

        $integrationSettings = [
            'senderNumber' => sanitize_textarea_field($settings['senderNumber']),
            'accountSID'   => sanitize_text_field($settings['accountSID']),
            'authToken'    => sanitize_text_field($settings['authToken']),
            'provider'     => 'twillio',
            'status'       => true
        ];

        // Update the reCaptcha details with siteKey & secretKey.
        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your Twilio api key has been verified and successfully set', 'fluentformpro'),
            'status'  => true
        ], 200);
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => 'SMS Notification by Twilio',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-sms_notification-settings'),
            'configure_message'     => __('SMS Notification is not configured yet! Please configure your SMS api first',
                'fluentformpro'),
            'configure_button_text' => __('Set SMS Notification API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'            => '',
            'receiver_number' => '',
            'message'         => '',
            'conditionals'    => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'         => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields'              => [
                [
                    'key'         => 'name',
                    'label'       => __('Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'         => 'receiver_number',
                    'label'       => __('To', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Type the receiver number', 'fluentformpro'),
                    'component'   => 'value_text'
                ],
                [
                    'key'         => 'message',
                    'label'       => __('SMS text', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('SMS Text', 'fluentformpro'),
                    'component'   => 'value_textarea'
                ],
                [
                    'require_list' => false,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Send SMS Notification conditionally based on your submission values',
                        'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list'   => false,
                    'key'            => 'enabled',
                    'label'          => __('Status', 'fluentformpro'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro')
                ]
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];
    }


    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }


    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        if (empty($feedData['receiver_number']) || empty($feedData['message'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed',
                __('No valid receiver_number found', 'fluentformpro'));
            return;
        }

        $apiSettings = $this->getGlobalSettings([]);
        $feedData['message'] = str_replace('<br />', "\n", $feedData['message']);
        $feedData['message'] = preg_replace('/\h+/', ' ', sanitize_textarea_field($feedData['message']));
        $smsData = [
            'Body' => trim($feedData['message']),
            'From' => $apiSettings['senderNumber'],
            'To'   => $feedData['receiver_number']
        ];

        $smsData = apply_filters_deprecated(
            'fluentform_integration_data_' . $this->integrationKey,
            [
                $smsData,
                $feed,
                $entry
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/integration_data_' . $this->integrationKey,
            'Use fluentform/integration_data_' . $this->integrationKey . ' instead of fluentform_integration_data_' . $this->integrationKey
        );

        $smsData = apply_filters('fluentform/integration_data_' . $this->integrationKey, $smsData, $feed, $entry);

        $api = $this->getRemoteClient();
        $response = $api->sendSMS($apiSettings['accountSID'], $smsData);

        if (is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
        } else {
            do_action('fluentform/integration_action_result', $feed, 'success',
                __('Twilio SMS feed has been successfully initialed and pushed data', 'fluentformpro'));
        }
    }

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new TwilioApi($settings['authToken'], $settings['accountSID']);
    }
}
