<?php

namespace FluentFormPro\Payments\PaymentMethods\Paddle;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;


class PaddleProcessor extends BaseProcessor
{
    public $method = 'paddle';

    protected $form;

    public function init()
    {
        add_action('fluentform/process_payment_' . $this->method, [$this, 'handlePaymentAction'], 10, 6);
        add_action('fluentform/payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));
        add_filter('fluentform/validate_payment_items_' . $this->method, [$this, 'validateSubmittedItems'], 10, 4);

        add_filter('fluentform/form_payment_settings', [$this, 'modifyFormPaymentSettings'], 10, 2);
        add_filter('fluentform/submission_order_items', [$this, 'resolveSubmissionOrderItems'], 10, 4);

        add_action('wp_ajax_fluentform_paddle_confirm_payment', array($this, 'confirmModalPayment'));
        add_action('wp_ajax_nopriv_fluentform_paddle_confirm_payment', array($this, 'confirmModalPayment'));

        // verifyPaymentNonce() accepts an absent nonce unless this is true.
        add_filter('fluentform/paddle_nonce_strict', '__return_true');
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

        if ($hasSubscriptions) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('Skip Subscription Item', 'fluentformpro'),
                'description'      => __('Paddle does not support subscriptions right now!', 'fluentformpro')
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

        $formPaymentSettings = PaymentHelper::getFormSettings($form->id, 'admin');

        $this->handlePayment($transaction, $submission, $form, $methodSettings, $formPaymentSettings);
    }

    protected function handlePayment($transaction, $submission, $form, $methodSettings, $formPaymentSettings)
    {
        $ipnDomain = site_url('index.php');
        if (defined('FLUENTFORM_PAY_IPN_DOMAIN') && FLUENTFORM_PAY_IPN_DOMAIN) {
            $ipnDomain = FLUENTFORM_PAY_IPN_DOMAIN;
        }

        $successArgs = [
            'fluentform_payment_api_notify' => 1,
            'payment_method'                => $this->method,
            'fluentform_payment'            => $submission->id,
            'transaction_hash'              => $transaction->transaction_hash,
            'type'                          => 'success'
        ];
        $successUrl = add_query_arg($successArgs, $ipnDomain);

        $cancelArgs = array_merge($successArgs, ['type' => 'cancel']);
        $cancelUrl = $submission->source_url;
        if (!wp_http_validate_url($cancelUrl)) {
            $cancelUrl = site_url($cancelUrl);
        }
        $cancelUrl = add_query_arg($cancelArgs, $cancelUrl);

        $currency = strtoupper($transaction->currency);
        $this->supportedCurrency($currency, $submission);

        $orderItems = $this->getOrderItems();

        $paymentArgs = [
            'collection_mode' => 'automatic',
            'checkout'        => [
                'url'                  => $successUrl,
                'customer_success_url' => wp_sanitize_redirect($successUrl),
                'customer_cancel_url'  => wp_sanitize_redirect($cancelUrl)
            ]
        ];

        $items = [];

        if (ArrayHelper::get($formPaymentSettings, 'paddle_transaction_type') == 'non_catalog_price') {
            $products = ArrayHelper::get($formPaymentSettings, 'paddle_non_catalog_price_data');

            foreach ($products as $product) {
                $productId = ArrayHelper::get($product, 'product_id');
                $paymentName = ArrayHelper::get($product, 'payment_item');

                foreach ($orderItems as $singleOrder) {
                    if ($singleOrder->parent_holder == $paymentName) {
                        $quantity = $singleOrder->quantity;
                        $itemName = $singleOrder->item_name;
                        $itemPrice = $singleOrder->item_price;

                        $items[] = [
                            'quantity' => intval($quantity),
                            'price'    => [
                                'description' => $itemName,
                                'unit_price'  => [
                                    'amount'        => $itemPrice,
                                    'currency_code' => $currency
                                ],
                                'product_id'  => $productId
                            ]
                        ];
                    }
                }
            }
        } elseif (ArrayHelper::get($formPaymentSettings, 'paddle_transaction_type') == 'catalog') {
            $prices = ArrayHelper::get($formPaymentSettings, 'paddle_catalog_data');
            foreach ($prices as $price) {
                $priceId = ArrayHelper::get($price, 'price_id');
                $quantityName = ArrayHelper::get($price, 'quantity', 1);
                $quantity = ArrayHelper::get($submission->response, $quantityName);
                $items[] = [
                    'quantity' => intval($quantity),
                    'price_id' => $priceId
                ];
            }
        } else {
            $items = [
                [
                    "quantity" => 1,
                    "price"    => [
                        'description' => $transaction->transaction_hash,
                        "unit_price"  => [
                            "amount"        => $transaction->payment_total,
                            "currency_code" => $currency
                        ],
                        "product"     => [
                            "name"         => $this->getProductNames(),
                            "tax_category" => "standard"
                        ]
                    ]
                ]
            ];
        }

        $paymentArgs = array_merge($paymentArgs, ['items' => $items]);

        $customerId = $this->handleCustomer($submission, $form);
        if ($customerId) {
            $paymentArgs['customer_id'] = $customerId;
        }

        $paymentArgs = apply_filters('fluentform/paddle_payment_args', $paymentArgs, $submission, $transaction, $form);

        $paymentIntent = (new API())->makeApiCall('transactions', $paymentArgs, $form->id, 'POST');

        if (is_wp_error($paymentIntent)) {
            $logData = [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Paddle Payment Error', 'fluentformpro'),
                'description'      => $paymentIntent->get_error_message()
            ];

            do_action('fluentform/log_data', $logData);

            wp_send_json_success([
                'message'     => $paymentIntent->get_error_message(),
                'append_data' => [
                    '__entry_intermediate_hash' => Helper::getSubmissionMeta($transaction->submission_id,
                        '__entry_intermediate_hash')
                ]
            ], 423);
        }

        if (ArrayHelper::get($paymentIntent, 'data.id')) {
            // Must upsert: setMetaData() only inserts and getMetaData() reads
            // first() with no ORDER BY, so a retried checkout would leave the
            // original intent winning and refuse whoever paid the newest one.
            Helper::setSubmissionMeta(
                $submission->id,
                'paddle_payment_intent_id',
                ArrayHelper::get($paymentIntent, 'data.id'),
                $form->id
            );

            $redirectUrl = ArrayHelper::get($paymentIntent, 'data.checkout.url');
            $logData = [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('Redirect to Paddle', 'fluentformpro'),
                'description'      => __('User redirect to Paddle for completing the payment', 'fluentformpro')
            ];

            do_action('fluentform/log_data', $logData);

            wp_send_json_success([
                'nextAction'   => 'payment',
                'actionName'   => 'normalRedirect',
                'redirect_url' => $redirectUrl,
                'message'      => __('You are redirecting to complete the purchase. Please wait while you are redirecting....',
                    'fluentformpro'),
                'result'       => [
                    'insert_id' => $submission->id,
                ]
            ], 200);
        }
    }

    public function handleSessionRedirectBack($data)
    {
        $type = sanitize_text_field(ArrayHelper::get($data, 'type'));
        $submissionId = intval(ArrayHelper::get($data, 'fluentform_payment'));
        $transactionHash = sanitize_text_field(ArrayHelper::get($data, 'transaction_hash'));

        $this->setSubmissionId($submissionId);
        $submission = $this->getSubmission();
        if (!$submission) {
            return;
        }

        $transaction = $this->getTransaction($transactionHash, 'transaction_hash');

        if (!$transaction) {
            $returnData = [
                'insert_id' => $submissionId,
                'title'     => __('Invalid Transaction', 'fluentformpro'),
                'result'    => false,
                'error'     => __('No valid transaction found for this payment.', 'fluentformpro')
            ];
            $this->showPaymentView($returnData);
            return;
        }

        if ($type == 'success') {
            if ($transaction->status === 'paid' || $this->getMetaData('is_form_action_fired') == 'yes') {
                $returnData = $this->getReturnData();
                $returnData['type'] = 'success';
            } else {
                $message = '<div class="ff_paddle_payment_container"><p></p></div>';

                $returnData = [
                    'insert_id' => $submissionId,
                    'title'     => __('Processing Paddle Payment', 'fluentformpro'),
                    'result'    => false,
                    'error'     => $message,
                    'type'      => 'success',
                    'is_new'    => true,
                    'txn_id'    => $transactionHash
                ];

                $this->addCheckoutJs($submissionId, $transactionHash);
            }
        } else {
            $returnData = [
                'insert_id' => $submissionId,
                'title'     => __('Payment Cancelled', 'fluentformpro'),
                'result'    => false,
                'error'     => __('Looks like you have cancelled the payment. Please try again!', 'fluentformpro'),
                'type'      => 'failed',
                'is_new'    => false
            ];
        }

        $this->showPaymentView($returnData);
    }

    protected function getPaymentMode()
    {
        $isLive = PaddleSettings::isLive();
        if ($isLive) {
            return 'production';
        }
        return 'sandbox';
    }

    protected function getProductNames()
    {
        $orderItems = $this->getOrderItems();
        $itemsHtml = '';
        foreach ($orderItems as $item) {
            $itemsHtml != "" && $itemsHtml .= ", ";
            $itemsHtml .= $item->item_name;
        }

        return $itemsHtml;
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
            $errors[] = __('Paddle Error: Paddle does not support subscriptions right now!', 'fluentformpro');
        }
        return $errors;
    }

