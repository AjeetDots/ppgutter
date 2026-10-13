<?php

/**
 * Theme filters.
 */

namespace App;

/**
 * Add "… Continued" to the excerpt.
 *
 * @return string
 */
add_filter('excerpt_more', function () {
    return sprintf(' &hellip; <a href="%s">%s</a>', get_permalink(), __('Continued', 'sage'));
});

remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );

add_action( 'init', function () {
    add_post_type_support( 'page', 'excerpt' );
});

// Disable default css for all the forms
add_filter('fluentform/load_default_public', '__return_false');