<?php

namespace FluentFormPro\Integrations\ClickSend;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ClickSend
{
    protected $apiUrl = 'https://rest.clicksend.com/v3/';

    protected $authToken = null;

    protected $username = null;

    public function __construct( $authToken = null, $username = null )
    {
        $this->authToken = $authToken;
        $this->username = $username;
    }

    public function default_options()
    {
        return array(
            'api_key'    => $this->apiKey
        );
    }

    public function make_request( $action, $options = array(), $method = 'GET' )
    {
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->authToken ),
                'Content-Type' => 'application/json',
            )
        );

        /* Build request URL. */
        $request_url = $this->apiUrl  . $action;

        /* Execute request based on method. */
        switch ( $method ) {
            case 'POST':
                $args['body'] = json_encode($options);
                $response = wp_remote_post( $request_url, $args );
                break;

            case 'GET':
                $response = wp_remote_get( $request_url, $args );
                break;
        }

        /* If WP_Error, die. Otherwise, return decoded JSON. */
        if ( is_wp_error( $response ) ) {
            return [
                'error' => 'API_Error',
                'message' => $response->get_error_message(),
                'response' => $response
            ];
        } else if($response['response']['code'] >= 300) {
            return [
                'error'    => 'API_Error',
                'message'  => $response['response']['message'],
                'response' => $response
            ];
        } else {
            return json_decode( $response['body'], true );
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
        return $this->make_request('sms/inbound', [], 'GET');
    }


    public function sendSMS($action , $data)
    {
        switch ( $action ) {
            case 'single-sms':
                $response = $this->make_request('sms/send', $data, 'POST');
                break;

            case 'sms-campaign':
                $response = $this->make_request('sms-campaigns/send', $data, 'POST');
                break;
        }


        if(!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }

        return $response;
    }

    public function addSubscriberContact($campaign_list_id,$data){

        $response = $this->make_request("lists/$campaign_list_id/contacts",$data, 'POST');

        if(!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }

        return $response;
    }

    public function addContactList($data){

        $response = $this->make_request("lists", $data, 'POST');

        if(!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }

        return $response;
    }

    public function addEmailCampaign($data){

        $response = $this->make_request("email-campaigns/send", $data, 'POST');

        if(!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }

        return $response;
    }


    public function getLists(){
        $response = $this->make_request('lists',[],'GET');

        if(!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }

        return $response;
    }

    public function getTemplates($action){
        $response = $this->make_request($action,[],'GET');

        if(!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }

        return $response;
    }
    public function getEmailAddress($action){
        $response = $this->make_request($action,[],'GET');

        if(!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }

        return $response;
    }

    public function get($action){
        $response = $this->make_request($action,[],'GET');

        if(!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }

        return $response;
    }

}