    protected function supportedCurrency($currency, $submission)
    {
        $supportedCurrencies = [
            'USD',
            'EUR',
            'GBP',
            'JPY',
            'AUD',
            'CAD',
            'CHF',
            'HKD',
            'SGD',
            'SEK',
            'ARS',
            'BRL',
            'CNY',
            'COP',
            'CZK',
            'DKK',
            'HUF',
            'ILS',
            'INR',
            'KRW',
            'MXN',
            'NOK',
            'NZD',
            'PLN',
            'RUB',
            'THB',
            'TRY',
            'TWD',
            'UAH',
            'ZAR'
        ];

        if (!in_array($currency, $supportedCurrencies)) {
            wp_send_json([
                'errors'      => $currency . __('is not supported by Paddle payment method', 'fluentformpro'),
                'append_data' => [
                    '__entry_intermediate_hash' => Helper::getSubmissionMeta($submission->id,
                        '__entry_intermediate_hash')
                ]
            ], 423);
        }
    }

    public function confirmModalPayment()
    {
        $transactionHash = sanitize_text_field(ArrayHelper::get($_REQUEST, 'transaction_hash'));
        $transaction = $this->getTransaction($transactionHash, 'transaction_hash');

        if (!$transaction) {
            wp_send_json([
                'errors' => __('Payment Error: Invalid Request', 'fluentformpro'),
            ], 423);
        }

        $this->setSubmissionId($transaction->submission_id);

        // Verify nonce
        $nonceResult = $this->verifyPaymentNonce($transaction->submission_id, 'paddle');
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

        $vendorPayment = ArrayHelper::get($_REQUEST, 'paddle_payment');
        $checkoutId = sanitize_text_field(ArrayHelper::get($vendorPayment, 'transaction_id'));

        if ($checkoutId) {
            $this->setSubmissionId($transaction->submission_id);

            // Bind the browser-supplied provider transaction to THIS local order:
            // it must equal the intent stored at creation, and must not already
            // belong to another row. Amount/currency agreement alone does not
            // prove identity (confused deputy).
            $expectedIntentId = $this->getMetaData('paddle_payment_intent_id');
            if (!$expectedIntentId || !hash_equals((string) $expectedIntentId, (string) $checkoutId)) {
                wp_send_json([
                    'errors' => __('Payment verification failed: transaction mismatch.', 'fluentformpro'),
                ], 423);
            }

            $reusedTransaction = wpFluent()->table('fluentform_transactions')
                ->where('charge_id', $checkoutId)
                ->where('id', '!=', $transaction->id)
                ->first();
            if ($reusedTransaction) {
                wp_send_json([
                    'errors' => __('Payment verification failed: transaction already used.', 'fluentformpro'),
                ], 423);
            }

            // Verify the transaction with Paddle API before marking as paid
            $paddleApi = new \FluentFormPro\Payments\PaymentMethods\Paddle\API();
            $verifiedTransaction = $paddleApi->makeApiCall('transactions/' . $checkoutId, [], $transaction->form_id, 'GET');

            if (is_wp_error($verifiedTransaction)) {
                do_action('fluentform/log_data', [
                    'parent_source_id' => $transaction->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $transaction->submission_id,
                    'component'        => 'Payment',
                    'status'           => 'error',
                    'title'            => __('Paddle Payment Verification Failed', 'fluentformpro'),
                    'description'      => $verifiedTransaction->get_error_message()
                ]);

                wp_send_json([
                    'errors' => __('Payment verification failed: ', 'fluentformpro') . $verifiedTransaction->get_error_message(),
                ], 423);
            }

            $verifiedStatus = ArrayHelper::get($verifiedTransaction, 'data.status', '');
            if (!in_array($verifiedStatus, ['completed', 'paid', 'billed'])) {
                wp_send_json([
                    'errors' => __('Payment has not been completed yet. Status: ', 'fluentformpro') . $verifiedStatus,
                ], 423);
            }

            $paddleTotals = ArrayHelper::get($verifiedTransaction, 'data.details.totals', []);
            $amounts = self::reconcileVerifiedAmount($transaction->payment_total, $paddleTotals);

            if ($amounts['gross'] <= 0) {
                wp_send_json([
                    'errors' => __('Payment verification failed: missing amount.', 'fluentformpro'),
                ], 423);
            }

            // Length check too: getFormCurrency() can return '', which would
            // otherwise match an empty provider code and assert nothing.
            $paddleCurrency = strtoupper((string) ArrayHelper::get($verifiedTransaction, 'data.currency_code', ''));
            $localCurrency = strtoupper((string) $transaction->currency);
            if (3 !== strlen($paddleCurrency) || 3 !== strlen($localCurrency) || $paddleCurrency !== $localCurrency) {
                wp_send_json([
                    'errors' => __('Payment verification failed: currency mismatch.', 'fluentformpro'),
                ], 423);
            }

            // Deliberately not gated on a non-zero payment_total: a zero recorded
            // total is reachable (catalog mode can filter every order item out)
            // and gating on it skipped verification for exactly those orders.
            if (!$amounts['matches']) {
                $strict = apply_filters('fluentform/paddle_amount_mismatch_strict', true);

                do_action('fluentform/log_data', [
                    'parent_source_id' => $transaction->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $transaction->submission_id,
                    'component'        => 'Payment',
                    'status'           => 'error',
                    'title'            => __('Paddle Amount Mismatch', 'fluentformpro'),
                    // Reason included because some rules reject while the two
                    // amounts are equal, which otherwise reads as a contradiction.
                    'description'      => sprintf(
                        // translators: %1$s is the reason code, %2$d is the expected amount, %3$d is the Paddle charged amount, %4$d is the tax within it, %5$s is the raw provider totals, %6$s is the payment status
                        __('Rejected (%1$s): expected %2$d, Paddle charged %3$d including %4$d tax. Provider totals: %5$s. Payment %6$s.', 'fluentformpro'),
                        $amounts['reason'],
                        intval($transaction->payment_total),
                        $amounts['gross'],
                        $amounts['tax'],
                        wp_json_encode($paddleTotals),
                        $strict ? 'rejected' : 'not settled'
                    )
                ]);

                // Neither branch settles. Non-strict only buys a durable record
                // to work from, since the charge is captured but the order stuck.
                if (!$strict) {
                    Helper::setSubmissionMeta(
                        $transaction->submission_id,
                        'paddle_amount_review',
                        [
                            'reason'         => $amounts['reason'],
                            'expected'       => intval($transaction->payment_total),
                            'charged'        => $amounts['gross'],
                            'tax'            => $amounts['tax'],
                            'transaction_id' => $checkoutId,
                            'totals'         => $paddleTotals,
                        ],
                        $transaction->form_id
                    );

                    wp_send_json([
                        'errors' => __('Payment amount mismatch detected. The payment has been flagged for review — please contact the site admin.', 'fluentformpro')
                    ], 423);
                }

                wp_send_json([
                    'errors' => __('Payment amount verification failed.', 'fluentformpro')
                ], 423);
            }

            // Get form confirmation settings for redirect URL
            $form = $this->getForm();
            $formSettings = Helper::getFormMeta($form->id, 'formSettings', []);

            // Determine redirect URL
            $redirectTo = ArrayHelper::get($formSettings, 'confirmation.redirectTo');
            if ($redirectTo && $redirectTo == 'customUrl') {
                $redirectUrl = ArrayHelper::get($formSettings, 'confirmation.customUrl');
            } else if ($redirectTo) {
                $redirectUrl = get_permalink($redirectTo);
            } else {
                $redirectUrl = wp_get_referer() ?: site_url();
            }

            $logData = [
                'parent_source_id' => $transaction->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $transaction->submission_id,
                'component'        => 'Payment',
                'status'           => 'success',
                'title'            => __('Payment Success', 'fluentformpro'),
                'description'      => __('Paddle payment verified and marked as paid', 'fluentformpro')
            ];

            do_action('fluentform/log_data', $logData);

            $submission = $this->getSubmission();
            $returnData = $this->handlePaid($submission, $transaction, $verifiedTransaction);

            // After handlePaid, so a failure cannot leave a breakdown on an
            // unpaid submission. payment_total stays net — Paddle remits the
            // tax. The basis matters because under tax-inclusive pricing the tax
            // is part of payment_total, so summing without it double-counts.
            if ($amounts['tax']) {
                Helper::setSubmissionMeta($transaction->submission_id, 'paddle_tax_total', $amounts['tax'], $transaction->form_id);
                Helper::setSubmissionMeta($transaction->submission_id, 'paddle_gross_total', $amounts['gross'], $transaction->form_id);
                Helper::setSubmissionMeta($transaction->submission_id, 'paddle_amount_basis', $amounts['reason'], $transaction->form_id);
            }

            $returnData['payment'] = $verifiedTransaction;
            $returnData['success_message'] = __('Your payment has successfully been processed!', 'fluentformpro');
            $returnData['redirect_url'] = $redirectUrl;
            $returnData['title'] = __('Payment Successful', 'fluentformpro');

            wp_send_json_success($returnData, 200);
        }

        wp_send_json([
            'errors'      => __('Payment could not be verified. Please contact site admin', 'fluentformpro'),
            'append_data' => [
                '__entry_intermediate_hash' => Helper::getSubmissionMeta($transaction->submission_id,
                    '__entry_intermediate_hash')
            ]
        ], 423);
    }

