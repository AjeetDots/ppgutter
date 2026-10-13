<?php

namespace FluentFormPro\Integrations\ZohoCRM;

defined('ABSPATH') or die;

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class Bootstrap extends IntegrationManagerController
{

    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Zoho CRM',
            'zohocrm',
            '_fluentform_zohocrm_settings',
            'zohocrm_feed',
            16
        );
        $this->logo = fluentFormMix('img/integrations/zohocrm.png');
        $this->description = 'Zoho CRM is an online Sales CRM software that manages your sales, marketing and support in one CRM platform.';
        $this->registerAdminHooks();
        add_filter('fluentform/save_integration_value_' . $this->integrationKey, [$this, 'validate'], 10, 3);
        add_action('admin_init', function () {
            if (isset($_REQUEST['ff_zohocrm_auth'])) {
                if (!current_user_can('fluentform_settings_manager')) {
                    return;
                }
                $client = $this->getRemoteClient();
                if (isset($_REQUEST['code'])) {
                    // SECURITY (PRO-17): verify the state binds this callback to the admin session
                    // that initiated the flow (see redirectToAuthServer). Reject forged callbacks.
                    $state = isset($_REQUEST['state']) ? sanitize_text_field($_REQUEST['state']) : '';
                    if (!wp_verify_nonce($state, 'ff_zohocrm_oauth')) {
                        wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-zohocrm-settings'));
                        exit();
                    }
                    // Get the access token now
                    $code = sanitize_text_field($_REQUEST['code']);
                    $settings = $this->getGlobalSettings([]);
                    $settings = $client->generateAccessToken($code, $settings);

                    if (!is_wp_error($settings)) {
                        $settings['status'] = true;
                        update_option($this->optionKey, $settings, 'no');
                    }

                    wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-zohocrm-settings'));
                    exit();
                } else {
                    $client->redirectToAuthServer();
                }
                die();
            }

        });
