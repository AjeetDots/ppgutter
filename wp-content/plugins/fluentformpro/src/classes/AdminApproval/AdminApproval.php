<?php

namespace FluentFormPro\classes\AdminApproval;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Helpers\IntegrationManagerHelper;
use FluentForm\App\Models\Form;
use FluentForm\App\Models\Submission;
use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\App\Services\FormBuilder\Notifications\EmailNotification;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\classes\DoubleOptin;
use FluentFormPro\classes\SharePage\SharePage;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class AdminApproval
{
    private $initialStatusSlug = 'unapproved';
    private $approvedStatusSlug = 'approved';
    private $declinedStatusSlug = 'declined';
    public $globalModule = 'admin_approval';
    
    public function init()
    {
        $enabled = $this->isEnabled();
        
        add_filter('fluentform/global_addons', [$this, 'addToIntegrationMenu'], 10, 1);
        
        if (!$enabled) {
            return;
        }
        new GlobalSettings();
        
        add_filter('fluentform/entry_statuses_core', [$this, 'addApprovalStatuses'], 10, 2);
        
        add_filter('fluentform/form_settings_ajax', [$this, 'injectInFormSettings'], 10, 2);
        
        add_action('fluentform/after_save_form_settings', [$this, 'saveFormSettings'], 10, 2);
        
        add_action('fluentform/before_form_actions_processing', [$this, 'processOnSubmission'], 10, 3);
        
        add_action('fluentform/after_submission_status_update', [$this, 'processStatusUpdate'], 10, 2);
        
        add_action('fluentform_do_email_report_scheduled_tasks', [$this, 'maybeDeleteUnApprovedAndDeclinedEntries'], 99);

        add_filter('fluentform/entries_vars',function($data){
            if($status = wpFluentForm('request')->get('submission_status')){
                $data['admin_approval_url_query'] = sanitize_text_field($status);
            }
            return $data;
        },10,1);
       
    }
    
    public function addToIntegrationMenu($addons)
    {
        $addons[$this->globalModule] = [
            'title'       => 'Admin Approval',
            'category'    => 'wp_core',
            'description' => __('Accept or decline form submissions to ensure that submitted data meets your requirements & get notified instantly.', 'fluentformpro'),
            'logo'        =>  fluentformMix('img/integrations/admin_approval.png'),
            'enabled'     => ($this->isEnabled()) ? 'yes' : 'no',
        ];
        return $addons;
    }
    
    public function addApprovalStatuses($statuses, $formId)
    {
        if (!$this->isActivatedInForm($formId)) {
            return $statuses;
        }
        $statuses[$this->initialStatusSlug] = __('Unapproved', 'fluentformpro');
        $statuses[$this->approvedStatusSlug] = __('Approved', 'fluentformpro');
        $statuses[$this->declinedStatusSlug] = __('Declined', 'fluentformpro');
        
        return $statuses;
    }
    
    public function injectInFormSettings($settings, $formId)
    {
        if ($approvalSettings = $this->getAdminApproval($formId)) {
            $settings[$this->globalModule] = $approvalSettings;
        }
        return $settings;
    }
    
    public function saveFormSettings($formId, $allSettings)
    {
        $adminApproval = ArrayHelper::get($allSettings, 'admin_approval', []);
        if ($adminApproval) {
            foreach (['approval_pending_message', 'approval_success_message', 'approval_failed_message'] as $messageKey) {
                if (isset($adminApproval[$messageKey])) {
                    $adminApproval[$messageKey] = wp_kses_post($adminApproval[$messageKey]);
                }
            }
            Helper::setFormMeta($formId, 'admin_approval_settings', $adminApproval);
        }
    }
    
    public function processOnSubmission($insertId, $formData, $form)
    {
        // Payment forms never park here: the gateway (return/webhook) is their sole
        // deferral, and it runs the feeds after payment. Anything relying on approval
        // to gate a paid form must instead hook the payment status update.
        if ($form->has_payment) {
            return;
        }
        $adminApprovalFormSettings = $this->getAdminApproval($form->id, 'public');
        $isInActive = ArrayHelper::get($adminApprovalFormSettings, 'status') != 'yes';
        $skipIfLoggedIn = ArrayHelper::get($adminApprovalFormSettings, 'skip_if_logged_in') == 'yes' && get_current_user_id();
        $submission = Submission::find($insertId);
        if (!$adminApprovalFormSettings || $isInActive || $skipIfLoggedIn || $submission->status == $this->approvedStatusSlug) {
            return;
        }
        $mailSent = $this->sendApprovalPendingAdminEmail($form, $insertId, $formData);
        Submission::where('id', $insertId)->update([
            'status' => $this->initialStatusSlug
        ]);

        // Admin approval with double opt-in, double Opt-in always run before admin approval.
        // If double opt-in active, render admin approval status on double Opt-in confirmation page.
        $doubleOptin = new DoubleOptin();
        if ($doubleOptin->isActivated($form->id) && in_array($submission->status, $doubleOptin->getStatuses())) {
            $this->renderApprovalConfirmationView($submission, $form, $formData);
            return;
        }
        $approvalPendingMessage = ArrayHelper::get($adminApprovalFormSettings, 'approval_pending_message');
        $approvalPendingMessage = ShortCodeParser::parse($approvalPendingMessage, $insertId, $formData);

        // Apply admin approval messages filter
        $approvalMessages = [
            'approval_message' => $approvalPendingMessage,
            'approved_message' => __('Submission verified and approved by Admin', 'fluentformpro'),
            'rejected_message' => __('Submission verified and declined by Admin', 'fluentformpro'),
            'notification_subject' => ArrayHelper::get($adminApprovalFormSettings, 'email_subject', 'Submission Declined'),
            'notification_body' => ArrayHelper::get($adminApprovalFormSettings, 'email_body', '')
        ];
        $approvalMessages = apply_filters('fluentform/admin_approval_messages', $approvalMessages, $formData, $form);

        $result = [
            'insert_id'  => $insertId,
            'result'     => [
                'redirectTo' => 'samePage',
                'message'    => $approvalMessages['approval_message'],
                'action'     => 'hide_form'
            ],
            'error'      => '',
        ];
        wp_send_json_success($result, 200);
    }
    
    public function processStatusUpdate($submissionId, $status)
    {
        if (!$submission = Submission::find($submissionId)) {
            return;
        }
        
        if (!$this->isEnabled()) {
            return;
        }
    
        $form = Form::find($submission->form_id);
        if ($status == $this->approvedStatusSlug) {
            $submissionData = json_decode($submission->response, true);
            $actionFired = Helper::getSubmissionMeta($submission->id, 'is_form_action_fired') == 'yes';
            if (!$actionFired) {
                $this->logData($form, $submission, $status);
                if (apply_filters('fluentform/should_process_submission_actions', true, $submission, $form)) {
                    (new SubmissionHandlerService())->processSubmissionData($submission->id, $submissionData, $form);
                }
            }
        } elseif ($status == $this->declinedStatusSlug) {
    
            $mailSent = Helper::getSubmissionMeta($submission->id, 'is_declined_email_sent') == 'yes';
            if (!$mailSent) {
                $this->maybeSendDeclinedEmail($submission);
                $this->logData($form, $submission, $status);
            }
        }
    }
    
    public function maybeDeleteUnApprovedAndDeclinedEntries()
    {
        $settings = get_option((new GlobalSettings())->optionKey);
        $autoDeleteOff = ArrayHelper::get($settings, 'auto_delete_status') != 'yes';
        if ($autoDeleteOff) {
            return;
        }
        
        $daySpan = intval(ArrayHelper::get($settings, 'auto_delete_day_span'));
        if (!$daySpan) {
            $daySpan = 7;
        }
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
        $date = date('Y-m-d H:i:s', (time() - $daySpan * DAY_IN_SECONDS));
        
        $oldEntries = wpFluent()->table('fluentform_submissions')
            ->whereIn('status', [$this->initialStatusSlug, $this->declinedStatusSlug])
            ->where('created_at', '<', $date)
            ->limit(100)
            ->get();
        
        if (count($oldEntries)) {
            // @todo need to move 'deleteEntries' method on helper class
            (new DoubleOptin())->deleteEntries($oldEntries);
        }
    }

    
    public function getAdminEmail($adminApprovalSettings)
    {
        if (ArrayHelper::get($adminApprovalSettings, 'email_notification') == 'admin_email') {
            $recipients = [get_option('admin_email')];
        } else {
            $custom_recipients = $adminApprovalSettings['custom_recipients'];
            $custom_recipients = explode(',', $custom_recipients);
            $recipients = [];
            foreach ($custom_recipients as $recipient) {
                $recipient = trim($recipient);
                if (is_email($recipient)) {
                    $recipients[] = $recipient;
                }
            }
        }
        return $recipients;
    }
    
    public function isEnabled()
    {
        $helper = new  IntegrationManagerHelper();
        if (method_exists($helper, 'isIntegrationEnabled')) {
            return IntegrationManagerHelper::isIntegrationEnabled($this->globalModule);
        } else {
            //todo: Fallback function support delete this after few version;
            static $globalModules = [];
            if (empty($globalModules)) {
                $globalModules = get_option('fluentform_global_modules_status');
            }
            if (\FluentForm\Framework\Helpers\ArrayHelper::get($globalModules, $this->globalModule) == 'yes') {
                return true;
            }
        }
        return false;
    }
    
    private function processEmail($form, $insertId, $data, $settings, $emailFields,$formData)
    {
        
        
        $data = ShortCodeParser::parse($data, $insertId, $formData);
        $notification = [
            'name'           => 'Admin Approval Notification Email',
            'fromName'       => ArrayHelper::get($settings, 'fromName'),
            'fromEmail'      => ArrayHelper::get($settings, 'fromEmail', ''),
            'replyTo'        => ArrayHelper::get($settings, 'replyTo'),
            'bcc'            => '',
            'subject'        => $data['email_subject'],
            'message'        => $data['email_body'],
            'enabled'        => true,
            'email_template' => '',
            'sendTo'         => [
                'type'  => 'email',
                'email' => $emailFields,
                'field' => ''
            ]
        ];
        
        $emailNotificationClass = new EmailNotification(wpFluentForm());
        $emailHeaders = $emailNotificationClass->getHeaders($notification);
        
        if (!ArrayHelper::isTrue($settings, 'asPlainText')) {
            $emailBody = $emailNotificationClass->getEmailWithTemplate($data['email_body'], $form, $notification);
        } else {
            $emailBody = $data['email_body'];
        }

        return wp_mail($emailFields, $data['email_subject'], $emailBody, $emailHeaders);
    }
    
    private function logData($form, $submission, $status)
    {
        do_action('fluentform/log_data', [
            'parent_source_id' => $form->id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'AdminApproval',
            'status'           => 'info',
            'title'            => 'Submission ' . ucwords($status),
            'description'      => 'Submission ' . ucwords($status) . ' by ' . wp_get_current_user()->display_name,
        ]);
    }
    
    public function getAdminApproval($formId, $scope = 'admin')
    {
        $defaults = [
            'status'                   => 'no',
            'approval_pending_message' => 'Please wait for admin approval to confirm this submission',
            'email_body'               => '<p>Hello There,</p><p>We regret to inform you that your recent submission has been declined. If you have any questions, please don\'t hesitate to reach out to us.</p><p>Thank you.</p>',
            'email_subject'            => 'Submission Declined',
            'skip_if_logged_in'        => 'yes',
            'asPlainText'              => 'no',
        ];
        
        $settings = \FluentForm\App\Helpers\Helper::getFormMeta($formId, 'admin_approval_settings', []);
        if ($settings) {
            $defaults = wp_parse_args($settings, $defaults);
        }
        if ($scope == 'public') {
            $defaults = wp_parse_args($defaults, $settings);
        }
        
        return $defaults;
    }
    
    protected function isActivatedInForm($formId = false)
    {
        if (!$formId) {
            return false;
        }
        // Keyed by form: unkeyed, one approval-off form ('no' is truthy) silenced every later one.
        static $activated = [];

        if (!isset($activated[$formId])) {
            $settings = $this->getAdminApproval($formId);
            $activated[$formId] = ($settings && !empty($settings['status'])) ? $settings['status'] : 'no';
        }

        return 'yes' === $activated[$formId];
    }
    
    private function sendApprovalPendingAdminEmail($form, $insertId, $formData)
    {
        $globalSettings = (new GlobalSettings())->getSettings([]);
        $emailFields = $this->getAdminEmail($globalSettings);
        if ($emailFields) {
            $emailFields = array_filter($emailFields, 'is_email');
            
            if (empty($emailFields)) {
                return;
            }
            $emailSubject = ArrayHelper::get($globalSettings, 'email_subject');
            $data['email_body'] = ArrayHelper::get($globalSettings, 'email_body');
            $approvedLink = admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . $form->id . '&update_status=' . $this->approvedStatusSlug . '#/entries/' . $insertId);
            $declinedLink = admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . $form->id . '&update_status=' . $this->declinedStatusSlug . '#/entries/' . $insertId);
            $data['email_body'] = str_replace(['#approve_link#', '#declined_link#'], [$approvedLink, $declinedLink], $data['email_body']);
            $data['email_subject'] = $emailSubject;
            
            $mailSent = $this->processEmail($form, $insertId, $data, $globalSettings, $emailFields,$formData);
            
            do_action('fluentform/log_data', [
                'parent_source_id' => $form->id,
                'source_type'      => 'submission_item',
                'source_id'        => $insertId,
                'component'        => 'AdminApproval',
                'status'           => 'info',
                'title'            => __('Admin Approval Notification Email sent', 'fluentformpro'),
                'description'      => __('Email sent to admin email : [', 'fluentformpro') . implode(', ', $emailFields) . ']',
            ]);
            return $mailSent;
        }
    }
    
    private function maybeSendDeclinedEmail($submission)
    {
        $formSettings = $this->getAdminApproval($submission->form_id);
        
        $emailField = ArrayHelper::get($formSettings, 'email_field');
        if (!$emailField) {
            return;
        }
    
        $formData = json_decode($submission->response, true);
        $emailId = trim(ArrayHelper::get($formData, $emailField));
        if (!$emailId || !is_email($emailId)) {
            return;
        }
    
        $form = Form::find($submission->form_id);
    
        $data['email_body'] = ArrayHelper::get($formSettings, 'email_body');
        $data['email_subject'] = ArrayHelper::get($formSettings, 'email_subject');
        
        $data = ShortCodeParser::parse($data, $submission->id, $formData);
        $this->processEmail($form, $submission->id, $data, $formSettings, [$emailId],$formData);
        
        do_action('fluentform/log_data', [
            'parent_source_id' => $form->id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'AdminApproval',
            'status'           => 'info',
            'title'            => __('Submission Declined Notification Email sent to User', 'fluentformpro'),
            'description'      => __('Email sent to user email : [', 'fluentformpro') . $emailId . ']',
        ]);
        Helper::setSubmissionMeta($submission->id, 'is_declined_email_sent', 'yes');
    }

    public function isEntryOnAdminApprovalMode($entryStatus)
    {
        return $this->isEnabled() && in_array($entryStatus, [$this->initialStatusSlug, $this->declinedStatusSlug, $this->approvedStatusSlug]);
    }


    public function renderApprovalConfirmationView($submission, $form, $formData)
    {
        $approvalSettings = $this->getAdminApproval($form->id, 'public');
        if ($submission->status == $this->approvedStatusSlug) {
            $landingContent = ArrayHelper::get($approvalSettings, 'approval_success_message', __("Submission verified and approved by Admin", 'fluentformpro'));
        } elseif ($submission->status == $this->declinedStatusSlug) {
            $landingContent = ArrayHelper::get($approvalSettings, 'approval_failed_message', __("Submission verified and declined by Admin", 'fluentformpro'));
        } else {
            $landingContent = ArrayHelper::get($approvalSettings, 'approval_pending_message', __('Please wait for admin to confirm this submission', 'fluentformpro'));
        }

        $landingContent = apply_filters('fluentform/admin_approval_confirmation_message', $landingContent, $submission->status, $form);
        $landingContent = ShortCodeParser::parse($landingContent, $submission->id, $formData);
        $sharePage = new SharePage();
        $statusTitle = ucfirst($submission->status);
        $settings = $sharePage->getSettings($form->id);
        $submissionVars = [
            'settings'        => $settings,
            'title'           => "Submission $statusTitle - {$form->title}",
            'form_id'         => $form->id,
            'entry'           => $submission,
            'form'            => $form,
            'bg_color'        => ArrayHelper::get($settings, 'custom_color', '#4286c4'),
            'landing_content' => $landingContent,
            'has_header'      => false,
            'isEmbeded'       => !!ArrayHelper::get($_GET, 'embedded')
        ];
        $submissionVars = apply_filters_deprecated(
            'fluentform_submission_vars',
            [
                $submissionVars,
                $form->id
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/submission_vars',
            'Use fluentform/submission_vars instead of fluentform_submission_vars.'
        );
        $landingVars = apply_filters('fluentform/submission_vars', $submissionVars, $form->id);
        $sharePage->loadPublicView($landingVars);
    }
}
