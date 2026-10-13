<?php

namespace FluentFormPro\Integrations\Mailjet;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    public $apiKey = null;

    public $secretKey = null;

    public $accessToken = null;

    public $url = 'https://api.mailjet.com/v3/';

    public function __construct($settings)
    {
        $this->clientId = $settings['api_key'];
        $this->clientSecret = $settings['secret_key'];
        $this->accessToken = 'Basic ' . base64_encode($settings['api_key'] . ':' . $settings['secret_key']);
    }

    public function checkAuth()
    {
        return $this->makeRequest($this->url . 'rest/contact');
    }

    public function makeRequest($url, $bodyArgs = [], $type = 'GET')
    {
        $request = [];
        if ($type == 'GET') {
            $request = wp_remote_get($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => $this->accessToken,
                ]
            ]);
        }

        if ($type == 'POST') {
            $request = wp_remote_post($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => $this->accessToken,
                ],
                'body' => $bodyArgs
            ]);
        }

        if (is_wp_error($request)) {
            $code = $request->get_error_code();
            $message = $request->get_error_message();
            return new \WP_Error($code, $message);
        }


        $body = wp_remote_retrieve_body($request);
        $body = \json_decode($body, true);
        $code = wp_remote_retrieve_response_code($request);

        if ($code == 200 || $code == 201) {
            return $body;
        } else {
            if (is_null($body)) {
                return new \WP_Error($code, __('Bad Request.', 'fluentformpro'));
            }
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            return new \WP_Error($code, __('Error related to ' . $body['ErrorRelatedTo'][0] . ': ' . $body['ErrorMessage'], 'fluentformpro'));
        }
    }

    public function subscribe($subscriber)
    {
        if ($subscriber['list_id'] == 'send') {
            $url = $this->url . $subscriber['list_id'];
        } else {
            $url = $this->url . 'rest/' . $subscriber['list_id'];
        }

        $post = \json_encode($subscriber['attributes'], true);

        $response = $this->makeRequest($url, $post, 'POST');

        return $response;
    }
}
