<?php

namespace FluentFormPro\Integrations\Automizy;

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
            'Automizy',
            'automizy',
            '_fluentform_automizy_settings',
            'automizy_feeds',
            40
        );

        $this->logo = fluentFormMix('img/integrations/automizy.png');

        $this->description = 'Connect Automizy with Fluent Forms and subscribe a contact when a form is submitted.';

        $this->registerAdminHooks();

       // add_filter('fluentform/notifying_async_automizy', '__return_false');
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('Automizy API Settings', 'fluentformpro'),
            'menu_description' => __('Automizy is email marketing software. Use Fluent Forms to collect customer information and automatically add it as Automizy subscriber list. <a target="_blank" rel="nofollow" href="https://app.automizy.com/api-token">Get API Token</a>', 'fluentformpro'),
            'valid_message'    => __('Your Automizy API Key is valid', 'fluentformpro'),
            'invalid_message'  => __('Your Automizy API Key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'apiKey' => [
                    'type'        => 'text',
                    'placeholder' => __('API Token', 'fluentformpro'),
                    'label_tips'  => __("Enter your Automizy API Key, if you do not have <br>Please login to your Automizy account and go to<br>Account -> Settings -> Api Token", 'fluentformpro'),
                    'label'       => __('Automizy API Token', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your Automizy API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Automizy', 'fluentformpro'),
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
            'message' => __('Your Automizy api key has been verified and successfully set', 'fluentformpro'),
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-automizy-settings'),
            'configure_message'     => __('Automizy is not configured yet! Please configure your Automizy api first', 'fluentformpro'),
            'configure_button_text' => __('Set Automizy API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'                 => '',
            'SubscriberFirstName'  => '',
            'SubscriberLastName'   => '',
            'Email'                => '',
            'CustomFields'         => (object)[],
            'other_fields_mapping' => [
                [
                    'item_value' => '',
                    'label'      => ''
                ]
            ],
            'list_id'              => '', // Automizy
            'tags'                 => '',
            'tag_routers'            => [],
            'tag_ids_selection_type' => 'simple',
            'conditionals'         => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'              => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields'              => [
                [
                    'key'         => 'name',
                    'label'       => __('Feed Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'         => 'list_id',
                    'required'    => true,
                    'label'       => __('Automizy Mailing Lists', 'fluentformpro'),
                    'placeholder' => __('Select Automizy Mailing List', 'fluentformpro'),
                    'tips'        => __('Select the Automizy Mailing List you would like to add your contacts to.', 'fluentformpro'),
                    'component'   => 'select',
                    'options'     => $this->getLists(),
                ],
                [
                    'key'                => 'CustomFields',
                    'require_list'       => false,
                    'label'              => __('Primary Fields', 'fluentformpro'),
                    'tips'               => __('Associate your Automizy merge tags to the appropriate Fluent Forms fields by selecting the appropriate form field from the list.', 'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('Automizy Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
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
                    'key'          => 'other_fields_mapping',
                    'require_list' => false,
                    'label'        => __('Custom Fields', 'fluentformpro'),
                    'remote_text'  => __('Automizy Field', 'fluentformpro'),
                    'tips'         => __('Select which Fluent Forms fields pair with their<br /> respective Automizy fields.', 'fluentformpro'),
                    'component'    => 'dropdown_many_fields',
                    'local_text'   => 'Form Field',
                    'options'      => $this->getCustomFields()
                ],
                [
                    'key' => 'tags',
                    'require_list' => true,
                    'label' => __('Tags', 'fluentformpro'),
                    'tips' => __('Associate tags to your Automizy contacts with a comma separated list (e.g. new lead, FluentForms, web source). Commas within a merge tag value will be created as a single tag.', 'fluentformpro'),
                    'component'    => 'selection_routing',
                    'simple_component' => 'value_text',
                    'routing_input_type' => 'text',
                    'routing_key'  => 'tag_ids_selection_type',
                    'settings_key' => 'tag_routers',
                    'labels'       => [
                        'choice_label'      => __('Enable Dynamic Tag Input', 'fluentformpro'),
                        'input_label'       => '',
                        'input_placeholder' => __('Tag', 'fluentformpro')
                    ],
                    'inline_tip' => __('Please provide each tag by comma separated value', 'fluentformpro')
                ],
                [
                    'require_list' => false,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow Automizy integration conditionally based on your submission values', 'fluentformpro'),
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

    private function getCustomFields()
    {
        $api = $this->getApiInstance();
        $fields = $api->getCustomFields();

        $formattedFields = [];

        foreach ($fields as $field) {
            $formattedFields[$field['name']] = $field['label'];
        }

        unset($formattedFields['firstname']);
        unset($formattedFields['lastname']);

        return $formattedFields;
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


        $listId = ArrayHelper::get($feedData, 'list_id');
        if (!$listId) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('Automizy API call has been skipped because no valid List available', 'fluentformpro'));
        }

        if (!is_email($feedData['Email'])) {
            $feedData['Email'] = ArrayHelper::get($formData, $feedData['Email']);
        }

        if (!is_email($feedData['Email'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', 'Automizy API call has been skipped because no valid email available');
            return;
        }

        $subscriber = [
            'email' => $feedData['Email']
        ];

        $customFields = [
            'firstname' => ArrayHelper::get($feedData, 'SubscriberFirstName'),
            'lastname'  => ArrayHelper::get($feedData, 'SubscriberLastName'),
        ];

        foreach ($feedData['other_fields_mapping'] as $field) {
            if (!empty($field['item_value'])) {
                $customFields[$field['label']] = $field['item_value'];
            }
        }

        $customFields = array_filter($customFields);
        if ($customFields) {
            $subscriber['customFields'] = $customFields;
        }

        $tags = $this->getSelectedTagIds($feedData, $formData, 'tags');
        if(!is_array($tags)) {
            $tags = explode(',', $tags);
        }

        $tags = array_map('trim', $tags);
        $tags = array_filter($tags);
        if ($tags) {
            $subscriber['tags'] = implode(',', $tags);
        }

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
        $result = $api->subscribe($listId, $subscriber);

        if (!empty($result['error'])) {
            $message = !empty($result['message']) ? $result['message'] : 'Automizy API call has been failed';

            do_action('fluentform/integration_action_result', $feed, 'failed', $message);
        } else {
            do_action('fluentform/integration_action_result', $feed, 'success', __('Automizy feed has been successfully initialed and pushed data', 'fluentformpro'));
        }
    }

    protected function getApiInstance()
    {
        $settings = $this->getApiSettings();
        return new API($settings['apiKey']);
    }
}
