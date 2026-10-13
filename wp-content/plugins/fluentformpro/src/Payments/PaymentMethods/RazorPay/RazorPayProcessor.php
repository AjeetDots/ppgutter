<?php

namespace FluentFormPro\Payments\PaymentMethods\RazorPay;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;

class RazorPayProcessor extends BaseProcessor
{
    public $method = 'razorpay';

    protected $form;

    public function init()
    {
        add_action('fluentform/process_payment_' . $this->method, array($this, 'handlePaymentAction'), 10, 6);
        add_action('fluentform/payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));

        add_action('fluentform/ipn_razorpay_action_paid', array($this, 'handlePaid'), 10, 2);
        add_action('fluentform/ipn_razorpay_action_refunded', array($this, 'handleRefund'), 10, 3);

        add_filter('fluentform/validate_payment_items_' . $this->method, [$this, 'validateSubmittedItems'], 10, 4);

        add_action('fluentform/rendering_payment_method_' . $this->method, array($this, 'addCheckoutJs'), 10, 3);

        add_action('wp_ajax_fluentform_razorpay_confirm_payment', array($this, 'confirmModalPayment'));
        add_action('wp_ajax_nopriv_fluentform_razorpay_confirm_payment', array($this, 'confirmModalPayment'));

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
                'description'      => __('Razorpay does not support subscriptions right now!', 'fluentformpro')
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
        $this->maybeShowModal($transaction, $submission, $form, $methodSettings);
        $this->handleRedirect($transaction, $submission, $form, $methodSettings);
    }

    public function handleRedirect($transaction, $submission, $form, $methodSettings)
    {
        $globalSettings = RazorPaySettings::getSettings();
        $successUrl = add_query_arg(array(
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'success'
        ), site_url('/'));

        $paymentArgs = array(
            'amount'          => intval($transaction->payment_total),
            'currency'        => strtoupper($transaction->currency),
            'description'     => $form->title,
            'reference_id'    => $transaction->transaction_hash,
            'customer'        => [
                'email'   => PaymentHelper::getCustomerEmail($submission, $form),
                'name'    => PaymentHelper::getCustomerName($submission, $form),
                'contact' => PaymentHelper::getCustomerPhoneNumber($submission, $form),
            ],
            "options"         => [
                "checkout" => [
                    'name'    => PaymentHelper::getCustomerName($submission, $form),
                ]
            ],
            'callback_url'    => $successUrl,
            'notes'           => [
                'form_id'       => $form->id,
                'submission_id' => $submission->id
            ],
            'callback_method' => 'get',
            'notify'          => [
                'email' => in_array('email', $globalSettings['notifications']),
                'sms'   => in_array('sms', $globalSettings['notifications']),
            ]
        );
        $paymentArgs = apply_filters_deprecated(
            'fluentform_razorpay_payment_args',
            [
                $paymentArgs,
                $submission,
                $transaction,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/razorpay_payment_args',
            'Use fluentform/razorpay_payment_args instead of fluentform_razorpay_payment_args.'
        );
        $paymentArgs = apply_filters('fluentform/razorpay_payment_args', $paymentArgs, $submission, $transaction, $form);
        $paymentIntent = (new API())->makeApiCall('payment_links', $paymentArgs, $form->id, 'POST');

        if (is_wp_error($paymentIntent)) {
            $logData = [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('RazorPay Payment Redirect Error', 'fluentformpro'),
                'description'      => $paymentIntent->get_error_message()
            ];

            do_action('fluentform/log_data', $logData);

            wp_send_json_success([
                'message' => $paymentIntent->get_error_message()
            ], 423);
        }

        Helper::setSubmissionMeta($submission->id, '_razorpay_payment_id', $paymentIntent['id']);

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Redirect to RazorPay', 'fluentformpro'),
            'description'      => __('User redirect to RazorPay for completing the payment', 'fluentformpro')
        ];
        do_action('fluentform/log_data', $logData);

        wp_send_json_success([
            'nextAction'   => 'payment',
            'actionName'   => 'normalRedirect',
            'redirect_url' => $paymentIntent['short_url'],
            'message'      => __('You are redirecting to razorpay.com to complete the purchase. Please wait while you are redirecting....', 'fluentformpro'),
            'result'       => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }

    protected function getPaymentMode($formId = false)
    {
        $isLive = RazorPaySettings::isLive($formId);
        if ($isLive) {
            return 'live';
        }
        return 'test';
    }

    public function handleSessionRedirectBack($data)
    {
        $submissionId = intval($data['fluentform_payment']);
        $this->setSubmissionId($submissionId);
        $submission = $this->getSubmission();

        $transactionHash = sanitize_text_field($data['transaction_hash']);
        $transaction = $this->getTransaction($transactionHash, 'transaction_hash');

        if (!$transaction || !$submission || $transaction->payment_method != $this->method) {
            return;
        }

        $payId = $this->sanitizeRazorpayId(ArrayHelper::get($data, 'razorpay_payment_id'));
        $payment = (new API())->makeApiCall('payments/' . $payId, [], $submission->form_id);
        $isSuccess = false;

        if (is_wp_error($payment)) {
            $returnData = [
                'insert_id' => $submission->id,
                'title'     => __('Failed to retrieve payment data', 'fluentformpro'),
                'result'    => false,
                'error'     => $payment->get_error_message()
            ];
        } else {
            $isSuccess = $payment['status'] == 'captured';

            // SECURITY (PRO-08): unlike the modal confirm path, this return handler did not bind
            // razorpay_payment_id to this order, so a captured payment id from another (same-price)
            // order could be replayed on the return URL to mark this one paid.
            $binding = $isSuccess
                ? $this->verifyRedirectPaymentBinding($data, $payment, $payId, $transaction, $submission)
                : true;

            if (is_wp_error($binding)) {
                $isSuccess = false;

                do_action('fluentform/log_data', [
                    'parent_source_id' => $submission->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'error',
                    'title'            => __('RazorPay Payment Binding Failed', 'fluentformpro'),
                    'description'      => $binding->get_error_message()
                ]);

                $returnData = [
                    'insert_id' => $submission->id,
                    'title'     => __('Payment verification failed', 'fluentformpro'),
                    'result'    => false,
                    'error'     => __('This payment does not belong to your order.', 'fluentformpro')
                ];
            } elseif ($isSuccess) {
                $returnData = $this->handlePaid($submission, $transaction, $payment);
            } else {
                $returnData = [
                    'insert_id' => $submission->id,
                    'title'     => __('Failed to retrieve payment data', 'fluentformpro'),
                    'result'    => false,
                    'error'     => __('Looks like you have cancelled the payment. Please try again!', 'fluentformpro')
                ];
            }
        }

        $returnData['type'] = ($isSuccess) ? 'success' : 'failed';

        if (!isset($returnData['is_new'])) {
            $returnData['is_new'] = false;
        }

        $this->showPaymentView($returnData);
    }

    public function handlePaid($submission, $transaction, $vendorTransaction)
    {
        $this->setSubmissionId($submission->id);

        // Check if actions are fired
        if ($this->getMetaData('is_form_action_fired') == 'yes') {
            return $this->completePaymentSubmission(false);
        }

        $status = 'paid';

        $expectedAmount = (int) $transaction->payment_total;
        $expectedCurrency = strtoupper((string) $transaction->currency);
        $vendorAmount = (int) ArrayHelper::get($vendorTransaction, 'amount');
        $vendorCurrency = strtoupper((string) ArrayHelper::get($vendorTransaction, 'currency', ''));
        $baseAmount = (int) ArrayHelper::get($vendorTransaction, 'base_amount', 0);
        $baseCurrency = strtoupper((string) ArrayHelper::get($vendorTransaction, 'base_currency', ''));
        $verifiedAmount = $vendorAmount;
        $verifiedCurrency = $vendorCurrency;
        $vendorOrderId = $this->sanitizeRazorpayId(ArrayHelper::get($vendorTransaction, 'order_id', ''));

        if ($baseAmount && (!$baseCurrency || $baseCurrency === $expectedCurrency)) {
            $verifiedAmount = $baseAmount;
            $verifiedCurrency = $baseCurrency ?: $verifiedCurrency;
        }

        if ($vendorOrderId) {
            $vendorOrder = (new API())->makeApiCall('orders/' . $vendorOrderId, [], $submission->form_id);

            if (!is_wp_error($vendorOrder)) {
                $vendorOrderAmount = (int) ArrayHelper::get($vendorOrder, 'amount');
                $vendorOrderCurrency = strtoupper((string) ArrayHelper::get($vendorOrder, 'currency', ''));

                if ($vendorOrderAmount) {
                    $verifiedAmount = $vendorOrderAmount;
                }

                if ($vendorOrderCurrency) {
                    $verifiedCurrency = $vendorOrderCurrency;
                }
            }
        }

        // Razorpay customer fee bearer can increase the final charged payment
        // amount during checkout. The order amount remains the merchant-created
        // amount, so prefer order.amount first, then base_amount, then amount.
        if ($expectedAmount && ($verifiedAmount !== $expectedAmount || $verifiedCurrency !== $expectedCurrency)) {
            $strict = apply_filters('fluentform/razorpay_amount_mismatch_strict', true);

            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('RazorPay Amount Mismatch', 'fluentformpro'),
                'description'      => sprintf(
                    // translators: %1$d is the expected amount, %2$s is the expected currency, %3$d is the verified RazorPay order/base amount, %4$s is the verified currency, %5$d is the customer charged amount, %6$s is the charged currency, %7$s is the payment status
                    __('Expected %1$d %2$s but RazorPay verified %3$d %4$s (charged %5$d %6$s). Payment %7$s.', 'fluentformpro'),
                    $expectedAmount,
                    $expectedCurrency,
                    $verifiedAmount,
                    $verifiedCurrency,
                    $vendorAmount,
                    $vendorCurrency,
                    $strict ? 'rejected' : 'marked for review'
                )
            ]);

            if ($strict) {
                return new \WP_Error('amount_mismatch', __('Payment amount verification failed.', 'fluentformpro'));
            }

            $status = 'requires_review';
        }

        // Let's make the payment as paid
        $updateData = [
            'payment_note'  => maybe_serialize($vendorTransaction),
            'charge_id'     => sanitize_text_field($vendorTransaction['id']),
            'payer_email'   => $vendorTransaction['email'],
            'payment_total' => $verifiedAmount ?: $vendorAmount,
            'currency'      => $verifiedCurrency ?: $vendorCurrency
        ];

        $this->updateTransaction($transaction->id, $updateData);
        $this->changeSubmissionPaymentStatus($status);
        $this->changeTransactionStatus($transaction->id, $status);
        $this->recalculatePaidTotal();
        $returnData = $this->getReturnData();
        $this->setMetaData('is_form_action_fired', 'yes');
        return $returnData;
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
            $errors[] = __('RazorPay Error: RazorPay does not support subscriptions right now!', 'fluentformpro');
        }
        return $errors;
    }

