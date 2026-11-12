<?php
/**
 * Plugin Name: Whatsapp Chat
 * Plugin URI: https://metapixels.co.uk/plugins
 * Description: A fake Whatsapp Chat widget
 * Version: 1.0
 * Author: Mervyn Booth
 * Author URI: https://metapixels.co.uk
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Whatsapp_Chat_Widget')) {
    /**
     * Constants here
     */
    define('WCW_PLUGIN_TEXT_DOMAIN', 'whatsappchat');
    define('WCW_PLUGIN', __FILE__);
    define('WCW_PLUGIN_NAME', 'Whatsapp Chat');
    define('WCW_PLUGIN_VERSION', 1.0);
    define('WCW_PLUGIN_BASE_NAME', plugin_basename(WCW_PLUGIN));
    define('WCW_PLUGIN_DIR', untrailingslashit(dirname(WCW_PLUGIN)));

    class Whatsapp_Chat_Widget {
        private $has_render;
        private $options;

        public function __construct() {
            $this->loader();
            $this->enqueue_assets();
            add_action('rest_api_init', [$this, 'register_route']);
        }

        /** Cloudflare Turnstile keys, shared with the simple-cloudflare-turnstile plugin */
        private static function turnstile_key($type)
        {
            if ($type === 'site') {
                return defined('CF_TURNSTILE_SITE_KEY') && CF_TURNSTILE_SITE_KEY ? CF_TURNSTILE_SITE_KEY : get_option('cfturnstile_key');
            }
            return defined('CF_TURNSTILE_SECRET_KEY') && CF_TURNSTILE_SECRET_KEY ? CF_TURNSTILE_SECRET_KEY : get_option('cfturnstile_secret');
        }

        public function register_route()
        {
            register_rest_route('whatsappchat/v1', '/link', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_link'],
                'permission_callback' => '__return_true',
            ]);
        }

        public function handle_link(WP_REST_Request $request)
        {
            $number = preg_replace('/\D/', '', $this->options['whatsappchat_phone_number'] ?? '');
            $secret = self::turnstile_key('secret');
            $token  = (string) $request->get_param('token');
            $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!$number || !$secret || $token === '' || strlen($token) > 2048) {
                return new WP_Error('wcw_unavailable', 'Unavailable', ['status' => 403]);
            }

            // Per-IP limit (counts failed attempts too) and a site-wide hourly cap
            $rate_key = 'wcw_rate_' . md5($ip);
            $count = (int) get_transient($rate_key);
            $total = (int) get_transient('wcw_total');
            if ($count >= 5 || $total >= 200) {
                return new WP_Error('wcw_rate_limited', 'Too many requests', ['status' => 429]);
            }
            set_transient($rate_key, $count + 1, HOUR_IN_SECONDS);

            $res = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'timeout' => 10,
                'body'    => ['secret' => $secret, 'response' => $token, 'remoteip' => $ip],
            ]);
            $body = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
            $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            // Token must be valid, issued for this widget, and solved on this site (not replayed from elsewhere)
            if (empty($body['success'])
                || ($body['action'] ?? '') !== 'whatsapp_chat'
                || !hash_equals(strtolower($site_host), strtolower($body['hostname'] ?? ''))) {
                return new WP_Error('wcw_failed', 'Verification failed', ['status' => 403]);
            }

            set_transient('wcw_total', $total + 1, HOUR_IN_SECONDS);
            $response = new WP_REST_Response(['url' => 'https://wa.me/' . $number]);
            $response->header('Cache-Control', 'no-store');
            return $response;
        }

        private function loader()
        {
            register_deactivation_hook(__FILE__, [$this, 'deactivate']);

            //Include Files
            require_once dirname(__FILE__ ) .'/frontend/render.php';
            $this->has_render = function_exists('whatsappchat_html');
            if ($this->has_render) {
                require_once dirname(__FILE__) .'/admin/options.php';
                $this->options = get_option('whatsappchat_settings');
                if ($this->options) {
                    add_action('wp_footer', [$this, 'render']);
                }
            }
        }

        public function render()
        {
            if ($this->options['whatsappchat_phone_number']) {
                whatsappchat_html($this->options);
            }
        }

        public function deactivate()
        {
            delete_option('whatsappchat_settings');
        }

        private function enqueue_assets()
        {
            add_action('wp_enqueue_scripts', function () {
                wp_enqueue_style(WCW_PLUGIN_NAME, plugin_dir_url( __FILE__ ) . 'public/css/main.css', [], WCW_PLUGIN_VERSION, 'all');
                wp_enqueue_script(WCW_PLUGIN_NAME, plugin_dir_url( __FILE__ ) . 'public/js/main.js', [], WCW_PLUGIN_VERSION, true);
                wp_localize_script(WCW_PLUGIN_NAME, 'whatsappChatCfg', [
                    'siteKey'  => self::turnstile_key('site'),
                    'endpoint' => rest_url('whatsappchat/v1/link'),
                ]);
            });
        }
    }
    new Whatsapp_Chat_Widget;
} //End class_exists