    /**
     * Reconcile what Paddle captured against what this order was recorded at.
     *
     * Paddle can be set to add tax on top of the price ("prices exclude tax",
     * tax_mode external), so the customer is legitimately charged more than the
     * net figure the form recorded. That tax delta is the ONLY difference
     * tolerated here — every other amount is still a mismatch, so a checkout
     * that settles for a different figure cannot be confirmed.
     *
     * Each guard below is load-bearing — removing any one lets an underpayment
     * settle:
     *   - negative tax would make (gross - tax) exceed gross;
     *   - incoherent totals could be bent into a match;
     *   - credit/balance are absent from the coherence identity, so a
     *     credit-settled charge satisfies it while no money moved;
     *   - a non-positive expectation is satisfiable by tax === gross.
     * With them held, every accepting branch implies funds collected >=
     * expected. Tax of 0 collapses to plain equality.
     *
     * @param int|string $expectedTotal Recorded order total, in cents
     * @param array      $totals        Paddle's data.details.totals block
     * @return array{matches: bool, gross: int, tax: int, net: int, reason: string}
     */
    public static function reconcileVerifiedAmount($expectedTotal, $totals)
    {
        $expected = intval($expectedTotal);
        $gross = intval(ArrayHelper::get($totals, 'total', 0));
        $tax = intval(ArrayHelper::get($totals, 'tax', 0));
        $subtotal = intval(ArrayHelper::get($totals, 'subtotal', 0));
        $discount = intval(ArrayHelper::get($totals, 'discount', 0));
        $credit = intval(ArrayHelper::get($totals, 'credit', 0));
        $balance = intval(ArrayHelper::get($totals, 'balance', 0));

        $result = [
            'matches' => false,
            'gross'   => $gross,
            'tax'     => $tax,
            'net'     => $gross - $tax,
            'reason'  => '',
        ];

        if ($gross <= 0) {
            $result['reason'] = 'missing_amount';
            return $result;
        }

        if ($tax < 0) {
            $result['reason'] = 'negative_tax';
            return $result;
        }

        if ($subtotal - $discount + $tax !== $gross) {
            $result['reason'] = 'incoherent_totals';
            return $result;
        }

        if ($credit > 0 || $balance > 0) {
            $result['reason'] = 'funds_not_collected';
            return $result;
        }

        if ($expected <= 0) {
            $result['reason'] = 'no_expected_amount';
            return $result;
        }

        if ($gross === $expected) {
            $result['matches'] = true;
            $result['reason'] = 'exact';
            return $result;
        }

        if ($gross - $tax === $expected) {
            $result['matches'] = true;
            $result['reason'] = 'tax_added_on_top';
            return $result;
        }

        $result['reason'] = 'amount_mismatch';

        return $result;
    }

