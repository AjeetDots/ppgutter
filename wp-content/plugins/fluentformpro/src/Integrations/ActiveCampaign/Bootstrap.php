<?php

namespace FluentFormPro\Integrations\ActiveCampaign;

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
            'ActiveCampaign',
            'activecampaign',
            '_fluentform_activecampaign_settings',
            'fluentform_activecampaign_feed',
            16
        );

        $this->logo = fluentFormMix('img/integrations/activecampaign.png');

        $this->description = __('Create signup forms in WordPress and connect ActiveCampaign to grow your list easily.', 'fluentformpro');

        $this->registerAdminHooks();

      //  add_filter('fluentform/notifying_async_activecampaign', '__return_false');
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo' => $this->logo,
            'menu_title' => __('ActiveCampaign API Settings', 'fluentformpro'),
            'menu_description' => __('ActiveCampaign is an integrated email marketing, marketing automation, and small business CRM. Save time while growing your business with sales automation. Use Fluent Forms to collect customer information and automatically add it to your ActiveCampaign list. If you don\'t have an ActiveCampaign account, you can <a href="https://www.activecampaign.com/" target="_blank">sign up for one here.</a>', 'fluentformpro'),
            'valid_message' => __('Your ActiveCampaign configuration is valid', 'fluentformpro'),
            'invalid_message' => __('Your ActiveCampaign configuration is invalid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields' => [
                'apiUrl' => [
                    'type' => 'text',
                    'placeholder' => 'API URL',
                    'label_tips' => __("Please Provide your ActiveCampaign API URL", 'fluentformpro'),
                    'label' => __('ActiveCampaign API URL', 'fluentformpro'),
                ],
                'apiKey' => [
                    'type' => 'password',
                    'placeholder' => 'API Key',
                    'label_tips' => __("Enter your ActiveCampaign API Key, if you do not have <br>Please log in to your ActiveCampaign account and find the api key", 'fluentformpro'),
                    'label' => __('ActiveCampaign API Key', 'fluentformpro'),
                ]
            ],
            'hide_on_valid' => true,
            'discard_settings' => [
                'section_description' => __('Your ActiveCampaign API integration is up and running', 'fluentformpro'),
                'button_text' => __('Disconnect ActiveCampaign', 'fluentformpro'),
                'data' => [
                    'apiKey' => ''
                ],
                'show_verify' => true
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
            'apiUrl' => '',
            'status' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (!$settings['apiKey']) {
            $integrationSettings = [
                'apiKey' => '',
                'apiUrl' => '',
                'status' => false
            ];
            // Update the reCaptcha details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');
            wp_send_json_success([
                'message' => __('Your settings has been updated and discarded', 'fluentformpro'),
                'status' => false
            ], 200);
        }

        $settings['apiUrl'] = trim((string) ArrayHelper::get($settings, 'apiUrl'));
        if ($settings['apiUrl'] && !preg_match('#^https?://#i', $settings['apiUrl'])) {
            $settings['apiUrl'] = 'https://' . ltrim($settings['apiUrl'], '/');
        }
        $allowHttp = (bool) apply_filters(
            'fluentform/activecampaign_allow_http', false, wp_parse_url($settings['apiUrl'], PHP_URL_HOST)
        );
        if (0 === stripos($settings['apiUrl'], 'http://') && !$allowHttp) {
            wp_send_json_error([
                'status'  => false,
                'message' => __('ActiveCampaign API URL must use HTTPS.', 'fluentformpro'),
            ], 400);
        }

        try {
            $settings['status'] = false;
            update_option($this->optionKey, $settings, 'no');
            $api = new ActiveCampaignApi($settings['apiUrl'], $settings['apiKey']);
            if ($api->auth_test()) {
                $settings['status'] = true;
                update_option($this->optionKey, $settings, 'no');

                 wp_send_json_success([
                    'status' => true,
                    'message' => __('Your settings has been updated!', 'fluentformpro')
                ], 200);
            }
            throw new \Exception(__('Invalid Credentials', 'fluentformpro'), 400);

        } catch (\Exception $e) {
            wp_send_json_error([
                'status' => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        }


    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title' => $this->title . ' Integration',
            'logo' => $this->logo,
            'is_active' => $this->isConfigured(),
            'configure_title' => __('Configuration required!', 'fluentformpro'),
            'global_configure_url' => admin_url('admin.php?page=fluent_forms_settings#general-activecampaign-settings'),
            'configure_message' => __('ActiveCampaign is not configured yet! Please configure your ActiveCampaign API first', 'fluentformpro'),
            'configure_button_text' => __('Set ActiveCampaign API', 'fluentformpro'),
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name' => '',
            'list_id' => '',
            'fieldEmailAddress' => '',
            'custom_field_mappings' => (object)[],
            'default_fields' => (object)[],
            'note' => '',
            'tags' => '',
            'tag_routers'            => [],
            'tag_ids_selection_type' => 'simple',
            'conditionals' => [
                'conditions' => [],
                'status' => false,
                'type' => 'all'
            ],
            'instant_responders' => false,
            'last_broadcast_campaign' => false,
            'enabled' => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields' => [
                [
                    'key' => 'name',
                    'label' => __('Name','fluentformpro'),
                    'required' => true,
                    'placeholder' => __('Your Feed Name','fluentformpro'),
                    'component' => 'text'
                ],
                [
                    'key' => 'list_id',
                    'label' => __('ActiveCampaign List','fluentformpro'),
                    'placeholder' => __('Select ActiveCampaign Mailing List','fluentformpro'),
                    'tips' => __('Select the ActiveCampaign Mailing List you would like to add your contacts to.','fluentformpro'),
                    'component' => 'list_ajax_options',
                    'options' => $this->getLists(),
                ],
                [
                    'key' => 'custom_field_mappings',
                    'require_list' => true,
                    'label' => __('Map Fields','fluentformpro'),
                    'tips' => __('Select which Fluent Forms fields pair with their<br /> respective ActiveCampaign fields.','fluentformpro'),
                    'component' => 'map_fields',
                    'field_label_remote' => __('ActiveCampaign Field','fluentformpro'),
                    'field_label_local' => __('Form Field','fluentformpro'),
                    'primary_fileds' => [
                        [
                            'key' => 'fieldEmailAddress',
                            'label' => __('Email Address','fluentformpro'),
                            'required' => true,
                            'input_options' => 'emails'
                        ]
                    ],
                    'default_fields' => [
                        array(
                            'name' => 'first_name',
                            'label' => esc_html__('First Name', 'fluentformpro'),
                            'required' => false
                        ),
                        array(
                            'name' => 'last_name',
                            'label' => esc_html__('Last Name', 'fluentformpro'),
                            'required' => false
                        ),
                        array(
                            'name' => 'phone',
                            'label' => esc_html__('Phone Number', 'fluentformpro'),
                            'required' => false
                        ),
                        array(
                            'name' => 'orgname',
                            'label' => esc_html__('Organization Name', 'fluentformpro'),
                            'required' => false
                        )
                    ]
                ],
                [
                    'key' => 'tags',
                    'require_list' => true,
                    'label' => __('Tags','fluentformpro'),
                    'tips' => __('Associate tags to your ActiveCampaign contacts with a comma separated list (e.g. new lead, FluentForms, web source). Commas within a merge tag value will be created as a single tag.','fluentformpro'),
                    'component'    => 'selection_routing',
                    'simple_component' => 'value_text',
                    'routing_input_type' => 'text',
                    'routing_key'  => 'tag_ids_selection_type',
                    'settings_key' => 'tag_routers',
                    'labels'       => [
                        'choice_label'      => __('Enable Dynamic Tag Input','fluentformpro'),
                        'input_label'       => '',
                        'input_placeholder' => 'Tag'
                    ],
                    'inline_tip' => __('Please provide each tag by comma separated value, You can use dynamic smart codes','fluentformpro'),
                ],
                [
                    'key' => 'note',
                    'require_list' => true,
                    'label' => __('Note','fluentformpro'),
                    'tips' => __('You can write a note for this contact','fluentformpro'),
                    'component' => 'value_textarea'
                ],
                [
                    'key' => 'double_optin_form',
                    'require_list' => true,
                    'label' => __('Double Opt-In Form','fluentformpro'),
                    'tips' => __('Select which ActiveCampaign form will be used when exporting to ActiveCampaign to send the opt-in email.','fluentformpro'),
                    'component' => 'list_select_filter',
                    'filter_by' => 'list_id',
                    'parsedType' => 'number',
                    'placeholder' => __('Select Double Opt-in Form','fluentformpro'),
                    'options' => $this->getAcForms(),
                ],
                [
                    'key' => 'instant_responders',
                    'require_list' => true,
                    'label' => __('Instant Responders','fluentformpro'),
                    'tips' => __('When the instant responders option is enabled, ActiveCampaign will<br/>send any instant responders setup when the contact is added to the<br/>list. This option is not available to users on a free trial.','fluentformpro'),
                    'component' => 'checkbox-single',
                    'checkbox_label' => __('Enable Instant Responder','fluentformpro')
                ],
                [
                    'key' => 'last_broadcast_campaign',
                    'require_list' => true,
                    'label' => __('Last Broadcast Campaign','fluentformpro'),
                    'tips' => __('When send the last broadcast campaign option is enabled,<br/>ActiveCampaign will send the last campaign sent out to the list<br/>to the contact being added. This option is not available to users<br/>on a free trial.','fluentformpro'),
                    'component' => 'checkbox-single',
                    'checkbox_label' => __('Enable Send the last broadcast campaign','fluentformpro')
                ],
                [
                    'require_list' => true,
                    'key' => 'conditionals',
                    'label' => __('Conditional Logics','fluentformpro'),
                    'tips' => __('Allow ActiveCampaign integration conditionally based on your submission values','fluentformpro'),
                    'component' => 'conditional_block'
                ],
                [
                    'require_list' => true,
                    'key' => 'enabled',
                    'label' => __('Status','fluentformpro'),
                    'component' => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed','fluentformpro')
                ]
            ],
            'button_require_list' => true,
            'integration_title' => $this->title
        ];
    }

    protected function getLists()
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }

        $lists = $api->get_lists();

        $formattedLists = [];
        foreach ($lists as $list) {
            if (is_array($list)) {
                $formattedLists[strval($list['id'])] = $list['name'];
            }
        }

        return $formattedLists;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        $fields = [];
        $api = $this->getApiClient();
        $response = $api->get_custom_fields();
        if ($response['result_code']) {
            $fields = array_filter($response, function ($item) {
                return is_array($item);
            });
            $formattedFileds = [];
            foreach ($fields as $field) {
                $formattedFileds[$field['id']] = $field['title'];
            }
            return $formattedFileds;
        }
        return $fields;
    }

    /**
     * Prepare ActiveCampaign forms for feed field.
     *
     * @return array
     */
    public function getAcForms()
    {
        $forms = array();
        $api = $this->getApiClient();
        // Get available ActiveCampaign forms.
        $ac_forms = $api->get_forms();

        // Add ActiveCampaign forms to array and return it.
        if (!empty($ac_forms)) {
            foreach ($ac_forms as $form) {
                if (!is_array($form)) {
                    continue;
                }
                if ($form['sendoptin'] == 0 || !is_array($form['lists'])) {
                    continue;
                }
                $forms[] = [
                    'label' => $form['name'],
                    'value' => 'item_' . $form['id'],
                    'lists' => $form['lists']
                ];
            }
        }
        return $forms;
    }

    /*
     * Submission Broadcast Handler
     */

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        if (!is_email($feedData['fieldEmailAddress'])) {
            $feedData['fieldEmailAddress'] = ArrayHelper::get($formData, $feedData['fieldEmailAddress']);
        }

        if (!is_email($feedData['fieldEmailAddress'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('Active Campaign API call has been skipped because no valid email available', 'fluentformpro'));
            return;
        }

        $addData = [
            'email' => $feedData['fieldEmailAddress'],
            'first_name' => ArrayHelper::get($feedData, 'default_fields.first_name'),
            'last_name' => ArrayHelper::get($feedData, 'default_fields.last_name'),
            'phone' => ArrayHelper::get($feedData, 'default_fields.phone'),
            'orgname' => ArrayHelper::get($feedData, 'default_fields.orgname'),
        ];

        $tags = $this->getSelectedTagIds($feedData, $formData, 'tags');
        if(!is_array($tags)) {
            $tags = explode(',', $tags);
        }

        $tags = array_map('trim', $tags);
        $tags = array_filter($tags);

        if ($tags) {
            $addData['tags'] = implode(',', $tags);
        }

        $list_id = $feedData['list_id'];
        $addData['p[' . $list_id . ']'] = $list_id;
        $addData['status[' . $list_id . ']'] = '1';

        foreach (ArrayHelper::get($feedData, 'custom_field_mappings', []) as $key => $value) {
            if (!$value) {
                continue;
            }
            $contact_key = 'field[' . $key . ',0]';
            $addData[$contact_key] = $value;
        }

        if (ArrayHelper::isTrue($feedData, 'instant_responders')) {
            $addData['instantresponders[' . $list_id . ']'] = 1;
        }

        if (ArrayHelper::isTrue($feedData, 'last_broadcast_campaign')) {
            $addData['lastmessage[' . $list_id . ']'] = 1;
        }

        if (!empty($feedData['double_optin_form'])) {
            $formId = str_replace('item_', '', $feedData['double_optin_form']);
            if ($formId) {
                $addData['form'] = $formId;
            }
        }

        $addData = array_filter($addData);
    
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

        // Now let's prepare the data and push to hubspot
        $api = $this->getApiClient();
        $response = $api->sync_contact($addData);

        if (is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
            return false;
        } else if ($response['result_code'] == 1) {
            do_action('fluentform/integration_action_result', $feed, 'success', __('Active Campaign has been successfully initialed and pushed data', 'fluentformpro'));
            if (ArrayHelper::get($feedData, 'note')) {
                // Contact Added
                $api->add_note($response['subscriber_id'], $list_id, ArrayHelper::get($feedData, 'note'));
            }
            return true;
        }

        do_action('fluentform/integration_action_result', $feed, 'failed', $response['result_message']);

        return false;
    }


    protected function getApiClient()
    {
        $settings = get_option($this->optionKey);

        return new ActiveCampaignApi(
            $settings['apiUrl'], $settings['apiKey']
        );
    }
}
