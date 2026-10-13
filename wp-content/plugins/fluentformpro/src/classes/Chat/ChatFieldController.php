<?php

namespace FluentFormPro\classes\Chat;

defined('ABSPATH') or die;

use FluentForm\App\Models\FormMeta;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;

/**
 *  Handling Chat Field Module.
 *
 * @since 5.1.5
 */
class ChatFieldController
{
    public $api = null;
    protected $optionKey = '_fluentform_openai_settings';
    protected $integrationKey = 'openai';
    protected $app = null;
    protected $settingsKey = 'open_ai_feed';

    public function __construct($app)
    {
        $this->app = $app;
        $this->api = new ChatApi($this->optionKey);
        $this->boot();
    }

    public function boot()
    {
        $isEnabled = $this->isEnabled();
        $this->enableIntegration($isEnabled);
        if (!$isEnabled) {
            return;
        }

        $isApiEnabled = $this->api->isApiEnabled();

        if ($isApiEnabled) {
            add_filter('fluentform/all_forms_vars', function ($settings) {
                $settings['has_gpt_feature'] = true;
                return $settings;
            });
            new ChatFormBuilder();
        }

        add_filter('fluentform/global_settings_components', [$this, 'addGlobalMenu'], 11, 1);

        add_filter('fluentform/global_integration_settings_' . $this->integrationKey, [$this, 'getGlobalSettings'], 11, 1);

        add_filter('fluentform/global_integration_fields_' . $this->integrationKey, [$this, 'getGlobalFields'], 11, 1);

        add_action('fluentform/save_global_integration_settings_' . $this->integrationKey, [$this, 'saveGlobalSettings'], 11,1);

        add_filter('fluentform/global_notification_types', [$this, 'addNotificationType'], 11, 1);

        add_filter('fluentform/get_available_form_integrations', [$this, 'pushIntegration'], 11, 2);

        add_filter('fluentform/global_notification_feed_' . $this->settingsKey, [$this, 'setFeedAttributes'], 11, 2);

        add_filter('fluentform/get_integration_defaults_' . $this->integrationKey, [$this, 'getIntegrationDefaults'], 11, 2);

        add_filter('fluentform/get_integration_settings_fields_' . $this->integrationKey, [$this, 'getSettingsFields'], 11, 2);

        add_filter('fluentform/save_integration_settings_' . $this->integrationKey, [$this, 'setMetaKey'], 11, 2);

        add_filter('fluentform/get_integration_values_' . $this->integrationKey, [$this, 'prepareIntegrationFeed'], 11, 3);

        add_filter('fluentform/save_integration_value_' . $this->integrationKey, [$this, 'validate'], 10, 3);

        add_filter('fluentform/form_class', [$this, 'beforeFormRenderCss'], 10, 2);

        add_filter('fluentform/chat_gpt_waiting_message', [$this, 'setWaitingMessage'], 10, 2);

        add_filter('fluentform/all_editor_shortcodes', [$this, 'insertAllEditorShortcode'], 10, 1);

//        add_action('wp_ajax_fluentform_openai_chat_completion', [$this, 'chatCompletion'], 11, 0);

//        add_action('wp_ajax_nopriv_fluentform_openai_chat_completion', [$this, 'chatCompletion'], 11, 0);

//        new ChatField();
    }

    public function setWaitingMessage($message, $form)
    {
        $feeds = FormMeta::where('meta_key', $this->settingsKey)
                         ->where('form_id', $form->id)
                         ->get();

        if (!count($feeds)) {
            return $message;
        }

        foreach ($feeds as $feed) {
            $value = json_decode($feed->value, true);
            if (ArrayHelper::isTrue($value, 'enabled')) {
                $waitingMessage = ArrayHelper::get($value, 'waiting_message');

                if (!$waitingMessage) {
                    return $message;
                }

                return $waitingMessage;
            }
        }

        return $message;
    }
    public function loadScripts($form)
    {
        $message = apply_filters('fluentform/chat_gpt_waiting_message','', $form);

        wp_register_script(
            'fluentform-chat-field-script',
            FLUENTFORMPRO_DIR_URL . 'public/js/chatFieldScript.js',
            ['jquery'],
            FLUENTFORMPRO_VERSION,
            true
        );
        
        wp_localize_script(
            'fluentform-chat-field-script',
            'fluentform_chat',
            [
                'message' => $message,
            ]
        );

        wp_enqueue_script('fluentform-chat-field-script');
    }

