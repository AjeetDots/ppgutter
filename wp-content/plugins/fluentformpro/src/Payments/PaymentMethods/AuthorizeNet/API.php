<?php

namespace FluentFormPro\Payments\PaymentMethods\AuthorizeNet;

use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    protected $apiLoginId;
    protected $transactionKey;
    protected $endpoint;
    protected $formId;

    public function __construct($formId = false)
    {
        $this->formId = $formId;
        $credentials = AuthorizeNetSettings::getApiCredentials($formId);
        $this->apiLoginId = $credentials['api_login_id'];
        $this->transactionKey = $credentials['transaction_key'];
        $this->endpoint = AuthorizeNetSettings::getApiEndpoint($formId);
    }

    public function getTransactionDetails($transactionId)
    {
        $requestData = [
            'getTransactionDetailsRequest' => [
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey
                ],
                'transId'                => $transactionId
            ]
        ];

        return $this->makeApiCall($requestData);
    }

    protected function makeApiCall($requestData)
    {
        // Convert to JSON format with proper formatting for Authorize.Net
        // Use JSON_PRESERVE_ZERO_FRACTION to maintain proper number formatting
        $jsonRequest = json_encode($requestData, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);

        $response = wp_remote_post($this->endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'body'    => $jsonRequest,
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);

        // Remove BOM if present
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        $responseData = json_decode($body, true);

        if (!$responseData) {
            return new \WP_Error(422, __('Invalid response from Authorize.net API', 'fluentformpro') . ': ' . $body);
        }

        if (isset($responseData['messages'])) {
            $resultCode = ArrayHelper::get($responseData, 'messages.resultCode');
            if ($resultCode === 'Error') {
                $errorText = '';
                $messages = ArrayHelper::get($responseData, 'messages.message', []);
                if (is_array($messages)) {
                    foreach ($messages as $message) {
                        $errorText .= ArrayHelper::get($message, 'text', '') . ' (Code: ' . ArrayHelper::get($message,
                                'code', '') . ') ';
                    }
                }

                if (empty($errorText)) {
                    $errorText = 'Unknown API error';
                }

                return new \WP_Error(422, $errorText, $responseData);
            }
        }

        return $responseData;
    }

    public function validateCredentials()
    {
        // Use the getMerchantDetailsRequest API call to validate credentials
        // Elements must be ordered according to XML schema
        $requestData = [
            'getMerchantDetailsRequest' => [
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey
                ]
            ]
        ];

        $response = $this->makeApiCall($requestData);

        if (is_wp_error($response)) {
            return false;
        }

        // Check if authentication was successful
        $resultCode = ArrayHelper::get($response, 'messages.resultCode');
        $isValid = ($resultCode === 'Ok');

        return $isValid;
    }

    public static function formatAmount($amount)
    {
        return number_format($amount / 100, 2, '.', '');
    }

    public function getPublicClientKey()
    {
        // Get merchant details to obtain the public client key
        $requestData = [
            'getMerchantDetailsRequest' => [
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey
                ]
            ]
        ];

        $response = $this->makeApiCall($requestData);

        if (is_wp_error($response)) {
            return '';
        }

        // Check if the response is successful
        $resultCode = ArrayHelper::get($response, 'messages.resultCode');
        if ($resultCode !== 'Ok') {
            return '';
        }

        // Extract the public client key from merchant details
        return ArrayHelper::get($response, 'publicClientKey', '');
    }

    public function createHostedPaymentForm($formData)
    {
        // For Accept.js approach, return the necessary data for client-side tokenization
        return [
            'use_accept_js'     => true,
            'public_client_key' => $this->getPublicClientKey(),
            'api_login_id'      => $this->apiLoginId,
            'form_data'         => $formData,
            'is_live'           => $this->isLive()
        ];
    }

    public function createTransaction($transactionData)
    {
        // Validate credentials before making API call
        if (empty($this->apiLoginId) || empty($this->transactionKey)) {
            return new \WP_Error(422,
                __('Authorize.Net API credentials are not configured properly. Please check your settings.',
                    'fluentformpro'));
        }

        // Prepare the transaction request for Accept.js opaque data
        $requestData = [
            'createTransactionRequest' => [
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey
                ],
                'refId'                  => ArrayHelper::get($transactionData, 'refId'),
                'transactionRequest'     => [
                    'transactionType' => ArrayHelper::get($transactionData, 'transactionType'),
                    'amount'          => ArrayHelper::get($transactionData, 'amount'),
                    'payment'         => ArrayHelper::get($transactionData, 'payment'),
                    'order'           => ArrayHelper::get($transactionData, 'order'),
                    'customer'        => ArrayHelper::get($transactionData, 'customer'),
                    'billTo'          => ArrayHelper::get($transactionData, 'billTo')
                ]
            ]
        ];

        $response = $this->makeApiCall($requestData);

        if (is_wp_error($response)) {
            return $response;
        }

        // Check for errors in the response
        $resultCode = ArrayHelper::get($response, 'messages.resultCode');
        if ($resultCode !== 'Ok') {
            $errorMessage = '';
            $messages = ArrayHelper::get($response, 'messages.message', []);
            if (is_array($messages)) {
                foreach ($messages as $message) {
                    $errorMessage .= ArrayHelper::get($message, 'text', '') . ' (Code: ' . ArrayHelper::get($message,
                            'code', '') . ') ';
                }
            }

            if (empty($errorMessage)) {
                $errorMessage = __('Failed to process payment. Please try again.', 'fluentformpro');
            }

            return new \WP_Error(422, $errorMessage);
        }

        return $response;
    }

    protected function isLive()
    {
        return AuthorizeNetSettings::isLive($this->formId);
    }
}
