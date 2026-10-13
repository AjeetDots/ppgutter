<?php

namespace FluentFormPro\classes\AdminApproval;

defined('ABSPATH') or die;

use FluentForm\Framework\Support\Arr;

class GlobalSettings extends AdminApproval
{
    
    public $optionKey = 'ff_admin_approval';
    
    public function __construct()
    {
        add_filter('fluentform/global_settings_components', [$this, 'addGlobalMenu'],10,1);
        add_filter('fluentform/global_integration_fields_' . $this->optionKey, array($this, 'getSettingsFields'), 10, 1);
        add_action('fluentform/save_global_integration_settings_' . $this->optionKey, array($this, 'saveSettings'), 10, 1);
        add_filter('fluentform/global_integration_settings_' . $this->optionKey, array($this, 'getSettings'), 10);
    }
    
    public function addGlobalMenu($setting)
    {
        $setting[$this->globalModule] = [
            'hash'           => $this->globalModule,
            'component'      => 'general-integration-settings',
            'settings_key'   => $this->optionKey,
            'title'          => __('Admin Approval', 'fluentformpro'),
            'top_level_menu' => true,
        ];
    
        return $setting;
    }
    
    public function getSettingsFields($fields)
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager');
        return [
            'logo'             => FLUENTFORMPRO_DIR_URL . 'public/images/google_map.png',
            'menu_title'       => __('Admin Approval Settings', 'fluentformpro'),
            'menu_description' => __(
                'Review & Approve submissions to ensure that submitted data meets your requirements, get notified instantly.',
                'fluentformpro'
            ),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'valid_message'    => __('Admin Approval is currently active', 'fluentformpro'),
            'invalid_message'  => __('Admin Approval is currently not active', 'fluentformpro'),
            'fields'           => [
                'email_notification'   => [
                    'type'        => 'radio_choice',
                    'placeholder' => '',
                    'label'       => __('Send Email Notification', 'fluentformpro'),
                    'options'     => [
                        'admin_email'  => __('Site Admin', 'fluentformpro'),
                        'custom_email' => __('Custom Email', 'fluentformpro')
                    ]
                ],
                'custom_recipients'         => [
                    'type'        => 'text',
                    'placeholder' => __('For multiple email addresses, use comma to separate them.', 'fluentformpro'),
                    'label'       => __('Enter Recipient Email Address', 'fluentformpro'),
                    'dependency'  => [
                        [
                            'depends_on' => 'email_notification',
                            'value'      => 'custom_email',
                            'operator'   => '==',
                        ],
                    
                    ],
                ],
                'email_subject'         => [
                    'type'        => 'text',
                    'placeholder' => __('Subject', 'fluentformpro'),
                    'label'       => __('Email Subject', 'fluentformpro'),
                ],
                'email_body'           => [
                    'type'       => 'wp_editor',
                    'label'      => __('Email Body', 'fluentformpro'),
                    'info'       => __(
                        "Use <b>#approve_link#</b> for approve, <b>#declined_link#</b> for declined, <b>{all_data}</b> for all Data and <b>{submission.admin_view_url}</b> for submission link",
                        'fluentformpro'
                    ),
                ],
                'asPlainText'          => [
                    'type'           => 'checkbox-single',
                    'checkbox_label' => __('Send Email as RAW HTML Format', 'fluentformpro'),
                ],
                'auto_delete_status'   => [
                    'type'           => 'checkbox_yes_no',
                    'checkbox_label' => __(
                        'Automatically delete  Unapproved & Declined entries if not confirmed in certain days',
                        'fluentformpro'
                    ),
                ],
                'auto_delete_day_span' => [
                    'type'       => 'input_number',
                    'label'      => __('Waiting Days', 'fluentformpro'),
                    'dependency' => [
                        [
                            'depends_on' => 'auto_delete_status',
                            'value'      => 'yes',
                            'operator'   => '==',
                        ]
                    ],
                ],
            ],
            'hide_on_valid'    => false,
            'discard_settings' => false
        ];
    }
    
    public function saveSettings($settings)
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager');
        update_option($this->optionKey, [
            'status'               => boolval($settings['status']),
            'email_notification'   => sanitize_text_field(Arr::get($settings, 'email_notification')),
            'asPlainText'          => Arr::isTrue($settings, 'asPlainText'),
            'auto_delete_status'   => sanitize_text_field(Arr::get($settings, 'auto_delete_status')),
            'auto_delete_day_span' => (int)(Arr::get($settings, 'auto_delete_day_span')),
            'custom_recipients'    => fluentFormSanitizer(Arr::get($settings, 'custom_recipients')),
            'email_body'           => fluentform_sanitize_html(Arr::get($settings, 'email_body')),
            'email_subject'        => sanitize_text_field(Arr::get($settings, 'email_subject')),
        ], 'no');
        
        wp_send_json_success([
            'message' => __('Admin Approval settings has been saved', 'fluentformpro'),
            'status'  => true
        ], 200);
    }
    
    public function getSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);
        $defaults = [
            'status'               => false,
            'email_notification'   => 'admin_email',
            'custom_recipients'    => '',
            'email_subject'        => 'Submission pending for Approval : {form_title}',
            'email_body'           => '<p>Hello There,</p><p>A new submission is pending approval. Please review and take the necessary action. Click this <a href="{submission.admin_view_url}"><span style="text-decoration: underline;">link</span></a> to view submission details. You can approve or decline the submission.</p><p>{all_data}</p><p><a style="color: #ffffff; background-color: #0072ff; font-size: 16px; border-radius: 2px; text-decoration: none; font-weight: normal; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;" href="#approve_link#">Approve</a> <a style="color: #ffffff; background-color: #ff001a; font-size: 16px; border-radius: 2px; text-decoration: none; font-weight: normal; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;" href="#declined_link#">Decline</a></p><p>Thank you</p>',
            'asPlainText'          => false,
            'auto_delete_status'   => 'no',
            'auto_delete_day_span' => '3'
        ];

        if (!$globalSettings) {
            return $defaults;
        }

        if (isset($globalSettings['asPlainText']) && !is_bool($globalSettings['asPlainText'])) {
            $globalSettings['asPlainText'] = Arr::isTrue($globalSettings, 'asPlainText');
        }

        return wp_parse_args($globalSettings, $defaults);
    }
    
}
