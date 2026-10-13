<?php

namespace FluentFormPro\Integrations\CleverReach;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class API
{
    protected $clientId = null;

    protected $clientSecret = null;

    protected $accessToken = null;

    protected $callBackUrl = null;

    protected $settings = [];

    protected $optionKey = null;

    // Valid only between a subscribe() call and the next one on this instance.
    protected $resubscribed = false;

    protected $tokenRefreshed = false;

    public function __construct($settings)
    {
        $this->clientId = isset($settings['client_id']) ? $settings['client_id'] : '';
        $this->clientSecret = isset($settings['client_secret']) ? $settings['client_secret'] : '';
        $this->accessToken = isset($settings['access_token']) ? $settings['access_token'] : '';
        $this->callBackUrl = admin_url('?ff_cleverreach_auth');
        $this->settings = $settings;
        if (isset($settings['option_key'])) {
            $this->optionKey = $settings['option_key'];
        }
    }

    public function redirectToAuthServer()
    {
        // SECURITY (PRO-17): bind the OAuth response to the initiating admin session so a
        // forged callback (attacker's code) cannot graft the attacker's account onto the site.
        $url = 'https://rest.cleverreach.com/oauth/authorize.php?client_id=' . $this->clientId . '&grant=basic&response_type=code&state=' . rawurlencode(wp_create_nonce('ff_cleverreach_oauth')) . '&redirect_uri=' . $this->callBackUrl;

        wp_redirect($url);
        exit();
    }

    public function checkForClientId()
    {
        $url = 'https://rest.cleverreach.com/oauth/authorize.php?client_id=' . $this->clientId . '&grant=basic&response_type=code&redirect_uri=' . $this->callBackUrl;
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (isset($body['error_description'])) {
            return new \WP_Error('invalid_client', $body['error_description']);
        }
    }