    /**
     * Pull the vendor identifiers off a verified transaction.
     *
     * Paths are those of the raw `GET /transactions/{id}` envelope. Reading the
     * flattened browser-payload paths instead wrote charge_id empty, which left
     * the reuse lookup in confirmModalPayment() unable to ever match.
     *
     * @param array $verifiedTransaction
     * @return array
     */
    public static function extractVendorFields($verifiedTransaction)
    {
        $data = ArrayHelper::get($verifiedTransaction, 'data', []);

        // `payments` records every attempt, so element 0 is often a decline.
        $card = [];
        foreach ((array) ArrayHelper::get($data, 'payments', []) as $payment) {
            if ('captured' === ArrayHelper::get($payment, 'status')) {
                $card = (array) ArrayHelper::get($payment, 'method_details.card', []);
                break;
            }
        }

        $brand = ArrayHelper::get($card, 'type');
        $last4 = ArrayHelper::get($card, 'last4');

        return [
            'charge_id'   => sanitize_text_field(ArrayHelper::get($data, 'id', '')),
            'card_brand'  => $brand ? sanitize_text_field($brand) : null,
            'card_last_4' => $last4 ? sanitize_text_field($last4) : null,
        ];
    }

    public function addCheckoutJs($submissionId, $transactionHash)
    {
        wp_enqueue_script('paddle', 'https://cdn.paddle.com/paddle/v2/paddle.js', [], FLUENTFORMPRO_VERSION);
        wp_enqueue_script('ff_paddle_handler', FLUENTFORMPRO_DIR_URL . 'public/js/paddle_handler.js', ['jquery'],
            FLUENTFORMPRO_VERSION);

        $paymentMode = $this->getPaymentMode();
        $submission = $this->getSubmission();
        $form = $this->getForm();

        // Get form confirmation settings for redirect URLs
        $formSettings = Helper::getFormMeta($form->id, 'formSettings', []);

        // Determine redirect URL on success
        $redirectTo = ArrayHelper::get($formSettings, 'confirmation.redirectTo');
        if ($redirectTo && $redirectTo == 'customUrl') {
            $redirectUrl = ArrayHelper::get($formSettings, 'confirmation.customUrl');
        } else if ($redirectTo) {
            $redirectUrl = get_permalink($redirectTo);
        } else {
            $redirectUrl = wp_get_referer() ?: site_url();
        }

        // Get cancel URL
        $cancelUrl = $submission->source_url;
        if (!wp_http_validate_url($cancelUrl)) {
            $cancelUrl = site_url($cancelUrl);
        }

        $allowedPaymentMethods = [
            'alipay', 'apple_pay', 'bancontact', 'card', 'google_pay', 'ideal', 'paypal'
        ];

        $nonce = wp_create_nonce('ff_payment_' . $submissionId);

        $checkoutVars = [
            'ajax_url'                => admin_url('admin-ajax.php'),
            'submission_id'           => $submissionId,
            'transaction_hash'        => $transactionHash,
            '_ff_payment_nonce'       => $nonce,
            'onFailedMessage'         => __("Sorry! We couldn't mark your payment as paid. Please try again later!", 'fluentformpro'),
            'payment_mode'            => $paymentMode,
            'title_message'           => __('Paddle Payment Successful', 'fluentformpro'),
            'theme'                   => 'light',
            'locale'                  => 'en',
            'client_token'            => PaddleSettings::getClientToken(),
            'frame_initial_height'    => '450',
            'frame_style'             => 'width: 100%; min-width: 312px; background-color: transparent; border: none;',
            'allowed_payment_methods' => $allowedPaymentMethods,
            'success_redirect'        => $redirectUrl,
            'failure_redirect'        => $cancelUrl,
            'redirect_delay'          => 1000
        ];

        wp_localize_script('ff_paddle_handler', 'ff_paddle_vars',
            apply_filters('fluentform/paddle_checkout_vars', $checkoutVars));
    }

