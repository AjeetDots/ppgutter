<?php

namespace FluentFormPro\Integrations\Hubspot;

use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    protected $apiUrl = 'https://api.hubapi.com/';

    protected $apiKey = null;
    protected $accessToken = null;


    public function __construct($apiKey = null, $accessToken = null)
    {
        $this->apiKey = $apiKey;
        $this->accessToken = $accessToken;
    }

    public function default_options()
    {
        return array(
            'hapikey' => $this->apiKey
        );
    }

    public function make_request($action, $options = array(), $method = 'GET')
    {
        $method = strtoupper($method);
        $request_url = $this->buildRequestUrl($action, 'GET' === $method ? $options : array());
        $args = array(
            'method'  => $method,
            'headers' => [
                'Content-Type' => 'application/json'
            ]
        );

        if ($this->accessToken) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        if (!in_array($method, array('GET', 'DELETE'))) {
            $args['body'] = json_encode($options);
        }

        switch ($method) {
            case 'GET':
                $response = wp_remote_get($request_url, $args);
                break;
            case 'POST':
                $response = wp_remote_post($request_url, $args);
                break;
            default:
                $response = wp_remote_request($request_url, $args);
                break;
        }

        /* If WP_Error, die. Otherwise, return decoded JSON. */
        if (is_wp_error($response)) {
            return new \WP_Error(400, $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = \json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) >= 400 && null === $decoded) {
            return new \WP_Error('api_error', 'HubSpot API Request Failed');
        }

        return $decoded;
    }

    protected function buildRequestUrl($action, $options = array())
    {
        $requestUrl = $this->apiUrl . $action;
        $query = $this->accessToken ? $options : wp_parse_args($options, $this->default_options());
        $query = array_filter($query, function ($value) {
            return null !== $value && '' !== $value;
        });

        if (!$query) {
            return $requestUrl;
        }

        $separator = false === strpos($requestUrl, '?') ? '?' : '&';

        return $requestUrl . $separator . http_build_query($query);
    }

    /**
     * Test the provided API credentials.
     *
     * @access public
     * @return bool
     */
    public function auth_test()
    {
        $contactsCheck = $this->make_request('crm/v3/objects/contacts?limit=1', [], 'GET');

        if (is_wp_error($contactsCheck)) {
            return $contactsCheck;
        }

        if ($this->isErrorResponse($contactsCheck)) {
            return new \WP_Error(
                'api_error',
                __('Your HubSpot token is missing required contact permissions. Please ensure your private app has the crm.objects.contacts.read and crm.objects.contacts.write scopes.', 'fluentformpro')
                    . ' (' . $this->getErrorMessage($contactsCheck) . ')'
            );
        }

        return $contactsCheck;
    }

    private function shouldKeepFieldValue($value)
    {
        if (is_array($value)) {
            return !empty($value);
        }

        return !($value === null || $value === '');
    }

    public function subscribe($listId, $values, $updateContact = false)
    {
        $values = array_filter($values, [$this, 'shouldKeepFieldValue']);
        $properties = [
            'properties' => $values
        ];

        $response = $this->make_request('crm/v3/objects/contacts', $properties, 'POST');

        if (is_wp_error($response)) {
            return $response;
        }

        if ($this->isConflictResponse($response)) {
            if ($updateContact) {
                // Update the existing contact's properties via PATCH.
                $response = $this->make_request(
                    'crm/v3/objects/contacts/' . rawurlencode($values['email']) . '?idProperty=email',
                    $properties,
                    'PATCH'
                );
            } else {
                // Preserve legacy v1 behaviour: existing contact stays untouched, but
                // we still resolve their ID so the list-membership step can run below.
                if (empty($values['email'])) {
                    return new \WP_Error('api_error', $this->getErrorMessage($response));
                }

                $existing = $this->getContactByEmail($values['email']);
                if (is_wp_error($existing)) {
                    return $existing;
                }

                $existingId = ArrayHelper::get($existing, 'id');
                if (!$existingId) {
                    return new \WP_Error('api_error', 'HubSpot contact already exists but its ID could not be resolved');
                }

                $response = ['id' => $existingId];
            }
        }

        if ($this->isErrorResponse($response)) {
            return new \WP_Error('api_error', $this->getErrorMessage($response));
        }

        $contactId = ArrayHelper::get($response, 'id');

        if (!$contactId && !empty($values['email'])) {
            $contact = $this->getContactByEmail($values['email']);

            if (is_wp_error($contact)) {
                return $contact;
            }

            $contactId = ArrayHelper::get($contact, 'id');
        }

        if (!$contactId) {
            return new \WP_Error('api_error', 'HubSpot contact ID was not returned');
        }

        if (!$listId) {
            return $contactId;
        }

        $listId = $this->getV3ListId($listId);

        if (is_wp_error($listId)) {
            return $listId;
        }

        // Lists v3 expects CRM record IDs, not legacy contact VID payloads.
        $updateResponse = $this->make_request('crm/v3/lists/' . rawurlencode($listId) . '/memberships/add', [
            (string) $contactId
        ], 'PUT');

        if (is_wp_error($updateResponse)) {
            return $updateResponse;
        }

        if ($this->isErrorResponse($updateResponse)) {
            return new \WP_Error('api_error', $this->getErrorMessage($updateResponse));
        } elseif (null === $updateResponse) {
            return new \WP_Error('api_error', 'HubSpot API Request Failed');
        }

        if (!empty($updateResponse['recordIdsMissing']) && in_array((string) $contactId, $updateResponse['recordIdsMissing'])) {
            return new \WP_Error('api_error', 'HubSpot contact was not added to the selected list');
        }

        return $contactId;
    }

    /**
     * Get all Forms in the system.
     *
     * @access public
     * @return array
     */
    public function getLists()
    {
        $lists = [];
        $offset = 0;

        do {
            $response = $this->make_request('crm/v3/lists/search', [
                'count'           => 500,
                'offset'          => $offset,
                'objectTypeId'    => '0-1',
                'processingTypes' => ['MANUAL', 'SNAPSHOT']
            ], 'POST');

            if (is_wp_error($response)) {
                return $response;
            }

            if ($this->isErrorResponse($response)) {
                return new \WP_Error('api_error', $this->getErrorMessage($response));
            }

            if (!empty($response['lists'])) {
                $lists = array_merge($lists, $response['lists']);
            }

            $nextOffset = ArrayHelper::get($response, 'offset', 0);

            if ($nextOffset == $offset) {
                break;
            }

            $offset = $nextOffset;
        } while (!empty($response['hasMore']));

        return $lists;
    }

    /**
     * Get all Tags in the system.
     *
     * @access public
     * @return array
     */
    public function getTags()
    {
        $response = $this->make_request('tags', array(), 'GET');

        if (is_wp_error($response)) {
            return false;
        }

        if (empty($response['error'])) {
            return $response['tags'];
        }

        return false;
    }
	public function getAllFields()
	{
		$lists = $this->getContactProperties();

		$fields = array_filter($lists, function ($item) {
			return ArrayHelper::get($item, 'formField') && ArrayHelper::get($item, 'hubspotDefined');
		});

		return $fields;
	}

    public function getCustomFields()
    {
        $lists = $this->getContactProperties();

        $customFields = array_filter($lists, function ($item) {
            return empty($item['hubspotDefined']);
        });

        return $customFields;
    }

    protected function getContactByEmail($email)
    {
        $response = $this->make_request(
            'crm/v3/objects/contacts/' . rawurlencode($email) . '?idProperty=email',
            [],
            'GET'
        );

        if (is_wp_error($response)) {
            return $response;
        }

        if ($this->isErrorResponse($response)) {
            return new \WP_Error('api_error', $this->getErrorMessage($response));
        }

        return $response;
    }

    protected function getV3ListId($listId)
    {
        if (0 === strpos($listId, 'v3:')) {
            return substr($listId, 3);
        }

        $response = $this->make_request('crm/v3/lists/idmapping', [
            'legacyListId' => $listId
        ], 'GET');

        if (is_wp_error($response)) {
            return $response;
        }

        if ($this->isErrorResponse($response)) {
            return new \WP_Error('api_error', $this->getErrorMessage($response));
        }

        if (!empty($response['listId'])) {
            return $response['listId'];
        }

        return new \WP_Error('api_error', 'HubSpot list ID could not be migrated to Lists API v3. Please re-select the HubSpot list in this feed.');
    }

    public function getContactProperties()
    {
        $properties = [];
        $after = null;

        do {
            $query = ['limit' => 100];
            if (null !== $after) {
                $query['after'] = $after;
            }

            $response = $this->make_request('crm/v3/properties/contacts', $query, 'GET');

            if (is_wp_error($response) || $this->isErrorResponse($response)) {
                return $properties;
            }

            $results = ArrayHelper::get($response, 'results', []);
            if (!empty($results)) {
                $properties = array_merge($properties, $results);
            }

            $nextAfter = ArrayHelper::get($response, 'paging.next.after');

            if (!$nextAfter || (string) $nextAfter === (string) $after) {
                break;
            }

            $after = (string) $nextAfter;
        } while (true);

        return $properties;
    }

    protected function isConflictResponse($response)
    {
        if (!is_array($response)) {
            return false;
        }

        $category = strtoupper((string) ArrayHelper::get($response, 'category'));
        $error = strtoupper((string) ArrayHelper::get($response, 'error'));
        $message = strtolower((string) ArrayHelper::get($response, 'message'));

        return 'CONFLICT' === $category
            || 'CONTACT_EXISTS' === $error
            || false !== strpos($message, 'already exists');
    }

    protected function isErrorResponse($response)
    {
        if (is_wp_error($response)) {
            return true;
        }

        if (!is_array($response)) {
            return false;
        }

        return 'error' === ArrayHelper::get($response, 'status')
            || !empty($response['error'])
            || !empty($response['errors']);
    }

    protected function getErrorMessage($response)
    {
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $message = ArrayHelper::get($response, 'message');

        if ($message) {
            return $message;
        }

        $error = ArrayHelper::get($response, 'error');

        if ($error) {
            return $error;
        }

        if (!empty($response['errors']) && is_array($response['errors'])) {
            $firstError = reset($response['errors']);
            $errorMessage = ArrayHelper::get($firstError, 'message');

            if ($errorMessage) {
                return $errorMessage;
            }
        }

        return 'HubSpot API Request Failed';
    }
}
