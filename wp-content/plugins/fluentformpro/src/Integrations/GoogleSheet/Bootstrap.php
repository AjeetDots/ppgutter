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
use FluentFormPro\Integrations\GoogleSheet\API\API;
use FluentFormPro\Integrations\GoogleSheet\API\Sheet;

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
    }

    public function getGlobalFields($fields)
    {
        $api = new API();

        return [
            'logo' => $this->logo,
            'menu_title' => __('Google Sheets', 'fluentformpro'),
            'menu_description' => __('Copy that Google Access Code from other window and paste it here, then click on Verify Code button.', 'fluentformpro'),
            'valid_message' => __('Your Google Access Code is valid', 'fluentformpro'),
            'invalid_message' => __('Your Google Access Code is not valid', 'fluentformpro'),
            'save_button_text' => __('Verify Code', 'fluentformpro'),
            'fields' => [
                'access_code' => [
                    'type' => 'text',
                    'placeholder' => __('Access Code', 'fluentformpro'),
                    'label_tips' => __("Enter Google Access Code. Please find this by clicking 'Get Google Sheet Access Code' Button", 'fluentformpro'),
                    'label' => __('Access Code', 'fluentformpro'),
                ],
                'button_link' => [
                    'type' => 'link',
                    'link_text' => __('Get Google Sheet Access Code', 'fluentformpro'),
                    'link' => $api->getAUthUrl(),
                    'target' => '_blank',
                    'tips' => __('Please click on this link get get Access Code From Google', 'fluentformpro'),
                ]
            ],
            'hide_on_valid' => true,
            'discard_settings' => [
                'section_description' => __('Your Google Sheet integration is up and running', 'fluentformpro'),
                'button_text' => __('Disconnect Google Sheet', 'fluentformpro'),
                'data' => [
                    'access_code' => ''
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
        $defaults = [
            'access_code' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['access_code'])) {
            $integrationSettings = [
                'access_code' => '',
                'status' => false
            ];
            // Update the reCaptcha details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');
            wp_send_json_success([
                'message' => __('Your settings has been updated', 'fluentformpro'),
                'status' => false
            ], 200);
        }

        // Verify API key now
        try {
            $accessCode = sanitize_textarea_field($settings['access_code']);
            $api = new API();

            $result = $api->generateAccessKey($accessCode);

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            $result['access_code'] = $accessCode;
            $result['created_at'] = time();
            $result['status'] = true;
            $result['version'] = 'latest';

            update_option($this->optionKey, $result, 'no');
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }

        wp_send_json_success([
            'message' => __('Your Google Sheet api key has been verified and successfully set', 'fluentformpro'),
            'status' => true
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

    public function checkColumnSlugs($settings, $integrationId)
    {
        $message = 'Validation Failed';
        // Validate First
        $errors = [];
        if (empty($settings['spreadsheet_id'])) {
            $errors['spreadsheet_id'] = [__('Please Provide spreadsheet ID', 'fluentformpro')];
        }
        if (empty($settings['work_sheet_id'])) {
            $errors['work_sheet_id'] = [__('Please Provide Worksheet Name', 'fluentformpro')];
        }
        if (empty($settings['meta_fields'])) {
            $errors['meta_fields'] = [__('Please Provide Meta Fields Values', 'fluentformpro')];
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
