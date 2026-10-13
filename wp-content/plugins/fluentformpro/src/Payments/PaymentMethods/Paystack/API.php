<?php

namespace FluentFormPro\Payments\PaymentMethods\Paystack;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;

class API
{
    /**
     * @deprecated Dead code — IPN hook (fluentform/ipn_endpoint_paystack) is never registered.
     * Actual payment confirmation uses PaystackProcessor::confirmModalPayment() via AJAX.
     * Kept as stub to avoid fatal errors if called externally. Will be removed in a future release.
     */
    public function verifyIPN()
    {
        return;
    }

    public function makeApiCall($path, $args, $formId, $method = 'GET')
    {
        $keys = PaystackSettings::getApiKeys($formId);

        $headers = [
            'Authorization' => 'Bearer ' . $keys['api_secret'],
            'Content-type'  => 'application/json'
        ];

        if ($method == 'POST') {
            $response = wp_remote_post('https://api.paystack.co/' . $path, [
                'headers' => $headers,
                'body'    => json_encode($args)
            ]);
        } else {
            $response = wp_remote_get('https://api.paystack.co/' . $path, [
                'headers' => $headers,
                'body'    => $args
            ]);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        if(!$responseData) {
            return new \WP_Error(423, 'Unknown Paystack API request error', $responseData);
        }

        if (!empty($responseData['error'])) {
            $message = ArrayHelper::get($responseData, 'error.description');
            if (!$message) {
                $message = __('Unknown Paystack API request error', 'fluentformpro');
            }
            return new \WP_Error(423, $message, $responseData);
        } else if (empty($responseData['status']) && !$responseData['status']) {
            // this is an error
            $message = __('Paystack API Error', 'fluentformpro');
            if (!empty($responseData['message'])) {
                $message = $responseData['message'];
            }

            return new \WP_Error(423, $message, $responseData);
        }

        return $responseData;
    }
}