    public function resolveSubmissionOrderItems($orderItems, $submissionData, $form, $method)
    {
        // Remove extra order items that's not mapping by setting for catalog, non_catalog_price type payment
        if ($this->method === $method) {
            $formPaymentSettings = PaymentHelper::getFormSettings($form->id, 'admin');
            if (ArrayHelper::get($formPaymentSettings, 'paddle_transaction_type') == 'non_catalog_price') {
                $products = ArrayHelper::get($formPaymentSettings, 'paddle_non_catalog_price_data');
                $paymentNames = [];
                foreach ($products as $product) {
                    $paymentNames[] = ArrayHelper::get($product, 'payment_item');
                }
                $orderItems = array_filter($orderItems, function ($item) use ($paymentNames) {
                    return in_array(ArrayHelper::get($item, 'parent_holder'), $paymentNames);
                });
            } elseif (ArrayHelper::get($formPaymentSettings, 'paddle_transaction_type') == 'catalog') {
                $prices = ArrayHelper::get($formPaymentSettings, 'paddle_catalog_data');
                $quantityItems = [];
                $formQuantityField = FormFieldsParser::getElement($form, ['item_quantity_component'], ['settings', 'attributes']);
                if ($formQuantityField) {
                    foreach ($formQuantityField as $quantityField) {
                        if ($targetProductName = ArrayHelper::get($quantityField, 'settings.target_product')) {
                            $quantityItems[ArrayHelper::get($quantityField, 'attributes.name')] = $targetProductName;
                        }
                    }
                }
                $qtyFieldNames = [];
                foreach ($prices as $price) {
                    $mappingQtyName = ArrayHelper::get($price, 'quantity');
                    if ($name = ArrayHelper::get($quantityItems, $mappingQtyName)) {
                        $qtyFieldNames[] = $name;
                    }
                }
                $orderItems = array_filter($orderItems, function ($item) use ($qtyFieldNames) {
                    return in_array(ArrayHelper::get($item, 'parent_holder'), $qtyFieldNames);
                });
            }
        }
        return $orderItems;
    }

