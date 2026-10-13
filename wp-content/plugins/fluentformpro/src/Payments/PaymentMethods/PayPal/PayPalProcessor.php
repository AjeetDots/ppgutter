<?php

namespace FluentFormPro\Payments\PaymentMethods\PayPal;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Models\SubmissionMeta;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentMethods\PayPal\API\IPN;
use FluentFormPro\Payments\PaymentMethods\PayPal\API\OrdersAPI;
use FluentFormPro\Payments\PaymentMethods\PayPal\API\SubscriptionsAPI;
use FluentFormPro\Payments\PaymentMethods\PayPal\API\WebhookHandler;

class PayPalProcessor extends BaseProcessor
{
    public $method = 'paypal';

    protected $form;

    protected $customerName = '';

    public function init()
    {
        add_action('fluentform/process_payment_' . $this->method, array($this, 'handlePaymentAction'), 10, 6);
        add_action('fluentform/payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));

        // IPN endpoint: IPN::verifyIPN() validates the notification, then fires
        // fluentform/ipn_paypal_action_{txn_type} hooks which are handled by IPN::init().
        // handleWebAcceptPayment is already called through IPN::updatePaymentStatusFromIPN,
        // so it should not be hooked directly here (see commented-out line below).
        //
        // Routing by request shape — not by the current connection_mode setting.
        // Checkout API webhooks always carry the PayPal Transmission headers and
        // a JSON body, so a v2 event must NEVER fall through to IPN::verifyIPN()
        // (which expects URL-encoded form data). If an admin flips connection_mode
        // back to legacy while v2 webhooks are still in flight — for example for
        // subscriptions created moments earlier — those events would otherwise be
        // silently dropped and the subscription would stay pending forever.
        add_action('fluentform/ipn_endpoint_' . $this->method, function () {
            if (isset($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'])) {
                (new WebhookHandler())->handle();
            } else {
                (new IPN())->verifyIPN();
            }
            exit(200);
        });

        // Removed: was causing handleWebAcceptPayment to run twice (once here, once via IPN::updatePaymentStatusFromIPN)
        // and did not pass $ipnVerified, bypassing verification.
        // add_action('fluentform/ipn_paypal_action_web_accept', array($this, 'handleWebAcceptPayment'), 10, 3);

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
        $paymentTotal = $this->getAmountTotal();

        if (!$paymentTotal && !$hasSubscriptions) {
            return false;
        }

        // Create the initial transaction here
        $transaction = $this->createInitialPendingTransaction($submission, $hasSubscriptions);

        if (PayPalSettings::useOrdersApi($form->id)) {
            if ($hasSubscriptions) {
                $this->handleSubscriptionsApiRedirect($transaction, $submission, $form, $methodSettings);
            } else {
                $this->handleOrdersApiRedirect($transaction, $submission, $form, $methodSettings);
            }
        } else {
            $this->handlePayPalRedirect($transaction, $submission, $form, $methodSettings, $hasSubscriptions);
        }
    }

    public function handlePayPalRedirect($transaction, $submission, $form, $methodSettings, $hasSubscriptions)
    {
        $paymentSettings = PaymentHelper::getPaymentSettings();

        $args = array(
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction ? $transaction->transaction_hash : '',
            'type'               => 'success'
        );

        if (empty($args['transaction_hash'])) {
            $args['entry_uid'] = Helper::getSubmissionMeta($submission->id, '_entry_uid_hash');
        }

        $successUrl = add_query_arg($args, site_url('index.php'));

        $cancelUrl = $submission->source_url;

        if (!wp_http_validate_url($cancelUrl)) {
            $cancelUrl = home_url($cancelUrl);
        }

        $domain = site_url('index.php');

        if(defined('FF_PAYPAL_IPN_DOMAIN') && FF_PAYPAL_IPN_DOMAIN) {
            $domain = FF_PAYPAL_IPN_DOMAIN;
        }

        $listener_url = add_query_arg(array(
            'fluentform_payment_api_notify' => 1,
            'payment_method'                => $this->method,
            'submission_id'                 => $submission->id
        ), $domain); //

        $customArgs =  array(
            'fs_id'  => $submission->id
        );

        if ($transaction) {
            $customArgs['transaction_hash'] = $transaction->transaction_hash;
        } else {
            $customArgs['entry_uid'] = Helper::getSubmissionMeta($submission->id, '_entry_uid_hash');
        }

        $paypal_args = array(
            'cmd'           => '_cart',
            'upload'        => '1',
            'rm'            => is_ssl() ? 2 : 1,
            'business'      => PayPalSettings::getPayPalEmail($form->id),
            'email'         => $transaction->payer_email,
            'no_shipping'   => (ArrayHelper::get($methodSettings, 'settings.require_shipping_address.value') == 'yes') ? '0' : '1',
            'shipping' => (ArrayHelper::get($methodSettings, 'settings.require_shipping_address.value') == 'yes') ? '1' : '0',
            'no_note'       => '1',
            'currency_code' => strtoupper($submission->currency),
            'charset'       => 'UTF-8',
            'custom'        => wp_json_encode($customArgs),
            'return'        => esc_url_raw($successUrl),
            'notify_url'    => $this->limitLength(esc_url_raw($listener_url), 255),
            'cancel_return' => esc_url_raw($cancelUrl)
        );

        if ($businessLogo = ArrayHelper::get($paymentSettings, 'business_logo')) {
            $paypal_args['image_url'] = $businessLogo;
        }

        $paypal_args = wp_parse_args($paypal_args, $this->getCartSummery());

        $paypal_args = apply_filters_deprecated(
            'fluentform_paypal_checkout_args',
            [
                $paypal_args,
                $submission,
                $transaction,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/paypal_checkout_args',
            'Use fluentform/paypal_checkout_args instead of fluentform_paypal_checkout_args.'
        );

        $paypal_args = apply_filters('fluentform/paypal_checkout_args', $paypal_args, $submission, $transaction, $form);

        if ($hasSubscriptions) {
            $this->customerName = PaymentHelper::getCustomerName($submission, $form);
            $paypal_args = $this->processSubscription($paypal_args, $transaction, $hasSubscriptions);
        }

        $redirectUrl = $this->getRedirectUrl($paypal_args, $form->id);

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Redirect to PayPal', 'fluentformpro'),
            'description'      => __('User redirect to paypal for completing the payment', 'fluentformpro')
        ];
        do_action('fluentform/log_data', $logData);

        wp_send_json_success([
            'nextAction'   => 'payment',
            'actionName'   => 'normalRedirect',
            'redirect_url' => $redirectUrl,
            'message'      => __('You are redirecting to PayPal.com to complete the purchase. Please wait while you are redirecting....', 'fluentformpro'),
            'result'       => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }

    public function handleOrdersApiRedirect($transaction, $submission, $form, $methodSettings)
    {
        $paymentSettings = PaymentHelper::getPaymentSettings();

        $successUrl = add_query_arg([
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'success',
        ], site_url('index.php'));

        $cancelUrl = $submission->source_url;
        if (!wp_http_validate_url($cancelUrl)) {
            $cancelUrl = home_url($cancelUrl);
        }

        $amountTotal = $this->getAmountTotal();
        $amountValue = PaymentHelper::floatToString((float) round($amountTotal / 100, 2));
        $currencyCode = strtoupper($submission->currency ?? '');

        $items = [];
        $itemTotal = 0;
        foreach ($this->getOrderItems() as $item) {
            if (!$item->item_price) {
                continue;
            }
            $unitAmount = PaymentHelper::floatToString((float) round($item->item_price / 100, 2));
            $items[] = [
                'name'        => PaymentHelper::limitLength($item->item_name, 127),
                'quantity'    => strval((int) $item->quantity),
                'unit_amount' => [
                    'currency_code' => $currencyCode,
                    'value'         => $unitAmount,
                ],
            ];
            $itemTotal += round($item->item_price / 100, 2) * (int) $item->quantity;
        }

        $discountTotal = 0;
        foreach ($this->getDiscountItems() as $discountItem) {
            $discountTotal += $discountItem->line_total;
        }
        $discountValue = PaymentHelper::floatToString((float) round($discountTotal / 100, 2));
        $itemTotalValue = PaymentHelper::floatToString((float) $itemTotal);

        $breakdown = [
            'item_total' => [
                'currency_code' => $currencyCode,
                'value'         => $itemTotalValue,
            ],
        ];

        if ($discountTotal > 0) {
            $breakdown['discount'] = [
                'currency_code' => $currencyCode,
                'value'         => $discountValue,
            ];
        }

        // Verify breakdown math: item_total - discount must equal amount
        $computedTotal = round($itemTotal - ($discountTotal / 100), 2);
        $expectedTotal = round($amountTotal / 100, 2);
        $breakdownMatches = abs($computedTotal - $expectedTotal) < 0.01;

        $purchaseUnit = [
            'reference_id' => $transaction->transaction_hash,
            'amount'       => [
                'currency_code' => $currencyCode,
                'value'         => $amountValue,
            ],
            'custom_id' => wp_json_encode([
                'fs_id'            => $submission->id,
                'transaction_hash' => $transaction->transaction_hash,
            ]),
        ];

        if ($items && $breakdownMatches) {
            $purchaseUnit['amount']['breakdown'] = $breakdown;
            $purchaseUnit['items'] = $items;
        }

        $experienceContext = [
            'return_url'  => esc_url_raw($successUrl),
            'cancel_url'  => esc_url_raw($cancelUrl),
            'user_action' => 'PAY_NOW',
        ];

        $businessName = ArrayHelper::get($paymentSettings, 'business_name', '');
        if ($businessName) {
            $experienceContext['brand_name'] = $businessName;
        }

        $orderPayload = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [$purchaseUnit],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => $experienceContext,
                ],
            ],
        ];

        $orderPayload = apply_filters('fluentform/paypal_orders_api_args', $orderPayload, $submission, $transaction, $form);

        $response = (new OrdersAPI())->createOrder($orderPayload, $form->id);

        if (is_wp_error($response)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Checkout Error', 'fluentformpro'),
                'description'      => $response->get_error_message(),
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => $response->get_error_message(),
            ], 423);
        }

