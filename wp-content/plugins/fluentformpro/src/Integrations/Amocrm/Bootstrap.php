<?php

namespace FluentFormPro\Integrations\Amocrm;

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
            'Amocrm',
            'amocrm',
            '_fluentform_amocrm_settings',
            'amocrm_feed',
            36
        );

//        add_filter('fluentform/notifying_async_amocrm', '__return_false');

        $this->logo = fluentFormMix('img/integrations/amocrm.png');

        $this->description = 'It can be a great way to manage your leads and tasks with amoCRM and Fluent Forms.';

        $this->registerAdminHooks();

        add_action('admin_init', function () {
            if (isset($_REQUEST['ff_amocrm_auth'])) {
                if (!current_user_can('fluentform_settings_manager')) {
                    return;
                }
                $client = $this->getRemoteClient();
                if(isset($_REQUEST['code'])) {
                    // SECURITY (PRO-17): reject callbacks not bound to the initiating admin session.
                    $state = isset($_REQUEST['state']) ? sanitize_text_field($_REQUEST['state']) : '';
                    if (!wp_verify_nonce($state, 'ff_amocrm_oauth')) {
                        wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-amocrm-settings'));
                        exit();
                    }
                    $code = sanitize_text_field($_REQUEST['code']);
                    $settings = $this->getGlobalSettings([]);
                    $referer = isset($settings['api_domain']) ? sanitize_text_field($settings['api_domain']) : '';
                    if (!$referer) {
                        $referer = sanitize_text_field($_REQUEST['referer']);
                    }
                    if (!$referer || !preg_match('/^[a-zA-Z0-9\-]+\.(amocrm|kommo)\.(com|ru|eu)$/', $referer)) {
                        wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-amocrm-settings'));
                        exit();
                    }
                    $settings = $client->generateAccessToken($code, $referer, $settings);
                    if (!is_wp_error($settings)) {
                        $settings['status'] = true;
                        update_option($this->optionKey, $settings, 'no');
                    }
                    wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-amocrm-settings'));
                    exit();
                }
                else {
                    $client->redirectToAuthServer();
                }
                die();
            }
        });

        add_filter(
            'fluentform/get_integration_values_amocrm',
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

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        
        return new API($settings);
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
            'account_url'   => '',
            'status'        => false,
            'access_token'  => '',
            'refresh_token' => '',
            'referer_url'   => ''
        ];

        $result = wp_parse_args($globalSettings, $defaults);

        // Backfill account_url from referer_url for existing installations
        if (empty($result['account_url']) && !empty($result['referer_url'])) {
            $referer = $result['referer_url'];
            if (strpos($referer, '//') === false) {
                $referer = 'https://' . $referer;
            }
            $host = parse_url($referer, PHP_URL_HOST);
            $result['account_url'] = $host ? strtolower($host) : $result['referer_url'];
        }

        return $result;
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('Amocrm Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_description'   => __($this->description, 'fluentformpro'),
            'valid_message'      => __('Your Amocrm Secret Key is valid', 'fluentformpro'),
            'invalid_message'    => __('Your Amocrm Secret Key is not valid', 'fluentformpro'),
            'save_button_text'   => __('Save Settings', 'fluentformpro'),
            'config_instruction' => $this->getConfigInstructions(),
            'fields'             => [
                'account_url' => [
                    'type'        => 'text',
                    'placeholder' => __('e.g. mycompany.amocrm.ru or mycompany.kommo.com', 'fluentformpro'),
                    'label_tips'  => __('Enter your Amocrm/Kommo account URL without https://', 'fluentformpro'),
                    'label'       => __('Account URL', 'fluentformpro'),
                ],
                'client_id' => [
                    'type'        => 'text',
                    'placeholder' => __('Amocrm Integration ID', 'fluentformpro'),
                    'label_tips'  => __('Enter your Amocrm Integration ID', 'fluentformpro'),
                    'label'       => __('Amocrm Integration ID', 'fluentformpro'),
                ],
                'client_secret' => [
                    'type'        => 'password',
                    'placeholder' => __('Amocrm Integration Secret Key', 'fluentformpro'),
                    'label_tips'  => __('Enter your Amocrm Integration Secret Key', 'fluentformpro'),
                    'label'       => __('Amocrm Secret Key', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your Amocrm API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Amocrm', 'fluentformpro'),
                'data'                => [
                    'account_url'   => '',
                    'client_id'     => '',
                    'client_secret' => '',
                    'access_token'  => '',
                ],
                'show_verify' => true
            ]
        ];
    }

    protected function getConfigInstructions()
    {
        $authLink = admin_url('?ff_amocrm_auth=true');
        ob_start(); ?>
        <div>
            <ol>
                <li>Enter your Account URL (e.g. <b>mycompany.amocrm.ru</b> or <b>mycompany.kommo.com</b>).</li>
                <li>Open Settings -> Integrations -> Then from upper right side click on the button Create Integration.</li>
                <li>Set the redirect URL as <b><?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $authLink; ?></b> then check the Allow access: All.</br>Set your Integration Name and give a short description and save the settings.
                </li>
                <li>Under private integrations find your integration. Click on the integration and go to Keys and scopes. Here you will find Secret key and Integration ID.</li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['client_id']) || empty($settings['client_secret']) || empty($settings['account_url'])) {
            $integrationSettings = [
                'account_url'   => '',
                'client_id'     => '',
                'client_secret' => '',
                'access_token'  => '',
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
            $oldSettings = $this->getGlobalSettings([]);
            $accountUrl = sanitize_text_field($settings['account_url']);
            if (strpos($accountUrl, '//') === false) {
                $accountUrl = 'https://' . $accountUrl;
            }
            $parsedHost = parse_url($accountUrl, PHP_URL_HOST);
            $accountUrl = $parsedHost ? strtolower($parsedHost) : rtrim(str_replace(['https://', 'http://'], '', $accountUrl), '/');
            $oldSettings['account_url'] = $accountUrl;
            $oldSettings['client_id'] = sanitize_text_field($settings['client_id']);
            $oldSettings['client_secret'] = sanitize_text_field($settings['client_secret']);
            $oldSettings['status'] = false;

            update_option($this->optionKey, $oldSettings, 'no');

            wp_send_json_success([
                'message'      => __('You are redirect to authenticate', 'fluentformpro'),
                'redirect_url' => admin_url('?ff_amocrm_auth=true')
            ], 200);
        }
        catch (\Exception $exception) {
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-amocrm-settings'),
            'configure_message'     => __('Amocrm is not configured yet! Please configure your Amocrm api first', 'fluentformpro'),
            'configure_button_text' => __('Set Amocrm API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        $listId = $this->app->request->get('serviceId');

        return [
            'name'         => '',
            'list_id'      => $listId,
            'fields' => [
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
                    'label'       => __('Amocrm Services', 'fluentformpro'),
                    'placeholder' => __('Select Amocrm Service', 'fluentformpro'),
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
        $lists = [
            'leads'             => 'Lead',
            'companies'         => 'Company',
            'contacts'          => 'Contact',
            'catalogs'          => 'List',
            'tasks'             => 'Task'
        ];

        $client = $this->getRemoteClient();
        $settings = $this->getGlobalSettings([]);

        $url = 'https://' . $settings['referer_url'] . '/api/v4/catalogs';

        try {
            $elements = $client->makeRequest($url, null);

            if (!$elements) {
                return $lists;
            }
        }
        catch (\Exception $exception) {
            return false;
        }

        if (is_wp_error($elements)) {
            $error = $elements->get_error_message();
            $code = $elements->get_error_code();
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($error, 'fluentformpro')
            ], $code);
        }

        foreach ($elements['_embedded']['catalogs'] as $catalog) {
            $lists['elements_' . $catalog['id']] = $catalog['name'];
        }

        return $lists;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
    }

    public function getFields($listId)
    {
        $settings = $this->getGlobalSettings([]);
        $mergedFields = [];

        switch ($listId) {
            case 'leads':
                $mergedFields =
                [
                    [
                        'key'         => 'leads_name',
                        'placeholder' => __('Enter Lead Name', 'fluentformpro'),
                        'label'       => __('Lead Name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Lead name is a required string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'price',
                        'placeholder' => __('Lead Sale', 'fluentformpro'),
                        'label'       => __('Lead Sale', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Lead Sale is a int type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'unix_created_at',
                        'placeholder' => __('Created at', 'fluentformpro'),
                        'label'       => __('Created at', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Created at is a Date type field in Unix Timestamp format.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ],
                    [
                        'key'         => 'unix_closed_at',
                        'placeholder' => __('Closed at', 'fluentformpro'),
                        'label'       => __('Closed at', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Closed at is a Date type field in Unix Timestamp format.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ],
                ];

                $url = 'https://' . $settings['referer_url'] . '/api/v4/leads/custom_fields';
                $customFields = $this->getCustomFields($url);
                $mergedFields = array_merge($mergedFields, $customFields);

                break;
            case 'companies':
                $mergedFields =
                [
                    [
                        'key'         => 'companies_name',
                        'placeholder' => __('Enter Company Name', 'fluentformpro'),
                        'label'       => __('Company Name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Company name is a required string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'unix_created_at',
                        'placeholder' => __('Created at', 'fluentformpro'),
                        'label'       => __('Created at', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Created at is a Date type field in Unix Timestamp format.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ]
                ];

                $url = 'https://' . $settings['referer_url'] . '/api/v4/companies/custom_fields';
                $customFields = $this->getCustomFields($url);
                $mergedFields = array_merge($mergedFields, $customFields);

                break;
            case 'contacts':
                $mergedFields =
                [
                    [
                        'key'         => 'contacts_name',
                        'placeholder' => __('Enter Contacts Name', 'fluentformpro'),
                        'label'       => __('Contacts Name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Contacts name is a required string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'first_name',
                        'placeholder' => __('Enter First Name', 'fluentformpro'),
                        'label'       => __('First Name', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('First name is a string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'last_name',
                        'placeholder' => __('Enter Last Name', 'fluentformpro'),
                        'label'       => __('Last Name', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Last name is a string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'unix_created_at',
                        'placeholder' => __('Created at', 'fluentformpro'),
                        'label'       => __('Created at', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Created at is a Date type field in Unix Timestamp format.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ]
                ];

                $url = 'https://' . $settings['referer_url'] . '/api/v4/contacts/custom_fields';
                $customFields = $this->getCustomFields($url);
                $mergedFields = array_merge($mergedFields, $customFields);

                break;
            case 'catalogs':
                $mergedFields =
                [
                    [
                        'key'         => 'catalogs_name',
                        'placeholder' => __('Enter List Name', 'fluentformpro'),
                        'label'       => __('List Name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('List name is a required string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'               => 'can_add_elements',
                        'label'             => __('Add elements to the list', 'fluentformpro'),
                        'required'          => false,
                        'tips'              => __('Add elements to the list is a bool type field.', 'fluentformpro'),
                        'component'         => 'radio_choice',
                        'options'           => [
                                'true' => __('yes', 'fluentformpro'),
                                'false' => __('no', 'fluentformpro')
                        ]
                    ],
                    [
                        'key'               => 'can_link_multiple',
                        'label'             => __('Link elements to multiple list', 'fluentformpro'),
                        'required'          => false,
                        'tips'              => __('Link elements can to the multiple list is a bool type field.', 'fluentformpro'),
                        'component'         => 'radio_choice',
                        'options'           => [
                                'true' => __('yes', 'fluentformpro'),
                                'false' => __('no', 'fluentformpro')
                        ]
                    ]
                ];
                break;
            case 'tasks':
                $mergedFields =
                [
                    [
                        'key'         => 'tasks_name',
                        'placeholder' => __('Enter Task Name', 'fluentformpro'),
                        'label'       => __('Task Name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Task name is a required string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'tasks_entity_type',
                        'placeholder' => __('Enter Task Entity Type', 'fluentformpro'),
                        'label'       => __('Task Entity Type', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Task Entity Type is a select type field.', 'fluentformpro'),
                        'component'   => 'select',
                        'options'     => [
                            'leads'     => 'Lead',
                            'contacts'  => 'Contact',
                            'companies' => 'Company',
                            'customers' => 'Customer'
                        ]
                    ],
                    [
                        'key'         => 'text',
                        'placeholder' => __('Enter Task Details', 'fluentformpro'),
                        'label'       => __('Task Details', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Task Details is a required string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'tasks_duration',
                        'placeholder' => __('Enter Task Duration', 'fluentformpro'),
                        'label'       => __('Task Duration', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Task Duration is a int type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'tasks_result[text]',
                        'placeholder' => __('Enter Task Result Details', 'fluentformpro'),
                        'label'       => __('Task Result Details', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Task Details is a string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'               => 'is_completed',
                        'label'             => __('Is Task Completed', 'fluentformpro'),
                        'required'          => false,
                        'tips'              => __('Is Task Completed is a bool type field.', 'fluentformpro'),
                        'component'         => 'radio_choice',
                        'options'           => [
                            'true' => __('yes', 'fluentformpro'),
                            'false' => __('no', 'fluentformpro')
                        ]
                    ],
                    [
                        'key'         => 'unix_complete_till',
                        'placeholder' => __('Complete At', 'fluentformpro'),
                        'label'       => __('Complete at', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Complete at is a Required Date type field in Unix Timestamp format.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ]
                ];
                break;
            case 'customers':
                $mergedFields =
                [
                    [
                        'key'         => 'customers_name',
                        'placeholder' => __('Enter Customer Name', 'fluentformpro'),
                        'label'       => __('Customer Name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Customer name is a required string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'next_price',
                        'placeholder' => __('Enter Expected purchase value', 'fluentformpro'),
                        'label'       => __('Enter Expected purchase value', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Expected purchase value is a required number type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                ];

                $url = 'https://' . $settings['referer_url'] . '/api/v4/customers/custom_fields';
                $customFields = $this->getCustomFields($url);
                $mergedFields = array_merge($mergedFields, $customFields);

                break;
            default:
                $mergedFields =
                [
                    [
                        'key'         => 'elements_name',
                        'placeholder' => __('Enter List element name' , 'fluentformpro'),
                        'label'       => __('List element name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('List element name is a required string type field.', 'fluentformpro'),
                        'component'   => 'value_text',
                    ]
                ];

                $listNumber = '';
                if (strpos($listId, 'elements') !== false) {
                    $listNumber = explode('_', $listId)[1];
                }

                $url = 'https://' . $settings['referer_url'] . '/api/v4/catalogs/' . $listNumber . '/custom_fields';
                $customFields = $this->getCustomFields($url);
                $mergedFields = array_merge($mergedFields, $customFields);
        }

        return $mergedFields;
    }

    protected function getCustomFields($url)
    {
        $client = $this->getRemoteClient();

        try {
            $lists = $client->makeRequest($url, null);
            if (!$lists) {
                return [];
            }
        }
        catch (\Exception $exception) {
            return false;
        }

        if (is_wp_error($lists)) {
            $error = $lists->get_error_message();
            $code = $lists->get_error_code();
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($error, 'fluentformpro')
            ], $code);
        }

        $customFields = [];

        foreach ($lists['_embedded']['custom_fields'] as $field) {
            if($enums = ArrayHelper::get($field, 'enums')) {
                $data = [
                    'key'         => 'custom*' . $field['id'] . '*select*' . $field['name'],
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'placeholder' => __($field['name'] . ' Type', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'label'       => __($field['name'] . ' Type', 'fluentformpro'),
                    'required'    => false,
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips'        => __($field['name'] . ' is a ' .$field['type']. ' type of field.', 'fluentformpro'),
                    'component'   => 'select'
                ];
                $options = [];
                foreach ($enums as $option) {
                    $options[$option['value']] = $option['value'];
                }
                $data['options'] = $options;

                array_push($customFields, $data);

                if($field['code'] == 'PHONE' || $field['code'] == 'EMAIL') {
                    $data = [
                        'key'         => 'custom*' . $field['id'] . '*text*' . $field['code'],
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'placeholder' => __($field['name'], 'fluentformpro'),
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'label'       => __($field['name'], 'fluentformpro'),
                        'required'    => false,
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                        'tips'        => __($field['name'] . ' is a string type of field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ];
                    array_push($customFields, $data);
                }
            }
            elseif ($field['type'] == 'checkbox') {
                $data = [
                    'key'               => 'custom*' . $field['id'] . '*checkbox*' . $field['name'],
                    'label'             => __('Is Discussable', 'fluentformpro'),
                    'required'          => false,
                    'tips'              => __('Is discussable is a bool type field.', 'fluentformpro'),
                    'component'         => 'radio_choice',
                    'options'           => [
                        'true' => __('yes', 'fluentformpro'),
                        'false' => __('no', 'fluentformpro')
                    ]
                ];
                array_push($customFields, $data);
            }
            else {
                $data = [
                    'key'         => 'custom*' . $field['id'] . '*normal*' . $field['name'],
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'placeholder' => __($field['name'], 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'label'       => __($field['name'], 'fluentformpro'),
                    'required'    => false,
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips'        => __($field['name'] . ' is a ' .$field['type']. ' type of field.', 'fluentformpro'),
                    'component'   => 'value_text'
                ];
                array_push($customFields, $data);
            }
        }

        return $customFields;
    }

    protected function getAllFields($listId)
    {
        $fields = $this->getFields($listId);

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
        $subscriber = [];

        $subscriber['list_id'] = $feedData['list_id'];

        if (strpos($feedData['list_id'], 'elements') !== false) {
            $listArray = explode('_', $feedData['list_id']);
            $subscriber['type'] = $listArray[0];
            $subscriber['list_id'] = $listArray[1];
        }

        if ($subscriber['list_id'] == 'tags') {
            $subscriber['entity_id'] = $feedData['entity_id'];
            unset($feedData['entity_id']);
        }

        $allFields = $this->getAllFields($feedData['list_id']);

        $subscriber['attributes']['custom_fields_values'] = [];
        $enumCode = '';

        foreach ($allFields as $field) {
            $key = $field['key'];

            if (!empty($feedData[$key])) {
                if (strpos($key, 'name') !== false) {
                    $subscriber['attributes']['name'] = ArrayHelper::get($feedData, $key);
                }
                elseif(strpos($key, 'can_') !== false) {
                    if ($feedData[$key] == 'true') {
                        $subscriber['attributes'][$key] = true;
                    }
                    else {
                        $subscriber['attributes'][$key] = false;
                    }
                }
                elseif(strpos($key, 'is_') !== false) {
                    if ($feedData[$key] == 'true') {
                        $subscriber['attributes'][$key] = true;
                    }
                    else {
                        $subscriber['attributes'][$key] = false;
                    }
                }
                elseif(strpos($key, 'unix_') !== false) {
                    $dateField = explode('unix_', $key);
                    $dateFieldKey = $dateField[1];
                    $subscriber['attributes'][$dateFieldKey] = strtotime(ArrayHelper::get($feedData, $key));
                }
                elseif (strpos($key, 'custom*') !== false) {
                    $customField = explode('*', $key);
                    $fieldId = $customField[1];
                    $fieldType = $customField[2];
                    $fieldName = $customField[3];

                    if($fieldType == 'select') {
                        if($fieldName == 'Unit') {
                            $customFields = [
                                'field_id'  => $fieldId,
                                'values'    => [
                                    [
                                        'value' => ArrayHelper::get($feedData, $key)
                                    ]
                                ]
                            ];
                        }
                        else {
                            $enumCode = ArrayHelper::get($feedData, $key);
                            continue;
                        }
                    }

                    if($fieldType == 'text') {
                        $customFields = [
                            'field_code' => $fieldName,
                            'values' => [
                                [
                                    'enum_code' => $enumCode,
                                    'value' => ArrayHelper::get($feedData, $key)
                                ]
                            ]
                        ];
                    }

                    if($fieldType == 'normal') {
                        $customFields = [
                            'field_id'  => $fieldId,
                            'values'    => [
                                [
                                    'value' => ArrayHelper::get($feedData, $key)
                                ]
                            ]
                        ];
                    }

                    if($fieldType == 'checkbox') {
                        if ($feedData[$key] == 'true') {
                            $checkboxValue = true;
                        }
                        else {
                            $checkboxValue = false;
                        }

                        $customFields = [
                            'field_id'  => $fieldId,
                            'values'    => [
                                [
                                    'value' => $checkboxValue
                                ]
                            ]
                        ];
                    }

                    array_push($subscriber['attributes']['custom_fields_values'], $customFields);
                }

                else {
                    $subscriber['attributes'][$key] = ArrayHelper::get($feedData, $key);
                }
            }
        }
        if (empty($subscriber['attributes']['custom_fields_values'])) {
            unset($subscriber['attributes']['custom_fields_values']);
        }

        $client = $this->getRemoteClient();
        $response = $client->subscribe($subscriber);

        if (!is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'success', __('Amocrm feed has been successfully initiated and pushed data', 'fluentformpro'));
        } else {
            $error = $response->get_error_message();
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }
}
