<?php

namespace FluentFormPro\Integrations\WebHook;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\Orders\OrderData;
use FluentForm\App\Modules\Payments\PaymentHelper;

trait NotifyTrait
{
    public function notify($feed, $formData, $entry, $form)
    {
        $settings = $feed['processedValues'];

        try {
            $requestHeaders = $this->getWebHookRequestHeaders($settings, $formData, $form, $entry->id);

            $requestMethod = $this->getWebHookRequestMethod($settings, $formData, $form, $entry->id);

            $requestData = $this->getWebHookRequestData($feed, $formData, $form, $entry);

            $requestUrl = $this->getWebHookRequestUrl(
                $settings, $formData, $form, $entry->id, $requestMethod, $requestData
            );

            $requestFormat = $settings['request_format'];
            if (in_array($requestMethod, ['POST', 'PUT', 'PATCH']) && $requestFormat == 'JSON') {
                $requestHeaders['Content-Type'] = 'application/json';
                $requestData = json_encode($requestData);
            }

            $sslVerify = apply_filters_deprecated(
                'ff_webhook_ssl_verify',
                [
                    false
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/webhook_ssl_verify',
                'Use fluentform/webhook_ssl_verify instead of ff_webhook_ssl_verify.'
            );

            $payload = [
                'body'      => !in_array($requestMethod, ['GET', 'DELETE']) ? $requestData : null,
                'method'    => $requestMethod,
                'headers'   => $requestHeaders,
                'sslverify' => apply_filters('fluentform/webhook_ssl_verify', $sslVerify),
                'reject_unsafe_urls' => true,
            ];

            $payload = apply_filters_deprecated(
                'fluentform_webhook_request_args',
                [
                    $payload,
                    $settings,
                    $formData,
                    $form,
                    $entry->id
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/webhook_request_args',
                'Use fluentform/webhook_request_args instead of fluentform_webhook_request_args.'
            );

            $payload = apply_filters(
                'fluentform/webhook_request_args',
                $payload, $settings, $formData, $form, $entry->id
            );

            $response = wp_safe_remote_request($requestUrl, $payload);

            if (is_wp_error($response)) {
                $code = ArrayHelper::get($response, 'response.code');
                throw new \Exception($response->get_error_message() . ', with response code: ' . $code,
                    (int)$response->get_error_code());
            } else {
                return $response;
            }
        } catch (\Exception $e) {
            return new \WP_Error('broke', $e->getMessage());
        }
    }

    protected function getWebHookRequestMethod($settings, $data, $form, $entryId)
    {
        $method = $settings['request_method'];

        $method = apply_filters_deprecated(
            'fluentform_webhook_request_method',
            [
                $method,
                $settings,
                $data,
                $form,
                $entryId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/webhook_request_method',
            'Use fluentform/webhook_request_method instead of fluentform_webhook_request_method.'
        );

        $method = apply_filters(
            'fluentform/webhook_request_method',
            $method, $settings, $data, $form, $entryId
        );

        return strtoupper($method);
    }

    protected function getWebHookRequestHeaders($settings, $data, $form, $entryId)
    {
        if ($settings['with_header'] == 'nop') {
            return [];
        }

        $parsedHeaders = $settings['request_headers'];

        $requestHeaders = [];
        foreach ($parsedHeaders as $header) {
            $requestHeaders[$header['key']] = $header['value'];
        }

        $requestHeaders = apply_filters_deprecated(
            'fluentform_webhook_request_headers',
            [
                $requestHeaders,
                $settings,
                $data,
                $form,
                $entryId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/webhook_request_headers',
            'Use fluentform/webhook_request_headers instead of fluentform_webhook_request_headers.'
        );

        $requestHeaders = apply_filters(
            'fluentform/webhook_request_headers',
            $requestHeaders, $settings, $data, $form, $entryId
        );

        unset($requestHeaders[null]);

        return $requestHeaders;
    }

    protected function getWebHookRequestData($feed, $data, $form, $entry)
    {
        $settings = $feed['processedValues'];
        // Remove standard WordPress and FluentForm internal fields
        $excludeFields = [
            '_wp_http_referer',
            '__fluent_form_embded_post_id'
        ];

        // Remove dynamic nonce fields that match the pattern '_fluentform_id_fluentformnonce'
        foreach ($data as $key => $value) {
            if (preg_match('/^_fluentform_\d+_fluentformnonce$/', $key)) {
                $excludeFields[] = $key;
            }
        }

        $formData = ArrayHelper::except($data, $excludeFields);

        $selectedData = [];
        if ($settings['request_body'] == 'all_fields') {
            $selectedData = $formData;
            $entry->user_inputs = Helper::replaceBrTag($entry->user_inputs);
            $submission = clone $entry;
            unset($submission->response);

            // Format payment amounts in submission data for webhook
            if ($entry->payment_total) {
                $submission->payment_total = PaymentHelper::formatMoney($submission->payment_total, $submission->currency);
                if ($submission->total_paid) {
                    $submission->total_paid = PaymentHelper::formatMoney($submission->total_paid, $submission->currency);
                }
            }

            $selectedData['__submission'] = $submission;
            if ($entry->payment_total) {
                $selectedData['__order_items'] = OrderData::getOrderItems($entry);
                $transactions = OrderData::getTransactions($entry->id);

                // Format payment amounts in transaction data for webhook
                foreach ($transactions as $transaction) {
                    if ($transaction->payment_total) {
                        $transaction->payment_total = PaymentHelper::formatMoney($transaction->payment_total, $transaction->currency);
                    }
                }

                $selectedData['__transactions'] = $transactions;
            }
        } else {
            foreach ($settings['fields'] as $index => $input) {
                if ($name = Helper::getInputNameFromShortCode(ArrayHelper::get($feed, "settings.fields.$index.value",
                    ''))) {
                    $hasRepeaterOrGridField = FormFieldsParser::getField($form, ['repeater_field', 'tabular_grid'],
                        $name);
                    if ($hasRepeaterOrGridField && $value = ArrayHelper::get($formData, $name)) {
                        $input['value'] = $value;
                    }
                }
                $input['value'] = Helper::replaceBrTag($input['value']);
                if ("[]" == substr($input['key'], -2)) {
                    // merge array data for same key inserting '[]' at last key
                    $input['key'] = substr($input['key'], 0, -2);
                    $selectedData[$input['key']][] = $input['value'];
                } else {
                    $selectedData[$input['key']] = $input['value'];
                }
            }
        }

        $selectedData = apply_filters_deprecated(
            'fluentform_webhook_request_data',
            [
                $selectedData,
                $settings,
                $data,
                $form,
                $entry
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/webhook_request_data',
            'Use fluentform/webhook_request_data instead of fluentform_webhook_request_data.'
        );

        return apply_filters(
            'fluentform/webhook_request_data',
            $selectedData, $settings, $data, $form, $entry
        );
    }

    protected function getWebHookRequestUrl($settings, $data, $form, $entryId, $requestMethod, $requestData)
    {
        $url = $settings['request_url'];

        if (in_array($requestMethod, ['GET', 'DELETE']) && !empty($requestData)) {
            $url = add_query_arg($requestData, $url);
        }

        $url = apply_filters_deprecated(
            'fluentform_webhook_request_url',
            [
                $url,
                $settings,
                $data,
                $form,
                $entryId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/webhook_request_url',
            'Use fluentform/webhook_request_url instead of fluentform_webhook_request_url.'
        );

        return apply_filters(
            'fluentform/webhook_request_url',
            $url, $settings, $data, $form, $entryId
        );
    }
}