    public function enableIntegration($isEnabled)
    {
        add_filter('fluentform/global_addons', function($addOns) use ($isEnabled) {
            $addOns[$this->integrationKey] = [
                'title'       => 'OpenAI ChatGPT',
                'description' => __('Connect OpenAI ChatGPT to add integrations or create forms using AI prompts', 'fluentformpro'),
                'logo'        => fluentFormMix('img/integrations/openai.png'),
                'enabled'     => ($isEnabled) ? 'yes' : 'no',
                'config_url'  => admin_url('admin.php?page=fluent_forms_settings#general-openai-settings'),
                'category'    => '', //Category : All
            ];

            return $addOns;
        }, 9);
    }

    public function addGlobalMenu($setting)
    {
        $setting[$this->integrationKey] = [
            'hash'         => 'general-' . $this->integrationKey . '-settings',
            'component'    => 'general-integration-settings',
            'settings_key' => $this->integrationKey,
            'title'        => 'ChatGPT Integration',
        ];
        return $setting;
    }

    public function getGlobalSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);
        if (!$globalSettings) {
            $globalSettings = [];
        }
        $defaults = [
            'access_token' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => fluentFormMix('img/integrations/openai.png'),
            'menu_title'       => __('OpenAI ChatGPT Integration', 'fluentformpro'),
            'menu_description' => __('The OpenAI ChatGPT API can be applied to create forms and dynamic submission confirmation message.', 'fluentformpro'),
            'valid_message'    => __('Your OpenAI ChatGPT connection is valid', 'fluentformpro'),
            'invalid_message'  => __('Your OpenAI ChatGPT connection is not valid', 'fluentformpro'),
            'save_button_text' => __('Verify OpenAI ChatGPT', 'fluentformpro'),
            'fields'           => [
                'button_link'  => [
                    'type'      => 'link',
                    'link_text' => __('Get OpenAI ChatGPT API Keys', 'fluentformpro'),
                    'link'      => 'https://platform.openai.com/account/api-keys',
                    'target'    => '_blank',
                    'tips'      => __('Please click on this link get API keys from OpenAI ChatGPT.', 'fluentformpro'),
                ],
                'access_token' => [
                    'type'        => 'password',
                    'placeholder' => __('API Keys', 'fluentformpro'),
                    'label_tips'  => __("Please find API Keys by clicking 'Get OpenAI ChatGPT API Keys' Button then paste it here", 'fluentformpro'),
                    'label'       => __('Access Code', 'fluentformpro'),
                ]
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your OpenAI ChatGPT integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect OpenAI ChatGPT', 'fluentformpro'),
                'data'                => [
                    'access_token' => ''
                ],
                'show_verify'         => true
            ]
        ];
    }

    public function saveGlobalSettings($settings)
    {
        $token = $settings['access_token'];
        if (empty($token)) {
            $integrationSettings = [
                'access_token' => '',
                'status'       => false
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
            $isAuth = $this->api->isAuthenticated($token);

            if ($isAuth && !is_wp_error($isAuth)) {
                $token = [
                    'status'       => true,
                    'access_token' => $settings['access_token']
                ];
            } else {
                throw new \Exception($isAuth->get_error_message(), $isAuth->get_error_code());
            }

            update_option($this->optionKey, $token, 'no');
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }

        wp_send_json_success([
            'message' => __('Your OpenAI ChatGPT API key has been verified and successfully set', 'fluentformpro'),
            'status'  => true
        ], 200);
    }

    public function addNotificationType($types)
    {
        $types[] = $this->settingsKey;
        return $types;
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => 'OpenAI ChatGPT',
            'logo'                  => fluentFormMix('img/integrations/openai.png'),
            'is_active'             => $this->isEnabled(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-openai-settings'),
            'configure_message'     => __('OpenAI ChatGPT is not configured yet! Please configure your OpenAI api first',
                'fluentformpro'),
            'configure_button_text' => __('Set OpenAI ChatGPT API', 'fluentformpro')
        ];

        return $integrations;
    }

    public function setFeedAttributes($feed, $formId)
    {
        $feed['provider'] = $this->integrationKey;
        $feed['provider_logo'] = fluentFormMix('img/integrations/openai.png');
        return $feed;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'            => '',
            'role'            => '',
            'prompt_field'    => '',
            'waiting_message' => '',
            'chat_loader'     => '',
            'conditionals'    => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'         => true
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
                    'key'       => 'role',
                    'label'     => __('Select Role', 'fluentformpro'),
                    'tips'      => __('Select how the AI should respond and behave', 'fluentformpro'),
                    'required'  => true,
                    'component' => 'select',
                    'options'   => [
                        'system'    => __('System', 'fluentformpro'),
                        'assistant' => __('Assistant', 'fluentformpro'),
                        'user'      => __('User', 'fluentformpro')
                    ]
                ],
                [
                    'key'         => 'prompt_field',
                    'label'       => __('Write Query', 'fluentformpro'),
                    'placeholder' => __('Write your query to get OpenAI ChatGPT generated result', 'fluentformpro'),
                    'tips'        => __('Write your query to get OpenAI ChatGPT generated result', 'fluentformpro'),
                    'required'    => true,
                    'component'   => 'value_textarea',
                ],
                [
                    'key'         => 'waiting_message',
                    'label'       => __('Waiting Message with Loader', 'fluentformpro'),
                    'placeholder' => __('Type waiting message or leave it empty skip the loader', 'fluentformpro'),
                    'tips'        => __('Write your desired waiting message while getting OpenAI ChatGPT generated result, it will show a loader Icon', 'fluentformpro'),
                    'value'       => __('Please wait while getting the data from ChatGPT. Do not refresh or close the window', 'fluentformpro'),
                    'required'    => true,
                    'component'   => 'value_textarea',
                ],
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
            ],
            'button_require_list' => false,
            'integration_title'   => 'OpenAI ChatGPT'
        ];
    }

    public function setMetaKey($data)
    {
        $data['meta_key'] = $this->settingsKey;
        return $data;
    }

    public function prepareIntegrationFeed($setting, $feed, $formId)
    {
        $defaults = $this->getIntegrationDefaults([], $formId);

        foreach ($setting as $settingKey => $settingValue) {
            if ('true' == $settingValue) {
                $setting[$settingKey] = true;
            } elseif ('false' == $settingValue) {
                $setting[$settingKey] = false;
            } elseif ('conditionals' == $settingKey) {
                if ('true' == $settingValue['status']) {
                    $settingValue['status'] = true;
                } elseif ('false' == $settingValue['status']) {
                    $settingValue['status'] = false;
                }
                $setting['conditionals'] = $settingValue;
            }
        }

        if (!empty($setting['list_id'])) {
            $setting['list_id'] = (string)$setting['list_id'];
        }

        return wp_parse_args($setting, $defaults);
    }

    public function validate($settings, $integrationId, $formId)
    {
        $error = false;
        $errors = [];

        if (empty($settings['role'])) {
            $error = true;
            $errors['role'] = __('Select Role is required', 'fluentformpro');
        }

        if (empty($settings['prompt_field'])) {
            $error = true;
            $errors['prompt_field'] = __('Write query is required', 'fluentformpro');
        }

        if ($error) {
            wp_send_json_error([
                'message' => __('Validation Failed', 'fluentformpro'),
                'errors'  => $errors
            ], 423);
        }

        return $settings;
    }

    private function isEnabled()
    {
        $globalModules = get_option('fluentform_global_modules_status');
        $openAiModule = ArrayHelper::get($globalModules, $this->integrationKey);
        if ($openAiModule == 'yes') {
            return true;
        }
        return false;
    }

    private function getFeeds($formId = '')
    {
        if (!$formId) {
            $request = $this->app->request->get();
            $formId = ArrayHelper::get($request, 'form_id');
        }
        $feeds = [];
        if ($formId) {
            $feeds = FormMeta::when($formId, function($q) use ($formId) {
                return $q->where('form_id', $formId);
            })->where('meta_key', $this->settingsKey)->get()->toArray();
        }

        return $feeds;
    }

     public function insertAllEditorShortcode($data = [])
     {
        $feeds = $this->getFeeds();

        if (!$feeds) {
            return $data;
        }

        $chatGPTShortCodesContainer = [
            'title'     => __('ChatGPT', 'fluentformpro'),
            'shortcodes' => []
        ];

        foreach ($feeds as $feed) {
            $value = json_decode(ArrayHelper::get($feed, 'value'), true);
            if (!ArrayHelper::isTrue($value, 'enabled')) {
                continue;
            }
            $feedId = ArrayHelper::get($feed, 'id');
            $formId = ArrayHelper::get($feed, 'form_id');
            $feedName = ArrayHelper::get($value, 'name');
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            $chatGPTShortCodesContainer['shortcodes']['{chat_gpt_response.'. $formId . '_' . $feedId . '}'] = __(sprintf('ChatGPT Response for %s', $feedName), 'fluentformpro');
        }

        $data[] = $chatGPTShortCodesContainer;

        return $data;
    }

    public function chatGPTSubmissionMessageHandler($formId, $feedId, $parser)
    {
        $feed = FormMeta
                ::where('id', $feedId)
                ->where('form_id', $formId)
                ->where('meta_key', $this->settingsKey)
                ->first();

        if (!$feed) {
            return '';
        }

        $value = json_decode($feed->value, true);

        if (!ArrayHelper::isTrue($value, 'enabled')) {
            return '';
        }

        $role = ArrayHelper::get($value, 'role');
        $content = ArrayHelper::get($value, 'prompt_field');
        $submission = $parser::getEntry();
        $submittedData = \json_decode($submission->response, true);
        $submissionId = $submission->id;
        $form = $parser::getForm();

        $content = ShortCodeParser::parse(
            $content,
            $submissionId,
            $submittedData,
            $form,
            false,
            true
        );

        $args = [
            "role"    => $role,
            "content" => $content
        ];


        $result = $this->api->makeRequest($args);

        if (is_wp_error($result)) {
            return '';
        }

        $content = trim(ArrayHelper::get($result, 'choices.0.message.content'), '"');
        $content = fluentform_sanitize_html($content);

        // Extract code from response if html code blocks exist
        return $this->api->maybeExtractCodeFromResponse($content);
    }

    public function beforeFormRenderCss($class, $form)
    {
        $formSettings = $form->settings;
        $message = ArrayHelper::get($formSettings, 'confirmation.messageToShow');
        $hasChatGpt = ArrayHelper::get($formSettings, 'confirmation.redirectTo') === 'samePage' && strpos($message, '{chat_gpt_response.') !== false;

        if ($hasChatGpt) {
            $class .= ' ff-has-chat-gpt';
            $this->loadScripts($form);
        }

        return $class;
    }

    // ajax handler for chat field
    /*public function chatCompletion()
    {
        $request = $this->app->request->get();
        $formId = ArrayHelper::get($request, 'form_id', '');
        $form = Form::find($formId);
        $fields = ArrayHelper::get(json_decode($form->form_fields, true), 'fields');
        $role = '';
        $content = ArrayHelper::get($request, 'content');
        $failedMessage = '';

        foreach ($fields as $field) {
            if (ArrayHelper::get($field, 'element') == 'chat') {
                $role = ArrayHelper::get($field, 'settings.open_ai_role');
                $content = $content ?: ArrayHelper::get($field, 'settings.open_ai_content');
                $failedMessage = ArrayHelper::get($field, 'settings.failed_message');
            }
        }

        $args = [
            "role"    => $role,
            "content" => $content
        ];

        $token = ArrayHelper::get(get_option($this->optionKey), 'access_token');

        $result = $this->makeRequest($token, $args);

        if (is_wp_error($result)) {
            wp_send_json_error($failedMessage, 422);
        }

        wp_send_json_success($result, 200);
    }*/
}
