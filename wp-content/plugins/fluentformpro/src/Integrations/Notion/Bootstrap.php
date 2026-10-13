<?php

namespace FluentFormPro\Integrations\Notion;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Str;
use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\Framework\Support\Arr;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Notion',
            'notion',
            '_fluentform_notion_settings',
            'notion_feed',
            36
        );

        $this->logo = fluentFormMix('img/integrations/notion.png');
        $this->description = 'Send form submissions directly to your Notion database.';
        $this->registerAdminHooks();

//        add_filter('fluentform/notifying_async_notion', '__return_false');

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

    public function getGlobalFields($fields)
    {
        $api = new API();

        return [
            'logo'             => $this->logo,
            'menu_title'       => __('Notion Integration', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_description' => __($this->description, 'fluentformpro'),
            'valid_message'    => __('Your Notion connection is valid', 'fluentformpro'),
            'invalid_message'  => __('Your Notion connection is not valid', 'fluentformpro'),
            'save_button_text' => __('Verify Notion', 'fluentformpro'),
            'fields'           => [
                'button_link'  => [
                    'type'      => 'link',
                    'link_text' => __('Get Notion Code', 'fluentformpro'),
                    'link'      => $api->getAuthUrl(),
                    'target'    => '_blank',
                    'tips'      => __('Please click on this link get Access Code from Notion and Select your Notion database to connect with Fluent Forms', 'fluentformpro'),
                ],
                'access_token' => [
                    'type'        => 'password',
                    'placeholder' => __('Access Code', 'fluentformpro'),
                    'label_tips'  => __("Please find access code by clicking 'Get Notion Access Code' Button then paste it here", 'fluentformpro'),
                    'label'       => __('Access Code', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your Notion integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Notion', 'fluentformpro'),
                'data'                => [
                    'access_code' => ''
                ],
                'show_verify'         => false
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
            'access_token' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['access_token'])) {
            $integrationSettings = [
                'access_token' => '',
                'status'       => false
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
            $token = sanitize_textarea_field($settings['access_token']);
            $api = new API();
            $result = $api->generateAccessToken($token);

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            $token = [
                'status'       => true,
                'access_token' => $result
            ];

            update_option($this->optionKey, $token, 'no');
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }

        wp_send_json_success([
            'message' => __('Your Notion api key has been verified and successfully set', 'fluentformpro'),
            'status'  => true
        ], 200);
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => 'Notion Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-notion-settings'),
            'configure_message'     => __('Notion is not configured yet! Please configure your Notion api first', 'fluentformpro'),
            'configure_button_text' => __('Set Notion API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        $listId = $this->app->request->get('serviceId');

        return [
            'name'         => '',
            'list_id'      => $listId,
            'conditionals' => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'      => true
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
                    'key'         => 'list_id',
                    'label'       => __('Notion Databases', 'fluentformpro'),
                    'placeholder' => __('Select Notion Database', 'fluentformpro'),
                    'required'    => true,
                    'component'   => 'refresh',
                    'options'     => $this->getLists()
                ],
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];

        $listId = $this->app->request->get('serviceId', ArrayHelper::get($settings, 'list_id'));

        if ($listId) {
            $fields = $this->getFields($listId);

            if (empty($fields)) {
                wp_send_json_error([
                    'message' => __("The selected service doesn't have any field settings.", 'fluentformpro'),
                ], 423);
            }

            $fields = array_merge($fieldSettings['fields'], $fields);
            $fieldSettings['fields'] = $fields;
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

        return $fieldSettings;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
    }

    protected function getLists()
    {
        $api = new API();
        if (!$this->isConfigured()) {
            return false;
        }

        $bodyArgs = [
            "filter" => [
                "value"    => "database",
                "property" => "object"
            ]
        ];

        $lists = $api->makeRequest('https://api.notion.com/v1/search', $bodyArgs, 'POST');

        if (is_wp_error($lists)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($lists->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $databases = [];

        foreach ($lists['results'] as $db) {
            $databases[$db['id']] = $db['title'][0]['plain_text'];
        }

        return $databases;
    }

    public function validate($settings, $integrationId, $formId)
    {
        $error = false;
        $errors = [];

        foreach ($this->getFields($settings['list_id']) as $field) {
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

    public function getFields($listId, $isNameValuePair = false)
    {
        $api = new API();
        if (!$this->isConfigured()) {
            return false;
        }

        $lists = $api->makeRequest('https://api.notion.com/v1/databases/' . $listId);

        if (is_wp_error($lists)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($lists->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $fields = [];

        $lists = $lists['properties'];

        if ($lists) {
            $supportedFormats = $this->getSupportedFormats();
            foreach ($lists as $input) {
                if (!$isNameValuePair) {
                    if (Arr::exists($supportedFormats, $input['type'])) {
                        $fields[] = [
                            'key'         => $input['name'],
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'placeholder' => __($input['name'], 'fluentformpro'),
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'label'       => __($input['name'], 'fluentformpro'),
                            'required'    => false,
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'tips'        => __($supportedFormats[$input['type']], 'fluentformpro'),
                            'component'   => 'value_text'
                        ];
                    }
                } else {
                    if (Arr::exists($supportedFormats, $input['type'])) {
                        $fields[] = [
                            'name' => $input['name'],
                            'type' => $input['type']
                        ];
                    }
                }
            }
        }

        return $fields;
    }

    protected function getSupportedFormats()
    {
        return [
            'checkbox'     => 'Boolean field',
            'date'         => 'Date field support all types of dates without d/m/Y, M/d/Y and y/m/d formats',
            'email'        => 'Email Field',
            'multi_select' => 'Multiple Select field',
            'number'       => 'Number Field',
            'phone_number' => 'Phone Field',
            'select'       => 'Single Select field',
            'status'       => 'Array of options and groups object',
            'title'        => 'Title Field',
            'url'          => 'URL value',
            'rich_text'    => 'Text/Textarea Field',
        ];
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        if (empty($feedData['list_id'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', 'No Valid Database Found');
            return;
        }

        $subscriber = [];
        $allFields = $this->getFields($feedData['list_id'], true);
        $cls = new \stdClass();

        foreach ($allFields as $field) {
            $name = $field['name'];
            $type = $field['type'];
            $value = ArrayHelper::get($feedData, $name);

            if (!empty($value)) {
                $subscriber['parent']['database_id'] = $feedData['list_id'];
                $cls->$name = new \stdClass();

                if ($type == 'title' || $type == 'rich_text') {
                    $cls->$name->$type[] = [
                        'text' => [
                            'content' => $value
                        ]
                    ];
                } elseif ($type == 'select' || $type == 'status') {
                    $cls->$name->$type = new \stdClass();
                    $cls->$name->$type->name = $value;
                } elseif ($type == 'date') {
                    $cls->$name->$type = new \stdClass();
                    $date = @date_create($value);
                    // If successful, format and return the date
                    if ($date !== false) {
                        $cls->$name->$type->start = date_format($date, 'c');
                    }
                } elseif ($type == 'multi_select') {
                    $values = [];
                    $values[] = $value;
                    if (strpos($value, ',') !== false) {
                        $values = Str::separateString($value, ',');
                    }
                    $options = [];
                    foreach ($values as $option) {
                        $options[]['name'] = $option;
                    }
                    $cls->$name->$type = $options;
                } elseif ($type == 'checkbox') {
                    if ($value == 'yes' || $value == 'true' || $value == 1) {
                        $cls->$name->$type = true;
                    } elseif ($value == 'no' || $value == 'false' || $value == 0) {
                        $cls->$name->$type = false;
                    }
                } elseif ($type == 'number') {
                    $cls->$name->$type = floatval($value);
                } else {
                    $cls->$name->$type = $value;
                }
                $subscriber['properties'] = $cls;
            }
        }

        $api = new API();
        $response = $api->subscribe($subscriber);

        if (!is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'success',
                __('Notion feed has been successfully initialed and pushed data', 'fluentformpro'));
        } else {
            $error = $response->get_error_message();
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }
}
