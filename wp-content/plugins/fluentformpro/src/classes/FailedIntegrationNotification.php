<?php

namespace FluentFormPro\classes;

use FluentForm\App\Models\Scheduler;
use FluentForm\App\Services\Emogrifier\Emogrifier;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Trigger Email notification hourly if found any failed api actions in ff_scheduled_actions
 */
class FailedIntegrationNotification
{
    private $key = '_fluentform_failed_integration_notification';
    
    
    public function init()
    {
        /* Using cron ff_every_five_minutes  */
        add_action('fluentform_do_scheduled_tasks', [$this, 'maybeSendEmail']);
        
        add_action('fluentform/saving_global_settings_with_key_method', [$this, 'saveEmailConfig'], 10, 1);
    }
    
    public function isEnabled()
    {
        $settings = $this->getEmailConfig();
        
        return $settings['status'] == 'yes';
    }
    
    public function getEmailConfig()
    {
        $settings = [
            'status'            => 'yes',
            'send_to_type'      => 'admin_email',
            'custom_recipients' => '',
        ];
        if (get_option($this->key)) {
            $settings = get_option($this->key);
        }
        
        return $settings;
    }
    
    public function saveEmailConfig($request)
    {
        if (ArrayHelper::get($request, 'key') != 'failedIntegrationNotification') {
            return;
        }
        $defaults = [
            'status'            => 'yes',
            'send_to_type'      => 'admin_email',
            'custom_recipients' => '',
        ];
        $settings = ArrayHelper::get($request, 'value');
        $settings = json_decode($settings, true);
        
        $settings = wp_parse_args($settings, $defaults);
        
        update_option($this->key, $settings, false);
        
        wp_send_json_success(true);
    }
    
    /**
     * Get Send Email Addresses
     *
     * @param $email
     * @return array
     */
    private function getSendAddresses($email)
    {
        $sendEmail = explode(',', $email);
        if (count($sendEmail) > 1) {
            $email = $sendEmail;
        } else {
            $email = [$email];
        }
        
        return array_filter($email, 'is_email');
    }
    
    public function maybeSendEmail()
    {
        if (!$this->isEnabled() || !$this->timeMatched()) {
            return;
        }
        $settings = $this->getEmailConfig();
        $currentTime = current_time('mysql');
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
        $lastHour = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime($currentTime)));
        $failedFeeds = Scheduler::whereIn('status', ['failed', 'error'])
            ->where('retry_count', '>=', 3)
            ->with(['form' => function ($query) {
                    $query->select('title', 'id');
                }])
            ->whereBetween('ff_scheduled_actions.updated_at', [$lastHour, $currentTime])
            ->limit(11)
            ->get();
    
        if (!count($failedFeeds)) {
            return;
        }
        foreach ($failedFeeds as $index => $feed) {
            $feedData = PaymentHelper::safeUnserialize($feed->data);
            $failedFeeds[$index]->integration_name = $this->getFeedName($feedData);
            $failedFeeds[$index]->submission_link = admin_url('admin.php?page=fluent_forms&form_id=' . $feed->form_id . '&route=entries#/entries/' . $feed->origin_id);
        }
        
        
       
        if ($settings['send_to_type'] == 'admin_email') {
            $email = get_option('admin_email');
        } else {
            $email = $settings['custom_recipients'];
        }
        
        $recipients = $this->getSendAddresses($email);
        if (!$recipients) {
            return;
        }
        
        $this->broadCast($recipients, $failedFeeds);
    }
    
    private function broadCast($recipients, $failedFeeds)
    {
        $currentTime = current_time('mysql');
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
        $lastHour = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime($currentTime)));
        $data = [
            'failed_feeds'    => $failedFeeds,
            'first_item_time' => $lastHour,
            'last_item_time'  => $currentTime,
        ];
        $emailBody = wpFluentForm('view')->make('email.failedIntegration.body', $data);
        
        $emailBody = apply_filters('fluentform/failed_integration_email_body', $emailBody, $data);
        
        $originalEmailBody = $emailBody;
        
        ob_start();
        // apply CSS styles inline for picky email clients
        try {
            $emogrifier = new Emogrifier($emailBody);
            $emailBody = $emogrifier->emogrify();
        } catch (\Exception $e) {
        }
        $maybeError = ob_get_clean();
        
        if ($maybeError) {
            $emailBody = $originalEmailBody;
        }
        
        $headers = ['Content-Type: text/html; charset=utf-8'];
        
        $emailSubject = esc_html__('Failed Integration Notification', 'fluentformpro');
        
        $emailSubject = apply_filters('fluentform/failed_integration_email_subject', $emailSubject);
        
        update_option('_ff_last_sent_failed_integration_mail', current_time('timestamp'), 'no');
        
        return wp_mail($recipients, $emailSubject, $emailBody, $headers);
    }
    
    public function timeMatched()
    {
        $prevValue = get_option('_ff_last_sent_failed_integration_mail');
        if (!$prevValue) {
            return true;
        }
        $time = apply_filters('fluentform/failed_integration_notification_time_gap', 3600);// set time by default 1 hour gap
        return (current_time('timestamp') - $prevValue) > $time;
    }
    
    private function getFeedName($feedData)
    {
        return !empty(ArrayHelper::get($feedData, 'settings.name')) ? ArrayHelper::get($feedData, 'settings.name') : ArrayHelper::get($feedData, 'settings.textTitle');
    }
    
}
