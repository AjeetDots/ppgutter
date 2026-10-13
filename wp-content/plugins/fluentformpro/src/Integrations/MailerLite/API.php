<?php

namespace FluentFormPro\Integrations\MailerLite;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    protected $apiUrl = 'https://api.mailerlite.com/api/v2/';

    protected $apiKey = null;

    protected $apiSecret = null;

    public function __construct($apiKey = null)
    {
        $this->apiKey = $apiKey;
    }

    public function default_options()
    {
        return [
            'User-Agent'          => 'MailerLite PHP SDK/2.0',
            'X-MailerLite-ApiKey' => $this->apiKey,
            'Content-Type'        => 'application/json'
        ];
    }

    public function make_request($action, $options = array(), $method = 'GET')
    {

        $headers = $this->default_options();
        $endpointUrl = $this->apiUrl . $action;
        $args = [
            'headers' => $headers
        ];

        if ($options) {
            $args['body'] = \json_encode($options);
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

        /* If WP_Error, die. Otherwise, return decoded JSON. */
        if (is_wp_error($response)) {
            return [
                'error'   => 'API_Error',
                'message' => $response->get_error_message()
            ];
        } else if ($response && $response['response']['code'] >= 300) {
            return [
                'error'   => 'API_Error',
                'message' => $response['response']['message']
            ];
        }
        return json_decode($response['body'], true);
    }

    /**
     * Test the provided API credentials.
     *
     * @access public
     * @return bool
     */
    public function auth_test()
    {
        return $this->make_request('groups', [], 'GET');
    }


    public function subscribe($formId, $data)
    {
        $response = $this->make_request('groups/' . $formId . '/subscribers', $data, 'POST');
        if (!empty($response['error'])) {
            return new \WP_Error('api_error', $response['message']);
        }
        return $response;
    }

    /**
     * Get all MailerLite groups (v2 paginates via limit/offset, max 1000).
     *
     * Loops until a page returns fewer rows than the requested limit
     * (= last page). The fluentform/mailerlite_groups_pagination filter
     * lets a snippet override the per-page limit and the safety cap on
     * total pages.
     *
     * @access public
     * @return array
     */
    public function getGroups()
    {
        $config = apply_filters('fluentform/mailerlite_groups_pagination', [
            'limit'        => 1000, // v2 max per page
            'max_pages'    => 50,   // hard stop in case the API misbehaves
            'start_offset' => 0,    // skip this many rows before starting
        ]);

        $limit = (int) ($config['limit'] ?? 1000);
        $maxPages = (int) ($config['max_pages'] ?? 50);
        $offset = (int) ($config['start_offset'] ?? 0);

        $allGroups = [];
        $page = 0;

        do {
            $response = $this->make_request(
                'groups?limit=' . $limit . '&offset=' . $offset,
                [],
                'GET'
            );

            if (!is_array($response) || !empty($response['error'])) {
                break;
            }

            $count = count($response);
            if ($count === 0) {
                break;
            }

            $allGroups = array_merge($allGroups, $response);
            $offset += $count;
            $page++;
        } while ($count === $limit && $page < $maxPages);

        return $allGroups;
    }

    public function getCustomFields()
    {
        $response = $this->make_request('fields', array(), 'GET');
        if (empty($response['error'])) {
            return $response;
        }
        return false;
    }

}
