<?php

namespace FluentFormPro\Integrations\SendFox;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'SendFox',
            'sendfox',
            '_fluentform_sendfox_settings',
            'sendfox_feeds',
            21
        );

        $this->logo = fluentFormMix('img/integrations/sendfox.png');

        $this->description = 'Connect SendFox with Fluent Forms and subscribe a contact when a form is submitted.';

        $this->registerAdminHooks();

       // add_filter('fluentform/notifying_async_sendfox', '__return_false');
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('SendFox API Settings', 'fluentformpro'),
            'menu_description' => __('SendFox is email marketing software. Use Fluent Forms to collect customer information and automatically add it as SendFox subscriber list.', 'fluentformpro'),
            'valid_message'    => __('Your SendFox API Key is valid', 'fluentformpro'),
            'invalid_message'  => __('Your SendFox API Key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'apiKey' => [
                    'type'        => 'textarea',
                    'placeholder' => 'API Key',
                    'label_tips'  => __("Enter your SendFox API Key, if you do not have <br>Please log in to your Sendfox account and go to<br>Account -> API Key", 'fluentformpro'),
                    'label'       => __('SendFox API Key', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your SendFox API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect SendFox', 'fluentformpro'),
                'data'                => [
                    'apiKey' => ''
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
            'apiKey' => '',
            'status' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (!$settings['apiKey']) {
            $integrationSettings = [
                'apiKey' => '',
                'status' => false
            ];

            // Update the reCaptcha details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_success([
                'message' => __('Your settings has been updated', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        //  Verify API key now
        try {
            $api = new API($settings['apiKey']);
            $result = $api->auth_test();
            if (!empty($result['error'])) {
                throw new \Exception($result['message']);
            }
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage(),
                'status'  => false
            ], 400);
        }

        // Integration key is verified now, Proceed now

        $integrationSettings = [
            'apiKey' => sanitize_text_field($settings['apiKey']),
            'status' => true
        ];

        // Update the reCaptcha details with siteKey & secretKey.
        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your SendFox API key has been verified and successfully set', 'fluentformpro'),
            'status'  => true
        ], 200);
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-sendfox-settings'),
            'configure_message'     => __('SendFox is not configured yet! Please configure your SendFox api first', 'fluentformpro'),
            'configure_button_text' => __('Set SendFox API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'                   => '',
            'SubscriberFirstName'         => '', // Name in SendFox
            'SubscriberLastName'         => '', // Name in SendFox
            'Email'                  => '',
            'CustomFields'           => (object)[],
            'list_id'                => '', // SendFox
            'conditionals'           => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'                => true
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
                    'key'         => 'list_id',
                    'label'       => __('SendFox Mailing Lists', 'fluentformpro'),
                    'placeholder' => __('Select SendFox Mailing List', 'fluentformpro'),
                    'tips'        => __('Select the SendFox Mailing List you would like to add your contacts to.', 'fluentformpro'),
                    'component'   => 'list_ajax_options',
                    'options'     => $this->getLists(),
                ],
                [
                    'key'                => 'CustomFields',
                    'require_list'       => true,
                    'label'              => __('Map Fields', 'fluentformpro'),
                    'tips'               => __('Associate your SendFox merge tags to  the appropriate Fluent Forms fields by selecting the appropriate form field from the list.', 'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('SendFox Field', 'fluentformpro'),
                    'field_label_local'  => 'Form Field',
                    'primary_fileds'     => [
                        [
                            'key'           => 'Email',
                            'label'         => __('Email Address', 'fluentformpro'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ],
                        [
                            'key'   => 'SubscriberFirstName',
                            'label' => __('First Name', 'fluentformpro')
                        ],
                        [
                            'key'   => 'SubscriberLastName',
                            'label' => __('Last Name', 'fluentformpro')
                        ]
                    ]
                ],
                [
                    'require_list' => true,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logic', 'fluentformpro'),
                    'tips'         => __('Allow SendFox integration conditionally based on your submission values', 'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list'    => true,
                    'key'             => 'enabled',
                    'label'           => __('Status','fluentformpro'),
                    'component'       => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro')
                ]
            ],
            'button_require_list' => true,
            'integration_title'   => $this->title
        ];
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }

    protected function getLists()
    {
        $api = $this->getApiInstance();
        $lists = $api->getLists();
        $formattedLists = [];
        foreach ($lists as $list) {
            $formattedLists[$list['id']] = $list['name'];
        }
        return $formattedLists;
    }

    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        if (!is_email($feedData['Email'])) {
            $feedData['Email'] = ArrayHelper::get($formData, $feedData['Email']);
        }

        if (!is_email($feedData['Email'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('SendFox API call has been skipped because no valid email available', 'fluentformpro'));
            return;
        }

        $subscriber = [
            'first_name'  => $feedData['SubscriberFirstName'],
            'last_name'  => $feedData['SubscriberLastName'],
            'email' => $feedData['Email'],
            'tags' => [
                $feedData['list_id']
            ]
        ];
        $subscriber = array_filter($subscriber);
    
        $subscriber = apply_filters_deprecated(
            'fluentform_integration_data_' . $this->integrationKey,
            [
                $subscriber,
                $feed,
                $entry
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/integration_data_' . $this->integrationKey,
            'Use fluentform/integration_data_' . $this->integrationKey . ' instead of fluentform_integration_data_' . $this->integrationKey
        );

        $subscriber = apply_filters('fluentform/integration_data_' . $this->integrationKey, $subscriber, $feed, $entry);

        $api = $this->getApiInstance();
        $result = $api->subscribe($subscriber);
        if (!$result) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('SendFox API call has been failed', 'fluentformpro'));
        } else {
            do_action('fluentform/integration_action_result', $feed, 'success', __('SendFox feed has been successfully initialed and pushed data', 'fluentformpro'));
        }
    }

    protected function getApiInstance()
    {
        $settings = $this->getApiSettings();
        return new API($settings['apiKey']);
    }
}
