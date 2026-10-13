<?php

namespace FluentFormPro\Payments\PaymentMethods\Stripe;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentMethods\Stripe\API\CheckoutSession;
use FluentFormPro\Payments\PaymentMethods\Stripe\API\Plan;

class StripeProcessor extends BaseProcessor
{
    public $method = 'stripe';

    protected $form;

    public function init()
    {
        add_action('fluentform/process_payment_stripe_hosted', array($this, 'handlePaymentAction'), 10, 6);
        add_action('fluentform/payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));
    }

    public function handlePaymentAction($submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable)
    {
        $this->setSubmissionId($submissionId);
        $this->form = $form;
        $submission = $this->getSubmission();
        $paymentTotal = $this->getAmountTotal();

        if (!$paymentTotal && !$hasSubscriptions) {
            return false;
        }

        // Create the initial transaction here
        $transaction = $this->createInitialPendingTransaction($submission, $hasSubscriptions);

        $this->handleCheckoutSession($transaction, $submission, $form, $methodSettings);
    }

    public function handleCheckoutSession($transaction, $submission, $form, $methodSettings)
    {

        $formSettings = PaymentHelper::getFormSettings($form->id);

        $args = [
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'success'
        ];


        $successUrl = add_query_arg($args, site_url('index.php'));

        $cancelUrl = $submission->source_url;

        if (!wp_http_validate_url($cancelUrl)) {
            $cancelUrl = site_url($cancelUrl);
        }
        

        $checkoutArgs = [
            'cancel_url'                 => wp_sanitize_redirect($cancelUrl),
            'success_url'                => wp_sanitize_redirect($successUrl),
            'locale'                     => 'auto',
            'billing_address_collection' => 'auto',
            'client_reference_id'        => $submission->id,
            'customer_email'             => $transaction->payer_email,
            'metadata'                   => [
                'submission_id'  => $submission->id,
                'form_id'        => $form->id,
                'transaction_id' => ($transaction) ? $transaction->id : ''
            ]
        ];

        if (ArrayHelper::get($methodSettings, 'settings.require_billing_info.value') == 'yes') {
            $checkoutArgs['billing_address_collection'] = 'required';
        }

        if (ArrayHelper::get($methodSettings, 'settings.require_shipping_info.value') == 'yes') {
            $checkoutArgs['shipping_address_collection'] = [
                'allowed_countries' => StripeSettings::supportedShippingCountries()
            ];
        }

        if ($lineItems = $this->getFormattedItems($submission->currency)) {
            $checkoutArgs['line_items'] = $lineItems;
        }

        $receiptEmail = PaymentHelper::getCustomerEmail($submission, $form);

        if ($receiptEmail) {
            $checkoutArgs['customer_email'] = $receiptEmail;
        }

        if ($transaction->transaction_type == 'subscription') {
            unset($checkoutArgs['submit_type']);

            $subscriptions = $this->getSubscriptions();
            $subscription = $subscriptions[0];

            if ($subscription->initial_amount) {
                if (!isset($checkoutArgs['line_items'])) {
                    $checkoutArgs['line_items'] = [];
                }
                $price = $subscription->initial_amount;
                if (PaymentHelper::isZeroDecimal($transaction->currency)) {
                    $price = intval($price / 100);
                }
                $checkoutArgs['line_items'][] = [
                    'amount'   => $price,
                    'currency' => $transaction->currency,
                    // translators: %s is the subscription plan name
                    'name'     => sprintf(__('Signup fee for %s', 'fluentformpro'), $subscription->plan_name),
                    'quantity' => 1
                ];
            }

            $stripePlan = Plan::getSubscriptionPlanBySubscription($subscription, $transaction->currency);

            if(is_wp_error($stripePlan)) {
                $this->handlePaymentChargeError($stripePlan->get_error_message(), $submission, $transaction);
            }

            $this->updateSubscription($subscription->id, [
                'vendor_plan_id' => $stripePlan->id
            ]);

            $subsArgs = [
                'items' => [
                    [
                        'plan'     => $stripePlan->id,
                        'quantity' => $subscription->quantity ? $subscription->quantity : 1
                    ]
                ]
            ];

            if ($subscription->trial_days) {
                $subsArgs['trial_period_days'] = $subscription->trial_days;
            }

            $subsArgs['metadata'] = $this->getIntentMetaData($submission, $form, $transaction);

            $checkoutArgs['subscription_data'] = $subsArgs;

        } else {
            $checkoutArgs['submit_type'] = 'auto';
            $checkoutArgs['payment_intent_data'] = $this->getPaymentIntentData($transaction, $submission, $form);
            if ($receiptEmail && ArrayHelper::get($formSettings, 'disable_stripe_payment_receipt') != 'yes') {
                $checkoutArgs['payment_intent_data']['receipt_email'] = $receiptEmail;
            }
        }
        if ($formSettings['transaction_type'] == 'donation' && $transaction->transaction_type != 'subscription') {
            $checkoutArgs['submit_type'] = 'donate';
        }

        $checkoutArgs = apply_filters_deprecated(
            'fluentform_stripe_checkout_args',
            [
                $checkoutArgs,
                $submission,
                $transaction,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/stripe_checkout_args',
            'Use fluentform/stripe_checkout_args instead of fluentform_stripe_checkout_args.'
        );

        $checkoutArgs = apply_filters('fluentform/stripe_checkout_args', $checkoutArgs, $submission, $transaction, $form);

        $session = CheckoutSession::create($checkoutArgs);

        if (!empty($session->error) || is_wp_error($session)) {
            $error = __('Something is wrong', 'fluentformpro');
            if (is_wp_error($session)) {
                $error = $session->get_error_message();
            } else if (!empty($session->error->message)) {
                $error = $session->error->message;
            }
            wp_send_json([
                'errors' => __('Stripe Error: ', 'fluentformpro') . $error
            ], 423);
        }

        $this->setMetaData('stripe_session_id', $session->id);

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Redirect to Stripe', 'fluentformpro'),
            'description'      => __('User redirect to Stripe for completing the payment', 'fluentformpro')
        ];
        do_action('fluentform/log_data', $logData);

        wp_send_json_success([
            'nextAction' => 'payment',
            'actionName' => 'stripeRedirectToCheckout',
            'sessionId'  => $session->id,
            'message'    => __('You are redirecting to stripe.com to complete the purchase. Please wait while you are redirecting....', 'fluentformpro'),
            'result'     => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }

    protected function getPaymentIntentData($transaction, $submission, $form)
    {
        $data = [
            'capture_method'              => 'automatic',
            'description'                 => $form->title,
            'statement_descriptor_suffix' => StripeSettings::getPaymentDescriptor($form),
        ];

        $paymentSettings = PaymentHelper::getFormSettings($form->id, 'admin');
        $intentMeta = $this->getIntentMetaData($submission, $form, $transaction, $paymentSettings);

        $data['metadata'] = $intentMeta;
        return $data;
    }

    public function getFormattedItems($currency)
    {
        $orderItems = $this->getOrderItems();

        $discountItems = $this->getDiscountItems();

        $discountTotal = 0;
        foreach ($discountItems as $item) {
            $discountTotal += $item->line_total;
        }

        $orderTotal = 0;
        foreach ($orderItems as $orderItem) {
            $orderTotal += $orderItem->line_total;
        }

        $formattedItems = [];
        $discountDistributed = 0;
        $itemCount = count($orderItems);

        foreach ($orderItems as $index => $item) {
            $price = $item->item_price;
            $quantity = $item->quantity ?: 1;

            if ($discountTotal && $orderTotal) {
                if ($index === $itemCount - 1) {
                    // Last item absorbs rounding remainder
                    $itemDiscount = (int) round(($discountTotal - $discountDistributed) / $quantity);
                } else {
                    $itemDiscount = (int) round(($discountTotal / $orderTotal) * $price);
                    $discountDistributed += $itemDiscount * $quantity;
                }
                $price = $price - $itemDiscount;
            }

            if (PaymentHelper::isZeroDecimal($currency)) {
                $price = intval($price / 100);
            }

            $stripeLine = [
                'amount'   => $price,
                'currency' => $currency,
                'name'     => $item->item_name,
                'quantity' => $quantity
            ];

            $formattedItems[] = $stripeLine;
        }

        return $formattedItems;
    }

    public function handleSessionRedirectBack($data)
    {
        $type = sanitize_text_field($data['type']);
        $submissionId = intval($data['fluentform_payment']);
        $this->setSubmissionId($submissionId);

        $submission = $this->getSubmission();

        if (!$submission) {
            return;
        }

        if ($type == 'success') {
            if ($this->getMetaData('is_form_action_fired') == 'yes') {
                $returnData = $this->getReturnData();
            } else {
                $sessionId = $this->getMetaData('stripe_session_id');
                $session = CheckoutSession::retrieve($sessionId, [
                    'expand' => [
                        'subscription.latest_invoice.payment_intent',
                        'payment_intent'
                    ]
                ], $submission->form_id);

                if ($session && !is_wp_error($session) && $session->customer) {
                    $transactionHash = sanitize_text_field($data['transaction_hash']);
                    $transaction = $this->getTransaction($transactionHash, 'transaction_hash');
                    $returnData = $this->processStripeSession($session, $submission, $transaction);
                } else {
                    $error = __('Sorry! No Valid payment session found. Please try again', 'fluentformpro');
                    if (is_wp_error($session)) {
                        $error = $session->get_error_message();
                    }
                    $returnData = [
                        'insert_id' => $submission->id,
                        'title'     => __('Failed to retrieve session data', 'fluentformpro'),
                        'result'    => false,
                        'error'     => $error
                    ];
                }
            }
        } else {
            $cancelledMessage = __('Looks like you have cancelled the payment. Please try again!', 'fluentformpro');
            $returnData = [
                'insert_id' => $submission->id,
                'result'    => false,
                'error'     => __('Looks like you have cancelled the payment. Please try again!', 'fluentformpro')
            ];
        }

        $returnData['type'] = $type;

        if (!isset($returnData['is_new'])) {
            $returnData['is_new'] = false;
        }

        $this->showPaymentView($returnData);
    }

    public function processStripeSession($session, $submission, $transaction)
    {
        $this->setSubmissionId($submission->id);

        if ($transaction->status == 'paid' && $submission->payment_status == 'paid') {
            return $this->getReturnData();
        }

        if (PaymentHelper::isReversedPaymentStatus($submission->payment_status)) {
            // Already reversed (refund/partial-refund/cancel): never resurrect to paid or
            // fire the pipeline. Both callers only reach here with the latch unset, and
            // getReturnData() fires the deferred pipeline when it is unset -- so return
            // display data only.
            return [
                'insert_id' => $submission->id,
                'result'    => false,
                'error'     => __('This payment has been reversed.', 'fluentformpro'),
            ];
        }

        $invoice = empty($session->subscription->latest_invoice) ? $session : $session->subscription->latest_invoice;

        $paymentStatus = $this->getIntentSuccessName($invoice->payment_intent);

        if (!$paymentStatus) {
            // Intent did not succeed (declined / requires_action / processing / etc.):
            // do NOT run success processing (status->paid, subscription activation,
            // fulfillment, action firing). Leave the status as-is so a later webhook can
            // still settle it, and return display data only.
            return [
                'insert_id' => $submission->id,
                'result'    => false,
                'error'     => __('Your payment has not been confirmed. This page will update once it is processed.', 'fluentformpro'),
            ];
        }

        $this->changeSubmissionPaymentStatus($paymentStatus);

        if($transaction->transaction_type == 'subscription') {
            $subscriptions = $this->getSubscriptions();

            if(count($subscriptions)) {
                $this->processSubscriptionSuccess($subscriptions, $invoice, $submission);

                $vendorSubscription = $session->subscription;
                if($vendorSubscription) {
                    Plan::maybeSetCancelAt($subscriptions[0], $vendorSubscription);
                }
            }
        }

        $this->processOneTimeSuccess($invoice, $transaction, $paymentStatus);

        $returnData = $this->completePaymentSubmission(false);
        $this->recalculatePaidTotal();
        $returnData['is_new'] = $this->getMetaData('is_form_action_fired') === 'yes';

        return $returnData;
    }

    protected function getIntentSuccessName($intent)
    {
        if (!$intent || !$intent->status) {
            return false;
        }

        $successStatuses = [
            'succeeded'        => 'paid',
            'requires_capture' => 'requires_capture'
        ];

        if (isset($successStatuses[$intent->status])) {
            return $successStatuses[$intent->status];
        }

        return false;
    }

    protected function getDescriptor($title)
    {
        $illegal = array('<', '>', '"', "'");
        // Remove slashes
        $descriptor = stripslashes($title);
        // Remove illegal characters
        $descriptor = str_replace($illegal, '', $descriptor);
        // Trim to 22 characters max
        return substr($descriptor, 0, 22);
    }

    protected function formatAddress($address)
    {
        $addressArray = [
            'line1'       => $address->line1,
            'line2'       => $address->line2,
            'city'        => $address->city,
            'state'       => $address->state,
            'postal_code' => $address->postal_code,
            'country'     => $address->country,
        ];
        return implode(', ', array_filter($addressArray));
    }

    public function handleRefund($event)
    {
        $data = $event->data->object;

        $chargeId = $data->payment_intent;
        // Get the Transaction from database
        $transaction = wpFluent()->table('fluentform_transactions')
            ->where('charge_id', $chargeId)
            ->where('payment_method', 'stripe')
            ->first();

        if (!$transaction) {
            // Not our transaction
            return;
        }

        $submission = wpFluent()->table('fluentform_submissions')
            ->find($transaction->submission_id);

        if (!$submission) {
            return;
        }

        $this->setSubmissionId($submission->id);
        $amountRefunded = $data->amount_refunded;
        if (PaymentHelper::isZeroDecimal($data->currency)) {
            $amountRefunded = $amountRefunded * 100;
        }

        // Remove All Existing Refunds
        wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $submission->id)
            ->where('transaction_type', 'refund')
            ->delete();

        $this->refund($amountRefunded, $transaction, $submission, 'stripe', $chargeId, 'Refund from Stripe');

    }

    public function getPaymentMode()
    {
        $stripeSettings = StripeSettings::getSettings();
        return $stripeSettings['payment_mode'];
    }

    public function processSubscriptionSuccess($subscriptions, $invoice, $submission)
    {
        foreach ($subscriptions as $subscription) {
            $subscriptionStatus = 'active';

            if ($subscription->trial_days) {
                $subscriptionStatus = 'trialling';
            }

            $updateData = [
                'vendor_customer_id' => $invoice->customer,
                'vendor_response'    => maybe_serialize($invoice),
            ];

            if(!$subscription->bill_count && $subscriptionStatus == 'active') {
                $updateData['bill_count'] = 1;
            }

            if (!$subscription->vendor_subscription_id) {
                $updateData['vendor_subscription_id'] = $invoice->subscription;
            }

            $this->updateSubscription($subscription->id, $updateData);
            $subscription = fluentFormApi('submissions')->getSubscription($subscription->id);
            $this->updateSubscriptionStatus($subscription, $subscriptionStatus);
        }

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'success',
            'title'            => __('Stripe Subscription Charged', 'fluentformpro'),
            'description'      => __('Stripe recurring subscription successfully initiated', 'fluentformpro')
        ];

        do_action('fluentform/log_data', $logData);

        if (!empty($invoice->payment_intent->charges->data[0])) {
            $charge = $invoice->payment_intent->charges->data[0];
            $this->recordStripeBillingAddress($charge, $submission);
        }
    }

    protected function recordStripeBillingAddress($charge, $submission)
    {
        if(!is_array($submission->response)) {
            $submission->response = json_decode($submission->response, true);
        }

        if (isset($submission->response['__checkout_billing_address_details'])) {
            return;
        }

        if (empty($charge->billing_details)) {
            return;
        }

        $billingDetails = $charge->billing_details;
        if (!empty($billingDetails->address)) {
            $submission->response['__checkout_billing_address_details'] = $billingDetails->address;
        }
        if (!empty($billingDetails->phone)) {
            $submission->response['__stripe_phone'] = $billingDetails->phone;
        }
        if (!empty($billingDetails->name)) {
            $submission->response['__stripe_name'] = $billingDetails->name;
        }
        if (!empty($billingDetails->email)) {
            $submission->response['__stripe_email'] = $billingDetails->email;
        }

        $this->updateSubmission($submission->id, [
            'response' => json_encode($submission->response)
        ]);

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Stripe Billing Address Logged', 'fluentformpro'),
            'description'      => __('Billing address from stripe has been logged in the submission data', 'fluentformpro')
        ];

        do_action('fluentform/log_data', $logData);
    }

