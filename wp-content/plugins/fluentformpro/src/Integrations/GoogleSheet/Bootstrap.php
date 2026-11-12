<?php

namespace FluentFormPro\Integrations\GoogleSheet;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Integrations\GoogleSheet\API\Sheet;
use FluentFormPro\Services\RemoteAuth;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Google Sheets',
            'google_sheet',
            '_fluentform_google_sheet_settings',
            'google_sheet_notification_feed',
            26
        );

        $this->logo = fluentFormMix('img/integrations/google-sheets.png');
        $this->description = 'Add Fluent Forms Submission to Google sheets when a form is submitted.';
        $this->registerAdminHooks();
        // add_filter('fluentform/notifying_async_google_sheet', '__return_false');
        add_filter('fluentform/save_integration_value_google_sheet', array($this, 'checkColumnSlugs'), 10, 2);

        add_action('admin_init', [$this, 'maybeHandleAuthReturn']);
        add_filter('fluentform/global_settings_denied_option_keys', [RemoteAuth::class, 'hideIdentityOption']);
        // Free removes admin_notices on its own pages; notices render on this hook instead.
        add_action('fluentform/global_menu', [RemoteAuth::class, 'showClockSkewNotice'], 99);
    }

    public function maybeHandleAuthReturn()
    {
        RemoteAuth::handleReturn(
            'ff_gsheet_auth',
            'google',
            'sheets',
            admin_url('admin.php?page=fluent_forms_settings#general-google_sheet-settings'),
            [$this, 'saveConnection']
        );
    }

    public function saveConnection($result)
    {
        $tokens = $result['token'];
        $tokens['wrapped_refresh_token'] = $result['wrapped_refresh_token'];
        $tokens['created_at'] = time();
        $tokens['status'] = true;
        $tokens['version'] = 'latest';

        update_option($this->optionKey, $tokens, 'no');
        RemoteAuth::resetClockState();
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo' => $this->logo,
            'menu_title' => __('Google Sheets', 'fluentformpro'),
            'menu_description' => __('Connect your Google account to push form submissions to a Google Sheet.', 'fluentformpro'),
            'valid_message' => __('Your Google Sheets connection is valid', 'fluentformpro'),
            'invalid_message' => RemoteAuth::pullFailure() ?: __('Your Google Sheets connection is not valid', 'fluentformpro'),
            'save_button_text' => __('Connect Google Sheets', 'fluentformpro'),
            // Shown on a connection made before per-site credentials existed ($integration.version is unset).
            'reconnect_notice' => __("This connection is using an older shared app credential that we're retiring for security reasons. Your feeds keep working as-is — no data is at risk — but we recommend reconnecting to switch to a secure, dedicated connection for your site. It only takes a minute and won't change your feed settings.", 'fluentformpro'),
            'fields' => [],
            'hide_on_valid' => true,
            // Disconnect leaves discard_settings.data in the screen's state; refetch so the next Connect posts fresh settings.
            'reload_on_save' => true,
            'discard_settings' => [
                'section_description' => __('Your Google Sheets integration is up and running', 'fluentformpro'),
                'button_text' => __('Disconnect Google Sheets', 'fluentformpro'),
                'data' => [
                    'connected' => ''
                ],
                'show_verify' => false
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
            RemoteAuth::resetClockState();
            wp_send_json_success([
                'message' => __('Your Google Sheets connection has been removed', 'fluentformpro'),
                'status' => false
            ], 200);
        }

        $connectUrl = RemoteAuth::prepareAuth('google', 'sheets', admin_url('?ff_gsheet_auth=1'));

        wp_send_json_success([
            'message' => __('You are being redirected to Google to authorize access', 'fluentformpro'),
            'redirect_url' => $connectUrl
        ], 200);
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title' => 'Google Sheet',
            'logo' => $this->logo,
            'is_active' => $this->isConfigured(),
            'configure_title' => __('Configuration required!', 'fluentformpro'),
            'global_configure_url' => admin_url('admin.php?page=fluent_forms_settings#general-google_sheet-settings'),
            'configure_message' => __('Google Sheet is not configured yet! Please configure your Google Sheet api first', 'fluentformpro'),
            'configure_button_text' => __('Set Google Sheet API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name' => '',
            'spreadsheet_id' => '',
            'work_sheet_id' => '',
            'meta_fields' => [
                (object)array()
            ],
            'conditionals' => [
                'conditions' => [],
                'status' => false,
                'type' => 'all'
            ],
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
                    'key' => 'spreadsheet_id',
                    'label' => __('Spreadsheet ID', 'fluentformpro'),
                    'required' => true,
                    'placeholder' => __('Spreadsheet ID', 'fluentformpro'),
                    'component' => 'text',
                    'inline_tip' => __('<a href="https://wpmanageninja.com/docs/fluent-form/integrations-available-in-wp-fluent-form/google-sheet-integration#get_sheet_id" target="blank">Check documentation</a> for how to find google Spreadsheet ID', 'fluentformpro')
                ],
                [
                    'key' => 'work_sheet_id',
                    'label' => __('Worksheet Name', 'fluentformpro'),
                    'required' => true,
                    'placeholder' => __('Worksheet Name', 'fluentformpro'),
                    'component' => 'text',
                    'inline_tip' => __('<a href="https://wpmanageninja.com/docs/fluent-form/integrations-available-in-wp-fluent-form/google-sheet-integration#get_sheet_id" target="blank">Check documentation</a> for how to find google Worksheet Name', 'fluentformpro')
                ],
                [
                    'key' => 'meta_fields',
                    'label' => __('Spreadsheet Fields', 'fluentformpro'),
                    'sub_title' => __('Please specify the meta ', 'fluentformpro'),
                    'required' => true,
                    'component' => 'dropdown_label_repeater',
                    'enable_map_all' => true,
                ],
                [
                    'key' => 'conditionals',
                    'label' => __('Conditional Logics', 'fluentformpro'),
                    'tips' => __('Push data to google sheet conditionally based on your submission values', 'fluentformpro'),
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

    public static function filterMappedRows($rows)
    {
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, function ($row) {
            $row = (array)$row;
            $label = trim((string)ArrayHelper::get($row, 'label', ''));
            $value = trim((string)ArrayHelper::get($row, 'item_value', ''));
            return '' !== $label || '' !== $value;
        }));
    }

    public function checkColumnSlugs($settings, $integrationId)
    {
        $message = 'Validation Failed';
        // Validate First
        $errors = [];
        $settings['meta_fields'] = self::filterMappedRows(ArrayHelper::get($settings, 'meta_fields', []));

        if (empty($settings['spreadsheet_id'])) {
            $errors['spreadsheet_id'] = [__('Please Provide spreadsheet ID', 'fluentformpro')];
        }
        if (empty($settings['work_sheet_id'])) {
            $errors['work_sheet_id'] = [__('Please Provide Worksheet Name', 'fluentformpro')];
        }
        if (empty($settings['meta_fields'])) {
            $errors['meta_fields'] = [__('Please map at least one Spreadsheet Field', 'fluentformpro')];
        }

        if (count($settings['meta_fields']) > 208) {
            $errors['meta_fields'] = [__('Spreadsheet Fields can not bet greater than 104', 'fluentformpro')];
            $message = __('Spreadsheet Fields can not bet greater than 104', 'fluentformpro');
        }

        if ($errors) {
            wp_send_json_error([
                'message' => $message,
                'errors' => $errors
            ], 423);
        }

        $keys = [];

        foreach ($settings['meta_fields'] as $index => $meta) {
            if (empty($meta['slug'])) {
                $slug = sanitize_title($meta['label'], 'column_' . $index, 'display');
                if (isset($keys[$slug])) {
                    $slug = $slug . '_' . time() . '_' . wp_rand(1, 100);
                }
                $settings['meta_fields'][$index]['slug'] = $slug;
                $keys[$slug] = $meta['label'];
            } else {
                $keys[$meta['slug']] = $meta['label'];
            }
        }


        // Let's get the sheet Header Now
        $sheet = new Sheet();
        $sheetId = $settings['spreadsheet_id'];
        $workId = $settings['work_sheet_id'];
        $response = $sheet->insertHeader($sheetId, $workId, $keys);

        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => $response->get_error_message(),
                'errors' => $response
            ], 423);
        }

        // we are done here
        return $settings;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }

    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $row = [];
        $metaFields = $feedData['meta_fields'];
        $metaFieldsFeedSettings = ArrayHelper::get($feed, 'settings.meta_fields');

        if (!$metaFields) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('No meta fields found', 'fluentformpro'));
            return;
        }

        $inputs = FormFieldsParser::getEntryInputs($form, ['raw']);
        foreach ($metaFields as $index => $field) {
            if ($metaFieldsFeedSettings && $fieldFeedSetting = ArrayHelper::get($metaFieldsFeedSettings, $index)){
                $name = Helper::getInputNameFromShortCode(ArrayHelper::get($fieldFeedSetting, 'item_value'));
                if ($name && $element = ArrayHelper::get($inputs, $name)) {
                    if (
                        'tabular_grid' == $element['element'] &&
                        $value = Helper::getTabularGridFormatValue($formData[$name], $element, "\n", ",  ")
                    ) {
                        $row[] = $value;
                        continue;
                    } elseif ("repeater_field" == $element['element']) {
                        $value = wp_unslash(sanitize_textarea_field(ArrayHelper::get($field, 'item_value')));
                        $row[] = str_replace('  ', '', $value);
                        continue;
                    }
                }
            }
            $row[] = wp_unslash(sanitize_textarea_field(ArrayHelper::get($field, 'item_value')));
        }
    
        $row = apply_filters_deprecated(
            'fluentform_integration_data_' . $this->integrationKey,
            [
                $row,
                $feed,
                $entry
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/integration_data_' . $this->integrationKey,
            'Use fluentform/integration_data_' . $this->integrationKey . ' instead of fluentform_integration_data_' . $this->integrationKey
        );
        $row = apply_filters('fluentform/integration_data_' . $this->integrationKey, $row, $feed, $entry);

        $sheet = new Sheet();
        $response = $sheet->insertRow($feedData['spreadsheet_id'], $feedData['work_sheet_id'], $row);

        if (is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
        } else {
            do_action('fluentform/integration_action_result', $feed, 'success', __('Pushed data to Google Sheet', 'fluentformpro'));
        }
    }
}
