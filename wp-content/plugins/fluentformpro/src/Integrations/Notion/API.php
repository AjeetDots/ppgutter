<?php

namespace FluentFormPro\Integrations\Notion;

class API
{
    private $clientId = 'a7d866f8-cdfe-477d-8cce-9e363355c6b2';
    private $clientSecret = 'secret_L6ONr6q4vy3scIo1f6FsjAwP6QjEp7KUxzRxy5ypeOs';
    private $redirect = 'https://fluentforms.com/gapi';
    private $optionKey = '_fluentform_notion_settings';

    public function __construct()
    {
        if (defined('FF_NOTION_CLIENT_ID')) {
            $this->clientId = FF_NOTION_CLIENT_ID;
        }

        if (defined('FF_NOTION_CLIENT_SECRET')) {
            $this->clientSecret = FF_NOTION_CLIENT_SECRET;
        }
    }

    public function makeRequest($url, $bodyArgs = null, $type = 'GET', $headers = [])
    {
        $request = [];

        if (empty($headers)) {
            $settings = get_option($this->optionKey);
            $accessToken = $settings['access_token'];

            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Notion-Version' => '2022-06-28'
            ];
        }

        if ($type == 'GET') {
            $request = wp_remote_get($url, [
                'headers' => $headers
            ]);
        }

        $bodyArgs = json_encode($bodyArgs, true);

        if ($type == 'POST') {
            $request = wp_remote_post($url, [
                'headers' => $headers,
                'body' => $bodyArgs
            ]);
        }

        if (is_wp_error($request)) {
            $message = $request->get_error_message();
            return new \WP_Error(423, $message);
        }

        $body = json_decode(wp_remote_retrieve_body($request), true);
        $code = wp_remote_retrieve_response_code($request);

        if ($code >= 400 && $code <= 504) {
            $error = 'Something went wrong';
            if (isset($body['message'])) {
                $error = $body['message'];
            }
            return new \WP_Error(423, $error);
        }

        return $body;
    }

    public function generateAccessToken($token)
    {
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type' => 'application/json',
            'Notion-Version' => '2022-06-28'
        ];

        $body = [
            'code'          => $token,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->redirect
        ];

        $request = $this->makeRequest('https://api.notion.com/v1/oauth/token', $body, 'POST', $headers);

        $token = $request['access_token'];

        $this->authCode = $token;

        return $token;
    }

    public function getAccessToken()
    {
        $tokens = get_option($this->optionKey);

        if (!$tokens) {
            return false;
        }

        if (($tokens['created_at'] + $tokens['expires_in'] - 30) < time()) {
            // It's expired so we have to re-issue again
            $refreshTokens = $this->refreshToken($tokens);

            if (!is_wp_error($refreshTokens)) {
                $tokens['access_token'] = $refreshTokens['access_token'];
                $tokens['expires_in'] = $refreshTokens['expires_in'];
                $tokens['created_at'] = time();
                update_option($this->optionKey, $tokens, 'no');
            } else {
                return false;
            }
        }

        return $tokens['access_token'];
    }

    public function getAuthUrl()
    {
        return 'https://api.notion.com/v1/oauth/authorize?client_id=' . $this->clientId . '&response_type=code&owner=user&redirect_uri=' . $this->redirect;
    }

    public function subscribe($subscriber)
    {
        $url = 'https://api.notion.com/v1/pages/';
        $response = $this->makeRequest($url, $subscriber, 'POST');
        return $response;
    }
}