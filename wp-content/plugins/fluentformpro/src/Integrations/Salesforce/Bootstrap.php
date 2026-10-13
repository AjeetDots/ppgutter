<?php

namespace FluentFormPro\Integrations\Salesforce;

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
            'Salesforce',
            'salesforce',
            '_fluentform_salesforce_settings',
            'salesforce_feed',
            36
        );

//        add_filter('fluentform/notifying_async_salesforce', '__return_false');

        $this->logo = fluentFormMix('img/integrations/salesforce.png');

        $this->description = "Connect Salesforce with Fluent Forms and get contacts and others more organized.";

        $this->registerAdminHooks();

        add_action('admin_init', function () {
            $isSaleforceAuthCode = isset($_REQUEST['ff_salesforce_auth']) && (isset($_REQUEST['code']) || isset($_REQUEST['error']));

            if ($isSaleforceAuthCode) {
                if (!current_user_can('fluentform_settings_manager')) {
                    return;
                }
                // SECURITY (PRO-17): reject callbacks not bound to the initiating admin session.
                $state = isset($_REQUEST['state']) ? sanitize_text_field($_REQUEST['state']) : '';
                if (!wp_verify_nonce($state, 'ff_salesforce_oauth')) {
                    wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-salesforce-settings'));
                    exit();
                }
                if (isset($_REQUEST['error'])) {
                    $error = isset($_REQUEST['error_description']) ? $_REQUEST['error_description'] : $_REQUEST['error'];
                    $this->authorizationFailed(sanitize_text_field(wp_unslash($error)));
                }
                // Get the access token now
                $code = sanitize_text_field($_REQUEST['code']);
                $settings = $this->getGlobalSettings([]);
                $client = $this->getRemoteClient();
                $settings = $client->generateAccessToken($code, $settings);

                if (is_wp_error($settings)) {
                    $this->authorizationFailed($settings->get_error_message());
                }

                $settings['status'] = true;
                update_option($this->optionKey, $settings, 'no');

                wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-salesforce-settings'));
                exit();
            }
        });

        add_filter(
            'fluentform/get_integration_values_salesforce',
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

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new API(
            $settings
        );
    }

    protected function authorizationFailed($message)
    {
        wp_die(
            /* translators: %s: error message returned by Salesforce */
            '<p>' . esc_html(sprintf(__('Salesforce authorization failed: %s', 'fluentformpro'), $message)) . '</p>' .
            '<p><a href="' . esc_url(admin_url('admin.php?page=fluent_forms_settings#general-salesforce-settings')) . '">' .
            esc_html__('Back to Salesforce settings', 'fluentformpro') . '</a></p>',
            esc_html__('Salesforce authorization failed', 'fluentformpro'),
            ['response' => 400]
        );
    }

    public function getGlobalSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);

        if (!$globalSettings) {
            $globalSettings = [];
        }

        $defaults = [
            'client_id'     => '',
            'client_secret' => '',
            'status'        => false,
            'is_sandbox'    => false,
            'access_token'  => '',
            'refresh_token' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('Salesforce Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_description'   => __($this->description, 'fluentformpro'),
            'valid_message'      => __('Your Salesforce API Key is valid', 'fluentformpro'),
            'invalid_message'    => __('Your Salesforce API Key is not valid', 'fluentformpro'),
            'save_button_text'   => __('Save Settings', 'fluentformpro'),
            'config_instruction' => $this->getConfigInstructions(),
            'fields'             => [
                'is_sandbox' => [
                    'type'           => 'checkbox-single',
                    'checkbox_label' => __('Salesforce Sandbox Account', 'fluentformpro'),
                    'label_tips'     => __('Check if your Salesforce is a sandbox account', 'fluentformpro'),
                    'label'          => __('Sandbox Account', 'fluentformpro'),
                ],
                'instance_url' => [
                    'type'        => 'text',
                    'placeholder' => __('Salesforce Domain URL', 'fluentformpro'),
                    'label_tips'  => __('Enter your Salesforce domain URL, e.g. https://{MyDomainName}.my.salesforce.com', 'fluentformpro'),
                    'label'       => __('Salesforce Domain URL', 'fluentformpro'),
                ],
                'client_id' => [
                    'type'        => 'text',
                    'placeholder' => __('Salesforce Consumer Key', 'fluentformpro'),
                    'label_tips'  => __('Enter your Salesforce Consumer Key', 'fluentformpro'),
                    'label'       => __('Salesforce Consumer Key', 'fluentformpro'),
                ],
                'client_secret' => [
                    'type'        => 'password',
                    'placeholder' => __('Salesforce App Consumer Secret', 'fluentformpro'),
                    'label_tips'  => __('Enter your Salesforce Consumer secret', 'fluentformpro'),
                    'label'       => __('Salesforce Consumer Secret', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your Salesforce API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Salesforce', 'fluentformpro'),
                'data'                => [
                    'client_id'     => '',
                    'client_secret' => '',
                    'access_token'  => '',
                    'instance_url'  => '',
                ],
                'show_verify' => true
            ]
        ];
    }

    protected function getConfigInstructions()
    {
        $authLink = admin_url('?ff_salesforce_auth=true');
        ob_start(); ?>
        <div>
            <ol>
                <li>In Salesforce, open Setup -> Apps -> External Client App Manager -> New External Client App (or App Manager -> New Connected App if your org still allows it). Set the App Name, API Name and Contact Email, enable OAuth and set the callback URL as <b><?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $authLink; ?></b></li>
                <li>Select the scopes : "Manage user data via APIs (api) and Perform requests at any time (refresh_token, offline_access)". "Require Proof Key for Code Exchange (PKCE)" and refresh token rotation are supported and can stay enabled. If your app restricts refresh tokens by IP address, add your website server's outgoing IP address to the app's Refresh Token IP allowlist; otherwise the connection will stop working after the first session expires. Save the app and wait a few minutes for it to be activated. Copy the Consumer Key and Consumer Secret to use them in the next step.<br/>
                </li>
                <li>Paste your Salesforce Domain URL, Consumer key and Consumer Secret and save the settings. This Domain URL uses a standard format as, <b>https://{MyDomainName}.my.salesforce.com</b>. For more details <a href="https://help.salesforce.com/s/articleView?id=sf.faq_domain_name_what.htm&type=5" target="_blank">click here</a>
                </li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            $integrationSettings = [
                'client_id'     => '',
                'client_secret' => '',
                'instance_url'  => '',
                'access_token'  => '',
                'is_sandbox'    => false,
                'status'        => false
            ];
            // Update the details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_success([
                'message' => __('Your settings has been updated', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        try {
            if (empty($settings['instance_url'])) {
                throw  new \Exception('Instance URL is required');
            }

            $instanceUrl = 'https://' . preg_replace('#^https?://#i', '', trim(sanitize_text_field($settings['instance_url'])));
            if (!API::isAllowedSalesforceHost($instanceUrl)) {
                throw new \Exception(__('Salesforce Domain URL must look like https://{MyDomainName}.my.salesforce.com', 'fluentformpro'));
            }

            $isSandbox = isset($settings['is_sandbox']) && $settings['is_sandbox'] === 'true' ? true : false;

            $oldSettings = $this->getGlobalSettings([]);
            $oldSettings['client_id'] = sanitize_text_field($settings['client_id']);
            $oldSettings['client_secret'] = sanitize_text_field($settings['client_secret']);
            $oldSettings['instance_url'] = $instanceUrl;
            $oldSettings['is_sandbox'] = $isSandbox;
            $oldSettings['status'] = false;

            update_option($this->optionKey, $oldSettings, 'no');

            wp_send_json_success([
                'message'      => __('You are redirecting Salesforce to authenticate', 'fluentformpro'),
                'redirect_url' => $this->getRemoteClient()->getRedirectServerURL()
            ], 200);
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-salesforce-settings'),
            'configure_message'     => __('Salesforce is not configured yet! Please configure your Salesforce api first', 'fluentformpro'),
            'configure_button_text' => __('Set Salesforce API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        $listId = $this->app->request->get('serviceId');

        return [
            'name'         => '',
            'list_id'      => $listId,
            'other_fields' => [
                [
                    'item_value' => '',
                    'label'      => ''
                ]
            ],
            'conditionals' => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled' => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        $fieldSettings = [
            'fields' => [
                [
                    'key'         => 'name',
                    'label'       => __('Feed Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'         => 'list_id',
                    'label'       => __('Salesforce Services', 'fluentformpro'),
                    'placeholder' => __('Select Salesforce Service', 'fluentformpro'),
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
                'tips'         => __('Allow this integration conditionally based on your submission values', 'fluentformpro'),
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

    protected function getLists()
    {
        return [
            'account'       => 'Account',
            'campaign'      => 'Campaign',
            'case'          => 'Case',
            'contact'       => 'Contact',
            'lead'          => 'Lead',
            'opportunity'   => 'Opportunity',
            'product2'      => 'Product',
        ];
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
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

    public function getFields($listId, $returnError = false)
    {
        $client = $this->getRemoteClient();
        if (!$this->isConfigured()) {
            return false;
        }

        $settings = get_option($this->optionKey);

        $lists = $client->makeRequest(
            rtrim($settings['instance_url'], '/') . '/services/data/v53.0/sobjects/' . $listId . '/describe',
            null
        );

        if (is_wp_error($lists)) {
            if ($returnError) {
                return $lists;
            }
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($lists->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $fields = [];

        if ($lists['fields']) {
            $otherFields = [];
            foreach ($lists['fields'] as $input) {
                if ($input['createable']) {
                    if (!$input['nillable'] && $input['type'] != 'picklist' && $input['type'] != 'reference' && $input['type'] != 'boolean' && $input['type'] != 'date') {
                        $data = [
                            'key'         => $input['name'],
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'placeholder' => __($input['label'], 'fluentformpro'),
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'label'       => __($input['label'], 'fluentformpro'),
                            'required'    => true,
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'tips'        => __($input['label'] . ' is a required field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ];
                        array_push($fields, $data);
                    }

                    if ($input['type'] == 'email') {
                        $data = [
                            'key'         => $input['name'],
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'placeholder' => __($input['label'], 'fluentformpro'),
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'label'       => __($input['label'], 'fluentformpro'),
                            'required'    => false,
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'tips'        => __($input['label'] . ' is a required field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ];
                        array_push($fields, $data);
                    }

                    if (!$input['nillable'] && $input['type'] == 'picklist' && !empty($input['picklistValues'])) {
                        $data = [
                            'key'         => $input['name'],
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'placeholder' => __($input['label'], 'fluentformpro'),
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'label'       => __($input['label'], 'fluentformpro'),
                            'required'    => true,
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'tips'        => __($input['label'] . ' is a required list field.', 'fluentformpro'),
                            'component'   => 'select'
                        ];
                        $options = [];
                        foreach ($input['picklistValues'] as $option) {
                            $options[$option['value']] = $option['label'];
                        }
                        $data['options'] = $options;
                        array_push($fields, $data);
                    }

                    if ($input['nillable'] && $input['type'] == 'picklist' && !empty($input['picklistValues'])) {
                        $data = [
                            'key'         => $input['name'],
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'placeholder' => __($input['label'], 'fluentformpro'),
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'label'       => __($input['label'], 'fluentformpro'),
                            'required'    => false,
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'tips'        => __($input['label'] . ' is a list field.', 'fluentformpro'),
                            'component'   => 'select'
                        ];
                        $options = [];
                        foreach ($input['picklistValues'] as $option) {
                            $options[$option['value']] = $option['label'];
                        }
                        $data['options'] = $options;
                        array_push($fields, $data);
                    }

                    if (!$input['nillable'] && $input['type'] == 'date') {
                        $data = [
                            'key'         => $input['name'],
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'placeholder' => __($input['label'], 'fluentformpro'),
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'label'       => __($input['label'], 'fluentformpro'),
                            'required'    => true,
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                            'tips'        => __($input['label'] . ' is a required field. Date format must be YYYY/MM/DD 00:00:00 format.', 'fluentformpro'),
                            'component'   => 'datetime'
                        ];
                        array_push($fields, $data);
                    }

                    if ($input['nillable'] && ($input['type'] != 'picklist' && $input['type'] != 'reference' && $input['type'] != 'boolean' && $input['type'] != 'email')) {
                        $otherFields[$input['name']] = $input['label'];
                    }
                }
            }
            if (!empty($otherFields)) {
                $fields[] = [
                    'key'                => 'other_fields',
                    'require_list'       => false,
                    'required'           => false,
                    'label'              => __('Other Fields', 'fluentformpro'),
                    'tips'               => __('Map fields according with Fluent Forms fields.', 'fluentformpro'),
                    'component'          => 'dropdown_many_fields',
                    'field_label_remote' => 'Others Field',
                    'field_label_local'  => 'Others Field',
                    'options'            => $otherFields
                ];
            }
        }

        return $fields;
    }

    protected function getAllFields($listId)
    {
        $fields = $this->getFields($listId, true);

        if (is_wp_error($fields)) {
            return $fields;
        }

        $allFields = [];
        foreach ($fields as $field) {
            $keyData = [];
            $keyData['key'] = $field['key'];
            if ($field['required']) {
                $keyData['required'] = $field['required'];
                array_push($allFields, $keyData);
            } else {
                $keyData['required'] = 0;
                array_push($allFields, $keyData);
            }
        }

        return $allFields;
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        if (empty($feedData['list_id'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', 'No Valid Service Found');
            return;
        }

        $subscriber = [
            'list_id' => $feedData['list_id']
        ];

        $allFields = $this->getAllFields($feedData['list_id']);

        if (is_wp_error($allFields)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $allFields->get_error_message());
            return;
        }

        foreach ($allFields as $field) {
            if (!empty($feedData[$field['key']])) {
                if ($field['key'] == 'other_fields') {
                    $otherFields = ArrayHelper::get($feedData, 'other_fields');

                    foreach ($otherFields as $other) {
                        if (!empty($other['item_value'])) {
                            $subscriber['attributes'][$other['label']] = $other['item_value'];
                        }
                    }
                } else {
                    $subscriber['attributes'][$field['key']] = ArrayHelper::get($feedData, $field['key']);
                }
            }
        }

        $client = $this->getRemoteClient();
        $response = $client->subscribe($subscriber);

        if (!is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'success', 'Salesforce feed has been successfully initialed and pushed data');
        } else {
            $error = $response->get_error_message();
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }
}
