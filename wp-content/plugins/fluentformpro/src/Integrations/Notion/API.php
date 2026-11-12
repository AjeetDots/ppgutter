<?php

namespace FluentFormPro\Integrations\Notion;

class API
{
    private $optionKey = '_fluentform_notion_settings';

    public function makeRequest($url, $bodyArgs = null, $type = 'GET', $headers = [])
    {
        $request = [];

        if (empty($headers)) {
            $settings = get_option($this->optionKey);
            $accessToken = $settings['access_token'];

            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Notion-Version' => '2025-09-03'
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

    public function subscribe($subscriber)
    {
        $url = 'https://api.notion.com/v1/pages/';
        $response = $this->makeRequest($url, $subscriber, 'POST');
        return $response;
    }
}
