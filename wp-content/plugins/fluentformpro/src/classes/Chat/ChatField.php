<?php

namespace FluentFormPro\classes\Chat;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Helpers\Helper;

class ChatField extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'chat',
            'ChatGPT',
            ['chat', 'openai', 'ai']
        );
    }

    public function getComponent()
    {
        return [
            'index'          => 25,
            'element'        => $this->key,
            'attributes'     => [
                'name'        => $this->key,
                'class'       => '',
                'value'       => '',
                'type'        => '',
                'placeholder' => __('Chat with OpenAI ChatGPT', 'fluentformpro')
            ],
            'settings'       => [
                'container_class'       => '',
                'placeholder'           => '',
                'label'                 => $this->title,
                'label_placement'       => '',
                'help_message'          => '',
                'admin_field_label'     => '',
                'validation_rules'      => [
                    'required' => [
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ],
                ],
                'conditional_logics'    => [],
                'disable_submit_button' => false,
                'open_ai_role'          => 'system',
                'open_ai_content'       => __('You are a helpful assistant.', 'fluentformpro'),
                'self_chat_bg_color'    => '#EEF1F6',
                'reply_chat_bg_color'   => '#FBF5F4',
                'failed_message'        => __('An error has been occurred! Please try again.', 'fluentformpro'),
                'show_chat_limit'       => 10,
            ],
            'editor_options' => [
                'title'      => $this->title,
                'icon_class' => 'el-icon-chat-line-square',
                'template'   => 'inputText'
            ],
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'admin_field_label',
            'placeholder',
            'value',
            'label_placement',
            'validation_rules',
            'disable_submit_button',
            'open_ai_role',
            'open_ai_content'
        ];
    }

    public function generalEditorElement()
    {
        return [
            'disable_submit_button' => [
                'template' => 'radio',
                'label'    => __('Disable Submit Button', 'fluentformpro'),
                'options'  => [
                    [
                        'value' => true,
                        'label' => __('Yes', 'fluentformpro'),
                    ],
                    [
                        'value' => false,
                        'label' => __('No', 'fluentformpro'),
                    ],
                ],
            ],
            'open_ai_role'          => [
                'template'  => 'select',
                'label'     => __('Select Role', 'fluentformpro'),
                'help_text' => 'Select a Role Type',
                'options'   => [
                    [
                        'label' => __('System', 'fluentformpro'),
                        'value' => 'system'
                    ],
                    [
                        'label' => __('User', 'fluentformpro'),
                        'value' => 'user'
                    ],
                    [
                        'label' => __('Assistant', 'fluentformpro'),
                        'value' => 'assistant'
                    ],
                ],
            ],
            'open_ai_content'       => [
                'template'  => 'inputTextarea',
                'label'     => __('OpenAI ChatGPT Content', 'fluentformpro'),
                'help_text' => __('How AI gonna Behave?', 'fluentformpro'),
            ]
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'name',
            'help_message',
            'container_class',
            'class',
            'conditional_logics',
            'self_chat_bg_color',
            'reply_chat_bg_color',
            'failed_message',
            'show_chat_limit'
        ];
    }

    public function advancedEditorElement()
    {
        return [
            'self_chat_bg_color'  => [
                'template'  => 'inputText',
                'label'     => __('Self Chat BG Color', 'fluentformpro'),
                'help_text' => __('Set self typed chat background color', 'fluentformpro')
            ],
            'reply_chat_bg_color' => [
                'template'  => 'inputText',
                'label'     => __('Reply Chat BG color', 'fluentformpro'),
                'help_text' => __('Set replied chat background color', 'fluentformpro'),
            ],
            'failed_message'      => [
                'template'  => 'inputText',
                'label'     => __('Failed Message', 'fluentformpro'),
                'help_text' => __('Set failure message if error occurs', 'fluentformpro'),
            ],
            'show_chat_limit'     => [
                'template'  => 'inputText',
                'label'     => __('Show Chat Count', 'fluentformpro'),
                'help_text' => __('Total chat to show in the DOM including self reply', 'fluentformpro')
            ]
        ];
    }

    public function render($data, $form)
    {
        $elementName = $data['element'];

        $data = apply_filters('fluentform/rendering_field_data_' . $elementName, $data, $form);

        $textareaValue = $this->extractValueFromAttributes($data);

        $data['attributes']['class'] = trim('ff-el-form-control ' . $data['attributes']['class']);
        $data['attributes']['id'] = $this->makeElementId($data, $form);

        if ($tabIndex = Helper::getNextTabIndex()) {
            $data['attributes']['tabindex'] = $tabIndex;
        }

        $ariaRequired = 'false';
        if (ArrayHelper::get($data, 'settings.validation_rules.required.value')) {
            $ariaRequired = 'true';
        }

        $data = $this->pushScripts($data, $form);

        $elMarkup = '<textarea aria-invalid="false" aria-required=' . $ariaRequired . ' %s>%s</textarea>';

        $atts = $this->buildAttributes($data['attributes']);

        $elMarkup = sprintf(
            $elMarkup,
            $atts,
            esc_attr($textareaValue)
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $atts is escaped before being passed in.

        $html = $this->buildElementMarkup($elMarkup, $data, $form);

        $this->printContent('fluentform/rendering_field_html_' . $elementName, $html, $data, $form);
    }

    private function pushScripts($data, $form)
    {
        $token = ArrayHelper::get(get_option('_fluentform_openai_settings'), 'access_token');

        $sendSvgIcon = '<svg version="1.1" id="ff_chat_svg" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="32px" height="28px" viewBox="0 0 535.5 535.5" xml:space="preserve" style="fill: #1A7EFB;"><g><g id="send"><polygon points="0,497.25 535.5,267.75 0,38.25 0,216.75 382.5,267.75 0,318.75"/></g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g></svg>';

        $chatSvgIcon = apply_filters('fluentform/chat_field_send_icon', $sendSvgIcon);

        wp_register_script(
            'fluentform-chat-field-script',
            FLUENTFORMPRO_DIR_URL . 'public/js/chatFieldScript.js',
            ['jquery'],
            FLUENTFORMPRO_VERSION,
            true
        );

        wp_enqueue_script('fluentform-chat-field-script');

        wp_localize_script(
            'fluentform-chat-field-script',
            'fluentform_chat',
            [
                'nonce'                 => wp_create_nonce(),
                'content'               => ArrayHelper::get($data, 'settings.open_ai_content'),
                'disable_submit_button' => ArrayHelper::get($data, 'settings.disable_submit_button'),
                'send_svg_icon'         => $chatSvgIcon,
                'self_chat_bg_color'    => ArrayHelper::get($data, 'settings.self_chat_bg_color'),
                'reply_chat_bg_color'   => ArrayHelper::get($data, 'settings.reply_chat_bg_color'),
                'show_chat_limit'       => ArrayHelper::get($data, 'settings.show_chat_limit')
            ]
        );

        $data['attributes']['data-ff-chat-field'] = "true";

        return apply_filters('fluentform/chat_field_before_render', $data, $form);
    }
}
