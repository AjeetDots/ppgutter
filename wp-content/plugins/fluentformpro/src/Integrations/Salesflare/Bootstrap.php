<?php

namespace FluentFormPro\Integrations\Salesflare;

defined('ABSPATH') or die;

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Integrations\Salesflare\API;

class Bootstrap extends IntegrationManagerController
{
    private $key = 'salesflare';
    private $name = 'Salesflare';
    
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            ucfirst($this->key),
            $this->key,
            '_fluentform_' . $this->key . '_settings',
            $this->key . '_feeds',
            98
        );
        $this->logo = fluentFormMix('img/integrations/salesflare.png');
        $this->description = 'Create Salesflare contact from WordPress, so you can grow your contact list.';
        $this->registerAdminHooks();
//        add_filter('fluentform/notifying_async_'.$this->key, '__return_false');
    }
    
    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            // translators: %s is the integration name
            'title' => sprintf(__('%s Integration', 'fluentformpro'), $this->name),
            'logo' => $this->logo,
            'is_active' => $this->isConfigured(),
            'configure_title' => __('Configuration required!', 'fluentformpro'),
            'global_configure_url' => admin_url(
                'admin.php?page=fluent_forms_settings#general-' . $this->key . '-settings'
            ),
            // translators: %s is the integration name
            'configure_message' => sprintf(
                __('%s is not configured yet! Please configure the addon first.', 'fluentformpro'),
                $this->name
            ),
            'configure_button_text' => __('Set API', 'fluentformpro')
        ];
        
        return $integrations;
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
    
    public function getGlobalFields($fields)
    {
        return [
            'logo' => $this->logo,
            // translators: %s is the integration name
            'menu_title' => sprintf(__('%s Integration Settings', 'fluentformpro'), $this->name),
            'menu_description' => __(
                'Copy the API Key from Salesflare settings API keys and paste it here, then click on Save.',
                'fluentformpro'
            ),
            // translators: %s is the integration name
            'valid_message' => sprintf(__('Your %s API Key is valid', 'fluentformpro'), $this->name),
            // translators: %s is the integration name
            'invalid_message' => sprintf(__('Your %s API Key is not valid', 'fluentformpro'), $this->name),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields' => [
                'apiKey' => [
                    'type' => 'text',
                    'placeholder' => __('API Key', 'fluentformpro'),
                    // translators: %s is the integration name
                    'label_tips' => sprintf(
                        __(
                            'Enter your  %s Api Key, Copy the API Code and paste it here, then click on Save button',
                            'fluentformpro'
                        ),
                        $this->name
                    ),
                    // translators: %s is the integration name
                    'label' => sprintf(__('%s API Key', 'fluentformpro'), $this->name),
                ],
            ],
            'hide_on_valid' => true,
            'discard_settings' => [
                // translators: %s is the integration name
                'section_description' => sprintf(
                    __('Your %s API integration is up and running', 'fluentformpro'),
                    $this->name
                ),
                // translators: %s is the integration name
                'button_text' => sprintf(__('Disconnect %s', 'fluentformpro'), $this->name),
                'data' => [
                    'apiKey' => ''
                ],
                'show_verify' => true
            ]
        ];
    }
    
    public function saveGlobalSettings($settings)
    {
        if (!$settings['apiKey']) {
            $integrationSettings = [
                'apiKey' => '',
                'status' => false
            ];
            update_option($this->optionKey, $integrationSettings, 'no');
            wp_send_json_success([
                'message' => __('Your settings has been updated and discarded', 'fluentformpro'),
                'status' => false
            ], 200);
        }
        
        try {
            $apiKey = $settings['apiKey'];
            update_option($this->optionKey, [
                'status' => false,
                'apiKey' => $apiKey
            ], 'no');
            
            $response = (new API($apiKey))->ping();
            
            if (!is_wp_error($response)) {
                update_option($this->optionKey, [
                    'status' => true,
                    'apiKey' => $apiKey
                ], 'no');
                
                wp_send_json_success([
                    'status' => true,
                    'message' => __('Your settings has been updated!', 'fluentformpro')
                ], 200);
            }
            
            throw new \Exception($response->get_error_message(), 400);
        } catch (\Exception $e) {
            wp_send_json_error([
                'status' => false,
                'message' => 'Invalid API key'
            ], $e->getCode());
        }
    }
    
    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name' => '',
            'list_id' => '',
            'email' => '',
            'firstname' => '',
            'lastname' => '',
            'website' => '',
            'company' => '',
            'phone' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'tags' => '',
            'fields' => (object)[],
            'custom_fields_mapping' => [
                [
                    'item_value' => '',
                    'label' => ''
                ]
            ],
            'conditionals' => [
                'conditions' => [],
                'status' => false,
                'type' => 'all'
            ],
            'contact_update' => false,
            'enabled' => true
        ];
    }
    
    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields' => [
                [
                    'key' => 'name',
                    'label' => __('Name', 'fluentformpro'),
                    'required' => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component' => 'text'
                ],
                [
                    'key' => 'fields',
                    'require_list' => false,
                    'label' => __('Map Fields', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips' => __('Select which Fluent Forms fields pair with their<br /> respective ' . $this->name . ' fields.', 'fluentformpro'),
                    'component' => 'map_fields',
                    'field_label_remote' => $this->name . ' Field',
                    'field_label_local' => __('Form Field', 'fluentformpro'),
                    'primary_fileds' => [
                        [
                            'key' => 'email',
                            'label' => __('Email Address', 'fluentformpro'),
                            'required' => true,
                            'input_options' => 'emails'
                        ],
                        [
                            'key' => 'firstname',
                            'label' => __('First Name', 'fluentformpro')
                        ],
                        [
                            'key' => 'lastname',
                            'label' => __('Last Name', 'fluentformpro')
                        ],
                        [
                            'key' => 'phone_number',
                            'label' => __('Phone Number', 'fluentformpro')
                        ],
                        [
                            'key' => 'country',
                            'label' => __('Country', 'fluentformpro')
                        ],
                        [
                            'key' => 'city',
                            'label' => __('City', 'fluentformpro')
                        ],
                        [
                            'key' => 'state_region',
                            'label' => __('State', 'fluentformpro')
                        ],
                        [
                            'key' => 'street',
                            'label' => __('Street', 'fluentformpro')
                        ],
                        [
                            'key' => 'zip',
                            'label' => __('Zip', 'fluentformpro')
                        ],
                    ]
                ],
                [
                    'key' => 'custom_fields_mapping',
                    'require_list' => false,
                    'label' => __('Custom Fields', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips' => __('Select which Fluent Forms fields pair with their<br /> respective ' . $this->name . ' fields.', 'fluentformpro'),
                    'component' => 'dropdown_many_fields',
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'field_label_remote' => __($this->name . ' Field', 'fluentformpro'),
                    'field_label_local' => __('Form Field', 'fluentformpro'),
                    'options' => $this->customFields()
                ],
                [
                    'require_list' => false,
                    'key' => 'tags',
                    'label' => __('Tags', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips' => __('Associate tags to your ' . $this->name . ' contacts with a comma separated list (e.g. new lead, FluentForms, web source). Commas within a merge tag value will be created as a single tag.', 'fluentformpro'),
                    'component' => 'value_text',
                    'inline_tip' => __('Please provide each tag by comma separated value, You can use dynamic smart codes', 'fluentformpro')
                ],
                [
                    'require_list' => false,
                    'key' => 'conditionals',
                    'label' => __('Conditional Logics', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips' => __('Allow ' . $this->name . ' integration conditionally based on your submission values', 'fluentformpro'),
                    'component' => 'conditional_block'
                ],
                [
                    'require_list' => false,
                    'key' => 'enabled',
                    'label' => __('Status', 'fluentformpro'),
                    'component' => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro')
                ]
            ],
            'button_require_list' => false,
            'integration_title' => $this->title
        ];
    }
    
    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }
    
    public function customFields()
    {
        $api = $this->getApiClient();
        $fields = $api->customFields();

        $formattedFields = [];

        foreach ($fields as $field) {
            if (isset($field['api_field'])) {
                $formattedFields[$field['api_field']] = $field['name'];
            }
        }
        
        return $formattedFields;
    }
    
    protected function getApiClient()
    {
        $settings = $this->getGlobalSettings([]);
        
        return new API(
            $settings['apiKey']
        );
    }
    
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        if (!is_email($feedData['email'])) {
            $feedData['email'] = ArrayHelper::get($formData, $feedData['email']);
        }
        
        if (!is_email($feedData['email'])) {
            do_action(
                'fluentform/integration_action_result',
                $feed,
                'failed',
                $this->name . __(' API called skipped because no valid email available', 'fluentformpro')
            );
            
            return;
        }
        
        $addData = [
            'email' => $feedData['email'],
            'firstname' => ArrayHelper::get($feedData, 'firstname'),
            'lastname' => ArrayHelper::get($feedData, 'lastname'),
            'phone_number' => ArrayHelper::get($feedData, 'phone_number'),
        ];
        if ($this->addressData($feedData)) {
            $addData['address'] = $this->addressData($feedData);
        }
        
        if ($customFields = ArrayHelper::get($feedData, 'custom_fields_mapping')) {
            $customData = [];
            foreach ($customFields as $customField) {
                $customData[$customField['label']] = $customField['item_value'];
            }
            $customData = array_filter($customData);
            if ($customData) {
                $addData['custom'] = $customData;
            }
        }
        
        $tags = ArrayHelper::get($feedData, 'tags');
        if (!is_array($tags)) {
            $tags = explode(',', $tags);
        }
        $tags = array_map('trim', $tags);
        $tags = array_filter($tags);
        if ($tags) {
            $addData['tags'] = $tags;
        }
    
        $addData = apply_filters_deprecated(
            'fluentform_integration_data_' . $this->integrationKey,
            [
                $addData,
                $feed,
                $entry
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/integration_data_' . $this->integrationKey,
            'Use fluentform/integration_data_' . $this->integrationKey . ' instead of fluentform_integration_data_' . $this->integrationKey
        );

        $addData = apply_filters('fluentform/integration_data_' . $this->integrationKey, $addData, $feed, $entry);
        $response = $this->getApiClient()->createContact($addData);
        if (!is_wp_error($response) && isset($response['id'])) {
            do_action(
                'fluentform/integration_action_result',
                $feed,
                'success',
                $this->name . __(' feed has been successfully initialed and pushed data', 'fluentformpro')
            );
        } else {
            $error = is_wp_error($response) ? $response->get_error_messages() : 'API Error when submitting Data';
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }
    
    private function addressData($feedData)
    {
        $addressList = ArrayHelper::only($feedData, [
            'city',
            'country',
            'state_region',
            'street',
            'zip'
        ]);
        $address = [];
        foreach ($addressList as $addressKey => $value) {
            $address[$addressKey] = $value;
        }
    
        if (count(array_filter($address)) > 0) {
            return array_filter($address);
        }
        return false;
    }
    
}
