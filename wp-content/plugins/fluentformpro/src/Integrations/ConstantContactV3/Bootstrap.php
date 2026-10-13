<?php

namespace FluentFormPro\Integrations\ConstantContactV3;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Constant Contact V3',
            'constatantcontactv3',
            '_fluentform_constantcontactv3_settings',
            'constantcontactv3_feed',
            27
        );

        $this->logo = fluentFormMix('img/integrations/constantcontact.png');

        $this->description = 'Connect Constant Contact (v3 API) with Fluent Forms and create subscriptions forms right into WordPress and grow your list.';

        $this->registerAdminHooks();

        add_filter('fluentform/save_integration_value_' . $this->integrationKey, [$this, 'validate'], 10, 3);

        add_action('admin_init', function() {
            $hasConstantContactAuthCode = isset($_REQUEST['ff_constant_contact_auth']) && isset($_REQUEST['code']);

            if ($hasConstantContactAuthCode) {
                if (!current_user_can('fluentform_settings_manager')) {
                    return;
                }
                // SECURITY (PRO-17): reject callbacks not bound to the initiating admin session.
                $state = isset($_REQUEST['state']) ? sanitize_text_field($_REQUEST['state']) : '';
                if (!wp_verify_nonce($state, 'ff_constant_contact_oauth')) {
                    wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-constantcontact-settings'));
                    exit();
                }
                // Get the access token now
                $code = sanitize_text_field($_REQUEST['code']);
                $settings = $this->getGlobalSettings([]);

                $client = $this->getRemoteClient();
                $token = $client->generateAccessToken($code, $settings);

                if (!is_wp_error($token)) {
                    $token['status'] = true;
                    update_option($this->optionKey, $token, 'no');
                }

                wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-constatantcontactv3-settings'));
                exit();
            }
        });