    public function generateAccessToken($code, $settings)
    {
        $response = wp_remote_post('https://rest.cleverreach.com/oauth/token.php', [
            'body' => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->callBackUrl,
                'code'          => $code
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

    protected function getApiSettings()
    {
        $this->maybeRefreshToken();

        if (is_wp_error($this->settings)) {
            return $this->settings;
        }

        if (!isset($this->settings['status']) || !$this->settings['status']) {
            return new \WP_Error('invalid', __('API key is invalid', 'fluentformpro'));
        }

        if (empty($this->settings['access_token'])) {
            return new \WP_Error('invalid', __('Access token is missing', 'fluentformpro'));
        }

        return [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'callback'      => $this->callBackUrl,
            'access_token'  => $this->settings['access_token'],
            'refresh_token' => isset($this->settings['refresh_token']) ? $this->settings['refresh_token'] : ''
        ];
    }

    protected function maybeRefreshToken()
    {
        // Defensive, and currently unreachable: settings only becomes a WP_Error
        // inside this method, after tokenRefreshed is set, so the guard below
        // already short-circuits re-entry. Kept because the array access on the
        // next line throws on a WP_Error, and \Error is not caught by the
        // callers' \Exception handlers -- it would white-screen, not degrade.
        if (is_wp_error($this->settings)) {
            return;
        }

        // getApiSettings() runs on every makeRequest(), so without this a
        // multi-call flow pays a token POST and an option write per call.
        if ($this->tokenRefreshed) {
            return;
        }

        if (empty($this->settings['refresh_token'])) {
            return;
        }

        $this->tokenRefreshed = true;

        $response = wp_remote_post('https://rest.cleverreach.com/oauth/token.php', [
            'body' => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->settings['refresh_token'],
                'redirect_uri'  => $this->callBackUrl
            ]
        ]);

        if (is_wp_error($response)) {
            $this->settings = $response;
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (isset($body['error_description'])) {
            $this->settings = new \WP_Error('invalid_client', $body['error_description']);
            return;
        }

        if (!isset($body['access_token']) || !isset($body['refresh_token'])) {
            // Deliberately not an error: the existing access token is usually
            // still valid (they last a year), and failing here would break feeds
            // that would otherwise have succeeded. A genuinely dead token now
            // surfaces as "HTTP 401" from the request itself.
            return;
        }

        $this->settings['access_token'] = $body['access_token'];
        $this->settings['refresh_token'] = $body['refresh_token'];
        $this->accessToken = $body['access_token'];

        if ($this->optionKey) {
            $existing = get_option($this->optionKey, []);
            if (!is_array($existing)) {
                $existing = [];
            }
            $existing['access_token'] = $body['access_token'];
            $existing['refresh_token'] = $body['refresh_token'];
            if (isset($body['expires_in'])) {
                $existing['expire_at'] = time() + intval($body['expires_in']);
            }
            update_option($this->optionKey, $existing, 'no');
        }
    }

    public function makeRequest($url, $bodyArgs, $type = 'GET', $headers = false)
    {
        $apiSettings = $this->getApiSettings();
        if (is_wp_error($apiSettings)) {
            return $apiSettings;
        }

        $this->accessToken = $apiSettings['access_token'];

        if (!is_array($headers)) {
            $headers = [];
        }

        $headers['Content-type'] = 'application/x-www-form-urlencoded';
        $headers['Authorization'] = 'Bearer ' . $this->accessToken;

        $args = [
            'headers' => $headers
        ];

        if ($bodyArgs) {
            $args['body'] = $bodyArgs;
        }

        $args['method'] = $type;

        $request = wp_remote_request($url, $args);

        if (is_wp_error($request)) {
            $message = $request->get_error_message();
            return new \WP_Error(423, $message);
        }

        $status = intval(wp_remote_retrieve_response_code($request));
        $body = json_decode(wp_remote_retrieve_body($request), true);

        $error = null;
        if (!empty($body['error'])) {
            $error = 'Unknown Error';
            if (isset($body['error_description'])) {
                $error = $body['error_description'];
            } elseif (!empty($body['error']['message'])) {
                $error = $body['error']['message'];
            }
        } elseif (!isset($body['errors']) && ($status >= 300 || ($status > 0 && $status < 200))) {
            // A gateway page or a 500 without an `error` key decodes to null,
            // which judging by the body alone would read as success. An `errors`
            // payload is left alone so guardResponseErrors() can keep its detail
            // -- replacing it with a bare status line loses the message and
            // stops a duplicate reported that way from being recognised.
            $error = trim('HTTP ' . $status . ' ' . wp_remote_retrieve_response_message($request));
        }

        if (null !== $error) {
            return new \WP_Error(423, $error, ['status' => $status]);
        }

        return $body;
    }

    /**
     * Resets the resubscribed flag; read it with hasResubscribed() on this same
     * instance immediately after this returns.
     *
     * @param array $subscriber  may carry registered/activated to override the defaults
     * @param bool  $resubscribe activate an existing receiver instead of failing
     * @return mixed|\WP_Error
     */
    public function subscribe($subscriber, $resubscribe = false)
    {
        $this->resubscribed = false;

        // Omitting ALL of registered/activated/deactivated yields an activated
        // receiver; supplying only some of them does not (createreceiver docs,
        // 2026-09). Send both explicitly so a later caller adding `registered`
        // alone cannot silently start creating inactive receivers. Adding
        // `deactivated` still deactivates -- this does not guard that.
        if (!isset($subscriber['registered'])) {
            $subscriber['registered'] = time();
        }

        if (!isset($subscriber['activated'])) {
            $subscriber['activated'] = $subscriber['registered'];
        }

        $response = $this->makeRequest(
            'https://rest.cleverreach.com/v3/groups/' . $subscriber['list_id'] . '/receivers',
            $subscriber,
            'POST'
        );

        // Failures arrive two ways: an `error` key (already a WP_Error here) and
        // an `errors` key on a 200. Normalise so both reach the same branch.
        if (!is_wp_error($response)) {
            $response = $this->guardResponseErrors($response);
        }

        // Returning a duplicate rejection as-is leaves someone who had
        // unsubscribed deactivated, and logs the feed failed.
        if (is_wp_error($response) && $resubscribe && $this->isDuplicateAddress($response)) {
            return $this->reactivateExisting($subscriber['list_id'], $subscriber['email'], $response);
        }

        return $response;
    }

    /**
     * Whether the last subscribe() on THIS instance activated an existing
     * receiver. False before subscribe() runs, and false if activation failed.
     *
     * @return bool
     */
    public function hasResubscribed()
    {
        return $this->resubscribed;
    }

    /**
     * Activate an existing receiver, reporting failure with the context that led
     * here -- otherwise the log shows only the activate error, with nothing to
     * say a reactivation was attempted.
     *
     * @param \WP_Error $createError the duplicate-address error that triggered this
     * @return mixed|\WP_Error
     */
    protected function reactivateExisting($listId, $email, $createError)
    {
        $response = $this->activateReceiver($listId, $email);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'cleverreach_resubscribe_failed',
                sprintf(
                    /* translators: 1: email address, 2: error reported by CleverReach */
                    __('CleverReach reported %1$s is already in this list, but reactivating it failed: %2$s', 'fluentformpro'),
                    $email,
                    $response->get_error_message()
                ),
                ['create_error' => $createError->get_error_message()]
            );
        }

        return $response;
    }

    /**
     * Passes the email as the {receiver} path segment instead of the numeric id,
     * avoiding a lookup. CleverReach does not document this for /activate -- it
     * is inferred from the sibling :pool_id endpoints and the rdoepner client,
     * and is UNVERIFIED. If activation starts failing as not-found, look here
     * first: the fallback is GET the receiver by email, then activate by id.
     *
     * @return mixed|\WP_Error
     */
    protected function activateReceiver($listId, $email)
    {
        $response = $this->makeRequest(
            'https://rest.cleverreach.com/v3/groups/' . $listId . '/receivers/' . rawurlencode($email) . '/activate',
            null,
            'PUT'
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $response = $this->guardResponseErrors($response);

        if (is_wp_error($response)) {
            return $response;
        }

        // Success was being inferred from the absence of an error, so a bare
        // `false` -- a plausible answer from an endpoint whose email-as-id form
        // is unverified -- was reported as a completed reactivation.
        if (false === $response || (is_array($response) && isset($response['success']) && !$response['success'])) {
            return new \WP_Error(
                'cleverreach_activate_unconfirmed',
                sprintf(
                    /* translators: %s: email address */
                    __('CleverReach did not confirm reactivating %s; the contact may still be unsubscribed.', 'fluentformpro'),
                    $email
                )
            );
        }

        $this->resubscribed = true;

        return $response;
    }

    /**
     * Matches free text because CleverReach documents no error code for this.
     * If the wording changes this returns false and the feed logs the failure,
     * as it did before the resubscribe option existed.
     *
     * @return bool
     */
    protected function isDuplicateAddress($error)
    {
        return stripos($error->get_error_message(), 'duplicate address') !== false;
    }

    /**
     * CleverReach reports some failures in an `errors` key on an otherwise
     * successful response.
     *
     * @param mixed $response
     * @return mixed returns a WP_Error when the payload carries an `errors` key
     */
    protected function guardResponseErrors($response)
    {
        if (isset($response['errors'])) {
            $errorMessage = is_array($response['errors']) ? json_encode($response['errors']) : $response['errors'];
            return new \WP_Error('error', $errorMessage);
        }

        return $response;
    }
}
