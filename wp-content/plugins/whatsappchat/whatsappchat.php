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
            //$this->enqueue_assets();
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
            });
        }
    }
    new Whatsapp_Chat_Widget;
} //End class_exists