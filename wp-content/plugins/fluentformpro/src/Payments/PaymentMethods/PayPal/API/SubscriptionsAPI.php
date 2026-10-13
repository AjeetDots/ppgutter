<?php

namespace FluentFormPro\Payments\PaymentMethods\PayPal\API;

if (!defined('ABSPATH')) {
    exit;
}

class SubscriptionsAPI
{
    /** @var OrdersAPI */
    private $api;

    public function __construct()
    {
        $this->api = new OrdersAPI();
    }

    public function createProduct($productData, $formId = false)
    {
        return $this->api->makeApiCall('/v1/catalogs/products', $productData, $formId, 'POST');
    }

    public function createPlan($planData, $formId = false)
    {
        return $this->api->makeApiCall('/v1/billing/plans', $planData, $formId, 'POST');
    }

    public function getPlan($planId, $formId = false)
    {
        $planId = preg_replace('/[^A-Za-z0-9\-]/', '', $planId);
        return $this->api->makeApiCall('/v1/billing/plans/' . $planId, [], $formId, 'GET');
    }

    public function createSubscription($subscriptionData, $formId = false)
    {
        return $this->api->makeApiCall('/v1/billing/subscriptions', $subscriptionData, $formId, 'POST');
    }

    public function getSubscription($subscriptionId, $formId = false)
    {
        $subscriptionId = preg_replace('/[^A-Za-z0-9\-]/', '', $subscriptionId);
        return $this->api->makeApiCall('/v1/billing/subscriptions/' . $subscriptionId, [], $formId, 'GET');
    }

    public function cancelSubscription($subscriptionId, $reason, $formId = false)
    {
        $subscriptionId = preg_replace('/[^A-Za-z0-9\-]/', '', $subscriptionId);
        return $this->api->makeApiCall(
            '/v1/billing/subscriptions/' . $subscriptionId . '/cancel',
            ['reason' => $reason],
            $formId,
            'POST'
        );
    }

    public function verifyWebhookSignature($verifyData, $formId = false)
    {
        return $this->api->makeApiCall('/v1/notifications/verify-webhook-signature', $verifyData, $formId, 'POST');
    }

    // A product or plan id only exists inside the account that created it, so the cache is
    // keyed by credentials the way OrdersAPI keys its token. Rotating keys misses, not breaks.
    private static function accountScope($formId = false)
    {
        $keys = \FluentFormPro\Payments\PaymentMethods\PayPal\PayPalSettings::getApiKeys($formId);
        return substr(md5((string) $keys['client_id']), 0, 8);
    }

    // Ids cached before the key carried an account are adopted once, so an upgrade does not
    // abandon a working product or plan and recreate it at PayPal.
    private static function adoptLegacyId($legacyKey, $optionKey)
    {
        $legacyId = get_option($legacyKey, '');
        if (!$legacyId) {
            return '';
        }
        update_option($optionKey, $legacyId, false);
        delete_option($legacyKey);

        return $legacyId;
    }

    /**
     * Get or create a PayPal product for the current mode.
     */
    public function getOrCreateProduct($formId = false)
    {
        $mode = \FluentFormPro\Payments\PaymentMethods\PayPal\PayPalSettings::isLive($formId) ? 'live' : 'test';
        $optionKey = 'fluentform_paypal_product_id_' . $mode . '_' . self::accountScope($formId);

        $productId = get_option($optionKey, '');
        if (!$productId) {
            $productId = self::adoptLegacyId('fluentform_paypal_product_id_' . $mode, $optionKey);
        }
        if ($productId) {
            return $productId;
        }

        $siteName = get_bloginfo('name') ?: 'Fluent Forms';
        $response = $this->createProduct([
            'name'        => $siteName . ' Subscription',
            'type'        => 'SERVICE',
            'category'    => 'SOFTWARE',
            'description' => 'Subscription payments via ' . $siteName,
        ], $formId);

        if (is_wp_error($response)) {
            return $response;
        }

        $productId = isset($response['id']) ? sanitize_text_field($response['id']) : '';
        if (!$productId) {
            return new \WP_Error('paypal_product_error', __('Failed to create PayPal product.', 'fluentformpro'));
        }

        update_option($optionKey, $productId, false);
        return $productId;
    }