//        add_filter('fluentform/notifying_async_zohocrm', '__return_false');

        add_filter(
            'fluentform/get_integration_values_zohocrm',
            [$this, 'resolveIntegrationSettings'],
            100, 
            3
        );
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo' => $this->logo,
            'menu_title' => __('Zoho CRM Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_description' => __($this->description, 'fluentformpro'),
            'valid_message' => __('Your Zoho CRM API Key is valid', 'fluentformpro'),
            'invalid_message' => __('Your Zoho CRM API Key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'config_instruction' => $this->getConfigInstructions(),
            'fields' => [
                'accountUrl' => [
                    'type' => 'select',
                    'placeholder' => __('Your Zoho CRM Account URL', 'fluentformpro'),
                    'label_tips' => __("Please Choose your Zoho CRM Account URL", 'fluentformpro'),
                    'label' => __('Account URL', 'fluentformpro'),
                    'options' => [
                        'https://accounts.zoho.com' => 'US',
                        'https://accounts.zoho.com.au' => 'AU',
                        'https://accounts.zoho.eu' => 'EU',
                        'https://accounts.zoho.in' => 'IN',
                        'https://accounts.zoho.com.cn' => 'CN',
                    ]
                ],
                'client_id' => [
                    'type' => 'text',
                    'placeholder' => __('Zoho CRM Client ID', 'fluentformpro'),
                    'label_tips' => __("Enter your Zoho CRM Client ID, if you do not have <br>Please login to your Zoho CRM account and go to <a href='https://api-console.zoho.com/'>Zoho Developer Console</a><br>", 'fluentformpro'),
                    'label' => __('Zoho CRM Client ID', 'fluentformpro'),
                ],
                'client_secret' => [
                    'type' => 'password',
                    'placeholder' => __('Zoho CRM Client Secret', 'fluentformpro'),
                    'label_tips' => __("Enter your Zoho CRM  Key, if you do not have <br>Please login to your Zoho CRM account and go to <a href='https://api-console.zoho.com/'>Zoho Developer Console</a>", 'fluentformpro'),
                    'label' => __('Zoho CRM Client Secret', 'fluentformpro'),
                ],
            ],
            'hide_on_valid' => true,
            'discard_settings' => [
                'section_description' => __('Your Zoho CRM integration is up and running', 'fluentformpro'),
                'button_text' => __('Disconnect Zoho CRM', 'fluentformpro'),
                'data' => [
                    'accountUrl' => '',
                    'client_id' => '',
                    'client_secret' => ''
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
            'accountUrl' => '',
            'client_id' => '',
            'client_secret' => '',
            'status' => '',
            'access_token' => '',
            'refresh_token' => '',
            'expire_at' => false
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        $integrationSettings = array();
        $err_msg = 'Error: Authorization info missing.';
        $err = false;
        if (empty($settings['client_secret'])) {
            $integrationSettings['client_secret'] = '';
            $err_msg = 'Client Secret is required';
            $err = true;
        }
        if (empty($settings['client_id'])) {
            $integrationSettings['client_id'] = '';
            $err_msg = 'Client Id is required';
            $err = true;
        }
        if (empty($settings['accountUrl'])) {
            $integrationSettings['accountUrl'] = '';
            $err_msg = 'Choose an account Url.';
            $err = true;
        }
        if ($err) {
            $integrationSettings['status'] = false;
            update_option($this->optionKey, $integrationSettings, 'no');
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($err_msg, 'fluentformpro'),
                'status' => false
            ], 400);
        }


        // Verify API key now
        try {
            $oldSettings = $this->getGlobalSettings([]);
            $oldSettings['accountUrl'] = esc_url_raw($settings['accountUrl']);
            $oldSettings['client_id'] = sanitize_text_field($settings['client_id']);
            $oldSettings['client_secret'] = sanitize_text_field($settings['client_secret']);
            $oldSettings['status'] = false;

            update_option($this->optionKey, $oldSettings, 'no');
            wp_send_json_success([
                'message' => __('You are being redirected to authenticate', 'fluentformpro'),
                'redirect_url' => admin_url('?ff_zohocrm_auth=1')
            ], 200);
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        $name = $this->app->request->get('serviceName', '');
        $listId = $this->app->request->get('serviceId', '');

        return [
            'name' => $name,
            'list_id' => $listId,
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
            'title' => $this->title . ' Integration',
            'logo' => $this->logo,
            'is_active' => $this->isConfigured(),
            'configure_title' => __('Configuration required!', 'fluentformpro'),
            'global_configure_url' => admin_url('admin.php?page=fluent_forms_settings#general-zohocrm-settings'),
            'configure_message' => __('Zoho CRM is not configured yet! Please configure your Zoho CRM api first', 'fluentformpro'),
            'configure_button_text' => __('Set Zoho CRM API', 'fluentformpro')
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

        $listId = $this->app->request->get(
            'serviceId',
            Arr::get($settings, 'list_id')
        );
        
        if ($listId) {
            $fields = $this->getFields($listId);

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

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
    }

    public function validate($settings, $integrationId, $formId)
    {
        $error = false;
        $errors = array();

        foreach ($this->getFields($settings['list_id']) as $field){
            if ($field['required'] && empty($settings[$field['key']])) {
                $error = true;

                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                $errors[$field['key']] = [__($field['label'].' is required', 'fluentformpro')];
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

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $list_id = $feedData['list_id'];
        if (!$list_id) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('Skipped insert Zoho CRM feed data for missing Service Id.', 'fluentformpro'));
            return false;
        }
        list ($formattedMainFields, $formattedOtherFields) = $this->getFormattedFields($list_id);
        $postData = [];
        $tags = null;
        foreach ($formattedMainFields as $fieldName => $mainField) {
            $fieldValue = Arr::get($feedData, $fieldName);
            if ($mainField['required'] && empty($fieldValue)) {
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                do_action('fluentform/integration_action_result', $feed, 'failed', __("Skipped insert Zoho CRM  $list_id data for missing required value. Field: " . $fieldName . '.' , 'fluentformpro'));
                return false;
            }
            if (!empty($fieldValue)) {
                if ('Tag' == $fieldName) {
                    $tags = $fieldValue;
                    continue;
                }
                switch ($mainField['data_type']) {
                    case 'email' :
                         $value = sanitize_email($fieldValue);
                        break;
                    case 'date' :
                        if (strstr($fieldValue, '/')) {
                            $fieldValue = str_replace('/', '.', $fieldValue);
                        }
                        $date = new \Datetime($fieldValue);
                        $value = $date->format('Y-m-d');
                        break;
                    case 'datetime':
                        if (strstr($fieldValue, '/')) {
                            $fieldValue = str_replace('/', '.', $fieldValue);
                        }
                        $datetime = new \Datetime($fieldValue);
                        $value = $datetime->format("c");
                        break;
                    default:
                        $value = sanitize_text_field($fieldValue);
                        break;
                }
                $postData[$fieldName] = $value;
            }
        }

        if(!empty($feedData['other_fields'])){
            foreach ($feedData['other_fields'] as $otherField){
                $fieldName = Arr::get($otherField, 'label');
                $fieldValue = Arr::get($otherField, 'item_value');
                if(!empty($fieldName) && !empty($fieldValue)){
                    $fieldType = Arr::get($formattedOtherFields, $fieldName . '.data_type');
                    if ('textarea' == $fieldType) {
                        $fieldValue = sanitize_textarea_field($fieldValue);
                    }
                    $postData[$fieldName] = $fieldValue;
                }
            }
        }

        $client = $this->getRemoteClient();
        $response = $client->insertModuleData($list_id, $postData);

        if (is_wp_error($response)) {
            // it's failed
            do_action('fluentform/integration_action_result', $feed, 'failed',  __('Failed to insert Zoho CRM feed. Details : ', 'fluentformpro') . $response->get_error_message());
        } else {
            // It's success
            do_action('fluentform/integration_action_result', $feed, 'success', __('Zoho CRM feed has been successfully inserted ', 'fluentformpro') . $list_id . __(' data.', 'fluentformpro'));

            // update record tags
            if ($tags && $recordId = Arr::get($response, 'data.0.details.id')) {
                try {
                    $client->addTags($list_id, $recordId, $tags);
                } catch (\Exception $e) {
                    // ...
                }
            }
        }

    }

    protected function getFormattedFields($list_id)
    {
        $mainFields = $otherFields = array();
        $client = $this->getRemoteClient();
        $response = $client->getAllFields($list_id);
        if (!is_wp_error($response) && $fields = Arr::get($response,'fields')) {
            foreach ($fields as $field) {
                $fieldName = Arr::get($field, 'api_name');
                $fieldType = Arr::get($field, 'data_type');
                if (!$fieldName || !$fieldType) {
                    continue;
                }
                if ($this->isMainField($field)) {
                    $mainFields[$fieldName] = [
                        'required'  => Arr::isTrue($field,'system_mandatory'),
                        'data_type' => $fieldType,
                    ];
                } elseif ($this->isOtherField($field)) {
                    $otherFields[$fieldName] = [
                        'required'  => Arr::isTrue($field,'system_mandatory'),
                        'data_type' => $fieldType,
                    ];
                }
            }
        }
        return [$mainFields, $otherFields];
    }

    protected function getServices()
    {
        $client = $this->getRemoteClient();
        $response = $client->getAllModules();
        $services_options = array();
        if (is_wp_error($response)) {
            return $services_options;
        }
        if ($response['modules']) {
            $services = $response['modules'];

            $availableServices = [
                'Leads', 'Contacts', 'Accounts', 'Deals', 'Tasks', 'Cases', 'Vendors', 'Solutions', 'Campaigns'
            ];

            foreach ($services as $service) {
                $validService = $service['creatable'] &&
                                $service['global_search_supported'] &&
                                in_array($service['api_name'], $availableServices);

                if ($validService) {
                    $services_options[$service['api_name']] = $service['singular_label'];
                }
            }
        }
        return $services_options;
    }

    protected function getFields($module_key)
    {
        $client = $this->getRemoteClient();
        $response = $client->getAllFields($module_key);
        if (is_wp_error($response)) {
            return false;
        }
        $fields = array();
        if ($response['fields']) {

            $others_fields = array();
            foreach ($response['fields'] as $field) {
                if ($this->isMainField($field)) {
                    $data = array(
                        'key' => $field['api_name'],
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'placeholder' => __($field['display_label'], 'fluentformpro'),
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'label' => __($field['field_label'], 'fluentformpro'),
                        'required' => false,
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'tips' => __('Enter ' . $field['display_label'] . ' value or choose form input provided by shortcode.', 'fluentformpro'),
                        'component' => 'value_text'
                    );

                    if ($field['system_mandatory']) {
                        $data['required'] = true;
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        $data['tips'] = __($field['display_label'] . ' is a required field. Enter value or choose form input provided by shortcode.', 'fluentformpro');
                    }
                    if($field['data_type'] == 'datetime'){
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        $data['tips'] = __($field['display_label'] . ' is a required field. Enter value or choose form input shortcode. <br> Make sure format is (01/01/2022 00:00 +0:00)', 'fluentformpro');
                    }
                    if ($field['data_type'] == 'picklist' && $field['pick_list_values']) {
                        $data['component'] = 'select';
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        $data['tips'] = __("Choose " . $field['display_label'] . " type in select list.", 'fluentformpro');
                        $data_options= array();
                        foreach ($field['pick_list_values'] as $option) {
                            $data_options[$option['display_value']] = $option['display_value'];
                        }
                        $data['options'] = $data_options;
                    }
                    if ($field['data_type'] == 'textarea') {
                        $data['component'] = 'value_textarea';
                    }

                    $fields[] = $data;
                } elseif ($this->isOtherField($field)) {
                    $others_fields[$field['api_name']] = $field['field_label'];
                }

            }
            if (!empty($others_fields)) {
                $fields[] = [
                    'key'                => 'other_fields',
                    'require_list'       => false,
                    'required'           => false,
                    'label'              => __('Other Fields', 'fluentformpro'),
                    'tips'               => __('Select which Fluent Forms fields pair with their respective Zoho crm modules fields. <br /> Field value must be string type.', 'fluentformpro'),
                    'component'          => 'dropdown_many_fields',
                    'field_label_remote' => __('Others Field', 'fluentformpro'),
                    'field_label_local'  => __('Others Field', 'fluentformpro'),
                    'options'            => $others_fields
                ];
            }
        }

        return $fields;
    }

    protected function isMainField($field)
    {
        return $field['system_mandatory'] ||
            'picklist' == $field['data_type'] ||
            'email' == $field['data_type'] ||
            'Tag' == $field['api_name'];
    }

    protected function isOtherField($field)
    {
        return in_array(Arr::get($field, 'data_type'), ['text', 'textarea', 'integer', 'website', 'phone', 'double', 'currency']);
    }

    protected function getConfigInstructions()
    {
        ob_start();
        ?>
        <div><h4>To Authenticate Zoho CRM First you need to register your application with Zoho CRM.</h4>
            <ol>
                <li> To register,
                    Go to <a href="https://api-console.zoho.com/" target="_blank">Zoho Developer Console</a>.
                </li>
                <li>
                    Choose a client type: <br>
                    <strong>Web Based:</strong> Applications that are running on a dedicated HTTP server. <br>
                    <strong>Note:</strong> No other client type allowed.
                </li>
                <li>
                    Enter the following details: <br>
                    <strong>Client Name:</strong> The name of your application you want to register with Zoho. <br>
                    <strong>Homepage URL:</strong> The URL of your web page. Your site url
                    <b><u><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo site_url(); ?></u></b><br>
                    <strong>Authorized Redirect URIs:</strong> Your app redirect url must be
                    <b><u><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo admin_url('?ff_zohocrm_auth=1'); ?></u></b>
                </li>

                <li>
                    Click CREATE. You will receive the Client ID and Client Secret. Copy the Client Id and Client
                    Secret.
                </li>
                <li>Then go back to your Zoho CRM setting page and choose your account URL, also paste the Client Id and
                    Secret Id in the input bellow.
                </li>
                <li>
                    Then save settings. You will be redirect to the Zoho CRM authorized page. Click Allow button for
                    authorized.
                </li>
                <strong>Note:</strong> If authorized successful you wil be redirected to the Zoho CRM settings page. If not
                you will see the error message on that page.
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new ZohoCRM(
            $settings['accountUrl'],
            $settings
        );
    }
}