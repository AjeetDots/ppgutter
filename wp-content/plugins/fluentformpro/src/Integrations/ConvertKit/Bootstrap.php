<?php

namespace FluentFormPro\Integrations\ConvertKit;

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
            'ConvertKit',
            'convertkit',
            '_fluentform_convertkit_settings',
            'convertkit_feed',
            25
        );

        $this->logo = fluentFormMix('img/integrations/convertkit.png');

        $this->description = 'Connect ConvertKit with Fluent Forms and create subscription forms right into WordPress and grow your list.';

        $this->registerAdminHooks();

      //  add_filter('fluentform/notifying_async_convertkit', '__return_false');
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('ConvertKit Settings', 'fluentformpro'),
            'menu_description' => __('ConvertKit is email marketing software for creators. Use Fluent Forms to collect customer information and automatically add it as ConvertKit subscriber list.', 'fluentformpro'),
            'valid_message'    => __('Your ConvertKit API Key is valid', 'fluentformpro'),
            'invalid_message'  => __('Your ConvertKit API Key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'apiKey'    => [
                    'type'        => 'text',
                    'placeholder' => __('API Key', 'fluentformpro'),
                    'label_tips'  => __("Enter your ConvertKit API Key, if you do not have <br>Please login to your ConvertKit account and go to<br>Profile -> Account Settings -> Account Info", 'fluentformpro'),
                    'label'       => __('ConvertKit API Key', 'fluentformpro'),
                ],
                'apiSecret' => [
                    'type'        => 'password',
                    'placeholder' => __('API Secret', 'fluentformpro'),
                    'label_tips'  => __("Enter your ConvertKit API Secret, if you do not have <br>Please login to your ConvertKit account and go to<br>Profile -> Account Settings -> Account Info", 'fluentformpro'),
                    'label'       => __('ConvertKit API Secret', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your ConvertKit API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect ConvertKit', 'fluentformpro'),
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
            'apiKey'    => '',
            'apiSecret' => '',
            'status'    => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (!$settings['apiKey']) {
            $integrationSettings = [
                'apiKey'    => '',
                'apiSecret' => '',
                'status'    => false
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
            $integrationSettings = [
                'apiKey'    => sanitize_text_field($settings['apiKey']),
                'apiSecret' => sanitize_text_field($settings['apiSecret']),
                'status'    => false
            ];
            update_option($this->optionKey, $integrationSettings, 'no');

            $api = new API($settings['apiKey'], $settings['apiSecret']);

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
            'apiKey'    => sanitize_text_field($settings['apiKey']),
            'apiSecret' => sanitize_text_field($settings['apiSecret']),
            'status'    => true
        ];

        // Update the reCaptcha details with siteKey & secretKey.
        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your ConvertKit api key has been verified and successfully set', 'fluentformpro'),
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-convertkit-settings'),
            'configure_message'     => __('ConvertKit is not configured yet! Please configure your ConvertKit api first', 'fluentformpro'),
            'configure_button_text' => __('Set ConvertKit API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'         => '',
            'list_id'      => '',
            'email'        => '',
            'first_name'   => '',
            'fields'       => (object)[],
            'tags' => [],
            'tag_ids_selection_type' => 'simple',
            'tag_routers'            => [],
            'conditionals' => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'resubscribe'  => false,
            'enabled'      => true
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
                    'label'       => __('ConvertKit Form', 'fluentformpro'),
                    'placeholder' => __('Select ConvertKit Form', 'fluentformpro'),
                    'tips'        => __('Select the ConvertKit Form you would like to use to add subscriber', 'fluentformpro'),
                    'component'   => 'list_ajax_options',
                    'options'     => $this->getLists(),
                ],
                [
                    'key'                => 'fields',
                    'require_list'       => true,
                    'label'              => __('Map Fields', 'fluentformpro'),
                    'tips'               => __('Select which Fluent Forms fields pair with their<br /> respective ConvertKit fields.', 'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('ConvertKit Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
                    'primary_fileds'     => [
                        [
                            'key'           => 'email',
                            'label'         => __('Email Address', 'fluentformpro'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ],
                        [
                            'key'   => 'first_name',
                            'label' => __('First Name', 'fluentformpro')
                        ]
                    ]
                ],
                [
                    'tips'         => __('Select tags for this subscriber.', 'fluentformpro'),
                    'key'          => 'tags',
                    'require_list' => true,
                    'label'        => __('Contact Tags', 'fluentformpro'),
                    'placeholder' => __('Select Tags', 'fluentformpro'),
                    'component'    => 'selection_routing',
                    'simple_component' => 'select',
                    'routing_input_type' => 'select',
                    'routing_key'  => 'tag_ids_selection_type',
                    'settings_key' => 'tag_routers',
                    'is_multiple'  => true,
                    'labels'       => [
                        'choice_label'      => __('Enable Dynamic Tag Selection', 'fluentformpro'),
                        'input_label'       => '',
                        'input_placeholder' => __('Set Tag', 'fluentformpro')
                    ],
                    'options'      => $this->getTags()
                ],
                [
                    'require_list' => true,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow ConvertKit integration conditionally based on your submission values', 'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list'    => true,
                    'key'             => 'enabled',
                    'label'           => __('Status', 'fluentformpro'),
                    'component'       => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro'),
                ]
            ],
            'button_require_list' => true,
            'integration_title'   => $this->title
        ];
    }

    protected function getLists()
    {
        $api = $this->getRemoteClient();
        $lists = $api->getLists();

        $formateddLists = [];
        foreach ($lists as $list) {
            $formateddLists[$list['id']] = $list['name'];
        }
        return $formateddLists;
    }

    protected function getTags()
    {
        $api = $this->getRemoteClient();
        $tags = $api->getTags();
        $formatedTags = [];
        foreach ($tags as $tag) {
            $formatedTags[strval($tag['id'])] = $tag['name'];
        }
        return $formatedTags;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        $api = $this->getRemoteClient();
        if (!$api) {
            return [];
        }
        $fields = $api->getCustomFields();

        $formattedFields = [];

        foreach ($fields as $field) {
            $formattedFields[$field['key']] = $field['label'];
        }

        return $formattedFields;
    }


    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        if (!is_email($feedData['email'])) {
            $feedData['email'] = ArrayHelper::get($formData, $feedData['email']);
        }

        if(!is_email($feedData['email'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('ConvertKit API call has been skipped because no valid email available', 'fluentformpro'));
            return;
        }

        $subscriber = [
            'email'     => $feedData['email'],
            'first_name' => ArrayHelper::get($feedData, 'first_name')
        ];

        $customFields = [];
        foreach (ArrayHelper::get($feedData, 'fields', []) as $key => $value) {
            if (!$value) {
                continue;
            }
            $customFields[$key] = $value;
        }

        $tags = $this->getSelectedTagIds($feedData, $formData, 'tags');
        if ($tags) {
            $subscriber['tags'] = $tags;
        }

        if($customFields) {
            $subscriber['fields'] = $customFields;
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

        $api = $this->getRemoteClient();
        $response = $api->subscribe($feedData['list_id'], $subscriber);

        if(is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
        } else {
            do_action('fluentform/integration_action_result', $feed, 'success', __('ConvertKit feed has been successfully initialed and pushed data', 'fluentformpro'));
        }
    }



    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new API($settings['apiKey'], $settings['apiSecret']);
    }

}
