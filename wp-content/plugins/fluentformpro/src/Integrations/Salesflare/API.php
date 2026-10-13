<?php

namespace FluentFormPro\Integrations\Salesflare;

if (!defined('ABSPATH')) {
    exit;
}

class API
{
    protected $apiUrl = 'https://api.salesflare.com/';
    
    protected $apiKey = '';
    
    protected $optionKey = '_fluentform_salesflare_settings';
    
    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }
    
    public function make_request($action, $options, $method = 'GET', $headers = "")
    {
        $endpointUrl = $this->apiUrl . $action;
        
        if ($headers) {
            $args = [
                'headers' => $headers
            ];
        }
        
        if ($options) {
            $args['body'] = $options;
        }
        /* Execute request based on method. */
        switch ($method) {
            case 'POST':
                $response = wp_remote_post($endpointUrl, $args);
                break;
            
            case 'GET':
                $response = wp_remote_get($endpointUrl, $args);
                break;
        }
        if (is_wp_error($response)) {
            return [
                'error' => 'API_Error',
                'message' => $response->get_error_message()
            ];
        } elseif ($response['response']['code'] == 200) {
            return json_decode($response['body'], true);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body['error'])) {
            $error = 'Unknown Error';
            if (isset($body['message'])) {
                $error = $body['message'];
            } elseif (!empty($body['error']['message'])) {
                $error = $body['error']['message'];
            }
            
            return new \WP_Error(423, $error);
        }
        
        return $body;
    }
    
    public function ping()
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey
        ];
        
        return $this->make_request('accounts', '', 'GET', $headers);
    }
    
    public function customFields()
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey
        ];
        
        return $this->make_request('customfields/contacts', '', 'GET', $headers);
    }
    
    public function createContact($addData)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json'
        ];
        
        return $this->make_request('contacts', json_encode($addData), 'POST', $headers);
    }
    
    
}
