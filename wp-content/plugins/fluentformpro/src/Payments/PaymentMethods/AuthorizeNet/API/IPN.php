<?php

namespace FluentFormPro\Payments\PaymentMethods\AuthorizeNet\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentFormPro\Payments\PaymentMethods\AuthorizeNet\AuthorizeNetSettings;

class IPN
{
    /**
     * Validate an incoming Authorize.Net webhook request without side effects.
     * Safe to call from a REST permission_callback.
     *
     * @return true|\WP_Error True if the request body matches the signature, WP_Error otherwise.
     */
    public function validateRequest()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return new \WP_Error('invalid_method', __('Invalid request method. Only POST is allowed.', 'fluentformpro'), ['status' => 405]);
        }

        $post_data = file_get_contents('php://input');
        if (empty($post_data)) {
            return new \WP_Error('empty_post_data', __('Empty POST data received.', 'fluentformpro'), ['status' => 400]);
        }

        $headers = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
        if (!isset($headers['x-anet-signature'])) {
            return new \WP_Error('missing_signature', __('Missing X-ANET-Signature header.', 'fluentformpro'), ['status' => 401]);
        }

        $reqSignatureKey = $headers['x-anet-signature'];
        if (strpos($reqSignatureKey, 'sha512=') === 0) {
            $reqSignatureKey = substr($reqSignatureKey, strlen('sha512='));
        }

        $mrchntSignatureKey = AuthorizeNetSettings::getWebhookSignatureKey();
        if (empty($mrchntSignatureKey)) {
            return new \WP_Error('missing_signature_key', __('Webhook signature key is not configured in settings.', 'fluentformpro'), ['status' => 503]);
        }

        $generated_hash = hash_hmac('sha512', $post_data, $mrchntSignatureKey);
        if (!hash_equals(strtolower($generated_hash), strtolower($reqSignatureKey))) {
            return new \WP_Error('signature_mismatch', __('Webhook signature verification failed. Invalid signature.', 'fluentformpro'), ['status' => 403]);
        }

        $data = json_decode($post_data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('invalid_json', __('Invalid JSON payload: ', 'fluentformpro') . json_last_error_msg(), ['status' => 400]);
        }

        if (!property_exists($data, 'eventType')) {
            return new \WP_Error('missing_event_type', __('Webhook payload missing eventType property.', 'fluentformpro'), ['status' => 400]);
        }

        return true;
    }

    /**
     * Read and dispatch the IPN event after the signature has already been validated.
     *
     * Re-reads php://input (cheap; PHP buffers the body so it can be re-read on PHP 5.6+).
     * Use only after validateRequest() returned true OR via verifyIPN() which composes both.
     *
     * @return true|\WP_Error
     */
    public function dispatchFromRequest()
    {
        $post_data = file_get_contents('php://input');
        if (empty($post_data)) {
            return new \WP_Error('empty_post_data', __('Empty POST data received.', 'fluentformpro'));
        }
        $data = json_decode($post_data);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($data)) {
            return new \WP_Error('invalid_json', __('Invalid JSON payload.', 'fluentformpro'));
        }
        $this->handleIpn($data);
        return true;
    }

    /**
     * Verify and process an incoming Authorize.Net webhook.
     * Kept as a backward-compatible composite of validateRequest + dispatchFromRequest.
     *
     * @return true|\WP_Error True on success, WP_Error on failure
     */
    public function verifyIPN()
    {
        $validation = $this->validateRequest();
        if (is_wp_error($validation)) {
            return $validation;
        }
        return $this->dispatchFromRequest();
    }

    /**
     * Handle the IPN event by dispatching to appropriate action hook
     *
     * @param object $data Webhook payload data
     * @return void
     */
    protected function handleIpn($data)
    {
        // Check if payload and entityName exist
        if (!isset($data->payload->entityName)) {
            return;
        }

        $entityName = $data->payload->entityName;

        if (has_action('fluentform/handle_authorizenet_' . $entityName . '_ipn')) {
            do_action('fluentform/handle_authorizenet_' . $entityName . '_ipn', $data);
        }
    }
}
