<?php

namespace App\Filters\Traits;

/**
 * Trait navs
 * @package App\Traits
 */
trait strings
{
    /**
     * Filters:
     *  'the_content_lite'
     */
    public function coreContentFilter()
    {
        /**
         * Filter the content with only CORE filters
         *
         * Thanks to Bill!
         * @link: https://gist.githubusercontent.com/billerickson/cd77f7b226b9379f9db75c38b0f4b3d5/raw/e32d56373e0f16544063a3e625d88b00941c1afd/functions.php
         *
         * @param $the_content
         * @return array
         */
        global $wp_embed;
        add_filter('the_content_lite', [$wp_embed, 'run_shortcode'], 8);
        add_filter('the_content_lite', [$wp_embed, 'autoembed'],     8);
        add_filter('the_content_lite', 'wptexturize'      );
        add_filter('the_content_lite', 'convert_chars'    );
        add_filter('the_content_lite', 'wpautop'          );
        add_filter('the_content_lite', 'shortcode_unautop');
        add_filter('the_content_lite', 'do_shortcode'     );

        add_filter('tiny_mce_before_init', function ($init) {
            $custom_colors = '
                "54b66c", "Primary",
                "f2f2f2", "Secondary",
                "333333", "Dark",
                "fff", "White",
            ';
            $init['textcolor_map'] = '[' . $custom_colors . ']';
            $init['textcolor_rows'] = 1;

            return $init;
        });

        add_action('acf/init', function () {

            if( function_exists('acf_add_options_page') ) {

                $option_page = acf_add_options_page(array(
                    'page_title'    => __('Site Options'),
                    'menu_title'    => __('Site Options'),
                    'menu_slug'     => 'theme-general-settings',
                    'capability'    => 'edit_posts',
                    'redirect'      => false,
                    'position'      => '2.1'
                ));
            }
        });
    }
}
