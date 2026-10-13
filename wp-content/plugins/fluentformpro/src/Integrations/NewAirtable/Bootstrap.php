<?php

namespace FluentFormPro\Integrations\NewAirtable;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Airtable',
            'airtable_v2',
            '_fluentform_newairtable_settings',
            'airtable_v2_feed',
            36
        );

//        add_filter('fluentform/notifying_async_airtable_v2', '__return_false');

        $this->logo = fluentformmix('img/integrations/airtable.png');

        $this->description = "Airtable is a low-code platform for building collaborative apps. Customize your workflow, collaborate, and achieve ambitious outcomes.";

        $this->registerAdminHooks();

        add_filter(
            'fluentform/get_integration_values_' . $this->integrationKey,
            [$this, 'resolveIntegrationSettings'],
            10,
            3
        );

        add_filter(
            'fluentform/save_integration_value_' . $this->integrationKey,
            [$this, 'validate'],
            10,
            3
        );
    }

    public function resolveIntegrationSettings($settings, $feed, $formId)
    {
        $configs = $this->app->request->get('configs', '');
        $baseId = ArrayHelper::get($configs, 'base_id');
        $tableId = ArrayHelper::get($configs, 'table_id');

        if ($baseId) {
            $settings['chained_config']['base_id'] = $configs['base_id'];
        }

        if ($tableId) {
            $settings['chained_config']['table_id'] = $configs['table_id'];
        }

        return $settings;
    }

    public function validate($settings, $integrationId, $formId)
    {
        $error = false;
        $errors = [];
        $baseId = ArrayHelper::get($settings, 'chained_config' . '.' . 'base_id');
        $tableId = ArrayHelper::get($settings, 'chained_config' . '.' . 'table_id');
        $fields = $this->getFields($baseId, $tableId);

        if (isset($fields['status']) && $fields['status'] === false) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($fields['message'], 'fluentformpro'),
                'errors'  => []
            ], 423);
        }

        foreach ($fields as $field) {
            if ($field['required'] && empty($settings[$field['key']])) {
                $error = true;
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                $errors[$field['key']] = [__($field['label'] . ' is required', 'fluentformpro')];
            }
        }

        if ($error) {
            wp_send_json_error([
                'message' => __('Validation Failed', 'fluentformpro'),
                'errors'  => $errors
            ], 423);
        }

        return $settings;
    }

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new API(
            $settings
        );
    }

    public function getGlobalSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);

        if (!$globalSettings) {
            $globalSettings = [];
        }

        $defaults = [
            'status'       => false,
            'access_token' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('Airtable Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_description'   => __($this->description, 'fluentformpro'),
            'valid_message'      => __('Your Airtable API Key is valid', 'fluentformpro'),
            'invalid_message'    => __('Your Airtable API Key is not valid', 'fluentformpro'),
            'save_button_text'   => __('Save Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'config_instruction' => __($this->getConfigInstructions(), 'fluentformpro'),
            'fields'             => [
                'access_token' => [
                    'type'        => 'password',
                    'placeholder' => __('Airtable Access Token', 'fluentformpro'),
                    'label_tips'  => __('Enter your Airtable Access Token', 'fluentformpro'),
                    'label'       => __('Airtable Access Token', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'      => true,
            'discard_settings'   => [
                'section_description' => __('Your Airtable API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Airtable', 'fluentformpro'),
                'data'                => [
                    'access_token' => '',
                ],
                'show_verify'         => true
            ]
        ];
    }

    protected function getConfigInstructions()
    {
        ob_start(); ?>
        <div>
            <ol>
                <li>
                    Go <a href="https://airtable.com/create/tokens" target="_blank">Here</a> and create your Access Token by clicking Create new token button.
                </li>
                <li>
                    Give a name for your token then select these scope <b>data.records:read</b>,
                    <b>data.records:write</b> and <b>schema.bases:read</b> by clicking Add a scope.
                </li>
                <li>
                    Select your access level by clicking Add a base then hit the Create token button below.
                </li>
                <li>
                    After token creation, copy the token and paste it here.
                </li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['access_token'])) {
            $integrationSettings = [
                'access_token' => '',
                'status'       => false
            ];

            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_error([
                'message' => __('Please provide all fields to integrate', 'fluentformpro'),
                'status'  => false
            ], 423);
        }

        try {
            $client = new API($settings);
            $result = $client->checkAuth();

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message(), $result->get_error_code());
            }
        }
        catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage(),
                'status'  => false
            ], $exception->getCode());
        }

        $integrationSettings = [
            'access_token' => $settings['access_token'],
            'status'       => true
        ];

        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your Airtable Access Token has been verified and successfully set', 'fluentformpro'),
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-airtable_v2-settings'),
            'configure_message'     => __('Airtable is not configured yet! Please configure your Airtable api first',
                'fluentformpro'),
            'configure_button_text' => __('Set Airtable API', 'fluentformpro')
        ];

        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        $config = $this->app->request->get('configs');
        $baseId = ArrayHelper::get($config, 'base_id');
        $tableId = ArrayHelper::get($config, 'table_id');

        return [
            'name'           => '',
            'chained_config' => [
                'base_id'  => $baseId,
                'table_id' => $tableId,
            ],
            'typecast'       => false,
            'conditionals'   => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'        => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        $config = $this->app->request->get('configs', ArrayHelper::get($settings, 'chained_config'));
        $baseId = ArrayHelper::get($config, 'base_id');
        $tableId = ArrayHelper::get($config, 'table_id');

        $fieldSettings = [
            'fields'              => [
                [
                    'key'         => 'name',
                    'label'       => __('Feed Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'            => 'chained_config',
                    'label'          => __('Airtable Configuration', 'fluentformpro'),
                    'required'       => true,
                    'component'      => 'chained-ajax-fields',
                    'options_labels' => [
                        'base_id'  => [
                            'placeholder' => __('Select Base', 'fluentformpro'),
                            'options'     => $this->getBases()
                        ],
                        'table_id' => [
                            'placeholder' => __('Select Table', 'fluentformpro'),
                            'options'     => $this->getTables($baseId)
                        ],
                    ]
                ],
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];

        $error = false;
        $message = '';

        if ($baseId && $tableId) {
            $fields = $this->getFields($baseId, $tableId);

            if (isset($fields['status']) && $fields['status'] === false) {
                $error = true;
                $message = $fields['message'];
            }

            if (!$error) {
                $fields = array_merge($fieldSettings['fields'], $fields);
                $fieldSettings['fields'] = $fields;
            }
        }

        $fieldSettings['fields'] = array_merge($fieldSettings['fields'], [
            [
                'key'         => 'typecast',
                'label'       => __('Enable Typecast', 'fluentformpro'),
                'required'    => false,
                'tips'        => __('Whether auto typecasting is enabled or not.', 'fluentformpro'),
                'component'   => 'radio_choice',
                'options'     => [
                    true => 'Yes',
                    false => 'No'
                ]
            ],
            [
                'require_list' => false,
                'key'          => 'conditionals',
                'label'        => __('Conditional Logics', 'fluentformpro'),
                'tips'         => __('Allow this integration conditionally based on your submission values',
                    'fluentformpro'),
                'component'    => 'conditional_block'
            ],
            [
                'require_list'   => false,
                'key'            => 'enabled',
                'label'          => __('Status', 'fluentformpro'),
                'component'      => 'checkbox-single',
                'checkbox_label' => __('Enable this feed', 'fluentformpro')
            ]
        ]);

        if ($error) {
            wp_send_json_error([
                'settings_fields' => $fieldSettings,
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message'         => __($message, 'fluentformpro'),
                'status'          => false
            ], 423);
        }

        return $fieldSettings;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
    }

    protected function getBases()
    {
        $api = $this->getRemoteClient();

        if (!$api) {
            return [];
        }

        $bases = $api->getBases();

        if (is_wp_error($bases)) {
            wp_send_json_error([
                'message' => $bases->get_error_message()
            ], 423);
        }

        $formattedBases = [];
        foreach ($bases['bases'] as $base) {
            $formattedBases[$base['id']] = $base['name'];
        }

        return $formattedBases;
    }

    protected function getTables($baseId)
    {
        if (!$baseId) {
            return [];
        }

        $api = $this->getRemoteClient();

        if (!$api) {
            return [];
        }

        $tables = $api->getTables($baseId);

        if (is_wp_error($tables)) {
            wp_send_json_error([
                'message' => $tables->get_error_message()
            ], 423);
        }

        $formattedTables = [];
        foreach ($tables['tables'] as $table) {
            $formattedTables[$table['id']] = $table['name'];
        }

        return $formattedTables;
    }

    protected function getFields($baseId, $tableId)
    {
        if (!$baseId && $tableId) {
            return [];
        }

        $formattedFields = [];
        $api = $this->getRemoteClient();

        $tables = $api->getTables($baseId);
        if (is_wp_error($tables)) {
            wp_send_json_error([
                'message' => $tables->get_error_message()
            ], 423);
        }

        $fields = [];
        foreach ($tables['tables'] as $table) {
            if ($table['id'] == $tableId) {
                $fields = $table['fields'];
                break;
            }
        }

        $supportedFormatsArray = $this->getSupportedFormats();
        foreach ($fields as $fieldValue) {
            if (array_key_exists($fieldValue['type'], $supportedFormatsArray)) {
                $data = [
                    'key'         => $fieldValue['id'],
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'placeholder' => __($fieldValue['name'], 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips'        => __(ArrayHelper::get($supportedFormatsArray, $fieldValue['type']), 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'label'       => __($fieldValue['name'], 'fluentformpro'),
                    'required'    => false,
                    'component'   => 'value_text',
                    'type'        => $fieldValue['type'],
                ];
                $formattedFields[] = $data;
            }
        }

        return $formattedFields;
    }

    protected function getSupportedFormats()
    {
        return [
            'singleLineText'      => 'Single Line Text field',
            'multilineText'       => 'Multi Line Text field',
            'checkbox'            => 'Boolean field',
            'singleSelect'        => 'Single Select field',
            'multipleSelects'     => 'Multi Select field',
            'date'                => 'Date field supports M/D/YYYY | D/M/YYYY | YYYY-MM-DD format',
            'phoneNumber'         => 'Phone Field',
            'email'               => 'Email Field',
            'url'                 => 'URL field',
            'number'              => 'Number Field',
            'currency'            => 'Currency Field',
            'percent'             => 'Percentage Value',
            'duration'            => 'An integer value representing number in seconds',
            'rating'              => 'Rating Field supports value ranged from 1 to 10',
            'singleCollaborator'  => 'Use Airtable registered User email',
            'multipleAttachments' => 'Allow you to add images, documents through Upload',
            'richText'            => 'Rich Text Field',
        ];
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $subscriber = [];
        $records['records'] = [];
        $baseId = ArrayHelper::get($feedData, 'chained_config' . '.' . 'base_id');
        $tableId = ArrayHelper::get($feedData, 'chained_config' . '.' . 'table_id');
        $formFields = ArrayHelper::get(json_decode($form->form_fields, true), 'fields');
        $fields = $this->getFields($baseId, $tableId);

        foreach ($fields as $field) {
            $fieldType = $field['type'];
            $fieldName = $field['label'];
            $fieldKey = $field['key'];
            $value = ArrayHelper::get($feedData, $fieldKey);
            if (!empty($value)) {
                if ($fieldType == 'checkbox') {
                    if ($value == 'true' || $value == 'yes') {
                        $value = true;
                    } elseif ($value == 'false' || $value == 'no') {
                        $value = false;
                    }
                    $subscriber[$fieldName] = $value;
                } elseif ($fieldType == 'multipleSelects') {
                    $arrayValues = array_map('trim', explode(',', $value));
                    $subscriber[$fieldName] = [];
                    foreach ($arrayValues as $multiValue) {
                        $subscriber[$fieldName][] = $multiValue;
                    }
                } elseif ($fieldType == 'singleCollaborator') {
                    if (is_email($value)) {
                        $subscriber[$fieldName] = ['email' => $value];
                    }
                } elseif ($fieldType == 'multipleAttachments') {
                    $arrayValues = array_map('trim', explode(',', $value));
                    $subscriber[$fieldName] = [];
                    foreach ($arrayValues as $urlValue) {
                        $subscriber[$fieldName][] = ['url' => $urlValue];
                    }
                } elseif ($fieldType == 'phoneNumber') {
                    // handling phone validation issue
                    // airtable only supports US/Canada style 10 digits (xxx) xxx-xxxx phone number format
                    foreach ($formFields as $formField) {
                        if (ArrayHelper::get($formField, 'element') == 'phone') {
                            $value = $this->handlePhoneNumberFormatting($value);
                        }
                    }
                    $subscriber[$fieldName] = $value;
                } elseif (
                        $fieldType == 'number' ||
                        $fieldType == 'currency' ||
                        $fieldType == 'percent' ||
                        $fieldType == 'duration' ||
                        $fieldType == 'rating'
                ) {
                    if (strpos($value, ',') !== false) {
                        $value = str_replace("," , "" , $value);
                    }
                    $subscriber[$fieldName] = +$value;
                } else {
                    $subscriber[$fieldName] = $value;
                }
            }
        }

        $subscriber = Helper::replaceBrTag($subscriber);
        $isTypecast = ArrayHelper::get($feed, 'settings.typecast');
        if ($isTypecast) {
            $records = ['typecast' => true];
        }

        $records['records'][] = ['fields' => $subscriber];
        $client = $this->getRemoteClient();
        $response = $client->subscribe($records, $baseId, $tableId);

        if (!is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'success',
                __('Airtable feed has been successfully initialed and pushed data', 'fluentformpro'));
        } else {
            $error = $response->get_error_message();
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }

    private function handlePhoneNumberFormatting($value)
    {
        $numericPart = preg_replace('/\D/', '', $value);

        // Pad the number with leading zeros if it's less than 10 digits
        if (strlen($numericPart) < 10) {
            $numericPart = str_pad($numericPart, 10, '0', STR_PAD_LEFT);
        }

        // Format the number
        if (strlen($numericPart) == 10) {
            // Format a 10-digit number
            return sprintf("(%s) %s-%s",
                substr($numericPart, 0, 3),
                substr($numericPart, 3, 3),
                substr($numericPart, 6));
        }

        if (strlen($numericPart) > 10) {
            // Extract the last 10 digits for the phone number
            $phoneNumberPart = substr($numericPart, -10);

            // Extract the country code part (digits before the last 10 digits)
            $countryCodePart = substr($numericPart, 0, -10);

            // Format the phone number part
            $formattedPhoneNumber = sprintf("(%s) %s-%s",
                substr($phoneNumberPart, 0, 3),
                substr($phoneNumberPart, 3, 3),
                substr($phoneNumberPart, 6));

            return '+' . $countryCodePart . ' ' . $formattedPhoneNumber;
        }

        return $value;
    }
}
