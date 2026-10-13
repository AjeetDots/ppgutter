<?php

namespace FluentFormPro\Payments\PaymentMethods\PayPal\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\PayPal\PayPalProcessor;
use FluentFormPro\Payments\PaymentMethods\PayPal\PayPalSettings;

class IPN
{
    public function init()
    {
        /*
        * paypal specific action hooks
        */
        // normal onetime payment process
        add_action('fluentform/ipn_paypal_action_web_accept', array($this, 'updatePaymentStatusFromIPN'), 10, 4);
        // Process PayPal subscription sign ups
        add_action('fluentform/ipn_paypal_action_subscr_signup', array($this, 'processSubscriptionSignup'), 10, 4);
        // Process PayPal subscription payments
        add_action('fluentform/ipn_paypal_action_subscr_payment', array($this, 'processSubscriptionPayment'), 10, 4);
        // Process PayPal subscription cancel
        add_action('fluentform/ipn_paypal_action_subscr_cancel', array($this, 'processSubscriptionPaymentCancel'), 10, 4);
        // Process PayPal subscription end of term notices
        add_action('fluentform/ipn_paypal_action_subscr_eot', array($this, 'processSubscriptionPaymentEot'), 10, 4);
        // Process PayPal payment failed
        add_action('fluentform/ipn_paypal_action_subscr_failed', array($this, 'processSubscriptionFailed'), 10, 4);
    }

    public function verifyIPN()
    {
        status_header(200);
        // Check the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return false;
        }

        $submissionId = intval(ArrayHelper::get($_GET, 'submission_id'));

        // Set initial post data to empty string
        $post_data = '';

