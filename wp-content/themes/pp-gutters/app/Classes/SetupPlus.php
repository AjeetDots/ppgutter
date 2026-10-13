<?php

// IMPORTANT: leave as base namespace for Blade Directives
namespace App;

if (!class_exists('App\SetupPlus')) {
    /**
     * Class SetupPlus
     * @since ITD Web v1.1
     * @package App
     */
    class SetupPlus
    {
        public function __construct()
        {
            // do stuff
            add_action('template_redirect', [$this, 'isOnePager']);
            add_action('wp_enqueue_scripts', [$this, 'enqueueScripts'], 101);
            // add_action('wp_enqueue_scripts', [$this, 'dequeueScriptsBlockEditorMaybe'], 102);
            add_action('script_loader_tag', [$this, 'scriptLoader'], 10);
            add_filter('wp_revisions_to_keep', [$this, 'revisions'], 10, 2);
            // add_action('after_setup_theme', [$this, 'bladeDirectives'], 100);
        }

        /**
         * If there's a one-pager option and it's set to true, then redirect everything to the homepage.
         */
        public function isOnePager()
        {
            if (defined('IS_ONE_PAGER') && IS_ONE_PAGER) {
                if (! (is_front_page() || is_feed())) {
                    wp_redirect(get_home_url());
                    exit;
                }
            }
        }


        /**
         * Dequeue Block Editor assets
         * @return void
         */
        public function dequeueScriptsBlockEditorMaybe()
        {
            // WP Scripts
               wp_dequeue_style('wp-block-library'); // not using block editor
               wp_dequeue_style('wp-block-library-theme');
               wp_dequeue_style('editor-buttons');
               wp_dequeue_style('buttons');
               wp_dequeue_style('global-styles');
        }

        /**
         * Refined enqueuing of frontend scripts
         */
        public function enqueueScripts()
        {
            // Update jQuery
            // wp_deregister_script('jquery');
            // wp_register_script('jquery', 'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js', false);
            // wp_enqueue_script('jquery');

            // WP Scripts
            // if (!has_blocks()) {
            //     wp_dequeue_style('wp-block-library'); // not using block editor
            //     wp_dequeue_style('wp-block-library-theme');
            //     wp_dequeue_style('editor-buttons');
            //     wp_dequeue_style('buttons');
            // }
            
            // if (get_post()->post_content ?? false) {
            //     if (!preg_match('/(\[contact-form|ven_form)/', get_post()->post_content)) {
            //         wp_dequeue_script('contact-form-7');
            //         wp_dequeue_style('contact-form-7');
            //     }
            // }

            // if ( !is_page( ['contact']) ){
            //     wp_dequeue_script( 'wpcf7-recaptcha' );
            //     wp_dequeue_script('google-recaptcha');
            // }
        }

        /**
         * Defer JavaScript
         *
         * @see: https://kinsta.com/blog/defer-parsing-of-javascript/#4-defer-javascript-via-functionsphp-file
         * @param $script_tag
         * @return string
         */
        public function scriptLoader($script_tag)
        {
            // don't break WP Admin
            if (is_admin() || is_admin_bar_showing()) return $script_tag;

            // only defer JS
            if (false === strpos($script_tag, '.js')) return $script_tag;

            // don't defer jQuery - add sri https://cdnjs.com/libraries/jquery
            if (preg_match('/jquery(.min)?.js/', $script_tag)) {
                if (!strpos($script_tag, 'cloudflare')) return $script_tag;
                return str_replace(' src', ' integrity="sha512-894YE6QWD5I59HgZOGReFYm4dnWc1Qt5NtvYSaNcOP+u1T9qYdvdihz0PPSiiqn/+/3e7Jo4EaG7TubfWGUrMQ==" crossorigin="anonymous" referrerpolicy="no-referrer" src', $script_tag);
            }

            // don't defer ACF JS as AF req early access.
            if (strpos($script_tag, 'advanced-custom-fields')) return $script_tag;

            // async
            //if (strpos($script_tag, 'dist/scripts/main')) return str_replace(' src', ' async src', $script_tag);

            // defer
            if (strpos($script_tag, 'dist/scripts/main')) return str_replace(' src', ' defer src', $script_tag);
            return $script_tag;
            //return str_replace(' src', ' defer src', $script_tag);
        }

        /**
         * Set revisions per post type
         *
         * @param $num
         * @param $post
         * @return int
         */
        public function revisions($num, $post)
        {
            if (in_array($post->post_type, ['post', 'page'])) $num = 3;
            return $num;
        }
    }

    new SetupPlus();
}