    public function addCheckoutJs($methodElement, $element, $form)
    {
        $settings = RazorPaySettings::getSettings();
        if ($settings['checkout_type'] != 'modal') {
            return;
        }

        wp_enqueue_script('razorpay', 'https://checkout.razorpay.com/v1/checkout.js', [], FLUENTFORMPRO_VERSION);
        wp_enqueue_script('ff_razorpay_handler', FLUENTFORMPRO_DIR_URL . 'public/js/razorpay_handler.js', ['jquery'], FLUENTFORMPRO_VERSION);
    }

    public function maybeShowModal($transaction, $submission, $form, $methodSettings)
    {
        $settings = RazorPaySettings::getSettings();
        if($settings['checkout_type'] != 'modal') {
            return;
        }

        // Create an order First
        $orderArgs = [
            'amount'   => intval($transaction->payment_total),
            'currency' => strtoupper($transaction->currency),
            'receipt'  => $transaction->transaction_hash,
            'notes'    => [
                'form_id'       => $form->id,
                'submission_id' => $submission->id
            ]
        ];

        $order = (new API())->makeApiCall('orders', $orderArgs, $form->id, 'POST');

        if (is_wp_error($order)) {
            $message = $order->get_error_message();
            $logData = [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('RazorPay Payment Error', 'fluentformpro'),
                'description'      => $order->get_error_message()
            ];

            do_action('fluentform/log_data', $logData);

            wp_send_json([
                'errors'      => __('RazorPay Error: ', 'fluentformpro') . $message,
                'append_data' => [
                    '__entry_intermediate_hash' => Helper::getSubmissionMeta($submission->id, '__entry_intermediate_hash')
                ]
            ], 423);
        }

        $this->updateTransaction($transaction->id, [
            'charge_id' => $order['id']
        ]);

        $keys = RazorPaySettings::getApiKeys($form->id);
        $paymentSettings = PaymentHelper::getPaymentSettings();

        $modalData = [
            'amount'       => intval($transaction->payment_total),
            'currency'     => strtoupper($transaction->currency),
            'description'  => $form->title,
            'reference_id' => $transaction->transaction_hash,
            'order_id'     => $order['id'],
            'name'         => $paymentSettings['business_name'],
            'key'          => $keys['api_key'],
            'prefill'      => [
                'email'   => PaymentHelper::getCustomerEmail($submission, $form),
                'name'    => PaymentHelper::getCustomerName($submission, $form),
                'contact' => PaymentHelper::getCustomerPhoneNumber($submission, $form),
            ],
        ];

        if ($theme_color = ArrayHelper::get($settings, 'theme_color')) {
            $modalData['theme'] = [
                "color" => $theme_color,
            ];
        }

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Razorpay Modal is initiated', 'fluentformpro'),
            'description'      => __('RazorPay Modal is initiated to complete the payment', 'fluentformpro')
        ];

