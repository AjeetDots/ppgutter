<?php

namespace FluentFormPro\Integrations\Insightly;

use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    public $apiKey = null;

    public $url = null;

    public function __construct($settings)
    {
        $this->url = trim($settings['url']);
        $this->apiKey = 'Basic ' . base64_encode($settings['api_key'] . ':' . ' ');
    }

    public function checkAuth()
    {
        return $this->makeRequest($this->url . '/v3.1/instance');
    }

    public function makeRequest($url, $bodyArgs = [], $type = 'GET')
    {
        $request = [];
        if ($type == 'GET') {
            $request = wp_remote_get($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => $this->apiKey,
                ]
            ]);
        }

        if ($type == 'POST') {
            $request = wp_remote_post($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => $this->apiKey,
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
            $message = ArrayHelper::get($body, 'Message');
            if(empty($message)){
                $message = ArrayHelper::get($body, '0.Message','Something went wrong please check again!');
            }
            return new \WP_Error($code, $message);
        }
    }

    public function subscribe($subscriber)
    {
        $url = $this->url . '/v3.1/' . $subscriber['list_id'];

        $post = \json_encode($subscriber['attributes'], true);

        $response = $this->makeRequest($url, $post, 'POST');

        return $response;
    }
}