    protected function processOneTimeSuccess($invoice, $transaction, $paymentStatus)
    {
        if ($transaction) {
            $updateData = [
                'charge_id'    => $invoice->payment_intent ? $invoice->payment_intent->id : null,
                'status'       => 'paid',
                'payment_note' => maybe_serialize($invoice->payment_intent)
            ];

            $updateData = array_merge($updateData, $this->retrieveCustomerDetailsFromInvoice($invoice));

            $this->updateTransaction($transaction->id, $updateData);

            $this->changeTransactionStatus($transaction->id, $paymentStatus);
        }
    }

    protected function getIntentMetaData($submission, $form, $transaction, $paymentSettings = false)
    {
        if (!$paymentSettings) {
            $paymentSettings = PaymentHelper::getFormSettings($form->id, 'admin');
        }

        $intentMeta = [
            'submission_id'  => $submission->id,
            'form_id'        => $form->id,
            'transaction_id' => $transaction->id,
            'wp_plugin'      => 'Fluent Forms Pro',
            'form_title'     => $form->title
        ];
    
        $metaItems = ArrayHelper::get($paymentSettings, 'stripe_meta_data', []);
        if ((ArrayHelper::get($paymentSettings, 'push_meta_to_stripe') == 'yes') && !empty($metaItems)) {

            foreach ($metaItems as $metaItem) {
                if ($itemValue = ArrayHelper::get($metaItem, 'item_value')) {
                    $metaData[ArrayHelper::get($metaItem, 'label', 'item')] = $itemValue;
                }
            }

            $metaData = ShortCodeParser::parse($metaData, $submission->id, $submission->response);

            $metaData = array_filter((array) $metaData);

            foreach ($metaData as $itemKey => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $metaData[$itemKey] = wp_strip_all_tags($value);
                }
            }

            $intentMeta = array_merge($intentMeta, $metaData);
        }

