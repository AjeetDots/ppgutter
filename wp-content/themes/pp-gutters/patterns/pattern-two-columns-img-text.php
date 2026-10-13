<?php
/**
 * Title: Two Columns Image & Text
 * Slug: pp-gutters/pattern-two-columns-img-text
 * Categories: custom-blocks, mpx-columns
 */
?>

<!-- wp:acf/module-columns {"name":"acf/module-columns","data":{},"mode":"preview"} -->
<!-- wp:acf/module-column {"name":"acf/module-column","data":{},"mode":"preview","className":"col-12 mw-1000 mx-auto pb-4"} -->
<!-- wp:heading {"textAlign":"center","placeholder":"Block Title Goes Here","textColor":"black"} -->
<h2 class="wp-block-heading has-text-align-center has-black-color has-text-color">Title here</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","placeholder":"Some content goes here as the paragraph.","textColor":"black"} -->
<p class="has-text-align-center has-black-color has-text-color">Lorem ipsum dolor sit amet consectetur. Amet tristique id ut pharetra porttitor eu euismod sapien. Libero cursus mauris pretium donec integer. Laoreet sed ut consectetur in vitae in id. Facilisi amet et id suspendisse id sed.</p>
<!-- /wp:paragraph -->
<!-- /wp:acf/module-column -->

<!-- wp:acf/module-column {"name":"acf/module-column","data":{},"mode":"preview","className":"col-lg-6"} -->
<!-- wp:image {"id":704,"sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/resources/block-preview-images/ppg-placeholder-img.webp" alt="This is a placeholder image" class="wp-image-704"/></figure>
<!-- /wp:image -->

<!-- wp:heading {"level":3,"placeholder":"Block Title Goes Here","textColor":"black"} -->
<h3 class="wp-block-heading has-black-color has-text-color">Domestic services</h3>
<!-- /wp:heading -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"primary","className":"is-style-fill"} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link has-primary-background-color has-background wp-element-button" href="#">View All Services</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:acf/module-column -->

<!-- wp:acf/module-column {"name":"acf/module-column","mode":"preview","className":"col-lg-6"} -->
<!-- wp:image {"id":704,"sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/resources/block-preview-images/ppg-placeholder-img.webp" alt="This is a placeholder image" class="wp-image-704"/></figure>
<!-- /wp:image -->

<!-- wp:heading {"level":3,"placeholder":"Block Title Goes Here","textColor":"black"} -->
<h3 class="wp-block-heading has-black-color has-text-color">Commercial services</h3>
<!-- /wp:heading -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"primary","className":"is-style-fill"} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link has-primary-background-color has-background wp-element-button" href="#">View All Services</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:acf/module-column -->
<!-- /wp:acf/module-columns -->
