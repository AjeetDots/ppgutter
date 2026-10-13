<?php

namespace FluentFormPro\Payments;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Database\Migrations\Submissions;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\Classes\CouponModel;
use FluentFormPro\Payments\Classes\PaymentManagement;
use FluentFormPro\Payments\Migrations\Migration;
use FluentFormPro\Payments\PaymentMethods\Offline\OfflineProcessor;
use FluentFormPro\Payments\PaymentMethods\Stripe\ConnectConfig;

class AjaxEndpoints
{
    public function handleEndpoint($route)
    {
        $validRoutes = [
            'enable_payment'               => 'enablePaymentModule',
            'update_global_settings'       => 'updateGlobalSettings',
            'get_payment_method_settings'  => 'getPaymentMethodSettings',
            'save_payment_method_settings' => 'savePaymentMethodSettings',
            'get_form_settings'            => 'getFormSettings',
            'save_form_settings'           => 'saveFormSettings',
            'update_transaction'           => 'updateTransaction',
            'get_coupons'                  => 'getCoupons',
            'enable_coupons'               => 'enableCoupons',
            'save_coupon'                  => 'saveCoupon',
            'delete_coupon'                => 'deleteCoupon',
            'get_stripe_connect_config'    => 'getStripeConnectConfig',
            'disconnect_stripe_connection' => 'disconnectStripeConnect',
            'get_pages'                    => 'getWpPages',
            'cancel_subscription'          => 'cancelSubscription'
        ];

        if (isset($validRoutes[$route])) {
            $this->{$validRoutes[$route]}();
        }

        die();
    }

    public function enablePaymentModule()
    {
        $this->upgradeDb();
        // Update settings
        $settings = PaymentHelper::updatePaymentSettings([
            'status' => 'yes'
        ]);
        // send response to reload the page

        wp_send_json_success([
            'message'  => __('Payment Module successfully enabled!', 'fluentformpro'),
            'settings' => $settings,
            'reload'   => 'yes'
        ]);
    }

    private function upgradeDB()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_transactions';
        $cols = $wpdb->get_col("DESC {$table}", 0);

