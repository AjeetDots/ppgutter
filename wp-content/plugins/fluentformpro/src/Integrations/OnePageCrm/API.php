<?php

namespace FluentFormPro\Integrations\OnePageCrm;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    protected $clientId = null;

    protected $clientSecret = null;

    protected $accessToken = null;

    protected $url = 'https://app.onepagecrm.com/api/v3/';

    public function __construct($settings)
    {
        $this->clientId = $settings['client_id'];
        $this->clientSecret = $settings['client_secret'];
        $this->accessToken = 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']);
    }

    public function checkAuth()
    {
        return $this->makeRequest($this->url . 'bootstrap');
    }

    public function getAccessToken()
    {
        return [
            'access_token' => $this->accessToken,
        ];
    }

    public function makeRequest($url, $bodyArgs = [], $type = 'GET')
    {
        $accessToken = $this->getAccessToken();

        $request = [];
        if ($type == 'GET') {
            $request = wp_remote_get($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => $accessToken['access_token'],
                ]
            ]);
        }

        if ($type == 'POST') {
            $request = wp_remote_post($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => $accessToken['access_token'],
                ],
                'body'    => $bodyArgs
            ]);
        }

        $body = wp_remote_retrieve_body($request);
        $body = \json_decode($body, true);
        $code = wp_remote_retrieve_response_code($request);

        if ($code == 200 || $code == 201) {
            return $body;
        }
        else {
            return new \WP_Error($code, $body['error_message'], $body['errors']);
        }
    }

    public function subscribe($subscriber)
    {
        $url = 'https://app.onepagecrm.com/api/v3/' . $subscriber['list_id'];

        $post = \json_encode($subscriber['attributes'], true);

        return $this->makeRequest($url, $post, 'POST');
    }
}
