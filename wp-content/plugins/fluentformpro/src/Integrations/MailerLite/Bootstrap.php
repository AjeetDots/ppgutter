<?php

namespace FluentFormPro\Integrations\MailerLite;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'MailerLite',
            'mailerlite',
            '_fluentform_mailerlite_settings',
            'mailerlite_feed',
            35
        );

        $this->logo = fluentFormMix('img/integrations/mailerlite.png');

        $this->description = 'Mailer Lite is an email marketing software for designers and their clients. Use Fluent Forms to add subscribers to MailerLite.';

        $this->registerAdminHooks();

//        add_filter('fluentform/notifying_async_mailerlite', '__return_false');
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('MailerLite Settings', 'fluentformpro'),
            'menu_description' => $this->description,
            'valid_message'    => __('Your MailerLite API Key is valid', 'fluentformpro'),
            'invalid_message'  => __('Your MailerLite API Key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'apiKey' => [
                    'type'        => 'text',
                    'placeholder' => 'API Key',
                    'label_tips'  => __("Enter your MailerLite API Key, if you do not have <br>Please log in to your MailerLite account and go to<br>Profile -> Account Settings -> Account Info", 'fluentformpro'),
                    'label'       => __('MailerLite API Key', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your MailerLite API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect MailerLite', 'fluentformpro'),
                'data'                => [
                    'apiKey' => ''
                ],
                'show_verify'         => true
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
            'apiKey' => '',
            'status' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (!$settings['apiKey']) {
            $integrationSettings = [
                'apiKey' => '',
                'status' => false
            ];
            // Update the reCaptcha details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');
            wp_send_json_success([
                'message' => __('Your settings has been updated', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        // Verify API key now
        try {
            $integrationSettings = [
                'apiKey' => sanitize_text_field($settings['apiKey']),
                'status' => false
            ];
            update_option($this->optionKey, $integrationSettings, 'no');

            $api = new API($settings['apiKey']);
            $result = $api->auth_test();
            if (!empty($result['error'])) {
                throw new \Exception($result['message']);
            }
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }

        // Integration key is verified now, Proceed now

        $integrationSettings = [
            'apiKey' => sanitize_text_field($settings['apiKey']),
            'status' => true
        ];

        // Update the reCaptcha details with siteKey & secretKey.
        update_option($this->optionKey, $integrationSettings, 'no');

        wp_send_json_success([
            'message' => __('Your MailerLite api key has been verified and successfully set', 'fluentformpro'),
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-mailerlite-settings'),
            'configure_message'     => __('MailerLite is not configured yet! Please configure your MailerLite api first', 'fluentformpro'),
            'configure_button_text' => __('Set MailerLite API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'         => '',
            'list_id'      => '',
            'fields'       => (object)[],
            'conditionals' => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'resubscribe'  => false,
            'enabled'      => true
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
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'         => 'list_id',
                    'label'       => __('Group List', 'fluentformpro'),
                    'placeholder' => __('Select MailerLite List', 'fluentformpro'),
                    'tips'        => __('Select the MailerLite Group you would like to add your contacts to.', 'fluentformpro'),
                    'component'   => 'list_ajax_options',
                    'options'     => $this->getLists(),
                ],
                [
                    'key'                => 'fields',
                    'require_list'       => true,
                    'label'              => __('Map Fields', 'fluentformpro'),
                    'tips'               => __('Select which Fluent Forms fields pair with their<br /> respective MailerLite fields.', 'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('MailerLite Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
                    'primary_fileds'     => []
                ],
                [
                    'require_list' => true,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow MailerLite integration conditionally based on your submission values', 'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list'   => true,
                    'key'            => 'resubscribe',
                    'label'          => __('Resubscribe', 'fluentformpro'),
                    'checkbox_label' => __('Reactivate subscriber if value is checked', 'fluentformpro'),
                    'component'      => 'checkbox-single'
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
        $lists = $api->getGroups();

        $formateddLists = [];
        foreach ($lists as $list) {
            $formateddLists[$list['id']] = $list['name'];
        }
        return $formateddLists;
    }

    public function getMergeFields($list = false, $listId = false, $formId = false)
    {
        $api = $this->getRemoteClient();
        if (!$api) {
            return [];
        }
        $fields = $api->getCustomFields();
        $formattedFields = [];

        if ($fields) {
            foreach ($fields as $field) {
                $formattedFields[$field['key']] = $field['title'];
            }
        }

        return $formattedFields;
    }


    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        $dataFields = $feedData['fields'];

        $fields = $this->getMergeFields();

        $subscriber = [
            'email'                  => '',
            'confirmation_ip'        => $entry->ip,
            'confirmation_timestamp' => date('Y-m-d H:i:s'), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
            'fields'                 => [],
            'resubscribe'            => !empty($feedData['resubscribe']) ? true : false
        ];

        foreach ($fields as $fieldKey => $fieldName) {
            if (empty($dataFields[$fieldKey])) {
                continue;
            }
            if ($fieldKey == 'name' || $fieldKey == 'email') {
                $subscriber[$fieldKey] = $dataFields[$fieldKey];
            } else {
                $subscriber['fields'][$fieldKey] = $dataFields[$fieldKey];
            }
        }

        if (empty($subscriber['email']) || !is_email($subscriber['email'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('MailerLte API call has been skipped because no valid email available', 'fluentformpro'));
            return;
        }
    
        $subscriber = apply_filters_deprecated(
            'fluentform_integration_data_' . $this->integrationKey,
            [
                $subscriber,
                $feed,
                $entry
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/integration_data_' . $this->integrationKey,
            'Use fluentform/integration_data_' . $this->integrationKey . ' instead of fluentform_integration_data_' . $this->integrationKey
        );

        $subscriber = apply_filters('fluentform/integration_data_' . $this->integrationKey, $subscriber, $feed, $entry);

        $api = $this->getRemoteClient();
        $response = $api->subscribe($feedData['list_id'], $subscriber);

        if (is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
            return;
        }

        do_action('fluentform/integration_action_result', $feed, 'success', __('MailerLite feed has been successfully initialed and pushed data', 'fluentformpro'));
    }

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new API($settings['apiKey']);
    }
}
