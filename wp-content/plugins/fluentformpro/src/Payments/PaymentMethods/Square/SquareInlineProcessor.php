<?php

namespace FluentFormPro\Payments\PaymentMethods\Square;

defined('ABSPATH') or die;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;


class SquareInlineProcessor extends SquareProcessor
{
    public function init()
    {
        add_action('fluentform/process_payment_square_inline', [$this, 'handlePaymentAction'], 10, 6);

        add_filter('fluentform/rendering_field_html_payment_method', [$this, 'checkForApplicationId'], 10, 3);
    }

    public function checkForApplicationId($html, $data, $form)
    {
        if (
            ArrayHelper::get($data, 'settings.payment_methods.square.enabled') == 'yes' &&
            ArrayHelper::get($data, 'settings.payment_methods.square.settings.embedded_checkout.value') == 'yes'
        ) {
            $squareSettings = SquareSettings::getSettings();

            if (SquareSettings::isLive()) {
                $isEmptyApplicationId = empty(ArrayHelper::get($squareSettings, 'live_application_id'));
            } else {
                $isEmptyApplicationId = empty(ArrayHelper::get($squareSettings, 'test_application_id'));
            }

            if ($isEmptyApplicationId) {
                $html = __('Application ID for Square Embedded checkout is missing. Please contact with administrator to setup Square correctly.',
                    'fluentformpro');
            }
        }

        return $html;
    }

    public function handlePaymentAction(
        $submissionId,
        $submissionData,
        $form,
        $methodSettings,
        $hasSubscriptions,
        $totalPayable
    ) {
        $this->setSubmissionId($submissionId);
        $this->form = $form;
        $submission = $this->getSubmission();
        $paymentTotal = $this->getAmountTotal();

        if (!$paymentTotal && !$hasSubscriptions) {
            return false;
        }

        $transaction = $this->createInitialPendingTransaction($submission, $hasSubscriptions);

        $paymentToken = ArrayHelper::get($submissionData['response'], '__square_payment_method_id');
        $verificationToken = ArrayHelper::get($submissionData['response'], '__square_verify_buyer_id');

        $apiKeys = SquareSettings::getApiKeys();

        $customerDetails = $this->getCustomerDetails($submission);

        $paymentArgs = [
            "idempotency_key"     => $transaction->transaction_hash,
            'source_id'           => $paymentToken,
            'verification_token'  => $verificationToken,
            'amount_money'        => [
                'amount'   => PaymentHelper::isZeroDecimal($transaction->currency) ? intval($paymentTotal / 100) : $paymentTotal,
                'currency' => $transaction->currency,
            ],
            'billing_address'     => ArrayHelper::get($customerDetails, 'billing_address'),
            'buyer_email_address' => ArrayHelper::get($customerDetails, 'buyer_email_address'),
            'location_id'         => ArrayHelper::get($apiKeys, 'location_id'),
            'note'                => $this->getProductNames(),
        ];

        $paymentArgs = apply_filters('fluentform/square_payment_args', $paymentArgs, $submission, $transaction, $form);

        $this->handleSquarePayment($transaction, $submission, $paymentArgs);
    }

