<?php

namespace FluentFormPro\Payments\PaymentMethods;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentForm\App\Services\Form\SubmissionHandlerService;

abstract class BaseProcessor
{
    protected $method;

    protected $form = null;

    protected $submission = null;

    protected $submissionId = null;

    public function init()
    {
        add_action('fluentform/process_payment_' . $this->method, array($this, 'handlePaymentAction'), 10, 6);
    }

    /**
     * Validate a payment confirmation request before processing.
     *
     * Checks that the transaction exists, is in a confirmable state,
     * and the submission hasn't already been paid.
     *
     * @param object $transaction  The transaction row from fluentform_transactions.
     * @param int    $submissionId The submission ID to validate against.
     * @return true|\WP_Error  True on success, WP_Error on failure.
     */
    public function validatePaymentConfirmation($transaction, $submissionId)
    {
        if (!$transaction) {
            $this->logPaymentSecurityEvent($submissionId, __('Payment confirmation attempted with invalid transaction', 'fluentformpro'));
            return new \WP_Error('invalid_transaction', __('Invalid transaction.', 'fluentformpro'));
        }

        // Ensure the transaction belongs to this submission
        if ((int) $transaction->submission_id !== (int) $submissionId) {
            $this->logPaymentSecurityEvent($submissionId, __('Transaction does not belong to this submission', 'fluentformpro'));
            return new \WP_Error('transaction_mismatch', __('Transaction does not match submission.', 'fluentformpro'));
        }

        // Only pending transactions can be confirmed
        if (!in_array($transaction->status, ['pending', 'intended'], true)) {
            $this->logPaymentSecurityEvent(
                $submissionId,
                // translators: %s is the transaction status
                sprintf(__('Payment confirmation attempted on transaction with status: %s', 'fluentformpro'), $transaction->status)
            );
            return new \WP_Error('invalid_transaction_status', __('This transaction cannot be confirmed.', 'fluentformpro'));
        }

        // Prevent double-pay: check submission payment_status
        $submission = wpFluent()->table('fluentform_submissions')
            ->where('id', $submissionId)
            ->first();

        if (!$submission) {
            return new \WP_Error('invalid_submission', __('Invalid submission.', 'fluentformpro'));
        }

        if ($submission->payment_status === 'paid') {
            $this->logPaymentSecurityEvent($submissionId, __('Payment confirmation attempted on already-paid submission', 'fluentformpro'));
            return new \WP_Error('already_paid', __('This payment has already been completed.', 'fluentformpro'));
        }

        return true;
    }

    /**
     * Verify a payment nonce for the given submission.
     *
     * @param int    $submissionId The submission ID.
     * @param string $method       The payment method name (e.g. 'razorpay').
     * @return true|\WP_Error  True on success, WP_Error on failure.
     */
    public function verifyPaymentNonce($submissionId, $method)
    {
        $nonce = isset($_REQUEST['_ff_payment_nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_ff_payment_nonce'])) : '';

        if (!$nonce) {
            $strict = apply_filters('fluentform/' . $method . '_nonce_strict', false);
            if ($strict) {
                return new \WP_Error('missing_nonce', __('Security verification failed.', 'fluentformpro'));
            }
            return true;
        }

        $nonceAction = 'ff_payment_' . $submissionId;
        if (!wp_verify_nonce($nonce, $nonceAction)) {
            return new \WP_Error('invalid_nonce', __('Security verification failed. Please try again.', 'fluentformpro'));
        }

