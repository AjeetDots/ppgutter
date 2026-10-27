<?php

namespace FluentFormPro\classes;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Models\Submission;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\App\Services\FormBuilder\Notifications\EmailNotification;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\classes\AdminApproval\AdminApproval;
use FluentFormPro\classes\SharePage\SharePage;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DoubleOptin
{
    private $initialStatusSlug = 'unconfirmed';
    private $confirmedStatusSlug = 'confirmed';

    public function init()
    {
        add_filter('fluentform/entry_statuses_core', function ($statuses, $formId) {
            if ($this->isActivated($formId)) {
                $statuses[$this->initialStatusSlug] = __('Unconfirmed', 'fluentformpro');
                $statuses[$this->confirmedStatusSlug] = __('Confirmed', 'fluentformpro');
            }
            return $statuses;
        }, 10, 2);

        add_filter('fluentform/entry_statuses_for_mutation', function ($statuses) {
            unset($statuses[$this->initialStatusSlug]);
            return $statuses;
        });

        add_filter('fluentform/form_settings_ajax', function ($settings, $formId) {
            if ($optinSettings = $this->getDoubleOptinSettings($formId)) {
                $settings['double_optin'] = $optinSettings;
            }
            return $settings;
        }, 10, 2);

        add_action('fluentform/after_save_form_settings', array($this, 'saveFormSettings'), 10, 2);

        add_action('fluentform/before_form_actions_processing', array($this, 'processOnSubmission'), 10, 3);

        add_action('fluentform/entry_confirmation', array($this, 'confirmSubmission'), 1, 1);

        add_action('wp_ajax_fluentform_get_global_double_optin', array($this, 'getGlobalSettingsAjax'));

        add_action('wp_ajax_fluentform_save_global_double_optin', array($this, 'updateGlobalSettingsAjax'));

        add_action('fluentform_do_email_report_scheduled_tasks', array($this, 'maybeDeleteUnconfirmedEntries'), 99);
        add_action('fluentform/maybe_scheduled_jobs', array($this, 'maybeDeleteUnconfirmedEntries'), 99);

        add_action('fluentform_do_email_report_scheduled_tasks', array($this, 'maybeDeleteOldEntries'));
    }

    public function isActivated($formId = false)
    {
        if (!$formId) {
            return false;
        }
        $settings = $this->getDoubleOptinSettings($formId);

        return $settings && ArrayHelper::get($settings, 'status') == 'yes';
    }

    /**
     * Get all double opt-in statuses
     *
     * @return array
     */
    public function getStatuses()
    {
        return [$this->initialStatusSlug, $this->confirmedStatusSlug];
    }

    public function saveFormSettings($formId, $allSettings)
    {
        $doubleOptinSettings = ArrayHelper::get($allSettings, 'double_optin', []);
        if ($doubleOptinSettings) {
            Helper::setFormMeta($formId, 'double_optin_settings', $doubleOptinSettings);
        }
    }

    public function getDoubleOptinSettings($formId, $scope = 'admin')
    {
        $defaults = [
            'status'                => 'no',
            'confirmation_message'  => __('Please check your email inbox to confirm this submission', 'fluentformpro'),
            'email_body_type'       => 'global',
            'email_subject'         => '',
            'email_body'            => '',
            'email_field'           => '',
            'skip_if_logged_in'     => 'yes',
            'skip_if_fc_subscribed' => 'yes',
            'asPlainText'           => 'no'
        ];

        // Apply translation filters to default messages
        $form = wpFluent()->table('fluentform_forms')->find($formId);
        if ($form) {
            $defaults['confirmation_message'] = apply_filters('fluentform/double_optin_confirmation_message', $defaults['confirmation_message'], [], $form);

            // Apply double opt-in messages filter for all related messages
            $optinMessages = [
                'confirmation_message' => $defaults['confirmation_message'],
                'email_subject' => $defaults['email_subject'],
                'email_body' => $defaults['email_body'],
                'success_message' => __('Thank you for confirming your submission', 'fluentformpro'),
                'error_message' => __('Sorry, we could not verify your submission', 'fluentformpro')
            ];
            $optinMessages = apply_filters('fluentform/double_optin_messages', $optinMessages, [], $form);
            $defaults['confirmation_message'] = $optinMessages['confirmation_message'];
        }

        $settings = \FluentForm\App\Helpers\Helper::getFormMeta($formId, 'double_optin_settings', []);

        if ($settings) {
            $defaults = wp_parse_args($settings, $defaults);
        }

        $globalSettings = $this->getGlobalSettings();

        if ($globalSettings['enabled'] != 'yes') {
            return false;
        }

        if (empty($defaults['email_body']) || $defaults['email_body_type'] == 'global') {
            $defaults['email_body'] = $globalSettings['email_body'];
        }

        if (empty($defaults['email_subject']) || $defaults['email_body_type'] == 'global') {
            $defaults['email_subject'] = $globalSettings['email_subject'];
        }

        if ($scope == 'public') {
            $defaults = wp_parse_args($defaults, $globalSettings);
        }

        return $defaults;
    }

    public function processOnSubmission($insertId, $formData, $form)
    {
        $doubleOptinSettings = $this->getDoubleOptinSettings($form->id, 'public');
        $submission = Submission::find($insertId);
        if (
            !$doubleOptinSettings
            || ArrayHelper::get($doubleOptinSettings, 'status') != 'yes'
            || (ArrayHelper::get($doubleOptinSettings, 'skip_if_logged_in') == 'yes' && get_current_user_id())
            || $submission->status == $this->initialStatusSlug
            || $submission->status == $this->confirmedStatusSlug
            || (new AdminApproval())->isEntryOnAdminApprovalMode($submission->status)
        ) {
            return;
        }

        $emailField = ArrayHelper::get($doubleOptinSettings, 'email_field');
        if (!$emailField) {
            return;
        }
        $emailId = trim(ArrayHelper::get($formData, $emailField));
        if (!$emailId || !is_email($emailId)) {
            return;
        }

        if (ArrayHelper::get($doubleOptinSettings, 'skip_if_fc_subscribed') == 'yes' && defined('FLUENTCRM')) {
            $crmContact = FluentCrmApi('contacts')->getContact($emailId);
            if ($crmContact && $crmContact->status == 'subscribed') {
                return;
            }
        }

        wpFluent()->table('fluentform_submissions')
            ->where('id', $insertId)
            ->update([
                'status' => $this->initialStatusSlug
            ]);


        $data = ArrayHelper::only($doubleOptinSettings, [
            'confirmation_message',
            'email_subject',
            'email_body'
        ]);

        if ($emailBody = ArrayHelper::get($data, 'email_body')) {
            $data['email_body'] = apply_filters('fluentform/submission_message_parse', $emailBody, $insertId, $formData, $form);
        }

        if ($emailSubject = ArrayHelper::get($data, 'email_subject')) {
            $data['email_subject'] = apply_filters('fluentform/submission_message_parse', $emailSubject, $insertId, $formData, $form);
        }

        if ($confirmationMessage = ArrayHelper::get($data, 'confirmation_message')) {
            $confirmationMessage = apply_filters('fluentform/submission_message_parse', $confirmationMessage, $insertId, $formData, $form);
            // Expand trusted shortcodes after submission-aware filters initialize their context.
            $data['confirmation_message'] = do_shortcode($confirmationMessage);
        }

        $data = ShortCodeParser::parse($data, $insertId, $formData);

        // Keep submitter-supplied shortcode brackets literal for the final do_shortcode() call.
        if (isset($data['confirmation_message'])) {
            $data['confirmation_message'] = str_replace(['[', ']'], ['&#91;', '&#93;'], $data['confirmation_message']);
        }

        $emailBody = $data['email_body'];

        $confirmationToken = Helper::getSubmissionMeta($insertId, '_double_optin_hash');
        if (!$confirmationToken) {
            $confirmationToken = md5(wp_generate_uuid4() . $insertId);
            Helper::setSubmissionMeta($insertId, '_double_optin_hash', $confirmationToken, $form->id);
        }

        $confirmationUrl = add_query_arg([
            'ff_landing'         => $form->id,
            'entry_confirmation' => $confirmationToken
        ], home_url());

        $emailBody = str_replace('#confirmation_url#', $confirmationUrl, $emailBody);

        $notification = [
            'name'           => 'Double Optin Email',
            'fromName'       => ArrayHelper::get($doubleOptinSettings, 'fromName'),
            'fromEmail'      => ArrayHelper::get($doubleOptinSettings, 'fromEmail', ''),
            'replyTo'        => ArrayHelper::get($doubleOptinSettings, 'replyTo'),
            'bcc'            => '',
            'subject'        => $data['email_subject'],
            'message'        => $data['email_body'],
            'enabled'        => true,
            'email_template' => '',
            'sendTo'         => [
                'type'  => 'email',
                'email' => $emailId,
                'field' => ''
            ]
        ];

        $emailNotificationClass = new EmailNotification(wpFluentForm());
        $emailHeaders = $emailNotificationClass->getHeaders($notification);

        if (ArrayHelper::get($doubleOptinSettings, 'asPlainText') != 'yes') {
            $emailBody = $emailNotificationClass->getEmailWithTemplate($emailBody, $form, $notification);
        }

        $mailSent = wp_mail($emailId, $data['email_subject'], $emailBody, $emailHeaders);

        do_action('fluentform/log_data', [
            'parent_source_id' => $form->id,
            'source_type'      => 'submission_item',
            'source_id'        => $insertId,
            'component'        => 'DoubleOptin',
            'status'           => 'info',
            'title'            => __('Double Optin Confirmation Email sent', 'fluentformpro'),
            'description'      => __('Double Optin Email sent to form submitter [', 'fluentformpro') . $emailId . ']',
        ]);

        $result = [
            'insert_id'  => $insertId,
            'result'     => [
                'redirectTo' => 'samePage',
                'message'    => do_shortcode($data['confirmation_message']),
                'action'     => 'hide_form'
            ],
            'error'      => '',
            'optin_sent' => $mailSent
        ];

        wp_send_json_success($result, 200);

    }

    private function getGlobalSettings()
    {
        $defaults = [
            'email_body'           => '<h2>Please Confirm Your Submission</h2><p>&nbsp;</p><p style="text-align: center;"><a style="color: #ffffff; background-color: #454545; font-size: 16px; border-radius: 5px; text-decoration: none; font-weight: normal; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;" href="#confirmation_url#">Confirm Submission</a></p><p>&nbsp;</p><p>If you received this email by mistake, simply delete it. Your form submission won\'t proceed if you don\'t click the confirmation link above.</p>',
            'email_subject'        => __('Please confirm your form submission', 'fluentformpro'),
            'fromName'             => '',
            'fromEmail'            => '',
            'replyTo'              => '',
            'enabled'              => 'yes',
            'auto_delete_status'   => 'yes',
            'auto_delete_day_span' => 5,
            'asPlainText'          => 'no'
        ];

        if ($settings = get_option('_fluentform_double_optin_settings')) {
            return wp_parse_args($settings, $defaults);
        }

        return $defaults;
    }

    public function confirmSubmission($data)
    {
        $formId = intval(ArrayHelper::get($data, 'ff_landing'));

        $invalidMessage = apply_filters(
            'fluentform/double_optin_invalid_confirmation_url_message',
            __('Sorry! The confirmation URL is invalid or has expired. If you continue to experience issues, please contact the site administrator for assistance.', 'fluentformpro')
        );

        if (!$this->isActivated($formId)) {
            die(wp_kses_post($invalidMessage));
        }

        $hash = sanitize_text_field(ArrayHelper::get($data, 'entry_confirmation'));

        $meta = wpFluent()->table('fluentform_submission_meta')
            ->where('form_id', $formId)
            ->whereIn('meta_key', ['_double_optin_hash', '_entry_uid_hash'])
            ->where('value', $hash)
            ->first();

        // Every entries viewer can read the entry hash, so it only confirms links mailed before the opt-in token existed.
        if (!$meta || ('_entry_uid_hash' === $meta->meta_key && Helper::getSubmissionMeta($meta->response_id, '_double_optin_hash'))) {
            die(wp_kses_post($invalidMessage));
        }
        $insertId = $meta->response_id;

        $entry = wpFluent()->table('fluentform_submissions')
            ->where('form_id', $formId)
            ->where('id', $insertId)
            ->first();

        if (!$entry) {
            die(wp_kses_post($invalidMessage));
        }

        // Check if the confirmation has already been completed
        if ($entry->status == $this->confirmedStatusSlug && Helper::getSubmissionMeta($insertId, 'is_form_action_fired') == 'yes') {
            $alreadyConfirmedMessage = apply_filters(
                'fluentform/double_optin_already_confirmed_message',
                __('The confirmation of the double opt-in has already been carried out and can only be done once. If you continue to experience issues, please contact the site administrator for assistance.', 'fluentformpro')
            );
            die(wp_kses_post($alreadyConfirmedMessage));
        }

        $submissionData = json_decode($entry->response, true);
        $form = wpFluent()->table('fluentform_forms')->find($formId);

        // Double opt-in with admin approval, admin approval always run after double opt-in confirmation.
        // If submission already on admin approval mode, render admin approval status on double Opt-in confirmation page.
        $adminApproval = new AdminApproval();
        if ($adminApproval->isEntryOnAdminApprovalMode($entry->status)) {
            $adminApproval->renderApprovalConfirmationView($entry, $form, $submissionData);
            return;
        }

        $isAwaitingConfirmation = $entry->status == $this->initialStatusSlug
            && Helper::getSubmissionMeta($insertId, 'is_form_action_fired') != 'yes';
        $shouldProcessActions = $isAwaitingConfirmation
            && apply_filters('fluentform/should_process_submission_actions', true, $entry, $form);
        $wasVetoed = $isAwaitingConfirmation && !$shouldProcessActions;

        $claimed = $shouldProcessActions && $this->claimForConfirmation($insertId);
        $actionsCompleted = false;

        if ($claimed) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $form->id,
                'source_type'      => 'submission_item',
                'source_id'        => $insertId,
                'component'        => 'DoubleOptin',
                'status'           => 'info',
                'title'            => __('Double Optin Confirmed', 'fluentformpro'),
                'description'      => __('Form submitter confirmed the double optin email from IP address: ', 'fluentformpro') . wpFluentForm()->request->getIp(),
            ]);

            $completionHook = 'fluentform/submission_inserted_' . $form->type . '_form';
            $markActionsCompleted = function () use (&$actionsCompleted) {
                $actionsCompleted = true;
            };
            add_action($completionHook, $markActionsCompleted, PHP_INT_MAX);
            try {
                $confirmation = (new SubmissionHandlerService())->processSubmissionData($insertId, $submissionData, $form);
            } finally {
                remove_action($completionHook, $markActionsCompleted, PHP_INT_MAX);
            }
            $result = $confirmation['result'];
        } else {
            $result = (new SubmissionHandlerService())->getReturnData($insertId, $form, $submissionData);
        }

        if (
            $wasVetoed
            || ($shouldProcessActions && !$claimed)
            || ($claimed && !$actionsCompleted)
            || (
                Helper::getSubmissionMeta($insertId, 'is_form_action_fired') != 'yes'
                && ($claimed || $entry->status == $this->confirmedStatusSlug)
            )
        ) {
            $result = [
                'message' => __('The confirmation was received, but submission processing has not completed. Please contact the site administrator.', 'fluentformpro'),
                'action'  => 'hide_form'
            ];
        }

        if ($redirectUrl = ArrayHelper::get($result, 'redirectUrl')) {
            wp_redirect($redirectUrl);
            exit();
        }

        $sharePage = new SharePage();
        $settings = $sharePage->getSettings($formId);

        $message = ShortCodeParser::parse($result['message'], $insertId, $submissionData);
        // Trusted shortcodes already expanded upstream (getReturnData); keep submitter-supplied
        // brackets literal for the landing page's final do_shortcode() call.
        $message = str_replace(['[', ']'], ['&#91;', '&#93;'], $message);

        $submissionVars = [
            'settings'        => $settings,
            'title'           => 'Submission Confirmed - ' . $form->title,
            'form_id'         => $formId,
            'entry'           => $entry,
            'form'            => $form,
            'bg_color'        => $settings['custom_color'],
            'landing_content' => $message,
            'has_header'      => false,
            'isEmbeded'       => !!ArrayHelper::get($_GET, 'embedded')
        ];
        $submissionVars = apply_filters_deprecated(
            'fluentform_submission_vars',
            [
                $submissionVars,
                $formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/submission_vars',
            'Use fluentform/submission_vars instead of fluentform_submission_vars.'
        );
        // We have to show the message now
        $landingVars = apply_filters('fluentform/submission_vars', $submissionVars, $formId);

        $sharePage->loadPublicView($landingVars);
    }

    private function claimForConfirmation($insertId)
    {
        if (Helper::getSubmissionMeta($insertId, 'is_form_action_fired') == 'yes') {
            return false;
        }

        return (bool) wpFluent()->table('fluentform_submissions')
            ->where('id', $insertId)
            ->where('status', $this->initialStatusSlug)
            ->update(['status' => $this->confirmedStatusSlug]);
    }

    public function getGlobalSettingsAjax()
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager');

        wp_send_json_success([
            'settings' => $this->getGlobalSettings()
        ], 200);
    }

    public function updateGlobalSettingsAjax()
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager');
        $settings = wp_unslash($_REQUEST['settings']);
        if (is_array($settings)) {
            $sanitizeMap = [
                'email_subject'        => 'sanitize_text_field',
                'email_body'           => 'wp_kses_post',
                'fromName'             => 'sanitize_text_field',
                'fromEmail'            => 'sanitize_email',
                'replyTo'              => 'sanitize_email',
                'enabled'              => 'sanitize_text_field',
                'auto_delete_status'   => 'sanitize_text_field',
                'auto_delete_day_span' => 'intval',
                'asPlainText'          => 'sanitize_text_field',
            ];
            $settings = fluentform_backend_sanitizer($settings, $sanitizeMap);
        } else {
            $settings = sanitize_text_field($settings);
        }
        update_option('_fluentform_double_optin_settings', $settings, 'no');
        wp_send_json_success([
            'message' => 'Settings successfully updated'
        ], 200);
    }

    public function maybeDeleteUnconfirmedEntries()
    {
        $this->maybeDeleteOldEntries();

        $settings = $this->getGlobalSettings();
        if (
            ArrayHelper::get($settings, 'auto_delete_status') != 'yes' ||
            ArrayHelper::get($settings, 'enabled') != 'yes'
        ) {
            return;
        }

        $daySpan = intval(ArrayHelper::get($settings, 'auto_delete_day_span'));
        if (!$daySpan) {
            $daySpan = 7;
        }
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
        $date = date('Y-m-d H:i:s', (time() - $daySpan * DAY_IN_SECONDS));

        $oldEntries = wpFluent()->table('fluentform_submissions')
            ->where('status', $this->initialStatusSlug)
            ->where('created_at', '<', $date)
            ->limit(100)
            ->get();

        if (count($oldEntries)) {
            $this->deleteEntries($oldEntries);
        }
    }

    public function maybeDeleteOldEntries()
    {
        $autoDeleteFormMetas = wpFluent()->table('fluentform_form_meta')
            ->where('meta_key', 'auto_delete_days')
            ->get();

        if (!count($autoDeleteFormMetas)) {
            return;
        }

        $formGroups = [];

        foreach ($autoDeleteFormMetas as $meta) {
            if (!isset($formGroups[$meta->value])) {
                $formGroups[$meta->value] = [];
            }
            $formGroups[$meta->value][] = $meta->form_id;
        }

        foreach ($formGroups as $delayDays => $formIds) {
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
            $date = date('Y-m-d H:i:s', (time() - $delayDays * DAY_IN_SECONDS));
            $oldEntries = wpFluent()->table('fluentform_submissions')
                ->whereIn('form_id', $formIds)
                ->where('created_at', '<', $date)
                ->limit(100)
                ->get();
            $this->deleteEntries($oldEntries);
        }
    }

    // @todo need to move on helper class
    public function deleteEntries($entries)
    {
        if (!count($entries)) {
            return;
        }

        $deletingIds = [];
        foreach ($entries as $entry) {
            $deletingIds[] = $entry->id;
            $this->deleteAssociateFiles($entry);
        }

        $deletingIds = array_filter($deletingIds);

        if (!$deletingIds) {
            return;
        }

        wpFluent()->table('fluentform_submissions')
            ->whereIn('id', $deletingIds)
            ->delete();


        wpFluent()->table('fluentform_submission_meta')
            ->whereIn('response_id', $deletingIds)
            ->delete();

        wpFluent()->table('fluentform_logs')
            ->whereIn('source_id', $deletingIds)
            ->where('source_type', 'submission_item')
            ->delete();

        wpFluent()->table('fluentform_entry_details')
            ->whereIn('submission_id', $deletingIds)
            ->delete();

        ob_start();

        try {

            wpFluent()->table('fluentform_order_items')
                ->whereIn('submission_id', $deletingIds)
                ->delete();

            wpFluent()->table('fluentform_transactions')
                ->whereIn('submission_id', $deletingIds)
                ->delete();

            wpFluent()->table('ff_scheduled_actions')
                ->whereIn('origin_id', $deletingIds)
                ->where('type', 'submission_action')
                ->delete();

        } catch (\Exception $exception) {
            // ...
        }

        $errors = ob_get_clean();
    }

    private function deleteAssociateFiles($entry)
    {
        $status = apply_filters_deprecated(
            'fluentform_disable_attachment_delete',
            [
                false,
                $entry->form_id
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/disable_attachment_delete',
            'Use fluentform/disable_attachment_delete instead of fluentform_disable_attachment_delete.'
        );
        if (apply_filters('fluentform/disable_attachment_delete', $status, $entry->form_id)) {
            return;
        }

        $fileFields = $this->getFileFields($entry->form_id);
        if (!$fileFields) {
            return;
        }

        $data = json_decode($entry->response, true);

        foreach ($fileFields as $field) {
            if (!empty($data[$field])) {
                $files = $data[$field];
                if (!is_array($files)) {
                    $files = [$files];
                }
                foreach ($files as $file) {
                    $file = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR . '/' . basename($file);
                    if (is_readable($file) && !is_dir($file)) {
                        wp_delete_file($file);
                    }
                }
            }
        }
    }

    private function getFileFields($formId)
    {
        static $fieldsCache = [];
        if (isset($formsCache[$formId])) {
            return $formsCache;
        }

        $form = wpFluent()->table('fluentform_forms')->find($formId);

        if (!$form) {
            return [];
        }

        $fields = FormFieldsParser::getAttachmentInputFields($form, ['element', 'attributes']);

        $filesFieldNames = [];
        foreach ($fields as $field) {
            if (!empty($field['attributes']['name'])) {
                $filesFieldNames[] = $field['attributes']['name'];
            }
        }

        $fieldsCache[$formId] = $filesFieldNames;

        return $fieldsCache[$formId];

    }
}
