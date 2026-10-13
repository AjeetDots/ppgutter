<?php

namespace FluentFormPro\Integrations\Amocrm;

use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    protected $clientId = null;

    protected $clientSecret = null;

    protected $refererUrl = null;

    protected $redirectUrl = null;

    protected $accessToken = null;

    protected $settings = [];

    public function __construct($settings)
    {
        $this->clientId = $settings['client_id'];
        $this->clientSecret = $settings['client_secret'];
        $this->accessToken = $settings['access_token'];
        $this->redirectUrl = admin_url('?ff_amocrm_auth=true');
        $this->settings = $settings;
    }

    public function redirectToAuthServer()
    {
        $accountUrl = isset($this->settings['account_url']) ? $this->settings['account_url'] : '';
        $oauthDomain = $this->getOAuthDomain($accountUrl);

        // SECURITY (PRO-17): bind the OAuth response to the initiating admin session so a
        // forged callback (attacker's code) cannot graft the attacker's account onto the site.
        $url = 'https://www.' . $oauthDomain . '/oauth?client_id=' . $this->clientId . '&state=' . rawurlencode(wp_create_nonce('ff_amocrm_oauth')) . '&mode=post_message';

        wp_redirect($url);
        exit();
    }

    protected function getOAuthDomain($accountUrl)
    {
        $host = $this->extractHost($accountUrl);

        if ($host) {
            $allowedDomains = ['amocrm.ru', 'amocrm.com', 'kommo.com'];
            foreach ($allowedDomains as $domain) {
                $len = strlen($domain);
                if (substr($host, -$len) === $domain) {
                    return $domain;
                }
            }
        }

        return 'kommo.com';
    }

    protected function extractHost($url)
    {
        $url = trim($url);
        if (!$url) {
            return '';
        }

        // Add scheme if missing so parse_url can extract the host
        if (strpos($url, '//') === false) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host ? strtolower($host) : '';
    }

    public function generateAccessToken($code, $referer, $settings)
    {
        $this->refererUrl = $referer;
        $response = wp_remote_post('https://'. $this->refererUrl . '/oauth2/access_token',
            [
                'body' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $this->redirectUrl,
                    'code'          => $code
                ]
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400 && $code <= 403) {
            return new \WP_Error($body['status'], $body['title'] . ': ' . $body['hint']);
        }

        $settings['access_token'] = $body['access_token'];
        $settings['refresh_token'] = $body['refresh_token'];
        $settings['referer_url'] = $referer;
        $settings['expire_at'] = time() + intval($body['expires_in']);

        if (empty($settings['account_url'])) {
            $settings['account_url'] = $this->extractHost($referer);
        }

        return $settings;
    }

    protected function getApiSettings()
    {
        $refresh = $this->maybeRefreshToken();

        if(is_wp_error($refresh)) {
            $code = $refresh->get_error_code();
            $message = $refresh->get_error_message();
            return new \WP_Error($code, $message);
        }

        $apiSettings = $this->settings;

        if (!$apiSettings['status'] || !$apiSettings['expire_at']) {
            return new \WP_Error('Invalid', __('API key is invalid', 'fluentformpro'));
        }

        return [
            'client_id'         => $this->clientId,
            'client_secret'     => $this->clientSecret,
            'redirect_url'      => $this->redirectUrl,
            'access_token'      => $this->settings['access_token'],
            'refresh_token'     => $this->settings['refresh_token'],
            'referer_url'       => $this->settings['referer_url']
        ];
    }

    protected function maybeRefreshToken()
    {
        $settings = $this->settings;
        $expireAt = $settings['expire_at'];

        if ($expireAt && $expireAt <= (time() - 30)) {
            $response = wp_remote_post('https://'. $settings['referer_url'] . '/oauth2/access_token', [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->settings['refresh_token'],
                    'redirect_uri' => $this->redirectUrl
                ]
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $body = \json_decode($body, true);
            $code = wp_remote_retrieve_response_code($response);

            if ($code >= 400 && $code <= 403) {
                return new \WP_Error($body['status'], $body['title'] . ': ' . $body['detail']);
            }

            $this->settings['access_token'] = $body['access_token'];
            $this->settings['refresh_token'] = $body['refresh_token'];
        }
    }

    public function makeRequest($url, $bodyArgs, $type = 'GET')
    {
        $apiSettings = $this->getApiSettings();

        if (is_wp_error($apiSettings)) {
            $code = $apiSettings->get_error_code();
            $message = $apiSettings->get_error_message();
            return new \WP_Error($code, $message);
        }

        $this->accessToken = $apiSettings['access_token'];

        $request = [];
        if ($type == 'GET') {
            $request = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => " Bearer ". $this->accessToken,
                ]
            ]);
        }

        if ($type == 'POST') {
            $request = wp_remote_post($url, [
                'headers' => [
                    'Authorization' => " Bearer ". $this->accessToken,
                    'Content-Type' => 'application/json'
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

        if ($code >= 200 && $code <= 299) {
            return $body;
        }
        elseif ($code == 401 || $code == 403) {
            return new \WP_Error($code, $body['title'] . ': ' .$body['detail']);
        }
        else {
            $errors = $body['validation-errors'][0]['errors'][0];
            return new \WP_Error($code, $errors['path'] . ' is ' .$errors['code']. '. Hint: ' .$errors['detail']);
        }
    }

    public function subscribe($subscriber)
    {
        $url = 'https://' . $this->settings['referer_url'] . '/api/v4/' . $subscriber['list_id'];

        if(ArrayHelper::get($subscriber, 'type')) {
            $url = 'https://' . $this->settings['referer_url'] . '/api/v4/catalogs/' . $subscriber['list_id'] . '/' .$subscriber['type'];
        }

        if(ArrayHelper::get($subscriber, 'entity_id')) {
            $url = 'https://' . $this->settings['referer_url'] . '/api/v4/' . $subscriber['entity_id'] . '/tags';
        }

        $post = \json_encode([$subscriber['attributes']], JSON_NUMERIC_CHECK);

        return $this->makeRequest($url, $post, 'POST');
    }
}