    public function modifyFormPaymentSettings($paymentSettings, $formId)
    {
        $availablePaymentMethods = PaymentHelper::getFormPaymentMethods($formId);

        if (
            !ArrayHelper::exists($availablePaymentMethods, 'paddle') &&
            ArrayHelper::get($availablePaymentMethods, 'paddle.enabled') == 'no'
        ) {
            return $paymentSettings;
        }

        $products = $this->getProducts($formId);
        $prices = $this->getPrices($formId);

        $customSettings = [
            'paddle_transaction_type'       => 'non_catalog',
            'paddle_create_customer'        => 'no',
            'paddle_catalog_data'           => [
                [
                    'price_id' => '',
                    'quantity' => ''
                ]
            ],
            'paddle_non_catalog_price_data' => [
                [
                    'product_id'   => '',
                    'payment_item' => ''
                ]
            ]
        ];

        $paymentSettings['settings'] = wp_parse_args($paymentSettings['settings'], $customSettings);
        $paymentSettings['settings']['paddle_products'] = $products;
        $paymentSettings['settings']['paddle_prices'] = $prices;

        return $paymentSettings;
    }

    protected function handlePaid($submission, $transaction, $vendorTransaction)
    {
        $this->setSubmissionId($submission->id);

        // Check if actions are fired
        if ($this->getMetaData('is_form_action_fired') == 'yes') {
            return $this->completePaymentSubmission(false);
        }

        $status = 'paid';

        // Let's make the payment as paid
        $updateData = self::extractVendorFields($vendorTransaction);
        $updateData['payment_note'] = maybe_serialize($vendorTransaction);
        $updateData['payer_email'] = $this->resolvePayerEmail($vendorTransaction, $submission);

        $this->updateTransaction($transaction->id, $updateData);
        $this->changeSubmissionPaymentStatus($status);
        $this->changeTransactionStatus($transaction->id, $status);
        $this->recalculatePaidTotal();
        $returnData = $this->getReturnData();
        $returnData['type'] = 'success';
        $this->setMetaData('is_form_action_fired', 'yes');
        return $returnData;
    }

