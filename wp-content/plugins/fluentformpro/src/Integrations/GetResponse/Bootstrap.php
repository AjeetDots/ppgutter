<?php

namespace FluentFormPro\Integrations\GetResponse;

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
            'GetResponse',
            'getresponse',
            '_fluentform_getresponse_settings',
            'fluentform_getresponse_feed',
            15
        );
        $this->description = 'Build your email list with Fluent Forms and GetResponse. Create subscribe forms and collect leads.';
        $this->logo = fluentFormMix('img/integrations/getresponse.png');
        $this->registerAdminHooks();
//        add_filter('fluentform/notifying_async_getresponse', '__return_false');
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
                'message' => __('Your settings has been updated and discarded', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        try {
            $apiKey = $settings['apiKey'];
            update_option($this->optionKey, [
                'status' => false,
                'apiKey' => $apiKey
            ], 'no');

            $response = (new GetResponseApi($apiKey))->ping();
            if (empty($response->codeDescription)) {
                update_option($this->optionKey, [
                    'status' => true,
                    'apiKey' => $apiKey
                ], 'no');
                wp_send_json_success([
                    'status'  => true,
                    'message' => __('Your settings has been updated!', 'fluentformpro')
                ], 200);
            }

            throw new \Exception($response->codeDescription, 400);

        } catch (\Exception $e) {
            wp_send_json_error([
                'status'  => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        }

    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('GetResponse API Settings', 'fluentformpro'),
            'menu_description' => __('GetResponse is an email marketing platform. It enables you to send email newsletters, campaigns, online surveys and follow-up autoresponders. Simple, easy interface. Use Fluent Forms to collect customer information and automatically add it to your GetResponse list. If you don\'t have a GetResponse account, you can <a href="https://www.getresponse.com/" target="_blank">sign up for one here.</a>', 'fluentformpro'),
            'valid_message'    => __('Your GetResponse configuration is valid', 'fluentformpro'),
            'invalid_message'  => __('Your GetResponse configuration is invalid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'apiKey' => [
                    'type'        => 'password',
                    'placeholder' => __('API Key', 'fluentformpro'),
                    'label_tips'  => __("Enter your GetResponse API Key, if you do not have<br />Please login to your GetResponse account and go to<br />Integrations & API from main menu.", 'fluentformpro'),
                    'label'       => __('GetResponse API Key', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your GetResponse API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect GetResponse', 'fluentformpro'),
                'data'                => [
                    'apiKey' => ''
                ]
            ]
        ];
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-getresponse-settings'),
            'configure_message'     => __('GetResponse API Key is not configured yet! Please configure your GetResponse api first', 'fluentformpro'),
            'configure_button_text' => __('Set GetResponse API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'              => '',
            'list_id'           => '',
            'fieldName'         => '',
            'fieldEmailAddress' => '',
            'field_mappings'    => (object)[],
            'dayOfCycle'        => '',
            'conditionals'      => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'           => true
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
                    'label'       => __('GetResponse List', 'fluentformpro'),
                    'placeholder' => __('Select GetResponse List', 'fluentformpro'),
                    'tips'        => __('Select the GetResponse List you would like to add your contacts to.', 'fluentformpro'),
                    'component'   => 'list_ajax_options',
                    'options'     => $this->getLists(),
                ],
                [
                    'key'                => 'field_mappings',
                    'require_list'       => true,
                    'label'              => __('Map Fields', 'fluentformpro'),
                    'tips'               => __('Select which Fluent Forms fields pair with their<br /> respective GetResponse fields.', 'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('GetResponse Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
                    'primary_fileds'     => [
                        [
                            'key'           => 'fieldEmailAddress',
                            'label'         => __('Email Address', 'fluentformpro'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ],
                        [
                            'key'   => 'fieldName',
                            'label' => __('Name', 'fluentformpro'),
                        ]
                    ]
                ],
                [
                    'require_list' => true,
                    'key'          => 'dayOfCycle',
                    'label'        => __('Autoresponder Cycle', 'fluentformpro'),
                    'tips'         => __('The day on which the contacts is in the Autoresponder cycle, keep empty to not include in autoresponder.', 'fluentformpro'),
                    'component'    => 'number'
                ],
                [
                    'require_list' => true,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow GetResponse integration conditionally based on your submission values', 'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list'    => true,
                    'key'             => 'enabled',
                    'label'           => __('Status', 'fluentformpro'),
                    'component'       => 'checkbox-single',
                    'checkbox_label'  => __('Enable This feed', 'fluentformpro'),
                ]
            ],
            'button_require_list' => true,
            'integration_title'   => $this->title
        ];
    }


    protected function getLists()
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }

        $lists = $api->getCampaigns();

        $formattedLists = [];
        foreach ($lists as $list) {
            $formattedLists[$list->campaignId] = $list->name;
        }
        return $formattedLists;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }
        $fields = $api->getCustomFields();

        $formattedFields = [];
       foreach ($fields as $field) {
           $formattedFields[$field->customFieldId] = $field->name;
       }
       return $formattedFields;
    }


    /*
     * Handle Notification Broadcast here
     */
    public function notify($feed, $formData, $entry, $form)
    {

        $feedData = $feed['processedValues'];
        if (!is_email($feedData['fieldEmailAddress'])) {
            $feedData['fieldEmailAddress'] = ArrayHelper::get($formData, $feedData['fieldEmailAddress']);
        }

        if (!is_email($feedData['fieldEmailAddress'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('GetResponse API call has been skipped because no valid email available', 'fluentformpro'));
        }

        $mainFields = ArrayHelper::only($feedData, [
            'fieldName',
            'fieldEmailAddress'
        ]);

        $addData = [
            'email' => $mainFields['fieldEmailAddress'],
            'campaign' => [
                'campaignId' => $feedData['list_id']
            ]
        ];

        if ($nameField = ArrayHelper::get($mainFields, 'fieldName')) {
            $addData['name'] = $nameField;
        }

        $dayOfCycle = (string) ArrayHelper::get($feed,'settings.dayOfCycle','');
        if($dayOfCycle!=''){
            $addData['dayOfCycle'] = $dayOfCycle;
        }

        $customValues = [];
        foreach ($feedData['field_mappings'] as $key => $value) {
            if($value) {
                $customValues[] = [
                    'customFieldId' => $key,
                    'value' => [
                        $value
                    ]
                ];
            }
        }

        if($customValues) {
            $addData['customFieldValues'] = $customValues;
        }

        if($entry->ip) {
            $addData['ipAddress'] = $entry->ip;
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

        // Now let's prepare the data and push
        $api = $this->getApiClient();
        $response = $api->addContact($addData);

       if($response->httpStatus === 400) {
           $error = !empty($response->context[0]->errorDescription) ? $response->context[0]->errorDescription : __('API Error when submitting Data', 'fluentformpro');
           do_action('fluentform/integration_action_result', $feed, 'failed', $error);
       } else {
           do_action('fluentform/integration_action_result', $feed, 'success', __('GetResponse feed has been successfully initialed and pushed data', 'fluentformpro'));
       }

    }


    protected function getApiClient()
    {
        $settings = get_option($this->optionKey);
        return new GetResponseApi((string)$settings['apiKey']);
    }
}
