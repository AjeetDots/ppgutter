<?php

namespace FluentFormPro\Payments\Classes;

use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class CouponController
{
    public function validateCoupon()
    {
        // SECURITY (PRO-13): this endpoint is unauthenticated, nonce-less and returns distinguishable
        // responses for valid vs invalid codes — an offline-speed oracle to enumerate every coupon
        // code and its value. Throttle per IP to blunt brute-force enumeration (legit users apply
        // only a handful of codes).
        // COMPAT: resolve the client IP the same proxy-aware way the rest of the plugin does
        // (Request::getIp). Behind a CDN/load balancer REMOTE_ADDR is the proxy, so every visitor
        // would share one bucket and 15 probes site-wide would lock coupon validation for everyone.
        $rlIp = wpFluentForm()->request->getIp();
        $rlKey = 'ff_coupon_probe_rl_' . md5($rlIp);
        $rlCount = (int) get_transient($rlKey);
        if ($rlCount >= apply_filters('fluentform/coupon_probe_limit_per_minute', 15)) {
            wp_send_json(['message' => __('Too many attempts. Please try again later.', 'fluentformpro')], 429);
        }
        set_transient($rlKey, $rlCount + 1, MINUTE_IN_SECONDS);

        $code = sanitize_text_field(ArrayHelper::get($_REQUEST, 'coupon'));
        $formId = intval(ArrayHelper::get($_REQUEST, 'form_id'));

        $totalAmount = intval(ArrayHelper::get($_REQUEST, 'total_amount'));

        $couponModel = new CouponModel();
        $coupon = $couponModel->getCouponByCode($code);

        if (!$coupon) {
            wp_send_json([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from filter
                'message' => wp_kses_post(__(apply_filters('fluentform/coupon_general_failure_message', 'The provided coupon is not valid', $formId), 'fluentformpro'))
            ], 423);
        }

        $failedMessageArray = ArrayHelper::get($coupon->settings, 'failed_message');

        if ($coupon->status != 'active' || $coupon->code !== $code) {
            wp_send_json([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
                'message' => wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'inactive'), 'fluentformpro'))
            ], 423);
        }

        if ($couponModel->isDateExpire($coupon)) {
            wp_send_json([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
                'message' => wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'date_expire'), 'fluentformpro'))
            ], 423);
        }

        if ($formIds = ArrayHelper::get($coupon->settings, 'allowed_form_ids')) {
            if (!in_array($formId, $formIds)) {
                wp_send_json([
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
                    'message' => wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'allowed_form'), 'fluentformpro'))
                ], 423);
            }
        }

        $couponLimit = ArrayHelper::get($coupon->settings, 'coupon_limit', 0);

        if ($couponLimit) {
            $userId = get_current_user_id();

            if ($userId) {
                if (!$couponModel->hasLimit($coupon->code, $couponLimit, $userId)) {
                    wp_send_json([
                        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
                        'message' => wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'limit'), 'fluentformpro'))
                    ], 423);
                }
            } else {
                wp_send_json([
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
                    'message' => wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'limit'), 'fluentformpro'))
                ], 423);
            }
        }

        if ($coupon->max_use) {
            if ($couponModel->couponGlobalUsedCount($coupon->code) >= (int) $coupon->max_use) {
                wp_send_json([
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
                    'message' => wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'limit'), 'fluentformpro'))
                ], 423);
            }
        }

        if ($coupon->min_amount && $coupon->min_amount > $totalAmount) {
            wp_send_json([
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
                'message' => wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'min_amount'), 'fluentformpro'))
            ], 423);
        }

        $otherCouponCodes = wp_unslash(ArrayHelper::get($_REQUEST, 'other_coupons', ''));

        if ($otherCouponCodes) {
            $otherCouponCodes = \json_decode($otherCouponCodes, true);
            if ($otherCouponCodes) {
                $codes = $couponModel->getCouponsByCodes($otherCouponCodes);
                foreach ($codes as $couponItem) {
                    if (($couponItem->stackable != 'yes' || $coupon->stackable != 'yes') && $coupon->code != $couponItem->code) {
                        wp_send_json([
                            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
                            'message' => wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'stackable'), 'fluentformpro'))
                        ], 423);
                    }
                }
            }
        }

        $successMessage = ArrayHelper::get($coupon->settings, 'success_message');

        $formattedCoupon = [
            'code'               => $coupon->code,
            'title'              => $coupon->title,
            'amount'             => $coupon->amount,
            'coupon_type'        => $coupon->coupon_type,
            'message'            => wp_kses_post($successMessage)
        ];

        if ($coupon->min_amount) {
            $formattedCoupon['min_amount'] = $coupon->min_amount;
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from config
            $formattedCoupon['min_amount_message'] = wp_kses_post(__(ArrayHelper::get($failedMessageArray, 'min_amount'), 'fluentformpro'));
        }

        wp_send_json([
            'coupon' => $formattedCoupon
        ], 200);
    }

}
