<?php

/**
 * Theme blocks.
 */

namespace App;

add_action( 'init', function () {
    if( function_exists('register_block_type') ) {
        $registered_blocks = [
            'hero', 'hero-v2', 'locations', 'services', 'columns', 'numbers-cards', 'gallery', 'before-after', 'about', 'additional-cards',
            'reviews', 'reviews-v2', 'contact-details', 'certifications', 'cta', 'form', 'breadcrumbs', 'grid-pages', 'latest-posts',
            'partials/column', 'partials/image-video', 'partials/about-cta', 'partials/about-right-side', 'partials/additional-cards'
        ];
        foreach ($registered_blocks as $block) {
            register_block_type(__DIR__ . '/blocks/' . $block);
        }
    }
});

add_filter( 'allowed_block_types_all', function ( $allowed_blocks, $post ) {
	return [
		'acf/module-hero',
		'acf/module-hero-v2',
		'acf/module-locations',
		'acf/module-services',
		'acf/module-grid-pages',
		'acf/module-columns',
		'acf/module-numbers-cards',
        'acf/module-gallery',
        'acf/module-before-after',
        'acf/module-about',
        'acf/module-additional-cards',
        'acf/module-reviews',
        'acf/module-reviews-v2',
        'acf/module-contact-details',
        'acf/module-certifications',
        'acf/module-cta',
        'acf/module-form',
        'acf/module-breadcrumbs',
        'acf/module-latest-posts',
        'acf/module-column',
        'acf/module-partial-image-video',
        'acf/module-partial-about-cta',
        'acf/module-partial-about-right-side',
        'acf/module-partial-additional-cards',
        'core/paragraph',
        'core/heading',
        'core/image',
        'core/video',
        'core/embed',
        'core/list',
        'core/list-item',
        'core/buttons',
        'core/button',
        'core/pattern',
        'core/html',
        'trustindex/block-selector',
        'fluentfom/guten-block',
    ];

}, 25, 2 );
