<?php

namespace FluentFormPro\Payments\PaymentMethods\RazorPay;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;

class API
{
    /**
     * @deprecated Dead code — IPN hook (fluentform/ipn_endpoint_razorpay) is never registered.
     * Actual payment confirmation uses RazorPayProcessor::confirmModalPayment() via AJAX.
     * Kept as stub to avoid fatal errors if called externally. Will be removed in a future release.
     */
    public function verifyIPN()
    {
        return;
    }

    public function makeApiCall($path, $args, $formId, $method = 'GET')
    {
        $keys = RazorPaySettings::getApiKeys($formId);
        $headers = [
            'Authorization' => 'Basic '.base64_encode($keys['api_key'].':'.$keys['api_secret']),
            'Content-type' => 'application/json'
        ];
        if($method == 'POST') {
            $response = wp_remote_post('https://api.razorpay.com/v1/'.$path, [
                'headers' => $headers,
                'body' => json_encode($args)
            ]);
        } else {
            $response = wp_remote_get('https://api.razorpay.com/v1/'.$path, [
                'headers' => $headers,
                'body' => $args
            ]);
        }

        if(is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        if(!empty($responseData['error'])) {
            $message = ArrayHelper::get($responseData, 'error.description');
            if(!$message) {
                $message = __('Unknown RazorPay API request error', 'fluentformpro');
            }
            return new \WP_Error(423, $message, $responseData);
        }

        return $responseData;
    }
}
