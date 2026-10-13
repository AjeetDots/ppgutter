<?php

namespace FluentFormPro\Payments\PaymentMethods\Paddle;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\Paddle\PaddleSettings;

class API
{
    public function makeApiCall($path, $args, $formId, $method = 'GET')
    {
        $keys = trim(PaddleSettings::getApiKey());

        if (!$keys) {
            return new \WP_Error('missing_key', __('Paddle API key is not configured. Please check your Paddle payment settings.', 'fluentformpro'));
        }

        $headers = [
            'Authorization' => 'Bearer ' . $keys,
            'Accept'        => 'application/json',
            'Content-type'  => 'application/json'
        ];

        $baseUrl = 'https://sandbox-api.paddle.com/';
        if (PaddleSettings::isLive()) {
            $baseUrl = 'https://api.paddle.com/';
        }

        if ($method == 'POST') {
            $response = wp_remote_post($baseUrl . $path, [
                'headers' => $headers,
                'body'    => json_encode($args)
            ]);
        } else {
            $response = wp_remote_get($baseUrl . $path, [
                'headers' => $headers
            ]);
        }
        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseCode = wp_remote_retrieve_response_code($response);
        $responseData = json_decode($body, true);

        if (isset($responseData['error'])) {
            $message = ArrayHelper::get($responseData, 'error.detail', '');
            if (!$message) {
                $message = __('Unknown Paddle API request error', 'fluentformpro');
            }
            return new \WP_Error($responseCode, $message, $responseData);
        }

        return $responseData;
    }
}
