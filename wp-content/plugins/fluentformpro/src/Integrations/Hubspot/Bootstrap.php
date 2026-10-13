<?php

namespace FluentFormPro\Integrations\Hubspot;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Str;
use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'HubSpot',
            'hubspot',
            '_fluentform_hubspot_settings',
            'hubspot_feed',
            26
        );

        $this->logo = fluentFormMix('img/integrations/hubspot.png');

//         add_filter('fluentform/notifying_async_hubspot', '__return_false');

        $this->description = 'Connect HubSpot with Fluent Forms and subscribe a contact when a form is submitted.';

        $this->registerAdminHooks();
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo' => $this->logo,
            'menu_title' => __('Hubspot API Settings', 'fluentformpro'),
            'menu_description' => __('Hubspot is a CRM software. Use Fluent Forms to collect customer information and automatically add to Hubspot. Please login to your Hubspot account and create a new private app with scopes <b>crm.objects.contacts.read</b>, <b>crm.objects.contacts.write</b>, and <b>crm.schemas.contacts.read</b>, then copy your token. For HubSpot list syncing, also add <b>crm.lists.read</b> and <b>crm.lists.write</b>. Check this <a href="https://wpmanageninja.com/docs/fluent-form/integrations-available-in-wp-fluent-form/hubspot-integration-with-wp-fluent-form-wordpress-plugin/" target="_blank">link</a> for details.', 'fluentformpro'),
            'valid_message' => __('Your Hubspot access token is valid', 'fluentformpro'),
            'invalid_message' => __('Your Hubspot access token is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields' => [
                'accessToken' => [
                    'type' => 'password',
                    'placeholder' => __('Access Token', 'fluentformpro'),
                    'label_tips' => __("Enter your Hubspot access token. If you do not have one, please login to your Hubspot account and create a new private app with contact scopes. For list syncing, also add <b>crm.lists.read</b> and <b>crm.lists.write</b>.", 'fluentformpro'),
                    'label' => __('Hubspot Access Token', 'fluentformpro'),
                ]
            ],
            'hide_on_valid' => true,
            'discard_settings' => [
                'section_description' => __('Your HubSpot API integration is up and running', 'fluentformpro'),
                'button_text' => __('Disconnect HubSpot', 'fluentformpro'),
                'data' => [
                    'accessToken' => ''
                ]
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
            'accessToken' => '',
            'apiKey' => '',
            'status' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (!$settings['accessToken']) {
            $integrationSettings = [
                'accessToken' => '',
                'apiKey' => '',
                'status' => false
            ];
            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_success([
                'message' => __('Your settings has been updated and discarded', 'fluentformpro'),
                'status' => false
            ], 200);
        }

        // Verify API key now
        try {
            $integrationSettings = [
                'accessToken' => sanitize_text_field($settings['accessToken']),
                'apiKey'      => '',
                'status'      => false
            ];
            update_option($this->optionKey, $integrationSettings, 'no');

            $api = new API($settings['apiKey'],$settings['accessToken']);
            $result = $api->auth_test();

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            if (!empty($result['message'])) {
                throw new \Exception($result['message']);
            }

        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }

        // Integration key is verified now, Proceed now

        $integrationSettings = [
            'accessToken' => sanitize_text_field($settings['accessToken']),
            'apiKey' => '',
            'status' => true
        ];

        // Update the reCaptcha details with siteKey & secretKey.
        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your HubSport API  has been verified and successfully set', 'fluentformpro'),
            'status' => true
        ], 200);
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title' => $this->title . ' Integration',
            'logo' => $this->logo,
            'is_active' => $this->isConfigured(),
            'configure_title' => __('Configuration required!', 'fluentformpro'),
            'global_configure_url' => admin_url('admin.php?page=fluent_forms_settings#general-hubspot-settings'),
            'configure_message' => __('HubSpot is not configured yet! Please configure your HubSpot api first', 'fluentformpro'),
            'configure_button_text' => __('Set HubSpot API', 'fluentformpro')
        ];
        return $integrations;
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
            'fields' => (object)[],
            'other_fields_mapping' => [
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
                    'key' => 'list_id',
                    'label' => __('HubSpot List(HubSpot Pro)', 'fluentformpro'),
                    'placeholder' => __('Select HubSpot Mailing List', 'fluentformpro'),
                    'tips' => __('HubSpot just restricted this for Pro Users. Select the HubSpot Mailing List you would like to add your contacts to.', 'fluentformpro'),
                    'component' => 'list_ajax_options',
                    'options' => $this->getLists($settings),
                ],
                [
                    'key' => 'fields',
                    'require_list' => false,
                    'label' => __('Map Fields', 'fluentformpro'),
                    'tips' => __('Select which Fluent Forms fields pair with their<br /> respective HubSpot fields.', 'fluentformpro'),
                    'component' => 'map_fields',
                    'field_label_remote' => __('HubSpot Field', 'fluentformpro'),
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
                            'key' => 'website',
                            'label' => __('Website', 'fluentformpro')
                        ],
                        [
                            'key' => 'company',
                            'label' => __('Company', 'fluentformpro')
                        ],
                        [
                            'key' => 'phone',
                            'label' => __('Phone', 'fluentformpro')
                        ],
                        [
                            'key' => 'address',
                            'label' => __('Address', 'fluentformpro')
                        ],
                        [
                            'key' => 'city',
                            'label' => __('City', 'fluentformpro')
                        ],
                        [
                            'key' => 'state',
                            'label' => __('State', 'fluentformpro')
                        ],
                        [
                            'key' => 'zip',
                            'label' => __('Zip', 'fluentformpro')
                        ],
                    ]
                ],
	            [
		            'key'                => 'other_fields_mapping',
		            'require_list'       => false,
		            'label'              => __('Other Fields', 'fluentformpro'),
		            'tips'               => __('Select which Fluent Forms fields pair with their<br /> respective HubSpot fields.', 'fluentformpro'),
		            'component'          => 'dropdown_many_fields',
		            'field_label_remote' => __('HubSpot Field', 'fluentformpro'),
		            'field_label_local'  => __('Form Field', 'fluentformpro'),
		            'options'            => $this->getOtherFields()
	            ],
                [
                    'require_list' => false,
                    'key' => 'conditionals',
                    'label' => __('Conditional Logics', 'fluentformpro'),
                    'tips' => __('Allow HubSpot integration conditionally based on your submission values', 'fluentformpro'),
                    'component' => 'conditional_block'
                ],
                [
                    'require_list' => false,
                    'key' => 'contact_update',
                    'label' => __('Update', 'fluentformpro'),
                    'component' => 'checkbox-single',
                    'checkbox_label' => __('Enable Contact Update', 'fluentformpro')
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

    protected function getLists($settings = [])
    {
        $api = $this->getRemoteClient();
        $lists = $api->getLists();
        $formattedLists = [];

        if (is_wp_error($lists)) {
            $lists = [];
        }

        foreach ($lists as $list) {
            $listId = ArrayHelper::get($list, 'listId');
            $name = ArrayHelper::get($list, 'name');

            if (!$listId || !$name) {
                continue;
            }

            $formattedLists['v3:' . $listId] = $name;
        }

        /*
         * Backward-compatible editor behavior:
         * preserve legacy list_id as a selectable value so old feeds remain visibly selected.
         */
        $selectedListId = (string) ArrayHelper::get($settings, 'list_id');
        if ($selectedListId && !isset($formattedLists[$selectedListId]) && 0 !== strpos($selectedListId, 'v3:')) {
            $formattedLists[$selectedListId] = __('Previously selected HubSpot list (legacy ID)', 'fluentformpro');
        }

        return $formattedLists;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new API($settings['apiKey'],$settings['accessToken']);
    }

	public function getOtherFields()
	{
		$api = $this->getRemoteClient();
        $allProperties = $api->getContactProperties();

        $unacceptedFields = [
            'email',
            'firstname',
            'lastname',
            'website',
            'company',
            'phone',
            'address',
            'city',
            'state',
            'zip'
        ];

        $formattedFields = [];
        $customFormattedFields = [];

        foreach ($allProperties as $property) {
            if (empty($property)) {
                continue;
            }
            $name      = ArrayHelper::get($property, 'name');
            $fieldType = ArrayHelper::get($property, 'fieldType');
            $type      = ArrayHelper::get($property, 'type');
            $label     = ArrayHelper::get($property, 'label');

            if (empty($property['hubspotDefined'])) {
                $customFormattedFields[$this->formatHubspotFieldKey($name, $fieldType, $type)] = $label;
            } elseif (ArrayHelper::get($property, 'formField') && !in_array($name, $unacceptedFields)) {
                $formattedFields[$this->formatHubspotFieldKey($name, $fieldType, $type)] = $label;
            }
        }

        $formattedFields = array_merge($formattedFields, $customFormattedFields);

		return $formattedFields;
	}

    private function formatHubspotFieldKey($name, $fieldType, $type)
    {
        $parts = [$name];

        if ($fieldType) {
            $parts[] = $fieldType;
        }

        if ($type) {
            $parts[] = $type;
        }

        return implode('*_ff_*', $parts);
    }

    private function isDate($value)
    {
        if (!$value) {
            return false;
        }

        try {
            new \DateTime($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function shouldConvertDateField($fieldType, $propertyType, $value)
    {
        if (!in_array($fieldType, ['date', 'datetime'], true) && !in_array($propertyType, ['date', 'datetime'], true)) {
            return false;
        }

        return $this->isDate($value);
    }

    private function shouldKeepFieldValue($value)
    {
        if (is_array($value)) {
            return !empty($value);
        }

        return !($value === null || $value === '');
    }

    private function parseHubspotFieldMeta($fieldToken, $api, &$customFieldMap)
    {
        $fieldName = $fieldToken;
        $fieldType = null;
        $propertyType = null;

        if (strpos($fieldToken, '*_ff_*') === false) {
            return [$fieldName, $fieldType, $propertyType];
        }

        $fieldParts = Str::separateString($fieldToken, '*_ff_*');
        if (!$fieldParts) {
            return [$fieldName, $fieldType, $propertyType];
        }

        $fieldName = ArrayHelper::get($fieldParts, 0, $fieldToken);
        $fieldType = ArrayHelper::get($fieldParts, 1);
        $propertyType = ArrayHelper::get($fieldParts, 2);

        if (count($fieldParts) === 2) {
            $legacyType = $fieldType;
            $fieldType = null;
            $propertyType = null;

            if (in_array($legacyType, ['checkbox', 'text', 'textarea', 'select', 'radio', 'booleancheckbox', 'date', 'file'], true)) {
                $fieldType = $legacyType;
            }

            if (in_array($legacyType, ['date', 'datetime', 'string', 'enumeration', 'number', 'bool', 'json'], true)) {
                $propertyType = $legacyType;
            }

            if (!$fieldType && $legacyType === 'enumeration') {
                if ($customFieldMap === null) {
                    $customFieldMap = [];
                    foreach ($api->getCustomFields() as $customField) {
                        $name = ArrayHelper::get($customField, 'name');
                        if ($name) {
                            $customFieldMap[$name] = $customField;
                        }
                    }
                }

                $customField = ArrayHelper::get($customFieldMap, $fieldName, []);
                $fieldType = ArrayHelper::get($customField, 'fieldType', $fieldType);
                $propertyType = ArrayHelper::get($customField, 'type', $propertyType);
            }
        }

        return [$fieldName, $fieldType, $propertyType];
    }

    /*
     * Notification Handler
     */

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        if (!is_email($feedData['email'])) {
            $feedData['email'] = ArrayHelper::get($formData, $feedData['email']);
        }
        if (!is_email($feedData['email'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('Hubspot API call has been skipped because no valid email available', 'fluentformpro'));
        }

        $mainFields = ArrayHelper::only($feedData, [
            'email',
            'firstname',
            'lastname',
            'website',
            'company',
            'phone',
            'address',
            'city',
            'state',
            'zip'
        ]);

        $fields = array_filter(
            array_merge($mainFields, ArrayHelper::get($feedData, 'fields', [])),
            [$this, 'shouldKeepFieldValue']
        );

        $api = $this->getRemoteClient();
        $customFieldMap = null;

        if(!empty($feedData['other_fields_mapping'])) {
            foreach ($feedData['other_fields_mapping'] as $field) {
                if ($this->shouldKeepFieldValue(ArrayHelper::get($field, 'item_value'))) {
                    $fieldValue = $field['item_value'];
                    list($fieldName, $fieldType, $propertyType) = $this->parseHubspotFieldMeta(
                        $field['label'],
                        $api,
                        $customFieldMap
                    );
                    if ($fieldType) {
                        if ($fieldType == 'checkbox') {
                            if (strpos($fieldValue, ',') !== false) {
                                $separateValues = Str::separateString($fieldValue, ',');
                                $fieldValue = implode(';', $separateValues);
                            }
                        }
                    }
                    $fields[$fieldName] = $fieldValue;
                    $dateField = $this->shouldConvertDateField($fieldType, $propertyType, $fieldValue);
                    if ($dateField) {
                        $fields[$fieldName] = strtotime($fieldValue)*1000;
                    }
                }
            }
        }
    
        $fields = apply_filters_deprecated(
            'fluentform_hubspot_field_data',
            [
                $fields,
                $feed,
                $entry,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/hubspot_field_data',
            'Use fluentform/hubspot_field_data instead of fluentform_hubspot_field_data'
        );

        $fields = apply_filters('fluentform/hubspot_field_data', $fields, $feed, $entry, $form);
    
        $fields = apply_filters_deprecated(
            'fluentform_integration_data_' . $this->integrationKey,
            [
                $fields,
                $feed,
                $entry
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/integration_data_' . $this->integrationKey,
            'Use fluentform/integration_data_' . $this->integrationKey . ' instead of fluentform_integration_data_' . $this->integrationKey
        );

        $fields = apply_filters('fluentform/integration_data_' . $this->integrationKey, $fields, $feed, $entry);

        // Now let's prepare the data and push to hubspot
        $updateContact =  ArrayHelper::get ($feedData,'contact_update');
        $response = $api->subscribe($feedData['list_id'], $fields , $updateContact);

        if (is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
        } else {
            do_action('fluentform/integration_action_result', $feed, 'success', __('Hubspot feed has been successfully initialed and pushed data', 'fluentformpro'));
        }
    }

}