        do_action('fluentform/log_data', $logData);

        $nonce = wp_create_nonce('ff_payment_' . $submission->id);

        # Tell the client to handle the action
        wp_send_json_success([
            'nextAction'         => 'razorpay',
            'actionName'         => 'initRazorPayModal',
            'submission_id'      => $submission->id,
            'modal_data'         => $modalData,
            'transaction_hash'   => $transaction->transaction_hash,
            '_ff_payment_nonce'  => $nonce,
            'message'            => __('Payment Modal is opening, Please complete the payment', 'fluentformpro'),
            'confirming_text'    => __('Confirming Payment, Please wait...', 'fluentformpro'),
            'result'             => [
                'insert_id' => $submission->id
            ],
            'append_data' => [
                '__entry_intermediate_hash' => Helper::getSubmissionMeta($transaction->submission_id, '__entry_intermediate_hash')
            ]
        ], 200);
    }

    protected function verifyCheckoutSignature($orderId, $paymentId, $signature, $formId)
    {
        if (!$orderId || !$paymentId || !$signature) {
            return false;
        }

        $keys = RazorPaySettings::getApiKeys($formId);
        $secret = (string) ArrayHelper::get($keys, 'api_secret', '');

        if (!$secret) {
            return false;
        }

        $expectedSignature = \hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);

        return \hash_equals($expectedSignature, $signature);
    }

    protected function sanitizeRazorpayId($value)
    {
        $value = sanitize_text_field((string) $value);

        return \preg_replace('/[^A-Za-z0-9_]/', '', $value);
    }

    /**
     * SECURITY (PRO-08): bind the returned razorpay_payment_id to the RazorPay resource this
     * submission actually created, before the return URL is allowed to mark it paid.
     *
     * Two checkout types land on this handler and they persist different ids:
     *  - `orders` flow  -> the order id is stored on the transaction as `charge_id`
     *  - `payment_links` flow (handleRedirect) -> the plink id is stored in the
     *    `_razorpay_payment_id` submission meta and no `charge_id` is ever written
     *
     * Keying only off `charge_id` would reject every legitimate payment-link payment, so each
     * flow is verified against the id it really stores.
     *
     * @return true|\WP_Error
     */
    protected function verifyRedirectPaymentBinding($data, $payment, $payId, $transaction, $submission)
    {
        $expectedOrderId = $this->sanitizeRazorpayId($transaction->charge_id);
        $vendorOrderId = $this->sanitizeRazorpayId(ArrayHelper::get($payment, 'order_id', ''));

        if ($expectedOrderId) {
            if ($vendorOrderId && \hash_equals($expectedOrderId, $vendorOrderId)) {
                return true;
            }

            return new \WP_Error('razorpay_order_mismatch', sprintf(
                // translators: %1$s is the expected RazorPay order id, %2$s is the received one.
                __('Expected order %1$s but the payment belongs to %2$s.', 'fluentformpro'),
                $expectedOrderId,
                $vendorOrderId ?: '-'
            ));
        }

        $expectedLinkId = $this->sanitizeRazorpayId(
            Helper::getSubmissionMeta($submission->id, '_razorpay_payment_id')
        );

        if (!$expectedLinkId) {
            return new \WP_Error('razorpay_unbound_payment', __(
                'No RazorPay order or payment link is recorded for this submission.',
                'fluentformpro'
            ));
        }

        // RazorPay signs the payment-link return URL with the API secret, so a valid signature
        // proves this payment id was made against this submission's own payment link.
        if ($this->verifyPaymentLinkSignature($data, $expectedLinkId, $payId, $submission->form_id)) {
            return true;
        }

        // No usable signature on the return URL — fall back to the authoritative API lookup,
        // which trusts nothing in the query string.
        return $this->verifyPaymentBelongsToLink($expectedLinkId, $payId, $submission->form_id);
    }

    protected function verifyPaymentLinkSignature($data, $expectedLinkId, $paymentId, $formId)
    {
        $linkId = $this->sanitizeRazorpayId(ArrayHelper::get($data, 'razorpay_payment_link_id', ''));
        $signature = sanitize_text_field((string) ArrayHelper::get($data, 'razorpay_signature', ''));

        if (!$linkId || !$signature || !$paymentId || !\hash_equals($expectedLinkId, $linkId)) {
            return false;
        }

        $keys = RazorPaySettings::getApiKeys($formId);
        $secret = (string) ArrayHelper::get($keys, 'api_secret', '');

        if (!$secret) {
            return false;
        }

        $referenceId = sanitize_text_field((string) ArrayHelper::get($data, 'razorpay_payment_link_reference_id', ''));
        $linkStatus = sanitize_text_field((string) ArrayHelper::get($data, 'razorpay_payment_link_status', ''));

        $payload = $linkId . '|' . $referenceId . '|' . $linkStatus . '|' . $paymentId;

        return \hash_equals(\hash_hmac('sha256', $payload, $secret), $signature);
    }

    /**
     * @return true|\WP_Error
     */
    protected function verifyPaymentBelongsToLink($linkId, $paymentId, $formId)
    {
        $paymentLink = (new API())->makeApiCall('payment_links/' . $linkId, [], $formId);

        if (is_wp_error($paymentLink)) {
            return new \WP_Error('razorpay_link_lookup_failed', sprintf(
                // translators: %s is the RazorPay API error message.
                __('Could not verify the payment link: %s', 'fluentformpro'),
                $paymentLink->get_error_message()
            ));
        }

        foreach ((array) ArrayHelper::get($paymentLink, 'payments', []) as $linkPayment) {
            $linkPaymentId = $this->sanitizeRazorpayId(ArrayHelper::get($linkPayment, 'payment_id', ''));

            if ($linkPaymentId && \hash_equals($linkPaymentId, $paymentId)) {
                return true;
            }
        }

        return new \WP_Error('razorpay_payment_not_on_link', sprintf(
            // translators: %1$s is the payment id, %2$s is the payment link id.
            __('Payment %1$s was not made against payment link %2$s.', 'fluentformpro'),
            $paymentId,
            $linkId
        ));
    }

    public function confirmModalPayment()
    {
        $data = $_REQUEST;
        $transactionHash = sanitize_text_field(ArrayHelper::get($data, 'transaction_hash'));
        $transaction = $this->getTransaction($transactionHash, 'transaction_hash');

        if (!$transaction) {
            wp_send_json([
                'errors' => __('Payment Error: Invalid Request', 'fluentformpro'),
            ], 423);
        }

        $this->setSubmissionId($transaction->submission_id);

        // Verify nonce
        $nonceResult = $this->verifyPaymentNonce($transaction->submission_id, 'razorpay');
        if (is_wp_error($nonceResult)) {
            wp_send_json([
                'errors' => $nonceResult->get_error_message(),
            ], 423);
        }

        // Shared validation: status, ownership, double-pay prevention
        $validation = $this->validatePaymentConfirmation($transaction, $transaction->submission_id);
        if (is_wp_error($validation)) {
            wp_send_json([
                'errors' => $validation->get_error_message(),
            ], 423);
        }

        $paymentId = $this->sanitizeRazorpayId(ArrayHelper::get($data, 'razorpay_payment_id'));
        $requestedOrderId = $this->sanitizeRazorpayId(ArrayHelper::get($data, 'razorpay_order_id'));
        $expectedOrderId = $this->sanitizeRazorpayId($transaction->charge_id);
        $signature = sanitize_text_field(ArrayHelper::get($data, 'razorpay_signature', ''));

        if (!$expectedOrderId || !$requestedOrderId || $expectedOrderId !== $requestedOrderId) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('RazorPay Order Mismatch', 'fluentformpro'),
                'description'      => sprintf(
                    // translators: %1$s is the expected order ID, %2$s is the requested order ID.
                    __('Expected order %1$s but received %2$s.', 'fluentformpro'),
                    $expectedOrderId,
                    $requestedOrderId
                )
            ]);

            wp_send_json([
                'errors' => __('Payment verification failed. Please try again.', 'fluentformpro'),
            ], 423);
        }

        if (!$this->verifyCheckoutSignature($expectedOrderId, $paymentId, $signature, $transaction->form_id)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('RazorPay Signature Mismatch', 'fluentformpro'),
                'description'      => __('RazorPay checkout signature verification failed.', 'fluentformpro')
            ]);

            wp_send_json([
                'errors' => __('Payment verification failed. Please try again.', 'fluentformpro'),
            ], 423);
        }

        $vendorPayment = (new API())->makeApiCall('payments/' . $paymentId, [], $transaction->form_id);


        if (is_wp_error($vendorPayment)) {
            $logData = [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'failed',
                'title'            => __('RazorPay Payment is failed to verify', 'fluentformpro'),
                'description'      => $vendorPayment->get_error_message()
            ];

            do_action('fluentform/log_data', $logData);


            wp_send_json([
                'errors'      => __('Payment Error: ', 'fluentformpro') . $vendorPayment->get_error_message(),
                'append_data' => [
                    '__entry_intermediate_hash' => Helper::getSubmissionMeta($transaction->submission_id, '__entry_intermediate_hash')
                ]
            ], 423);
        }

        $vendorOrderId = $this->sanitizeRazorpayId(ArrayHelper::get($vendorPayment, 'order_id', ''));
        if (!$vendorOrderId || $expectedOrderId !== $vendorOrderId) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('RazorPay Payment Order Mismatch', 'fluentformpro'),
                'description'      => sprintf(
                    // translators: %1$s is the expected order ID, %2$s is the vendor payment order ID.
                    __('Expected order %1$s but payment belongs to %2$s.', 'fluentformpro'),
                    $expectedOrderId,
                    $vendorOrderId
                )
            ]);

            wp_send_json([
                'errors' => __('Payment verification failed. Please try again.', 'fluentformpro'),
            ], 423);
        }

        if ($vendorPayment['status'] == 'paid' || $vendorPayment['status'] == 'captured') {
            $logData = [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'success',
                'title'            => __('Payment Success', 'fluentformpro'),
                'description'      => __('Razorpay payment has been marked as paid', 'fluentformpro')
            ];

            do_action('fluentform/log_data', $logData);

            $this->setSubmissionId($transaction->submission_id);
            $submission = $this->getSubmission();
            $returnData = $this->handlePaid($submission, $transaction, $vendorPayment);

            if (is_wp_error($returnData)) {
                wp_send_json([
                    'errors' => $returnData->get_error_message(),
                ], 423);
            }

            $returnData['payment'] = $vendorPayment;
            wp_send_json_success($returnData, 200);

        }

        wp_send_json([
            'errors'      => __('Payment could not be verified. Please contact site admin', 'fluentformpro'),
            'append_data' => [
                '__entry_intermediate_hash' => Helper::getSubmissionMeta($transaction->submission_id, '__entry_intermediate_hash')
            ]
        ], 423);

    }
}
