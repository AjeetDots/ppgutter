<?php

namespace FluentFormPro\Payments\PaymentMethods\PayPal;

if (!defined('ABSPATH')) {
    exit;
}

use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\PayPal\API\SubscriptionsAPI;

class PayPalPaymentManager
{
    public function cancelSubscription($subscription, $scope = 'admin', $submission = false)
    {
        // Legacy or missing vendor ID: cancel locally (can't cancel via REST API)
        if (empty($subscription->vendor_subscription_id) || strpos($subscription->vendor_subscription_id, 'I-') !== 0) {
            PaymentHelper::recordSubscriptionCancelled($subscription, $scope);
            return true;
        }

        if (!$submission) {
            $submission = wpFluent()->table('fluentform_submissions')
                ->where('id', $subscription->submission_id)
                ->first();
        }

        $formId = $submission ? $submission->form_id : false;
        $reason = $scope === 'admin'
            ? __('Cancelled by site admin', 'fluentformpro')
            : __('Cancelled by subscriber', 'fluentformpro');

        $api = new SubscriptionsAPI();
        $response = $api->cancelSubscription(
            $subscription->vendor_subscription_id,
            $reason,
            $formId
        );

        if (is_wp_error($response)) {
            return $response;
        }

        PaymentHelper::recordSubscriptionCancelled($subscription, $scope);

        return true;
    }
}
