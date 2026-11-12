<?php

namespace FluentFormPro\Integrations\GoogleSheet\API;

use FluentFormPro\Services\RemoteAuth;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    private $optionKey = '_fluentform_google_sheet_settings';

    public function makeRequest($url, $bodyArgs, $type = 'GET', $headers = false)
    {
        if (!$headers) {
            $headers = array(
                'Content-Type' => 'application/http',
                'Content-Transfer-Encoding' => 'binary',
                'MIME-Version' => '1.0',
            );
        }

        $args = [
            'headers' => $headers,
        ];
        if ($bodyArgs) {
            $args['body'] = json_encode($bodyArgs);
        }

        $args['method'] = $type;
        $request = wp_remote_request($url, $args);

        if (is_wp_error($request)) {
            $message = $request->get_error_message();
            return new \WP_Error(423, $message);
        }

        $body = json_decode(wp_remote_retrieve_body($request), true);

        if (!empty($body['error'])) {
            $error = 'Unknown Error';
            if (isset($body['error_description'])) {
                $error = $body['error_description'];
            } elseif (!empty($body['error']['message'])) {
                $error = $body['error']['message'];
            }
            return new \WP_Error(423, $error);
        }

        return $body;
    }

    public function getAccessToken()
    {
        $tokens = get_option($this->optionKey);

        if (empty($tokens['access_token'])) {
            return false;
        }

        if (($tokens['created_at'] + $tokens['expires_in'] - 30) < time()) {
            $refreshed = $this->refreshToken($tokens);

            if (is_wp_error($refreshed)) {
                if ('ff_auth_reconnect' === $refreshed->get_error_code()) {
                    update_option($this->optionKey, ['status' => false], 'no');
                    RemoteAuth::resetClockState();
                }

                return false;
            }

            $tokens['access_token'] = $refreshed['access_token'];
            $tokens['expires_in'] = $refreshed['expires_in'];
            $tokens['created_at'] = time();
            // A wrapped token replaces the raw one; a site refreshing with its own Google app keeps the raw one.
            if (!empty($refreshed['wrapped_refresh_token'])) {
                $tokens['wrapped_refresh_token'] = $refreshed['wrapped_refresh_token'];
                unset($tokens['refresh_token']);
            }
            update_option($this->optionKey, $tokens, 'no');
        }

        return $tokens['access_token'];
    }

    private function refreshToken($tokens)
    {
        if (!empty($tokens['wrapped_refresh_token'])) {
            return RemoteAuth::refreshAuth('google', 'sheets', $tokens['wrapped_refresh_token']);
        }

        if (empty($tokens['refresh_token'])) {
            return new \WP_Error('ff_auth_reconnect', __('Your Google Sheets connection needs to be reconnected.', 'fluentformpro'));
        }

        $ownClient = $this->siteOwnClient($tokens);
        if ($ownClient) {
            return $this->refreshWithOwnClient($tokens['refresh_token'], $ownClient);
        }

        // Pre-update connection on a Fluent Forms app: migrated once (worker docs/CONTRACT.md §8).
        return RemoteAuth::migrateGoogleAuth($tokens['refresh_token'], home_url());
    }

    // A site that set its own Google app in wp-config keeps refreshing with it; the service doesn't hold that secret.
    // Same constants the old plugin read: tokens with 'version' came from FF_GSHEET_*, older ones from FF_GSHEET_LEGACY_*.
    private function siteOwnClient($tokens)
    {
        $prefix = isset($tokens['version']) ? 'FF_GSHEET_' : 'FF_GSHEET_LEGACY_';
        $id = defined($prefix . 'CLIENT_ID') ? constant($prefix . 'CLIENT_ID') : '';
        $secret = defined($prefix . 'CLIENT_SECRET') ? constant($prefix . 'CLIENT_SECRET') : '';

        return ($id && $secret) ? [$id, $secret] : null;
    }

    private function refreshWithOwnClient($refreshToken, $client)
    {
        $result = $this->makeRequest('https://accounts.google.com/o/oauth2/token', [
            'client_id'     => $client[0],
            'client_secret' => $client[1],
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ], 'POST');

        if (is_wp_error($result) || empty($result['access_token'])) {
            return new \WP_Error(423, __('Could not refresh the Google Sheets connection.', 'fluentformpro'));
        }

        return [
            'access_token' => $result['access_token'],
            'expires_in'   => isset($result['expires_in']) ? $result['expires_in'] : 3600,
        ];
    }
}
