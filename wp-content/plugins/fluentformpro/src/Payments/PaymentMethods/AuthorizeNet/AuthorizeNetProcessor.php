<?php

namespace FluentFormPro\Payments\PaymentMethods\AuthorizeNet;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentMethods\AuthorizeNet\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class AuthorizeNetProcessor extends BaseProcessor
{
    public $method = 'authorizenet';

    protected $form;

    public function init()
    {
        add_action('fluentform/process_payment_' . $this->method, [$this, 'handlePaymentAction'], 10, 6);
        add_action('fluentform/payment_frameless_' . $this->method, [$this, 'handleRedirectBack']);
        add_action('fluentform/rendering_payment_method_' . $this->method, [$this, 'addCheckoutJs'], 10, 3);
        add_action('wp_ajax_fluentform_authorizenet_process_payment', [$this, 'processAuthorizeNetPayment']);
        add_action('wp_ajax_nopriv_fluentform_authorizenet_process_payment', [$this, 'processAuthorizeNetPayment']);

        add_action('fluentform/handle_authorizenet_transaction_ipn', [$this, 'handleTransactionIpn'], 10, 1);

        // Register REST API endpoint for webhooks
        add_action('rest_api_init', [$this, 'registerWebhookEndpoint']);
    }

    /**
     * Register REST API endpoint for Authorize.Net webhooks.
     *
     * Signature verification runs in the permission_callback so the request
     * is rejected with a proper 4xx response before the handler runs. This
     * is the WP-recommended pattern (works on all WP versions since 4.7;
     * required to avoid _doing_it_wrong notices on WP 6.4+ / WP 7).
     *
     * @return void
     */
    public function registerWebhookEndpoint()
    {
        register_rest_route('fluentform/v1', '/authorizenet/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleWebhookRequest'],
            'permission_callback' => [$this, 'authorizeWebhookRequest'],
        ]);
    }

    /**
     * Permission callback: validate the Authorize.Net webhook signature.
     * Returns true on a verified request, WP_Error on a verification failure.
     * Logs failures so admins can see rejected webhooks in the FF log.
     *
     * @return true|\WP_Error
     */
    public function authorizeWebhookRequest()
    {
        $ipn = new \FluentFormPro\Payments\PaymentMethods\AuthorizeNet\API\IPN();
        $result = $ipn->validateRequest();

        if (is_wp_error($result)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => 0,
                'source_type'      => 'submission_item',
                'source_id'        => 0,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Authorize.Net Webhook Verification Failed', 'fluentformpro'),
                'description'      => $result->get_error_message()
            ]);
        }

        return $result;
    }

    /**
     * Handle a verified Authorize.Net webhook. Signature has already been
     * checked in the permission_callback; this method only dispatches the
     * IPN event to listeners.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handleWebhookRequest($request)
    {
        $ipn = new \FluentFormPro\Payments\PaymentMethods\AuthorizeNet\API\IPN();
        $ipn->dispatchFromRequest();

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Webhook processed successfully'
        ], 200);
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

        // Authorize.Net integration focuses on one-time payments only
        if ($hasSubscriptions) {
            wp_send_json_error([
                'message' => __('Authorize.Net integration does not support subscriptions. Please use one-time payments only.',
                    'fluentformpro')
            ], 422);
        }

        $transaction = $this->createInitialPendingTransaction();

        $hostedFormData = $this->createHostedPaymentForm($submission, $transaction, $form);

        if (is_wp_error($hostedFormData)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Authorize.Net API Error', 'fluentformpro'),
                'description'      => $hostedFormData->get_error_message()
            ]);

            wp_send_json_error([
                'message' => $hostedFormData->get_error_message()
            ], 422);
        }

        // Store transaction data for later verification
        $this->setMetaData('authorizenet_client_key', ArrayHelper::get($hostedFormData, 'client_key', ''));

        do_action('fluentform/log_data', [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Authorize.Net Payment Initiated', 'fluentformpro'),
            'description'      => __('Authorize.Net Accept.js payment form initiated for user', 'fluentformpro')
        ]);

        // Use Accept.js approach - return modal data for client-side tokenization
        $this->maybeShowModal($transaction, $submission, $form, $hostedFormData);
    }

    protected function createHostedPaymentForm($submission, $transaction, $form)
    {
        $successUrl = add_query_arg(array(
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'success'
        ), site_url('/'));

        $cancelUrl = add_query_arg(array(
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'cancel'
        ), site_url('/'));

        // Format amount for Authorize.Net (convert from cents to dollars)
        $amount = API::formatAmount($transaction->payment_total);

        // Get customer information
        $customerName = PaymentHelper::getCustomerName($submission, $form);
        $customerEmail = PaymentHelper::getCustomerEmail($submission, $form);

        // Parse customer name into first and last name
        $nameParts = explode(' ', trim($customerName), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        // Prepare hosted form data
        $hostedFormData = [
            'amount'              => $amount,
            'currency'            => $transaction->currency,
            'description'         => $form->title . ' - Submission #' . $submission->id,
            'invoice_number'      => 'FF-' . $submission->id,
            'customer_name'       => $customerName,
            'customer_first_name' => $firstName,
            'customer_last_name'  => $lastName,
            'customer_email'      => $customerEmail,
            'return_url'          => $successUrl,
            'cancel_url'          => $cancelUrl,
            'transaction_hash'    => $transaction->transaction_hash
        ];

        // Create hosted payment form through API
        $api = new API($form->id);

        // First validate credentials
        if (!$api->validateCredentials()) {
            return new \WP_Error(422,
                __('Authorize.Net API credentials are invalid. Please check your API Login ID and Transaction Key in the payment settings.',
                    'fluentformpro'));
        }

        $response = $api->createHostedPaymentForm($hostedFormData);

        if (is_wp_error($response)) {
            return $response;
        }

        return $response;
    }

    public function handleRedirectBack($data)
    {
        $submissionId = intval($data['fluentform_payment']);
        $this->setSubmissionId($submissionId);

        $submission = $this->getSubmission();
        $transactionHash = sanitize_text_field($data['transaction_hash']);
        $transaction = $this->getTransaction($transactionHash, 'transaction_hash');

        if (!$transaction || !$submission) {
            wp_send_json_error([
                'message' => __('Invalid transaction data', 'fluentformpro')
            ]);
        }

        $type = ArrayHelper::get($data, 'type', 'success');
        $form = $this->getForm();

        if ($type == 'success') {
            // Verify the payment with Authorize.Net
            $chargeId = ArrayHelper::get($data, 'transId');

            if ($chargeId) {
                // Get transaction details from Authorize.Net
                $api = new API($form->id);
                $transactionDetails = $api->getTransactionDetails($chargeId);

                if (!is_wp_error($transactionDetails)) {
                    $transactionInfo = ArrayHelper::get($transactionDetails, 'transaction', []);
                    $transactionStatus = ArrayHelper::get($transactionInfo, 'transactionStatus');

                    if ($transactionStatus === 'settledSuccessfully' || $transactionStatus === 'capturedPendingSettlement') {
                        // SECURITY (PRO-03): this return path marked the order paid purely on a
                        // settled transId supplied in the URL, with no amount check — so any cheap
                        // settled transaction id under the merchant account could mark an expensive
                        // order paid. Verify the gateway-reported amount equals the recorded total.
                        $authAmount = ArrayHelper::get($transactionInfo, 'authAmount', ArrayHelper::get($transactionInfo, 'settleAmount'));
                        $authCents = (int) round(((float) $authAmount) * 100);
                        if ((int) $transaction->payment_total && $authCents !== (int) $transaction->payment_total) {
                            do_action('fluentform/log_data', [
                                'parent_source_id' => $transaction->form_id,
                                'source_type'      => 'submission_item',
                                'source_id'        => $transaction->submission_id,
                                'component'        => 'Payment',
                                'status'           => 'error',
                                'title'            => __('Authorize.Net Amount Mismatch', 'fluentformpro'),
                                'description'      => sprintf(
                                    // translators: %1$d is the expected amount, %2$d is the gateway-reported amount
                                    __('Expected %1$d but the gateway reported %2$d. Payment rejected.', 'fluentformpro'),
                                    (int) $transaction->payment_total,
                                    $authCents
                                ),
                            ]);
                            wp_send_json_error([
                                'message' => __('Payment amount verification failed.', 'fluentformpro')
                            ]);
                        }

                        // createTransaction stamps order.invoiceNumber = FF-{submission_id} on every
                        // charge. Without binding on it, any settled transId of the same amount under
                        // the merchant account marks this order paid. Reject any charge whose invoice
                        // does not belong to this submission.
                        $gatewayInvoice = ArrayHelper::get($transactionInfo, 'order.invoiceNumber');
                        $expectedInvoice = 'FF-' . $transaction->submission_id;

                        if (!$gatewayInvoice || $gatewayInvoice !== $expectedInvoice) {
                            do_action('fluentform/log_data', [
                                'parent_source_id' => $transaction->form_id,
                                'source_type'      => 'submission_item',
                                'source_id'        => $transaction->submission_id,
                                'component'        => 'Payment',
                                'status'           => 'error',
                                'title'            => __('Authorize.Net Order Mismatch', 'fluentformpro'),
                                'description'      => sprintf(
                                    // translators: %1$s is the expected invoice, %2$s is the gateway-reported invoice
                                    __('Charge does not belong to this submission. Expected invoice %1$s but the gateway reported %2$s. Payment rejected.', 'fluentformpro'),
                                    $expectedInvoice,
                                    $gatewayInvoice ?: '(none)'
                                ),
                            ]);
                            wp_send_json_error([
                                'message' => __('Payment verification failed.', 'fluentformpro')
                            ]);
                        }

                        // Serialize the duplicate check and the charge_id write so two concurrent
                        // replays of the same transId cannot both pass the check and both mark paid.
                        global $wpdb;
                        $chargeLock = 'ff_ffcharge_' . md5('authorizenet|' . $chargeId);
                        $lockState = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $chargeLock));

                        // '0' means another confirmation holds the lock (a replay flood is the expected
                        // cause), so a paid-state change must fail closed rather than race an
                        // unserialized write. '1' acquired it; NULL means the engine has no user locks
                        // (e.g. SQLite) — fall through to the duplicate check, which together with the
                        // invoice binding above still blocks cross-order reuse.
                        if ($lockState === '0') {
                            do_action('fluentform/log_data', [
                                'parent_source_id' => $transaction->form_id,
                                'source_type'      => 'submission_item',
                                'source_id'        => $transaction->submission_id,
                                'component'        => 'Payment',
                                'status'           => 'error',
                                'title'            => __('Authorize.Net Charge Lock Contention', 'fluentformpro'),
                                'description'      => sprintf(
                                    // translators: %s is the charge id
                                    __('Could not obtain an exclusive lock to confirm charge %s. Payment rejected.', 'fluentformpro'),
                                    $chargeId
                                ),
                            ]);
                            wp_send_json_error([
                                'message' => __('Payment verification is busy. Please try again.', 'fluentformpro')
                            ]);
                        }

                        try {
                            // A same-transaction replay lands on its own row, which the duplicate query
                            // excludes, so short-circuit when this row is already paid instead of
                            // re-running the paid transition and re-firing its hooks.
                            $currentRow = wpFluent()->table('fluentform_transactions')
                                ->where('id', $transaction->id)
                                ->first();

                            // A reversed order (refunded/partially-refunded/cancelled) must not be
                            // resurrected to paid by a replayed success URL. The webhook path guards
                            // this; the redirect path must too. The Pro helper facade keeps older
                            // Free versions compatible.
                            $isReversedRow = $currentRow
                                && PaymentHelper::isReversedPaymentStatus($currentRow->status);

                            if (!$isReversedRow && (!$currentRow || $currentRow->status !== 'paid')) {
                                // Reject reuse of a charge already recorded on another local transaction,
                                // so one settled transId cannot mark several orders paid.
                                $duplicateCharge = wpFluent()->table('fluentform_transactions')
                                    ->where('charge_id', $chargeId)
                                    ->where('payment_method', 'authorizenet')
                                    ->where('id', '!=', $transaction->id)
                                    ->first();

                                if ($duplicateCharge) {
                                    if ($lockState === '1') {
                                        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $chargeLock));
                                    }
                                    do_action('fluentform/log_data', [
                                        'parent_source_id' => $transaction->form_id,
                                        'source_type'      => 'submission_item',
                                        'source_id'        => $transaction->submission_id,
                                        'component'        => 'Payment',
                                        'status'           => 'error',
                                        'title'            => __('Authorize.Net Duplicate Charge', 'fluentformpro'),
                                        'description'      => sprintf(
                                            // translators: %s is the reused charge id
                                            __('Charge %s is already recorded on another transaction. Payment rejected.', 'fluentformpro'),
                                            $chargeId
                                        ),
                                    ]);
                                    wp_send_json_error([
                                        'message' => __('Payment verification failed.', 'fluentformpro')
                                    ]);
                                }

                                $this->updateTransaction($transaction->id, [
                                    'status'       => 'paid',
                                    'charge_id'    => $chargeId,
                                    'payment_note' => 'Payment completed via Authorize.Net hosted form. Status: ' . $transactionStatus
                                ]);

                                $this->changeSubmissionPaymentStatus('paid');
                                $this->recalculatePaidTotal();
                            }
                        } finally {
                            if ($lockState === '1') {
                                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $chargeLock));
                            }
                        }

                        $returnData = $this->getReturnData();
                        $returnData['type'] = 'success';
                        $returnData['is_new'] = false;
                    } else {
                        $returnData = [
                            'insert_id' => $submission->id,
                            'title'     => __('Payment Status Unclear', 'fluentformpro'),
                            'result'    => false,
                            'error'     => __('Payment status: ', 'fluentformpro') . $transactionStatus,
                            'type'      => 'error',
                            'is_new'    => false
                        ];
                    }
                } else {
                    $returnData = [
                        'insert_id' => $submission->id,
                        'title'     => __('Payment Verification Failed', 'fluentformpro'),
                        'result'    => false,
                        'error'     => __('Could not verify payment with Authorize.Net', 'fluentformpro'),
                        'type'      => 'error',
                        'is_new'    => false
                    ];
                }
            } else {
                // No transaction ID provided - do NOT assume success
                // Keep as processing and rely on webhook to confirm
                $returnData = [
                    'insert_id' => $submission->id,
                    'title'     => __('Payment Processing', 'fluentformpro'),
                    'result'    => false,
                    'error'     => __('Your payment is being processed. You will receive a confirmation once the payment is verified.', 'fluentformpro'),
                    'type'      => 'info',
                    'is_new'    => false
                ];
            }
        } else {
            // Payment was cancelled
            $returnData = [
                'insert_id' => $submission->id,
                'title'     => __('Payment Cancelled', 'fluentformpro'),
                'result'    => false,
                'error'     => __('Payment was cancelled by user', 'fluentformpro'),
                'type'      => 'error',
                'is_new'    => false
            ];
        }

        $this->showPaymentView($returnData);
    }

    // IPN handlers for webhook events
    public function handleTransactionIpn($data)
    {
        if (!$data) {
            return;
        }

        $eventType = $data->eventType;
        $payload = $data->payload;

        $chargeId = $payload->id;

        $transaction = wpFluent()->table('fluentform_transactions')
            ->where('charge_id', $chargeId)
            ->where('payment_method', 'authorizenet')
            ->first();

        if (!$transaction) {
            return;
        }

        // net.authorize.payment.fraud.approved get fraud approved from this
        $eventType = str_replace('net.authorize.payment.', '', $eventType);
        $eventType = str_replace('.', '_', $eventType);

        // A replay must not resurrect a settled transaction. 'capture_created' delegates to
        // 'authcapture_created', so it belongs here too; 'paid' and 'failed' are not terminal.
        $promotingEvents = [
            'authorization_created',
            'priorAuthCapture_created',
            'authcapture_created',
            'capture_created',
            'fraud_approved',
        ];

        if (
            in_array($eventType, $promotingEvents, true)
            && in_array($transaction->status, ['refunded', 'partially-refunded', 'cancelled'], true)
        ) {
            return;
        }

        if (method_exists($this, $eventType)) {
            $this->$eventType($transaction, $payload);
        } else {
            return;
        }
    }

    // Event handlers for different webhook events
    public function authorization_created($transaction, $payload)
    {
        $transactionId = $payload->id;
        $responseCode = $payload->responseCode ?? '';

        $this->setSubmissionId($transaction->submission_id);

        if ($responseCode == '1') {
            $this->changeTransactionStatus($transaction->id, 'pending');
            $this->changeSubmissionPaymentStatus('pending');

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'success',
                'title'            => __('Authorize.Net Payment Authorized', 'fluentformpro'),
                'description'      => 'Payment authorized successfully via webhook. Transaction ID: ' . $transactionId
            ]);
        } elseif ($responseCode == '4') {
            $this->changeTransactionStatus($transaction->id, 'pending');
            $this->changeSubmissionPaymentStatus('pending');

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('Authorize.Net Payment Held for Review', 'fluentformpro'),
                'description'      => 'Payment authorization held for review via webhook. Transaction ID: ' . $transactionId
            ]);
        } else {
            $this->changeTransactionStatus($transaction->id, 'failed');
            $this->changeSubmissionPaymentStatus('failed');

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Authorize.Net Payment Authorization Failed', 'fluentformpro'),
                'description'      => 'Payment authorization failed via webhook. Transaction ID: ' . $transactionId . ' | Response Code: ' . $responseCode
            ]);
        }
    }

    public function priorAuthCapture_created($transaction, $payload)
    {
        $transactionId = $payload->id;
        $responseCode = $payload->responseCode ?? '';

        $this->setSubmissionId($transaction->submission_id);

        if ($responseCode == '1') {
            $this->changeTransactionStatus($transaction->id, 'paid');
            $this->changeSubmissionPaymentStatus('paid');
            $this->recalculatePaidTotal();

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'success',
                'title'            => __('Authorize.Net Payment Captured (Prior Auth)', 'fluentformpro'),
                'description'      => 'Payment captured successfully via prior auth webhook. Transaction ID: ' . $transactionId
            ]);

            $this->completePaymentSubmission(false);
            $this->setMetaData('is_form_action_fired', 'yes');
        } elseif ($responseCode == '4') {
            $this->changeTransactionStatus($transaction->id, 'pending');
            $this->changeSubmissionPaymentStatus('pending');

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('Authorize.Net Payment Held for Review', 'fluentformpro'),
                'description'      => 'Payment capture held for review via prior auth webhook. Transaction ID: ' . $transactionId
            ]);
        } else {
            $this->changeTransactionStatus($transaction->id, 'failed');

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Authorize.Net Payment Capture Failed (Prior Auth)', 'fluentformpro'),
                'description'      => 'Payment capture failed via prior auth webhook. Transaction ID: ' . $transactionId . ' | Response Code: ' . $responseCode
            ]);
        }
    }

    public function authcapture_created($transaction, $payload)
    {
        $transactionId = $payload->id;
        $responseCode = $payload->responseCode ?? '';

        $this->setSubmissionId($transaction->submission_id);

        if ($responseCode == '1') {
            $this->changeTransactionStatus($transaction->id, 'paid');
            $this->changeSubmissionPaymentStatus('paid');
            $this->recalculatePaidTotal();

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'success',
                'title'            => __('Authorize.Net Payment Captured', 'fluentformpro'),
                'description'      => 'Payment captured successfully via webhook. Transaction ID: ' . $transactionId
            ]);

            $this->completePaymentSubmission(false);
            $this->setMetaData('is_form_action_fired', 'yes');
        } elseif ($responseCode == '4') {
            if ($transaction->status === 'paid') {
                do_action('fluentform/log_data', [
                    'parent_source_id' => $transaction->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $transaction->submission_id,
                    'component'        => 'Payment',
                    'status'           => 'info',
                    'title'            => __('Authorize.Net Review Status Ignored', 'fluentformpro'),
                    'description'      => 'Ignored held-for-review webhook because the transaction is already marked as paid. Transaction ID: ' . $transactionId
                ]);

                return;
            }

            $this->changeTransactionStatus($transaction->id, 'pending');
            $this->changeSubmissionPaymentStatus('pending');

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('Authorize.Net Payment Held for Review', 'fluentformpro'),
                'description'      => 'Payment capture held for review via webhook. Transaction ID: ' . $transactionId
            ]);
        } else {
            $this->changeTransactionStatus($transaction->id, 'failed');
            $this->changeSubmissionPaymentStatus('failed');

            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Authorize.Net Payment Failed', 'fluentformpro'),
                'description'      => 'Payment failed via webhook. Transaction ID: ' . $transactionId . ' | Response Code: ' . $responseCode
            ]);
        }
    }

    public function capture_created($transaction, $payload)
    {
        $this->authcapture_created($transaction, $payload);
    }

    public function fraud_approved($transaction, $payload)
    {
        $this->setSubmissionId($transaction->submission_id);

        $accountLast4 = substr($payload->accountNumber ?? '', -4);
        $accountType = $payload->accountType ?? 'unknown';
        $updateData = [
            'card_last_4' => $accountType === 'eCheck' ? null : $accountLast4,
        ];

        $this->updateTransaction($transaction->id, $updateData);
        $this->changeTransactionStatus($transaction->id, 'paid');
        $this->changeSubmissionPaymentStatus('paid');
        $this->recalculatePaidTotal();

        do_action('fluentform/log_data', [
            'parent_source_id' => $transaction->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $transaction->submission_id,
            'component'        => 'Payment',
            'status'           => 'success',
            'title'            => __('Authorize.Net Fraud Approved', 'fluentformpro'),
            'description'      => 'Fraud check approved via webhook. Transaction ID: ' . $payload->id
        ]);

        $this->completePaymentSubmission(false);
        $this->setMetaData('is_form_action_fired', 'yes');
    }

    public function fraud_declined($transaction, $payload)
    {
        $this->setSubmissionId($transaction->submission_id);
        $this->changeTransactionStatus($transaction->id, 'failed');
        $this->changeSubmissionPaymentStatus('failed');

        do_action('fluentform/log_data', [
            'parent_source_id' => $transaction->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $transaction->submission_id,
            'component'        => 'Payment',
            'status'           => 'error',
            'title'            => __('Authorize.Net Fraud Declined', 'fluentformpro'),
            'description'      => 'Fraud check declined via webhook. Transaction ID: ' . $payload->id
        ]);
    }

    public function void_created($transaction, $payload)
    {
        $this->setSubmissionId($transaction->submission_id);
        $this->changeTransactionStatus($transaction->id, 'cancelled');
        $this->changeSubmissionPaymentStatus('cancelled');

        do_action('fluentform/log_data', [
            'parent_source_id' => $transaction->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $transaction->submission_id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Authorize.Net Payment Voided', 'fluentformpro'),
            'description'      => 'Payment voided via webhook. Transaction ID: ' . $payload->id
        ]);
    }

    public function refund_created($transaction, $payload)
    {
        $this->setSubmissionId($transaction->submission_id);
        $this->changeTransactionStatus($transaction->id, 'refunded');
        $this->changeSubmissionPaymentStatus('refunded');

        do_action('fluentform/log_data', [
            'parent_source_id' => $transaction->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $transaction->submission_id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Authorize.Net Payment Refunded', 'fluentformpro'),
            'description'      => 'Payment refunded via webhook. Transaction ID: ' . $payload->id
        ]);
    }

    protected function getPaymentMode()
    {
        return AuthorizeNetSettings::getMode($this->form->id);
    }

    public function addCheckoutJs($methodElement, $element, $form)
    {
        $isLive = AuthorizeNetSettings::isLive($form->id);
        $acceptJsUrl = $isLive ? 'https://js.authorize.net/v1/Accept.js' : 'https://jstest.authorize.net/v1/Accept.js';

        // Enqueue Lity for modal functionality
        wp_enqueue_script('lity', FLUENTFORMPRO_DIR_URL . 'public/libs/lity/lity.min.js', ['jquery'], '2.3.1', true);
        wp_enqueue_style('lity', FLUENTFORMPRO_DIR_URL . 'public/libs/lity/lity.min.css', [], '2.3.1', 'all');
        wp_enqueue_script('authorize-net-accept-js', $acceptJsUrl, [], null, true);
        wp_enqueue_script('ff_authorizenet_handler', FLUENTFORMPRO_DIR_URL . 'public/js/authorizenet_accept_handler.js', ['authorize-net-accept-js', 'jquery', 'lity'], FLUENTFORMPRO_VERSION, true);
    }

    public function maybeShowModal($transaction, $submission, $form, $hostedFormData)
    {
        $publicClientKey = ArrayHelper::get($hostedFormData, 'public_client_key');
        $apiLoginId = ArrayHelper::get($hostedFormData, 'api_login_id');
        $isLive = ArrayHelper::get($hostedFormData, 'is_live', false);
        $formData = ArrayHelper::get($hostedFormData, 'form_data', []);
        $currencySettings = PaymentHelper::getCurrencyConfig($form->id);
        $currencySymbol = html_entity_decode($currencySettings['currency_sign'], ENT_QUOTES, 'UTF-8');

        $paymentTotal = $transaction->payment_total / 100;
        $formattedAmount = number_format(
            $paymentTotal,
            ArrayHelper::get($currencySettings, 'decimal_points', 2),
            ArrayHelper::get($currencySettings, 'decimal_separator', '.'),
            ArrayHelper::get($currencySettings, 'thousand_separator', ',')
        );

        $modalData = [
            'public_client_key' => $publicClientKey,
            'api_login_id'      => $apiLoginId,
            'is_live'           => $isLive,
            'amount'            => API::formatAmount($transaction->payment_total),
            'currency_symbol'   => $currencySymbol,
            'formatted_amount'  => $formattedAmount,
            'form_data'         => $formData,
            'ajax_url'          => admin_url('admin-ajax.php'),
            'submission_id'     => $submission->id,
            'form_id'           => $form->id,
            'transaction_hash'  => $transaction->transaction_hash,
            '_ff_payment_nonce' => wp_create_nonce('ff_payment_' . $submission->id),
            'modal_styles'      => $this->getModalStyles(),
            'i18n'              => [
                'payment_details'      => __('Payment Details', 'fluentformpro'),
                'provide_card_details' => __('Please provide your credit card details to complete the payment',
                    'fluentformpro'),
                'processing'           => __('Processing Payment', 'fluentformpro'),
                'card_number'          => __('Card Number', 'fluentformpro'),
                'expiration'           => __('Expiration (MM/YY)', 'fluentformpro'),
                'cvc'                  => __('CVC', 'fluentformpro'),
                'submit'               => __('Pay', 'fluentformpro') . ' ' . $currencySymbol . $formattedAmount,
                'payment_failed'       => __('Payment Failed', 'fluentformpro'),
                'try_again'            => __('Please try again or contact site administrator', 'fluentformpro')
            ]
        ];

        wp_send_json_success([
            'nextAction'       => 'authorizenet',
            'actionName'       => 'initAuthorizeNetModal',
            'submission_id'    => $submission->id,
            'modal_data'       => $modalData,
            'transaction_hash' => $transaction->transaction_hash,
            'message'          => __('Payment Modal is opening, Please complete the payment', 'fluentformpro'),
            'confirming_text'  => __('Confirming Payment, Please wait...', 'fluentformpro'),
            'result'           => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }
    
    /**
     * Get modal styles with filter for customization
     *
     * @return string
     */
    private function getModalStyles()
    {
        $defaultStyles = '
            .ff-authorizenet-payment-form {
                max-width: 400px;
                margin: 0 auto;
                padding: 20px;
                background-color: #ffffff;
                border-radius: 4px;
            }

            .ff-authorizenet-form-header {
                text-align: center;
                margin-bottom: 24px;
            }

            .ff-authorizenet-form-header h3 {
                margin: 0 0 8px 0;
                font-size: 24px;
                font-weight: 600;
                color: #1f2937;
            }

            .ff-authorizenet-form-header p {
                margin: 0 0 4px 0;
                color: #6b7280;
                font-size: 16px;
            }

            .ff-authorizenet-amount {
                font-size: 18px;
                font-weight: 600;
                color: #059669;
            }

            .ff-authorizenet-form-group {
                margin-bottom: 20px;
                position: relative;
            }

            .ff-authorizenet-form-group label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                color: #374151;
                font-size: 16px;
            }

            .ff-authorizenet-form-group input {
                width: 100%;
                padding: 12px 16px;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                font-size: 16px;
                transition: all 0.2s ease;
            }

            .ff-authorizenet-form-group input:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            }

            .ff-authorizenet-form-row {
                display: flex;
                gap: 16px;
            }

            .ff-authorizenet-form-row .ff-authorizenet-form-group {
                flex: 1;
            }

            .ff-authorizenet-payment-errors {
                background: #fef2f2;
                border: 1px solid #fecaca;
                color: #dc2626;
                padding: 12px 16px;
                border-radius: 8px;
                margin-bottom: 20px;
                font-size: 14px;
            }

            .ff-authorizenet-btn {
                width: 100%;
                padding: 14px 24px;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                border: none;
                position: relative;
                background: #3b82f6;
                color: white;
                margin-top: 8px;
            }

            .ff-authorizenet-btn:hover:not(:disabled) {
                background: #2563eb;
            }

            .ff-authorizenet-btn:disabled {
                background: #9ca3af;
                cursor: not-allowed;
            }

            .ff-authorizenet-btn-spinner {
                position: absolute;
                left: 50%;
                top: 50%;
                transform: translate(-50%, -50%);
                padding: 14px 24px;
            }

            @media (max-width: 640px) {
                .ff-authorizenet-payment-form {
                    padding: 16px;
                }

                .ff-authorizenet-form-row {
                    flex-direction: column;
                    gap: 0;
                }
            }
        ';

        return apply_filters('fluentform/authorizenet_modal_styles', $defaultStyles);
    }

    public function processAuthorizeNetPayment()
    {
        try {
            $submissionId = intval(ArrayHelper::get($_POST, 'submission_id'));
            $formId = intval(ArrayHelper::get($_POST, 'form_id'));
            $transactionHash = sanitize_text_field(ArrayHelper::get($_POST, 'transaction_hash'));
            $paymentFailed = ArrayHelper::get($_POST, 'payment_failed', false);
            $errorMessage = sanitize_text_field(ArrayHelper::get($_POST, 'error_message', ''));

            // Handle failed payment (from Accept.js errors)
            if ($paymentFailed) {
                wp_send_json_error([
                    'message' => 'Payment failed: ' . $errorMessage
                ]);
                return;
            }

            $opaqueDataDescriptor = sanitize_text_field($_POST['opaque_data_descriptor']);
            $opaqueDataValue = sanitize_text_field($_POST['opaque_data_value']);

            $this->setSubmissionId($submissionId);

            // Verify nonce
            $nonceResult = $this->verifyPaymentNonce($submissionId, 'authorizenet');
            if (is_wp_error($nonceResult)) {
                wp_send_json_error([
                    'message' => $nonceResult->get_error_message()
                ]);
            }

            $submission = $this->getSubmission();
            $form = $this->getForm();

            if (!$submission || !$form || $form->id != $formId) {
                wp_send_json_error([
                    'message' => __('Invalid submission data', 'fluentformpro')
                ]);
            }

            $transaction = wpFluent()->table('fluentform_transactions')
                ->where('submission_id', $submissionId)
                ->where('transaction_hash', $transactionHash)
                ->where('payment_method', 'authorizenet')
                ->first();

            // Shared validation: status, ownership, double-pay prevention
            $validation = $this->validatePaymentConfirmation($transaction, $submissionId);
            if (is_wp_error($validation)) {
                wp_send_json_error([
                    'message' => $validation->get_error_message()
                ]);
            }

            // Format amount for Authorize.Net (convert from cents to dollars)
            $amount = API::formatAmount($transaction->payment_total);

            // Get customer information
            $customerName = PaymentHelper::getCustomerName($submission, $form);
            $customerEmail = PaymentHelper::getCustomerEmail($submission, $form);
            $customerAddress = PaymentHelper::getCustomerAddress($submission);
            $customerPhone = PaymentHelper::getCustomerPhoneNumber($submission, $form);

            // Parse customer name
            $nameParts = explode(' ', trim($customerName), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            // Prepare billing address
            $billTo = [
                'firstName' => $firstName,
                'lastName'  => $lastName
            ];

            if ($customerAddress) {
                $addressLine1 = ArrayHelper::get($customerAddress, 'address_line_1', '');
                $addressLine2 = ArrayHelper::get($customerAddress, 'address_line_2', '');
                $billTo['address'] = $addressLine2 ? $addressLine1 . ', ' . $addressLine2 : $addressLine1;
                $billTo['city'] = ArrayHelper::get($customerAddress, 'city', '');
                $billTo['state'] = ArrayHelper::get($customerAddress, 'state', '');
                $billTo['zip'] = ArrayHelper::get($customerAddress, 'zip', '');
                $billTo['country'] = ArrayHelper::get($customerAddress, 'country', 'US');
            }

            if ($customerPhone) {
                $billTo['phoneNumber'] = $customerPhone;
            }

            // Determine transaction type based on settings
            $transactionType = 'authCaptureTransaction'; // Default
            $settingType = AuthorizeNetSettings::getTransactionType($form->id);
            if ($settingType == 'auth_only') {
                $transactionType = 'authOnlyTransaction';
            }

            // Prepare transaction data for Accept.js
            $transactionData = [
                'refId'           => 'FF-' . $transaction->id,
                'transactionType' => $transactionType,
                'amount'          => $amount,
                'payment'         => [
                    'opaqueData' => [
                        'dataDescriptor' => $opaqueDataDescriptor,
                        'dataValue'      => $opaqueDataValue
                    ]
                ],
                'order'           => [
                    'invoiceNumber' => 'FF-' . $submission->id,
                    'description'   => $form->title . ' - Submission #' . $submission->id
                ],
                'customer'        => [
                    'id'    => (string) ($submission->user_id ?: $submission->id),
                    'email' => $customerEmail
                ],
                'billTo'          => $billTo
            ];

            // Process payment through Authorize.Net API
            $api = new API($form->id);
            $response = $api->createTransaction($transactionData);

            if (is_wp_error($response)) {
                // Update transaction status to failed
                $this->updateTransaction($transaction->id, [
                    'status' => 'failed',
                    'payment_note' => 'API Error: ' . $response->get_error_message()
                ]);

                // Update submission payment status to failed
                $this->changeSubmissionPaymentStatus('failed');

                do_action('fluentform/log_data', [
                    'parent_source_id' => $form->id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'error',
                    'title'            => __('Authorize.Net Payment Failed', 'fluentformpro'),
                    'description'      => $response->get_error_message()
                ]);

                wp_send_json_error([
                    'message' => $response->get_error_message()
                ]);
            }

            // Check transaction response
            $transactionResponse = ArrayHelper::get($response, 'transactionResponse', []);
            $responseCode = ArrayHelper::get($transactionResponse, 'responseCode');

            if ($responseCode == '1') {
                // Update transaction with payment details
                $chargeId = ArrayHelper::get($transactionResponse, 'transId');
                $authCode = ArrayHelper::get($transactionResponse, 'authCode');

                // Determine status based on transaction type
                $status = 'paid';
                if ($settingType == 'auth_only') {
                    $status = 'pending';
                }

                $updateData = [
                    'charge_id'      => $chargeId,
                    'status'         => $status,
                    'payment_note'   => 'Auth Code: ' . $authCode . ($status == 'pending' ? ' (Authorized - Pending Capture)' : ''),
                    'payment_method' => 'authorizenet',
                    'payment_mode'   => AuthorizeNetSettings::getMode($form->id)
                ];

                $this->updateTransaction($transaction->id, $updateData);
                
                // Only mark as paid and complete submission for auth_capture transactions
                if ($status == 'paid') {
                    $this->changeSubmissionPaymentStatus('paid');
                    $this->recalculatePaidTotal();
                    $this->completePaymentSubmission();
                } else {
                    // For auth_only, just complete the submission but keep payment status as pending
                    $this->completePaymentSubmission();
                }
            } elseif ($responseCode == '4') {
                $chargeId = ArrayHelper::get($transactionResponse, 'transId', '');
                $reviewMessage = ArrayHelper::get($response, 'messages.message.0.text');
                if (!$reviewMessage) {
                    $reviewMessage = ArrayHelper::get($transactionResponse, 'messages.0.description');
                }
                if (!$reviewMessage) {
                    $reviewMessage = __('Transaction held for review by Authorize.Net.', 'fluentformpro');
                }

                $updateData = [
                    'status'         => 'pending',
                    'payment_note'   => $reviewMessage,
                    'payment_method' => 'authorizenet',
                    'payment_mode'   => AuthorizeNetSettings::getMode($form->id)
                ];

                if ($chargeId) {
                    $updateData['charge_id'] = $chargeId;
                }

                $this->updateTransaction($transaction->id, $updateData);
                $this->changeSubmissionPaymentStatus('pending');

                do_action('fluentform/log_data', [
                    'parent_source_id' => $form->id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'info',
                    'title'            => __('Authorize.Net Payment Held for Review', 'fluentformpro'),
                    'description'      => $reviewMessage . ($chargeId ? ' Transaction ID: ' . $chargeId : '')
                ]);

                wp_send_json_success([
                    'insert_id' => $submission->id,
                    'result'    => [
                        'message' => fluentform_sanitize_html($reviewMessage),
                        'action'  => 'hide_form'
                    ],
                    'error'     => '',
                    'type'      => 'info',
                    'is_new'    => false
                ], 200);
            } else {
                $errorCode = ArrayHelper::get($transactionResponse, 'responseCode');
                $errorText = ArrayHelper::get($transactionResponse, 'errors.0.errorText',
                    __('Unknown error', 'fluentformpro'));

                // Update transaction status to failed
                $this->updateTransaction($transaction->id, [
                    'status' => 'failed',
                    'payment_note' => 'Payment failed: ' . $errorText
                ]);

                // Update submission payment status to failed
                $this->changeSubmissionPaymentStatus('failed');

                do_action('fluentform/log_data', [
                    'parent_source_id' => $form->id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'error',
                    'title'            => __('Authorize.Net Payment Failed', 'fluentformpro'),
                    'description'      => __('Error Code: ', 'fluentformpro') . $errorCode . ' - ' . $errorText
                ]);

                wp_send_json_error([
                    'message' => $errorText
                ]);
            }

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => __('Payment processing failed. Please try again.', 'fluentformpro')
            ]);
        }
    }
}
