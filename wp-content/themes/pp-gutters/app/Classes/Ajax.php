<?php

namespace App\Ajax;

use function App\template;

if (!class_exists('App\Ajax')) {
    class Ajax
    {
        /**
         * Ajax constructor.
         */
        public function __construct()
        {
            add_action('wp_enqueue_scripts', [$this, 'enqueueScripts'], 101); // 100 fires scripts in setup.php
            add_action('wp_ajax_fetch_post', [$this, 'fetchPost']);
            add_action('wp_ajax_nopriv_fetch_post', [$this, 'fetchPost']);
        }

        /**
         * Enqueue Additional Scripts
         *
         * Enqueues additional scripts and hang localised scripts off the theme's 'main.js' script (enqueued in setup.php)
         */
        public function enqueueScripts()
        {
            // Print out a nonce so we can verify the request
            wp_localize_script('sage/main.js', 'siteObj', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('site-sec'),
            ]);
        }

        /**
         * Fetch content for modal
         *
         * Uses custom WP Query, getGridPosts
         */
        public function fetchPost()
        {
            //$this->validateRequest();

            // wp_send_json_success($data);

            // wp_reset_postdata();

            // wp_die();
        }

        /**
         * Helper to handle nonce validating
         *
         * @param string $nonce
         */
        private function validateRequest($nonce = 'nonce')
        {
            $nonce = sanitize_text_field($_POST[$nonce]);

            // check nonce
            if (!wp_verify_nonce($nonce, 'site-sec')) {
                wp_die('<div class="alert alert-danger">' . __('Failed security check when fetching data. Please try again.') . '</div>',
                    __('Failed Security Check')
                );
            }
        }
    }

    // init
    new Ajax();
}