//         add_filter('fluentform/notifying_async_' . $this->integrationKey, '__return_false');
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-constatantcontactv3-settings'),
            'configure_message'     => __('Constant Contact V3 is not configured yet! Please configure your Constant Contact v3 api first',
                'fluentformpro'),
            'configure_button_text' => __('Set Constant Contact V3 API', 'fluentformpro')
        ];

        return $integrations;
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('Constant Contact V3 API Settings', 'fluentformpro'),
            'menu_description'   => __('Constant Contact is an integrated email marketing, marketing automation, and small business CRM. Save time while growing your business with sales automation. Use Fluent Forms to collect customer information and automatically add it to your Constant Contact list. If you don\'t have a Constant Contact account, you can <a href="https://www.constantcontact.com/" rel="noopener" target="_blank">sign up for one here.</a>',
                'fluentformpro'),
            'valid_message'      => __('Your Constant Contact configuration is valid', 'fluentformpro'),
            'invalid_message'    => __('Your Constant Contact configuration is invalid', 'fluentformpro'),
            'save_button_text'   => __('Verify Constant Contact', 'fluentformpro'),
            'config_instruction' => $this->getConfigInstructions(),
            'fields'             => [
                'client_id'     => [
                    'type'        => 'text',
                    'placeholder' => __('Enter Client ID', 'fluentformpro'),
                    'label_tips'  => __("Enter your Constant Contact Client ID", 'fluentformpro'),
                    'label'       => __('Constant Contact Client ID', 'fluentformpro'),
                ],
                'client_secret' => [
                    'type'        => 'password',
                    'placeholder' => __('Enter Client Secret', 'fluentformpro'),
                    'label_tips'  => __("Enter your Constant Contact Client Secret Key", 'fluentformpro'),
                    'label'       => __('Constant Contact Client Secret', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'      => true,
            'discard_settings'   => [
                'section_description' => __('Your Constant Contact V3 API integration is up and running',
                    'fluentformpro'),
                'button_text'         => __('Disconnect Constant Contact', 'fluentformpro'),
                'data'                => [
                    'client_id'     => '',
                    'client_secret' => '',
                    'status'        => false,
                    'access_token'  => '',
                    'refresh_token' => '',
                    'expires_at'    => ''
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

    protected function getConfigInstructions()
    {
        ob_start();
        ?>
        <div>
            <h4>Constant Contact V3 API</h4>
            <ol>
                <li>
                    Go to <a href="https://app.constantcontact.com/pages/dma/portal/" target="_blank">link</a> and click <b>New Application</b> button to add your app to connect.
                </li>
                <li>
                    Enter your desired name -> Application OAuth2 Settings choose <b>Authorization Code Flow and
                        Implicit Flow</b> -> Choose your refresh token type as <b>Rotating Refresh Tokens</b> then click <b>Create</b> button
                </li>
                <li>
                    Click <b>Edit</b> button on newly created Application -> Copy the <b>API Key (Client Id)</b> and paste here in Fluent Form.
                </li>
                <li>
                    Click on <b>Generate Client Secret</b> button -> Copy the Client Secret and paste here in Fluent Form.
                </li>
                <li>
                    Set your Redirect Url as <b><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo admin_url('?ff_constant_contact_auth=true'); ?></b>
                </li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function validate($settings, $integrationId, $formId)
    {
        if (empty($settings['email_address'])) {
            wp_send_json_error([
                'message' => __('Email is required for Constant Contact.', 'fluentformpro'),
                'errors'  => []
            ], 422);
        }
        return $settings;
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
            'access_token'  => '',
            'refresh_token' => '',
            'expires_at'    => '',
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            $integrationSettings = [
                'client_id'     => '',
                'client_secret' => '',
                'status'        => false,
                'access_token'  => '',
                'refresh_token' => '',
                'expires_at'    => '',
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
            $oldSettings['client_id'] = sanitize_text_field($settings['client_id']);
            $oldSettings['client_secret'] = sanitize_text_field($settings['client_secret']);
            $oldSettings['status'] = false;

            update_option($this->optionKey, $oldSettings, 'no');

            wp_send_json_success([
                'message'      => __('You are redirecting Constant Contact to authenticate', 'fluentformpro'),
                'redirect_url' => $this->getRemoteClient()->getRedirectServerURL()
            ], 200);
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'                      => '',
            'list_id'                   => '',
            'tag_ids'                   => [],
            'contact_fields'            => (object)[],
            'email_address'             => '',
            'permission_to_send'        => '',
            'first_name'                => '',
            'last_name'                 => '',
            'job_title'                 => '',
            'company_name'              => '',
            'birthday_month'            => '',
            'birthday_day'              => '',
            'anniversary'               => '',
            'home_phone'                => '',
            'work_phone'                => '',
            'mobile_phone'              => '',
            'home_address_street'       => '',
            'home_address_city'         => '',
            'home_address_state'        => '',
            'home_address_postal_code'  => '',
            'home_address_country'      => '',
            'work_address_street'       => '',
            'work_address_city'         => '',
            'work_address_state'        => '',
            'work_address_postal_code'  => '',
            'work_address_country'      => '',
            'other_address_street'      => '',
            'other_address_city'        => '',
            'other_address_state'       => '',
            'other_address_postal_code' => '',
            'other_address_country'     => '',
            'custom_fields'     => [
                [
                    'item_value' => '',
                    'label'      => ''
                ]
            ],
            'conditionals'              => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'update_if_exist'           => false,
            'enabled'                   => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields'              => [
                [
                    'key'         => 'name',
                    'label'       => __('Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Title', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'         => 'list_id',
                    'label'       => __('Constant Contact List', 'fluentformpro'),
                    'placeholder' => __('Select Constant Contact Mailing List', 'fluentformpro'),
                    'tips'        => __('Select the Constant Contact Mailing List you would like to add your contacts to.',
                        'fluentformpro'),
                    'component'   => 'list_ajax_options',
                    'options'     => $this->getLists(),
                ],
                [
                    'key'         => 'tag_ids',
                    'label'       => __('Constant Contact Tags', 'fluentformpro'),
                    'placeholder' => __('Select Constant Contact Tag', 'fluentformpro'),
                    'tips'        => __('Select the Constant Contact Tag you would like to add your contacts to.', 'fluentformpro'),
                    'component'   => 'select',
                    'is_multiple' => true,
                    'options'     => $this->getTags(),
                ],
                [
                    'key'                => 'contact_fields',
                    'require_list'       => true,
                    'label'              => __('Map Fields', 'fluentformpro'),
                    'tips'               => __('Select which Fluent Forms fields pair with their respective Constant Contact fields.', 'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('Constant Contact Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
                    'primary_fileds'     => [
                        [
                            'key'           => 'email_address',
                            'label'         => __('Email Address', 'fluentformpro'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ],
                        [
                            'key'   => 'permission_to_send',
                            'label' => __('Type of Permission', 'fluentformpro'),
                            'tips'  => __('Identifies the type of permission that the Constant Contact account has been granted to send email to the contact.', 'fluentformpro'),
                            'input_options' => 'select',
                            'placeholder' => __('Select Permission Type', 'fluentformpro'),
                            'options' => [
                                'explicit'             => __('Explicit', 'fluentformpro'),
                                'implicit'             => __('Implicit', 'fluentformpro'),
                                'not_set'              => __('Not Set', 'fluentformpro'),
                                'pending_confirmation' => __('Pending Confirmation', 'fluentformpro'),
                                'temp_hold'            => __('Temporary Hold', 'fluentformpro'),
                                'unsubscribed'         => __('Unsubscribed', 'fluentformpro'),
                            ]
                        ],
                        [
                            'key'   => 'first_name',
                            'label' => __('First Name', 'fluentformpro'),
                        ],
                        [
                            'key'   => 'last_name',
                            'label' => __('Last Name', 'fluentformpro'),
                        ],
                        [
                            'key'   => 'job_title',
                            'label' => __('Job Title', 'fluentformpro')
                        ],
                        [
                            'key'   => 'company_name',
                            'label' => __('Company Name', 'fluentformpro')
                        ],
                        [
                            'key'   => 'birthday_month',
                            'label' => __('Birthday Month', 'fluentformpro'),
                            'tips' => __('The month value for the contact\'s birthday. Valid values are from 1 through 12.', 'fluentformpro'),
                        ],
                        [
                            'key'   => 'birthday_day',
                            'label' => __('Birth Day', 'fluentformpro'),
                            'tips' => __('The day value for the contact\'s birthday. Valid values are from 1 through 31.', 'fluentformpro'),
                        ],
                        [
                            'key'   => 'anniversary',
                            'label' => __('Anniversary', 'fluentformpro'),
                            'tips' => __('this value could be the date when the contact first became a customer of an organization in Constant Contact. Valid date formats are MM/DD/YYYY, DD/MM/YYYY, YYYY-MM-DD.', 'fluentformpro'),
                        ],
                        [
                            'key'   => 'home_phone',
                            'label' => __('Home Phone Number', 'fluentformpro')
                        ],
                        [
                            'key'   => 'work_phone',
                            'label' => __('Work Phone Number', 'fluentformpro')
                        ],
                        [
                            'key'   => 'mobile_phone',
                            'label' => __('Mobile Phone Number', 'fluentformpro')
                        ],
                        [
                            'key'   => 'home_address_street',
                            'label' => __('Home Street Address', 'fluentformpro')
                        ],
                        [
                            'key'   => 'home_address_city',
                            'label' => __('Home City Address', 'fluentformpro')
                        ],
                        [
                            'key'   => 'home_address_state',
                            'label' => __('Home State', 'fluentformpro')
                        ],
                        [
                            'key'   => 'home_address_postal_code',
                            'label' => __('Home Postal Code', 'fluentformpro')
                        ],
                        [
                            'key'   => 'home_address_country',
                            'label' => __('Home Country', 'fluentformpro')
                        ],
                        [
                            'key'   => 'work_address_street',
                            'label' => __('Work Street Address', 'fluentformpro')
                        ],
                        [
                            'key'   => 'work_address_city',
                            'label' => __('Work City Address', 'fluentformpro')
                        ],
                        [
                            'key'   => 'work_address_state',
                            'label' => __('Work State', 'fluentformpro')
                        ],
                        [
                            'key'   => 'work_address_postal_code',
                            'label' => __('Work Postal Code', 'fluentformpro')
                        ],
                        [
                            'key'   => 'work_address_country',
                            'label' => __('Work Country', 'fluentformpro')
                        ],
                        [
                            'key'   => 'other_address_street',
                            'label' => __('Other Street Address', 'fluentformpro')
                        ],
                        [
                            'key'   => 'other_address_city',
                            'label' => __('Other City Address', 'fluentformpro')
                        ],
                        [
                            'key'   => 'other_address_state',
                            'label' => __('Other State', 'fluentformpro')
                        ],
                        [
                            'key'   => 'other_address_postal_code',
                            'label' => __('Other Postal Code', 'fluentformpro')
                        ],
                        [
                            'key'   => 'other_address_country',
                            'label' => __('Other Country', 'fluentformpro')
                        ]
                    ]
                ],
                [
                    'key'          => 'custom_fields',
                    'require_list' => false,
                    'label'        => __('Custom Fields', 'fluentformpro'),
                    'remote_text'  => __('Constant Contact Custom Field', 'fluentformpro'),
                    'tips'         => __('Select which Fluent Forms fields pair with their respective Constant Contact fields. Custom Date Fields supports only MM/DD/YYYY format', 'fluentformpro'),
                    'component'    => 'dropdown_many_fields',
                    'local_text'   => 'Form Field',
                    'options'      => $this->getCustomFields()
                ],
                [
                    'require_list' => true,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow Constant Contact integration conditionally based on your submission values', 'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list'   => true,
                    'key'            => 'update_if_exist',
                    'label'          => __('Update Contact', 'fluentformpro'),
                    'component'      => 'checkbox-single',
                    'tips'           => __('Checking this will also support multiple list subscription', 'fluentformpro'),
                    'checkbox_label' => __('Update the contact if exists', 'fluentformpro')
                ],
                [
                    'require_list'   => true,
                    'key'            => 'enabled',
                    'label'          => __('Status', 'fluentformpro'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro')
                ]
            ],
            'button_require_list' => true,
            'integration_title'   => $this->title
        ];
    }

    protected function getLists()
    {
        $api = $this->getRemoteClient();

        $lists = $api->makeRequest('contact_lists/?limit=1000');

        $formattedLists = [];

        if (is_wp_error($lists)) {
            wp_send_json_error([
                'message' => $lists->get_error_message()
            ], 423);
        }
        $lists = ArrayHelper::get($lists, 'lists');

        if (!empty($lists)) {
            foreach ($lists as $list) {
                $formattedLists[$list['list_id']] = $list['name'];
            }
            return $formattedLists;
        }

        return [];
    }

    protected function getTags()
    {
        $api = $this->getRemoteClient();

        $tags = $api->makeRequest('contact_tags/?limit=500');

        $formattedTags = [];

        if (is_wp_error($tags)) {
            wp_send_json_error([
                'message' => $tags->get_error_message()
            ], 423);
        }
        $tags = ArrayHelper::get($tags, 'tags');

        if (!empty($tags)) {
            foreach ($tags as $tag) {
                $formattedTags[$tag['tag_id']] = $tag['name'];
            }
            return $formattedTags;
        }

        return [];
    }

    protected function getCustomFields()
    {
        $api = $this->getRemoteClient();

        $customFields = $api->makeRequest('contact_custom_fields/?limit=100');

        $formattedFields = [];

        if (is_wp_error($customFields)) {
            wp_send_json_error([
                'message' => $customFields->get_error_message()
            ], 423);
        }

        $customFields = ArrayHelper::get($customFields, 'custom_fields');

        if (!empty($customFields)) {
            foreach ($customFields as $field) {
                $formattedFields[$field['custom_field_id']] = $field['label'];
            }
            return $formattedFields;
        }

        return [];
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $feedData['email_address'] = ArrayHelper::get($formData, $feedData['email_address']);
        $updateIfExist = ArrayHelper::get($feedData, 'update_if_exist', 0) == 1;

        if (!is_email($feedData['email_address']) || !$feedData['list_id']) {
            do_action('fluentform/integration_action_result', $feed, 'failed',
                __('Constant Contact V3 API call has been skipped because no valid email available', 'fluentformpro'));
            return false;
        }

        $subscriber = [];
        $addresses = [
            'home' => [],
            'work' => [],
            'other' => []
        ];

        $mapFields = ArrayHelper::only($feedData, [
            'list_id',
            'tag_ids',
            'contact_fields',
            'email_address',
            'permission_to_send',
            'first_name',
            'last_name',
            'job_title',
            'company_name',
            'birthday_month',
            'birthday_day',
            'anniversary',
            'home_phone',
            'work_phone',
            'mobile_phone',
            'home_address_street',
            'home_address_city',
            'home_address_state',
            'home_address_postal_code',
            'home_address_country',
            'work_address_street',
            'work_address_city',
            'work_address_state',
            'work_address_postal_code',
            'work_address_country',
            'other_address_street',
            'other_address_city',
            'other_address_state',
            'other_address_postal_code',
            'other_address_country',
        ]);

        foreach ($mapFields as $fieldName => $fieldValue) {
            if (empty($fieldValue)) {
                continue;
            }

            if ($updateIfExist) {
                if ($fieldName === 'email_address') {
                    $subscriber['email_address'] = $fieldValue;
                }
            } else {
                if ($fieldName === 'email_address') {
                    $subscriber['email_address'] = [
                        'address' => $fieldValue,
                    ];
                }

                if ($fieldName === 'permission_to_send') {
                    $subscriber['email_address']['permission_to_send'] = $fieldValue;
                }
            }

            if ($fieldName === 'birthday_month') {
                $subscriber['birthday_month'] = (int)$fieldValue;
            }

            if ($fieldName === 'birthday_day') {
                $subscriber['birthday_day'] = (int)$fieldValue;
            }

            if (strpos($fieldName, '_address_') !== false) {
                $addressArray = explode('_', $fieldName, 3);
                $kind = ArrayHelper::get($addressArray, '0');
                $type = ArrayHelper::get($addressArray, '2');

                if (!isset($addresses[$kind])) {
                    $addresses[$kind] = [];
                }

                $addresses[$kind][$type] = $fieldValue;
                continue;
            }

            if (!isset($subscriber[$fieldName])) {
                $subscriber[$fieldName] = $fieldValue;
            }

            if ($fieldName === 'list_id') {
                $subscriber['list_memberships'] = [
                    $fieldValue
                ];
                unset($subscriber[$fieldName]);
            }

            if ($fieldName === 'tag_ids') {
                $subscriber['taggings'] = $fieldValue;
                unset($subscriber[$fieldName]);
            }

            if (strpos($fieldName, '_phone') !== false) {
                $phoneArray = explode('_', $fieldName);
                $kind = ArrayHelper::get($phoneArray, '0');

                $subscriber['phone_numbers'][] = [
                    'phone_number' => $fieldValue,
                    'kind' => $kind,
                ];
                unset($subscriber[$fieldName]);
            }
        }

        $subscriber['street_addresses'] = [];
        foreach ($addresses as $kind => $address) {
            if (!empty($address)) {
                $formattedAddress = [
                    'kind' => $kind,
                    'street' => ArrayHelper::get($address, 'street', ''),
                    'city' => ArrayHelper::get($address, 'city', ''),
                    'state' => ArrayHelper::get($address, 'state', ''),
                    'postal_code' => ArrayHelper::get($address, 'postal_code', ''),
                    'country' => ArrayHelper::get($address, 'country', '')
                ];
                // Remove empty fields
                $formattedAddress = array_filter($formattedAddress);
                $subscriber['street_addresses'][] = $formattedAddress;
            }
        }

        if ($customFields = ArrayHelper::get($feedData, 'custom_fields')) {
            foreach ($customFields as $fieldKey => $fieldValue) {
                if (empty(ArrayHelper::get($fieldValue, 'item_value'))) {
                    unset($customFields[$fieldKey]);
                    continue;
                }
                $subscriber['custom_fields'][] = [
                    'custom_field_id' => ArrayHelper::get($fieldValue, 'label'),
                    'value'           => ArrayHelper::get($fieldValue, 'item_value'),
                ];
            }
        }

        $subscriber['create_source'] = 'Contact';

        $subscriber = apply_filters('fluentform/integration_data_' . $this->integrationKey, $subscriber, $feed, $entry);

        $subscriber = \json_encode($subscriber);
        $client = $this->getRemoteClient();
        
        $endPoint = '/contacts';
        if ($updateIfExist) {
            $endPoint .= '/sign_up_form';
        }
        
        $results = $client->makeRequest($endPoint, $subscriber, 'POST');

        if (is_wp_error($results)) {
            do_action('fluentform/integration_action_result', $feed, 'failed',
                $results->get_error_message());
        } elseif ($contactId = ArrayHelper::get($results, 'contact_id')) {
            do_action('fluentform/integration_action_result', $feed, 'success',
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                __('Constant Contact has been successfully initialed and pushed data with Contact ID: ' . $contactId, 'fluentformpro'));
        }
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }
}