    /**
     * Get or create a PayPal billing plan based on subscription parameters.
     */
    public function getOrCreatePlan($subscription, $currency, $formId = false)
    {
        $mode = \FluentFormPro\Payments\PaymentMethods\PayPal\PayPalSettings::isLive($formId) ? 'live' : 'test';
        $recurringAmount = round($subscription->recurring_amount / 100, 2);
        $interval = $subscription->billing_interval;
        $billTimes = intval($subscription->bill_times);
        $trialDays = intval($subscription->trial_days);

        // Plan name is composed below from item_name + plan_name; include both
        // in the cache key so distinct named plans don't collide on identical
        // money/interval and inherit the first-saved name forever.
        $itemName = (string) ($subscription->item_name ?: '');
        $planName = (string) ($subscription->plan_name ?: '');

        $planHash = md5(implode('_', [
            $recurringAmount, $currency, $interval, $billTimes, $trialDays,
            $itemName, $planName,
        ]));
        $optionKey = 'fluentform_paypal_plan_' . $mode . '_' . self::accountScope($formId) . '_' . $planHash;

        $planId = get_option($optionKey, '');
        if (!$planId) {
            $planId = self::adoptLegacyId('fluentform_paypal_plan_' . $mode . '_' . $planHash, $optionKey);
        }
        if ($planId) {
            return $planId;
        }

        $productId = $this->getOrCreateProduct($formId);
        if (is_wp_error($productId)) {
            return $productId;
        }

        $intervalMap = [
            'day'   => 'DAY',
            'week'  => 'WEEK',
            'month' => 'MONTH',
            'year'  => 'YEAR',
        ];
        $intervalUnit = isset($intervalMap[$interval]) ? $intervalMap[$interval] : 'MONTH';

        $billingCycles = [];
        $sequence = 1;

        if ($trialDays > 0) {
            $billingCycles[] = [
                'tenure_type'    => 'TRIAL',
                'sequence'       => $sequence,
                'total_cycles'   => 1,
                'frequency'      => [
                    'interval_unit'  => 'DAY',
                    'interval_count' => $trialDays,
                ],
                'pricing_scheme' => [
                    'fixed_price' => [
                        'currency_code' => strtoupper($currency),
                        'value'         => '0',
                    ],
                ],
            ];
            $sequence++;
        }

        $billingCycles[] = [
            'tenure_type'    => 'REGULAR',
            'sequence'       => $sequence,
            'total_cycles'   => $billTimes > 0 ? $billTimes : 0,
            'frequency'      => [
                'interval_unit'  => $intervalUnit,
                'interval_count' => 1,
            ],
            'pricing_scheme' => [
                'fixed_price' => [
                    'currency_code' => strtoupper($currency),
                    'value'         => \FluentFormPro\Payments\PaymentHelper::floatToString((float) $recurringAmount),
                ],
            ],
        ];

        $planData = [
            'product_id'          => $productId,
            'name'                => ($subscription->item_name ?: 'Subscription') . ' - ' . ($subscription->plan_name ?: $interval),
            'billing_cycles'      => $billingCycles,
            'payment_preferences' => [
                'auto_bill_outstanding'     => true,
                'payment_failure_threshold' => 3,
            ],
        ];

        $response = $this->createPlan($planData, $formId);

        if (is_wp_error($response)) {
            return $response;
        }

        $planId = isset($response['id']) ? sanitize_text_field($response['id']) : '';
        if (!$planId) {
            return new \WP_Error('paypal_plan_error', __('Failed to create PayPal billing plan.', 'fluentformpro'));
        }

        update_option($optionKey, $planId, false);
        return $planId;
    }
}
