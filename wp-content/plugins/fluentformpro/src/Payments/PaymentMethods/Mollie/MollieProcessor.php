<?php

namespace FluentFormPro\Payments\PaymentMethods\Mollie;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentMethods\Mollie\API\IPN;

class MollieProcessor extends BaseProcessor
{
    public $method = 'mollie';

    protected $form;

    public function init()
    {
        add_action('fluentform/process_payment_' . $this->method, array($this, 'handlePaymentAction'), 10, 6);
        add_action('fluentform/payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));

        add_action('fluentform/ipn_endpoint_' . $this->method, function () {
            (new IPN())->verifyIPN();
            exit(200);
        });

        // Keep webhook only for refunds since they happen from Mollie dashboard
        add_action('fluentform/ipn_mollie_action_refunded', array($this, 'handleRefund'), 10, 3);

	    add_filter(
		    'fluentform/validate_payment_items_' . $this->method,
		    [$this, 'validateSubmittedItems'], 10, 4
	    );
    }

    public function handlePaymentAction($submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable)
    {
        $this->setSubmissionId($submissionId);
        $this->form = $form;
        $submission = $this->getSubmission();

        if ($hasSubscriptions) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('Skip Subscription Item', 'fluentformpro'),
                'description'      => __('Mollie does not support subscriptions right now!', 'fluentformpro')
            ]);
        }

        $uniqueHash = wp_generate_password(32, false);

        $transactionId = $this->insertTransaction([
            'transaction_type' => 'onetime',
            'transaction_hash' => $uniqueHash,
            'payment_total'    => $this->getAmountTotal(),
            'status'           => 'pending',
            'currency'         => PaymentHelper::getFormCurrency($form->id),
            'payment_mode'     => $this->getPaymentMode()
        ]);

        $transaction = $this->getTransaction($transactionId);

        $this->handleRedirect($transaction, $submission, $form, $methodSettings);
    }

    public function handleRedirect($transaction, $submission, $form, $methodSettings)
    {
        $successUrl = add_query_arg(array(
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'success'
        ), site_url('/'));

        $ipnDomain = site_url('index.php');
        if(defined('FLUENTFORM_PAY_IPN_DOMAIN') && FLUENTFORM_PAY_IPN_DOMAIN) {
            $ipnDomain = FLUENTFORM_PAY_IPN_DOMAIN;
        }

        $listener_url = add_query_arg(array(
            'fluentform_payment_api_notify' => 1,
            'payment_method'                => $this->method,
            'submission_id'                 => $submission->id,
            'transaction_hash'              => $transaction->transaction_hash,
        ), $ipnDomain);

        $paymentArgs = array(
            'amount' => [
                'currency' => $transaction->currency,
                'value' => number_format((float) $transaction->payment_total / 100, 2, '.', '')
            ],
            'description' => $form->title,
            'redirectUrl' => $successUrl,
            'webhookUrl' => $listener_url,
            'metadata' => json_encode([
                'form_id' => $form->id,
                'submission_id' => $submission->id
            ]),
            'sequenceType' => 'oneoff'
        );

        $paymentArgs = apply_filters_deprecated(
            'fluentform_mollie_payment_args',
            [
                $paymentArgs,
                $submission,
                $transaction,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/mollie_payment_args',
            'Use fluentform/mollie_payment_args instead of fluentform_mollie_payment_args'
        );

        $paymentArgs = apply_filters('fluentform/mollie_payment_args', $paymentArgs, $submission, $transaction, $form);
        $paymentIntent = (new IPN())->makeApiCall('payments', $paymentArgs, $form->id, 'POST');

        if(is_wp_error($paymentIntent)) {
            $logData = [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Mollie Payment Redirect Error', 'fluentformpro'),
                'description'      => $paymentIntent->get_error_message()
            ];

            do_action('fluentform/log_data', $logData);
            wp_send_json_success([
                'message'      => $paymentIntent->get_error_message()
            ], 423);
        }

        Helper::setSubmissionMeta($submission->id, '_mollie_payment_id', $paymentIntent['id']);

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Redirect to Mollie', 'fluentformpro'),
            'description'      => __('User redirect to Mollie for completing the payment', 'fluentformpro')
        ];

        do_action('fluentform/log_data', $logData);

        wp_send_json_success([
            'nextAction'   => 'payment',
            'actionName'   => 'normalRedirect',
            'redirect_url' => $paymentIntent['_links']['checkout']['href'],
            'message'      => __('You are redirecting to Mollie.com to complete the purchase. Please wait while you are redirecting....', 'fluentformpro'),
            'result'       => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }

    protected function getPaymentMode($formId = false)
    {
        $isLive = MollieSettings::isLive($formId);
        if($isLive) {
            return 'live';
        }
        return 'test';
    }

    public function handlePaid($submission, $vendorTransaction)
    {
        $this->setSubmissionId($submission->id);
        $transaction = $this->getLastTransaction($submission->id);

        if (!$transaction || $transaction->payment_method != $this->method) {
            return;
        }

        // Check if actions are fired
        if ($this->getMetaData('is_form_action_fired') == 'yes') {
            return;
        }

        // If vendorTransaction is not provided, try to fetch it from Mollie API
        if (!$vendorTransaction || empty($vendorTransaction['id'])) {
            $molliePaymentId = Helper::getSubmissionMeta($submission->id, '_mollie_payment_id');
            if ($molliePaymentId) {
                $vendorTransaction = (new API\IPN())->makeApiCall('payments/' . $molliePaymentId, [], $submission->form_id, 'GET');
            }
        }

        $status = 'paid';

        // Verify payment amount matches expected amount
        if (!empty($vendorTransaction['amount']['value']) && $transaction->payment_total) {
            $mollieAmountInCents = intval(round(floatval($vendorTransaction['amount']['value']) * 100));
            if ($mollieAmountInCents !== intval($transaction->payment_total)) {
                $status = 'requires_review';
                do_action('fluentform/log_data', [
                    'parent_source_id' => $submission->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'error',
                    'title'            => __('Mollie Amount Mismatch', 'fluentformpro'),
                    'description'      => sprintf(
                        // translators: %1$d is the expected amount in cents, %2$d is the Mollie reported amount in cents
                        __('Expected %1$d cents but Mollie reported %2$d cents. Payment marked for review.', 'fluentformpro'),
                        intval($transaction->payment_total),
                        $mollieAmountInCents
                    )
                ]);
            }
        }

        // Let's make the payment as paid
        $updateData = [
            'payment_note'     => maybe_serialize($vendorTransaction),
            'charge_id'        => sanitize_text_field($vendorTransaction['id']),
        ];

        $this->updateTransaction($transaction->id, $updateData);
        $this->changeSubmissionPaymentStatus($status);
        $this->changeTransactionStatus($transaction->id, $status);
        $this->recalculatePaidTotal();
        $this->completePaymentSubmission(false);
        $this->setMetaData('is_form_action_fired', 'yes');
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

        $isNew = false;

        // Always check with Mollie API for current payment status
        $molliePaymentId = Helper::getSubmissionMeta($submission->id, '_mollie_payment_id');

        if ($molliePaymentId) {
            $vendorTransaction = (new API\IPN())->makeApiCall('payments/' . $molliePaymentId, [], $submission->form_id, 'GET');

            if (!is_wp_error($vendorTransaction)) {
                $mollieStatus = $vendorTransaction['status'];

                if ($mollieStatus === 'paid') {
                    // Payment is paid, process it
                    $this->handlePaid($submission, $vendorTransaction);
                    $isNew = true;
                    $returnData = $this->getReturnData();
                } elseif ($mollieStatus === 'authorized') {
                    // Payment is authorized, treat as paid
                    $this->handleAuthorized($submission, $vendorTransaction);
                    $isNew = true;
                    $returnData = $this->getReturnData();
                } elseif (in_array($mollieStatus, ['canceled', 'expired', 'failed'])) {
                    // Payment failed/canceled/expired
                    $this->handleFailedPayment($submission, $vendorTransaction, $mollieStatus);
                    $returnData = [
                        'insert_id' => $submission->id,
                        'title'     => __('Payment Failed', 'fluentformpro'),
                        'result'    => false,
                        // translators: %s is the payment status
                        'error'     => sprintf(__('Payment %s. Please try again.', 'fluentformpro'), $mollieStatus)
                    ];
                } else {
                    // Payment still pending
                    $returnData = [
                        'insert_id' => $submission->id,
                        'title'     => __('Payment is being processed', 'fluentformpro'),
                        'result'    => false,
                        'error'     => __('Your payment is being processed. Please check back in a few minutes.', 'fluentformpro')
                    ];
                }
            } else {
                // API call failed
                $returnData = [
                    'insert_id' => $submission->id,
                    'title'     => __('Payment verification failed', 'fluentformpro'),
                    'result'    => false,
                    'error'     => __('Unable to verify payment status. Please contact support if payment was deducted.', 'fluentformpro')
                ];
            }
        } else {
            // No Mollie payment ID found
            $returnData = [
                'insert_id' => $submission->id,
                'title'     => __('Payment was not marked as paid', 'fluentformpro'),
                'result'    => false,
                'error'     => __('Unable to verify payment status. Please contact support.', 'fluentformpro')
            ];
        }

        $returnData['type'] = 'success';
        $returnData['is_new'] = $isNew;

        $this->showPaymentView($returnData);
    }

    public function handleAuthorized($submission, $vendorTransaction)
    {
        $this->setSubmissionId($submission->id);
        $transaction = $this->getLastTransaction($submission->id);

        if (!$transaction || $transaction->payment_method != $this->method) {
            return;
        }

        // Check if actions are fired
        if ($this->getMetaData('is_form_action_fired') == 'yes') {
            return;
        }

        $status = 'paid'; // Treat authorized as paid for FluentForm

        // Let's make the payment as paid
        $updateData = [
            'payment_note'     => maybe_serialize($vendorTransaction),
            'charge_id'        => sanitize_text_field($vendorTransaction['id']),
        ];

        $this->updateTransaction($transaction->id, $updateData);
        $this->changeSubmissionPaymentStatus($status);
        $this->changeTransactionStatus($transaction->id, $status);
        $this->recalculatePaidTotal();
        $this->completePaymentSubmission(false);
        $this->setMetaData('is_form_action_fired', 'yes');
    }

    public function handleFailedPayment($submission, $vendorTransaction, $status)
    {
        $this->setSubmissionId($submission->id);
        $transaction = $this->getLastTransaction($submission->id);

        if (!$transaction || $transaction->payment_method != $this->method) {
            return;
        }

        // Update transaction with failure details
        $updateData = [
            'payment_note' => maybe_serialize($vendorTransaction),
            'charge_id'    => sanitize_text_field($vendorTransaction['id']),
        ];

        $this->updateTransaction($transaction->id, $updateData);

        // Set appropriate status based on Mollie status
        $transactionStatus = ($status === 'canceled') ? 'cancelled' : 'failed';
        $this->changeTransactionStatus($transaction->id, $transactionStatus);
        $this->changeSubmissionPaymentStatus($transactionStatus);
    }

    public function handleRefund($refundAmount, $submission, $vendorTransaction)
    {
        $this->setSubmissionId($submission->id);
        $transaction = $this->getLastTransaction($submission->id);
        $this->updateRefund($refundAmount, $transaction, $submission, $this->method);
    }

	public function validateSubmittedItems($errors, $paymentItems, $subscriptionItems, $form)
	{
        $singleItemTotal = 0;
        foreach ($paymentItems as $paymentItem) {
            if ($paymentItem['line_total']) {
                $singleItemTotal += $paymentItem['line_total'];
            }
        }
        if (count($subscriptionItems) && !$singleItemTotal) {
            $errorMessage = __('Mollie Error: Mollie does not support subscriptions right now!', 'fluentformpro');
            $errors[] = apply_filters('fluentform/payment_error_message', $errorMessage, null, $form);
        }
        return $errors;
    }
}
