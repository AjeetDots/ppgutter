<?php

namespace FluentFormPro\Integrations\Zapier;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Integrations\LogResponseTrait;
use FluentForm\Framework\Helpers\ArrayHelper;

trait NotifyTrait
{
    use LogResponseTrait;

    /**
     * SECURITY (PRO-16): the Zapier feed URL is a form-manager-controlled setting and was
     * fetched with the ordinary HTTP API and no host restriction (the intended
     * hooks.zapier.com base was declared but never enforced), so it was an arbitrary
     * server-side request — including a synchronous oracle via verifyEndpoint(). Only allow
     * the official Zapier catch-hook host over HTTPS.
     */
    private function isValidZapierUrl($url)
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        // COMPAT: filterable so an existing feed pointed at a Zapier host this list does not
        // anticipate (a new region/domain) keeps working without patching. Site PHP only — a form
        // manager cannot reach it, so the default-deny posture is preserved.
        $defaultHosts = ['hooks.zapier.com'];
        $allowedHosts = (array) apply_filters('fluentform/zapier_allowed_webhook_hosts', $defaultHosts, $url);
        $allowedHosts = array_map('strtolower', array_filter($allowedHosts, 'is_string'));
        // A filter returning nothing usable must not silently break every Zapier feed — fall back
        // to the official host so the default keeps working no matter what the filter returns.
        if (!$allowedHosts) {
            $allowedHosts = $defaultHosts;
        }

        return 'https' === $scheme && in_array($host, $allowedHosts, true);
    }

    public function notify($feed, $formData, $entry, $form)
    {
        try {
            $values = $feed['processedValues'];
            $payload = ['body' => $formData];
    
            $payload = apply_filters_deprecated(
                'fluentform_integration_data_zapier',
                [
                    $payload,
                    $feed,
                    $entry
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/integration_data_zapier',
                'Use fluentform/integration_data_zapier instead of fluentform_integration_data_zapier.'
            );
            $payload = apply_filters('fluentform/integration_data_zapier', $payload, $feed, $entry);
            if (!$this->isValidZapierUrl(ArrayHelper::get($values, 'url'))) {
                return new \WP_Error('invalid_url', __('Invalid Zapier webhook URL.', 'fluentformpro'));
            }
            $response = wp_safe_remote_post($values['url'], $payload);
            if (is_wp_error($response)) {
                $code = ArrayHelper::get($response, 'response.code');
                throw new \Exception($response->get_error_message() .', with response code: '.$code, (int)$response->get_error_code());
            } else {
                return $response;
            }
        } catch (\Exception $e) {
            return new \WP_Error('broke', $e->getMessage());
        }
    }


    public function verifyEndpoint()
    {
        $formId = intval($this->app->request->get('form_id'));

        $form = wpFluent()->table('fluentform_forms')->find($formId);

        $fields = array_map(function ($f) {
            return str_replace('.*', '', $f);
        }, array_keys(FormFieldsParser::getInputs($form)));

        $webHook = wpFluent()
            ->table($this->table)
            ->where('form_id', $formId)
            ->where('meta_key', $this->metaKey)
            ->first();

        $webHook = json_decode($webHook->value);

        $requestData = json_encode(
            array_combine($fields, array_fill(0, count($fields), ''))
        );

        $requestHeaders['Content-Type'] = 'application/json';

        $payload = [
            'body'    => $requestData,
            'method'  => 'POST',
            'headers' => $requestHeaders
        ];

        if (!isset($webHook->url) || !$this->isValidZapierUrl($webHook->url)) {
            wp_send_json_error(array(
                'message' => __('Invalid Zapier webhook URL.', 'fluentformpro')
            ), 400);
        }

        $response = wp_safe_remote_request($webHook->url, $payload);

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => $response->get_error_message()
            ), 400);
        }

        wp_send_json_success(array(
            'message' => __('Sample sent successfully.', 'fluentformpro'),
        ));
    }
}
