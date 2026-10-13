<?php
namespace FluentFormPro\Integrations\Sendinblue;

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
            'Brevo (formerly SendInBlue)',
            'sendinblue',
            '_fluentform_sendinblue_settings',
            'fluentform_sendinblue_feed',
            16
        );

        $this->logo = fluentFormMix('img/integrations/brevo.png');

        $this->description = 'Create contacts easily on your Brevo (formerly SendInBlue) email list with Fluent Forms Brevo integration.';

        $this->registerAdminHooks();

    //    add_filter('fluentform/notifying_async_sendinblue', '__return_false');
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('Brevo API Settings', 'fluentformpro'),
            'menu_description' => __('Brevo (formerly SendInBlue) is an integrated email marketing, marketing automation, and small business CRM. Save time while growing your business with sales automation. Use Fluent Forms to collect customer information and automatically add it to your (formerly Sendinblue) list. If you don\'t have an (formerly Sendinblue) account, you can <a href="https://www.brevo.com//" target="_blank">sign up for one here.</a>', 'fluentformpro'),
            'valid_message'    => __('Your Brevo configuration is valid', 'fluentformpro'),
            'invalid_message'  => __('Your Brevo configuration is invalid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'apiKey'    => [
                    'type'        => 'password',
                    'placeholder' => __('V3 API Key', 'fluentformpro'),
                    'label_tips'  => __("Enter your Brevo API Key, if you do not have <br>Please login to your Brevo account and find the api key", 'fluentformpro'),
                    'label'       => __('Brevo V3 API Key', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your Brevo API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Brevo', 'fluentformpro'),
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
            'status'    => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (!$settings['apiKey']) {
            $integrationSettings = [
                'apiKey'    => '',
                'status'    => false
            ];

            // Update the details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_success([
                'message' => __('Your settings has been updated and discarded', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        try {
            $settings['status'] = false;
            update_option($this->optionKey, $settings, 'no');
            $api = new SendinblueApi($settings['apiKey']);
            $auth = $api->auth_test();
            if (isset($auth['email'])) {
                $settings['status'] = true;
                update_option($this->optionKey, $settings, 'no');
                wp_send_json_success([
                    'status'  => true,
                    'message' => __('Your settings has been updated!', 'fluentformpro')
                ], 200);
            }
            throw new \Exception(__('Invalid Credentials', 'fluentformpro'), 400);
        } catch (\Exception $e) {
            wp_send_json_error([
                'status'  => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        }
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-sendinblue-settings'),
            'configure_message'     => __('Brevo is not configured yet! Please configure your Brevo API first', 'fluentformpro'),
            'configure_button_text' => __('Set Brevo API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'                    => '',
            'list_id'                 => '',
            'fieldEmailAddress'       => '',
            'custom_field_mappings'   => (object)[],
            'default_fields'          => (object)[],
            'other_fields_mapping' => [
                [
                    'item_value' => '',
                    'label' => ''
                ]
            ],
            'ip_address' => '{ip}',
            'conditionals'            => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'                 => true
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
                    'label'       => __('Brevo Segment', 'fluentformpro'),
                    'placeholder' => __('Select Brevo Segment', 'fluentformpro'),
                    'tips'        => __('Select the Brevo segment you would like to add your contacts to.', 'fluentformpro'),
                    'component'   => 'list_ajax_options',
                    'options'     => $this->getLists()
                ],
                [
                    'key'                => 'custom_field_mappings',
                    'require_list'       => true,
                    'label'              => __('Map Fields', 'fluentformpro'),
                    'tips'               => __('Select which Fluent Forms fields pair with their<br /> respective Brevo fields.', 'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('Brevo Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
                    'primary_fileds'     => [
                        [
                            'key'           => 'fieldEmailAddress',
                            'label'         => __('Email Address', 'fluentformpro'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ]
                    ],
                    'default_fields'     => [
                        array(
                            'name'     => 'first_name',
                            'label'    => esc_html__('First Name', 'fluentformpro'),
                            'required' => false
                        ),
                        array(
                            'name'     => 'last_name',
                            'label'    => esc_html__('Last Name', 'fluentformpro'),
                            'required' => false
                        )
                    ]
                ],
                [
                    'key'                => 'other_fields_mapping',
                    'require_list'       => true,
                    'label'              => __('Other Fields', 'fluentformpro'),
                    'tips'               => __('Select which Fluent Forms fields pair with their<br /> respective Brevo fields.', 'fluentformpro'),
                    'component'          => 'dropdown_many_fields',
                    'field_label_remote' => __('Brevo Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
                    'options'            => $this->attributes()
                ],
                [
                    'require_list' => true,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow Brevo integration conditionally based on your submission values', 'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list'    => true,
                    'key'             => 'enabled',
                    'label'           => __('Status', 'fluentformpro'),
                    'component'       => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro')
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

        $lists = $api->getLists();

        if(!$lists) {
            return [];
        }

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
        return [];
    }

    public function attributes()
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }

        $attributes = $api->attributes();

        if (empty($attributes['attributes'])) {
            return [];
        }

        $formattedAttributes = [];
        foreach ($attributes['attributes'] as $attribute) {
            if (is_array($attribute)) {
                $formattedAttributes[$attribute['name']] = $attribute['name'];
            }
        }
        return $formattedAttributes;
    }

    protected function getAttributeTypes()
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }

        $attributes = $api->attributes();
        if (empty($attributes['attributes'])) {
            return [];
        }

        $types = [];
        foreach ($attributes['attributes'] as $attribute) {
            if (is_array($attribute) && !empty($attribute['name']) && !empty($attribute['type'])) {
                $types[$attribute['name']] = $attribute['type'];
            }
        }
        return $types;
    }

    /**
     * Format a form field value for the Brevo contact attributes API.
     *
     * Per Brevo docs, attributes accept: number (double), string, boolean,
     * or array of strings. Values that don't match the attribute type are
     * silently ignored by the API.
     *
     * @see https://developers.brevo.com/reference/create-contact
     *
     * @param mixed       $value The raw form field value.
     * @param string|null $type  Target Brevo attribute type (text, boolean, date, float, multiple-choice, ...).
     * @return mixed The formatted value.
     */
    protected function formatAttributeValue($value, $type = null)
    {
        // Multi-choice / category attributes accept an array of strings.
        // ShortCodeParser collapses multi-select / checkbox arrays into a
        // comma-joined string, so split it back to an array for these.
        $isMultiValue = in_array($type, ['multiple-choice', 'category'], true);
        if ($isMultiValue && is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), function ($v) {
                return $v !== '';
            }));
        }

        // Safety net for programmatic callers that may pass native booleans.
        if (is_bool($value)) {
            return $value;
        }

        // Arrays from multi-select/checkbox fields
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), function ($v) {
                return $v !== '';
            }));
        }

        if (!is_string($value)) {
            return $value;
        }

        $lower = strtolower(trim($value));

        // Convert common boolean-like form values to actual booleans.
        // Brevo boolean attributes require JSON true/false — string
        // values like "Yes" are silently ignored by the API.
        // Note: This applies to all mapped attributes regardless of their
        // Brevo type. A string attribute whose value is exactly "yes"/"no"
        // will be sent as a boolean. In practice, boolean attributes
        // (opt-in, newsletter) are the primary use case for these values.
        if (in_array($lower, ['yes', 'true', 'on', 'checked'], true)) {
            return true;
        }
        if (in_array($lower, ['no', 'false', 'off', 'unchecked'], true)) {
            return false;
        }

        return $value;
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
            do_action('fluentform/integration_action_result', $feed, 'failed', __('Brevo API call has been skipped because no valid email available', 'fluentformpro'));
            return;
        }

        $addData = [];
        $attributes = new \stdClass;
        $addData['listIds'] = [absint($feedData['list_id'])] ;
        $addData['email'] = $feedData['fieldEmailAddress'];

        $defaultFields = ArrayHelper::get($feedData, 'default_fields');
        $attributes->FIRSTNAME = $defaultFields['first_name'];
        $attributes->LASTNAME = $defaultFields['last_name'];

        $attributeTypes = $this->getAttributeTypes();

        foreach (ArrayHelper::get($feedData, 'other_fields_mapping') as $item) {
            $label = $item['label'];
            if ($item['item_value'] !== '' && $item['item_value'] !== null) {
                $type = isset($attributeTypes[$label]) ? $attributeTypes[$label] : null;
                $attributes->$label = $this->formatAttributeValue($item['item_value'], $type);
            }
        }
        
        $addData['attributes'] = $attributes;
    
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

        $api = $this->getApiClient();
        
        $response = $api->addContact($addData);

        if (!is_wp_error($response) && !empty($response['id'])) {
            do_action('fluentform/integration_action_result', $feed, 'success', __('Brevo feed has been successfully initialed and pushed data', 'fluentformpro'));
        } else {
            $error = __('API Error when submitting Data', 'fluentformpro');
            if(is_wp_error($response)) {
                $error = $response->get_error_message();
            }
            if(is_array($error)) {
                $error =  $response->get_error_messages()[0];
            }
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }


    protected function getApiClient()
    {
        $settings = get_option($this->optionKey);
        return new SendinblueApi(
            $settings['apiKey']
        );
    }
}
