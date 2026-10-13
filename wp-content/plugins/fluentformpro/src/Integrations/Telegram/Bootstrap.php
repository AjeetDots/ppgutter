<?php

namespace FluentFormPro\Integrations\Telegram;

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
            'Telegram ',
            'telegram',
            '_fluentform_telegram_settings',
            'telegram_feed',
            99
        );

        $this->logo = fluentFormMix('img/integrations/telegram.png');

        $this->description = 'Send notification to Telegram channel or group when a form is submitted.';

        $this->registerAdminHooks();

//        add_filter('fluentform/notifying_async_' . $this->integrationKey, '__return_false');
    }

    public function getGlobalFields($fields)
    {

        return [
            'logo'             => $this->logo,
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_title'       => __($this->title . ' Settings', 'fluentformpro'),
            'menu_description' => __('Create a Bot by sending /newbot command to @BotFather in telegram. After completing the steps @BotFather will provide you the Bot Token. <br>Create a Channel/group/supergroup. Add the Bot as Administrator to your Channel/Group.<br /><a href="https://wpmanageninja.com/docs/fluent-form/integrations-available-in-wp-fluent-form/telegram-messenger-integration-with-wp-fluent-forms/" target="_blank">Please read the documentation for step by step tutorial</a>', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'valid_message'    => __($this->title . ' integration is complete', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'invalid_message'  => __($this->title . ' integration is not complete', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'bot_token' => [
                    'type'        => 'password',
                    'placeholder' => __('bot_token', 'fluentformpro'),
                    'label_tips'  => __("Enter your Telegram Bot Token", 'fluentformpro'),
                    'label'       => __(' Bot Token', 'fluentformpro'),
                ],
                'chat_id'   => [
                    'type'        => 'text',
                    'placeholder' => __('Channel Username/ID', 'fluentformpro'),
                    'label_tips'  => __("Enter your Telegram API channel user ID, You can also use message id. Please check documentation for more details.", 'fluentformpro'),
                    'label'       => __('Default Channel/Group Chat ID', 'fluentformpro'),
                ],
                'message'   => [
                    'type'        => 'textarea',
                    'placeholder' => __('Test Message', 'fluentformpro'),
                    'label_tips'  => __("Enter your Test Message", 'fluentformpro'),
                    'label'       => __('Test Message (optional)', 'fluentformpro'),
                    'tips'        => __('Provide a message if you want to send a test message now', 'fluentformpro')
                ],
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'section_description' => __('Your ' . $this->title . ' API integration is up and running', 'fluentformpro'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'button_text'         => __('Disconnect ' . $this->title, 'fluentformpro'),
                'data'                => [
                    'chat_id'   => '',
                    'bot_token' => '',
                    'message'   => ''
                ],
                'show_verify'         => true
            ]
        ];
    }

    public function getGlobalSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);
        if (!$globalSettings) {
            $globalSettings = [
                'status' => ''
            ];
        }
        $defaults = [
            'status'    => '',
            'chat_id'   => '',
            'bot_token' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['chat_id']) || empty($settings['bot_token'])) {
            $settings['status'] = false;
            update_option($this->optionKey, $settings, 'no');
            wp_send_json_success([
                'message' => __('Your settings has been updated', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
        $responseMessage = __('Your ' . $this->integrationKey . ' api key has been verified and successfully set', 'fluentformpro');
        $status = false;
        // Verify API key now
        try {
            $api = $this->getApiClient($settings['bot_token']);

            $apiStatus = $api->getMe();

            if (is_wp_error($apiStatus)) {
                throw new \Exception($apiStatus->get_error_message());
            }

            $apiSettings = [
                'bot_token' => sanitize_textarea_field($settings['bot_token']),
                'status'    => true,
                'chat_id'   => sanitize_textarea_field($settings['chat_id'])
            ];

            $message = ArrayHelper::get($settings, 'message', '');
            if ($message) {
                $api->setChatId($apiSettings['chat_id']);
                $result = $api->sendMessage($message);
                if (is_wp_error($result)) {
                    $apiSettings['status'] = false;
                    $responseMessage = 'Your api key is right but, the message could not be sent to the provided chat id. Error: ' . $result->get_error_message();
                }
            }

            $status = $apiSettings['status'];

            update_option($this->optionKey, $apiSettings, 'no');

        } catch (\Exception $exception) {

            $settings ['status'] = false;
            update_option($this->optionKey, $settings, 'no');
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }

        $responseCode = 200;
        if (!$status) {
            $responseCode = 423;
        }

        wp_send_json_success([
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'message' => __($responseMessage, 'fluentformpro'),
            'status'  => $status
        ], $responseCode);

    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-' . $this->integrationKey . '-settings'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'configure_message'     => __($this->title . ' is not configured yet! Please configure your  api first', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'configure_button_text' => __('Set ' . $this->title . ' API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'           => '',
            'send_message'   => '',
            'custom_chat_id' => '',
            'conditionals'   => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'        => true
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
                    'key'         => 'send_message',
                    'label'       => __('Message to Send', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Telegram Message', 'fluentformpro'),
                    'component'   => 'value_textarea'
                ],
                [
                    'key'         => 'custom_chat_id',
                    'label'       => __('Custom Chat/Channel ID', 'fluentformpro'),
                    'required'    => false,
                    'placeholder' => __('Custom Chat ID', 'fluentformpro'),
                    'component'   => 'text',
                    'inline_tip'  => __('Provide custom chat id if you want to send a different channel or chat ID. <b>Leave blank for global chat ID</b>', 'fluentformpro')
                ],
                [
                    'key'       => 'conditionals',
                    'label'     => __('Conditional Logics', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'tips'      => __('Allow ' . $this->title . ' integration conditionally based on your submission values', 'fluentformpro'),
                    'component' => 'conditional_block'
                ],
                [
                    'key'            => 'enabled',
                    'label'          => __('Status', 'fluentformpro'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro'),
                ],
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];
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

        if (empty ($feedData['send_message'])) {
            // for now single file  upload only otherwise skip this
            return;
        }

        $settings = $this->getGlobalSettings([]);

        if(!$settings['status']) {
            return;
        }

        if ($chatId = ArrayHelper::get($feedData, 'custom_chat_id')) {
            if (trim($chatId)) {
                $settings['chat_id'] = $chatId;
            }
        }

        $api = $this->getApiClient($settings['bot_token'], $settings['chat_id']);
        $response = $api->sendMessage($feedData['send_message']);

        if (is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
            return;
        }

        $messageId = ArrayHelper::get($response, 'result.message_id');
        do_action(
            'fluentform/integration_action_result',
            $feed,
            'success',
            __('Telegram feed has been successfully initialed and pushed data. Message ID: ', 'fluentformpro') . $messageId
        );
    }

    protected function getApiClient($token, $chatId = '')
    {

        return new TelegramApi(
            $token,
            $chatId
        );
    }
}