        // Fallback just in case post_max_size is lower than needed
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
            ini_set('post_max_size', '12M');
        }
        // Start the encoded data collection with notification command
        $encoded_data = 'cmd=_notify-validate';

        // Get current arg separator
        $arg_separator = ini_get('arg_separator.output');

        // Verify there is a post_data
        if ($post_data || strlen($post_data) > 0) {
            // Append the data
            $encoded_data .= $arg_separator . $post_data;
        } else {
            // Check if POST is empty
            if (empty($_POST)) {
                PaymentHelper::log([
                    'status'      => 'error',
                    'title'       => __('Invalid PayPal IPN Notification Received. No Posted Data', 'fluentformpro'),
                    'description' => json_encode($_GET)
                ]);
                // Nothing to do
                return false;
            } else {
                // Loop through each POST
                foreach ($_POST as $key => $value) {
                    // Encode the value and append the data
                    $encoded_data .= $arg_separator . "$key=" . urlencode($value);
                }
            }
        }
   
        try {
            // Decode once — parse_str() already URL-decodes; a pre-decode let an echoed field forge top-level fields past _notify-validate.
            parse_str($encoded_data, $encoded_data_array);
            foreach ($encoded_data_array as $key => $value) {
                if (is_string($value)) {
                    $encoded_data_array[$key] = $this->utf8_encode_custom($value);
                }
            }
        } catch (\Exception $e) {
            //
        }
        
        foreach ($encoded_data_array as $key => $value) {
            if (false !== strpos($key, 'amp;')) {
                $new_key = str_replace('&amp;', '&', $key);
                $new_key = str_replace('amp;', '&', $new_key);
                unset($encoded_data_array[$key]);
                $encoded_data_array[$new_key] = $value;
            }
        }
      

        // Check if $post_data_array has been populated
        if (!is_array($encoded_data_array) && !empty($encoded_data_array)) {
            PaymentHelper::log([
                'status'      => 'error',
                'title'       => __('Invalid PayPal IPN Notification Received', 'fluentformpro'),
                'description' => json_encode($_POST)
            ]);
            return false;
        }

        $encoded_data_array = apply_filters_deprecated(
            'fluentform_process_paypal_ipn_data',
            [
                $encoded_data_array
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/process_paypal_ipn_data',
            'Use fluentform/process_paypal_ipn_data instead of fluentform_process_paypal_ipn_data.'
        );

        $encoded_data_array = apply_filters('fluentform/process_paypal_ipn_data', $encoded_data_array);
        $defaults = array(
            'txn_type'       => '',
            'payment_status' => '',
            'custom'         => ''
        );

        $encoded_data_array = wp_parse_args($encoded_data_array, $defaults);
        $customJson = ArrayHelper::get($encoded_data_array, 'custom');
        $customArray = json_decode($customJson, true);

        if (!$submissionId) {
            if (!$customArray || empty($customArray['fs_id'])) {
                $submissionId = false;
            } else {
                $submissionId = intval($customArray['fs_id']);
            }
        }

        $submission = false;
        if ($submissionId) {
            $submission = wpFluent()->table('fluentform_submissions')->find($submissionId);
        }

        if (!$submission) {
            PaymentHelper::log([
                'status'      => 'error',
                'title'       => __('Invalid PayPal IPN Notification Received. No Submission Found', 'fluentformpro'),
                'description' => json_encode($encoded_data_array)
            ]);
            return false;
        }

        // Always verify IPN with PayPal unless explicitly bypassed via filter.
        // Use add_filter('fluentform/paypal_verify_ipn', '__return_false') to skip verification.
        $ipnVerified = false;
        $shouldVerifyIpn = apply_filters('fluentform/paypal_verify_ipn', true);

        if ($shouldVerifyIpn) {
            $validate_ipn = wp_unslash($_POST); // WPCS: CSRF ok, input var ok.
            $validate_ipn['cmd'] = '_notify-validate';

            $params = array(
                'body'        => $validate_ipn,
                'timeout'     => 60,
                'httpversion' => '1.1',
                'compress'    => false,
                'decompress'  => false,
                'user-agent'  => 'FluentForm/' . FLUENTFORMPRO_VERSION,
            );

            // Post back to PayPal to validate the IPN notification is genuine.
            $response = wp_safe_remote_post((!PayPalSettings::isLive()) ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr', $params);
            if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && strstr($response['body'], 'VERIFIED')) {
                PaymentHelper::log([
                    'status' => 'success',
                    'title'  => __('Received valid response from PayPal IPN', 'fluentformpro'),
                    'description' => json_encode($encoded_data_array)
                ], $submission);
                $ipnVerified = true;
            } else {
                PaymentHelper::log([
                    'status'      => 'error',
                    'title'       => __('PayPal IPN verification Failed', 'fluentformpro'),
                    'description' => json_encode($encoded_data_array)
                ], $submission);
                return false;
            }
        } else {
            // Filter explicitly disabled verification — treat as verified.
            $ipnVerified = true;
        }

        if (has_action('fluentform/ipn_paypal_action_' . $encoded_data_array['txn_type'])) {
            do_action_deprecated(
                'fluentform_ipn_paypal_action_' . $encoded_data_array['txn_type'],
                [
                    $encoded_data_array,
                    $submissionId,
                    $submission,
                    $ipnVerified
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/ipn_paypal_action_' . $encoded_data_array['txn_type'],
                'Use fluentform/ipn_paypal_action_' . $encoded_data_array['txn_type'] . ' instead of fluentform_ipn_paypal_action_' . $encoded_data_array['txn_type']
            );
            // Allow PayPal IPN types to be processed separately
            do_action('fluentform/ipn_paypal_action_' . $encoded_data_array['txn_type'], $encoded_data_array, $submissionId, $submission, $ipnVerified);
        } else {
            do_action_deprecated(
                'fluentform_ipn_paypal_action_web_accept',
                [
                    $encoded_data_array,
                    $submissionId,
                    $submission,
                    $ipnVerified
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/ipn_paypal_action_web_accept',
                'Use fluentform/ipn_paypal_action_web_accept instead of fluentform_ipn_paypal_action_web_accept.'
            );
            // Fallback to web accept just in case the txn_type isn't present
            do_action('fluentform/ipn_paypal_action_web_accept', $encoded_data_array, $submissionId, $submission, $ipnVerified);
        }
        
        exit;
    }

    public function updatePaymentStatusFromIPN($data, $submissionId, $submission, $ipnVerified = false)
    {
        (new PayPalProcessor())->handleWebAcceptPayment($data, $submissionId, $ipnVerified);
    }

    public function processSubscriptionSignup($data, $submissionId, $submission, $ipnVerified = false)
    {
        if (!$ipnVerified) {
            PaymentHelper::log([
                'status'      => 'error',
                'title'       => __('PayPal IPN verification failed for subscription signup. Processing aborted.', 'fluentformpro'),
                'description' => json_encode($data)
            ], $submission);
            return false;
        }

        if ($this->isReceiverMismatched($data, $submission)) {
            return false;
        }

        $subscription = $this->findSubscriptionBySubmissionId($submissionId);

        if (!$subscription) {
            return;
        }

        // FF-SEC-41: a replayed/late signup for an already-closed (cancelled/completed) subscription
        // must not reactivate it or mark the order paid.
        if (in_array($subscription->status, ['cancelled', 'completed'], true)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Subscription',
                'status'           => 'info',
                'title'            => __('Late PayPal subscription signup ignored', 'fluentformpro'),
                'description'      => __('The subscription is already closed, so the signup event was not reprocessed.', 'fluentformpro'),
            ]);
            return;
        }

        // A replayed/late signup IPN must not resurrect a reversed (refunded/cancelled)
        // order back to paid. The Pro helper facade keeps older Free versions compatible.
        $currentSubmission = $this->findSubmission($submissionId);
        if ($currentSubmission
            && PaymentHelper::isReversedPaymentStatus($currentSubmission->payment_status)) {
            return;
        }

        // Only a trial (a1 = 0, nothing due) activates paid at signup; a non-trial stays pending until subscr_payment settles.
        $isTrial = $subscription->trial_days && $subscription->status == 'pending';
        if ($isTrial) {
            $this->updateSubmission($submissionId, [
                'payment_status' => 'paid',
            ]);
        }

        $submission = $this->findSubmission($submissionId);

        // PayPal re-delivers signups; a resend must not end a trial already running.
        $subscriptionStatus = ($isTrial || 'trialling' === $subscription->status) ? 'trialling' : 'active';

        $this->updateSubscription($subscription->id, [
            'vendor_response'        => maybe_serialize($data),
            'vendor_customer_id'     => $data['payer_id'],
            'vendor_subscription_id' => $data['subscr_id']
        ]);

        $subscription = fluentFormApi('submissions')->getSubscription($subscription->id);

        $paypalProcessor = (new PayPalProcessor());
        $paypalProcessor->setSubmissionId($submission->id);
        $paypalProcessor->updateSubscriptionStatus($subscription, $subscriptionStatus);

        if ($subscription->status == 'pending') {
            $logData = [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Subscription',
                'status'           => 'success',
                'title'            => __('PayPal Subscription Complete', 'fluentformpro'),
                'description'      => __('PayPal recurring payment subscription successfully initiated', 'fluentformpro')
            ];

            do_action('fluentform/log_data', $logData);
        }

        // PayPal sometimes send subscr_payment after few second and use see empty data
        // So We have to entry the payment here
        if (isset($data['from_subscr_signup'])) {
            // Transaction will be added via processSubscriptionPayment
            return true;
        }

        // Let's check if the transaction actually added or not in the meantime
        $transaction = wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $submission->id)
            ->first();

        if ($transaction) {
            return true; // transaction has been added already
        }

        // We have to make a dummy transaction for now with the available data
        $amount = ArrayHelper::get($data, 'amount3');
        if (ArrayHelper::get($data, 'amount1')) {
            // this for with signup fee
            $amount = ArrayHelper::get($data, 'amount1');
        }

        $amount = intval($amount * 100);

        $paymentData = [
            'form_id'          => $submission->form_id,
            'submission_id'    => $submission->id,
            'subscription_id'  => $subscription->id,
            'payer_email'      => ArrayHelper::get($data, 'payer_email'),
            'payer_name'       => trim(ArrayHelper::get($data, 'first_name') . ' ' . ArrayHelper::get($data, 'last_name')),
            'transaction_type' => 'subscription',
            'payment_method'   => 'paypal',
            // Non-trial line stays pending/null so subscr_payment settles it; trial keeps a paid placeholder.
            'charge_id'        => $isTrial ? 'temp_' . $data['subscr_id'] : null,
            'payment_total'    => $amount,
            'status'           => $isTrial ? 'paid' : 'pending',
            'currency'         => $submission->currency,
            'payment_mode'     => (PayPalSettings::isLive($submission->form_id)) ? 'live' : 'test',
            'payment_note'     => maybe_serialize($data)
        ];

        if ($submission->user_id) {
            $paymentData['user_id'] = $submission->user_id;
        }

        $paypalProcessor->maybeInsertSubscriptionCharge($paymentData);
    }

    public function processSubscriptionPayment($vendorData, $submissionId, $submission, $ipnVerified = false)
    {
        if (!$ipnVerified) {
            PaymentHelper::log([
                'status'      => 'error',
                'title'       => __('PayPal IPN verification failed for subscription payment. Processing aborted.', 'fluentformpro'),
                'description' => json_encode($vendorData)
            ], $submission);
            return false;
        }

        if ($this->isReceiverMismatched($vendorData, $submission)) {
            return false;
        }

        $subscription = $this->findSubscriptionBySubmissionId($submissionId);

        if (!$submission || !$subscription) {
            return false; // maybe the submission has been deleted
        }

        if ($subscription->status == 'pending') {
            $vendorData['from_subscr_signup'] = 'yes';
            // somehow the subscr_signup hook could not be fired yet. So let's make it active manually
            $this->processSubscriptionSignup($vendorData, $submissionId, $submission, $ipnVerified);
            $subscription = $this->findSubscriptionBySubmissionId($submissionId);
        }

        // FF-SEC-41: a late Completed IPN for an already-closed subscription is still recorded
        // on the ledger, but must not renew it (no received hooks, first-payment actions, or completion).
        $isTerminalSubscription = in_array($subscription->status, ['cancelled', 'completed'], true);

        $paymentStatus = strtolower($vendorData['payment_status']);
        if ($paymentStatus == 'completed') {
            $paymentStatus = 'paid';
        }
        // Fallback for payment_total
        // For non USD currency 'payment_gross' get empty(''), and total payment is stored in 'mc_gross'
        if (!is_numeric(ArrayHelper::get($vendorData, 'payment_gross'))) {
            $vendorData['payment_gross'] = ArrayHelper::get($vendorData, 'mc_gross');
        }
        if (!is_numeric(ArrayHelper::get($vendorData, 'payment_gross'))) {
            return false; // invalid payment total
        }
        // One txn_id lookup: a settled row on this submission is a resend (no-op); the same charge on another submission is a cross-submission replay.
        $txnId = ArrayHelper::get($vendorData, 'txn_id');
        $pendingTransaction = $this->findInitialPendingTransaction($submissionId, $subscription->id, $txnId);
        $isSettledReplay = false;
        $reusedElsewhere = false;
        if ($txnId) {
            $priorRows = wpFluent()->table('fluentform_transactions')
                ->where('charge_id', $txnId)
                ->where('payment_method', 'paypal')
                ->get();
            foreach ($priorRows as $priorRow) {
                if ((int) $priorRow->submission_id !== (int) $submissionId) {
                    $reusedElsewhere = true;
                } elseif (in_array((string) $priorRow->status, ['paid', 'partially-refunded', 'refunded'], true)) {
                    $isSettledReplay = true;
                }
            }
        }

        if ($reusedElsewhere) {
            $paymentStatus = 'requires_review';
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Transaction Reuse', 'fluentformpro'),
                'description'      => __('IPN transaction id is already recorded on another submission. Held for review.', 'fluentformpro'),
            ]);
        }

        // A resend of a charge already settled on this submission must leave the settled row untouched.
        if ($isSettledReplay) {
            return true;
        }

        // FF-SEC-65/35: hold for review an IPN that doesn't pay what PayPal was set to charge --
        // a1 (the pending payment_total) on the first cycle, else a3 (recurring_amount).
        $paidCents = intval(round(floatval($vendorData['payment_gross']) * 100));
        $expectedCents = $pendingTransaction
            ? (int) $pendingTransaction->payment_total
            : (int) $subscription->recurring_amount;
        if ($expectedCents && $paidCents !== $expectedCents) {
            $paymentStatus = 'requires_review';
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Subscription Amount Mismatch', 'fluentformpro'),
                'description'      => sprintf(
                    /* translators: 1: reported cents, 2: expected cents */
                    __('IPN reported %1$d cents; expected %2$d. Held for review.', 'fluentformpro'),
                    $paidCents,
                    $expectedCents
                ),
            ]);
        }
        // A verified subscr_payment always carries mc_currency; require it present and matching.
        $ipnCurrency = ArrayHelper::get($vendorData, 'mc_currency');
        if ($submission->currency
            && ('' === (string) $ipnCurrency || 0 !== strcasecmp((string) $ipnCurrency, (string) $submission->currency))) {
            $paymentStatus = 'requires_review';
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Subscription Currency Mismatch', 'fluentformpro'),
                'description'      => sprintf(
                    /* translators: 1: reported currency, 2: order currency */
                    __('IPN currency %1$s does not match the order currency %2$s. Held for review.', 'fluentformpro'),
                    $ipnCurrency ?: '(none)',
                    $submission->currency
                ),
            ]);
        }

        $paymentData = [
            'form_id'          => $submission->form_id,
            'submission_id'    => $submission->id,
            'subscription_id'  => $subscription->id,
            'payer_email'      => ArrayHelper::get($vendorData, 'payer_email'),
            'payer_name'       => trim(ArrayHelper::get($vendorData, 'first_name') . ' ' . ArrayHelper::get($vendorData, 'last_name')),
            'transaction_type' => 'subscription',
            'payment_method'   => 'paypal',
            'charge_id'        => $vendorData['txn_id'],
            'payment_total'    => $paidCents,
            'status'           => $paymentStatus,
            'currency'         => $submission->currency,
            'payment_mode'     => (PayPalSettings::isLive($submission->form_id)) ? 'live' : 'test',
            'payment_note'     => maybe_serialize($vendorData)
        ];

        if ($submission->user_id) {
            $paymentData['user_id'] = $submission->user_id;
        }

        $payPalProcessor = new PayPalProcessor();
        $payPalProcessor->setSubmissionId($submissionId);

        if ($pendingTransaction) {
            $payPalProcessor->updateTransaction($pendingTransaction->id, $paymentData);
            $transactionId = $pendingTransaction->id;
        } else {
            $transactionId = $payPalProcessor->maybeInsertSubscriptionCharge($paymentData);
        }

        // FF-SEC-41: payment is on the ledger; a closed subscription gets no renewal side effects.
        if ($isTerminalSubscription) {
            $payPalProcessor->recalculatePaidTotal();
            return;
        }

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Subscription',
            'status'           => 'success',
            'title'            => __('PayPal Subscription Payment', 'fluentformpro'),
            'description'      => __('Congratulations! New Payment has been received from your subscription', 'fluentformpro')
        ];

        // Don't log a payment-received success for a held (requires_review) charge.
        $isSettled = PaymentHelper::isFulfillablePaymentStatus($paymentStatus);
        if ($isSettled) {
            do_action('fluentform/log_data', $logData);
        }

        do_action_deprecated(
            'fluentform_form_submission_activity_start',
            [
                $submission->form_id
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/form_submission_activity_start',
            'Use fluentform/form_submission_activity_start instead of fluentform_form_submission_activity_start.'
        );

        do_action('fluentform/form_submission_activity_start', $submission->form_id);

        $previousBillCount = (int) $subscription->bill_count;

        $payPalProcessor->recalculatePaidTotal();
        $updatedSubscription = $this->findSubscription($subscription->id);
        $isNewPayment = $previousBillCount !== (int) $updatedSubscription->bill_count;

        // A settled first payment promotes the submission to paid and fulfils it (signup no longer does),
        // covering both the signup-first and payment-first IPN orderings.
        $promoted = 0;
        if ($isSettled) {
            // Match only a still-pending row so a concurrent refund/cancel is never resurrected; the
            // affected-row count says whether this settle actually promoted the order, gating fulfilment.
            $promoted = wpFluent()->table('fluentform_submissions')
                ->where('id', $submissionId)
                ->where('payment_status', 'pending')
                ->update(['payment_status' => $paymentStatus, 'updated_at' => current_time('mysql')]);
            if ($pendingTransaction && $promoted) {
                $returnData = $payPalProcessor->completePaymentSubmission(false);
            }
        }

        // Announce a received installment only once it has settled, not on a Pending/Denied IPN.
        $freshSubmission = $this->findSubmission($submissionId);
        $hookSubmission = $freshSubmission ?: $submission;

        if ($isNewPayment && $isSettled) {
            do_action_deprecated(
                'fluentform_subscription_payment_received',
                [
                    $hookSubmission,
                    $updatedSubscription,
                    $submission->form_id,
                    $subscription
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/subscription_payment_received',
                'Use fluentform/subscription_payment_received instead of fluentform_subscription_payment_received.'
            );
            do_action('fluentform/subscription_payment_received', $hookSubmission, $updatedSubscription, $submission->form_id, $subscription);

            do_action_deprecated(
                'fluentform_subscription_payment_received_paypal',
                [
                    $hookSubmission,
                    $updatedSubscription,
                    $submission->form_id,
                    $subscription
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/subscription_payment_received_paypal',
                'Use fluentform/subscription_payment_received_paypal instead of fluentform_subscription_payment_received_paypal.'
            );
            do_action('fluentform/subscription_payment_received_paypal', $hookSubmission, $updatedSubscription, $submission->form_id, $subscription);
        }

        // Re-read fresh: a refund/cancel landing since the IPN's early snapshot must not fulfil.
        $isReversedNow = $freshSubmission
            && PaymentHelper::isReversedPaymentStatus($freshSubmission->payment_status);
        if (!$isReversedNow && (int) $updatedSubscription->bill_count === 1 && $isSettled) {
            PaymentHelper::maybeFireSubmissionActionHok($hookSubmission);
            $transaction = $this->findTransaction($transactionId);

            do_action_deprecated(
                'fluentform_form_payment_success',
                [
                    $hookSubmission,
                    $transaction,
                    $submission->form_id,
                    false
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/form_payment_success',
                'Use fluentform/form_payment_success instead of fluentform_form_payment_success.'
            );

            do_action('fluentform/form_payment_success', $hookSubmission, $transaction, $submission->form_id, false);
        }
    }

    public function processSubscriptionPaymentCancel($vendorData, $submissionId, $submission, $ipnVerified = false)
    {
        if (!$ipnVerified) {
            PaymentHelper::log([
                'status'      => 'error',
                'title'       => __('PayPal IPN verification failed for subscription cancel. Processing aborted.', 'fluentformpro'),
                'description' => json_encode($vendorData)
            ], $submission);
            return false;
        }

        $subscription = $this->findSubscriptionBySubmissionId($submissionId);

        if (!$subscription) {
            return;
        }

        $paypalProcess = new PayPalProcessor();
        $paypalProcess->setSubmissionId($submissionId);
        $paypalProcess->updateSubscriptionStatus($subscription, 'cancelled');
    }

    public function processSubscriptionPaymentEot($vendorData, $submissionId, $submission, $ipnVerified = false)
    {
        if (!$ipnVerified) {
            PaymentHelper::log([
                'status'      => 'error',
                'title'       => __('PayPal IPN verification failed for subscription EOT. Processing aborted.', 'fluentformpro'),
                'description' => json_encode($vendorData)
            ], $submission);
            return false;
        }

        $subscription = $this->findSubscriptionBySubmissionId($submissionId);

        if (!$subscription) {
            return;
        }

        $paypalProcess = new PayPalProcessor();
        $paypalProcess->setSubmissionId($submissionId);
        $paypalProcess->updateSubscriptionStatus($subscription, 'completed');
    }

    public function processSubscriptionFailed($vendorData, $submissionId, $submission, $ipnVerified = false)
    {
        if (!$ipnVerified) {
            PaymentHelper::log([
                'status'      => 'error',
                'title'       => __('PayPal IPN verification failed for subscription failure. Processing aborted.', 'fluentformpro'),
                'description' => json_encode($vendorData)
            ], $submission);
            return false;
        }

        $subscription = $this->findSubscriptionBySubmissionId($submissionId);

        if (!$subscription) {
            return;
        }

        $paypalProcess = new PayPalProcessor();
        $paypalProcess->setSubmissionId($submissionId);
        $paypalProcess->updateSubscriptionStatus($subscription, 'cancelled');
    }

    // A VERIFIED IPN proves PayPal sent it, not that money reached this merchant; mirrors PayPalProcessor::handleWebAcceptPayment().
    private function isReceiverMismatched($data, $submission)
    {
        if (!$submission) {
            return false;
        }

        // `business` is the address the button was made out to; fall back to the account's
        // primary `receiver_email`. is_email() rejects PayPal's non-email merchant-id form
        // and guards an array-shaped field on PHP 8.
        $receiver = ArrayHelper::get($data, 'business');
        if (!is_string($receiver) || !is_email($receiver)) {
            $receiver = ArrayHelper::get($data, 'receiver_email');
        }

        $merchantEmail = PayPalSettings::getPayPalEmail($submission->form_id);

        $matches = $merchantEmail
            && is_string($receiver)
            && 0 === strcasecmp(trim((string) $merchantEmail), trim($receiver));

        if ($matches) {
            return false;
        }

        // Fail closed: a mismatch, a malformed receiver, or no merchant configured (an empty
        // `business` button never took a real payment) is rejected and logged.
        do_action('fluentform/log_data', [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'error',
            'title'            => __('PayPal Receiver Mismatch', 'fluentformpro'),
            'description'      => __('IPN receiver email does not match the configured merchant account. Payment rejected.', 'fluentformpro'),
        ]);

        return true;
    }

    private function findSubscriptionBySubmissionId($submissionId)
    {
        return wpFluent()->table('fluentform_subscriptions')
            ->where('submission_id', $submissionId)
            ->first();
    }

    private function findSubscription($subscriptionId)
    {
        return wpFluent()->table('fluentform_subscriptions')
            ->where('id', $subscriptionId)
            ->first();
    }

    private function findSubmission($id)
    {
        return wpFluent()->table('fluentform_submissions')
            ->where('id', $id)
            ->first();
    }

    private function findInitialPendingTransaction($submissionId, $subscriptionId, $txnId = '')
    {
        return wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $submissionId)
            ->where('subscription_id', $subscriptionId)
            ->where('payment_method', 'paypal')
            ->where('transaction_type', 'subscription')
            ->where('status', 'pending')
            ->where(function ($query) use ($txnId) {
                $query->whereNull('charge_id');

                if ($txnId) {
                    $query->orWhere('charge_id', $txnId);
                }
            })
            ->first();
    }

    private function updateSubscription($id, $data)
    {
        $data['updated_at'] = current_time('mysql');

        wpFluent()->table('fluentform_subscriptions')
            ->where('id', $id)
            ->update($data);
    }

    private function updateSubmission($id, $data)
    {
        $data['updated_at'] = current_time('mysql');

        wpFluent()->table('fluentform_submissions')
            ->where('id', $id)
            ->update($data);
    }

    public function findTransaction($id, $transactionType = 'subscription')
    {
        return wpFluent()->table('fluentform_transactions')
            ->where('id', $id)
            ->where('transaction_type', $transactionType)
            ->first();
    }
    
    /**
     * With the function it is now possible to replace
     * all utf8_encode calls with the new utf8_encode_custom function to avoid the deprecation notice
     * @return false|string
     */
    public function utf8_encode_custom($s)
    {
        $s .= $s;
        $len = \strlen($s);
        for ($i = $len >> 1, $j = 0; $i < $len; ++$i, ++$j) {
            switch (true) {
                case $s[$i] < "\x80": $s[$j] = $s[$i]; break;
                case $s[$i] < "\xC0": $s[$j] = "\xC2"; $s[++$j] = $s[$i]; break;
                default: $s[$j] = "\xC3"; $s[++$j] = \chr(\ord($s[$i]) - 64); break;
            }
        }
        return substr($s, 0, $j);
    }
}
