<?php

namespace FluentFormPro\Integrations\SMSNotification;

use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class TwilioApi
{
    protected $apiUrl = 'https://api.twilio.com/2010-04-01/';

    protected $authToken = null;

    protected $accountSID = null;

    public function __construct($authToken = null, $accountSID = null)
    {
        $this->authToken = $authToken;
        $this->accountSID = $accountSID;
    }

    public function default_options()
    {
        return array(
            'api_key' => $this->apiKey
        );
    }

    public function make_request($action, $options = array(), $method = 'GET')
    {
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->accountSID . ':' . $this->authToken),
                'Content-Type'  => 'application/json',
            )
        );

        /* Build request URL. */
        $request_url = $this->apiUrl . $action;

        /* Execute request based on method. */
        $response = '';
        switch ($method) {
            case 'POST':
                $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                //$request_url .= '?'.http_build_query($options);
                $args['body'] = $options;
                $response = wp_remote_post($request_url, $args);
                break;

            case 'GET':
                $response = wp_remote_get($request_url, $args);
                break;
        }

        /* If WP_Error, die. Otherwise, return decoded JSON. */
        if (is_wp_error($response)) {
            return new \WP_Error($response->get_error_code(), $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 300) {
            $message = ArrayHelper::get($body, 'message');
            return new \WP_Error($code, $message);
        } else {
            return $body;
        }
    }

    /**
     * Test the provided API credentials.
     *
     * @access public
     * @return bool
     */
    public function auth_test()
    {
        return $this->make_request('Accounts.json', [], 'GET');
    }


    public function sendSMS($accountId, $data)
    {
        $response = $this->make_request('Accounts/' . \rawurlencode($accountId) . '/Messages.json', $data, 'POST');

        if (is_wp_error($response)) {
            return new \WP_Error($response->get_error_code(), $response->get_error_message());
        }

        return $response;
    }

}
