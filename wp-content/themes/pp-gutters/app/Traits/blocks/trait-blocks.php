<?php

namespace App\MpxBlocks\Traits;

use function Roots\bundle;

trait blocks
{
  public bool $is_editor_mode;

  /**
   * Adding the  Metapixels Blocks category and positioning it at the top of the list.
   */
  public function addBlocksCategory()
  {
    add_action( 'block_categories', function ( $categories ) {
      return array_merge(
        $categories,
        [['slug'  => 'metapixels-blocks', 'title' => __( 'MetaPixels Blocks', 'metapixels-boilerplate' ),],]
      );
    }, 10, 2 );

    add_action( 'block_categories', function ( $categories ) {
      return array_merge(
        $categories,
        [['slug'  => 'metapixels-partials', 'title' => __( 'MetaPixels Partials', 'metapixels-boilerplate' ),],]
      );
    }, 10, 2 );

    add_filter( 'block_categories_all', function ( $cats ) {
      $new = ['cats' => ['slug'  => 'metapixels-blocks', 'title' => 'MetaPixels Blocks']];
      $position = 0;
      $cats = array_slice( $cats, 0, $position, true );
      // $cats = array_slice( $cats, 0, $position, true ) + $new + array_slice( $cats, $position, null, true );
      // $cats = array_values( $cats );

      return $cats;
    } );
  }

  /**
   * Enqueuing block assets.
   */
  public function enqueueBlockAssets()
  {
    add_action( 'enqueue_block_editor_assets', function(){
        wp_register_script( 'slick', '//cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js', [ 'jquery', 'acf' ] );
        wp_register_script( 'gallery', get_template_directory_uri() . '/app/blocks/gallery/gallery.js', [ 'jquery', 'acf', 'slick' ], null, true );
        wp_register_script( 'locations', get_template_directory_uri() . '/app/blocks/locations/locations.js', [ 'jquery', 'acf', 'slick' ], null, true );
        wp_register_script( 'numbers-cards', get_template_directory_uri() . '/app/blocks/numbers-cards/numbers-cards.js', [ 'jquery', 'acf', 'slick' ], null, true );
        if (has_block('acf/module-hero')) bundle('hero')->enqueue();
        if (has_block('acf/module-hero-v2')) bundle('hero-v2')->enqueue();
        if (has_block('acf/module-before-after')) bundle('before-after')->enqueue();
        if (has_block('acf/module-gallery')) bundle('gallery')->enqueue();
        if (has_block('acf/module-locations')) bundle('locations')->enqueue();
        if (has_block('acf/module-reviews')) bundle('reviews')->enqueue();
        if (has_block('acf/module-form')) bundle('form')->enqueue();
    });

    add_action('wp_enqueue_scripts', function () {
        if (has_block('acf/module-hero')) bundle('hero')->enqueue();
        if (has_block('acf/module-hero-v2')) bundle('hero-v2')->enqueue();
        if (has_block('acf/module-before-after')) bundle('before-after')->enqueue();
        if (has_block('acf/module-gallery')) bundle('gallery')->enqueue();
        if (has_block('acf/module-locations')) bundle('locations')->enqueue();
        if (has_block('acf/module-reviews')) bundle('reviews')->enqueue();
        if (has_block('acf/module-form')) bundle('form')->enqueue();
    }, 100);
  }

  /**
   * Loading Block Assets only if the block is used on the page.
   */
  public function loadingBlockAssets()
  {
    add_filter( 'should_load_separate_core_block_assets', '__return_true' );
  }

  /**
   * Creating Pattern Categories
   */
  public function createPatternCategories()
  {
    add_action( 'init', function() {

      register_block_pattern_category(
          'full-page-layout',
          [
            'label'       => _x( 'Full Page Layout', 'Block pattern category' ),
            'description' => __( 'A collection of full page layouts.' ),
          ]
      );
      register_block_pattern_category(
          'custom-blocks',
          [
            'label'       => _x( 'Custom Blocks', 'Block pattern category' ),
            'description' => __( 'A collection of custom blocks' ),
          ]
      );
      register_block_pattern_category(
          'mpx-columns',
          [
            'label'       => _x( 'Columns', 'Block pattern category' ),
            'description' => __( 'A collection of custom blocks' ),
          ]
      );
      register_block_pattern_category(
          'mpx-banners',
          [
            'label'       => _x( 'Banners', 'Block pattern category' ),
            'description' => __( 'A collection of custom blocks' ),
          ]
      );
      register_block_pattern_category(
          'mpx-global',
          [
            'label'       => _x( 'Global', 'Block pattern category' ),
            'description' => __( 'A collection of custom blocks' ),
          ]
      );
  } );;
  }

    /**
     * Adding loading eager to image in hero block
     */
    public function addEagerToHeroBlock()
    {
        add_filter('render_block', function ($block_content, $block) {
            if ($block['blockName'] === 'acf/module-hero' && strpos($block_content, '<img') !== false) {
                $block_content = preg_replace_callback(
                    '/<img([^>]*)>/i',
                    function ($matches) {
                        if (strpos($matches[1], 'loading=') === false) {
                            return '<img' . $matches[1] . ' loading="eager">';
                        }
                        return $matches[0];
                    },
                    $block_content
                );
            }
            return $block_content;
        }, 10, 2);
    }
}
