<?php

namespace FluentFormPro\Integrations\ConstantContactV3;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;

class API
{
    protected $apiUrl = 'https://api.cc.email/v3/';
    protected $clientId = null;
    protected $clientSecret = null;
    protected $refreshToken = null;
    protected $accessToken = null;
    protected $redirectUrl = null;
    protected $expiresAt = null;

    public function __construct($settings)
    {
        $this->clientId = $settings['client_id'];
        $this->clientSecret = $settings['client_secret'];
        $this->accessToken = $settings['access_token'];
        $this->refreshToken = $settings['refresh_token'];
        $this->expiresAt = $settings['expires_at'];
        $this->redirectUrl = admin_url('?ff_constant_contact_auth=true');
    }

    public function getRedirectServerURL()
    {
        // SECURITY (PRO-17): use a verifiable session-bound nonce as state (was an unpersisted
        // random value that the callback never checked) so a forged callback cannot graft the
        // attacker's account onto the site.
        return 'https://authz.constantcontact.com/oauth2/default/v1/authorize?' .
               'client_id=' . $this->clientId . '&scope=contact_data%20offline_access&response_type=code&state=' . rawurlencode(wp_create_nonce('ff_constant_contact_oauth')) . '&redirect_uri=' . $this->redirectUrl;
    }

    public function generateAccessToken($code, $settings)
    {
        $url = 'https://authz.constantcontact.com/oauth2/default/v1/token';

        // Set authorization header
        // Make string of "API_KEY:SECRET"
        $auth = $this->clientId . ':' . $this->clientSecret;
        $credentials = base64_encode($auth);

        $response = wp_remote_post($url, [
            'header' => [
                'Authorization' => 'Basic ' . $credentials,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/x-www-form-urlencoded'
            ],
            'body'   => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code'          => $code,
                'redirect_uri'  => $this->redirectUrl,
                'grant_type'    => 'authorization_code'
            ]
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (
            $errorCode = ArrayHelper::get($body, 'errorCode') &&
            $errorDescription = ArrayHelper::get($body, 'errorSummary')
        ) {
            return new \WP_Error($errorCode, $errorDescription);
        }

        $settings['access_token'] = $body['access_token'];
        $settings['refresh_token'] = $body['refresh_token'];
        $settings['expires_at'] = time() + intval($body['expires_in']);

        return $settings;
    }

    protected function getApiSettings()
    {
        $refreshedToken = $this->maybeRefreshToken();

        if (is_wp_error($refreshedToken)) {
            wp_send_json_error([
                'message' => $refreshedToken->get_error_message()
            ], 423);
        }

        return $refreshedToken;
    }

    protected function maybeRefreshToken()
    {
        $updatedCredential = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at'    => $this->expiresAt,
            'status'        => true
        ];

        if ($this->expiresAt && $this->expiresAt <= (time() - 30)) {
            $url = 'https://authz.constantcontact.com/oauth2/default/v1/token';
            // Set authorization header
            // Make string of "API_KEY:SECRET"
            $auth = $this->clientId . ':' . $this->clientSecret;
            $credentials = base64_encode($auth);

            $response = wp_remote_post($url, [
                'header' => [
                    'Authorization' => 'Basic ' . $credentials,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/x-www-form-urlencoded'
                ],
                'body'   => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->refreshToken,
                    'grant_type'    => 'refresh_token',
                ]
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $body = \json_decode($body, true);

            if (
                $error = ArrayHelper::get($body, 'error') &&
                $errorDescription = ArrayHelper::get($body, 'error_description')
            ) {
                return new \WP_Error($error, $errorDescription);
            }

            $this->accessToken = $body['access_token'];
            $this->refreshToken = $body['refresh_token'];
            $this->expiresAt = time() + intval($body['expires_in']);

            $updatedCredential = [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'access_token'  => $this->accessToken,
                'refresh_token' => $this->refreshToken,
                'expires_at'    => $this->expiresAt,
                'status'        => true
            ];

            update_option('_fluentform_constantcontactv3_settings', $updatedCredential);
        }

        return $updatedCredential;
    }

    public function makeRequest($endpoint, $bodyArgs = [], $type = 'GET')
    {
        $apiSettings = $this->getApiSettings();
        if (is_wp_error($apiSettings)) {
            $message = $apiSettings->get_error_message();
            return new \WP_Error($apiSettings->get_error_code(), $message);
        }

        $url = $this->apiUrl . $endpoint;
        $request = [];
        if ($type == 'GET') {
            $request = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => " Bearer " . $this->accessToken,
                ]
            ]);
        }

        if ($type == 'POST') {
            $request = wp_remote_post($url, [
                'headers' => [
                    'Authorization' => " Bearer " . $this->accessToken,
                    'Content-Type'  => 'application/json'
                ],
                'body'    => $bodyArgs
            ]);
        }

        if (is_wp_error($request)) {
            $message = $request->get_error_message();
            return new \WP_Error($request->get_error_code(), $message);
        }

        $body = wp_remote_retrieve_body($request);
        $code = wp_remote_retrieve_response_code($request);
        $body = \json_decode($body, true);

        if ($code >= 200 && $code <= 299) {
            return $body;
        }

        $body = ArrayHelper::exists($body, '0') ? ArrayHelper::get($body, '0') : $body;
        $error = __('Unknown Error', 'fluentformpro');
        if ($message = ArrayHelper::get($body, 'error_message')) {
            $error = $message;
        }

        return new \WP_Error($code, $error);
    }

    public function subscribe($subscriber)
    {
        $url = $this->apiUrl . '/services/data/v53.0/sobjects/' . $subscriber['list_id'];
        $post = \json_encode($subscriber['attributes'], JSON_NUMERIC_CHECK);

        return $this->makeRequest($url, $post, 'POST');
    }
}
