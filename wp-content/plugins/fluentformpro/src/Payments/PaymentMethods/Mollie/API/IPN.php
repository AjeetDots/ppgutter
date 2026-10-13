<?php

namespace FluentFormPro\Payments\PaymentMethods\Mollie\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\Mollie\MollieSettings;
class IPN
{
    public function verifyIPN()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        // Mollie sends payment ID in POST body
        $data = $_POST;
        if (empty($data)) {
            $postData = file_get_contents('php://input');
            if ($postData) {
                parse_str($postData, $data);
            }
        }

        if (empty($data)) {
            return;
        }

        $data = wp_parse_args($data, $_REQUEST);
        $this->handleIpn($data);
        exit(200);
    }

    protected function handleIpn($data)
    {
        $submissionId = intval(ArrayHelper::get($data, 'submission_id'));
        if (!$submissionId || empty($data['id'])) {
            return;
        }
        $submission = wpFluent()->table('fluentform_submissions')->where('id', $submissionId)->first();


        if (!$submission) {
            return;
        }
        $vendorId = sanitize_text_field($data['id']);

        // SECURITY (PRO-07): this public webhook took both submission_id and the vendor payment
        // id from the request and never checked the id was the one recorded for this submission
        // at checkout (_mollie_payment_id). Bind them, so a real payment id from another order
        // cannot be posted with an arbitrary submission_id to force it paid. Fail closed when no
        // Mollie payment was recorded for the submission.
        $storedPaymentId = \FluentForm\App\Helpers\Helper::getSubmissionMeta($submission->id, '_mollie_payment_id');
        if (!$storedPaymentId || !hash_equals((string) $storedPaymentId, (string) $vendorId)) {
            return false;
        }

        $vendorTransaction = $this->makeApiCall('payments/'.$vendorId, [], $submission->form_id, 'GET');

        if(is_wp_error($vendorTransaction)) {
            $logData = [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => __('Mollie Payment Webhook Error', 'fluentformpro'),
                'description'      => $vendorTransaction->get_error_message()
            ];

            do_action('fluentform/log_data', $logData);
            return false;
        }

        $status = $vendorTransaction['status'];

        // Handle refunds first
        $refundedAmount = $refundAmount = ArrayHelper::get($vendorTransaction, 'amountRefunded.value');
        if (intval($refundedAmount)) {
            $refundAmount = intval($refundAmount * 100); // in cents
            do_action('fluentform/ipn_mollie_action_refunded', $refundAmount, $submission, $vendorTransaction, $data);
        } else {
            // Only process payment status other than refund
            do_action('fluentform/ipn_mollie_action_' . $status, $submission, $vendorTransaction, $data);
        }
    }

    public function makeApiCall($path, $args, $formId, $method = 'GET')
    {
        $apiKey = MollieSettings::getApiKey($formId);
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey
        ];
        if($method == 'POST') {
            $response = wp_remote_post('https://api.mollie.com/v2/'.$path, [
                'headers' => $headers,
                'body' => $args
            ]);
        } else {
            $response = wp_remote_get('https://api.mollie.com/v2/'.$path, [
                'headers' => $headers,
                'body' => $args
            ]);
        }

        if(is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);
        if(empty($responseData['id'])) {
            $message = ArrayHelper::get($responseData, 'detail');
            if(!$message) {
                $message = ArrayHelper::get($responseData, 'error.message');
            }
            if(!$message) {
                $message = __('Unknown Mollie API request error', 'fluentformpro');
            }

            return new \WP_Error(423, $message, $responseData);
        }

        return $responseData;
    }
}
