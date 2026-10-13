<?php

namespace FluentFormPro\Integrations\Discord;

if (!defined('ABSPATH')) {
    exit;
}

class Discord
{
    public static function sendMessage($webhook, $message)
    {
        // SECURITY (PRO-15): the webhook URL is a form-manager-controlled feed setting that
        // was passed straight to wp_remote_post with no validation, turning a Discord feed
        // into an arbitrary server-side request (internal hosts, cloud metadata). Restrict it
        // to official Discord webhook hosts and use the safe HTTP client.
        $host = strtolower((string) wp_parse_url($webhook, PHP_URL_HOST));
        $isDiscordHost = in_array($host, ['discord.com', 'discordapp.com'], true)
            || (bool) preg_match('/\.discord(app)?\.com$/', $host);
        // COMPAT: filterable so an existing feed relaying through a Discord-compatible endpoint
        // keeps working without patching. Site PHP only — a form manager cannot reach it.
        $isDiscordHost = (bool) apply_filters('fluentform/discord_allowed_webhook_host', $isDiscordHost, $host, $webhook);
        if ('https' !== strtolower((string) wp_parse_url($webhook, PHP_URL_SCHEME)) || !$isDiscordHost) {
            return new \WP_Error('invalid_webhook', __('Invalid Discord webhook URL.', 'fluentformpro'));
        }

        $data = [
            'payload_json' => json_encode($message)
        ];

        $res = wp_safe_remote_post($webhook, [
            'body' => $data,
            'header' => [
                'content-type' => 'multipart/form-data',
            ]
        ]);

        if (is_wp_error($res)) {
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if (in_array($code, [200, 201, 204], true)) {
            return true;
        }
        return new \WP_Error($code, wp_remote_retrieve_response_message($res));
    }
    
}