        return $intentMeta;
    }

    protected function retrieveCustomerDetailsFromInvoice($invoice)
    {
        $customer = [];

        if (!empty($invoice->payment_intent->charges->data[0])) {
            $charge = $invoice->payment_intent->charges->data[0];

            $customer = $this->retrieveCustomerDetailsFromCharge($charge);

            $customerName = isset($charge->billing_details->name) &&
                            !empty($charge->billing_details->name) ?
                            $charge->billing_details->name : (
                                isset($invoice->customer_name) &&
                                !empty($invoice->customer_name) ?
                                $invoice->customer_name : ''
                            );

            $customer = array_merge($customer, [
                'payer_email' => $invoice->customer_email,
                'payer_name'  => $customerName,
            ]);
        }

        return $customer;
    }

    protected function retrieveCustomerDetailsFromCharge($charge)
    {
        $customer = [];

        if (!empty($charge->billing_details)) {
            $customer['billing_address'] = $this->formatAddress($charge->billing_details->address);
        }

        if (!empty($charge->shipping) && !empty($charge->shipping->address)) {
            $customer['shipping_address'] = $this->formatAddress($charge->shipping->address);
        }

        if (!empty($charge->payment_method_details->card)) {
            $card = $charge->payment_method_details->card;
            $customer['card_brand'] = $card->brand;
            $customer['card_last_4'] = $card->last4;
        }

        return $customer;
    }

    protected function handlePaymentChargeError($message, $submission, $transaction, $charge = false, $type = 'general')
    {
        do_action_deprecated(
            'fluentform_payment_stripe_failed',
            [
                $submission,
                $transaction,
                $this->form->id,
                $charge,
                $type
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_stripe_failed',
            'Use fluentform/payment_stripe_failed instead of fluentform_payment_stripe_failed.'
        );

        do_action('fluentform/payment_stripe_failed', $submission, $transaction, $this->form->id, $charge, $type);

        do_action_deprecated(
            'fluentform_payment_failed',
            [
                $submission,
                $transaction,
                $this->form->id,
                $charge,
                $type
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_failed',
            'Use fluentform/payment_failed instead of fluentform_payment_failed.'
        );
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
                'title'            => __('Stripe Payment Error', 'fluentformpro'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'description'      => __($message, 'fluentformpro')
            ];

            do_action('fluentform/log_data', $logData);
        }

        wp_send_json([
            'errors'      => __('Stripe Error: ', 'fluentformpro') . $message,
            'append_data' => [
                '__entry_intermediate_hash' => Helper::getSubmissionMeta($submission->id, '__entry_intermediate_hash')
            ]
        ], 423);
    }

    public function recordSubscriptionCharge($subscription, $transactionData)
    {
        $this->setSubmissionId($subscription->submission_id);
        return $this->maybeInsertSubscriptionCharge($transactionData);
    }
}
