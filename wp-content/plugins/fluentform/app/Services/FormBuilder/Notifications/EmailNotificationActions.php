<?php

namespace FluentForm\App\Services\FormBuilder\Notifications;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class EmailNotificationActions
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function register()
    {
        add_filter('fluentform/notifying_async_email_notifications', '__return_false', 9);
        add_filter('fluentform/notifying_async_notifications', '__return_false', 9);

        add_filter('fluentform/global_notification_active_types', function ($types) {
            $types['notifications'] = 'email_notifications';
            return $types;
        });

        add_action('fluentform/integration_notify_notifications', [$this, 'notify'], 10, 4);

        add_action('fluentform/notify_on_form_submit', [$this, 'notifyOnSubmitPaymentForm'], 10, 3);
    }

    public function notifyOnSubmitPaymentForm($submissionId, $submissionData, $form)
    {
        $emailFeeds = wpFluent()->table('fluentform_form_meta')
            ->where('form_id', $form->id)
            ->where('meta_key', 'notifications')
            ->get();

        if (count($emailFeeds) === 0) {
            return;
        }

        $formData = $this->getFormData($submissionId);
        $notificationManager = new  \FluentForm\App\Services\Integrations\GlobalNotificationManager(wpFluentForm());

        $activeEmailFeeds = $notificationManager->getEnabledFeeds($emailFeeds, $formData, $submissionId);
        if (! $activeEmailFeeds) {
            return;
        }

        $onSubmitEmailFeeds = array_filter($activeEmailFeeds, function ($feed) {
            return 'payment_form_submit' == ArrayHelper::get($feed, 'settings.feed_trigger_event');
        });

        if (! $onSubmitEmailFeeds || 'yes' === Helper::getSubmissionMeta($submissionId, '_ff_on_submit_email_sent')) {
            return;
        }

        $entry = $this->getEntry($submissionId);

        foreach ($onSubmitEmailFeeds as $feed) {
            $processedValues = $feed['settings'];
            unset($processedValues['conditionals']);

            $processedValues = ShortCodeParser::parse(
                $processedValues,
                $submissionId,
                $formData,
                $form,
                false,
                $feed['meta_key']
            );
            $feed['processedValues'] = $processedValues;

            $this->notify($feed, $formData, $entry, $form);
        }

        Helper::setSubmissionMeta($submissionId, '_ff_on_submit_email_sent', 'yes', $form->id);
    }

    public function notify($feed, $formData, $entry, $form)
    {
        // A payment_success email asserts a settled charge, so gate it on the payment status
        // for every payment form -- not only those with a Payment Method field.
        $isTriggerOnPaymentSuccess = ArrayHelper::get($feed, 'processedValues.feed_trigger_event') === 'payment_success';
        if (!empty($form->has_payment) && $isTriggerOnPaymentSuccess && !$this->paymentSucceeded($entry, $form)) {
            return;
        }

        $notifier = $this->app->make(
            'FluentForm\App\Services\FormBuilder\Notifications\EmailNotification'
        );

        $emailData = $feed['processedValues'];
        $emailAttachments = $this->getAttachments($emailData, $formData, $entry, $form);
        if ($emailAttachments) {
            $emailData['attachments'] = $emailAttachments;
        }

        $notifier->notify($emailData, $formData, $form, $entry->id);
    }

    // Whether a payment_success trigger may fire for this entry.
    protected function paymentSucceeded($entry, $form)
    {
        $paymentStatus = is_null($entry->payment_status ?? null) ? '' : (string) $entry->payment_status;

        // Settled → a custom/settled status a gateway registered still fires; it is only the
        // known-unsettled ones that defer.
        if ('' !== $paymentStatus) {
            $unsettledStatuses = apply_filters('fluentform/unsettled_payment_statuses', [
                'pending', 'failed', 'requires_review', 'cancelled', 'refunded', 'partially-refunded',
            ]);
            return !in_array($paymentStatus, $unsettledStatuses, true);
        }

        // Empty status is a $0 order: fulfil a coupon-zeroed purchase, not an omitted or zeroed input.
        $isFreeOrder = 'yes' === Helper::getSubmissionMeta($entry->id, '_ff_zero_total_free_order');

        return (bool) apply_filters('fluentform/send_payment_success_on_zero_total', $isFreeOrder, $entry, $form);
    }

    /**
     * @param $emailData
     * @param $formData
     * @param $entry
     * @param $form
     *
     * @return array
     */
    private function getAttachments($emailData, $formData, $entry, $form)
    {
        $emailAttachments = [];

        $uploadDir = wp_upload_dir();

        if (!empty($emailData['attachments']) && is_array($emailData['attachments'])) {
            $attachments = [];
            foreach ($emailData['attachments'] as $name) {
                $fileUrls = ArrayHelper::get($formData, $name);
                if ($fileUrls && is_array($fileUrls)) {
                    foreach ($fileUrls as $url) {
                        $filePath = $this->resolveUploadAttachmentPath($url, $uploadDir);
                        if ($filePath) {
                            $attachments[] = $filePath;
                        }
                    }
                }
            }
            $emailAttachments = $attachments;
        }
        $mediaAttachments = ArrayHelper::get($emailData, 'media_attachments');
        if (!empty($mediaAttachments) && is_array($mediaAttachments)) {
            $attachments = [];
            foreach ($mediaAttachments as $file) {
                $fileUrl = ArrayHelper::get($file, 'url');
                $filePath = $this->resolveUploadAttachmentPath($fileUrl, $uploadDir);
                if ($filePath) {
                    $attachments[] = $filePath;
                }
            }
            $emailAttachments = array_merge($emailAttachments, $attachments);
        }
    
        $emailAttachments = apply_filters_deprecated(
            'fluentform_email_attachments',
            [
                $emailAttachments,
                $emailData,
                $formData,
                $entry,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/email_attachments',
            'Use fluentform/email_attachments instead of fluentform_email_attachments.'
        );
        // let others to apply attachments
        $emailAttachments = apply_filters(
            'fluentform/email_attachments',
            $emailAttachments,
            $emailData,
            $formData,
            $entry,
            $form
        );
        return $emailAttachments;
    }

    private function resolveUploadAttachmentPath($fileUrl, $uploadDir)
    {
        if (!$fileUrl || empty($uploadDir['baseurl']) || empty($uploadDir['basedir'])) {
            return null;
        }

        $normalizedUrl = strtok($fileUrl, '?#');
        if (strpos($normalizedUrl, $uploadDir['baseurl']) !== 0) {
            return null;
        }

        $relativePath = rawurldecode(str_replace($uploadDir['baseurl'], '', $normalizedUrl));
        $relativePath = ltrim(wp_normalize_path($relativePath), '/');

        if (!$relativePath || strpos($relativePath, '../') !== false) {
            return null;
        }

        $uploadsBaseDir = realpath($uploadDir['basedir']);
        if (!$uploadsBaseDir) {
            return null;
        }

        $uploadsBaseDir = trailingslashit(wp_normalize_path($uploadsBaseDir));
        $resolvedPath = realpath($uploadsBaseDir . $relativePath);

        if (!$resolvedPath) {
            return null;
        }

        $resolvedPath = wp_normalize_path($resolvedPath);
        if (strpos($resolvedPath, $uploadsBaseDir) !== 0 || !is_file($resolvedPath)) {
            return null;
        }

        return $resolvedPath;
    }

    public function getFormData($submissionId)
    {
        $submission = wpFluent()->table('fluentform_submissions')
            ->where('id', $submissionId)
            ->first();

        if (! $submission) {
            return false;
        }

        return json_decode($submission->response, true);
    }

    public function getEntry($submissionId)
    {
        return wpFluent()->table('fluentform_submissions')
            ->where('id', $submissionId)
            ->first();
    }
}
