<?php

namespace FluentFormPro\Integrations\ZohoCRM;

class ZohoCRM
{
    protected $accountUrl = '';

    protected $apiUrl = 'https://www.zohoapis.com/crm/v2/';

    protected $clientId = null;

    protected $clientSecret = null;

    protected $callBackUrl = null;

    protected $settings = [];

    public function __construct($accountUrl, $settings)
    {
        if (substr($accountUrl, -1) == '/') {
            $accountUrl = substr($accountUrl, 0, -1);
        }

        $apiDataServer = explode('.', $accountUrl);
        $apiDataServerCountryCode = end($apiDataServer);

         if ($apiDataServerCountryCode === 'cn' || $apiDataServerCountryCode === 'au') {
             $this->apiUrl = 'https://www.zohoapis.com.'. end($apiDataServer).'/crm/v2/';
         } else {
             $this->apiUrl = 'https://www.zohoapis.'. end($apiDataServer).'/crm/v2/';
         }

        $this->accountUrl = $accountUrl;
        $this->clientId = $settings['client_id'];
        $this->clientSecret = $settings['client_secret'];
        $this->settings = $settings;
        $this->callBackUrl = admin_url('?ff_zohocrm_auth=1');

        // SECURITY (PRO-38): the OAuth client_secret is POSTed to $accountUrl/oauth/v2/token, which
        // was accepted with esc_url_raw only. Zoho account URLs are always on a zoho.* domain;
        // reject anything else so a tampered accountUrl cannot exfiltrate the client_secret to an
        // internal or attacker host (SSRF + credential exposure). Fail closed by emptying it.
        if ($this->accountUrl && !self::isAllowedZohoHost($this->accountUrl)) {
            $this->accountUrl = '';
        }
    }

    protected static function isAllowedZohoHost($url)
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if ('https' !== $scheme || !$host) {
            return false;
        }
        // accounts.zoho.com / .eu / .in / .com.au / .com.cn / .jp / .sa and zohocloud.ca
        if (preg_match('/(^|\.)zoho(cloud)?\.(com|eu|in|jp|sa|ca|com\.au|com\.cn)$/', $host)) {
            return true;
        }
        // COMPAT: filterable escape valve for a legitimate Zoho region/host not covered above,
        // without patching (keeps the default-deny posture). Return true to allow $host.
        return (bool) apply_filters('fluentform/zoho_allowed_host', false, $host);
    }

    public function redirectToAuthServer()
    {
        $url = add_query_arg([
            'scope' => 'ZohoCRM.users.ALL,ZohoCRM.modules.ALL,ZohoCRM.settings.ALL',
            'client_id' => $this->clientId,
            'access_type' => 'offline',
            'redirect_uri' => $this->callBackUrl,
            'response_type' => 'code',
            // SECURITY (PRO-17): bind the OAuth response to the initiating admin session so a
            // forged callback (attacker's code) cannot graft the attacker's account onto the site.
            'state' => wp_create_nonce('ff_zohocrm_oauth'),
        ], $this->accountUrl . '/oauth/v2/auth');

        wp_redirect($url);
        exit();
    }

    public function generateAccessToken($code, $settings)
    {
        $response = wp_remote_post($this->accountUrl . '/oauth/v2/token', [
            'body' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->callBackUrl,
                'code' => $code
            ]
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (isset($body['error_description'])) {
            return new \WP_Error('invalid_client', $body['error_description']);
        }

        $settings['access_token'] = $body['access_token'];
        $settings['refresh_token'] = $body['refresh_token'];
        $settings['expire_at'] = time() + intval($body['expires_in']);
        return $settings;
    }

    public function make_request($action, $data = array(), $method = 'GET')
    {
        $settings = $this->getApiSettings();
        if (is_wp_error($settings)) {
            return $settings;
        }

        $url = $this->apiUrl . $action;

        $response = false;
        $args = [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $settings['access_token']
            ]
        ];
        if ($method == 'GET') {
            $url = add_query_arg($data, $url);
            $response = wp_remote_get($url, $args);
        } else if ($method == 'POST') {
            $args['body'] = json_encode(['data' => [$data]]);
            $response = wp_remote_post($url, $args);
        }

        if (!$response) {
            return new \WP_Error('invalid', 'Request could not be performed');
        }
        if (is_wp_error($response)) {
            return new \WP_Error('wp_error', $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (isset($body['status']) && $body['status'] == 'error') {
            $message = $body['message'];
            return new \WP_Error('request_error', $message);
        }

        return $body;
    }

    protected function getApiSettings()
    {
        $this->maybeRefreshToken();

        $apiSettings = $this->settings;

        if (!$apiSettings['status'] || !$apiSettings['expire_at']) {
            return new \WP_Error('invalid', 'API key is invalid');
        }

        return array(
            'baseUrl' => $this->apiUrl,
            'version' => 'OAuth2',
            'clientKey' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'callback' => $this->callBackUrl,
            'access_token' => $apiSettings['access_token'],
            'refresh_token' => $apiSettings['refresh_token'],
            'expire_at' => $apiSettings['expire_at']
        );
    }

    protected function maybeRefreshToken()
    {
        $settings = $this->settings;
        $expireAt = $settings['expire_at'];
        if ($expireAt && $expireAt <= (time() - 10)) {
            // we have to regenerate the tokens
            $response = wp_remote_post($this->accountUrl . '/oauth/v2/token', [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $settings['refresh_token'],
                    'redirect_uri' => $this->callBackUrl
                ]
            ]);

            if (is_wp_error($response)) {
                $settings['status'] = false;
            }

            $body = wp_remote_retrieve_body($response);
            $body = \json_decode($body, true);
            if (isset($body['error_description'])) {
                $settings['status'] = false;
            }

            $settings['access_token'] = $body['access_token'];
            $settings['expire_at'] = time() + intval($body['expires_in']);
            $this->settings = $settings;
            update_option('_fluentform_zohocrm_settings', $settings, 'no');
        }
    }

    public function getAllModules()
    {
        return $this->make_request('settings/modules', [], 'GET');
    }

    public function getAllFields($module_name)
    {
        return $this->make_request("settings/fields?module=$module_name", [], 'GET');
    }

    public function insertModuleData($module_name, $data)
    {
        $response = $this->make_request($module_name, $data, 'POST');

        if(!empty($response['data'][0]['details']['id'])){
            return $response;
        }
        $err_msg = 'Date insert failed';
        if($response['data'][0]['status'] == 'error'){
            $err_msg = $response['data'][0]['message'];
        }
        return new \WP_Error('error', $err_msg);
    }

    public function addTags($module_name, $recordId, $tags)
    {
        return $this->make_request("$module_name/$recordId/actions/add_tags?tag_names=$tags", null, 'POST');
    }

}