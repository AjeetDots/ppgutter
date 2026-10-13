<?php

namespace FluentFormPro\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;

class SaveProgressButton extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'save_progress_button',
            'Save & Resume',
            ['save', 'button', 'progress'],
            'advanced'
        );
        $this->updateExistingFields();
        
        add_action('wp_enqueue_scripts', function () {
            $vars = apply_filters('fluentform/save_progress_vars', [
                'source_url'            => home_url($_SERVER['REQUEST_URI']),
                'nonce'                 => wp_create_nonce(),
                'copy_button'           => sprintf("<img src='%s' >", fluentformMix('img/copy.svg')),
                'copy_success_button'   => sprintf("<img src='%s' >", fluentformMix('img/check.svg')),
                'email_button'          => sprintf("<img src='%s' >", fluentformMix('img/email.svg')),
                'email_placeholder_str' => __('Your Email Here', 'fluentformpro'),
            ]);
            wp_localize_script('form-save-progress', 'form_state_save_vars', $vars);
        });
    }
    
    public function pushFormInputType($types)
    {
        return $types;
    }
    
    function getComponent()
    {
        return [
            'index'          => 15,
            'element'        => $this->key,
            'attributes'     => [
                'class' => '',
                'name' => 'save_progress_button'
            ],
            'settings'       => [
                'button_style'       => 'default',
                'button_size'        => 'md',
                'align'              => 'left',
                'container_class'    => '',
                'current_state'      => 'normal_styles',
                'background_color'   => 'rgb(26, 126, 251)',
                'color'              => 'rgb(255, 255, 255)',
                'hover_styles'       => (object)[
                    'backgroundColor' => '#ffffff',
                    'borderColor'     => '#1a7efb',
                    'color'           => '#1a7efb',
                    'borderRadius'    => '',
                    'minWidth'        => '100%'
                ],
                'normal_styles'      => (object)[
                    'backgroundColor' => '#1a7efb',
                    'borderColor'     => '#1a7efb',
                    'color'           => '#ffffff',
                    'borderRadius'    => '',
                    'minWidth'        => ''
                ],
                'button_ui'          => (object)[
                    'text'    => __('Save & Resume', 'fluentformpro'),
                    'type'    => 'default',
                    'img_url' => ''
                ],
                'conditional_logics' => [],
                'save_resume_for_logged_in_user' => false,
                'email_resume_link_enabled' => true,
                'use_link_once'             => false,
                'save_success_message' => __('Your progress has been successfully saved. You can access the form anytime by copying the link or get the resume form link to your email from below.','fluentformpro'),
                // translators: %s is the form name
                'email_subject' => sprintf(__('Resume Form Submission : %s','fluentformpro'), '{form_name}'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'email_body'    => __(self::getEmailBody(), 'fluentformpro'),
                'send_email_on_entry_create' => false,
                'on_create_email_address' => get_option('admin_email'),
                // translators: %1$s is the form name, %2$s is the partial entry ID
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'on_create_email_subject' => sprintf(__('A Partial entry created on form: %1$s and partial entry ID: %2$s','fluentformpro'), '{form_name}', '{partial_entry_id}'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'on_create_email_body'    => __(self::getAdminPartialEntryEmailBody('created'), 'fluentformpro'),
                'send_email_on_entry_update' => false,
                'on_update_email_address' => get_option('admin_email'),
                // translators: %1$s is the form name, %2$s is the partial entry ID
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'on_update_email_subject' => sprintf(__('A Partial entry updated on form: %1$s and partial entry ID: %2$s','fluentformpro'), '{form_name}', '{partial_entry_id}'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'on_update_email_body'    => __(self::getAdminPartialEntryEmailBody('updated'), 'fluentformpro'),
            ],
            'editor_options' => [
                'title'      => $this->title,
                'icon_class' => 'dashicons dashicons-arrow-right-alt',
                'template'   => 'customButton'
            ],
        ];
    }
    
    public function pushConditionalSupport($conditonalItems)
    {
        return $conditonalItems;
    }
    
    
    public function getGeneralEditorElements()
    {
        return [
            'btn_text',
            'button_ui',
            'button_style',
            'button_size',
            'align',
            'save_success_message',
            'save_resume_for_logged_in_user',
            'email_resume_link_enabled',
            'email_subject',
            'email_body',
            'use_link_once',
        ];
    }
    
    public function getAdvancedEditorElements()
    {
        return [
            'container_class',
            'class',
            'name',
            'conditional_logics',
            'send_email_on_entry_create',
            'on_create_email_address',
            'on_create_email_subject',
            'on_create_email_body',
            'send_email_on_entry_update',
            'on_update_email_address',
            'on_update_email_subject',
            'on_update_email_body',
        ];
    }

    public function generalEditorElement()
    {
        return [
            'save_success_message' => [
                'template'  => 'inputTextarea',
                'label'     => __('Success Message', 'fluentformpro'),
                'help_text'  => __('Message to show after saving form state.', 'fluentformpro'),

            ],
            'save_resume_for_logged_in_user' => [
                'template'  => 'radioButton',
                'label'     => __('Allow Only For Logged In User', 'fluentformpro'),
                'help_text' => __('Only logged in user can see and use save and resume', 'fluentformpro'),
                'options'   => [
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
            'email_resume_link_enabled' => [
                'template'  => 'radioButton',
                'label'     => __('Enable Email Link', 'fluentformpro'),
                'help_text' => __('Allow User to Email Resume Link', 'fluentformpro'),
                'options'   => [
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
            'email_subject' => [
                'template'  => 'inputText',
                'label'     => __('Email Subject', 'fluentformpro'),
                // translators: %s is the placeholder name
                'help_text'  => sprintf(__('Use %s placeholder to get the Form Name.', 'fluentformpro'),'{form_name}'),
                'dependency' => [
                    'depends_on' => 'settings/email_resume_link_enabled',
                    'value'      => true,
                    'operator'   => '==',
                ],
            ],
            'email_body' => [
                'template'  => 'inputHTML',
                'label'     => __('Email Body', 'fluentformpro'),
                'hide_extra' => 'yes',
                // translators: %s is the placeholder name
                'inline_help_text'  => sprintf(__('Use %s placeholder to get the email resume link.', 'fluentformpro'),'{email_resume_link}'),
                'dependency' => [
                    'depends_on' => 'settings/email_resume_link_enabled',
                    'value'      => true,
                    'operator'   => '==',
                ],
            ],
            'use_link_once' => [
                'template'  => 'radioButton',
                'label'     => __('Allow use the link only once', 'fluentformpro'),
                'help_text' => __('Use the link only once after that no one can use it.', 'fluentformpro'),
                'options'   => [
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
        ];
    }

    public function advancedEditorElement()
    {
        return [
            'send_email_on_entry_create'  => [
                'template'  => 'radioButton',
                'label'     => __('Send Email on Partial Entry Created', 'fluentformpro'),
                'help_text' => __('Send Email on Partial Entry Created', 'fluentformpro'),
                'options'   => [
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
            'on_create_email_address'     => [
                'template'   => 'inputText',
                'label'      => __('Email Address', 'fluentformpro'),
                'help_text'  => __('Leaving it empty will send to Email to the Admin', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/send_email_on_entry_create',
                    'value'      => true,
                    'operator'   => '==',
                ],
            ],
            'on_create_email_subject'     => [
                'template'   => 'inputText',
                'label'      => __('Email Subject', 'fluentformpro'),
                // translators: %s is the placeholder name
                'help_text'  => sprintf(__('Use %s placeholder to get the Form Name.', 'fluentformpro'), '{form_name}'),
                'dependency' => [
                    'depends_on' => 'settings/send_email_on_entry_create',
                    'value'      => true,
                    'operator'   => '==',
                ],
            ],
            'on_create_email_body'        => [
                'template'         => 'inputHTML',
                'label'            => __('Email Body', 'fluentformpro'),
                'hide_extra'       => 'yes',
                // translators: %s is the placeholder name
                'inline_help_text' => sprintf(__('Use %s placeholder to get the partial entry details.', 'fluentformpro'),
                    '{partial_entry_link}'),
                'dependency'       => [
                    'depends_on' => 'settings/send_email_on_entry_create',
                    'value'      => true,
                    'operator'   => '==',
                ],
            ],
            'send_email_on_entry_update'  => [
                'template'  => 'radioButton',
                'label'     => __('Send Email on Partial Entry Updated', 'fluentformpro'),
                'help_text' => __('Send Email on Partial Entry Updated', 'fluentformpro'),
                'options'   => [
                    [
                        'value' => true,
                        'label' => __('Yes', 'fluentformpro'),
                    ],
                    [
                        'value' => false,
                        'label' => __('No', 'fluentformpro'),
                    ],
                ]
            ],
            'on_update_email_address'     => [
                'template'   => 'inputText',
                'label'      => __('Email Address', 'fluentformpro'),
                'help_text'  => __('Leaving it empty will send to Email to the Admin', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/send_email_on_entry_update',
                    'value'      => true,
                    'operator'   => '==',
                ],
            ],
            'on_update_email_subject'     => [
                'template'   => 'inputText',
                'label'      => __('Email Subject', 'fluentformpro'),
                // translators: %s is the placeholder name
                'help_text'  => sprintf(__('Use %s placeholder to get the Form Name.', 'fluentformpro'), '{form_name}'),
                'dependency' => [
                    'depends_on' => 'settings/send_email_on_entry_update',
                    'value'      => true,
                    'operator'   => '==',
                ],
            ],
            'on_update_email_body'        => [
                'template'         => 'inputHTML',
                'label'            => __('Email Body', 'fluentformpro'),
                'hide_extra'       => 'yes',
                // translators: %s is the placeholder name
                'inline_help_text' => sprintf(__('Use %s placeholder to get the partial entry details.', 'fluentformpro'),
                    '{partial_entry_link}'),
                'dependency'       => [
                    'depends_on' => 'settings/send_email_on_entry_update',
                    'value'      => true,
                    'operator'   => '==',
                ],
            ]
        ];
    }

    public function render($data, $form)
    {
        if (
            Arr::get($data, 'settings.save_resume_for_logged_in_user')
            && !is_user_logged_in()
        ) {
            return '';
        }

        $elementName = $data['element'];
        $data = apply_filters_deprecated(
            'fluentform_rendering_field_data_' . $elementName,
            [
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_data_' . $elementName,
            'Use fluentform/rendering_field_data_' . $elementName . ' instead of fluentform_rendering_field_data_' . $elementName
        );
        $data = apply_filters('fluentform/rendering_field_data_' . $elementName, $data, $form);
       
        wp_enqueue_script('form-save-progress');
        
        add_filter('fluentform/form_class', function ($formClass){
            return $formClass .= ' ff-form-has-save-progress';
        });
        
        $btnStyle = Arr::get($data['settings'], 'button_style');
        
        $btnSize = 'ff-btn-';
        $btnSize .= isset($data['settings']['button_size']) ? $data['settings']['button_size'] : 'md';
        $oldBtnType = isset($data['settings']['button_style']) ? '' : ' ff-btn-primary ';
        
        $align = 'ff-el-group ff-text-' . @$data['settings']['align'];
        
        $btnClasses = [
            'ff-btn ff-btn-save-progress',
            $oldBtnType,
            $btnSize,
            $data['attributes']['class']
        ];
        
        if($btnStyle == 'no_style') {
            $btnClasses[] = 'ff_btn_no_style';
        } else {
            $btnClasses[] = 'ff_btn_style';
        }
        
        $data['attributes']['class'] = trim(implode(' ', array_filter($btnClasses)));
        
        if($tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex()) {
            $data['attributes']['tabindex'] = $tabIndex;
        }
        $styles = '';
        if (Arr::get($data, 'settings.button_style') == '') {
            $data['attributes']['class'] .= ' wpf_has_custom_css';
            // it's a custom button
            $buttonActiveStyles = Arr::get($data, 'settings.normal_styles', []);
            $buttonHoverStyles = Arr::get($data, 'settings.hover_styles', []);
            
            $activeStates = '';
            foreach ($buttonActiveStyles as $styleAtr => $styleValue) {
                if (!$styleValue) {
                    continue;
                }
                if ($styleAtr == 'borderRadius') {
                    $styleValue .= 'px';
                }
                $activeStates .= ltrim(strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/', '-$0', $styleAtr)), '_') . ':' . $styleValue . ';';
            }
            if ($activeStates) {
                $styles .= 'form.fluent_form_' . $form->id . ' .wpf_has_custom_css.ff-btn-save-progress { ' . $activeStates . ' }';
            }
            $hoverStates = '';
            foreach ($buttonHoverStyles as $styleAtr => $styleValue) {
                if (!$styleValue) {
                    continue;
                }
                if ($styleAtr == 'borderRadius') {
                    $styleValue .= 'px';
                }
                $hoverStates .= ltrim(strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/', '-$0', $styleAtr)), '-') . ':' . $styleValue . ';';
            }
            if ($hoverStates) {
                $styles .= 'form.fluent_form_' . $form->id . ' .wpf_has_custom_css.ff-btn-save-progress:hover { ' . $hoverStates . ' } ';
            }
        } else if($btnStyle != 'no_style') {
            $styles .= 'form.fluent_form_' . $form->id . ' .ff-btn-save-progress { background-color: ' . Arr::get($data, 'settings.background_color') . '; color: ' . Arr::get($data, 'settings.color') . '; }';
        }

        if (Arr::get($data, 'settings.email_resume_link_enabled')) {
            $data['attributes']['class'] .= ' ff_resume_email_enabled';
        }
        
        $atts = $this->buildAttributes($data['attributes']);
        $hasConditions = $this->hasConditions($data) ? 'has-conditions ' : '';
        $cls = trim($align . ' ' . $data['settings']['container_class'] . ' ' . $hasConditions);
        
        $html = "<div class='" . esc_attr($cls) . " ff_submit_btn_wrapper ff_submit_btn_wrapper_custom'>";
        
        // ADDED IN v1.2.6 - updated in 1.4.4
        if (isset($data['settings']['button_ui'])) {
            if ($data['settings']['button_ui']['type'] == 'default') {
                $buttonText = apply_filters('fluentform/save_progress_button_text', $data['settings']['button_ui']['text'], $form);
                $html .= '<button ' . $atts . '>' . fluentform_sanitize_html($buttonText) . '</button>';
            } else {
                $html .= "<button class='ff-btn-save-progress' type='submit'><img style='max-width: 200px;' src='" . esc_url($data['settings']['button_ui']['img_url']) . "' alt='" . esc_attr__('Submit Form', 'fluentformpro') . "'></button>";
            }
        } else {
            $buttonText = apply_filters('fluentform/save_progress_button_text', $data['settings']['btn_text'], $form);
            $html .= '<button ' . $atts . '>' . fluentform_sanitize_html($buttonText) . '</button>';
        }
        
        $styles = fluentformSanitizeCSS($styles);
        if ($styles) {
            $html .= '<style>' . $styles . '</style>';
        }
        
        $html .= '</div>';
    
        $html = apply_filters_deprecated(
            'fluentform_rendering_field_html_' . $elementName,
            [
                $html,
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_html_' . $elementName,
            'Use fluentform/rendering_field_html_' . $elementName . ' instead of fluentform_rendering_field_html_' . $elementName
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form);
    }
    
    private function getEmailBody()
    {
        return '<p>Hello,</p>
                <p>Your Progress has been saved as you have not completed filling out the {form_name}. Continue where you left off using the link below.</p>
                <p><a style="color: #ffffff; background-color: #3f9eff; text-decoration: none; font-weight: normal; font-style: normal; padding: 0.5rem 1rem; border-color: #0072ff;"
                       href="{email_resume_link}">
                        Resume Form
                    </a></p>
                <p>Thank you</p>';
    }

    private function getAdminPartialEntryEmailBody($action)
    {
        return "<p>Hello,</p>
                <p>A partial submission has been {$action} on {form_name}. See the submission using the link below.</p>
                <p>
                    <a style='color: #ffffff; background-color: #3f9eff; text-decoration: none; font-weight: normal; font-style: normal; padding: 0.5rem 1rem; border-color: #0072ff;' href='{partial_entry_link}'>View partial submission</a>
                </p>
                <p>Thank you</p>";
    }
    
    private function updateExistingFields()
    {
        $nameIndex = 0;
        add_filter('fluentform/editor_init_element_save_progress_button', function ($element) use (&$nameIndex) {
            if (!isset($element['settings']['email_resume_link_enabled'])) {
                $element['settings']['email_resume_link_enabled'] = false;
            }
            if (!isset($element['settings']['save_success_message'])) {
                $element['settings']['save_success_message'] = __('Your progress has been successfully saved. You can access the form anytime by copying the link or get the resume form link to your email from below.', 'fluentformpro');
            }
            if (!isset($element['settings']['email_subject'])) {
                // translators: %s is the form name
                $element['settings']['email_subject'] = sprintf(__('Resume Form Submission : %s',
                    'fluentformpro'), '{form_name}');
            }
            if (!isset($element['settings']['email_body'])) {
                $element['settings']['email_body'] = $this->getEmailBody();
            }
            if (!isset($element['settings']['save_resume_for_logged_in_user'])) {
                $element['settings']['save_resume_for_logged_in_user'] = false;
            }
            if (!isset($element['settings']['send_email_on_entry_create'])) {
                $element['settings']['send_email_on_entry_create'] = false;
            }
            if (!isset($element['settings']['on_create_email_address'])) {
                $element['settings']['on_create_email_address'] = get_option('admin_email');
            }
            if (!isset($element['settings']['on_create_email_subject'])) {
                // translators: %1$s is the form name, %2$s is the partial entry ID
                $element['settings']['on_create_email_subject'] = sprintf(__('A Partial entry created on form: %1$s and partial entry ID: %2$s','fluentformpro'), '{form_name}', '{partial_entry_id}');
            }
            if (!isset($element['settings']['on_create_email_body'])) {
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from method
                $element['settings']['on_create_email_body'] = __(self::getAdminPartialEntryEmailBody('created'), 'fluentformpro');
            }
            if (!isset($element['settings']['send_email_on_entry_update'])) {
                $element['settings']['send_email_on_entry_update'] = false;
            }
            if (!isset($element['settings']['on_update_email_address'])) {
                $element['settings']['on_update_email_address'] = get_option('admin_email');
            }
            if (!isset($element['settings']['on_update_email_subject'])) {
                // translators: %1$s is the form name, %2$s is the partial entry ID
                $element['settings']['on_update_email_subject'] = sprintf(__('A Partial entry updated on form: %1$s and partial entry ID: %2$s','fluentformpro'), '{form_name}', '{partial_entry_id}');
            }
            if (!isset($element['settings']['on_update_email_body'])) {
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from method
                $element['settings']['on_update_email_body'] = __(self::getAdminPartialEntryEmailBody('updated'), 'fluentformpro');
            }
            if (!isset($element['attributes']['name'])) {
                $element['attributes']['name'] = 'save_progress_button' . ($nameIndex > 0 ? '_' . $nameIndex : '');
                $nameIndex++;
            }
            if (!isset($element['settings']['use_link_once'])) {
                $element['settings']['use_link_once'] = false;
            }
            return $element;
        });
    }
}