    /**
     * Resolve the email to stamp on the transaction: the address Paddle billed,
     * then the form's own fields. Never session context.
     *
     * Deliberately not PaymentHelper::getCustomerEmail(), which prefers the
     * logged-in user's account email over the form's fields. payer_email is an
     * ownership key that maybeAssignTransactions() matches on at user_register,
     * so a visitor who happens to be logged in would claim someone else's order.
     *
     * @param array  $vendorTransaction Verified GET /transactions/{id} envelope
     * @param object $submission
     * @return string
     */
    protected function resolvePayerEmail($vendorTransaction, $submission)
    {
        $customerId = ArrayHelper::get($vendorTransaction, 'data.customer_id');

        if ($customerId) {
            $customer = (new API())->makeApiCall(
                'customers/' . $customerId,
                [],
                $submission->form_id,
                'GET'
            );

            if (!is_wp_error($customer)) {
                if ($email = ArrayHelper::get($customer, 'data.email')) {
                    return sanitize_email($email);
                }
            }
        }

        return sanitize_email(self::submissionEmail($submission, $this->getForm()));
    }

    /**
     * The submitter's own email, from the form only.
     *
     * Mirrors the form-field half of PaymentHelper::getCustomerEmail() without
     * its get_current_user_id() branch.
     *
     * @param object $submission
     * @param object $form
     * @return string
     */
    public static function submissionEmail($submission, $form)
    {
        $formSettings = PaymentHelper::getFormSettings($submission->form_id, 'admin');

        if ($mapped = ArrayHelper::get($formSettings, 'receipt_email')) {
            if ($email = ArrayHelper::get($submission->response, $mapped)) {
                return $email;
            }
        }

        if (!$form) {
            return '';
        }

        $emailFields = FormFieldsParser::getInputsByElementTypes($form, ['input_email'], ['attributes']);
        foreach ($emailFields as $field) {
            $name = ArrayHelper::get($field, 'attributes.name');
            if ($name && !empty($submission->response[$name])) {
                return $submission->response[$name];
            }
        }

        return '';
    }

