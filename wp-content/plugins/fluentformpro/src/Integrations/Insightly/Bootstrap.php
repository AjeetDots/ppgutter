<?php

namespace FluentFormPro\Integrations\Insightly;

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
            'Insightly',
            'insightly',
            '_fluentform_insightly_settings',
            'insightly_feed',
            36
        );

//        add_filter('fluentform/notifying_async_insightly', '__return_false');

        $this->logo = fluentFormMix('img/integrations/insightly.png');

        $this->description = 'With Insightly CRM, you can tailor the standard sales processes of contact, lead and opportunity management.';

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
                'errors' => $errors
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
            'url' => '',
            'api_key' => '',
            'status' => false,
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo' => $this->logo,
            'menu_title' => __('Insightly Settings', 'fluentformpro'),
            'menu_description' => $this->description,
            'valid_message' => __('Your Insightly Integration Key is valid', 'fluentformpro'),
            'invalid_message' => __('Your Insightly Integration Key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'config_instruction' => __($this->getConfigInstructions(), 'fluentformpro'),
            'fields' => [
                'url' => [
                    'type' => 'text',
                    'placeholder' => __('Insightly API URL', 'fluentformpro'),
                    'label_tips' => __('Enter your Insightly API URL', 'fluentformpro'),
                    'label' => __('Insightly API URL', 'fluentformpro'),
                ],
                'api_key' => [
                    'type' => 'password',
                    'placeholder' => __('Insightly API Key', 'fluentformpro'),
                    'label_tips' => __('Enter your Insightly API Key', 'fluentformpro'),
                    'label' => __('Insightly API Key', 'fluentformpro'),
                ]
            ],
            'hide_on_valid' => true,
            'discard_settings' => [
                'section_description' => __('Your Insightly API integration is up and running', 'fluentformpro'),
                'button_text' => __('Disconnect Insightly', 'fluentformpro'),
                'data' => [
                    'url' => '',
                    'api_key' => ''
                ],
                'show_verify' => true
            ]
        ];
    }

    protected function getConfigInstructions()
    {
        ob_start(); ?>
        <div>
            <ol>
                <li>Go <a href="https://crm.na1.insightly.com/users/usersettings" target="_blank">Here</a> and copy your
                    API URL with https:// and Secret Key after that Paste here.
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['url']) && empty($settings['api_key'])) {
            $integrationSettings = [
                'url' => '',
                'api_key' => '',
                'status' => false
            ];

            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_error([
                'message' => __('Please provide all fields to integrate', 'fluentformpro'),
                'status' => false
            ], 423);
        }

        try {
            $client = new API($settings);
            $result = $client->checkAuth();

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message(), $result->get_error_code());
            }
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage(),
                'status' => false
            ], $exception->getCode());
        }

        $integrationSettings = [
            'url' => $settings['url'],
            'api_key' => $settings['api_key'],
            'status' => true
        ];

        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your Insightly API key has been verified and successfully set', 'fluentformpro'),
            'status' => true
        ], 200);
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title' => $this->title . ' Integration',
            'logo' => $this->logo,
            'is_active' => $this->isConfigured(),
            'configure_title' => __('Configration required!', 'fluentformpro'),
            'global_configure_url' => admin_url('admin.php?page=fluent_forms_settings#general-insightly-settings'),
            'configure_message' => __('Insightly is not configured yet! Please configure your Insightly API first',
                'fluentformpro'),
            'configure_button_text' => __('Set Insightly API', 'fluentformpro')
        ];

        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        $listId = $this->app->request->get('serviceId');

        return [
            'name' => '',
            'list_id' => $listId,
            'fields' => [
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

    public function getSettingsFields($settings, $formId)
    {
        $fieldSettings = [
            'fields' => [
                [
                    'key' => 'name',
                    'label' => __('Feed Name', 'fluentformpro'),
                    'required' => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component' => 'text'
                ],
                [
                    'key' => 'list_id',
                    'label' => __('Insightly Services', 'fluentformpro'),
                    'placeholder' => __('Select Insightly Service', 'fluentformpro'),
                    'required' => true,
                    'component' => 'refresh',
                    'options' => $this->getLists()
                ],
            ],
            'button_require_list' => false,
            'integration_title' => $this->title
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
                'checkbox_label' => __('Enable this feed', 'fluentformpro')
            ]
        ]);

        return $fieldSettings;
    }

    protected function getLists()
    {
        return [
            'contacts' => 'Contact',
            'opportunities' => 'Opportunity',
            'leads' => 'Lead',
            'organisations' => 'Organisations',
            'projects' => 'Project',
            'tasks' => 'Task',
        ];
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
    }

    public function getFields($listId)
    {
        $mergedFields = [];
        switch ($listId) {
            case 'contacts':
                $mergedFields = [
                    [
                        'key' => 'EMAIL_ADDRESS',
                        'placeholder' => __('Enter Email', 'fluentformpro'),
                        'label' => __('Email', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Email is a email type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'SALUTATION',
                        'placeholder' => __('Enter Salutation', 'fluentformpro'),
                        'label' => __('Salutation', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Salutation is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'FIRST_NAME',
                        'placeholder' => __('Enter First Name', 'fluentformpro'),
                        'label' => __('First Name', 'fluentformpro'),
                        'required' => true,
                        'tips' => __('First Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'LAST_NAME',
                        'placeholder' => __('Enter Last Name', 'fluentformpro'),
                        'label' => __('Last Name', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Last Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ]
                ];
                $organisations = $this->getOrganisations();
                $mergedFields[] = array_merge($mergedFields, $organisations);
                $mergedFields = array_merge($mergedFields,
                    [
                        [
                            'key' => 'TITLE',
                            'placeholder' => __('Enter Organisation Title', 'fluentformpro'),
                            'label' => __('Organisation Title', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Organisation Title is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'SOCIAL_LINKEDIN',
                            'placeholder' => __('Enter LinkedIn URL', 'fluentformpro'),
                            'label' => __('LinkedIn URL', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('LinkedIn URL is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'SOCIAL_TWITTER',
                            'placeholder' => __('Enter Twitter URL', 'fluentformpro'),
                            'label' => __('Twitter URL', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Twitter URL is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'date*DATE_OF_BIRTH',
                            'placeholder' => __('Enter Date Of Birth', 'fluentformpro'),
                            'label' => __('Date Of Birth', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Date Of Birth is a date type field on y-m-d.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'PHONE',
                            'placeholder' => __('Enter Phone', 'fluentformpro'),
                            'label' => __('Phone', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Phone is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'PHONE_HOME',
                            'placeholder' => __('Enter Home Phone', 'fluentformpro'),
                            'label' => __('Home Phone', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Home Phone is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'PHONE_MOBILE',
                            'placeholder' => __('Enter Mobile Phone', 'fluentformpro'),
                            'label' => __('Mobile Phone', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Mobile Phone is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'PHONE_OTHER',
                            'placeholder' => __('Enter Other Phone', 'fluentformpro'),
                            'label' => __('Other Phone', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Other Phone is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'PHONE_FAX',
                            'placeholder' => __('Enter FAX Phone', 'fluentformpro'),
                            'label' => __('FAX Phone', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('FAX Phone is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ASSISTANT_NAME',
                            'placeholder' => __('Enter Assistant Name', 'fluentformpro'),
                            'label' => __('Assistant Name', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Assistant Name is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'PHONE_ASSISTANT',
                            'placeholder' => __('Enter Assistant Phone', 'fluentformpro'),
                            'label' => __('Assistant Phone', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Assistant Phone is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_MAIL_STREET',
                            'placeholder' => __('Enter Street Address', 'fluentformpro'),
                            'label' => __('Street Address', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Street Address is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_MAIL_CITY',
                            'placeholder' => __('Enter City', 'fluentformpro'),
                            'label' => __('City', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('City is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_MAIL_STATE',
                            'placeholder' => __('Enter State', 'fluentformpro'),
                            'label' => __('State', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('State is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_MAIL_POSTCODE',
                            'placeholder' => __('Enter Post Code', 'fluentformpro'),
                            'label' => __('Post Code', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Post Code is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_MAIL_COUNTRY',
                            'placeholder' => __('Enter Country', 'fluentformpro'),
                            'label' => __('Country', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Country is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_OTHER_STREET',
                            'placeholder' => __('Enter Other Street Address', 'fluentformpro'),
                            'label' => __('Other Street Address', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Other Street Address is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_OTHER_CITY',
                            'placeholder' => __('Enter Other City', 'fluentformpro'),
                            'label' => __('Other City', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Other City is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_OTHER_STATE',
                            'placeholder' => __('Enter Other State', 'fluentformpro'),
                            'label' => __('Other State', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Other State is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_OTHER_POSTCODE',
                            'placeholder' => __('Enter Other Post Code', 'fluentformpro'),
                            'label' => __('Other Post Code', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Other Post Code is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ],
                        [
                            'key' => 'ADDRESS_OTHER_COUNTRY',
                            'placeholder' => __('Enter Other Country', 'fluentformpro'),
                            'label' => __('Other Country', 'fluentformpro'),
                            'required' => false,
                            'tips' => __('Other Country is a string type field.', 'fluentformpro'),
                            'component' => 'value_text'
                        ]
                    ]);
                break;
            case 'opportunities':
                $mergedFields = [
                    [
                        'key' => 'OPPORTUNITY_NAME',
                        'placeholder' => __('Opportunity Name', 'fluentformpro'),
                        'label' => __('Opportunity Name', 'fluentformpro'),
                        'required' => true,
                        'tips' => __('Opportunity Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'OPPORTUNITY_DETAILS',
                        'placeholder' => __('Opportunity Details', 'fluentformpro'),
                        'label' => __('Opportunity Details', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Opportunity Details is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ]
                ];
                $organisations = $this->getOrganisations();
                $mergedFields[] = array_merge($mergedFields, $organisations);
                $categories = $this->getProjectCategories();
                $mergedFields[] = array_merge($mergedFields, $categories);
                $mergedFields = array_merge($mergedFields,
                [
                    [
                        'key' => 'PROBABILITY',
                        'placeholder' => __('Enter Probability Of Winning', 'fluentformpro'),
                        'label' => __('Probability Of Winning', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Probability Of Winning is a integer type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'date*FORECAST_CLOSE_DATE',
                        'placeholder' => __('Enter Forecast Close Date', 'fluentformpro'),
                        'label' => __('Forecast Close Date', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Forecast Close Date is a date type field on y-m-d.', 'fluentformpro'),
                        'component' => 'value_text',
                    ],
                    [
                        'key' => 'date*ACTUAL_CLOSE_DATE',
                        'placeholder' => __('Enter Actual Close Date', 'fluentformpro'),
                        'label' => __('Actual Close Date', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Actual Close Date is a date type field on y-m-d.', 'fluentformpro'),
                        'component' => 'value_text'
                    ]
                ]);
                $users = $this->getUsers('RESPONSIBLE_USER_ID');
                $mergedFields[] = array_merge($mergedFields, $users);
                $mergedFields = array_merge($mergedFields,
                [
                    [
                        'key' => 'BID_AMOUNT',
                        'placeholder' => __('Enter Bid Amount', 'fluentformpro'),
                        'label' => __('Bid Amount', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Bid Amount is a number type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'BID_TYPE',
                        'placeholder' => __('Enter Bid Type', 'fluentformpro'),
                        'label' => __('Bid Type', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Bid Amount is a number type field.', 'fluentformpro'),
                        'component' => 'select',
                        'options' => [
                            'Fixed Bid' => __('Fixed Bid', 'fluentformpro'),
                            'Per Hour' => __('Per Hour', 'fluentformpro'),
                            'Per Day' => __('Per Day', 'fluentformpro'),
                            'Per week' => __('Per Week', 'fluentformpro'),
                            'Per Month' => __('Per Month', 'fluentformpro'),
                            'Per Year' => __('Per Year', 'fluentformpro')
                        ]
                    ],
                ]);
                $pipelines = $this->getPipelines();
                $mergedFields[] = array_merge($mergedFields, $pipelines);
                $pipelineStages = $this->getPipelineStages();
                $mergedFields[] = array_merge($mergedFields, $pipelineStages);
                break;
            case 'leads':
                $mergedFields = [
                    [
                        'key' => 'SALUTATION',
                        'placeholder' => __('Enter Salutation', 'fluentformpro'),
                        'label' => __('Salutation', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Salutation is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'FIRST_NAME',
                        'placeholder' => __('Enter First Name', 'fluentformpro'),
                        'label' => __('First Name', 'fluentformpro'),
                        'required' => true,
                        'tips' => __('First Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'LAST_NAME',
                        'placeholder' => __('Enter Last Name', 'fluentformpro'),
                        'label' => __('Last Name', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Last Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                ];
                $leadStatuses = $this->getLeadStatuses();
                $leadSources = $this->getLeadSources();
                $users = $this->getUsers('RESPONSIBLE_USER_ID');
                $mergedFields[] = array_merge($mergedFields, $leadStatuses);
                $mergedFields[] = array_merge($mergedFields, $leadSources);
                $mergedFields[] = array_merge($mergedFields, $users);
                $mergedFields = array_merge($mergedFields, [
                    [
                        'key' => 'TITLE',
                        'placeholder' => __('Enter Title', 'fluentformpro'),
                        'label' => __('Title', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Title is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ORGANISATION_NAME',
                        'placeholder' => __('Enter Organisation Name', 'fluentformpro'),
                        'label' => __('Organisation Name', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Organisation Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'EMAIL',
                        'placeholder' => __('Enter Email', 'fluentformpro'),
                        'label' => __('Email', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Email is a email type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'FAX',
                        'placeholder' => __('Enter Fax', 'fluentformpro'),
                        'label' => __('Fax', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Fax is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'INDUSTRY',
                        'placeholder' => __('Enter Industry', 'fluentformpro'),
                        'label' => __('Industry', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Industry is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'LEAD_DESCRIPTION',
                        'placeholder' => __('Enter Lead Description', 'fluentformpro'),
                        'label' => __('Lead Description', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Lead Description is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'LEAD_RATING',
                        'placeholder' => __('Enter Lead Rating', 'fluentformpro'),
                        'label' => __('Lead Rating', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Lead Rating is a number type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'MOBILE',
                        'placeholder' => __('Enter Mobile', 'fluentformpro'),
                        'label' => __('Mobile', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Mobile is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'PHONE',
                        'placeholder' => __('Enter Phone', 'fluentformpro'),
                        'label' => __('Phone', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Phone is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'WEBSITE',
                        'placeholder' => __('Enter Website', 'fluentformpro'),
                        'label' => __('Website', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Website is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'EMPLOYEE_COUNT',
                        'placeholder' => __('Enter Number Of Employees', 'fluentformpro'),
                        'label' => __('Number Of Employees', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Number Of Employees is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_STREET',
                        'placeholder' => __('Enter Street Address', 'fluentformpro'),
                        'label' => __('Street Address', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Street Address is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_CITY',
                        'placeholder' => __('Enter City', 'fluentformpro'),
                        'label' => __('City', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('City is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_STATE',
                        'placeholder' => __('Enter State', 'fluentformpro'),
                        'label' => __('State', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('State is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_POSTCODE',
                        'placeholder' => __('Enter Post Code', 'fluentformpro'),
                        'label' => __('Post Code', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Post Code is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_COUNTRY',
                        'placeholder' => __('Enter Country', 'fluentformpro'),
                        'label' => __('Country', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Country is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ]
                ]);
                break;
            case 'organisations':
                $mergedFields = [
                    [
                        'key' => 'ORGANISATION_NAME',
                        'placeholder' => __('Enter Organisation Name', 'fluentformpro'),
                        'label' => __('Organisation Name', 'fluentformpro'),
                        'required' => true,
                        'tips' => __('Organisation Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'BACKGROUND',
                        'placeholder' => __('Enter Background', 'fluentformpro'),
                        'label' => __('Background', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Background is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'PHONE',
                        'placeholder' => __('Enter Phone', 'fluentformpro'),
                        'label' => __('Phone', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Phone is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'PHONE_FAX',
                        'placeholder' => __('Enter FAX', 'fluentformpro'),
                        'label' => __('FAX', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('FAX is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'WEBSITE',
                        'placeholder' => __('Enter Website', 'fluentformpro'),
                        'label' => __('Website', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Website is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_BILLING_STREET',
                        'placeholder' => __('Enter Billing Address Street', 'fluentformpro'),
                        'label' => __('Billing Address Street', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Billing Address Street is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_BILLING_CITY',
                        'placeholder' => __('Enter Billing Address City', 'fluentformpro'),
                        'label' => __('Billing Address City', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Billing Address City is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_BILLING_STATE',
                        'placeholder' => __('Enter Billing Address State', 'fluentformpro'),
                        'label' => __('Billing Address State', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Billing Address State is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_BILLING_COUNTRY',
                        'placeholder' => __('Enter Billing Address Country', 'fluentformpro'),
                        'label' => __('Billing Address Country', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Billing Address Country is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_BILLING_POSTCODE',
                        'placeholder' => __('Enter Billing Address Postcode', 'fluentformpro'),
                        'label' => __('Billing Address Postcode', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Billing Address Postcode is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_SHIP_STREET',
                        'placeholder' => __('Enter Shipping Address Street', 'fluentformpro'),
                        'label' => __('Shipping Address Street', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Shipping Address Street is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_SHIP_CITY',
                        'placeholder' => __('Enter Shipping Address City', 'fluentformpro'),
                        'label' => __('Shipping Address City', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Shipping Address City is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_SHIP_STATE',
                        'placeholder' => __('Enter Shipping Address State', 'fluentformpro'),
                        'label' => __('Shipping Address State', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Shipping Address State is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_SHIP_POSTCODE',
                        'placeholder' => __('Enter Shipping Address Postcode', 'fluentformpro'),
                        'label' => __('Shipping Address Postcode', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Shipping Address Postcode is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'ADDRESS_SHIP_COUNTRY',
                        'placeholder' => __('Enter Shipping Address Country', 'fluentformpro'),
                        'label' => __('Shipping Address Country', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Shipping Address Country is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'SOCIAL_LINKEDIN',
                        'placeholder' => __('Enter LinkedIn', 'fluentformpro'),
                        'label' => __('LinkedIn', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('LinkedIn is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'SOCIAL_FACEBOOK',
                        'placeholder' => __('Enter Facebook', 'fluentformpro'),
                        'label' => __('Facebook', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Facebook is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'SOCIAL_TWITTER',
                        'placeholder' => __('Enter Twitter', 'fluentformpro'),
                        'label' => __('Twitter', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Twitter is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                ];
                break;
            case 'projects':
                $mergedFields = [
                    [
                        'key' => 'PROJECT_NAME',
                        'placeholder' => __('Enter Project Name', 'fluentformpro'),
                        'label' => __('Project Name', 'fluentformpro'),
                        'required' => true,
                        'tips' => __('Project Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'PROJECT_DETAILS',
                        'placeholder' => __('Enter Project Details', 'fluentformpro'),
                        'label' => __('Project Details', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Project Details is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'STATUS',
                        'placeholder' => __('Select Project Status', 'fluentformpro'),
                        'label' => __('Project Status', 'fluentformpro'),
                        'required' => true,
                        'tips' => __('Project Status is a string type field.', 'fluentformpro'),
                        'component' => 'select',
                        'options' => [
                            'IN PROGRESS' => 'In Progress',
                            'DEFERRED' => 'Deferred',
                            'CANCELLED' => 'Cancelled',
                            'ABANDONED' => 'Abandoned',
                            'COMPLETED' => 'Completed'
                        ]
                    ]
                ];
                $opportunities = $this->getOpportunities();
                $projectCategories = $this->getProjectCategories();
                $pipelines = $this->getPipelines();
                $pipelineStages = $this->getPipelineStages();
                $users = $this->getUsers('RESPONSIBLE_USER_ID');
                $mergedFields[] = array_merge($mergedFields, $opportunities);
                $mergedFields[] = array_merge($mergedFields, $projectCategories);
                $mergedFields[] = array_merge($mergedFields, $pipelines);
                $mergedFields[] = array_merge($mergedFields, $pipelineStages);
                $mergedFields[] = array_merge($mergedFields, $users);
                break;
            case 'tasks':
                $mergedFields = [
                    [
                        'key' => 'TITLE',
                        'placeholder' => __('Enter Task Name', 'fluentformpro'),
                        'label' => __('Task Name', 'fluentformpro'),
                        'required' => true,
                        'tips' => __('Task Name is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                ];
                $taskCategories = $this->getTaskCategories();
                $mergedFields[] = array_merge($mergedFields, $taskCategories);
                $responsibleUser = $this->getUsers('RESPONSIBLE_USER_ID');
                $mergedFields[] = array_merge($mergedFields, $responsibleUser);
                $mergedFields = array_merge($mergedFields, [
                    [
                        'key' => 'date*DUE_DATE',
                        'placeholder' => __('Due Date', 'fluentformpro'),
                        'label' => __('Due date', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Due date is a date type field on y-m-d.', 'fluentformpro'),
                        'component' => 'value_text',
                    ],
                    [
                        'key' => 'date*START_DATE',
                        'placeholder' => __('Start Date', 'fluentformpro'),
                        'label' => __('Start date', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Start date is a date type field on y-m-d.', 'fluentformpro'),
                        'component' => 'value_text',
                    ],
                    [
                        'key' => 'date*REMINDER_DATE_UTC',
                        'placeholder' => __('Remainder Due Date', 'fluentformpro'),
                        'label' => __('Remainder Due date', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Remainder Due date is a date type field on y-m-d.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'PERCENT_COMPLETE',
                        'placeholder' => __('Progress in Percentage', 'fluentformpro'),
                        'label' => __('Progress', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Progress is a number type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                    [
                        'key' => 'PRIORITY',
                        'placeholder' => __('Priority', 'fluentformpro'),
                        'label' => __('Priority', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Priority is a select type field.', 'fluentformpro'),
                        'component' => 'select',
                        'options' => [
                            '0' => __('Low', 'fluentformpro'),
                            '1' => __('Medium', 'fluentformpro'),
                            '2' => __('High', 'fluentformpro')
                        ]
                    ],
                    [
                        'key' => 'STATUS',
                        'placeholder' => __('Status', 'fluentformpro'),
                        'label' => __('Status', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Status is a select type field.', 'fluentformpro'),
                        'component' => 'select',
                        'options' => [
                            'IN PROGRESS' => __('In Progress', 'fluentformpro'),
                            'COMPLETED' => __('Completed', 'fluentformpro'),
                            'DEFERRED' => __('Deferred', 'fluentformpro'),
                            'WAITING' => __('Waiting', 'fluentformpro')
                        ]
                    ],
                    [
                        'key' => 'DETAILS',
                        'placeholder' => __('Description', 'fluentformpro'),
                        'label' => __('Description', 'fluentformpro'),
                        'required' => false,
                        'tips' => __('Description is a string type field.', 'fluentformpro'),
                        'component' => 'value_text'
                    ],
                ]);
                $opportunities = $this->getOpportunities();
                $projects = $this->getProjects();
                $mergedFields[] = array_merge($mergedFields, $opportunities);
                $mergedFields[] = array_merge($mergedFields, $projects);
                $mergedFields = array_merge($mergedFields, [

                ]);
                break;
            default:
                $mergedFields = [];
        }

        $customFields = $this->getCustomFields($listId);
        return array_merge($mergedFields, $customFields);
    }

    protected function getCustomFields($listId)
    {
        $client = $this->getRemoteClient();

        $customFields = $client->makeRequest($client->url . '/v3.1/customfields/' . $listId, null);

        if (is_wp_error($customFields)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($customFields->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $customFormattedFields = [];

        foreach ($customFields as $fieldValue) {
            $customFormattedFields[] = [
                'key' => 'custom*' . $fieldValue['FIELD_NAME'],
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'placeholder' => __('Enter ' . $fieldValue['FIELD_LABEL'], 'fluentformpro'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'label' => __($fieldValue['FIELD_LABEL'], 'fluentformpro'),
                'required' => false,
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'tips' => __($fieldValue['FIELD_LABEL'] . ' is a ' . $fieldValue['FIELD_TYPE'] . ' type field.',
                    'fluentformpro'),
                'component' => 'value_text'
            ];
        }

        return $customFormattedFields;
    }

    protected function getLeadStatuses()
    {
        $client = $this->getRemoteClient();

        $leadStatuses = $client->makeRequest($client->url . '/v3.1/leadstatuses', null);

        if (is_wp_error($leadStatuses)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($leadStatuses->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'LEAD_STATUS_ID',
            'placeholder' => __('Select Lead Status', 'fluentformpro'),
            'label' => __('Lead Status', 'fluentformpro'),
            'required' => true,
            'tips' => __('Lead Status is a required select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($leadStatuses as $option) {
            $options[$option['LEAD_STATUS_ID']] = $option['LEAD_STATUS'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getLeadSources()
    {
        $client = $this->getRemoteClient();

        $leadSources = $client->makeRequest($client->url . '/v3.1/leadsources', null);

        if (is_wp_error($leadSources)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($leadSources->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'LEAD_SOURCE_ID',
            'placeholder' => __('Select Lead Source', 'fluentformpro'),
            'label' => __('Lead Source', 'fluentformpro'),
            'required' => true,
            'tips' => __('Lead Source is a required select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($leadSources as $option) {
            $options[$option['LEAD_SOURCE_ID']] = $option['LEAD_SOURCE'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getOpportunities()
    {
        $client = $this->getRemoteClient();

        $opportunities = $client->makeRequest($client->url . '/v3.1/opportunities', null);

        if (is_wp_error($opportunities)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($opportunities->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'OPPORTUNITY_ID',
            'placeholder' => __('Select Opportunity Category', 'fluentformpro'),
            'label' => __('Opportunity Category', 'fluentformpro'),
            'required' => false,
            'tips' => __('Opportunity Category is a select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($opportunities as $option) {
            $options[$option['OPPORTUNITY_ID']] = $option['OPPORTUNITY_NAME'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getProjects()
    {
        $client = $this->getRemoteClient();

        $projects = $client->makeRequest($client->url . '/v3.1/projects', null);

        if (is_wp_error($projects)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($projects->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'PROJECT_ID',
            'placeholder' => __('Select Project', 'fluentformpro'),
            'label' => __('Project', 'fluentformpro'),
            'required' => false,
            'tips' => __('Project is a select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($projects as $option) {
            $options[$option['PROJECT_ID']] = $option['PROJECT_NAME'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getOpportunityCategories()
    {
        $client = $this->getRemoteClient();

        $opportunityCategories = $client->makeRequest($client->url . '/v3.1/opportunitycategories', null);

        if (is_wp_error($opportunityCategories)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($opportunityCategories->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'CATEGORY_ID',
            'placeholder' => __('Select Category', 'fluentformpro'),
            'label' => __('Category', 'fluentformpro'),
            'required' => false,
            'tips' => __('Category is a select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($opportunityCategories as $option) {
            $options[$option['CATEGORY_ID']] = $option['CATEGORY_NAME'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getUsers($key)
    {
        $client = $this->getRemoteClient();

        $users = $client->makeRequest($client->url . '/v3.1/users', null);

        if (is_wp_error($users)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($users->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        if ($key == 'RESPONSIBLE_USER_ID') {
            $data = [
                'key' => $key,
                'placeholder' => __('Select Responsible User', 'fluentformpro'),
                'label' => __('Responsible User', 'fluentformpro'),
                'required' => false,
                'tips' => __('Responsible User is a select type field.', 'fluentformpro'),
                'component' => 'select'
            ];
        }

        else {
            $data = [
                'key' => $key,
                'placeholder' => __('Select Owner User', 'fluentformpro'),
                'label' => __('Owner User', 'fluentformpro'),
                'required' => false,
                'tips' => __('Owner User is a select type field.', 'fluentformpro'),
                'component' => 'select'
            ];
        }

        $options = [];
        foreach ($users as $option) {
            $options[$option['USER_ID']] = $option['FIRST_NAME'] . ' ' . $option['LAST_NAME'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getPipelines()
    {
        $client = $this->getRemoteClient();

        $pipelines = $client->makeRequest($client->url . '/v3.1/pipelines', null);

        if (is_wp_error($pipelines)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($pipelines->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'PIPELINE_ID',
            'placeholder' => __('Select Pipeline', 'fluentformpro'),
            'label' => __('Pipeline', 'fluentformpro'),
            'required' => false,
            'tips' => __('Pipeline is a select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($pipelines as $option) {
            $options[$option['PIPELINE_ID']] = $option['PIPELINE_NAME'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getPipelineStages()
    {
        $client = $this->getRemoteClient();

        $pipelineStages = $client->makeRequest($client->url . '/v3.1/pipelinestages', null);

        if (is_wp_error($pipelineStages)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($pipelineStages->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'STAGE_ID',
            'placeholder' => __('Select Pipeline Stage', 'fluentformpro'),
            'label' => __('Pipeline Stage', 'fluentformpro'),
            'required' => false,
            'tips' => __('Pipeline Stage is a select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($pipelineStages as $option) {
            $options[$option['STAGE_ID']] = $option['STAGE_NAME'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getProjectCategories()
    {
        $client = $this->getRemoteClient();

        $projectCategories = $client->makeRequest($client->url . '/v3.1/projectcategories', null);

        if (is_wp_error($projectCategories)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($projectCategories->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'CATEGORY_ID',
            'placeholder' => __('Select Project Category', 'fluentformpro'),
            'label' => __('Project Category', 'fluentformpro'),
            'required' => false,
            'tips' => __('Project Category is a select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($projectCategories as $option) {
            $options[$option['CATEGORY_ID']] = $option['CATEGORY_NAME'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getOrganisations()
    {
        $client = $this->getRemoteClient();

        $organisations = $client->makeRequest($client->url . '/v3.1/organisations', null);

        if (is_wp_error($organisations)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($organisations->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'ORGANISATION_ID',
            'placeholder' => __('Select Organisation', 'fluentformpro'),
            'label' => __('Organisation', 'fluentformpro'),
            'required' => false,
            'tips' => __('Organisation is a select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($organisations as $option) {
            $options[$option['ORGANISATION_ID']] = $option['ORGANISATION_NAME'];
        }
        $data['options'] = $options;

        return $data;
    }

    protected function getTaskCategories()
    {
        $client = $this->getRemoteClient();

        $taskCategories = $client->makeRequest($client->url . '/v3.1/taskcategories', null);

        if (is_wp_error($taskCategories)) {
            wp_send_json_error([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'message' => __($taskCategories->get_error_message(), 'fluentformpro'),
            ], 423);
        }

        $data = [
            'key' => 'CATEGORY_ID',
            'placeholder' => __('Select Task Category', 'fluentformpro'),
            'label' => __('Task Category', 'fluentformpro'),
            'required' => false,
            'tips' => __('Task Category is a select type field.', 'fluentformpro'),
            'component' => 'select'
        ];

        $options = [];
        foreach ($taskCategories as $option) {
            $options[$option['CATEGORY_ID']] = $option['CATEGORY_NAME'];
        }
        $data['options'] = $options;

        return $data;
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
        $subscriber['attributes'] = [];

        $subscriber['list_id'] = $feedData['list_id'];

        $allFields = $this->getAllFields($feedData['list_id']);

        foreach ($allFields as $field) {
            $key = $field['key'];

            if (!empty($feedData[$key])) {
                if(strpos($key, '*') != false) {
                    $fieldArray = explode('*', $key, 2);
                    $fieldType = $fieldArray[0];
                    $fieldName = $fieldArray[1];

                    if($fieldType == 'date') {
                        $timeStamp = strtotime($feedData[$key]);
                        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
                        $date = date('Y-m-d H:i:s', $timeStamp);
                        $subscriber['attributes'][$fieldName] = $date;
                    }

                    if($fieldType == 'custom') {
                        $subscriber['attributes']['CUSTOMFIELDS'][] = [
                            'FIELD_NAME' => $fieldName,
                            'FIELD_VALUE' => $feedData[$key]
                        ];
                    }
                }
                else {
                    $subscriber['attributes'][$key] = ArrayHelper::get($feedData, $key);
                }
            }
        }

        $client = $this->getRemoteClient();
        $response = $client->subscribe($subscriber);

        if (!is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'success',
                __('Insightly feed has been successfully initiated and pushed', 'fluentformpro') . $feedData['list_id'] . ' data');
        } else {
            $message = $response->get_error_message();
            if (!$message) {
                $errorCode = $response->get_error_code();
                if ($errorCode == 400) {
                    $message = __("Data validation failed", 'fluentformpro');
                } elseif ($errorCode == 401) {
                    $message = __("Authentication failed", 'fluentformpro');
                } elseif ($errorCode == 402) {
                    $message = __("Record limit reached", 'fluentformpro');
                }
            }
            do_action('fluentform/integration_action_result', $feed, 'failed', $message);
        }
    }
}
