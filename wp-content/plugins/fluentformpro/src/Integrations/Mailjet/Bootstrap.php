<?php

namespace FluentFormPro\Integrations\Mailjet;

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
            'Mailjet',
            'mailjet',
            '_fluentform_mailjet_settings',
            'mailjet_feed',
            36
        );

//        add_filter('fluentform/notifying_async_mailjet', '__return_false');

        $this->logo = fluentFormMix('img/integrations/mailjet.png');

        $this->description = 'Mailjet is an easy-to-use all-in-one e-mail platform.';

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
            'access_token'  => '',
            'status'        => false,
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('Mailjet Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_description'   => __($this->description, 'fluentformpro'),
            'valid_message'      => __('Your Mailjet Integration Key is valid', 'fluentformpro'),
            'invalid_message'    => __('Your Mailjet Integration Key is not valid', 'fluentformpro'),
            'save_button_text'   => __('Save Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'config_instruction' => __($this->getConfigInstructions(), 'fluentformpro'),
            'fields'             => [
                'api_key' => [
                    'type'        => 'text',
                    'placeholder' => __('Mailjet API Key', 'fluentformpro'),
                    'label_tips'  => __('Enter your Mailjet API Key', 'fluentformpro'),
                    'label'       => __('Mailjet API Key', 'fluentformpro'),
                ],
                'secret_key' => [
                    'type'        => 'password',
                    'placeholder' => __('Mailjet Secret Key', 'fluentformpro'),
                    'label_tips'  => __('Enter your Mailjet Secret Key', 'fluentformpro'),
                    'label'       => __('Mailjet Secret Key', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your Mailjet API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Mailjet', 'fluentformpro'),
                'data'                => [
                    'api_key'     => '',
                    'secret_key' => '',
                    'access_token'  => '',
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
                <li>Go <a href="https://app.mailjet.com/account/apikeys" target="_blank">Here</a> and copy your API Key and Secret Key.
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['api_key']) || empty($settings['secret_key'])) {
            $integrationSettings = [
                'api_key'     => '',
                'secret_key' => '',
                'access_token'  => '',
                'status'        => false
            ];

            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_error([
                'message' => __('Please provide all fields to integrate', 'fluentformpro'),
                'status'  => false
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
            'api_key'  => $settings['api_key'],
            'secret_key'  => $settings['secret_key'],
            'status'   => true
        ];

        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your Mailjet API key has been verified and successfully set', 'fluentformpro'),
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-mailjet-settings'),
            'configure_message'     => __('Mailjet is not configured yet! Please configure your Mailjet API first', 'fluentformpro'),
            'configure_button_text' => __('Set Mailjet API', 'fluentformpro')
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
                    'label'       => __('Mailjet Services', 'fluentformpro'),
                    'placeholder' => __('Select Mailjet Service', 'fluentformpro'),
                    'required'    => true,
                    'component'   => 'refresh',
                    'options'     => $this->getLists()
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
            'contact'       => 'Contact',
            'template'      => 'Template',
            'send'          => 'Send Email'
        ];
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
            case 'contact':
                $mergedFields =
                    [
                        [
                            'key'       => 'contact_bool_IsExcludedFromCampaigns',
                            'label'     => __('Exclude the contact', 'fluentformpro'),
                            'required'  => false,
                            'tips'      => __('Whether the contact is added to the exclusion list for campaigns or not', 'fluentformpro'),
                            'component' => 'radio_choice',
                            'options'   => [
                                'true' => __('Yes', 'fluentformpro'),
                                'false' => __('No', 'fluentformpro')
                            ]
                        ],
                        [
                            'key'         => 'contact_string_Email',
                            'placeholder' => __('Enter Contact Email', 'fluentformpro'),
                            'label'       => __('Contact Email', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Contact Email is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'contact_string_Name',
                            'placeholder' => __('Enter Contact Name', 'fluentformpro'),
                            'label'       => __('Contact Name', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Contact Name is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                    ];
                break;
            case 'template':
                $mergedFields =
                    [
                        [
                            'key'         => 'template_string_Name',
                            'placeholder' => __('Enter Template Name', 'fluentformpro'),
                            'label'       => __('Template Name', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Template name is a required string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'template_string_Author',
                            'placeholder' => __('Enter Author Name', 'fluentformpro'),
                            'label'       => __('Author Name', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Author Name is a required string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'template_multiselect_Categories',
                            'placeholder' => __('Select Categories', 'fluentformpro'),
                            'label'       => __('Select Categories', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Select a Template Category', 'fluentformpro'),
                            'component'   => 'select',
                            'is_multiple' => true,
                            'options'     => [
                                    'full'       => 'Full',
                                    'basic'      => 'Basic',
                                    'newsletter' => 'Newsletter',
                                    'e-commerce' => 'E-commerce',
                                    'events'     => 'Events',
                                    'travel'     => 'Travel',
                                    'sports'     => 'Sports',
                                    'welcome'    => 'Welcome',
                                    'contact-property-update' => 'Contact Property Update',
                                    'support'    => 'Support',
                                    'invoice'    => 'Invoice',
                                    'anniversary' => 'Anniversary',
                                    'account'     => 'Account',
                                    'activation'  => 'Activation'
                                ]
                        ],
                        [
                            'key'         => 'template_string_Copyright',
                            'placeholder' => __('Enter Copyright Message', 'fluentformpro'),
                            'label'       => __('Copyright Message', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Copyright Message is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'template_string_Description',
                            'placeholder' => __('Enter Template Description', 'fluentformpro'),
                            'label'       => __('Company Message', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Company Message is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'template_bool_IsStarred',
                            'label'       => __('Starred Campaign', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Whether the campaign is marked as Starred or not.', 'fluentformpro'),
                            'component'   => 'radio_choice',
                            'options'     => [
                                'true' => __('Yes', 'fluentformpro'),
                                'false' => __('No', 'fluentformpro')
                            ]
                        ],
                        [
                            'key'         => 'template_multiselect_Purposes',
                            'placeholder' => __('Select Purpose', 'fluentformpro'),
                            'label'       => __('Select Purpose', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Select a Purpose', 'fluentformpro'),
                            'component'   => 'select',
                            'is_multiple' => true,
                            'options'     => [
                                'marketing'     => __('Marketing', 'fluentformpro'),
                                'transactional' => __('Transactional', 'fluentformpro'),
                                'automation'    => __('Automation', 'fluentformpro')
                            ],
                        ]
                    ];
                    break;
            case 'send':
                $mergedFields =
                    [
                        [
                            'key'         => 'send_string_FromEmail',
                            'placeholder' => __('Enter Sender Email', 'fluentformpro'),
                            'label'       => __('Sender Email', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Sender Email is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'send_string_FromName',
                            'placeholder' => __('Enter Sender Name', 'fluentformpro'),
                            'label'       => __('Sender Name', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Sender Name is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'send_string_To',
                            'placeholder' => __('Enter Recipients Email', 'fluentformpro'),
                            'label'       => __('Recipients Email', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Recipients Email is a required string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'send_string_Cc',
                            'placeholder' => __('Enter CC Email', 'fluentformpro'),
                            'label'       => __('CC Email', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('CC Email is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'send_string_Bcc',
                            'placeholder' => __('Enter BCC Email', 'fluentformpro'),
                            'label'       => __('BCC Email', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('BCC Email is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'send_string_Subject',
                            'placeholder' => __('Enter Email Subject', 'fluentformpro'),
                            'label'       => __('Email Subject', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Email Subject is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'send_string_Text-part',
                            'placeholder' => __('Enter Email Text Part', 'fluentformpro'),
                            'label'       => __('Email Text Part', 'fluentformpro'),
                            'required'    => true,
                            'tips'        => __('Email Text Part is a required string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'send_string_Html-part',
                            'placeholder' => __('Enter Email Html Part', 'fluentformpro'),
                            'label'       => __('Email Html Part', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Email Html Part is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                        [
                            'key'         => 'send_string_Mj-campaign',
                            'placeholder' => __('Enter Campaign Name', 'fluentformpro'),
                            'label'       => __('Campaign Name', 'fluentformpro'),
                            'required'    => false,
                            'tips'        => __('Campaign Name is a string type field.', 'fluentformpro'),
                            'component'   => 'value_text'
                        ],
                    ];
                break;
            default:
                $mergedFields =
                    [
                        []
                    ];
        }

        return $mergedFields;
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
                $fieldArray = explode('_', $key, 3);
                $fieldService = $fieldArray[0];
                $fieldType = $fieldArray[1];
                $fieldName = $fieldArray[2];

                if ($fieldType == 'bool') {
                    if (ArrayHelper::get($feedData, $key) == 'true') {
                        $subscriber['attributes'][$fieldName] = true;
                    } else {
                        $subscriber['attributes'][$fieldName] = false;
                    }
                } else {
                    $subscriber['attributes'][$fieldName] = ArrayHelper::get($feedData, $key);
                }

                if ($fieldService == 'send') {
                    $subscriber['attributes']['Mj-deduplicatecampaign'] = false;
                }
            }
        }

        $client = $this->getRemoteClient();
        $response = $client->subscribe($subscriber);

        if (!is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'success', 'Mailjet feed has been successfully initiated and pushed data');
        } else {
            $error = $response->get_error_message();
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }
}