    protected function handleCustomer($submission, $form)
    {
        $customerName = PaymentHelper::getCustomerName($submission, $this->form);
        $customerAddress = PaymentHelper::getCustomerAddress($submission);

        // Same resolver as resolvePayerEmail(), and for the same reason: this is
        // what confirmation later reads back off the Paddle customer to fill
        // payer_email, an ownership key. Sourcing it from the session here would
        // reinstate the problem no matter what confirmation does.
        $customerEmail = self::submissionEmail($submission, $form);

        // No email on the form means no identity we are entitled to assert.
        // Deliberately not falling back to the logged-in account: customer_id is
        // optional on the checkout, so Paddle collects the address itself rather
        // than us attributing the order to whoever happened to be signed in.
        if (!$customerEmail) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $form->id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('Skip Customer Creation', 'fluentformpro'),
                'description'      => __('No email was submitted, so Paddle will collect the customer details at checkout.', 'fluentformpro')
            ]);

            return null;
        }

        // First create a customer
        $customerData = [
            'email' => $customerEmail,
            'name'  => $customerName
        ];

        $customerResponse = (new API())->makeApiCall('customers', $customerData, $form->id, 'POST');
        $customerId = null;

        if (is_wp_error($customerResponse)) {
            $errorData = $customerResponse->get_error_data();
            $errorCode = $customerResponse->get_error_code();
            $errorMessage = $customerResponse->get_error_message();

            // Check if the error is "customer_already_exists"
            if (
                $errorData &&
                isset($errorData['error']) &&
                isset($errorData['error']['code']) &&
                $errorData['error']['code'] === 'customer_already_exists'
            ) {
                // Customer already exists, find by email
                $customerId = $this->findCustomerByEmail($customerEmail, $form, $submission);

                if ($customerId) {
                    do_action('fluentform/log_data', [
                        'parent_source_id' => $form->id,
                        'source_type'      => 'submission_item',
                        'source_id'        => $submission->id,
                        'component'        => 'Payment',
                        'status'           => 'info',
                        'title'            => __('Existing Customer Found', 'fluentformpro'),
                        // phpcs:ignore WordPress.WP.I18n.InterpolatedVariableText
                        'description'      => __("Using existing customer with ID: {$customerId}", 'fluentformpro')
                    ]);
                } else {
                    do_action('fluentform/log_data', [
                        'parent_source_id' => $form->id,
                        'source_type'      => 'submission_item',
                        'source_id'        => $submission->id,
                        'component'        => 'Payment',
                        'status'           => 'failed',
                        'title'            => __('Customer Lookup Failed', 'fluentformpro'),
                        'description'      => __("Customer exists but couldn't be found by email", 'fluentformpro')
                    ]);
                }
            } else {
                // Other error occurred
                do_action('fluentform/log_data', [
                    'parent_source_id' => $form->id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'failed',
                    'title'            => __('Skip Customer Creation', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.InterpolatedVariableText
                    'description'      => __("Error Code: {$errorCode}. Reason: {$errorMessage}", 'fluentformpro')
                ]);
            }
        } else {
            if (isset($customerResponse['data']['id'])) {
                $customerId = $customerResponse['data']['id'];

                do_action('fluentform/log_data', [
                    'parent_source_id' => $form->id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'info',
                    'title'            => __('Customer Created', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.InterpolatedVariableText
                    'description'      => __("Customer created with ID: {$customerId}", 'fluentformpro')
                ]);
            }
        }

        if ($customerAddress && $customerId && !empty($customerAddress['country'])) {
            $this->handleCustomerAddress($customerId, $customerAddress, $form, $submission);
        }

        return $customerId;
    }

    protected function findCustomerByEmail($email, $form, $submission)
    {
        // Use the exact email parameter
        $endpoint = 'customers?email=' . urlencode($email);
        $response = (new API())->makeApiCall($endpoint, [], $form->id);

        if (is_wp_error($response)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $form->id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Customer Search Failed', 'fluentformpro'),
                'description'      => $response->get_error_message()
            ]);
            return null;
        }

        if (!isset($response['data']) || empty($response['data'])) {
            return null;
        }

        return ArrayHelper::get($response, 'data.0.id', '');
    }
    
    protected function handleCustomerAddress($customerId, $customerAddress, $form, $submission)
    {
        $address = [];

        // Paddle requires country code
        if ($country = ArrayHelper::get($customerAddress, 'country')) {
            $address['country_code'] = $country;
        }
        
        if ($firstLine = ArrayHelper::get($customerAddress, 'address_line_1')) {
            $address['first_line'] = $firstLine;
        }

        if ($secondLine = ArrayHelper::get($customerAddress, 'address_line_2')) {
            $address['second_line'] = $secondLine;
        }

        if ($city = ArrayHelper::get($customerAddress, 'city')) {
            $address['city'] = $city;
        }

        if ($state = ArrayHelper::get($customerAddress, 'state')) {
            $address['region'] = $state;
        }

        if ($zip = ArrayHelper::get($customerAddress, 'zip')) {
            $address['postal_code'] = $zip;
        }
        
        $endpoint = 'customers/' . $customerId . '/addresses';
        $customerAddressResponse = (new API())->makeApiCall($endpoint, $address, $form->id, 'POST');

        if (!is_wp_error($customerAddressResponse) && isset($customerAddressResponse['data']['id'])) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $form->id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('Customer Address Created', 'fluentformpro'),
                // phpcs:ignore WordPress.WP.I18n.InterpolatedVariableText
                'description'      => __("Customer address created for customer ID: {$customerId}", 'fluentformpro')
            ]);
            
            return;
        }

        do_action('fluentform/log_data', [
            'parent_source_id' => $form->id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'failed',
            'title'            => __('Skip Customer Address Creation', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.InterpolatedVariableText
            'description'      => __("Could not be able to create address for customer ID: {$customerId}", 'fluentformpro')
        ]);
    }

    private function getProducts($formId)
    {
        $products = (new API())->makeApiCall('products', [], $formId);

        if (is_wp_error($products)) {
            return [];
        }

        $formattedProducts = [];

        foreach ($products['data'] as $product) {
            $id = $product['id'];
            $name = $product['name'];
            $formattedProducts[$id] = $name;
        }

        return $formattedProducts;
    }

    private function getPrices($formId)
    {
        $prices = (new API())->makeApiCall('prices', [], $formId);

        if (is_wp_error($prices)) {
            return [];
        }

        $formattedPrices = [];

        foreach ($prices['data'] as $price) {
            if (ArrayHelper::get($price, 'billing_cycle')) {
                continue;
            }
            $id = $price['id'];
            $description = $price['description'];
            $formattedPrices[$id] = $description;
        }

        return $formattedPrices;
    }
}