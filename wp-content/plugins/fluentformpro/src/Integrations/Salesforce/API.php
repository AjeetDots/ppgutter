<?php

namespace FluentFormPro\Integrations\Salesforce;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    protected $clientId = null;

    protected $clientSecret = null;

    protected $instance_url = null;

    protected $accessToken = null;

    protected $callBackUrl = null;

    protected $settings = [];

    protected $refreshTokenRevoked = false;

    public function __construct($settings)
    {
        $this->clientId = $settings['client_id'];
        $this->clientSecret = $settings['client_secret'];
        $this->instance_url = rtrim($settings['instance_url'], '/');
        $this->accessToken = $settings['access_token'];
        $this->callBackUrl = admin_url('?ff_salesforce_auth=true');
        $this->settings = $settings;

        // SECURITY (PRO-38): Salesforce instance URLs are always on salesforce.com / force.com.
        // A settings_manager could otherwise point instance_url at an internal or attacker host and
        // the OAuth Bearer token attached to every request would be exfiltrated there (SSRF +
        // credential exposure). Fail closed by emptying an out-of-policy host, so the derived URLs
        // become relative and wp_remote_* rejects them before any token leaves.
        if ($this->instance_url && !self::isAllowedSalesforceHost($this->instance_url)) {
            $this->instance_url = '';
        }
    }

    public static function isAllowedSalesforceHost($url)
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if ('https' !== $scheme || !$host) {
            return false;
        }
        // COMPAT: filterable so an operator can add a legitimate Salesforce host this list does not
        // anticipate (a new TLD/region) without patching, keeping the default-deny posture.
        $suffixes = apply_filters('fluentform/salesforce_allowed_host_suffixes', ['salesforce.com', 'force.com']);
        foreach ((array) $suffixes as $suffix) {
            $suffix = strtolower((string) $suffix);
            if ($host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix) {
                return true;
            }
        }
        return false;
    }

    public function getRedirectServerURL()
    {
        $verifier = wp_generate_password(64, false);
        set_transient('ff_salesforce_pkce_' . get_current_user_id(), $verifier, HOUR_IN_SECONDS);

        // SECURITY (PRO-17): bind the OAuth response to the initiating admin session so a
        // forged callback (attacker's code) cannot graft the attacker's account onto the site.
        return $this->instance_url .
            '/services/oauth2/authorize?response_type=code&client_id=' .
            $this->clientId .
            '&state=' . rawurlencode(wp_create_nonce('ff_salesforce_oauth')) .
            '&code_challenge=' . rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=') .
            '&code_challenge_method=S256' .
            '&redirect_uri=' .
            $this->callBackUrl;
    }

    public function generateAccessToken($code, $settings)
    {
        $url = $settings['is_sandbox'] == 'true' ? 'https://test.salesforce.com/services/oauth2/token' : 'https://login.salesforce.com/services/oauth2/token';

        $body = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->callBackUrl,
            'code'          => $code
        ];

        $verifier = get_transient('ff_salesforce_pkce_' . get_current_user_id());
        if ($verifier) {
            $body['code_verifier'] = $verifier;
            delete_transient('ff_salesforce_pkce_' . get_current_user_id());
        }

        $response = wp_remote_post($url, [
            'body' => $body
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (empty($body['access_token']) || empty($body['refresh_token'])) {
            if (!empty($body['access_token'])) {
                $message = __('Salesforce did not return a refresh token. Add the "Perform requests at any time (refresh_token, offline_access)" scope to your app and connect again.', 'fluentformpro');
            } else {
                $message = $this->tokenErrorMessage($response, $body);
            }
            return new \WP_Error('invalid_client', $message);
        }

        $settings['access_token'] = $body['access_token'];
        $settings['refresh_token'] = $body['refresh_token'];
        $settings['refreshed_at'] = time();

        return $settings;
    }

    protected function maybeRefreshToken()
    {
        $url = $this->settings['is_sandbox'] == 'true' ? 'https://test.salesforce.com/services/oauth2/token' : 'https://login.salesforce.com/services/oauth2/token';

        $response = wp_remote_post($url, [
            'body' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->settings['refresh_token']
            ]
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (empty($body['access_token'])) {
            $message = $this->tokenErrorMessage($response, $body);
            if (isset($body['error']) && 'invalid_grant' === $body['error']) {
                $this->refreshTokenRevoked = $message;
            }
            return new \WP_Error('invalid_client', $message);
        }

        $tokens = [
            'access_token' => $body['access_token'],
            'refreshed_at' => time()
        ];
        if (!empty($body['refresh_token'])) {
            $tokens['refresh_token'] = $body['refresh_token'];
        }
        $this->settings = array_merge($this->settings, $tokens);

        // Merge into a fresh read so an admin disconnecting or saving new credentials meanwhile is not overwritten.
        $stored = get_option('_fluentform_salesforce_settings');
        if (is_array($stored) && !empty($stored['status'])) {
            update_option('_fluentform_salesforce_settings', array_merge($stored, $tokens), 'no');
        }

        return true;
    }

    protected function tokenErrorMessage($response, $body)
    {
        if (!empty($body['error_description'])) {
            return $body['error_description'];
        }
        if (!empty($body['error'])) {
            return $body['error'];
        }
        return 'HTTP ' . wp_remote_retrieve_response_code($response);
    }

    protected function sendRequest($url, $bodyArgs, $type)
    {
        $headers = [
            'Authorization' => " Bearer ". $this->settings['access_token'],
        ];

        if ($type == 'POST') {
            $headers['Content-Type'] = 'application/json';
            return wp_remote_post($url, [
                'headers' => $headers,
                'body' => $bodyArgs
            ]);
        }

        return wp_remote_get($url, [
            'headers' => $headers
        ]);
    }

    public function makeRequest($url, $bodyArgs, $type = 'GET')
    {
        if (!$this->settings['status']) {
            return new \WP_Error('invalid', __('API key is invalid', 'fluentformpro'));
        }

        // Salesforce expires refresh tokens left unused for 30 days, and steady traffic keeps the access
        // token alive so the 401 refresh below never runs; use the refresh token at least weekly.
        if (empty($this->settings['refreshed_at']) || time() - (int) $this->settings['refreshed_at'] > WEEK_IN_SECONDS) {
            $this->maybeRefreshToken();
        }

        $request = $this->sendRequest($url, $bodyArgs, $type);

        if (!is_wp_error($request) && 401 == wp_remote_retrieve_response_code($request)) {
            $this->refreshTokenRevoked = false;
            $refreshed = $this->maybeRefreshToken();
            if (is_wp_error($refreshed) && !$this->refreshTokenRevoked) {
                return $refreshed;
            }
            if (!is_wp_error($refreshed)) {
                $request = $this->sendRequest($url, $bodyArgs, $type);
            }
        }

        if (is_wp_error($request)) {
            $message = $request->get_error_message();
            return new \WP_Error($request->get_error_code(), $message);
        } elseif ($request['response']['code'] >= 200 && $request['response']['code'] <= 299) {
            return json_decode($request['body'], true);
        }

        $body = wp_remote_retrieve_body($request);
        $body = \json_decode($body, true);
        $body = isset($body[0]) ? $body[0] : $body;

        $error = 'Unknown Error';

        if (401 == $request['response']['code'] && $this->refreshTokenRevoked) {
            /* translators: %s: reason returned by Salesforce */
            return new \WP_Error(401, sprintf(__('Salesforce rejected the saved authorization (%s). Please reconnect Salesforce in Fluent Forms → Global Settings → Configure Integrations → Salesforce.', 'fluentformpro'), $this->refreshTokenRevoked));
        }

        if (!empty($body['errorCode'])) {
            if (isset($body['message'])) {
                $error = $body['message'];
            }
        }

        return new \WP_Error($request['response']['code'], $error);
    }

    public function subscribe($subscriber)
    {
        $url = $this->instance_url . '/services/data/v53.0/sobjects/' . $subscriber['list_id'];
        $post = \json_encode($subscriber['attributes'], JSON_NUMERIC_CHECK);

        $response = $this->makeRequest($url, $post, 'POST');

        return $response;
    }
}