        return true;
    }

    /**
     * Log a security-relevant payment event.
     *
     * @param int    $submissionId
     * @param string $description
     */
    protected function logPaymentSecurityEvent($submissionId, $description)
    {
        $formId = 0;
        if ($submissionId) {
            $submission = wpFluent()->table('fluentform_submissions')
                ->select(['form_id'])
                ->where('id', $submissionId)
                ->first();
            if ($submission) {
                $formId = $submission->form_id;
            }
        }

        do_action('fluentform/log_data', [
            'parent_source_id' => $formId,
            'source_type'      => 'submission_item',
            'source_id'        => $submissionId,
            'component'        => 'Payment',
            'status'           => 'error',
            'title'            => __('Payment Security Alert', 'fluentformpro'),
            'description'      => $description
        ]);
    }

    public abstract function handlePaymentAction($submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable);

    public function setSubmissionId($submissionId)
    {
        $this->submissionId = $submissionId;
    }

    public function getSubmissionId()
    {
        return $this->submissionId;
    }

    public function insertTransaction($data)
    {
        if (empty($data['transaction_type'])) {
            $data['transaction_type'] = 'onetime';
        }

        $data = wp_parse_args($data, $this->getTransactionDefaults());

        if (empty($data['transaction_hash'])) {
            $data['transaction_hash'] = wp_generate_password(32, false);
        }

        return wpFluent()->table('fluentform_transactions')->insertGetId($data);
    }

    public function insertRefund($data)
    {
        $submission = $this->getSubmission();
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');
        $data['form_id'] = $submission->form_id;
        $data['submission_id'] = $submission->id;
        $data['payment_method'] = $this->method;
        if (empty($data['transaction_type'])) {
            $data['transaction_type'] = 'refund';
        }

        if ($userId = get_current_user_id()) {
            $data['user_id'] = $userId;
        }

        if (empty($data['transaction_hash'])) {
            $data['transaction_hash'] = wp_generate_password(32, false);
        }

        return wpFluent()->table('fluentform_transactions')->insertGetId($data);
    }

    public function getTransaction($transactionId, $column = 'id')
    {
        return wpFluent()->table('fluentform_transactions')
            ->where($column, $transactionId)
            ->first();
    }

    public function getRefund($refundId, $column = 'id')
    {
        return wpFluent()->table('fluentform_transactions')
            ->where($column, $refundId)
            ->where('transaction_type', 'refund')
            ->first();
    }

    public function getTransactionByChargeId($chargeId)
    {
        return wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $this->submissionId)
            ->where('charge_id', $chargeId)
            ->first();
    }

    public function getLastTransaction($submissionId)
    {
        return wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $submissionId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function changeSubmissionPaymentStatus($newStatus)
    {
        do_action_deprecated(
            'fluentform_before_payment_status_change',
            [
                $newStatus,
                $this->getSubmission()
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/before_payment_status_change',
            'Use fluentform/before_payment_status_change instead of fluentform_before_payment_status_change.'
        );

        do_action('fluentform/before_payment_status_change', $newStatus, $this->getSubmission());

        wpFluent()->table('fluentform_submissions')
            ->where('id', $this->submissionId)
            ->update([
                'payment_status' => $newStatus,
                'updated_at'     => current_time('mysql')
            ]);

        $this->submission = null;

        $logData = [
            'parent_source_id' => $this->getForm()->id,
            'source_type'      => 'submission_item',
            'source_id'        => $this->submissionId,
            'component'        => 'Payment',
            'status'           => 'paid' === $newStatus ? 'success' : $newStatus,
            'title'            => __('Payment Status changed', 'fluentformpro'),
            'description'      => __('Payment status changed to ', 'fluentformpro') . $newStatus
        ];

        do_action('fluentform/log_data', $logData);

        do_action_deprecated(
            'fluentform_after_payment_status_change',
            [
                $newStatus,
                $this->getSubmission()
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/after_payment_status_change',
            'Use fluentform/after_payment_status_change instead of fluentform_after_payment_status_change.'
        );

        do_action('fluentform/after_payment_status_change', $newStatus, $this->getSubmission());

        return true;
    }

    public function recalculatePaidTotal()
    {
        // was: this status list also matched the refund rows themselves (status refunded), so a full refund
        // summed gross + refund - refund and total_paid stayed at the gross amount
        $transactions = wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $this->submissionId)
            ->whereIn('status', ['paid', 'requires_capture', 'processing', 'partially-refunded', 'refunded'])
            ->where('transaction_type', '!=', 'refund')
            ->get();

        $total = 0;
        $subscriptionId = false;

        foreach ($transactions as $transaction) {
            $total += $transaction->payment_total;
            if($transaction->subscription_id) {
                $subscriptionId = $transaction->subscription_id;
            }
        }

        $refunds = $this->getRefundTotal();
        if ($refunds) {
            $total = $total - $refunds;
        }

        wpFluent()->table('fluentform_submissions')
            ->where('id', $this->submissionId)
            ->update([
                'total_paid' => $total,
                'updated_at' => current_time('mysql')
            ]);

        // was: its own count of every paid-scope row here, so a $0 trial start became installment one
        if ($subscriptionId) {
            list($installmentCount) = $this->getPaymentCountsAndTotal($subscriptionId);
            $this->updateSubscription($subscriptionId, [
                'bill_count' => $installmentCount
            ]);
        }
    }

    public function getRefundTotal()
    {
        $refunds = wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $this->submissionId)
            ->where('transaction_type', 'refund')
            ->get();

        $total = 0;
        if (count($refunds) > 0) {
            foreach ($refunds as $refund) {
                $total += $refund->payment_total;
            }
        }

        return $total;
    }

    public function changeTransactionStatus($transactionId, $newStatus)
    {
        do_action_deprecated(
            'fluentform_before_transaction_status_change',
            [
                $newStatus,
                $this->getSubmission(),
                $transactionId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/before_transaction_status_change',
            'Use fluentform/before_transaction_status_change instead of fluentform_before_transaction_status_change.'
        );

        do_action(
            'fluentform/before_transaction_status_change',
            $newStatus,
            $this->getSubmission(),
            $transactionId
        );

        wpFluent()->table('fluentform_transactions')
            ->where('id', $transactionId)
            ->update([
                'status'     => $newStatus,
                'updated_at' => current_time('mysql')
            ]);

        do_action_deprecated(
            'fluentform_after_transaction_status_change',
            [
                $newStatus,
                $this->getSubmission(),
                $transactionId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/after_transaction_status_change',
            'Use fluentform/after_transaction_status_change instead of fluentform_after_transaction_status_change.'
        );

        do_action(
            'fluentform/after_transaction_status_change',
            $newStatus,
            $this->getSubmission(),
            $transactionId
        );

        return true;
    }

    public function updateTransaction($transactionId, $data)
    {
        $data['updated_at'] = current_time('mysql');

        return wpFluent()->table('fluentform_transactions')
            ->where('id', $transactionId)
            ->update($data);
    }

    public function completePaymentSubmission($isAjax = true)
    {
        $returnData = $this->getReturnData();
        if ($isAjax) {
            wp_send_json_success($returnData, 200);
        }
        return $returnData;
    }

    public function getReturnData()
    {
        $submission = $this->getSubmission();
        try {
            $submissionService = new SubmissionHandlerService();
            if ($this->getMetaData('is_form_action_fired') == 'yes') {
                $data = $submissionService->getReturnData($submission->id, $this->getForm(),
                    $submission->response);
                
                $returnData = [
                    'insert_id' => $submission->id,
                    'result'    => $data,
                    'error'     => ''
                ];
                if (!isset($_REQUEST['fluentform_payment_api_notify'])) {
                    // now we have to check if we need this user as auto login
                    if ($loginId = $this->getMetaData('_make_auto_login')) {
                        $this->maybeAutoLogin($loginId, $submission);
                    }
                }
            } else {
                $returnData = $submissionService->processSubmissionData(
                    $this->submissionId, $submission->response, $this->getForm()
                );
                $this->setMetaData('is_form_action_fired', 'yes');
            }
            return $returnData;
        
        } catch (\Exception $e) {
            return [
                'insert_id' => $submission->id,
                'result'    => '',
                'error'     => $e->getMessage(),
            ];
        }
        
    }

    public function getSubmission()
    {
        if (!is_null($this->submission)) {
            return $this->submission;
        }

        $submission = wpFluent()->table('fluentform_submissions')
            ->where('id', $this->submissionId)
            ->first();

        if (!$submission) {
            return false;
        }

        $submission->response = json_decode($submission->response, true);

        $this->submission = $submission;

        return $this->submission;

    }

    public function getForm()
    {
        if (!is_null($this->form)) {
            return $this->form;
        }

        $submission = $this->getSubmission();

        $this->form = wpFluent()->table('fluentform_forms')
            ->where('id', $submission->form_id)
            ->first();

        return $this->form;
    }

    public function getOrderItems()
    {
        return wpFluent()->table('fluentform_order_items')
            ->where('submission_id', $this->submissionId)
            ->where('type', '!=', 'discount') // type = single, signup_fee
            ->get();
    }

    public function getDiscountItems()
    {
        return wpFluent()->table('fluentform_order_items')
            ->where('submission_id', $this->submissionId)
            ->where('type', 'discount')
            ->get();
    }

    public function setMetaData($name, $value)
    {
        $value = maybe_serialize($value);

        return wpFluent()->table('fluentform_submission_meta')
            ->insertGetId([
                'response_id' => $this->getSubmissionId(),
                'form_id'     => $this->getForm()->id,
                'meta_key'    => $name,
                'value'       => $value,
                'created_at'  => current_time('mysql'),
                'updated_at'  => current_time('mysql')
            ]);
    }

    public function deleteMetaData($name)
    {
        return wpFluent()->table('fluentform_submission_meta')
            ->where('meta_key', $name)
            ->where('response_id', $this->getSubmissionId())
            ->delete();
    }

    public function getMetaData($metaKey)
    {
        $meta = wpFluent()->table('fluentform_submission_meta')
            ->where('response_id', $this->getSubmissionId())
            ->where('meta_key', $metaKey)
            ->first();

        if ($meta && $meta->value) {
            return maybe_unserialize($meta->value);
        }

        return false;
    }

    public function showPaymentView($returnData)
    {
        $redirectUrl = ArrayHelper::get($returnData, 'result.redirectUrl');
        if ($redirectUrl) {
            wp_redirect($redirectUrl);
            exit();
        }

        $form = $this->getForm();

        if (!empty($returnData['title'])) {
            $title = $returnData['title'];
        } else if ($returnData['type'] == 'success') {
            $title = __('Payment Success', 'fluentformpro');
            $title = apply_filters('fluentform/payment_success_title', $title, $this->getSubmission(), $form);
        } else {
            $title = __('Payment Failed', 'fluentformpro');
            $title = apply_filters('fluentform/payment_failed_title', $title, $this->getSubmission(), $form);
        }

        $message = $returnData['error'];
        if (!$message) {
            $message = $returnData['result']['message'];
        }

        // Apply payment message filter
        if ($message) {
            $message = apply_filters('fluentform/payment_confirmation_message', $message, $this->getSubmission(), $form);
        }

        $data = [
            'status'     => $returnData['type'],
            'form'       => $form,
            'title'      => $title,
            'submission' => $this->getSubmission(),
            'message'    => $message,
            'is_new'     => $returnData['is_new'],
            'data'       => $returnData
        ];

        $data = apply_filters_deprecated(
            'fluentform_frameless_page_data',
            [
                $data
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/frameless_page_data',
            'Use fluentform/frameless_page_data instead of fluentform_frameless_page_data.'
        );

        $data = apply_filters('fluentform/frameless_page_data', $data);

        add_filter('pre_get_document_title', function ($title) use ($data) {
            return $data['title'] . ' ' . apply_filters('document_title_separator', '-') . ' ' . $data['form']->title;
        });

        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style('fluent-form-landing', FLUENTFORMPRO_DIR_URL . 'public/css/frameless.css', [], FLUENTFORMPRO_VERSION);
        });

        status_header(200);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->loadView('frameless_view', $data);
        exit(200);
    }

    public function loadView($view, $data = [])
    {
        $file = FLUENTFORMPRO_DIR_PATH . 'src/views/' . $view . '.php';
        extract($data);
        ob_start();
        include($file);
        return ob_get_clean();
    }

    public function refund($refund_amount, $transaction, $submission, $method = '', $refundId = '', $refundNote = 'Refunded')
    {
        $this->setSubmissionId($submission->id);
        $status = 'refunded';

        $alreadyRefunded = $this->getRefundTotal();
        $totalRefund = intval($refund_amount + $alreadyRefunded);

        if ($totalRefund < $transaction->payment_total) {
            $status = 'partially-refunded';
        }

        $this->changeTransactionStatus($transaction->id, $status);
        $this->changeSubmissionPaymentStatus($status);
        $uniqueHash = wp_generate_password(32, false);

        $refundData = [
            'form_id'          => $submission->form_id,
            'submission_id'    => $submission->id,
            'transaction_hash' => $uniqueHash,
            'payment_method'   => $transaction->payment_method,
            'charge_id'        => $refundId,
            'payment_note'     => $refundNote,
            'payment_total'    => $refund_amount,
            'currency'         => $transaction->currency,
            'payment_mode'     => $transaction->payment_mode,
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
            'status'           => 'refunded',
            'transaction_type' => 'refund'
        ];

        $refundId = $this->insertRefund($refundData);

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Refund issued', 'fluentformpro'),
            'description'      => __('Refund issued and refund amount: ', 'fluentformpro') . number_format($refund_amount / 100, 2)
        ];

        do_action('fluentform/log_data', $logData);

        $this->recalculatePaidTotal();
        $refund = $this->getRefund($refundId);

        do_action_deprecated(
            'fluentform_payment_' . $status . '_' . $method,
            [
                $refund,
                $transaction,
                $submission
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_' . $status . '_' . $method,
            'Use fluentform/payment_' . $status . '_' . $method . ' instead of fluentform_payment_' . $status . '_' . $method
        );

        do_action('fluentform/payment_' . $status . '_' . $method, $refund, $transaction, $submission);

        do_action_deprecated(
            'fluentform_payment_' . $status,
            [
                $refund,
                $transaction,
                $submission
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_' . $status,
            'Use fluentform/payment_' . $status . ' instead of fluentform_payment_' . $status
        );
        do_action('fluentform/payment_' . $status, $refund, $transaction, $submission);
    }

    public function updateRefund($totalRefund, $transaction, $submission, $method = '', $refundId = '', $refundNote = 'Refunded')
    {
        if(!$totalRefund) {
            return;
        }

        $this->setSubmissionId($submission->id);
        $existingRefund = wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $submission->id)
            ->where('transaction_type', 'refund')
            ->first();

        if ($existingRefund) {

            if ($existingRefund->payment_total == $totalRefund) {
                return;
            }

            $status = 'refunded';
            if ($totalRefund < $transaction->payment_total) {
                $status = 'partially-refunded';
            }
            $updateData = [
                'form_id'          => $submission->form_id,
                'submission_id'    => $submission->id,
                'payment_method'   => $transaction->payment_method,
                'charge_id'        => $refundId,
                'payment_note'     => $refundNote,
                'payment_total'    => $totalRefund,
                'payment_mode'     => $transaction->payment_mode,
                'created_at'       => current_time('mysql'),
                'updated_at'       => current_time('mysql'),
                'status'           => 'refunded',
                'transaction_type' => 'refund'
            ];

            wpFluent()->table('fluentform_transactions')->where('id', $existingRefund->id)
                ->update($updateData);

            $existingRefund = wpFluent()->table('fluentform_transactions')
                ->where('submission_id', $submission->id)
                ->where('transaction_type', 'refund')
                ->first();

            if ($transaction->status != $status) {
                $this->changeTransactionStatus($transaction->id, $status);
            }

            do_action_deprecated(
                'fluentform_payment_refund_updated_' . $method,
                [
                    $existingRefund,
                    $existingRefund->form_id
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/payment_refund_updated_' . $method,
                'Use fluentform/payment_refund_updated_' . $method . ' instead of fluentform_payment_refund_updated_' . $method
            );

            do_action('fluentform/payment_refund_updated_' . $method, $existingRefund, $existingRefund->form_id);

            do_action_deprecated(
                'fluentform_payment_refund_updated',
                [
                    $existingRefund,
                    $existingRefund->form_id
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/payment_refund_updated',
                'Use fluentform/payment_refund_updated instead of fluentform_payment_refund_updated.'
            );

            do_action('fluentform/payment_refund_updated', $existingRefund, $existingRefund->form_id);

        } else {
            $this->refund($totalRefund, $transaction, $submission, $method, $refundId, $refundNote);
        }
    }

    private function maybeAutoLogin($loginId, $submission)
    {
        if (is_user_logged_in() || !$loginId) {
            return;
        }
        if ($loginId != $submission->user_id) {
            return;
        }

        wp_clear_auth_cookie();
        wp_set_current_user($loginId);
        wp_set_auth_cookie($loginId);
        $this->deleteMetaData('_make_auto_login');
    }

    public function getAmountTotal()
    {
        $orderItems = $this->getOrderItems();

        $amountTotal = 0;
        foreach ($orderItems as $item) {
            $amountTotal += $item->line_total;
        }

        $discountItems = $this->getDiscountItems();
        foreach ($discountItems as $discountItem) {
            $amountTotal -= $discountItem->line_total;
        }

        return $amountTotal;
    }

    public function handleSessionRedirectBack($data)
    {
        $submissionId = intval($data['fluentform_payment']);
        $this->setSubmissionId($submissionId);

        $submission = $this->getSubmission();

        $transactionHash = sanitize_text_field($data['transaction_hash']);
        $transaction = $this->getTransaction($transactionHash, 'transaction_hash');

        if (!$transaction || !$submission) {
            return;
        }

        $type = $transaction->status;
        $form = $this->getForm();

        if ($type == 'paid') {
            $returnData = $this->getReturnData();
        } else {
            $pendingTitle = __('Payment was not marked as paid', 'fluentformpro');
            $pendingMessage = __('Looks like you have is still on pending status', 'fluentformpro');
            $returnData = [
                'insert_id' => $submission->id,
                'title'     => apply_filters('fluentform/payment_pending_title', $pendingTitle, $submission, $form),
                'result'    => false,
                'error'     => apply_filters('fluentform/payment_pending_message', $pendingMessage, $submission, $form)
            ];
        }

        $returnData['type'] = 'success';
        $returnData['is_new'] = false;

        $this->showPaymentView($returnData);
    }

    public function getSubscriptions($status = false)
    {
        $subscriptions = wpFluent()->table('fluentform_subscriptions')
            ->where('submission_id', $this->submissionId)
            ->when($status, function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->get();

        foreach ($subscriptions as $subscription) {
            $subscription->original_plan = maybe_unserialize($subscription->original_plan);
            $subscription->vendor_response = maybe_unserialize($subscription->vendor_response);
        }

        return $subscriptions;
    }

    public function updateSubscription($id, $data)
    {
        $data['updated_at'] = current_time('mysql');

        return wpFluent()->table('fluentform_subscriptions')
            ->where('id', $id)
            ->update($data);
    }

    public function maybeInsertSubscriptionCharge($item)
    {
        $exists = wpFluent()->table('fluentform_transactions')
            ->where('transaction_type', 'subscription')
            ->where('submission_id', $item['submission_id'])
            ->where('subscription_id', $item['subscription_id'])
            ->where('charge_id', $item['charge_id'])
            ->where('payment_method', $item['payment_method'])
            ->first();

        $isNew = false;

        if ($exists) {
            // We don't want to update the address and payer email that we already have here
            if ($exists->billing_address) {
                unset($item['billing_address']);
            }
            if ($exists->payer_email) {
                unset($item['payer_email']);
            }

            unset($item['transaction_hash']);
            unset($item['created_at']);

            // was: overwrote the status unconditionally, so a replayed success event un-refunded the row
            if (PaymentHelper::isReversedPaymentStatus($exists->status)) {
                unset($item['status']);
            }

            wpFluent()->table('fluentform_transactions')
                ->where('id', $exists->id)
                ->update($item);

            $id = $exists->id;
        } else {
            if (empty($item['created_at'])) {
                $item['created_at'] = current_time('mysql');
                $item['updated_at'] = current_time('mysql');
            }

            if (empty($item['transaction_hash'])) {
                $uniqueHash = wp_generate_password(32, false);
                $item['transaction_hash'] = $uniqueHash;
            }

            $id = wpFluent()->table('fluentform_transactions')->insertGetId($item);
            $isNew = true;
        }


        $transaction = fluentFormApi('submissions')->transaction($id);

        // We want to update the total amount here
        $parentSubscription = wpFluent()->table('fluentform_subscriptions')
            ->where('id', $transaction->subscription_id)
            ->first();

        // Let's count the total subscription payment
        if ($parentSubscription) {
            list($billCount, $paymentTotal) = $this->getPaymentCountsAndTotal($parentSubscription->id);

            wpFluent()->table('fluentform_subscriptions')
                ->where('id', $parentSubscription->id)
                ->update([
                    'bill_count'    => $billCount,
                    'payment_total' => $paymentTotal,
                    'updated_at'    => current_time('mysql')
                ]);

            // was: total_paid took the gross subscription sum here, so a renewal or a replayed invoice
            // after a refund put the refunded amount back; recalculatePaidTotal() is the net authority
            wpFluent()->table('fluentform_submissions')->where('id', $parentSubscription->submission_id)
                ->update([
                    'payment_total' => $paymentTotal,
                ]);
            $this->recalculatePaidTotal();

            $subscription = wpFluent()->table('fluentform_subscriptions')
                ->where('id', $parentSubscription->id)
                ->first();

            // A closed subscription still gets its late payment on the ledger; only the renewal side effects are withheld.
            $isTerminalSubscription = in_array($subscription->status, ['cancelled', 'completed'], true);

            // Announce a received installment only for a settled row, not a requires_review/pending one.
            if ($isNew && !$isTerminalSubscription && PaymentHelper::isFulfillablePaymentStatus($transaction->status)) {
                $submission = $this->getSubmission();
                do_action_deprecated(
                    'fluentform_subscription_received_payment',
                    [
                        $subscription,
                        $submission
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/subscription_received_payment',
                    'Use fluentform/subscription_received_payment instead of fluentform_subscription_received_payment.'
                );
                do_action('fluentform/subscription_received_payment', $subscription, $submission);

                do_action_deprecated(
                    'fluentform_subscription_received_payment_' . $submission->payment_method,
                    [
                        $subscription,
                        $submission
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/subscription_received_payment_' . $submission->payment_method,
                    'Use fluentform/subscription_received_payment_' . $submission->payment_method . ' instead of fluentform_subscription_received_payment_' . $submission->payment_method
                );
                do_action('fluentform/subscription_received_payment_' . $submission->payment_method, $subscription, $submission);
            }

            if ($isNew && $isTerminalSubscription) {
                $submission = $this->getSubmission();
                do_action('fluentform/log_data', [
                    'parent_source_id' => $submission->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'info',
                    'title'            => __('Late subscription payment recorded', 'fluentformpro'),
                    'description'      => __('The subscription is already closed, so no renewal actions were fired.', 'fluentformpro'),
                ]);
            }

            if (!$isTerminalSubscription && $subscription->bill_times > 0 && $subscription->bill_count >= $subscription->bill_times) {
                // We have to mark the subscription as completed
                $this->updateSubscriptionStatus($subscription, 'completed');
            }
        }

        return $id;
    }

    public function getPaymentCountsAndTotal($subscriptionId, $paymentMethod = false)
    {
        // was: every row of any status, so a Pending echeck or a failed attempt counted as an installment
        $payments = wpFluent()
            ->table('fluentform_transactions')
            ->select(['id', 'payment_total', 'payment_note'])
            ->where('transaction_type', 'subscription')
            ->where('subscription_id', $subscriptionId)
            ->whereIn('status', ['paid', 'partially-refunded', 'refunded'])
            ->when($paymentMethod, function ($q) use ($paymentMethod) {
                $q->where('payment_method', $paymentMethod);
            })
            ->get();

        $subscription = wpFluent()->table('fluentform_subscriptions')->find($subscriptionId);
        $hasTrial = $subscription && $subscription->trial_days;

        $installmentCount = 0;
        $paymentTotal = 0;

        foreach ($payments as $payment) {
            $paymentTotal += $payment->payment_total;
            // A $0 trial start is not a billed period; a Stripe renewal invoice can still bill $0.
            if ($payment->payment_total > 0 || $this->isBilledZeroInvoice($payment->payment_note, $hasTrial)) {
                $installmentCount++;
            }
        }

        return [$installmentCount, $paymentTotal];
    }

    protected function isBilledZeroInvoice($paymentNote, $hasTrial)
    {
        $invoice = (array) PaymentHelper::safeUnserialize($paymentNote);
        $reason = $invoice['billing_reason'] ?? null;

        if ('subscription_cycle' === $reason) {
            return true;
        }

        return 'subscription_create' === $reason && !$hasTrial;
    }

    protected function getCancelAtTimeStamp($subscription)
    {
        if (!$subscription->bill_times) {
            return false;
        }

        $dateTime = current_datetime();
        $localtime = $dateTime->getTimestamp() + $dateTime->getOffset();

        $billingStartDate = $localtime;

        if ($subscription->expiration_at) {
            $billingStartDate = strtotime($subscription->expiration_at);
        }

        $billTimes = $subscription->bill_times;

        $interval = $subscription->billing_interval;

        $interValMaps = [
            'day'   => 'days',
            'week'  => 'weeks',
            'month' => 'months',
            'year'  => 'years'
        ];

        if (isset($interValMaps[$interval]) && $billTimes > 1) {
            $interval = $interValMaps[$interval];
        }

        return strtotime('+ ' . $billTimes . ' ' . $interval, $billingStartDate);
    }

    public function updateSubmission($id, $data)
    {
        $data['updated_at'] = current_time('mysql');

        return wpFluent()->table('fluentform_submissions')
            ->where('id', $id)
            ->update($data);
    }

    public function limitLength($string, $limit = 127)
    {
        $str_limit = $limit - 3;
        if (function_exists('mb_strimwidth')) {
            if (mb_strlen($string) > $limit) {
                $string = mb_strimwidth($string, 0, $str_limit) . '...';
            }
        } else {
            if (strlen($string) > $limit) {
                $string = substr($string, 0, $str_limit) . '...';
            }
        }
        return $string;
    }

    public function getTransactionDefaults()
    {
        $submission = $this->getSubmission();
        if (!$submission) {
            return [];
        }

        $data = [];

        if ($customerEmail = PaymentHelper::getCustomerEmail($submission, $this->getForm())) {
            $data['payer_email'] = $customerEmail;
        }

        if ($customerName = PaymentHelper::getCustomerName($submission, $this->getForm())) {
            $data['payer_name'] = $customerName;
        }

        if ($submission->user_id) {
            $data['user_id'] = $submission->user_id;
        } else if ($user = get_user_by('ID', get_current_user_id())) {
            $data['user_id'] = $user->ID;
        }

        // Never link a guest order to an account by unverified payer_email (attacker-typed):
        // ownership comes only from a logged-in buyer (above) or the verified order-claim flow.

        $address = PaymentHelper::getCustomerAddress($submission);
        if (!$address) {
            $address = ArrayHelper::get($submission->response, 'address_1');
        }
        if ($address) {
            $address = array_filter($address);
            if ($address) {
                $data['billing_address'] = implode(', ', $address);
            }
        }

        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');
        $data['form_id'] = $submission->form_id;
        $data['submission_id'] = $submission->id;
        $data['payment_method'] = $this->method;

        return $data;
    }

    public function createInitialPendingTransaction($submission = false, $hasSubscriptions = false)
    {
        if (!$submission) {
            $submission = $this->getSubmission();
        }

        $form = $this->getForm();

        $uniqueHash = wp_generate_password(32, false);

        $transactionData = [
            'transaction_type' => 'onetime',
            'transaction_hash' => $uniqueHash,
            'payment_total'    => $this->getAmountTotal(),
            'status'           => 'pending',
            'currency'         => strtoupper($submission->currency),
            'payment_mode'     => $this->getPaymentMode()
        ];
        if ($hasSubscriptions) {
            $subscriptions = $this->getSubscriptions();
            if (count($subscriptions) > 0) {
                $subscriptionInitialTotal = 0;
                foreach ($subscriptions as $subscription) {
                    if (!$subscription->trial_days) {
                        $subscriptionInitialTotal += $subscription->recurring_amount;
                    }
                    if ($subscription->initial_amount) {
                        $subscriptionInitialTotal += $subscription->initial_amount;
                    }
                    $transactionData['subscription_id'] = $subscription->id;
                }
                $transactionData['payment_total'] += $subscriptionInitialTotal;
                $transactionData['transaction_type'] = 'subscription';
            }
        }

        $transactionId = $this->insertTransaction($transactionData);

        $this->updateSubmission($submission->id, [
            'payment_total' => $transactionData['payment_total']
        ]);

        return $this->getTransaction($transactionId);

    }

    /**
     * @param object $subscription
     * @param string $newStatus
     * @param string $note
     * @return object
     */
    public function updateSubscriptionStatus($subscription, $newStatus, $note = '')
    {
        if(!$note) {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            $note = __('Subscription status has been changed to '.$newStatus.' from '.$subscription->status, 'fluentformpro');
            $note = apply_filters('fluentform/subscription_confirmation_message', $note, [], $this->getForm());
        }

        $oldStatus = $subscription->status;

        if ($oldStatus == $newStatus) {
            return $subscription;
        }

        // A terminal subscription never moves again: a late subscr_eot/Completed must not revive
        // cancelled -> completed (or completed -> cancelled) and fire its status side effects.
        $terminalStatuses = ['cancelled', 'completed'];
        if (in_array($oldStatus, $terminalStatuses)) {
            return $subscription;
        }

        wpFluent()->table('fluentform_subscriptions')
            ->where('id', $subscription->id)
            ->update([
                'status' => $newStatus,
                'updated_at' => current_time('mysql')
            ]);

        $logData = [
            'parent_source_id' => $this->getForm()->id,
            'source_type'      => 'submission_item',
            'source_id'        => $this->submissionId,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => apply_filters('fluentform/recurring_payment_message', __('Subscription Status changed to ', 'fluentformpro') . $newStatus, [], $this->getForm()),
            'description'      => $note
        ];

        do_action('fluentform/log_data', $logData);

        $subscription->status = $newStatus;

        $submission = $this->getSubmission();

        do_action_deprecated(
            'fluentform_subscription_payment_' . $newStatus,
            [
                $subscription,
                $submission,
                false
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/subscription_payment_' . $newStatus,
            'Use fluentform/subscription_payment_' . $newStatus . ' instead of fluentform_subscription_payment_' . $newStatus
        );

        do_action('fluentform/subscription_payment_' . $newStatus, $subscription, $submission, false);

        do_action_deprecated(
            'fluentform_subscription_payment_' . $newStatus . '_' . $submission->payment_method,
            [
                $subscription,
                $submission,
                false
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/subscription_payment_' . $newStatus . '_' . $submission->payment_method,
            'Use fluentform/subscription_payment_' . $newStatus . '_' . $submission->payment_method . ' instead of fluentform_subscription_payment_' . $newStatus . '_' . $submission->payment_method
        );
        do_action('fluentform/subscription_payment_' . $newStatus . '_' . $submission->payment_method, $subscription, $submission, false);

        return $subscription;
    }
}
