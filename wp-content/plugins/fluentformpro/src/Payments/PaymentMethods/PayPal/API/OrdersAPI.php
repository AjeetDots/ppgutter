<?php

namespace FluentFormPro\Payments\PaymentMethods\PayPal\API;

if (!defined('ABSPATH')) {
    exit;
}

use FluentFormPro\Payments\PaymentMethods\PayPal\PayPalSettings;

class OrdersAPI
{
    public function getAccessToken($formId = false)
    {
        $keys = PayPalSettings::getApiKeys($formId);
        $mode = PayPalSettings::isLive($formId) ? 'live' : 'test';
        $cacheKey = 'fluentform_paypal_token_' . $mode . '_' . substr(md5($keys['client_id']), 0, 8);

        $cached = get_transient($cacheKey);
        if ($cached) {
            return $cached;
        }

        $baseUrl = $this->getBaseUrl($formId);

        $response = wp_remote_post($baseUrl . '/v1/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($keys['client_id'] . ':' . $keys['secret_key']),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'grant_type=client_credentials',
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            $apiError = isset($body['error_description']) ? $body['error_description'] : '';
            $error = sprintf(
                __('PayPal authentication failed (%s mode). %s', 'fluentformpro'),
                $mode,
                $apiError ?: __('No access token returned.', 'fluentformpro')
            );
            return new \WP_Error('paypal_auth_error', $error);
        }

        $token = sanitize_text_field($body['access_token']);
        $expiresIn = isset($body['expires_in']) ? intval($body['expires_in']) - 60 : 3600;
        set_transient($cacheKey, $token, $expiresIn);

        return $token;
    }

    public function createOrder($orderData, $formId = false)
    {
        return $this->makeApiCall('/v2/checkout/orders', $orderData, $formId, 'POST');
    }

    public function captureOrder($orderId, $formId = false)
    {
        $orderId = preg_replace('/[^A-Za-z0-9]/', '', $orderId);
        return $this->makeApiCall('/v2/checkout/orders/' . $orderId . '/capture', [], $formId, 'POST');
    }

    public function getOrder($orderId, $formId = false)
    {
        $orderId = preg_replace('/[^A-Za-z0-9]/', '', $orderId);
        return $this->makeApiCall('/v2/checkout/orders/' . $orderId, [], $formId, 'GET');
    }

    public function makeApiCall($path, $args, $formId, $method = 'GET', $isRetry = false)
    {
        $token = $this->getAccessToken($formId);
        if (is_wp_error($token)) {
            return $token;
        }

        $baseUrl = $this->getBaseUrl($formId);
        $requestArgs = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($method === 'POST') {
            $requestArgs['body'] = $args ? wp_json_encode($args) : '{}';
            $response = wp_remote_post($baseUrl . $path, $requestArgs);
        } else {
            $response = wp_remote_get($baseUrl . $path, $requestArgs);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = intval(wp_remote_retrieve_response_code($response));

        // 204 No Content is a valid success (e.g., subscription cancel)
        if ($statusCode === 204) {
            return ['status' => 'success'];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Retry once on 401 with fresh token
        if ($statusCode === 401 && !$isRetry) {
            $this->clearTokenCache($formId);
            return $this->makeApiCall($path, $args, $formId, $method, true);
        }

        if ($body === null && $statusCode < 400) {
            return new \WP_Error('paypal_api_error', __('Invalid response from PayPal API.', 'fluentformpro'));
        }

        if ($statusCode >= 400 || !empty($body['details'])) {
            $message = isset($body['message']) ? $body['message'] : __('Unknown PayPal API error', 'fluentformpro');
            if (!empty($body['details'][0]['description'])) {
                $message .= ' - ' . $body['details'][0]['description'];
            }
            return new \WP_Error('paypal_api_error', $message, $body);
        }

        return $body;
    }

    public function clearTokenCache($formId = false)
    {
        $keys = PayPalSettings::getApiKeys($formId);
        $mode = PayPalSettings::isLive($formId) ? 'live' : 'test';
        delete_transient('fluentform_paypal_token_' . $mode . '_' . substr(md5($keys['client_id']), 0, 8));
    }

    private function getBaseUrl($formId = false)
    {
        if (PayPalSettings::isLive($formId)) {
            return 'https://api-m.paypal.com';
        }
        return 'https://api-m.sandbox.paypal.com';
    }
}