        $orderId = ArrayHelper::get($response, 'id', '');
        if (!$orderId) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Order ID Missing', 'fluentformpro'),
                'description'      => __('PayPal returned a successful response but no order ID was included.', 'fluentformpro'),
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => __('Could not create PayPal order. Please try again.', 'fluentformpro'),
            ], 423);
        }
        Helper::setSubmissionMeta($submission->id, '_paypal_order_id', sanitize_text_field($orderId));

        // Find the approval URL from the links array
        $approveUrl = '';
        $links = ArrayHelper::get($response, 'links', []);
        foreach ($links as $link) {
            if (ArrayHelper::get($link, 'rel') === 'payer-action' || ArrayHelper::get($link, 'rel') === 'approve') {
                $approveUrl = ArrayHelper::get($link, 'href', '');
                break;
            }
        }

        if (!$approveUrl) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Approval URL Missing', 'fluentformpro'),
                'description'      => __('PayPal returned a successful response but no approval URL was found.', 'fluentformpro'),
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => __('Could not retrieve the PayPal checkout URL. Please try again.', 'fluentformpro'),
            ], 423);
        }

        do_action('fluentform/log_data', [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Redirect to PayPal Checkout', 'fluentformpro'),
            'description'      => __('User redirected to PayPal for completing the payment', 'fluentformpro'),
        ]);

        wp_send_json_success([
            'nextAction'   => 'payment',
            'actionName'   => 'normalRedirect',
            'redirect_url' => $approveUrl,
            'message'      => __('You are redirecting to PayPal to complete the purchase. Please wait while you are redirecting....', 'fluentformpro'),
            'result'       => [
                'insert_id' => $submission->id,
            ],
        ], 200);
    }

    public function handleSubscriptionsApiRedirect($transaction, $submission, $form, $methodSettings)
    {
        $paymentSettings = PaymentHelper::getPaymentSettings();
        $subscriptions = $this->getSubscriptions();
        $validSubscription = null;

        foreach ($subscriptions as $sub) {
            if ($sub->recurring_amount) {
                $validSubscription = $sub;
                break;
            }
        }

        if (!$validSubscription) {
            wp_send_json_error([
                'message' => __('No valid subscription found.', 'fluentformpro'),
            ], 423);
        }

        $api = new SubscriptionsAPI();
        $currency = strtoupper($submission->currency ?? '');

        $planId = $api->getOrCreatePlan($validSubscription, $currency, $form->id);
        if (is_wp_error($planId)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Plan Creation Error', 'fluentformpro'),
                'description'      => $planId->get_error_message(),
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => $planId->get_error_message(),
            ], 423);
        }

        $successUrl = add_query_arg([
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'success',
        ], site_url('index.php'));

        $cancelUrl = $submission->source_url;
        if (!wp_http_validate_url($cancelUrl)) {
            $cancelUrl = home_url($cancelUrl);
        }

        $subscriptionData = [
            'plan_id'    => $planId,
            'custom_id'  => wp_json_encode([
                'fs_id'            => $submission->id,
                'transaction_hash' => $transaction->transaction_hash,
            ]),
            'application_context' => [
                'return_url'  => esc_url_raw($successUrl),
                'cancel_url'  => esc_url_raw($cancelUrl),
                'user_action' => 'SUBSCRIBE_NOW',
            ],
        ];

        // Add setup fee if there's an initial amount beyond the recurring
        $initialAmount = $transaction->payment_total - $validSubscription->recurring_amount;
        if ($initialAmount > 0) {
            $subscriptionData['plan'] = [
                'payment_preferences' => [
                    'setup_fee' => [
                        'currency_code' => $currency,
                        'value'         => PaymentHelper::floatToString((float) round($initialAmount / 100, 2)),
                    ],
                ],
            ];
        }

        $businessName = ArrayHelper::get($paymentSettings, 'business_name', '');
        if ($businessName) {
            $subscriptionData['application_context']['brand_name'] = $businessName;
        }

        $subscriptionData = apply_filters('fluentform/paypal_subscription_api_args', $subscriptionData, $submission, $transaction, $form, $validSubscription);

        $response = $api->createSubscription($subscriptionData, $form->id);

        if (is_wp_error($response)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Subscription Error', 'fluentformpro'),
                'description'      => $response->get_error_message(),
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => $response->get_error_message(),
            ], 423);
        }

        $vendorSubscriptionId = ArrayHelper::get($response, 'id', '');
        if (!$vendorSubscriptionId) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Subscription ID Missing', 'fluentformpro'),
                'description'      => __('PayPal returned a successful response but no subscription ID was included.', 'fluentformpro'),
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => __('Could not create PayPal subscription. Please try again.', 'fluentformpro'),
            ], 423);
        }

        Helper::setSubmissionMeta($submission->id, '_paypal_subscription_id', sanitize_text_field($vendorSubscriptionId));
        wpFluent()->table('fluentform_subscriptions')
            ->where('id', $validSubscription->id)
            ->update([
                'vendor_subscription_id' => sanitize_text_field($vendorSubscriptionId),
                'updated_at'             => current_time('mysql'),
            ]);

        // Find approval URL
        $approveUrl = '';
        foreach (ArrayHelper::get($response, 'links', []) as $link) {
            if (ArrayHelper::get($link, 'rel') === 'approve') {
                $approveUrl = ArrayHelper::get($link, 'href', '');
                break;
            }
        }

        if (!$approveUrl) {
            $this->changeSubmissionPaymentStatus('failed');
            $this->changeTransactionStatus($transaction->id, 'failed');
            wp_send_json_error([
                'message' => __('Could not retrieve the PayPal subscription URL. Please try again.', 'fluentformpro'),
            ], 423);
        }

        do_action('fluentform/log_data', [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Redirect to PayPal Subscription', 'fluentformpro'),
            'description'      => __('User redirected to PayPal for subscription approval', 'fluentformpro'),
        ]);

        wp_send_json_success([
            'nextAction'   => 'payment',
            'actionName'   => 'normalRedirect',
            'redirect_url' => $approveUrl,
            'message'      => __('You are redirecting to PayPal to complete the subscription. Please wait while you are redirecting....', 'fluentformpro'),
            'result'       => [
                'insert_id' => $submission->id,
            ],
        ], 200);
    }

    private function handleSubscriptionApiReturn($submission, $vendorSubscriptionId)
    {
        $transaction = $this->getLastTransaction($submission->id);
        $api = new SubscriptionsAPI();

        $subDetails = $api->getSubscription($vendorSubscriptionId, $submission->form_id);

        if (is_wp_error($subDetails)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Subscription Verification Error', 'fluentformpro'),
                'description'      => $subDetails->get_error_message(),
            ]);

            $returnData = $this->getReturnData();
            $returnData['type'] = 'failed';
            $returnData['is_new'] = false;
            $this->showPaymentView($returnData);
            return;
        }

        $subStatus = ArrayHelper::get($subDetails, 'status', '');
        // APPROVED means the buyer accepted the plan, not that it activated or billed.
        $isActive = in_array($subStatus, ['ACTIVE']);

        if ($isActive) {
            $subscription = wpFluent()->table('fluentform_subscriptions')
                ->where('submission_id', $submission->id)
                ->where('vendor_subscription_id', $vendorSubscriptionId)
                ->first();

            // This API bills a setup_fee alongside a trial cycle, so only a zero-due trial settles.
            $wasPending = $subscription && $subscription->status == 'pending';
            $isTrial = $wasPending && $subscription->trial_days;
            $isZeroDueTrial = $isTrial && !$subscription->initial_amount;

            if ($subscription) {
                wpFluent()->table('fluentform_subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'vendor_response' => maybe_serialize($subDetails),
                        'updated_at'      => current_time('mysql'),
                    ]);

                $subscription = fluentFormApi('submissions')->getSubscription($subscription->id);
                // A reload must not end a trial already running.
                $stillTrialling = $isTrial || 'trialling' === $subscription->status;
                $this->updateSubscriptionStatus($subscription, $stillTrialling ? 'trialling' : 'active');
            }

            if ($transaction) {
                $payerEmail = sanitize_email(ArrayHelper::get($subDetails, 'subscriber.email_address', ''));
                $payerName = trim(
                    ArrayHelper::get($subDetails, 'subscriber.name.given_name', '') . ' ' .
                    ArrayHelper::get($subDetails, 'subscriber.name.surname', '')
                );

                $this->updateTransaction($transaction->id, [
                    'payer_email'  => $payerEmail,
                    'payer_name'   => sanitize_text_field($payerName),
                    'payment_note' => maybe_serialize($subDetails),
                ]);
            }

            if ($isZeroDueTrial) {
                $this->changeSubmissionPaymentStatus('paid');
                if ($transaction) {
                    $this->changeTransactionStatus($transaction->id, 'paid');
                }
                $this->recalculatePaidTotal();
                $returnData = $this->completePaymentSubmission(false);
                $returnData['type'] = 'success';
                $returnData['is_new'] = true;
            } else {
                // Stays pending for the webhook, so this re-runs on reload; log the transition only.
                if ($wasPending) {
                    do_action('fluentform/log_data', [
                        'parent_source_id' => $submission->form_id,
                        'source_type'      => 'submission_item',
                        'source_id'        => $submission->id,
                        'component'        => 'Payment',
                        'status'           => 'info',
                        'title'            => __('PayPal Subscription Awaiting First Payment', 'fluentformpro'),
                        'description'      => __('Subscription is active at PayPal; waiting for the first payment to settle.', 'fluentformpro'),
                    ]);
                }

                $messageTxt = __("Your subscription has been set up. We're waiting for PayPal to confirm your first payment. This may take some time. You can close this page. Please don't submit another payment while confirmation is pending.", 'fluentformpro');
                $messageTxt = apply_filters('fluentform/paypal_subscription_pending_message', $messageTxt, $submission, $this->form);

                $this->addDelayedCheck($submission->id);

                $returnData = [
                    'insert_id' => $submission->id,
                    'title'     => apply_filters('fluentform/paypal_subscription_pending_title', __('Subscription Pending', 'fluentformpro'), $submission),
                    'result'    => false,
                    'error'     => "<div class='ff_paypal_delay_loader_check'>{$messageTxt}</div>",
                    'loader'    => true,
                    'type'      => 'success',
                    'is_new'    => false,
                ];
            }
        } else {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('PayPal Subscription Pending', 'fluentformpro'),
                'description'      => sprintf(
                    __('Subscription status from PayPal: %s. Waiting for activation.', 'fluentformpro'),
                    $subStatus
                ),
            ]);

            if ($subStatus === 'APPROVAL_PENDING') {
                $returnData = [
                    'insert_id' => $submission->id,
                    'title'     => __('Subscription Pending', 'fluentformpro'),
                    'result'    => false,
                    'error'     => __('Your subscription is being processed by PayPal. It may take a few moments to activate.', 'fluentformpro'),
                ];
                $returnData['type'] = 'success';
            } else {
                $returnData = $this->getReturnData();
                $returnData['type'] = 'failed';
            }
            $returnData['is_new'] = false;
        }

        $this->showPaymentView($returnData);
    }

    private function getCartSummery()
    {
        $items = $this->getOrderItems();
        $paypal_args = array();
        if ($items) {
            $counter = 1;
            foreach ($items as $item) {
                if (!$item->item_price) {
                    continue;
                }

                $amount = PaymentHelper::floatToString((float)round($item->item_price / 100, 2));
                $itemName = PaymentHelper::formatPaymentItemString($item->item_name, 127);

                $paypal_args['item_name_' . $counter] = PaymentHelper::limitLength($itemName, 127);
                $paypal_args['quantity_' . $counter] = (int)$item->quantity;
                $paypal_args['amount_' . $counter] = $amount;
                $counter = $counter + 1;
            }
        }

        $discountItems = $this->getDiscountItems();
        if (count($discountItems)) {
            $discountTotal = 0;
            foreach ($discountItems as $discountItem) {
                $discountTotal += $discountItem->line_total;
            }
            $paypal_args['discount_amount_cart'] = round($discountTotal / 100, 2);
        }

        return $paypal_args;
    }

    private function getRedirectUrl($args, $formId = false)
    {
        if ($this->getPaymentMode($formId) == 'test') {
            $paypal_redirect = 'https://www.sandbox.paypal.com/cgi-bin/webscr/?test_ipn=1&';
        } else {
            $paypal_redirect = 'https://www.paypal.com/cgi-bin/webscr/?';
        }

        return $paypal_redirect . http_build_query($args, '', '&');
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

        // Orders API / Subscriptions API flow: handle redirect back
        // Verify transaction_hash matches the active pending transaction
        $transactionHash = sanitize_text_field(ArrayHelper::get($data, 'transaction_hash', ''));
        if ($type == 'success' && $submission->payment_status !== 'paid' && $transactionHash) {
            $transaction = $this->getLastTransaction($submissionId);
            if ($transaction && $transaction->transaction_hash === $transactionHash && $transaction->status === 'pending') {
                $paypalOrderId = Helper::getSubmissionMeta($submissionId, '_paypal_order_id');
                if ($paypalOrderId) {
                    $this->handleOrdersApiCapture($submission, $paypalOrderId);
                    return;
                }

                $paypalSubscriptionId = Helper::getSubmissionMeta($submissionId, '_paypal_subscription_id');
                if ($paypalSubscriptionId) {
                    $this->handleSubscriptionApiReturn($submission, $paypalSubscriptionId);
                    return;
                }
            }
        }

        $isNew = false;

        if ($type == 'success' && $submission->payment_status === 'paid') {
            $isNew = $this->getMetaData('is_form_action_fired') != 'yes';
            $returnData = $this->getReturnData();
        } else if ($type == 'success') {
            $transaction = $this->getLastTransaction($submission->id);
            $messageTxt = __("We're waiting for PayPal to confirm your payment. This may take some time. You can close this page. Please don't submit another payment while confirmation is pending.", 'fluentformpro');
            $messageTxt = apply_filters('fluentform/paypal_payment_processing_message', $messageTxt, $submission, $this->form);
            $message = "<div class='ff_paypal_delay_loader_check'>{$messageTxt}</div>";
            $enableSandboxMode = apply_filters('fluentform/enable-paypal-sandbox-mode', true);
            $loader = true;
            if($transaction && $transaction->payment_mode != 'live' && !$enableSandboxMode) {
                $sandboxMessage = __('Looks like you are using sandbox mode. PayPal does not send instant payment notification while using sandbox mode', 'fluentformpro');
                $message = apply_filters('fluentform/paypal_payment_sandbox_message', $sandboxMessage, $submission, $this->form);
                $loader = false;
            }
            $message = apply_filters_deprecated(
                'fluentform_paypal_pending_message',
                [
                    $message,
                    $submission
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/paypal_pending_message',
                'Use fluentform/paypal_pending_message instead of fluentform_paypal_pending_message.'
            );
            $message = apply_filters('fluentform/paypal_pending_message', $message, $submission);
            $this->addDelayedCheck($submissionId);
            $messageTitle = __('Payment pending', 'fluentformpro');
            $returnData = [
                'insert_id' => $submission->id,
                'title'     => apply_filters('fluentform/paypal_pending_message_title', $messageTitle, $submission),
                'result'    => false,
                'error'     => $message,
                'loader'    => $loader
            ];
        } else {
            $cancelledTitle = __('Payment Cancelled', 'fluentformpro');
            $cancelledMessage = __('Looks like you have cancelled the payment', 'fluentformpro');
            $cancelledTitle = __('Payment Cancelled', 'fluentformpro');
            $cancelledMessage = __('Looks like you have cancelled the payment', 'fluentformpro');
            $returnData = [
                'insert_id' => $submission->id,
                'title'     => apply_filters('fluentform/paypal_payment_cancelled_title', $cancelledTitle, $submission, $this->form),
                'result'    => false,
                'error'     => apply_filters('fluentform/paypal_payment_cancelled_message', $cancelledMessage, $submission, $this->form)
            ];
        }

        $returnData['type'] = $type;
        $returnData['is_new'] = $isNew;

        $this->showPaymentView($returnData);
    }

    private function handleOrdersApiCapture($submission, $paypalOrderId)
    {
        $transaction = $this->getLastTransaction($submission->id);
        $api = new OrdersAPI();

        if (!$transaction) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Capture Error', 'fluentformpro'),
                'description'      => __('No transaction record found for this submission.', 'fluentformpro'),
            ]);
            $this->changeSubmissionPaymentStatus('failed');
            $returnData = $this->getReturnData();
            $returnData['type'] = 'failed';
            $returnData['is_new'] = false;
            $this->showPaymentView($returnData);
            return;
        }

        // Attempt capture directly — handle already-captured in error recovery
        $capture = $api->captureOrder($paypalOrderId, $submission->form_id);

        if (is_wp_error($capture)) {
            $errorData = $capture->get_error_data();
            $isAlreadyCaptured = is_array($errorData)
                && isset($errorData['details'][0]['issue'])
                && $errorData['details'][0]['issue'] === 'ORDER_ALREADY_CAPTURED';

            if ($isAlreadyCaptured) {
                // Check if first request already finished
                $submission = $this->getSubmission();
                if ($submission->payment_status === 'paid') {
                    $returnData = $this->getReturnData();
                    $returnData['type'] = 'success';
                    $returnData['is_new'] = false;
                    $this->showPaymentView($returnData);
                    return;
                }
                // First request still processing — fetch order details to complete locally
                $orderDetails = $api->getOrder($paypalOrderId, $submission->form_id);
                if (!is_wp_error($orderDetails)) {
                    $capture = $orderDetails;
                    // Fall through to normal processing below
                }
            }

            // Still an error after recovery attempt
            if (is_wp_error($capture)) {
                $errorMessage = $capture->get_error_message();
                $issue = is_array($errorData) ? ArrayHelper::get($errorData, 'details.0.issue', '') : '';
                if ($issue === 'ORDER_NOT_APPROVED' || $issue === 'INVALID_RESOURCE_ID') {
                    $errorMessage = __('Your PayPal session has expired. Please submit the form again.', 'fluentformpro');
                }

                do_action('fluentform/log_data', [
                    'parent_source_id' => $submission->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'error',
                    'title'            => __('PayPal Capture Error', 'fluentformpro'),
                    'description'      => $errorMessage,
                ]);

                $this->changeSubmissionPaymentStatus('failed');
                $this->changeTransactionStatus($transaction->id, 'failed');

                $returnData = $this->getReturnData();
                $returnData['type'] = 'failed';
                $returnData['is_new'] = false;
                $this->showPaymentView($returnData);
                return;
            }
        }

        $captureDetails = ArrayHelper::get($capture, 'purchase_units.0.payments.captures.0', []);
        $captureDetailStatus = ArrayHelper::get($captureDetails, 'status', '');
        $capturedAmount = ArrayHelper::get($captureDetails, 'amount.value', '0');
        $capturedCurrency = ArrayHelper::get($captureDetails, 'amount.currency_code', '');
        $captureId = ArrayHelper::get($captureDetails, 'id', '');

        $payer = ArrayHelper::get($capture, 'payer', []);
        $payerEmail = ArrayHelper::get($payer, 'email_address', '');
        $payerName = trim(ArrayHelper::get($payer, 'name.given_name', '') . ' ' . ArrayHelper::get($payer, 'name.surname', ''));

        $status = 'paid';

        // Check capture status — PENDING means money not yet received (eCheck, regulatory hold)
        if ($captureDetailStatus === 'PENDING') {
            $status = 'processing';
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'info',
                'title'            => __('PayPal Payment Pending', 'fluentformpro'),
                'description'      => __('Payment captured but pending clearance (e.g., eCheck or regulatory review).', 'fluentformpro'),
            ]);
        }

        // Verify amount and currency
        $expectedAmount = round($transaction->payment_total / 100, 2);
        $receivedAmount = floatval($capturedAmount);

        if (abs($expectedAmount - $receivedAmount) > 0.01 || strtoupper($transaction->currency ?? '') !== strtoupper($capturedCurrency)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Amount Mismatch', 'fluentformpro'),
                'description'      => sprintf(
                    __('Expected %s %s but received %s %s', 'fluentformpro'),
                    $expectedAmount,
                    $transaction->currency,
                    $capturedAmount,
                    $capturedCurrency
                ),
            ]);
            $status = 'requires_review';
        }

        $updateData = [
            'charge_id'    => sanitize_text_field($captureId),
            'payer_email'  => sanitize_email($payerEmail),
            'payer_name'   => sanitize_text_field($payerName),
            'payment_note' => maybe_serialize($capture),
        ];

        $this->updateTransaction($transaction->id, $updateData);
        $this->changeTransactionStatus($transaction->id, $status);
        $this->changeSubmissionPaymentStatus($status);
        $this->recalculatePaidTotal();

        if ($status === 'paid') {
            $returnData = $this->completePaymentSubmission(false);
            $returnData['type'] = 'success';
        } else {
            $returnData = $this->getReturnData();
            $returnData['type'] = $status === 'processing' ? 'success' : 'failed';
        }

        $returnData['is_new'] = $status === 'paid';
        $this->showPaymentView($returnData);
    }

    public function handleWebAcceptPayment($data, $submissionId, $ipnVerified = false)
    {
        $this->setSubmissionId($submissionId);
        $submission = $this->getSubmission();

        if (!$submission) {
            return;
        }

        // Abort if IPN was not verified — prevents processing forged notifications.
        if (!$ipnVerified) {
            PaymentHelper::log([
                'status'      => 'error',
                'title'       => __('PayPal IPN verification failed. Payment update aborted.', 'fluentformpro'),
                'description' => __('The IPN notification could not be verified with PayPal.', 'fluentformpro')
            ], $submission);
            return;
        }

        $payment_status = strtolower($data['payment_status']);

        if ($payment_status == 'refunded' || $payment_status == 'reversed') {
            // Process a refund
            $this->processRefund($data, $submission);
            return;
        }

        $transaction = $this->getLastTransaction($submissionId);

        if (!$transaction || $transaction->payment_method != $this->method) {
            return;
        }

        if ($data['txn_type'] != 'web_accept' && $data['txn_type'] != 'cart' && $data['payment_status'] != 'Refunded') {
            return;
        }

        // Check if actions are fired
        if ($this->getMetaData('is_form_action_fired') == 'yes') {
            return;
        }

        $business_email = isset($data['business']) && is_email($data['business']) ? trim($data['business']) : trim($data['receiver_email']);

        $this->setMetaData('paypal_receiver_email', $business_email);

        // SECURITY (PRO-02): the PayPal Standard notify_url is attacker-controllable, so a
        // _notify-validate "VERIFIED" IPN only proves PayPal generated the message — not that
        // the money reached THIS site's merchant account. Without this check an attacker could
        // pay their own PayPal account and have the matching-amount IPN mark the order paid.
        // Require the receiver to match the configured merchant email.
        $merchantEmail = PayPalSettings::getPayPalEmail($submission->form_id);
        if ($merchantEmail && 0 !== strcasecmp(trim((string) $merchantEmail), (string) $business_email)) {
            do_action('fluentform/log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('PayPal Receiver Mismatch', 'fluentformpro'),
                'description'      => __('IPN receiver email does not match the configured merchant account. Payment rejected.', 'fluentformpro'),
            ]);
            return;
        }

        if ('completed' == $payment_status || 'pending' == $payment_status) {
            $txnId = sanitize_text_field(ArrayHelper::get($data, 'txn_id', ''));
            $usedElsewhere = wpFluent()->table('fluentform_transactions')
                ->where('payment_method', $this->method)
                ->where('charge_id', $txnId)
                ->where('submission_id', '!=', $submissionId)
                ->exists();

            $rawCustom = ArrayHelper::get($data, 'custom', '');
            $custom = is_string($rawCustom) ? json_decode($rawCustom, true) : null;
            $customId = is_array($custom) ? ArrayHelper::get($custom, 'fs_id', '') : $custom;
            $matchesSubmission = (is_int($customId) || is_string($customId))
                && ctype_digit((string) $customId)
                && ltrim((string) $customId, '0') === (string) $submission->id;
            // Missing custom is safe only for a payment already bound to this transaction.
            if ($rawCustom === '' && $txnId !== '' && $transaction->charge_id === $txnId) {
                $matchesSubmission = true;
            }

            if ($txnId === '' || $usedElsewhere || !$matchesSubmission) {
                // Claim against the live status; the change* helpers update by id alone.
                $heldTransaction = wpFluent()->table('fluentform_transactions')
                    ->where('id', $transaction->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->update([
                        'status'     => 'requires_review',
                        'updated_at' => current_time('mysql'),
                    ]);
                if ($heldTransaction) {
                    $this->changeTransactionStatus($transaction->id, 'requires_review');
                }

                $heldSubmission = wpFluent()->table('fluentform_submissions')
                    ->where('id', $submission->id)
                    ->whereIn('payment_status', ['pending', 'processing'])
                    ->update([
                        'payment_status' => 'requires_review',
                        'updated_at'     => current_time('mysql'),
                    ]);
                if ($heldSubmission) {
                    $this->changeSubmissionPaymentStatus('requires_review');
                }
                PaymentHelper::log([
                    'status'      => 'error',
                    'title'       => __('PayPal payment requires review', 'fluentformpro'),
                    'description' => sprintf(
                        // translators: %s is the transaction id from the PayPal notification
                        __('The payment reference is missing, does not match this submission, or is already used by another submission. Verify PayPal transaction %s before fulfilling this entry.', 'fluentformpro'),
                        '' !== $txnId ? $txnId : __('(none provided)', 'fluentformpro')
                    ),
                ], $submission, true);
                return;
            }

            $status = 'paid';

            if ($payment_status == 'pending') {
                $status = 'processing';
            }

            // Verify payment amount matches expected amount
            if (isset($data['mc_gross']) && $transaction->payment_total) {
                $paidAmountInCents = intval(round(floatval($data['mc_gross']) * 100));
                if ($paidAmountInCents !== intval($transaction->payment_total)) {
                    $status = 'requires_review';
                    do_action('fluentform/log_data', [
                        'parent_source_id' => $submission->form_id,
                        'source_type'      => 'submission_item',
                        'source_id'        => $submission->id,
                        'component'        => 'Payment',
                        'status'           => 'error',
                        'title'            => __('PayPal Amount Mismatch', 'fluentformpro'),
                        'description'      => sprintf(
                            // translators: %1$d is the expected amount in cents, %2$d is the PayPal reported amount in cents
                            __('Expected %1$d cents but PayPal reported %2$d cents. Payment marked for review.', 'fluentformpro'),
                            intval($transaction->payment_total),
                            $paidAmountInCents
                        )
                    ]);
                }
            }

            // Verify the paid currency matches the order currency
            if (isset($data['mc_currency']) && $transaction->currency
                && 0 !== strcasecmp((string) $transaction->currency, (string) $data['mc_currency'])) {
                $status = 'requires_review';
                do_action('fluentform/log_data', [
                    'parent_source_id' => $submission->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'error',
                    'title'            => __('PayPal Currency Mismatch', 'fluentformpro'),
                    'description'      => sprintf(
                        // translators: %1$s is the expected currency, %2$s is the PayPal reported currency
                        __('Expected %1$s but PayPal reported %2$s. Payment marked for review.', 'fluentformpro'),
                        $transaction->currency,
                        $data['mc_currency']
                    )
                ]);
            }

            // Let's make the payment as paid
            $updateData = [
                'payment_note'     => maybe_serialize($data),
                'charge_id'        => sanitize_text_field($data['txn_id']),
                'payer_email'      => sanitize_text_field($data['payer_email']),
                'payer_name'       => ArrayHelper::get($data, 'first_name') . ' ' . ArrayHelper::get($data, 'last_name'),
                'shipping_address' => $this->getAddress($data)
            ];

            $this->updateTransaction($transaction->id, $updateData);
            $this->changeSubmissionPaymentStatus($status);
            $this->changeTransactionStatus($transaction->id, $status);
            $this->recalculatePaidTotal();

            // Fire actions only for a settled payment. processing/requires_review record
            // the order and skip the latch, so the later Completed IPN fulfils once.
            if (PaymentHelper::isFulfillablePaymentStatus($status)) {
                $returnData = $this->completePaymentSubmission(false);
                $this->setMetaData('is_form_action_fired', 'yes');
            }

            if (isset($data['pending_reason'])) {
                $logData = [
                    'parent_source_id' => $submission->form_id,
                    'source_type'      => 'submission_item',
                    'source_id'        => $submission->id,
                    'component'        => 'Payment',
                    'status'           => 'info',
                    'title'            => __('PayPal Payment Pending', 'fluentformpro'),
                    'description'      => $this->getPendingReason($data)
                ];


                // Log Processing Reason
                do_action('fluentform/log_data', $logData);
            }
        }
    }

    private function processRefund($data, $submission)
    {
        if ($submission->payment_status == 'refunded') {
            return;
        }

        if ($submission->payment_status == 'refunded') {
            return;
        }

        // check if already refunded
        $refundExist = $this->getTransactionByChargeId($data['txn_id']);

        if ($refundExist) {
            return;
        }

        $transaction = $this->getTransactionByChargeId($data['parent_txn_id']);

        if (!$transaction) {
            return;
        }

        $refund_amount = $data['mc_gross'] * -100;

        $this->refund($refund_amount, $transaction, $submission, 'paypal', $data['txn_id'], 'Refund From PayPal');

    }

    private function getAddress($data)
    {
        $address = array();
        if (!empty($data['address_street'])) {
            $address['address_line1'] = sanitize_text_field($data['address_street']);
        }
        if (!empty($data['address_city'])) {
            $address['address_city'] = sanitize_text_field($data['address_city']);
        }
        if (!empty($data['address_state'])) {
            $address['address_state'] = sanitize_text_field($data['address_state']);
        }
        if (!empty($data['address_zip'])) {
            $address['address_zip'] = sanitize_text_field($data['address_zip']);
        }
        if (!empty($data['address_state'])) {
            $address['address_country'] = sanitize_text_field($data['address_country_code']);
        }
        return implode(', ', $address);
    }

    public function getPaymentMode($formId = false)
    {
        $isLive = PayPalSettings::isLive($formId);
        if ($isLive) {
            return 'live';
        }
        return 'test';
    }

    private function getPendingReason($data)
    {
        $note = 'Payment marked as pending';
        switch (strtolower($data['pending_reason'])) {
            case 'echeck' :
                $note = __('Payment made via eCheck and will clear automatically in 5-8 days', 'fluentformpro');
                break;
            case 'address' :
                $note = __('Payment requires a confirmed customer address and must be accepted manually through PayPal', 'fluentformpro');
                break;
            case 'intl' :
                $note = __('Payment must be accepted manually through PayPal due to international account regulations', 'fluentformpro');
                break;
            case 'multi-currency' :
                $note = __('Payment received in non-shop currency and must be accepted manually through PayPal', 'fluentformpro');
                break;
            case 'paymentreview' :
            case 'regulatory_review' :
                $note = __('Payment is being reviewed by PayPal staff as high-risk or in possible violation of government regulations', 'fluentformpro');
                break;
            case 'unilateral' :
                $note = __('Payment was sent to non-confirmed or non-registered email address.', 'fluentformpro');
                break;
            case 'upgrade' :
                $note = __('PayPal account must be upgraded before this payment can be accepted', 'fluentformpro');
                break;

            case 'verify' :
                $note = __('PayPal account is not verified. Verify account in order to accept this payment', 'fluentformpro');
                break;
            case 'other' :
                $note = __('Payment is pending for unknown reasons. Contact PayPal support for assistance', 'fluentformpro');
                break;
        }
        return $note;
    }

    public function validateSubmittedItems($errors, $paymentItems, $subscriptionItems, $form)
    {
        $singleItemTotal = 0;

        foreach ($paymentItems as $paymentItem) {
            if ($paymentItem['line_total']) {
                $singleItemTotal += $paymentItem['line_total'];
            }
        }

        $validSubscriptions = [];

        foreach ($subscriptionItems as $subscriptionItem) {
            if ($subscriptionItem['recurring_amount']) {
                $validSubscriptions[] = $subscriptionItem;
            }
        }

        if ($singleItemTotal && count($validSubscriptions)) {
            $errors[] = __('PayPal Error: PayPal does not support subscriptions payment and single amount payment at one request', 'fluentformpro');
        }

        if (count($validSubscriptions) > 2) {
           $errors[] = __('PayPal Error: PayPal does not support multiple subscriptions at one request', 'fluentformpro');
        }

        return $errors;
    }

    public function processSubscription($originalArgs, $transaction, $hasSubscriptions)
    {
        $paymentSettings = PaymentHelper::getPaymentSettings();

        if (!$hasSubscriptions || $transaction->transaction_type != 'subscription') {
            return $originalArgs;
        }

        $subscriptions = $this->getSubscriptions();
        $validSubscriptions = [];

        foreach ($subscriptions as $subscriptionItem) {
            if ($subscriptionItem->recurring_amount) {
                $validSubscriptions[] = $subscriptionItem;
            }
        }

        if (!$validSubscriptions || count($validSubscriptions) > 1) {
            // PayPal Standard does not support more than 1 subscriptions
            // We may add paypal express later for this on.
            return $originalArgs;
        }

        // We just need the first subscriptipn
        $subscription = $validSubscriptions[0];

        if (!$subscription->recurring_amount) {
            return $originalArgs;
        }

        // Setup PayPal arguments
        $paypal_args = array(
            'business'      => $originalArgs['business'],
            'email'         => $originalArgs['email'],
            'invoice'       => $transaction->transaction_hash,
            'no_shipping'   => '1',
            'shipping'      => '0',
            'no_note'       => '1',
            'currency_code' => strtoupper($originalArgs['currency_code']),
            'charset'       => 'UTF-8',
            'custom'        => $originalArgs['custom'],
            'rm'            => '2',
            'return'        => $originalArgs['return'],
            'cancel_return' => $originalArgs['cancel_return'],
            'notify_url'    => $originalArgs['notify_url'],
            'cbt'           => $paymentSettings['business_name'],
            'bn'            => 'FluentFormPro_SP',
            'sra'           => '1',
            'src'           => '1',
            'cmd'           => '_xclick-subscriptions'
        );

        $names = explode(' ', $transaction->payer_name, 2);
        if (count($names) == 2) {
            $firstName = $names[0];
            $lastName = $names[1];
        } else {
            $firstName = $transaction->payer_name;
            $lastName = '';
        }

        if($firstName) {
            $paypal_args['first_name'] = $firstName;
        }

        if($lastName) {
            $paypal_args['last_name'] = $lastName;
        }

        $recurring_amount = $subscription->recurring_amount;
        $initial_amount = $transaction->payment_total - $recurring_amount;

        $recurring_amount = round($recurring_amount / 100, 2);
        $initial_amount = round($initial_amount / 100, 2);

        // A trial must take precedence over the derived initial amount.
        if ($subscription->trial_days) {
            $paypal_args['a1'] = 0;
            $paypal_args['p1'] = $subscription->trial_days;
            $paypal_args['t1'] = 'D';
        } elseif ($initial_amount) {
            $paypal_args['a1'] = round($initial_amount + $recurring_amount, 2);
            $paypal_args['p1'] = 1;
        }

        $paypal_args['a3'] = $recurring_amount;

        $paypal_args['item_name'] = $subscription->item_name . ' - ' . $subscription->plan_name;

        $paypal_args['p3'] = 1; // for now it's 1 as 1 times per period

        switch ($subscription->billing_interval) {
            case 'day':
                $paypal_args['t3'] = 'D';
                break;
            case 'week':
                $paypal_args['t3'] = 'W';
                break;
            case 'month':
                $paypal_args['t3'] = 'M';
                break;
            case 'year':
                $paypal_args['t3'] = 'Y';
                break;
        }

        if (!$subscription->trial_days && $initial_amount) {
            $paypal_args['t1'] = $paypal_args['t3'];
        }

        if ($subscription->bill_times > 1) {
            if (!$subscription->trial_days && $initial_amount) {
                $subscription->bill_times = $subscription->bill_times - 1;
            }

            $billTimes = $subscription->bill_times <= 52 ? absint($subscription->bill_times) : 52;
            $paypal_args['srt'] = $billTimes;
        }

        foreach ($paypal_args as $argName => $argValue) {
            if($argValue === '') {
                unset($paypal_args[$argName]);
            }
        }

        return $paypal_args;

    }

    public function addDelayedCheck($submissionId)
    {
        wp_enqueue_script('ff_paypal', FLUENTFORMPRO_DIR_URL.'public/js/ff_paypal.js', ['jquery'], FLUENTFORM_VERSION, true);
        $checkToken = wp_generate_password(32, false);
        Helper::setSubmissionMeta($submissionId, '_ff_payment_check_token', $checkToken);
        $delayedCheckVars = [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'submission_id'      => $submissionId,
            '_ff_payment_token'  => $checkToken,
            'timeout'            => 10000,
            'onFailedMessage'    => __("Sorry! We couldn't mark your payment as paid. Please try again later!",
                'fluentformpro')
        ];
        wp_localize_script('ff_paypal', 'ff_paypal_vars',apply_filters('fluentform/paypal_delayed_check_vars',  $delayedCheckVars));
    }

    /**
     * Check if paypal payment is marked paid
     * @return json response
     */
    public function isPaid()
    {
        $submissionId = intval($_REQUEST['submission_id']);

        $token = isset($_REQUEST['_ff_payment_token']) ? sanitize_text_field($_REQUEST['_ff_payment_token']) : '';
        $storedToken = Helper::getSubmissionMeta($submissionId, '_ff_payment_check_token');
        if (!$token || !$storedToken || !hash_equals($storedToken, $token)) {
            wp_send_json([
                'message' => __('Security verification failed.', 'fluentformpro'),
            ], 403);
        }

        $this->setSubmissionId($submissionId);

        $submission = $this->getSubmission();

        if (!$submission ) {
            wp_send_json([
                'message' => __('Invalid Payment Transaction', 'fluentformpro'),
            ]);
        }

        if ($submission->payment_status == 'paid') {
            SubmissionMeta::where('response_id', $submissionId)
                ->where('meta_key', '_ff_payment_check_token')
                ->delete();
            wp_send_json_success([
                'nextAction'     => 'reload',
            ]);
        } else {
            wp_send_json_success([
                'nextAction' => 'reCheck',
                'payment_status' => $submission->payment_status
            ]);
        }
    }
}