        if ($cols && in_array('subscription_id', $cols) && in_array('transaction_hash', $cols)) {
            // We are good
        } else {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
            Migration::migrate();
            // Migrate the database
            Submissions::migrate(true); // Add payment_total
        }
    }

    public function updateGlobalSettings()
    {
        $settings = wp_unslash($_REQUEST['settings']);

        if (is_array($settings)) {
            $sanitizeMap = [
                'status'                       => 'sanitize_text_field',
                'currency'                     => 'sanitize_text_field',
                'currency_sign_position'       => 'sanitize_text_field',
                'currency_separator'           => 'sanitize_text_field',
                'decimal_points'               => 'intval',
                'business_name'                => 'sanitize_text_field',
                'business_logo'                => 'sanitize_url',
                'business_address'             => 'sanitize_textarea_field',
                'debug_log'                    => 'sanitize_text_field',
                'all_payments_page_id'         => 'intval',
                'receipt_page_id'              => 'intval',
                'user_can_manage_subscription' => 'sanitize_text_field',
            ];
            $settings = fluentform_backend_sanitizer($settings, $sanitizeMap);
        }

        // Update settings
        $settings = PaymentHelper::updatePaymentSettings($settings);

        // send response to reload the page
        wp_send_json_success([
            'message'  => __('Settings successfully updated!', 'fluentformpro'),
            'settings' => $settings,
            'reload'   => 'yes'
        ]);

    }

    public function getPaymentMethodSettings()
    {
        $method = sanitize_text_field($_REQUEST['method']);

        $paymentSettings = apply_filters_deprecated(
            'fluentform_payment_settings_' . $method,
            [
                []
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_settings_' . $method,
            'Use fluentform/payment_settings_' . $method . ' instead of fluentform_payment_settings_' . $method
        );

        $settings = apply_filters('fluentform/payment_settings_' . $method, $paymentSettings);

        // SECURITY (PRO-12): only Stripe masked its stored secret; every other gateway returned
        // live API secrets / access keys / the Authorize.Net webhook signing key in cleartext to
        // any settings_manager (including delegated non-admin roles). Mask all secret fields to the
        // same 'ENCRYPTED_KEY' sentinel; savePaymentMethodSettings() restores an unchanged secret.
        if (is_array($settings)) {
            foreach (self::secretSettingFields() as $secretField) {
                if (!empty($settings[$secretField]) && is_string($settings[$secretField]) && 'ENCRYPTED_KEY' !== $settings[$secretField]) {
                    $settings[$secretField] = 'ENCRYPTED_KEY';
                }
            }
        }

        wp_send_json_success([
            'settings' => ($settings) ? $settings : false
        ]);
    }

    /**
     * Secret-bearing global gateway setting keys that must never be returned in cleartext.
     */
    protected static function secretSettingFields()
    {
        return [
            'test_secret_key', 'live_secret_key',
            'test_api_secret', 'live_api_secret',
            'test_access_key', 'live_access_key',
            'webhook_signature_key', 'client_token',
        ];
    }

    public function savePaymentMethodSettings()
    {
        $method = sanitize_text_field($_REQUEST['method']);
        $settings = wp_unslash($_REQUEST['settings']);

        if (is_array($settings)) {
            $sanitizeMap = [
                'is_active'              => 'sanitize_text_field',
                'payment_mode'           => 'sanitize_text_field',
                'checkout_type'          => 'sanitize_text_field',
                'status'                 => 'sanitize_text_field',
                'provider'               => 'sanitize_text_field',
                'is_encrypted'           => 'sanitize_text_field',
                'transaction_type'       => 'sanitize_text_field',
                'test_publishable_key'   => 'sanitize_text_field',
                'test_secret_key'        => 'sanitize_text_field',
                'live_publishable_key'   => 'sanitize_text_field',
                'live_secret_key'        => 'sanitize_text_field',
                'test_api_key'           => 'sanitize_text_field',
                'test_api_secret'        => 'sanitize_text_field',
                'live_api_key'           => 'sanitize_text_field',
                'live_api_secret'        => 'sanitize_text_field',
                'test_account_id'        => 'sanitize_text_field',
                'live_account_id'        => 'sanitize_text_field',
                'test_application_id'    => 'sanitize_text_field',
                'live_application_id'    => 'sanitize_text_field',
                'test_access_key'        => 'sanitize_text_field',
                'live_access_key'        => 'sanitize_text_field',
                'test_location_id'       => 'sanitize_text_field',
                'live_location_id'       => 'sanitize_text_field',
                'client_token'           => 'sanitize_text_field',
                'webhook_signature_key'  => 'sanitize_text_field',
                'paypal_email'           => 'sanitize_email',
                'connection_mode'        => 'sanitize_text_field',
                'test_client_id'         => 'sanitize_text_field',
                'test_secret_key'        => 'sanitize_text_field',
                'live_client_id'         => 'sanitize_text_field',
                'live_secret_key'        => 'sanitize_text_field',
                'webhook_id'             => 'sanitize_text_field',
            ];
            $settings = fluentform_backend_sanitizer($settings, $sanitizeMap);

            // SECURITY (PRO-12): the read endpoint masks stored secrets as 'ENCRYPTED_KEY'. Restore
            // any secret field submitted as the mask (i.e. unchanged in the UI) from the stored
            // settings so the mask is never persisted over a real secret. Stripe keeps its own
            // established mask/restore flow.
            if ('stripe' !== $method) {
                $storedSettings = apply_filters('fluentform/payment_settings_' . $method, []);
                foreach (self::secretSettingFields() as $secretField) {
                    if (isset($settings[$secretField]) && 'ENCRYPTED_KEY' === $settings[$secretField]) {
                        $settings[$secretField] = (is_array($storedSettings) && isset($storedSettings[$secretField]))
                            ? $storedSettings[$secretField]
                            : '';
                    }
                }
            }
        }

        $settingsValidation = apply_filters_deprecated(
            'fluentform_payment_method_settings_validation_' . $method,
            [
                [],
                $settings
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_method_settings_validation_' . $method,
            'Use fluentform/payment_method_settings_validation_' . $method . ' instead of fluentform_payment_method_settings_validation_' . $method
        );

        $validationErrors = apply_filters('fluentform/payment_method_settings_validation_' . $method, $settingsValidation, $settings);

        if ($validationErrors) {
            wp_send_json_error([
                'message' => __('Failed to save settings', 'fluentformpro'),
                'errors'  => $validationErrors
            ], 423);
        }

        $settings = apply_filters_deprecated(
            'fluentform_payment_method_settings_save_' . $method,
            [
                $settings
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_method_settings_save_' . $method,
            'Use fluentform/payment_method_settings_save_' . $method . ' instead of fluentform_payment_method_settings_save_' . $method
        );

        $settings = apply_filters('fluentform/payment_method_settings_save_' . $method, $settings);

        update_option('fluentform_payment_settings_' . $method, $settings, 'yes');

        wp_send_json_success([
            'message' => __('Settings successfully updated', 'fluentformpro')
        ]);
    }

    public function getFormSettings()
    {
        $formId = intval($_REQUEST['form_id']);
        $settings = PaymentHelper::getFormSettings($formId, 'admin');
        $form = wpFluent()->table('fluentform_forms')->find($formId);
        $addressFields = array_values(FormFieldsParser::getAddressFields($form));

        $paymentSettings = [
            'settings'        => $settings,
            'currencies'      => PaymentHelper::getCurrencies(),
            'payment_methods' => PaymentHelper::getFormPaymentMethods($formId),
            'addressFields'   => array_filter($addressFields)
        ];

        $paymentSettings = apply_filters('fluentform/form_payment_settings', $paymentSettings, $formId);

        wp_send_json_success($paymentSettings, 200);
    }

    public function saveFormSettings()
    {
        $formId = (int)$_REQUEST['form_id'];
        $settings = wp_unslash($_REQUEST['settings']);

        if (is_array($settings)) {
            $sanitizeMap = [
                'currency'                       => 'sanitize_text_field',
                'status'                         => 'sanitize_text_field',
                'push_meta_to_stripe'            => 'sanitize_text_field',
                'receipt_email'                  => 'sanitize_text_field',
                'customer_name'                  => 'sanitize_text_field',
                'customer_address'               => 'sanitize_text_field',
                'transaction_type'               => 'sanitize_text_field',
                'stripe_account_type'            => 'sanitize_text_field',
                'disable_stripe_payment_receipt' => 'sanitize_text_field',
                'stripe_descriptor'              => 'sanitize_text_field',
                'custom_paypal_id'               => 'sanitize_email',
                'custom_paypal_mode'             => 'sanitize_text_field',
                'paypal_account_type'            => 'sanitize_text_field',
                'payment_mode'                   => 'sanitize_text_field',
                'publishable_key'                => 'sanitize_text_field',
                'secret_key'                     => 'sanitize_text_field',
                'item_value'                     => 'sanitize_text_field',
                'label'                          => 'sanitize_text_field',
            ];
            $settings = fluentform_backend_sanitizer($settings, $sanitizeMap);
        }

        Helper::setFormMeta($formId, '_payment_settings', $settings);

        wp_send_json_success([
            'message' => __('Settings successfully saved', 'fluentformpro')
        ], 200);
    }

    public function updateTransaction()
    {
        $transactionData = wp_unslash($_REQUEST['transaction']);
        $transactionId = intval($transactionData['id']);
        $oldTransaction = wpFluent()->table('fluentform_transactions')
            ->find($transactionId);

        $changingStatus = $oldTransaction->status != $transactionData['status'];

        $updateData = ArrayHelper::only($transactionData, [
            'payer_name',
            'payer_email',
            'billing_address',
            'shipping_address',
            'charge_id',
            'status'
        ]);

        $updateData = array_map('sanitize_text_field', $updateData);
        if (isset($updateData['payer_email'])) {
            $updateData['payer_email'] = sanitize_email($updateData['payer_email']);
        }
        $updateData['updated_at'] = current_time('mysql');

        wpFluent()->table('fluentform_transactions')
            ->where('id', $transactionId)
            ->update($updateData);

        $subscriptionId = intval(ArrayHelper::get($_REQUEST, 'subscription_id', '0'));
        if ($subscriptionId) {
            $existingSubscription = wpFluent()->table('fluentform_subscriptions')
                                        ->find($subscriptionId);

            $changedStatus = ArrayHelper::get($transactionData, 'status');

            $isStatusChanged = $existingSubscription->status != $changedStatus;

            if ($isStatusChanged) {
                wpFluent()->table('fluentform_subscriptions')
                          ->where('id', $subscriptionId)
                          ->update([
                              'status' => $changedStatus,
                              'updated_at' => current_time('mysql')
                          ]);
            }
        }

        $newStatus = $transactionData['status'];
        if (
            ($changingStatus && ($newStatus == 'refunded' || $newStatus == 'partially-refunded')) ||
            ($newStatus == 'partially-refunded' && ArrayHelper::get($transactionData, 'refund_amount'))
        ) {
            $refundAmount = 0;
            $refundNote = 'Refunded by Admin';

            if ($newStatus == 'refunded') {
                // Handle refund here
                $refundAmount = $oldTransaction->payment_total;
            } else if ($newStatus == 'partially-refunded') {
                $refundAmount = ArrayHelper::get($transactionData, 'refund_amount') * 100;
                $refundNote = sanitize_text_field(ArrayHelper::get($transactionData, 'refund_note', 'Refunded by Admin'));
            }

            if ($refundAmount) {
                $offlineProcessor = new OfflineProcessor();
                $offlineProcessor->setSubmissionId($oldTransaction->submission_id);

                $submission = $offlineProcessor->getSubmission();
                $offlineProcessor->refund($refundAmount, $oldTransaction, $submission, $oldTransaction->payment_method, 'refund_' . time(), $refundNote);
            }

        }

        if ($changingStatus) {

            if ($newStatus == 'paid' || $newStatus == 'pending' || $newStatus == 'processing') {
                // Delete All Refunds, recording them first
                $this->recordAndRemoveRefundLedger($oldTransaction, $newStatus);
            }

            $offlineProcessor = new OfflineProcessor();
            $offlineProcessor->setSubmissionId($oldTransaction->submission_id);
            $offlineProcessor->changeSubmissionPaymentStatus($newStatus);
            $offlineProcessor->changeTransactionStatus($transactionId, $newStatus);
            $offlineProcessor->recalculatePaidTotal();
        }

        $shouldRunActions = ArrayHelper::get($transactionData, 'should_run_actions', 'no');

        if (
            $changingStatus &&
            $newStatus === 'paid' &&
            $shouldRunActions === 'yes'
        ) {
            do_action(
                'fluentform/run_actions_after_update_transaction_as_paid',
                $newStatus,
                $oldTransaction
            );
        }

        wp_send_json_success([
            'message' => __('Successfully updated data', 'fluentformpro')
        ], 200);
    }

    public function getCoupons()
    {
        $status = get_option('fluentform_coupon_status');
        if ($status != 'yes') {
            wp_send_json([
                'coupon_status' => false
            ], 200);
        }

        $couponModel = new CouponModel();

        ob_start();
        $coupons = $couponModel->getCoupons(true);
        $errors = ob_get_clean();

        if ($errors) {
            (new CouponModel())->migrate();
            $coupons = $couponModel->getCoupons(true);
        }

        $data = [
            'coupon_status' => 'yes',
            'coupons'       => $coupons
        ];

        if (isset($_REQUEST['page']) && $_REQUEST['page'] == 1) {
            $forms = wpFluent()->table('fluentform_forms')
                ->select(['id', 'title'])
                ->where('has_payment', 1)
                ->get();
            $formattedForms = [];
            foreach ($forms as $form) {
                $formattedForms[$form->id] = $form->title;
            }
            $data['available_forms'] = $formattedForms;
        }

        wp_send_json($data, 200);
    }

    public function enableCoupons()
    {
        (new CouponModel())->migrate();
        update_option('fluentform_coupon_status', 'yes', 'no');
        wp_send_json([
            'coupon_status' => 'yes'
        ], 200);
    }

    public function saveCoupon()
    {
        $coupon = wp_unslash($_REQUEST['coupon']);

        if (is_array($coupon)) {
            $sanitizeMap = [
                'title'       => 'sanitize_text_field',
                'code'        => 'sanitize_text_field',
                'coupon_type' => 'sanitize_text_field',
                'amount'      => 'sanitize_text_field',
                'status'      => 'sanitize_text_field',
                'stackable'   => 'sanitize_text_field',
                'start_date'  => 'sanitize_text_field',
                'expire_date' => 'sanitize_text_field',
            ];
            $coupon = fluentform_backend_sanitizer($coupon, $sanitizeMap);

            if (isset($coupon['settings']) && is_array($coupon['settings'])) {
                if (isset($coupon['settings']['success_message'])) {
                    $coupon['settings']['success_message'] = wp_kses_post($coupon['settings']['success_message']);
                }

                if (isset($coupon['settings']['failed_message']) && is_array($coupon['settings']['failed_message'])) {
                    foreach ($coupon['settings']['failed_message'] as $messageKey => $message) {
                        $coupon['settings']['failed_message'][$messageKey] = wp_kses_post($message);
                    }
                }
            }
        }

        $validator = fluentValidator($coupon, [
            'title'       => 'required',
            'code'        => 'required',
            'amount'      => 'required',
            'coupon_type' => 'required',
            'status'      => 'required'
        ]);

        if ($validator->validate()->fails()) {
            $errors = $validator->errors();
            wp_send_json([
                'errors'  => $errors,
                'message' => __('Please fill up all the required fields', 'fluentformpro')
            ], 423);
        }

        $couponId = false;

        if (isset($coupon['id'])) {
            $couponId = $coupon['id'];
            unset($coupon['id']);
        }

        if (false !== strpos((string) $coupon['code'], ',')) {
            wp_send_json([
                'errors'  => [
                    'code' => [
                        'comma' => __('Coupon code cannot contain a comma', 'fluentformpro')
                    ]
                ],
                'message' => __('Coupon code cannot contain a comma', 'fluentformpro')
            ], 423);
        }

        if ($exist = (new CouponModel())->isCouponCodeAvailable($coupon['code'], $couponId)) {
            wp_send_json([
                'errors'  => [
                    'code' => [
                        'exist' => __('Same coupon code already exists', 'fluentformpro')
                    ]
                ],
                'message' => __('Same coupon code already exists', 'fluentformpro')
            ], 423);
        }

        if ($couponId) {
            (new CouponModel())->update($couponId, $coupon);
        } else {
            $couponId = (new CouponModel())->insert($coupon);
        }

        wp_send_json([
            'message'   => __('Coupon has been saved successfully', 'fluentformpro'),
            'coupon_id' => $couponId
        ], 200);

    }

    public function deleteCoupon()
    {
        $couponId = intval($_REQUEST['coupon_id']);
        (new CouponModel())->delete($couponId);
        wp_send_json([
            'message'   => __('Coupon has been successfully deleted', 'fluentformpro'),
            'coupon_id' => $couponId
        ], 200);
    }

    public function getStripeConnectConfig()
    {
        wp_send_json_success(ConnectConfig::getConnectConfig());
    }

    public function disconnectStripeConnect()
    {
        return ConnectConfig::disconnect($_REQUEST, true);
    }

    public function getWpPages()
    {
        $pages = wpFluent()->table('posts')
            ->select(['ID', 'post_title'])
            ->where('post_status', 'publish')
            ->where('post_type', 'page')
            ->orderBy('ID', 'ASC')
            ->limit(500)
            ->get();

        wp_send_json_success([
            'pages' => $pages
        ]);
    }

    public function cancelSubscription()
    {
        $subscriptionId = intval(ArrayHelper::get($_REQUEST, 'subscription_id'));

        $subscription = fluentFormApi('submissions')->getSubscription($subscriptionId);

        if (!$subscription) {
            wp_send_json_error([
                'message' => __('Subscription could not be found', 'fluentformpro')
            ], 423);
        }

        $transactionId = intval(ArrayHelper::get($_REQUEST, 'transaction_id', '0'));

        // SECURITY (FINDING-29): bind the cancelled records to the AUTHORIZED SUBSCRIPTION itself,
        // not merely its form. The submission is derived from the subscription (not the request), and
        // the transaction must belong to that submission AND this subscription — so a per-form
        // payment manager cannot flip an unrelated subscription's transaction/submission from the
        // same form to cancelled. subscription_id is null on some legacy rows, so it is matched with
        // a null-safe fallback while submission_id (1:1 with the subscription) does the hard binding.
        $subscriptionSubmissionId = (int) $subscription->submission_id;

        $scopeTxn = function ($query) use ($subscription, $subscriptionSubmissionId) {
            return $query
                ->where('submission_id', $subscriptionSubmissionId)
                ->where(function ($q) use ($subscription) {
                    $q->where('subscription_id', $subscription->id)
                      ->orWhereNull('subscription_id')
                      ->orWhere('subscription_id', 0);
                });
        };

        // FINDING-29 + review #243: derive the subscription's OWN transaction from the authorized
        // scope. Prefer the request's transaction_id only when it resolves inside that scope; the id
        // is optional in the admin UI, so a missing/stale/mismatched value must not leave the local
        // transaction active while the submission and gateway are cancelled — fall back to the
        // subscription's transaction.
        $oldTransaction = $scopeTxn(
            $transactionId
                ? wpFluent()->table('fluentform_transactions')->where('id', $transactionId)
                : wpFluent()->table('fluentform_transactions')
        )->first();

        if (!$oldTransaction) {
            $oldTransaction = $scopeTxn(wpFluent()->table('fluentform_transactions'))->first();
        }

        $oldSubmission = wpFluent()->table('fluentform_submissions')
                                   ->where('id', $subscriptionSubmissionId)
                                   ->first();

        // CORRECTNESS (review #243): cancel at the gateway FIRST — it holds the authoritative state.
        // Only touch local records after it confirms, so a gateway failure never leaves us showing
        // "cancelled" locally while the subscription keeps charging.
        $response = (new PaymentManagement())->cancelSubscription($subscription);

        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => $response->get_error_code() . ' - ' . $response->get_error_message()
            ], 423);
        }

        // Gateway cancelled: reconcile each local record independently (so a partially-cancelled
        // state is completed rather than skipped) and atomically. The transaction stays scoped to
        // this subscription (FINDING-29), so an unrelated same-form transaction cannot be flipped.
        $now = current_time('mysql');
        wpFluent()->transaction(function () use ($scopeTxn, $subscriptionSubmissionId, $oldTransaction, $oldSubmission, $now) {
            if ($oldTransaction && $oldTransaction->status !== 'cancelled') {
                $scopeTxn(wpFluent()->table('fluentform_transactions')->where('id', $oldTransaction->id))
                          ->update([
                              'status'     => 'cancelled',
                              'updated_at' => $now
                          ]);
            }

            if ($oldSubmission && $oldSubmission->payment_status !== 'cancelled') {
                wpFluent()->table('fluentform_submissions')
                          ->where('id', $subscriptionSubmissionId)
                          ->update([
                              'payment_status' => 'cancelled',
                              'updated_at'     => $now
                          ]);
            }
        });

        wp_send_json_success([
            'message' => $response
        ]);

    }

    /**
     * The record is the compensating control for an irreversible delete, so if it cannot be written the rows must survive.
     */
    private function recordAndRemoveRefundLedger($oldTransaction, $newStatus)
    {
        $refunds = wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $oldTransaction->submission_id)
            ->where('transaction_type', 'refund')
            ->get();

        if (!count($refunds)) {
            return;
        }

        $ids = [];
        $total = 0;
        $records = [];

        foreach ($refunds as $refund) {
            $ids[] = $refund->id;
            $total += $refund->payment_total;
            $records[] = '#' . $refund->id . ' (' . PaymentHelper::formatMoney($refund->payment_total, $refund->currency) . ')';
        }

        $description = sprintf(
            /* translators: 1: previous status, 2: new status, 3: number of refund records, 4: formatted total, 5: the deleted records */
            __(
                'Payment status changed from %1$s to %2$s, which removed %3$d refund record(s) totalling %4$s: %5$s',
                'fluentformpro'
            ),
            $oldTransaction->status,
            $newStatus,
            count($refunds),
            PaymentHelper::formatMoney($total, $oldTransaction->currency),
            implode(', ', $records)
        );

        // Record first: if this write fails the rows are still here to try again.
        do_action('fluentform/log_data', [
            'parent_source_id' => $oldTransaction->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $oldTransaction->submission_id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Refund records deleted', 'fluentformpro'),
            'description'      => $description,
        ]);

        // Only the rows just recorded, so a refund added meanwhile is not destroyed unrecorded.
        wpFluent()->table('fluentform_transactions')->whereIn('id', $ids)->delete();
    }
}
