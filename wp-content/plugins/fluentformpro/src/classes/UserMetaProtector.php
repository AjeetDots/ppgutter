<?php

namespace FluentFormPro\classes;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Deny-list for the user-meta keys a form feed must never write. Add to it from outside
 * through core's is_protected_meta filter, scoped to 'user'.
 */
class UserMetaProtector
{
    /** Not delegated to is_protected_meta(), which any plugin can opt out of. */
    private static $alwaysProtected = [
        'user_pass',
        'user_activation_key',
        'session_tokens',
        '_application_passwords',
        '_new_email',
        'use_ssl',
        'default_password_nag',
        '_fluent_forms_has_role',
        '_fluent_forms_allowed_forms',
        '_fluent_forms_has_specific_forms_permission',
    ];

    /** @param array $context form_id / entry_id / integration, for the feed log */
    public static function isProtected($key, $userId = 0, $context = [])
    {
        if (!is_string($key) && !is_numeric($key)) {
            return false;
        }

        // update_metadata() unslashes before resolving; the column is ci + PAD SPACE.
        $key = function_exists('wp_unslash') ? wp_unslash((string) $key) : stripslashes((string) $key);

        // A feed's default mapping row has a blank label: refused, but not worth logging.
        if ('' === trim($key)) {
            return true;
        }

        // strtr, not strtolower: that is locale-sensitive before PHP 8.2.
        $folded = strtr(rtrim($key, ' '), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

        global $wpdb;
        // base_prefix: multisite caps keys carry the blog id (wp_2_capabilities).
        $prefix = preg_quote(isset($wpdb->base_prefix) ? $wpdb->base_prefix : 'wp_', '/');

        // The collation folds whole alphabets, not just case, so refuse anything outside
        // this charset. The `_` twin is ACF's hidden companion for the same field.
        $hit = !preg_match('/^[a-z0-9_.\-]+$/', $folded)
            || preg_match('/^' . $prefix . '(\d+_)?(capabilities|user_level|use_ssl|default_password_nag)$/i', $folded)
            || in_array($folded, self::$alwaysProtected, true)
            || in_array('_' . $folded, self::$alwaysProtected, true)
            || (function_exists('is_protected_meta') && is_protected_meta($folded, 'user'));

        if (!$hit) {
            return false;
        }

        static::logRefusal($key, $userId, $context);
        do_action('fluentform/protected_user_meta_key_blocked', $key, $userId, $context);

        return true;
    }

    protected static function logRefusal($key, $userId, $context)
    {
        $formId = isset($context['form_id']) ? $context['form_id'] : 0;
        if (!$formId) {
            return; // nothing to attach the row to; the action is the only channel
        }

        do_action('fluentform/log_data', [
            'title'            => __('Protected user meta key skipped', 'fluentformpro'),
            'status'           => 'failed',
            'description'      => sprintf(
                /* translators: 1: meta key, 2: user id */
                __('The mapped user meta key "%1$s" is reserved by WordPress or Fluent Forms and was not written to user #%2$d. Rename the mapping in this feed\'s settings.', 'fluentformpro'),
                esc_html((string) $key),
                (int) $userId
            ),
            'parent_source_id' => $formId,
            'source_id'        => isset($context['entry_id']) ? $context['entry_id'] : null,
            'component'        => isset($context['integration']) ? $context['integration'] : 'user_registration',
            'source_type'      => 'submission_item',
        ]);
    }
}
