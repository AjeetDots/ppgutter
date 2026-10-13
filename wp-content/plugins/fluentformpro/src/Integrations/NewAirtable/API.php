<?php

namespace FluentFormPro\Integrations\NewAirtable;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    protected $accessToken = null;
    public $url = 'https://api.airtable.com/v0/';

    public function __construct($settings)
    {
        $this->accessToken = 'Bearer ' . $settings['access_token'];
    }

    public function checkAuth()
    {
        return $this->makeRequest($this->url . 'meta/whoami');
    }

    public function getBases()
    {
        return $this->makeRequest($this->url . 'meta/bases');
    }

    public function getTables($baseId)
    {
        return $this->makeRequest($this->url . 'meta/bases/' . $baseId . '/tables');
    }

    public function makeRequest($url, $bodyArgs = [], $type = 'GET')
    {
        $request = [];
        $headers = [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => $this->accessToken
        ];

        if ($type == 'GET') {
            $request = wp_remote_get($url, [
                'headers' => $headers
            ]);
        }

        if ($type == 'POST') {
            $request = wp_remote_post($url, [
                'headers' => $headers,
                'body'    => $bodyArgs
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
            return new \WP_Error($code,
                isset($body['error']['type']) ? $body['error']['type'] . ': ' . $body['error']['message'] : $body['error']);
        }
    }

    public function subscribe($subscriber, $baseId, $tableId)
    {
        $url = 'https://api.airtable.com/v0/' . $baseId . '/' . $tableId;
        $post = \json_encode($subscriber, true);
        return $this->makeRequest($url, $post, 'POST');
    }
}