<?php

namespace FluentFormPro\Integrations\Airtable;

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
            'Airtable Legacy',
            'airtable',
            '_fluentform_airtable_settings',
            'airtable_feed',
            36
        );

//        add_filter('fluentform/notifying_async_airtable', '__return_false');

        $this->logo = fluentFormMix('img/integrations/airtable.png');

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
            $settings['chained_config']['base_id'] = $baseId;
        }

        if ($tableId) {
            $settings['chained_config']['table_id'] = $tableId;
        }

        return $settings;
    }

    public function validate($settings, $integrationId, $formId)
    {
        $error  = false;
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
                $error                 = true;
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
            'api_key'  => '',
            'base_id'  => '',
            'table_id' => '',
            'status'   => false,
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
                'api_key'  => [
                    'type'        => 'password',
                    'placeholder' => __('Airtable API Key', 'fluentformpro'),
                    'label_tips'  => __('Enter your Airtable API Key', 'fluentformpro'),
                    'label'       => __('Airtable API Key', 'fluentformpro'),
                ],
                'base_id'  => [
                    'type'        => 'text',
                    'placeholder' => __('Airtable Base ID', 'fluentformpro'),
                    'label_tips'  => __('Enter your Airtable Base ID', 'fluentformpro'),
                    'label'       => __('Airtable Base ID', 'fluentformpro'),
                ],
                'table_id' => [
                    'type'        => 'text',
                    'placeholder' => __('Airtable Table ID', 'fluentformpro'),
                    'label_tips'  => __('Enter your Airtable Table ID', 'fluentformpro'),
                    'label'       => __('Airtable Table ID', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'      => true,
            'discard_settings'   => [
                'section_description' => __('Your Airtable API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Airtable', 'fluentformpro'),
                'data'                => [
                    'api_key' => '',
                ],
                'show_verify'         => true
            ]
        ];
    }

    protected function getConfigInstructions()
    {
        $newAirtableLink = admin_url('admin.php?page=fluent_forms_settings#general-airtable_v2-settings');
        ob_start(); ?>
        <div>
            <p style="color: #f52020">Airtable announces Personal API keys will be deprecated by the end of January 2024. So we will close this Airtable integration after January 2024. Please use new airtable integration from <a href="<?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $newAirtableLink ?>" target="_blank">here.</a></p>
            <ol>
                <li>Go <a href="https://airtable.com/account" target="_blank">Here</a> and copy your API key and paste
                    it.
                </li>
                <li>Go <a href="https://airtable.com/api" target="_blank">Here</a> and select your desired Airtable base
                    and then copy the ID of this base and paste it as Base ID. Then scroll to Table section and select
                    your desired Airtable table under selected base and then copy the ID of this table and paste it as
                    Table ID.
                </li>
                <li>If you want to implement multiple bases or tables, then just paste Base IDs or Table IDs on their
                    respective fields by separating them with commas(,). Then select them your desired Base and Table
                    combination from respected Fluent Form integration.
                </li>
                <li>You have to fill all of fields of any single row in Airtable to integrate with Fluent Form. If there
                    is any blank column in the row, blank columns will be skipped. You must ensure that there is at
                    least one row in the table that contains no blank column.
                </li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['api_key']) || empty($settings['base_id']) || empty($settings['table_id'])) {
            $integrationSettings = [
                'api_key'  => '',
                'base_id'  => '',
                'table_id' => '',
                'status'   => false
            ];

            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_error([
                'message' => __('Please provide all fields to integrate', 'fluentformpro'),
                'status'  => false
            ], 423);
        }

        $integrationSettings = [
            'api_key'  => $settings['api_key'],
            'base_id'  => $settings['base_id'],
            'table_id' => $settings['table_id'],
            'status'   => true
        ];

        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your Airtable API key has been verified and successfully set', 'fluentformpro'),
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-airtable-settings'),
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
                            'options'     => $this->getTables()
                        ],
                    ]
                ],
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];

        $config  = $this->app->request->get('configs', ArrayHelper::get($settings, 'chained_config'));
        $baseId  = $config['base_id'];
        $tableId = $config['table_id'];

        $error   = false;
        $message = '';

        if ($baseId && $tableId) {
            $fields = $this->getFields($baseId, $tableId);

            if (isset($fields['status']) && $fields['status'] === false) {
                $error   = true;
                $message = $fields['message'];
            }

            if (!$error) {
                $fields                  = array_merge($fieldSettings['fields'], $fields);
                $fieldSettings['fields'] = $fields;
            }
        }

        $fieldSettings['fields'] = array_merge($fieldSettings['fields'], [
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

    protected function getFields($baseId, $tableId)
    {
        $fields = [];
        $client = $this->getRemoteClient();

        if ( ! $this->isConfigured()) {
            return [
                'message' => __('Not configured.', 'fluentformpro'),
                'status'  => false
            ];
        }

        try {
            $lists = $client->makeRequest('https://api.airtable.com/v0/' . $baseId . '/' . $tableId, null);

            if ( ! $lists) {
                return [
                    'message' => __('No Record Found.', 'fluentformpro'),
                    'status'  => false
                ];
            }
        } catch (\Exception $exception) {
            return [
                'message' => $exception->getMessage(),
                'status'  => false
            ];;
        }

        if (is_wp_error($lists)) {
            return [
                'message' => __('Please select valid Base and Table field combination.', 'fluentformpro'),
                'status'  => false
            ];
        }

        $empty = true;

        foreach ($lists['records'] as $index => $records) {
            if ( ! empty($lists['records'][$index]['fields'])) {
                $empty = false;
                break;
            }
        }

        if ($empty) {
            return [
                'message' => __('Your base table is empty. You must ensure that there is at least one row in the table that contains no blank column.', 'fluentformpro'),
                'status'  => false
            ];
        }


        $customList  = [];
        $maxKeyCount = 0;
        $desiredKey  = 0;

        foreach ($lists['records'] as $fieldKey => $fieldValues) {
            if ($maxKeyCount < count($fieldValues['fields'])) {
                $maxKeyCount = count($fieldValues['fields']);
                $desiredKey  = $fieldKey;
            }
        }

        foreach ($lists['records'][$desiredKey]['fields'] as $fieldKey => $fieldValues) {
            if (is_array($fieldValues)) {
                if (array_key_exists('name', $fieldValues) && array_key_exists('email', $fieldValues)) {
                    $customList['key']       = 'collab_' . $fieldKey;
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    $customList['label']     = __('Enter ' . $fieldKey, 'fluentformpro');
                    $customList['required']  = false;
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    $customList['tips']      = __('Enter ' . $fieldKey . ' value or choose form input provided by shortcode.',
                        'fluentformpro');
                    $customList['component'] = 'value_text';
                } else {
                    foreach ($fieldValues as $value) {
                        if ( ! empty(ArrayHelper::get($value, 'url'))) {
                            $customList['key'] = 'url_' . $fieldKey;
                        } else {
                            $customList['key'] = 'array_' . $fieldKey;
                        }
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        $customList['label']     = __('Enter ' . $fieldKey, 'fluentformpro');
                        $customList['required']  = false;
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        $customList['tips']      = __('Enter ' . $fieldKey . ' value or choose form input provided by shortcode.',
                            'fluentformpro');
                        $customList['component'] = 'value_text';
                    }
                }
            } else {
                if ($fieldValues == 'true' || $fieldValues == 'false') {
                    $customList['key'] = 'boolean_' . $fieldKey;
                } else {
                    $customList['key'] = 'normal_' . $fieldKey;
                }
                $customList['component'] = 'value_text';
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                $customList['label']     = __('Enter ' . $fieldKey, 'fluentformpro');
                $customList['required']  = false;
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                $customList['tips']      = __('Enter ' . $fieldKey . ' value or choose form input provided by shortcode.', 'fluentformpro');
            }

            $fields[] = $customList;
        }

        return $fields;
    }

    protected function getBases()
    {
        $client = $this->getRemoteClient();

        if ( ! $this->isConfigured()) {
            return false;
        }

        return $client->baseIdArray;
    }

    protected function getTables()
    {
        $client = $this->getRemoteClient();

        if ( ! $this->isConfigured()) {
            return false;
        }

        return $client->tableIdArray;
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData           = $feed['processedValues'];
        $subscriber         = [];
        $records['records'] = [];
        $baseId             = $feedData['chained_config']['base_id'];
        $tableId            = $feedData['chained_config']['table_id'];
        $fields             = $this->getFields($baseId, $tableId);

        foreach ($fields as $index => $field) {
            $key   = ArrayHelper::get($field, 'key');
            $value = ArrayHelper::get($feedData, $key);

            if (!empty($value)) {
                $fieldArray = explode('_', $key);
                $fieldType  = $fieldArray[0];
                $fieldName  = $fieldArray[1];

                if ($fieldType == 'normal') {
                    $subscriber[$fieldName] = $value;
                } elseif ($fieldType == 'boolean') {
                    if ($value == 'true' || $value == 'yes') {
                        $value = true;
                    } elseif ($value == 'false' || $value == 'no') {
                        $value = false;
                    }
                    $subscriber[$fieldName] = $value;
                } elseif ($fieldType == 'url') {
                    $arrayValues            = array_map('trim', explode(',', $value));
                    $subscriber[$fieldName] = [];
                    foreach ($arrayValues as $urlValue) {
                        $subscriber[$fieldName][] = ['url' => $urlValue];
                    }
                } elseif ($fieldType == 'array') {
                    $arrayValues            = array_map('trim', explode(',', $value));
                    $subscriber[$fieldName] = $arrayValues;
                } elseif ($fieldType == 'collab') {
                    if (is_email($value)) {
                        $subscriber[$fieldName] = ['email' => $value];
                    }
                }
            }
        }

        $subscriber = Helper::replaceBrTag($subscriber);

        $records['records'][] = ['fields' => $subscriber];
        $client               = $this->getRemoteClient();
        $response             = $client->subscribe($records, $baseId, $tableId);

        if (!is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'success',
                'Airtable feed has been successfully initialed and pushed data');
        } else {
            $error = $response->get_error_message();
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }
}
