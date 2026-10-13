<?php

namespace FluentFormPro\Payments\PaymentMethods\Square;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;


class SquareProcessor extends BaseProcessor
{
    
    public $method = 'square';
    
    protected $form;
    
    public function init()
    {
        add_action('fluentform/process_payment_square_hosted', array($this, 'handlePaymentAction'), 10, 6);
        add_action('fluentform/payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));
        add_filter('fluentform/validate_payment_items_' . $this->method, [$this, 'validateSubmittedItems'], 10, 4);
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
                'description'      => __('Square does not support subscriptions right now!', 'fluentformpro')
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
    
    protected function getPaymentMode()
    {
        $isLive = SquareSettings::isLive();
        if ($isLive) {
            return 'live';
        }
        return 'test';
    }
    
    protected function handleRedirect($transaction, $submission, $form, $methodSettings)
    {
        $keys = SquareSettings::getApiKeys();
        
        $ipnDomain = site_url('index.php');
        if(defined('FLUENTFORM_PAY_IPN_DOMAIN') && FLUENTFORM_PAY_IPN_DOMAIN) {
            $ipnDomain = FLUENTFORM_PAY_IPN_DOMAIN;
        }
        
        $listenerUrl = add_query_arg(array(
            'fluentform_payment'            => $submission->id,
            'payment_method'                => $this->method,
            'transaction_hash'              => $transaction->transaction_hash,
        ), $ipnDomain);
    
        $amount = PaymentHelper::isZeroDecimal($transaction->currency)
            ? intval($transaction->payment_total / 100)
            : intval($transaction->payment_total);

        $paymentArgs = [
            "idempotency_key"    => $transaction->transaction_hash,
            "quick_pay"          => [
                "name"        => $this->getProductNames() ?: $form->title,
                "price_money" => [
                    "amount"   => $amount,
                    "currency" => strtoupper($transaction->currency)
                ],
                "location_id" => ArrayHelper::get($keys, "location_id")
            ],
            "checkout_options"   => [
                "redirect_url" => $listenerUrl
            ],
            "payment_note"       => $form->title . ' - Submission #' . $submission->id
        ];

        $prePopulatedData = [];

        $buyerEmail = PaymentHelper::getCustomerEmail($submission, $form);
        if ($buyerEmail && is_email($buyerEmail)) {
            $prePopulatedData['buyer_email'] = $buyerEmail;
        }

        $buyerPhone = PaymentHelper::getCustomerPhoneNumber($submission, $form);
        if ($buyerPhone) {
            $prePopulatedData['buyer_phone_number'] = $buyerPhone;
        }

        $buyerAddress = PaymentHelper::getCustomerAddress($submission);
        if ($buyerAddress) {
            $address = [];
            $addressMap = [
                'address_line_1' => 'address_line_1',
                'address_line_2' => 'address_line_2',
                'city'           => 'locality',
                'state'          => 'administrative_district_level_1',
                'zip'            => 'postal_code',
                'country'        => 'country',
            ];
            foreach ($addressMap as $ffKey => $squareKey) {
                $value = ArrayHelper::get($buyerAddress, $ffKey, '');
                if ($value) {
                    $address[$squareKey] = $value;
                }
            }
            if ($address) {
                $prePopulatedData['buyer_address'] = $address;
            }
        }

        $buyerName = PaymentHelper::getCustomerName($submission, $form);
        if ($buyerName) {
            $nameParts = explode(' ', trim($buyerName), 2);
            $firstName = $nameParts[0];
            $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
            if (empty($prePopulatedData['buyer_address'])) {
                $prePopulatedData['buyer_address'] = [];
            }
            $prePopulatedData['buyer_address']['first_name'] = $firstName;
            if ($lastName) {
                $prePopulatedData['buyer_address']['last_name'] = $lastName;
            }
        }

        if ($prePopulatedData) {
            $paymentArgs['pre_populated_data'] = $prePopulatedData;
        }

        $paymentArgs = apply_filters('fluentform/square_payment_args', $paymentArgs, $submission, $transaction, $form);
        $paymentIntent = (new API())->makeApiCall('online-checkout/payment-links', $paymentArgs, $form->id, 'POST', true);
        if (is_wp_error($paymentIntent)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Square Payment Redirect Error', 'fluentformpro'),
                'description'      => $paymentIntent->get_error_message()
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => $paymentIntent->get_error_message()
            ], 423);
        }

        $paymentLinkId = sanitize_text_field(ArrayHelper::get($paymentIntent, 'payment_link.id', ''));
        $orderId = sanitize_text_field(ArrayHelper::get($paymentIntent, 'payment_link.order_id', ''));
        if ($paymentLinkId) {
            Helper::setSubmissionMeta($submission->id, '_square_payment_id', $paymentLinkId);
        }
        if ($orderId) {
            Helper::setSubmissionMeta($submission->id, '_square_order_id', $orderId);
        }

        do_action('fluentform/log_data', [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Redirect to Square', 'fluentformpro'),
            'description'      => __('User redirect to Square for completing the payment', 'fluentformpro')
        ]);

        $checkoutPageUrl = ArrayHelper::get($paymentIntent, 'payment_link.url', '');
        if (!$checkoutPageUrl) {
            $checkoutPageUrl = ArrayHelper::get($paymentIntent, 'payment_link.long_url', '');
        }

        if (!$checkoutPageUrl) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Square Payment Link Missing', 'fluentformpro'),
                'description'      => __('Square returned a successful response but no checkout URL was found.', 'fluentformpro')
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => __('Could not retrieve the Square payment link. Please try again.', 'fluentformpro')
            ], 423);
        }

        $checkoutPageUrl = esc_url($checkoutPageUrl);

        wp_send_json_success([
            'nextAction'   => 'payment',
            'actionName'   => 'normalRedirect',
            'redirect_url' => $checkoutPageUrl,
            'message'      => __('You are redirecting to square to complete the purchase. Please wait while you are redirecting....', 'fluentformpro'),
            'result'       => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }
    
    public function getProductNames()
    {
        $orderItems = $this->getOrderItems();
        $itemsHtml = '';
        foreach ($orderItems as $item) {
            $itemsHtml != "" && $itemsHtml .= ", ";
            $itemsHtml .=  $item->item_name ;
        }
        
        return $itemsHtml;
    }
    
    public function handleSessionRedirectBack($data)
    {
        $submissionId = intval($data['fluentform_payment']);
        $this->setSubmissionId($submissionId);
        $submission = $this->getSubmission();
        $transaction = $this->getLastTransaction($submissionId);

        // Prefer stored order ID (trusted); fall back to URL param only if not stored
        $storedOrderId = Helper::getSubmissionMeta($submissionId, '_square_order_id');
        $urlOrderId = sanitize_text_field(ArrayHelper::get($data, 'orderId', ArrayHelper::get($data, 'transactionId', '')));
        $paymentId = $storedOrderId ?: $urlOrderId;

        if ($storedOrderId && $urlOrderId && $storedOrderId !== $urlOrderId) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Square Order ID Mismatch', 'fluentformpro'),
                'description'      => __('The order ID from the callback URL does not match the stored order ID. Possible tampering.', 'fluentformpro')
            ]);
            $returnData = $this->handleFailed($submission, $transaction);
            $returnData['type'] = 'failed';
            return $this->showPaymentResult($returnData);
        }

        if (!$paymentId) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Square Order ID Missing', 'fluentformpro'),
                'description'      => __('Could not determine the Square order ID from callback URL or stored metadata.', 'fluentformpro')
            ]);
            $returnData = $this->handleFailed($submission, $transaction);
            $returnData['type'] = 'failed';
            return $this->showPaymentResult($returnData);
        }

        $payment = (new API())->makeApiCall('orders/' . $paymentId, [], $submission->form_id, 'GET', true);

        if (is_wp_error($payment)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Square Payment Error', 'fluentformpro'),
                'description'      => $payment->get_error_message()
            ]);
            $returnData = $this->handleFailed($submission, $transaction);
            $returnData['type'] = 'failed';
        } else {
            // Payment Links orders stay OPEN after payment (COMPLETED only after fulfillment).
            // Check tenders exist and net_amount_due is 0 to confirm payment was collected.
            $tenders = ArrayHelper::get($payment, 'order.tenders', []);
            $amountDue = (int) ArrayHelper::get($payment, 'order.net_amount_due_money.amount', -1);
            $orderState = ArrayHelper::get($payment, 'order.state', '');
            $isPaid = ($orderState == 'COMPLETED') || (count($tenders) > 0 && $amountDue === 0);

            if ($isPaid) {
                $returnData = $this->handlePaid($submission, $transaction, $payment);
                // handlePaid may detect amount/currency mismatch and mark status as 'failed'
                $updatedTransaction = $this->getLastTransaction($submissionId);
                $returnData['type'] = ($updatedTransaction && $updatedTransaction->status === 'paid') ? 'success' : 'failed';
            } else {
                $returnData = $this->handleFailed($submission, $transaction);
                $returnData['type'] = 'failed';
            }
        }

        $this->showPaymentResult($returnData);
    }

    private function showPaymentResult($returnData)
    {
        if (!isset($returnData['is_new'])) {
            $returnData['is_new'] = false;
        }
        $redirectUrl = ArrayHelper::get($returnData, 'result.redirectUrl');

        if ($redirectUrl) {
            wp_redirect($redirectUrl);
            exit;
        }
        $this->showPaymentView($returnData);
    }

    private function handleFailed($submission, $transaction)
    {
        $this->setSubmissionId($submission->id);
        $this->changeSubmissionPaymentStatus('failed');
        $this->changeTransactionStatus($transaction->id, 'failed');
        if ($this->getMetaData('is_form_action_fired') == 'yes') {
            return $this->completePaymentSubmission(false);
        }
        $this->setMetaData('is_form_action_fired', 'yes');
        return $this->getReturnData();
    }

    
    private function handlePaid($submission, $transaction, $payment)
    {
        $this->setSubmissionId($submission->id);
        if ($this->getMetaData('is_form_action_fired') == 'yes') {
            return $this->completePaymentSubmission(false);
        }
    
        $status = 'paid';
    
        // Let's make the payment as paid
        $updateData = [
            'payment_note' => maybe_serialize($payment),
            'charge_id'    => sanitize_text_field(ArrayHelper::get($payment, 'order.id')),
        ];
    
        if ($last4 = ArrayHelper::get($payment, 'order.tenders.0.card_details.card.last_4')) {
            $updateData['card_last_4'] = $last4;
        }
        // Validate amount and currency BEFORE marking as paid
        $expectedAmount = $transaction->payment_total;
        $receivedAmount = (int) ArrayHelper::get($payment, 'order.total_money.amount', 0);
        $receivedCurrency = ArrayHelper::get($payment, 'order.total_money.currency', '');

        if (PaymentHelper::isZeroDecimal($transaction->currency)) {
            $expectedAmount = (int)($transaction->payment_total / 100);
        }

        if ($expectedAmount != $receivedAmount || strtoupper($transaction->currency) != strtoupper($receivedCurrency)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Transaction Amount Mismatch - Square', 'fluentformpro'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'description'      => __('Transaction Amount should be ' . PaymentHelper::formatMoney($expectedAmount,
                        $transaction->currency) . ' but received ' . PaymentHelper::formatMoney($receivedAmount,
                        $receivedCurrency), 'fluentformpro')
            ]);
            $status = 'failed';
        }

        $this->updateTransaction($transaction->id, $updateData);
        $this->changeSubmissionPaymentStatus($status);
        $this->changeTransactionStatus($transaction->id, $status);
        $this->recalculatePaidTotal();
        $returnData = $this->getReturnData();

        $this->setMetaData('is_form_action_fired', 'yes');

        return $returnData;
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
            $errorMessage = __('Square Error: Square does not support subscriptions right now!', 'fluentformpro');
            $errors[] = apply_filters('fluentform/payment_error_message', $errorMessage, null, $form);
        }
        return $errors;
    }
}
