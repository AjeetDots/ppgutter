<?php

namespace FluentFormPro\Integrations\OnePageCrm;

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
            'OnePageCrm',
            'onepagecrm',
            '_fluentform_onepagecrm_settings',
            'onepagecrm_feed',
            36
        );

        $this->logo = fluentFormMix('img/integrations/onepagecrm.png');

        $this->description = "Complete your actions with the combination of Fluent Forms and OnePageCRM to collect leads and more.";

        $this->registerAdminHooks();

//         add_filter('fluentform/notifying_async_onepagecrm', '__return_false');

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

    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('OnePageCrm Settings', 'fluentformpro'),
            'menu_description'   => $this->description,
            'valid_message'      => __('Your OnePageCrm API Key is valid', 'fluentformpro'),
            'invalid_message'    => __('Your OnePageCrm API Key is not valid', 'fluentformpro'),
            'save_button_text'   => __('Save Settings', 'fluentformpro'),
            'config_instruction' => $this->getConfigInstructions(),
            'fields'             => [
                'client_id'     => [
                    'type'        => 'text',
                    'placeholder' => __('OnePageCrm User ID', 'fluentformpro'),
                    'label_tips'  => __('Enter your OnePageCrm User ID', 'fluentformpro'),
                    'label'       => __('OnePageCrm User ID', 'fluentformpro'),
                ],
                'client_secret' => [
                    'type'        => 'password',
                    'placeholder' => __('OnePageCrm Api Key', 'fluentformpro'),
                    'label_tips'  => __('Enter your OnePageCrm Api Key', 'fluentformpro'),
                    'label'       => __('OnePageCrm Api Key', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'      => true,
            'discard_settings'   => [
                'section_description' => __('Your OnePageCrm API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect OnePageCrm', 'fluentformpro'),
                'data'                => [
                    'client_id'     => '',
                    'client_secret' => '',
                    'access_token'  => '',
                ],
                'show_verify'         => true
            ]
        ];
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
            'client_id'     => '',
            'client_secret' => '',
            'access_token'  => '',
            'status'        => false,
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    protected function getConfigInstructions()
    {
        ob_start(); ?>
        <div>
            <ol>
                <li>After logging into your OnePageCRM account and navigating to this <a
                            href="https://app.onepagecrm.com/app/api" target="_blank">Link</a> and select the
                    configuration tab.
                </li>
                <li>Copy the User ID and API Key to use for authentication.</li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function saveGlobalSettings($settings)
    {
        if (!$settings['client_id'] || !$settings['client_secret']) {
            $integrationSettings = [
                'client_id'     => '',
                'client_secret' => '',
                'access_token'  => '',
                'status'        => false
            ];

            // Update the reCaptcha details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');
            wp_send_json_success([
                'message' => __('Your settings has been updated', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        //  Verify API key now
        try {
            $client = new API($settings);
            $result = $client->checkAuth();

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message(), $result->get_error_code());
            }

            $accessToken = $client->getAccessToken();
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage(),
                'status'  => false
            ], $exception->getCode());
        }

        $integrationSettings = [
            'client_id'     => sanitize_text_field($settings['client_id']),
            'client_secret' => sanitize_text_field($settings['client_secret']),
            'access_token'  => $accessToken['access_token'],
            'status'        => true
        ];

        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your OnePageCRM api key has been verified and successfully set', 'fluentformpro'),
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-onepagecrm-settings'),
            'configure_message'     => __('OnePageCRM is not configured yet! Please configure your OnePageCRM api first',
                'fluentformpro'),
            'configure_button_text' => __('Set OnePageCRM API', 'fluentformpro')
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
                    'label'       => __('OnePageCRM Services', 'fluentformpro'),
                    'placeholder' => __('Select OnePageCRM Service', 'fluentformpro'),
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

    protected function getLists()
    {
        return [
            'contacts'         => 'Contact',
            'deals'            => 'Deal',
            'predefined_items' => 'Predefined Item',
            'notes'            => 'Note',
            'actions'          => 'Action'
        ];
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
    }

    public function getFields($listId)
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $url = 'https://app.onepagecrm.com/api/v3/';
        $mergedFields = [];

        switch ($listId) {
            case 'contacts':
                $mergedFields =
                    [
                        [
                            'key'         => 'title',
                            'placeholder' => __('Enter Title', 'fluentformpro'),
                            'label'       => __('Title', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Title is a select type field.', 'fluentformpro'),
                            'component'   => 'select',
                            'options'     => [
                                'Mr'  => __('Mr', 'fluentformpro'),
                                'Mrs' => __('Mrs', 'fluentformpro'),
                                'Ms'  => __('Ms', 'fluentformpro')
                            ]
                        ],
                        [
                            'key'         => 'first_name',
                            'placeholder' => __('Enter First Name', 'fluentformpro'),
                            'label'       => __('First Name', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('First Name is a required string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'last_name',
                            'placeholder' => __('Enter Last Name', 'fluentformpro'),
                            'label'       => __('Last Name', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Last Name is a required string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'job_title',
                            'placeholder' => __('Enter Job Title', 'fluentformpro'),
                            'label'       => __('Job Title', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Job Title is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'       => 'starred',
                            'label'     => __('Starred', 'fluentformpro'),
                            'required'  => false,
                            'tips'      => __('Starred is a bool type field.', 'fluentformpro'),
                            'component' => 'radio_choice',
                            'options'   => [
                                'true'  => __('yes', 'fluentformpro'),
                                'false' => __('no', 'fluentformpro')
                            ]
                        ],
                        [
                            'key'         => 'company_name',
                            'placeholder' => __('Enter Company Name', 'fluentformpro'),
                            'label'       => __('Company Name', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Company Name is a  string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'urls_type',
                            'placeholder' => __('Select URL Type', 'fluentformpro'),
                            'label'       => __('Select URL Type', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('URL is a select type field.', 'fluentformpro'),
                            'component'   => 'select',
                            'options'     => [
                                'website'     => __('Website', 'fluentformpro'),
                                'blog'        => __('Blog', 'fluentformpro'),
                                'twitter'     => __('Twitter', 'fluentformpro'),
                                'linkedin'    => __('Linkedin', 'fluentformpro'),
                                'xing'        => __('Xing', 'fluentformpro'),
                                'facebook'    => __('Facebook', 'fluentformpro'),
                                'google_plus' => __('Google Plus', 'fluentformpro'),
                                'other'       => __('Other', 'fluentformpro')
                            ]
                        ],
                        [
                            'key'         => 'urls_value',
                            'placeholder' => __('Enter URL', 'fluentformpro'),
                            'label'       => __('Enter URL', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('URL is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'phones_type',
                            'placeholder' => __('Select Phone Type', 'fluentformpro'),
                            'label'       => __('Select Phone Type', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Phone is a select type field.', 'fluentformpro'),
                            'component'   => 'select',
                            'options'     => [
                                'work'    => __('Work', 'fluentformpro'),
                                'mobile'  => __('Mobile', 'fluentformpro'),
                                'home'    => __('Home', 'fluentformpro'),
                                'direct'  => __('Direct', 'fluentformpro'),
                                'fax'     => __('Fax', 'fluentformpro'),
                                'skype'   => __('Skype', 'fluentformpro'),
                                'company' => __('Company', 'fluentformpro'),
                                'other'   => __('Other', 'fluentformpro')
                            ]
                        ],
                        [
                            'key'         => 'phones_value',
                            'placeholder' => __('Enter Phone', 'fluentformpro'),
                            'label'       => __('Enter Phone', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Phone is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'emails_type',
                            'placeholder' => __('Select Email', 'fluentformpro'),
                            'label'       => __('Select Email', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Email is a select type field.', 'fluentformpro'),
                            'component'   => 'select',
                            'options'     => [
                                'work'  => __('Work', 'fluentformpro'),
                                'home'  => __('Home', 'fluentformpro'),
                                'other' => __('Other', 'fluentformpro')
                            ]
                        ],
                        [
                            'key'         => 'emails_value',
                            'placeholder' => __('Enter Email', 'fluentformpro'),
                            'label'       => __('Enter Email', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Email is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'address_address',
                            'placeholder' => __('Enter Address', 'fluentformpro'),
                            'label'       => __('Enter Address', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Address is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'address_city',
                            'placeholder' => __('Enter City', 'fluentformpro'),
                            'label'       => __('Enter City', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('City is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'address_state',
                            'placeholder' => __('Enter State', 'fluentformpro'),
                            'label'       => __('Enter State', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('State is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'address_zip_code',
                            'placeholder' => __('Enter Zip Code', 'fluentformpro'),
                            'label'       => __('Enter Zip Code', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Zip Code is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'address_country_code',
                            'placeholder' => __('Enter Country Code', 'fluentformpro'),
                            'label'       => __('Enter Country Code', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Country Code is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'address_type',
                            'placeholder' => __('Enter Address Type', 'fluentformpro'),
                            'label'       => __('Enter Address Type', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Address Type is a string type field.', 'fluentformpro'),
                            'component'   => 'select',
                            'options'     => [
                                'work'     => __('Work', 'fluentformpro'),
                                'home'     => __('Home', 'fluentformpro'),
                                'billing'  => __('Billing', 'fluentformpro'),
                                'delivery' => __('Delivery', 'fluentformpro'),
                                'other'    => __('Other', 'fluentformpro')
                            ]
                        ],
                        [
                            'key'         => 'contactKey_status_id',
                            'placeholder' => __('Select Status', 'fluentformpro'),
                            'label'       => __('Select Status', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Status is a select type field.', 'fluentformpro'),
                            'component'   => 'select',
                            'options'     => $this->getAllStatuses()
                        ],
                        [
                            'key'         => 'lead_source_id',
                            'placeholder' => __('Select Lead Source', 'fluentformpro'),
                            'label'       => __('Select Lead Source', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Lead Source is a select type field.', 'fluentformpro'),
                            'component'   => 'select',
                            'options'     => $this->getAllLeadSources()
                        ],
                        [
                            'key'         => 'background',
                            'placeholder' => __('Write Background', 'fluentformpro'),
                            'label'       => __('Write Background', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Background is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ]
                    ];

                $url = $url . '/custom_fields?per_page=100';
                $customFields = $this->getCustomFields($url);
                $mergedFields = array_merge($mergedFields, $customFields);

                break;

            case 'deals':
                $mergedFields = [
                    [
                        'key'         => 'dealKey_name',
                        'placeholder' => __('Enter Deal Name', 'fluentformpro'),
                        'label'       => __('Deal Name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Deal Name is a string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'text',
                        'placeholder' => __('Enter Deal Details', 'fluentformpro'),
                        'label'       => __('Deal Details', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Deal details is a string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'stage',
                        'placeholder' => __('Enter Stage', 'fluentformpro'),
                        'label'       => __('Stage Number', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Stage Number is a int type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'expected_close_date',
                        'placeholder' => __('Enter Expected Close Date', 'fluentformpro'),
                        'label'       => __('Expected Close Date', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Expected Close Date is a date type field.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ],
                    [
                        'key'         => 'close_date',
                        'placeholder' => __('Enter Close Date', 'fluentformpro'),
                        'label'       => __('Close Date', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Close Date is a date type field.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ],
                    [
                        'key'         => 'date',
                        'placeholder' => __('Enter Creation Date', 'fluentformpro'),
                        'label'       => __('Creation Date', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('creation Date is a date type field.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ],
                    [
                        'key'         => 'dealKey_status',
                        'placeholder' => __('Select status of the deal', 'fluentformpro'),
                        'label'       => __('Status', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Status is a select type field.', 'fluentformpro'),
                        'component'   => 'select',
                        'options'     => [
                            'pending' => __('Pending', 'fluentformpro'),
                            'won'     => __('Won', 'fluentformpro'),
                            'lost'    => __('Lost', 'fluentformpro')
                        ]
                    ],
                ];

                $userFields = $this->getUsers();
                $mergedFields[] = $userFields;

                break;

            case 'predefined_items':
                $mergedFields = [
                    [
                        'key'         => 'itemKey_name',
                        'placeholder' => __('Enter Item Name', 'fluentformpro'),
                        'label'       => __('Item Name', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Item Name is a string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'description',
                        'placeholder' => __('Enter Item Description', 'fluentformpro'),
                        'label'       => __('Item Description', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Item Description is a string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'price',
                        'placeholder' => __('Enter Item Price', 'fluentformpro'),
                        'label'       => __('Item Price', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Item Price is a int type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ]
                ];

                break;

            case 'notes':
                $mergedFields = [
                    [
                        'key'         => 'text',
                        'placeholder' => __('Enter Note Details', 'fluentformpro'),
                        'label'       => __('Note Details', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Item Name is a string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'Date',
                        'placeholder' => __('Enter Notes Date', 'fluentformpro'),
                        'label'       => __('Date', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Date is a date type field.', 'fluentformpro'),
                        'component'   => 'datetime'
                    ]
                ];

                $userFields = $this->getUsers();
                $mergedFields[] = $userFields;
                break;
            case 'actions':
                $mergedFields = [
                    [
                        'key'         => 'actionKey_status',
                        'placeholder' => __('Select Action Status', 'fluentformpro'),
                        'label'       => __('Action Status', 'fluentformpro'),
                        'required'    => true,
                        'tips'        => __('Action Status is a select type field.', 'fluentformpro'),
                        'component'   => 'select',
                        'options'     => [
                            'asap'             => 'ASAP',
                            'date'             => 'Date',
                            'date_time'        => 'Date Time',
                            'waiting'          => 'Waiting',
                            'queued'           => 'Queued',
                            'queued_with_date' => 'Queued With Date',
                            'done'             => 'Done'
                        ]
                    ],
                    [
                        'key'         => 'text',
                        'placeholder' => __('Enter Action Details', 'fluentformpro'),
                        'label'       => __('Action Details', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Action Details is string type field.', 'fluentformpro'),
                        'component'   => 'value_text'
                    ],
                    [
                        'key'         => 'date',
                        'placeholder' => __('Enter Action Date', 'fluentformpro'),
                        'label'       => __('Action Date', 'fluentformpro'),
                        'required'    => false,
                        'tips'        => __('Action Date is date type field. Due date for the action (status must be one of Date, Date Time or Queued with date)',
                            'fluentformpro'),
                        'component'   => 'datetime'
                    ]
                ];

                $userFields = $this->getUsers();
                $mergedFields[] = $userFields;
                break;
        }

        return $mergedFields;
    }

    protected function getUsers()
    {
        $client = $this->getRemoteClient();

        $url = 'https://app.onepagecrm.com/api/v3/contacts';

        try {
            $lists = $client->makeRequest($url, null);
            if (!$lists) {
                return [];
            }
        } catch (\Exception $exception) {
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

        $data = [
            'key'         => 'contact_id',
            'placeholder' => __('Select the User', 'fluentformpro'),
            'label'       => __('Contact Name', 'fluentformpro'),
            'required'    => true,
            'tips'        => __('Select the User', 'fluentformpro'),
            'component'   => 'select',
        ];
        $data['options'] = [];

        foreach ($lists['data']['contacts'] as $contact) {
            $data['options'] += [$contact['contact']['id'] => $contact['contact']['first_name'] . ' ' . $contact['contact']['last_name']];
        }

        return $data;
    }

    protected function getAllStatuses()
    {
        $client = $this->getRemoteClient();

        $url = 'https://app.onepagecrm.com/api/v3/statuses';

        try {
            $lists = $client->makeRequest($url, null);
            if (!$lists) {
                return [];
            }
        } catch (\Exception $exception) {
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

        $lists = ArrayHelper::get($lists, 'data');
        if (!$lists) {
            return [];
        }

        $formattedStatuses = [];
        foreach ($lists as $status) {
            $id = ArrayHelper::get($status, 'status.id');
            $name = ArrayHelper::get($status, 'status.text');
            $formattedStatuses[$id] = $name;
        }

        return $formattedStatuses;
    }

    protected function getAllLeadSources()
    {
        $client = $this->getRemoteClient();

        $url = 'https://app.onepagecrm.com/api/v3/lead_sources';

        try {
            $lists = $client->makeRequest($url, null);
            if (!$lists) {
                return [];
            }
        } catch (\Exception $exception) {
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

        $lists = ArrayHelper::get($lists, 'data');
        if (!$lists) {
            return [];
        }

        $formattedLeadSources = [];
        foreach ($lists as $leadSource) {
            $id = ArrayHelper::get($leadSource, 'id');
            $name = ArrayHelper::get($leadSource, 'text');
            $formattedLeadSources[$id] = $name;
        }

        return $formattedLeadSources;
    }

    protected function getCustomFields($url)
    {
        $client = $this->getRemoteClient();

        try {
            $lists = $client->makeRequest($url, null);
            if (!$lists) {
                return [];
            }
        } catch (\Exception $exception) {
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

        foreach ($lists['data']['custom_fields'] as $customField) {
            $field = $customField['custom_field'];

            if ($field['type'] == 'multiple_choice' || $field['type'] == 'select_box') {
                $data = [
                    'key'         => 'custom_' . $field['id'] . '_multiple_choice_' . $field['name'],
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'placeholder' => __($field['name'], 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'label'       => __($field['name'], 'fluentformpro'),
                    'required'    => false,
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips'        => __($field['name'] . ' is a ' . $field['type'] . ' type of field.',
                        'fluentformpro'),
                    'component'   => 'select'
                ];

                $choices = ArrayHelper::get($field, 'choices');
                $options = [];
                foreach ($choices as $choice) {
                    $options[$choice] = $choice;
                }
                $data['options'] = $options;
            } elseif ($field['type'] == 'anniversary' || $field['type'] == 'date') {
                $data = [
                    'key'       => 'custom_' . $field['id'] . '_date_' . $field['name'],
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'label'     => __($field['name'], 'fluentformpro'),
                    'required'  => false,
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips'      => __($field['name'] . ' is a ' . $field['type'] . ' type of field.', 'fluentformpro'),
                    'component' => 'datetime',
                ];
            } else {
                $data = [
                    'key'         => 'custom_' . $field['id'] . '_normal_' . $field['name'],
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'placeholder' => __($field['name'], 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'label'       => __($field['name'], 'fluentformpro'),
                    'required'    => false,
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips'        => __($field['name'] . ' is a ' . $field['type'] . ' type of field.',
                        'fluentformpro'),
                    'component'   => 'value_text'
                ];
            }
            $customFields[] = $data;
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
                $allFields[] = $keyData;
            } else {
                $keyData['required'] = 0;
                $allFields[] = $keyData;
            }
        }

        return $allFields;
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $subscriber = [];
        $subscriber['list_id'] = $feedData['list_id'];
        $subscriber['attributes'] = [];
        $fieldContainer = [];
        $fieldFormatted['address_list'] = [];
        $fieldFormatted['custom_fields'] = [];
        $customFieldContainer = [];
        $normalTemp = [];
        $customTemp = [];
        $addressTemp = [];

        $allFields = $this->getAllFields($feedData['list_id']);

        foreach ($allFields as $field) {
            if (!empty($feedData[$field['key']])) {
                $fieldContainer[$field['key']] = ArrayHelper::get($feedData, $field['key']);
            }
        }

        foreach ($fieldContainer as $key => $value) {
            if (strpos($key, 'urls') !== false ||
                strpos($key, 'phones') !== false ||
                strpos($key, 'emails') !== false ||
                strpos($key, 'address') !== false) {
                $arr = explode('_', $key, 2);
                $keyName = $arr[0];
                $fieldName = $arr[1];

                if ($keyName == 'address') {
                    $addressTemp[$fieldName] = $value;
                } else {
                    if ($arr[1] == 'type') {
                        $normalTemp[$keyName]['type'] = $value;
                    }

                    if ($arr[1] == 'value') {
                        $normalTemp[$keyName]['value'] = $value;
                    }
                }
                unset($fieldContainer[$key]);
            }

            if (strpos($key, 'date') !== false && strpos($key, 'custom') == false) {
                // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
                $value = date('Y-m-d', strtotime($value));
                $fieldFormatted[$key] = $value;
                unset($fieldContainer[$key]);
            }

            if (strpos($key, 'custom') !== false) {
                $arr = explode('_', $key);
                $fieldId = $arr[1];
                $fieldType = $arr[2];

                if ($fieldType == 'date') {
                    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
                    $value = date('Y-m-d', strtotime($value));
                }

                $customTemp['custom_field']['id'] = $fieldId;
                $customTemp['value'] = $value;
                $customFieldContainer[] = $customTemp;

                unset($fieldContainer[$key]);
            }

            if (strpos($key, 'contactKey') !== false || strpos($key, 'dealKey') !== false || strpos($key,
                    'itemKey') !== false) {
                $arr = explode('_', $key, 2);
                $keyName = $arr[1];
                $fieldFormatted[$keyName] = $value;
                unset($fieldContainer[$key]);
            }
        }

        $fieldFormatted += $normalTemp;

        if (empty($fieldFormatted['address_list'])) {
            unset($fieldFormatted['address_list']);
        } else {
            $fieldFormatted['address_list'][] = $addressTemp;
        }

        if (!empty($customFieldContainer)) {
            $fieldFormatted['custom_fields'] += $customFieldContainer;
        }

        $fieldContainer += $fieldFormatted;
        $subscriber['attributes'] = $fieldContainer;
        $client = $this->getRemoteClient();
        $response = $client->subscribe($subscriber);

        if (!is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'success',
                __('OnePageCRM feed has been successfully initiated and pushed data', 'fluentformpro'));
        } else {
            $errorMessage = $response->get_error_message();

            $errors = $response->get_error_data();
            if ($errors) {
                if (is_array($errors)) {
                    $stringifyErrorMessages = implode('', $errors);
                    $errors = $stringifyErrorMessages;
                }
                $errorMessage = $errorMessage . '. ' . $errors;
            }

            do_action('fluentform/integration_action_result', $feed, 'failed', $errorMessage);
        }
    }
}