    public function setSubmissionId($submissionId)
    {
        $this->submissionId = $submissionId;
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

    protected function getCustomerDetails($submission)
    {
        $address = PaymentHelper::getCustomerAddress($submission);
        $receiptName = PaymentHelper::getCustomerName($submission, $this->form);
        $receiptEmail = PaymentHelper::getCustomerEmail($submission, $this->form);

        $customerDetails = [];
        if ($address) {
            $customerDetails['billing_address'] = [
                'address_line_1'                  => ArrayHelper::get($address, 'address_line_1'),
                'address_line_2'                  => ArrayHelper::get($address, 'address_line_2'),
                'locality'                        => ArrayHelper::get($address, 'city'),
                'country'                         => ArrayHelper::get($address, 'country'),
                'postal_code'                     => ArrayHelper::get($address, 'zip'),
                'administrative_district_level_1' => ArrayHelper::get($address, 'state')
            ];
        }

        if ($receiptName) {
            if (strpos($receiptName, ' ') !== false) {
                $namesArray = explode(' ', $receiptName);
                $customerDetails['billing_address']['first_name'] = ArrayHelper::get($namesArray, 0);
                $customerDetails['billing_address']['last_name'] = ArrayHelper::get($namesArray, 1);
            }
        }

        if ($receiptEmail) {
            $customerDetails['buyer_email_address'] = $receiptEmail;
        }

        return $customerDetails;
    }

    protected function handleSquarePayment($transaction, $submission, $intentArgs)
    {
        $intent = (new API())->makeApiCall('payments', $intentArgs, $this->form->Id, 'POST', true);
        $charge = ArrayHelper::get($intent, 'payment');

        if (is_wp_error($intent) || !$charge) {
            $message = $intent->get_error_message() ?: __('Payment Failed! Your card may have been declined.', 'fluentformpro');

            $this->handlePaymentChargeError($message, $submission, $transaction, false, 'payment_intent');
        }

        // Only an explicitly COMPLETED Square payment is settled. Anything else — a
        // non-completed charge (APPROVED/PENDING/…) OR a missing/empty status from a
        // malformed response — must not mark the order paid (fail closed).
        $chargeStatus = ArrayHelper::get($charge, 'status');
        if ($chargeStatus !== 'COMPLETED') {
            $this->handlePaymentChargeError(
                __('Your payment has not been completed. Please try again.', 'fluentformpro'),
                $submission,
                $transaction,
                $charge,
                'payment_intent'
            );

            return;
        }

        $this->handlePaymentSuccess($charge, $transaction, $submission);
    }

    protected function handlePaymentSuccess($charge, $transaction, $submission)
    {
        $chargeId = ArrayHelper::get($charge, 'id');

        $transactionData = [
            'charge_id'      => $chargeId,
            'payment_method' => 'square',
            'payment_mode'   => $this->getPaymentMode(),
            'payment_note'   => maybe_serialize($charge)
        ];

        $methodDetails = ArrayHelper::get($charge, 'card_details');
        if ($methodDetails) {
            $transactionData['card_brand'] = ArrayHelper::get($methodDetails, 'card_brand');
            $transactionData['card_last_4'] = ArrayHelper::get($methodDetails, 'last_4');
        }

        $this->updateTransaction($transaction->id, $transactionData);

        $this->changeTransactionStatus($transaction->id, 'paid');

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Payment Status changed', 'fluentformpro'),
            'description'      => __('Payment status changed to paid', 'fluentformpro')
        ];

        do_action('fluentform/log_data', $logData);

        $this->updateSubmission($submission->id, [
            'payment_status' => 'paid',
            'payment_method' => 'square',
        ]);

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'success',
            'title'            => __('Payment Complete', 'fluentformpro'),
            'description'      => __('Payment Successfully made via Square. Charge ID: ', 'fluentformpro') . $chargeId
        ];

        do_action('fluentform/log_data', $logData);

        $this->recalculatePaidTotal();

        $this->sendSuccess();
    }

    protected function handlePaymentChargeError($message, $submission, $transaction, $charge = false, $type = 'general')
    {
        do_action('fluentform/payment_square_inline_failed', $submission, $transaction, $this->form->id, $charge, $type);

        do_action('fluentform/payment_failed', $submission, $transaction, $this->form->id, $charge, $type);

        if ($transaction) {
            $this->changeTransactionStatus($transaction->id, 'failed');
        }

        $this->changeSubmissionPaymentStatus('failed');

        if ($message) {
            $logData = [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Square Payment Error', 'fluentformpro'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'description'      => __($message, 'fluentformpro')
            ];

            do_action('fluentform/log_data', $logData);
        }

        wp_send_json([
            'errors'      => __('Square Error: ', 'fluentformpro') . $message
        ], 423);
    }

    protected function sendSuccess()
    {
        try {
            $returnData = $this->getReturnData();
            wp_send_json_success($returnData, 200);
        } catch (\Exception $e) {
            wp_send_json([
                'errors' => $e->getMessage()
            ], 423);
        }
    }
}
