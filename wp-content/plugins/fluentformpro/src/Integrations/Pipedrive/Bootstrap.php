<?php
namespace FluentFormPro\Integrations\Pipedrive;

defined('ABSPATH') or die;

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Pipedrive',
            'pipedrive',
            '_fluentform_pipedrive_settings',
            'pipedrive_feed',
            26
        );

        $this->logo = fluentFormMix('img/integrations/pipedrive.png');

        $this->description = 'By connecting Pipedrive with Fluent Forms, you can connect and organize leads and more.';

        $this->registerAdminHooks();

//        add_filter('fluentform/notifying_async_pipedrive', '__return_false');
        add_filter(
            'fluentform/get_integration_values_pipedrive',
            [$this, 'resolveIntegrationSettings'],
            100,
            3
        );
        add_filter('fluentform/save_integration_value_' . $this->integrationKey, [$this, 'validate'], 10, 3);
    }


    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('Pipedrive API Settings', 'fluentformpro'),
            'menu_description'   => __('Pipedrive is a deal-driven customer relationship management CRM solution that also works as an account-management tool with the ability to assist with marketing and the entire sales process. If you don\'t have an Pipedrive account, you can <a href="https://www.pipedrive.com/en/register"  target="_blank">sign up for one here.</a>', 'fluentformpro'),
            'valid_message'      => __('Your Pipedrive API token is valid', 'fluentformpro'),
            'invalid_message'    => __('Your Pipedrive API token is invalid', 'fluentformpro'),
            'save_button_text'   => __('Verify Pipedrive API Token', 'fluentformpro'),
            'config_instruction' => $this->getConfigInstructions(),
            'fields'             => [
                'apiToken' => [
                    'type'        => 'text',
                    'placeholder' => __('Enter Your API token', 'fluentformpro'),
                    'label_tips'  => __("Enter your Pipedrive API token, if you do not have follow-up upper instructions.", 'fluentformpro'),
                    'label'       => __('Pipedrive API Token', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'      => true,
            'discard_settings'   => [
                'section_description' => __('Your Pipedrive API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Pipedrive', 'fluentformpro'),
                'data'                => [
                    'apiToken' => ''
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
            'apiToken' => '',
            'status'      => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        $name = $this->app->request->get('serviceName', '');
        $serviceId = $this->app->request->get('serviceId', '');

        return [
            'name' => $name,
            'list_id' => $serviceId,
            'other_fields' => [
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
            'enabled' => true
        ];
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-pipedrive-settings'),
            'configure_message'     => __('Pipedrive is not configured yet! Please configure your Pipedrive api token first', 'fluentformpro'),
            'configure_button_text' => __('Set Pipedrive API Token', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getSettingsFields($settings, $formId)
    {
        $fieldSettings = [
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
                    'label' => __('Services', 'fluentformpro'),
                    'placeholder' => __('Choose Service', 'fluentformpro'),
                    'required' => true,
                    'component' => 'refresh',
                    'options' => $this->getServices()
                ],
            ],
            'button_require_list' => false,
            'integration_title' => $this->title
        ];

        $serviceId = $this->app->request->get(
            'serviceId',
            ArrayHelper::get($settings, 'list_id')
        );

        if ($serviceId) {
            $fields = $this->getFields($serviceId);
            $fields = array_merge($fieldSettings['fields'], $fields);
            $fieldSettings['fields'] = $fields;
        }

        $fieldSettings['fields'] = array_merge($fieldSettings['fields'], [
            [
                'require_list' => false,
                'key' => 'conditionals',
                'label' => __('Conditional Logics', 'fluentformpro'),
                'tips' => __('Allow this integration conditionally based on your submission values', 'fluentformpro'),
                'component' => 'conditional_block'
            ],
            [
                'require_list' => false,
                'key' => 'enabled',
                'label' => __('Status', 'fluentformpro'),
                'component' => 'checkbox-single',
                'checkbox_label' => __('Enable This feed', 'fluentformpro')
            ]
        ]);

        return $fieldSettings;
    }

    public function saveGlobalSettings($settings){
        if (!$settings['apiToken']) {
            $integrationSettings = [
                'apiToken' => '',
                'status'      => false
            ];
            // Update the  details with access token
            update_option($this->optionKey, $integrationSettings, 'no');
            wp_send_json_error([
                'message'      => __('API token in required.', 'fluentformpro'),
                'status'       => false,
                'require_load' => true
            ], 404);
        }

        try {
            $settings['status'] = false;
            update_option($this->optionKey, $settings, 'no');

            $api = $this->getApi($settings['apiToken']);
            $auth = $api->auth_test();

            if ($auth['success']) {
                $settings['status'] = true;
                update_option($this->optionKey, $settings, 'no');
                wp_send_json_success([
                    'status'  => true,
                    'message' => __('Your settings has been updated!', 'fluentformpro')
                ], 200);
            }
            throw new \Exception(__('Invalid Api Token','fluentformpro'), 401);
        } catch (\Exception $e) {
            wp_send_json_error([
                'status'  => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        }
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }
    public function resolveIntegrationSettings($settings, $feed, $formId)
    {
        $serviceName = $this->app->request->get('serviceName', '');
        $serviceId = $this->app->request->get('serviceId', '');

        if ($serviceName) {
            $settings['name'] = $serviceName;
        }

        if ($serviceId) {
            $settings['list_id'] = $serviceId;
        }

        return $settings;
    }

    protected function getServices() {
        return [
            'persons'       => 'Person',
            'leads'         => 'Leads',
            'organizations' => 'Organization',
            'deals'         => 'Deal',
            'activities'    => 'Activity',
        ];
    }

    public function getFields($serviceId) {
        if ($serviceId === 'leads') {
            $fieldId = $this->makeFieldEndpoint('deals');
        } else {
            $fieldId = $this->makeFieldEndpoint($serviceId);
        }
        $api = $this->getApi();
        $response = $api->getFields($fieldId);

        // if got error or integration status is false exit with false
        if (is_wp_error($response) || !$response['success']) {
            return false;
        }

        $fields = array();
        if ($response['data']) {
            $others_fields = array();
            foreach ($response['data'] as $field) {
                // if field is not valid skip this field
                if (!$this->isFieldValid($field, $serviceId)) {
                    continue;
                }


                if ($this->filterField($field) && !ArrayHelper::get($field, 'edit_flag'))  {

                    if (in_array($field['key'], ['name', 'status'])) {
                        $field['key'] = 'field_' . $field['key'];
                    }

                    $data = array(
                        'key' => $field['key'],
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'placeholder' => __($field['name'], 'fluentformpro'),
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'label' => __($field['name'], 'fluentformpro'),
                        'data_type' => $field['field_type'],
                        'required' => false,
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'tips' => __('Enter ' . $field['name'] . ' value or choose form input provided by shortcode.', 'fluentformpro'),
                        'component' => 'value_text'
                    );

                    if ($this->isRequiredField($serviceId, $field)) {
                        $data['required'] = true;
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        $data['tips'] = __($field['name'] . ' is a required field. Enter value or choose form input provided by shortcode.', 'fluentformpro');
                    }

                    if ($field['key'] === 'value') {
                        $data['tips'] = __('Amount value. Currency is pipedrive default currency.', 'fluentformpro');
                    }

                    if ($this->isSelectField($field)) {
                        $data['component'] = 'select';
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        $data['tips'] = __("Choose " . $field['name'] . " type in select list.", 'fluentformpro');
                        $data_options = array();
                        if ($field['field_type'] === 'user') {
                            $users = $api->getUsers();
                            if (is_wp_error($users) || !$users['success']) {
                                continue;
                            }
                            if ($users['data']) {
                                $data_options = $this->formatArray($users['data']);
                            }
                        } elseif (in_array($field['field_type'], ['org', 'people'])) {
                            $people_options = $orgs_options = [];
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            $data['tips'] .= __("<br> If list empty first create " . $field['name'] ." in you pipedrive dashboard.", 'fluentformpro');
                            $orgs = $api->getOrganizations();
                            if (is_wp_error($orgs) || !$orgs['success']) {
                                continue;
                            }

                            if ($orgs['data']) {
                                $orgs_options = $this->formatArray($orgs['data']);
                            }

                            $people = $api->getPerson();
                            if (is_wp_error($people) || !$people['success']) {
                                continue;
                            }

                            if ($people['data']) {
                                $people_options = $this->formatArray($people['data']);
                            }
                            if ($field['field_type'] === 'people') {
                                if ($orgs_options && empty($people_options)) {
                                    $data['required'] =  false;
                                }
                                $data_options = $people_options;
                            }

                            if ($field['field_type'] === 'org') {
                                if ($people_options && empty($orgs_options)) {
                                    $data['required'] =  false;
                                }
                                if ($people_options && !empty($orgs_options)) {
                                    $data['required'] =  false;
                                }
                                $data_options = $orgs_options;
                            }

                        }
                        else {
                            $data_options = $this->formatArray($field['options'], 'options');
                        }

                        $data['options'] = $data_options;
                    }
                    if ($field['field_type'] == 'text') {
                        $data['component'] = 'value_textarea';
                    }

                    if ($serviceId === 'leads' && $field['key'] === 'value') {
                        $data['required'] = false;
                        $fields[] = $data;
                        $fields = $this->getLeadExtraFields($fields);
                    } elseif ($serviceId === 'activities' && $field['key'] === 'type') {
                        $fields[] = $data;
                        $fields[] = [
                            'key' => 'due_date',
                            'placeholder' => __('Enter Lead Title or choose shortcode', 'fluentformpro'),
                            'label' => __('Activity Due Date', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('The due date of the activity. In ISO 8601 format: YYYY-MM-DD.', 'fluentformpro'),
                            'component' => 'datetime'
                        ];
                    }
                    else {
                        $fields[] = $data;
                    }
                } else {
                    if ($this->isOthersfield($field)) {
                        $others_fields[$field['key']] = $field['name'];
                    }
                }

            }
            if (!empty($others_fields)) {
                $fields[] = [
                    'key' => 'other_fields',
                    'require_list' => false,
                    'required' => false,
                    'label' => __('Other Fields', 'fluentformpro'),
                    'tips' => __('Select which Fluent Forms fields pair with their respective pipedrive modules fields. <br /> Field value must be string type.', 'fluentformpro'),
                    'component' => 'dropdown_many_fields',
                    'options' => $others_fields
                ];
            }
        }

        if ($serviceId === 'persons') {
            // Email and Phone are stock Pipedrive fields with edit_flag=false,
            // so they never surface through the API-driven loop above. Expose
            // them here so the form can actually map them.
            $fields[] = [
                'key'         => 'email',
                'placeholder' => __('Email', 'fluentformpro'),
                'label'       => __('Email', 'fluentformpro'),
                'required'    => false,
                'tips'        => __('Choose the form Email field. Required if Marketing Status is set to anything other than "No consent".', 'fluentformpro'),
                'component'   => 'value_text',
            ];
            $fields[] = [
                'key'         => 'phone',
                'placeholder' => __('Phone', 'fluentformpro'),
                'label'       => __('Phone', 'fluentformpro'),
                'required'    => false,
                'tips'        => __('Choose the form Phone field.', 'fluentformpro'),
                'component'   => 'value_text',
            ];
            $fields[] = [
                'key'         => 'marketing_status',
                'placeholder' => __('Marketing Status', 'fluentformpro'),
                'label'       => __('Marketing Status', 'fluentformpro'),
                'required'    => false,
                'tips'        => __('Used for Pipedrive campaigns. If not set, Pipedrive defaults this to "No consent".', 'fluentformpro'),
                'component'   => 'select',
                'options'     => [
                    'no_consent'   => __('No consent', 'fluentformpro'),
                    'unsubscribed' => __('Unsubscribed', 'fluentformpro'),
                    'subscribed'   => __('Subscribed', 'fluentformpro'),
                    'archived'     => __('Archived', 'fluentformpro'),
                ]
            ];
        }

        return $fields;

    }

    protected function getLeadExtraFields($fields) {
        $api = $this->getApi();
        $currencies = [];


        $response = $api->getCurrencies();
        if (!is_wp_error($response) && $response['success'] && $response['data']) {
            $currencies = $this->formatArray($response['data'], 'currencies');
        }

        $fields[] = [
            'key' => 'currency',
            'placeholder' => __('Currency', 'fluentformpro'),
            'label' => __('Currency Code', 'fluentformpro'),
            'required' => false,
            'tips' => __('Choose witch country currency amount value is.', 'fluentformpro'),
            'component' => 'select',
            'options' => $currencies
        ];
        $fields[] = [
            'key' => 'expected_close_date',
            'placeholder' => __('Enter Lead Title or choose shortcode', 'fluentformpro'),
            'label' => __('Expected Close Date', 'fluentformpro'),
            'required' => false,
            'tips' => __('The date of when the deal which will be created from the lead is expected to be closed. In ISO 8601 format: YYYY-MM-DD.', 'fluentformpro'),
            'component' => 'datetime'
        ];
        return $fields;
    }

    protected function coerceForService(array $postData, $serviceId)
    {
        $intFields = [];
        $stringFields = [];
        $boolFields = [];
        $arrayOf = null;
        $disallowed = [];

        switch ($serviceId) {
            case 'leads':
                $intFields    = ['owner_id', 'person_id', 'organization_id', 'channel'];
                $stringFields = ['title', 'channel_id', 'visible_to', 'origin_id', 'lead_id'];
                $boolFields   = ['was_seen'];
                $arrayOf      = ['label_ids' => 'string'];
                $disallowed   = ['is_archived'];
                break;
            case 'deals':
                $intFields    = ['user_id', 'person_id', 'org_id', 'stage_id', 'pipeline_id', 'visible_to', 'channel'];
                $stringFields = ['title', 'currency', 'status', 'lost_reason', 'channel_id', 'expected_close_date'];
                $boolFields   = ['is_archived', 'is_deleted'];
                $arrayOf      = ['label_ids' => 'int'];
                break;
            case 'persons':
            case 'organizations':
                $intFields    = ['owner_id', 'org_id', 'visible_to'];
                $stringFields = ['name', 'marketing_status'];
                $arrayOf      = ['label_ids' => 'int'];
                break;
            case 'activities':
                $intFields    = ['user_id', 'deal_id', 'person_id', 'org_id', 'project_id', 'priority'];
                $stringFields = ['subject', 'type', 'note', 'public_description', 'lead_id', 'due_date', 'due_time', 'duration'];
                $boolFields   = ['done', 'busy_flag'];
                break;
        }

        foreach ($intFields as $key) {
            if (!array_key_exists($key, $postData)) continue;
            $val = $postData[$key];
            if ($val === '' || $val === null) {
                unset($postData[$key]);
                continue;
            }
            if (is_numeric($val)) {
                $int = (int) $val;
                if ($int > 0) {
                    $postData[$key] = $int;
                } else {
                    unset($postData[$key]);
                }
            } else {
                unset($postData[$key]);
            }
        }

        foreach ($stringFields as $key) {
            if (!array_key_exists($key, $postData)) continue;
            $val = $postData[$key];
            if ($val === '' || $val === null) {
                unset($postData[$key]);
                continue;
            }
            $postData[$key] = (string) $val;
        }

        foreach ($boolFields as $key) {
            if (!array_key_exists($key, $postData)) continue;
            $val = $postData[$key];
            if ($val === '' || $val === null) {
                unset($postData[$key]);
                continue;
            }
            $postData[$key] = (bool) filter_var($val, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_array($arrayOf)) {
            foreach ($arrayOf as $key => $itemType) {
                if (!array_key_exists($key, $postData)) continue;
                $val = $postData[$key];
                if ($val === '' || $val === null || $val === []) {
                    unset($postData[$key]);
                    continue;
                }
                if (!is_array($val)) {
                    $val = is_string($val) ? array_map('trim', explode(',', $val)) : [$val];
                }
                $coerced = [];
                foreach ($val as $item) {
                    if ($item === '' || $item === null) continue;
                    if ($itemType === 'int') {
                        $i = (int) $item;
                        if ($i > 0) $coerced[] = $i;
                    } else {
                        $coerced[] = (string) $item;
                    }
                }
                if ($coerced) {
                    $postData[$key] = array_values($coerced);
                } else {
                    unset($postData[$key]);
                }
            }
        }

        foreach ($disallowed as $key) {
            unset($postData[$key]);
        }

        return $postData;
    }

    protected function autoCreatePersonForLead($formData, $form, $api)
    {
        $person = $this->buildPersonPayloadFromFormData($formData, $form);
        if (empty($person['name'])) {
            return null;
        }

        $email = ArrayHelper::get($person, 'email.0.value');
        $cacheKey = $email ? 'ff_pd_person_' . $api->getApiTokenHash() . '_' . md5(strtolower($email)) : null;

        if ($cacheKey) {
            $cachedId = get_transient($cacheKey);
            if ($cachedId) {
                return (int) $cachedId;
            }
        }

        if ($email) {
            $existingId = $api->findPersonByEmail($email);
            if ($existingId) {
                set_transient($cacheKey, $existingId, DAY_IN_SECONDS);
                return $existingId;
            }
        }

        $response = $api->insertServiceData('persons', $person);
        if (is_wp_error($response)) {
            return $response;
        }
        $newId = (int) ArrayHelper::get($response, 'data.id');
        if ($newId && $cacheKey) {
            set_transient($cacheKey, $newId, DAY_IN_SECONDS);
        }
        return $newId ?: null;
    }

    protected function buildPersonPayloadFromFormData($formData, $form)
    {
        $person = [];

        foreach ((array) $formData as $val) {
            if (is_array($val) && (isset($val['first_name']) || isset($val['last_name']))) {
                $name = trim(ArrayHelper::get($val, 'first_name', '') . ' ' . ArrayHelper::get($val, 'last_name', ''));
                if ($name !== '') {
                    $person['name'] = $name;
                    break;
                }
            }
        }
        if (empty($person['name'])) {
            foreach (['name', 'full_name', 'fullname', 'first_name'] as $key) {
                if (!empty($formData[$key]) && is_string($formData[$key])) {
                    $person['name'] = $formData[$key];
                    break;
                }
            }
        }

        $emailFieldKey = ($form && isset($form->id))
            ? \FluentForm\App\Models\FormMeta::retrieve('_primary_email_field', $form->id)
            : null;
        $email = null;
        if ($emailFieldKey && !empty($formData[$emailFieldKey]) && is_string($formData[$emailFieldKey])) {
            $email = $formData[$emailFieldKey];
        }
        if (!$email) {
            foreach ((array) $formData as $val) {
                if (is_string($val) && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $email = $val;
                    break;
                }
            }
        }
        if ($email) {
            $person['email'] = [['value' => $email, 'primary' => true]];
        }

        foreach (['phone', 'phone_number', 'mobile', 'mobile_number'] as $key) {
            if (!empty($formData[$key]) && is_string($formData[$key])) {
                $person['phone'] = [['value' => $formData[$key], 'primary' => true]];
                break;
            }
        }

        if (empty($person['name']) && $email) {
            $person['name'] = $email;
        }

        return $person;
    }

    protected function coerceChannelValue($value, $api = null)
    {
        static $labelMaps = [];

        if ($value === '' || $value === null) {
            return null;
        }
        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }

        if ($api === null) {
            $api = $this->getApi();
        }
        $tokenHash = $api->getApiTokenHash();

        if (!isset($labelMaps[$tokenHash])) {
            $channels = $api->getChannels();
            if (is_wp_error($channels) || empty($channels)) {
                $labelMaps[$tokenHash] = [];
                return null;
            }
            $labelMaps[$tokenHash] = [];
            foreach ($channels as $channel) {
                $label = strtolower((string) ArrayHelper::get($channel, 'label', ''));
                if ($label !== '') {
                    $labelMaps[$tokenHash][$label] = (int) ArrayHelper::get($channel, 'id');
                }
            }
        }

        $key = strtolower((string) $value);
        return (isset($labelMaps[$tokenHash][$key]) && $labelMaps[$tokenHash][$key] > 0)
            ? $labelMaps[$tokenHash][$key]
            : null;
    }

    protected function formatArray($items = [], $from = '') {
        $newArray = [];
        foreach ($items as $item) {
            if ($from === 'currencies') {
                // make country currencies options for dropdown
                $newArray[$item['code']] = $item['name'];
            } elseif ($from === 'lead_keys') {
                // make leads service keys for validation and get data from feed settings
                $data = [
                    'key' => $item['key'],
                    'feed_key' => $item['key'],
                    'label' => $item['label'],
                    'data_type' => '',
                    'required' => $item['required']
                ];
                if (isset($item['data_type'])) {
                    $data['data_type'] = $item['data_type'];
                }
                array_push($newArray, $data);
            }  elseif ($from === 'options') {
                // format other field options
                $newArray[$item['id']] = $item['label'];
            }
            else {
                // make other select field options
                $newArray[$item['id']] = $item['name'];
            }
        }
        return $newArray;
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $list_id = $feedData['list_id'];
        if (!$list_id) {
            return false;
        }
        $keys = $this->getAllKeys($list_id);
        $postData = array();


        foreach ($keys as $key){
            if (ArrayHelper::get($key, 'other_fields') && !empty($feedData['other_fields'])) {
                foreach ($feedData['other_fields'] as $other_field) {
                    if (
                            !empty($other_field['item_value']) &&
                            !empty($other_field['label']) &&
                            $other_field['label'] === $key['feed_key']
                    ) {
                        $postData[$other_field['label']] = $other_field['item_value'];
                    }
                }
                continue;
            }

            if ($key['required'] && empty($feedData[$key['feed_key']])) {
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                do_action('fluentform/integration_action_result', $feed, 'failed',  __('Failed to insert Pipedrive feed. Details : ' . $key['label'] . ' empty', 'fluentformpro'));
                return false;
            }

            if (!empty($feedData[$key['feed_key']])) {
                $postData[$key['key']] = $feedData[$key['feed_key']];
            }
        }

        if ($list_id === 'leads') {
            $rawAmount = ArrayHelper::get($postData, 'value');
            $rawCurrency = ArrayHelper::get($postData, 'currency');
            $amount = is_numeric($rawAmount) ? (int) $rawAmount : 0;
            $currency = ($rawCurrency !== null && $rawCurrency !== '') ? (string) $rawCurrency : '';

            if ($amount > 0 && $currency !== '') {
                $postData['value'] = ['amount' => $amount, 'currency' => $currency];
            } else {
                ArrayHelper::forget($postData, ['value']);
            }
            ArrayHelper::forget($postData, ['currency']);

            if ($orgId = ArrayHelper::get($postData, 'org_id')) {
                $postData['organization_id'] = intval($orgId);
                ArrayHelper::forget($postData, ['org_id']);
            }
            if ($person = ArrayHelper::get($postData, 'person_id')) {
                $postData['person_id'] = intval($person);
            }
            if ($owner = ArrayHelper::get($postData, 'user_id')) {
                $postData['owner_id'] = intval($owner);
                ArrayHelper::forget($postData, ['user_id']);
            }

            if ($date = ArrayHelper::get($postData, 'expected_close_date')) {
                // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
                $postData['expected_close_date'] = date('Y-m-d', strtotime($date));
            }

            if (isset($postData['channel'])) {
                $coerced = $this->coerceChannelValue($postData['channel']);
                if ($coerced !== null) {
                    $postData['channel'] = $coerced;
                } else {
                    unset($postData['channel']);
                }
            }

            if (isset($postData['channel_id']) && $postData['channel_id'] !== '') {
                $postData['channel_id'] = (string) $postData['channel_id'];
            } else {
                unset($postData['channel_id']);
            }
        }

        $postData = $this->coerceForService($postData, $list_id);

        if ($list_id === 'persons') {
            $marketingStatus = ArrayHelper::get($postData, 'marketing_status');
            $emailMapped = !empty($postData['email']);
            if ($marketingStatus && $marketingStatus !== 'no_consent' && !$emailMapped) {
                unset($postData['marketing_status']);
                do_action(
                    'fluentform/integration_action_result',
                    $feed,
                    'success',
                    __('Pipedrive marketing_status was dropped because no Email value was sent in this submission. Map the Email field on this feed (or fill the form Email) to send marketing status.', 'fluentformpro')
                );
            }
        }

        $postData = apply_filters('fluentform/integration_data_' . $this->integrationKey, $postData, $feed, $entry);

        if ($list_id === 'activities') {
            if ($date = ArrayHelper::get($postData,'due_date')) {
                // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
                $postData['due_date'] = date('Y-m-d', strtotime($date));
            }
        }

        $api = $this->getApi();

        if ($list_id === 'leads' && empty($postData['person_id']) && empty($postData['organization_id'])) {
            $autoId = $this->autoCreatePersonForLead($formData, $form, $api);
            if (is_wp_error($autoId)) {
                do_action('fluentform/integration_action_result', $feed, 'failed', __('Failed to auto-create Pipedrive person. Details : ', 'fluentformpro') . $autoId->get_error_message());
                return false;
            }
            if ($autoId) {
                $postData['person_id'] = (int) $autoId;
            }
        }

        $response = $api->insertServiceData($feedData['list_id'], $postData);

        if (is_wp_error($response)) {
            // it's failed
            do_action('fluentform/integration_action_result', $feed, 'failed',  __('Failed to insert Pipedrive feed. Details : ', 'fluentformpro') . $response->get_error_message());
        } else {
            // It's success
            do_action('fluentform/integration_action_result', $feed, 'success', __('Pipedrive feed has been successfully inserted ', 'fluentformpro') . $list_id . __(' data.', 'fluentformpro'));
        }
    }

    public function validate($settings, $integrationId, $formId)
    {
        $error = false;
        $errors = array();
        $fields = $this->getAllKeys($settings['list_id']);
        if ($fields) {
            foreach ($fields as $field){
                if ($field['required'] && empty($settings[$field['feed_key']])) {
                    $error = true;
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    $msg = __($field['label'].' is required.', 'fluentformpro');
                    if ($field['data_type'] === 'org') {
                        $msg .= __(' First Create Organization In your Integration.', 'fluentformpro');
                    }
                    $errors[$field['feed_key']] = [$msg];
                }
            }
        }

        if ($error){
            wp_send_json_error([
                'message' => __('Validation Failed', 'fluentformpro'),
                'errors'  => $errors
            ], 423);
        }

        return $settings;
    }

    protected function getAllKeys($serviceId)
    {
        $fields = $this->getFields($serviceId);
        $keys = [];
        foreach ($fields as $field) {

            $data = [
                'key' => $field['key'],
                'feed_key' => $field['key'],
                'label' => $field['label'],
                'data_type' => ArrayHelper::get($field, 'data_type'),
                'required' => $field['required']
            ];
            if (in_array($field['key'], ['field_name', 'field_status'])) {
                $data['key'] = (explode("_",$field['key']))[1];
            }
            if ($field['key'] == 'other_fields' && $options = ArrayHelper::get($field, 'options')) {
                $data['other_fields'] = true;
                foreach ($options as $key => $value) {
                    $data = [
                        'key' => $key,
                        'feed_key' => $key,
                        'label' => $value,
                        'other_fields' => true,
                        'data_type' => '',
                        'required' => false
                    ];
                    $keys[] = $data;
                }
            } else {
                $keys[] = $data;
            }
        }
        return $keys;
    }


    protected function isFieldValid($field, $serviceId)
    {
        // if bulk edit not set skip this field
        if (!isset($field['bulk_edit_allowed']) || !ArrayHelper::get($field, 'bulk_edit_allowed')) {
            return false;
        }

        //skip leads status field
        if ($serviceId === 'leads' && $field['key'] === 'status') {
            return false;
        }

        // Persons marketing_status is appended manually below with the canonical
        // 4-value enum; skip whatever Pipedrive returns for it so the field
        // does not appear twice on accounts where the API exposes it.
        if ($serviceId === 'persons' && $field['key'] === 'marketing_status') {
            return false;
        }

        // if field data type is data/stage/varchar_options/lead/deal skip this field
        if (
            in_array($field['field_type'], ['date', 'stage', 'varchar_options', 'lead', 'deal', 'set']) ||
            $field['key'] === 'done'
        ) {
            return  false;
        }

        // if field type is select but not property options skit this field
        if (in_array($field['field_type'], ['enum', 'visible_to']) && !isset($field['options'])) {
            return false;
        }

        return true;
    }
    protected function filterField($field)
    {
        if (
            ($field['mandatory_flag'] && $field['bulk_edit_allowed']) ||
            ($field['bulk_edit_allowed'] &&
                (
                    in_array($field['field_type'], ['enum', 'visible_to', 'text', 'org']) ||
                    $field['key'] === 'value'
                )
            ) ||
            ArrayHelper::get($field, 'important_flag')
        ) {
            return true;
        }
        return false;
    }
    protected function isSelectField($field)
    {
        if (
            (
                in_array($field['field_type'], ['enum', 'visible_to', 'status'])
                && ArrayHelper::get($field, 'options')
            ) ||
            in_array($field['field_type'], ['user', 'people', 'org'])
        ) {
            return true;
        }
        return false;
    }
    protected function isRequiredField($serviceId, $field) {
        switch ($serviceId) {
            case 'organizations':
            case 'persons':
                return ArrayHelper::get($field, 'key') == 'field_name';
            case 'deals':
            case 'leads':
                return ArrayHelper::get($field, 'key') == 'title';
            case 'activities':
                return ArrayHelper::get($field, 'key') == 'subject';
            default:
                return false;
        }
    }
    protected function isOthersfield($field) {
        if (
                ArrayHelper::get($field, 'edit_flag') &&
                in_array($field['field_type'],['varchar', 'phone', 'text'])
            ) {
            return true;
        }
        return false;
    }
    protected function makeFieldEndpoint($serviceId)
    {
        if ($serviceId === 'activities') {
            $fieldId = 'activityFields';
        } else {
            $fieldId = substr($serviceId,0,strlen($serviceId) -1) . 'Fields';
        }
        return $fieldId;
    }



    protected function getApi($apiToken = null)
    {
        if (!$apiToken) {
            $apiToken = $this->getGlobalSettings([])['apiToken'];
        }
        return new PipedriveApi($apiToken);
    }

    protected function getConfigInstructions()
    {
        ob_start();
        ?>
        <div>
            <h4>You can get the API token manually from the Pipedrive web app by going to account name (on the top right) > Company settings > Personal preferences > API or by clicking <a href="https://app.pipedrive.com/settings/api"  target="_blank">here</a>.</h4>
        </div>
        <?php
        return ob_get_clean();
    }
}