<?php

namespace FluentFormPro\Integrations\GetGist;

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
            'GetGist',
            'getgist',
            '_fluentform_getgist_settings',
            'getgist_feed',
            36
        );

        $this->logo = fluentFormMix('img/integrations/getgist.png');

        $this->description = 'Gist is an email marketing tool, and you can connect Fluent Forms to build email lists.';

        $this->registerAdminHooks();

       // add_filter('fluentform/notifying_async_getgist', '__return_false');
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('GetGist Settings', 'fluentformpro'),
            'menu_description' => $this->description,
            'valid_message'    => __('Your GetGist API Key is valid', 'fluentformpro'),
            'invalid_message'  => __('Your GetGist API Key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'apiKey' => [
                    'type'        => 'text',
                    'placeholder' => __('API Key', 'fluentformpro'),
                    'label_tips'  => __("Enter your GetGist API Key, if you do not have <br>Please log in to your GetGist account and go to<br>Settings -> Integrations -> API key", 'fluentformpro'),
                    'label'       => __('GetGist API Key', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your GetGist API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect GetGist', 'fluentformpro'),
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
            'message' => __('Your GetGist api key has been verified and successfully set', 'fluentformpro'),
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
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-getgist-settings'),
            'configure_message'     => __('GetGist is not configured yet! Please configure your GetGist api first', 'fluentformpro'),
            'configure_button_text' => __('Set GetGist API', 'fluentformpro')
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
                    'label'       => __('Feed Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'                => 'fields',
                    'label'              => __('Map Fields', 'fluentformpro'),
                    'tips'               => __('Select which Fluent Forms fields pair with their<br /> respective Gist fields.', 'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('Gist Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
                    'primary_fileds'     => [
                        [
                            'key'           => 'email',
                            'label'         => __('Email Address', 'fluentformpro'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ],
                        [
                            'key'           => 'lead_name',
                            'label'         => __('Name', 'fluentformpro'),
                            'required'      => false
                        ],
                        [
                            'key'           => 'lead_phone',
                            'label'         => __('Phone', 'fluentformpro'),
                            'required'      => false
                        ]
                    ]
                ],
                [
                    'key'         => 'tags',
                    'label'       => __('Lead Tags', 'fluentformpro'),
                    'required'    => false,
                    'placeholder' => __('Tags', 'fluentformpro'),
                    'component'   => 'value_text',
                    'inline_tip' => __('Use comma separated value. You can use smart tags here', 'fluentformpro')
                ],
                [
                    'key'             => 'landing_url',
                    'label'           => __('Landing URL', 'fluentformpro'),
                    'tips'            => __('When this option is enabled, FluentForm will pass the form page url to the gist lead', 'fluentformpro'),
                    'component'       => 'checkbox-single',
                    'checkbox_label' => __('Enable Landing URL', 'fluentformpro')
                ],
                [
                    'key'             => 'last_seen_ip',
                    'label'           => __('Push IP Address', 'fluentformpro'),
                    'tips'            => __('When this option is enabled, FluentForm will pass the last_seen_ip to gist', 'fluentformpro'),
                    'component'       => 'checkbox-single',
                    'checkbox_label' => __('Enable last IP address', 'fluentformpro')
                ],
                [
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow Gist integration conditionally based on your submission values', 'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'key'             => 'enabled',
                    'label'           => __('Status', 'fluentformpro'),
                    'component'       => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro')
                ]
            ],
            'integration_title'   => $this->title
        ];
    }

    protected function getLists()
    {
        return [];
    }

    public function getMergeFields($list = false, $listId = false, $formId = false)
    {
       return [];
    }


    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $subscriber = [
            'name' => ArrayHelper::get($feedData, 'lead_name'),
            'email' => ArrayHelper::get($feedData, 'email'),
            'phone' => ArrayHelper::get($feedData, 'lead_phone'),
            'created_at' => time(),
            'last_seen_at' => time()
        ];

        $tags = ArrayHelper::get($feedData, 'tags');
        if($tags) {
            $tags = explode(',', $tags);
            $formtedTags = [];
            foreach ($tags as $tag) {
                $formtedTags[] = wp_strip_all_tags(trim($tag));
            }
            $subscriber['tags'] = $formtedTags;
        }

        if(ArrayHelper::isTrue($feedData, 'landing_url')) {
            $subscriber['landing_url'] = $entry->source_url;
        }

        if(ArrayHelper::isTrue($feedData, 'last_seen_ip')) {
            $subscriber['last_seen_ip'] = $entry->ip;
        }

        $subscriber = array_filter($subscriber);

        if(!empty($subscriber['email']) && !is_email($subscriber['email'])) {
            $subscriber['email'] = ArrayHelper::get($formData, $subscriber['email']);
        }

        if(!is_email($subscriber['email'])) {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('GetGist API called skipped because no valid email available', 'fluentformpro'));
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
        $response = $api->subscribe($subscriber);

        if (is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
        } else {
            do_action('fluentform/integration_action_result', $feed, 'success', __('GetGist feed has been successfully initialed and pushed data', 'fluentformpro'));
        }
    }

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new API($settings['apiKey']);
    }

}
