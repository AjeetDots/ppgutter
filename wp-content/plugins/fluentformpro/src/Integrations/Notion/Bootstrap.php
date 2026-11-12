<?php

namespace FluentFormPro\Integrations\Notion;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Str;
use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\Framework\Support\Arr;
use FluentFormPro\Services\RemoteAuth;

class Bootstrap extends IntegrationManagerController
{
    private $dataSourceIds = [];

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

        add_action('admin_init', [$this, 'maybeHandleAuthReturn']);
    }

    public function maybeHandleAuthReturn()
    {
        RemoteAuth::handleReturn(
            'ff_notion_auth',
            'notion',
            'workspace',
            admin_url('admin.php?page=fluent_forms_settings#general-notion-settings'),
            [$this, 'saveConnection']
        );
    }

    public function saveConnection($result)
    {
        update_option($this->optionKey, [
            'status'       => true,
            'access_token' => $result['token']['access_token'],
            'version'      => 'latest',
        ], 'no');
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
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('Notion Integration', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_description' => __($this->description, 'fluentformpro'),
            'valid_message'    => __('Your Notion connection is valid', 'fluentformpro'),
            'invalid_message'  => RemoteAuth::pullFailure() ?: __('Your Notion connection is not valid', 'fluentformpro'),
            'save_button_text' => __('Connect Notion', 'fluentformpro'),
            // Shown on a connection made before per-site credentials existed ($integration.version is unset).
            'reconnect_notice' => __("This connection is using an older shared app credential that we're retiring for security reasons. Your feeds keep working as-is — no data is at risk — but we recommend reconnecting to switch to a secure, dedicated connection for your site. It only takes a minute and won't change your feed settings.", 'fluentformpro'),
            'fields'           => [],
            'hide_on_valid'    => true,
            // Disconnect leaves discard_settings.data in the screen's state; refetch so the next Connect posts fresh settings.
            'reload_on_save'   => true,
            'discard_settings' => [
                'section_description' => __('Your Notion integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Notion', 'fluentformpro'),
                'data'                => [
                    'connected' => ''
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

        return wp_parse_args($globalSettings, ['status' => false]);
    }

    public function saveGlobalSettings($settings)
    {
        if (array_key_exists('connected', $settings) && empty($settings['connected'])) {
            update_option($this->optionKey, ['status' => false], 'no');
            wp_send_json_success([
                'message' => __('Your Notion connection has been removed', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        $connectUrl = RemoteAuth::prepareAuth('notion', 'workspace', admin_url('?ff_notion_auth=1'));

        wp_send_json_success([
            'message'      => __('You are being redirected to Notion to authorize access', 'fluentformpro'),
            'redirect_url' => $connectUrl
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
                "value"    => "data_source",
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

        $sourcesByDatabase = [];

        foreach ($lists['results'] as $source) {
            $databaseId = ArrayHelper::get($source, 'parent.database_id');
            if ($databaseId) {
                $sourcesByDatabase[$databaseId][$source['id']] = ArrayHelper::get($source, 'title.0.plain_text', __('(Untitled)', 'fluentformpro'));
            }
        }

        // Saved feeds store the database ID, so it stays the key; it means the database's first data source.
        $databases = [];

        foreach ($sourcesByDatabase as $databaseId => $sources) {
            if (count($sources) === 1) {
                $databases[$databaseId] = reset($sources);
                continue;
            }

            $database = $api->makeRequest('https://api.notion.com/v1/databases/' . $databaseId);
            $databaseTitle = is_wp_error($database) ? reset($sources) : ArrayHelper::get($database, 'title.0.plain_text', __('(Untitled)', 'fluentformpro'));

            $databases[$databaseId] = $databaseTitle;
            foreach ($sources as $sourceId => $sourceTitle) {
                $databases[$sourceId] = $databaseTitle . ' › ' . $sourceTitle;
            }
        }

        return $databases;
    }

    protected function resolveDataSourceId($listId)
    {
        if (isset($this->dataSourceIds[$listId])) {
            return $this->dataSourceIds[$listId];
        }

        $database = (new API())->makeRequest('https://api.notion.com/v1/databases/' . $listId);

        // A non-default source picked in the feed is saved as its own data source ID, which is not a database.
        $dataSourceId = is_wp_error($database) ? $listId : ArrayHelper::get($database, 'data_sources.0.id', $listId);

        return $this->dataSourceIds[$listId] = $dataSourceId;
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

        $lists = $api->makeRequest('https://api.notion.com/v1/data_sources/' . $this->resolveDataSourceId($listId));

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
                $subscriber['parent']['data_source_id'] = $this->resolveDataSourceId($feedData['list_id']);
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
