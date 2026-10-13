<?php

namespace App\Admin;

if (!class_exists(__NAMESPACE__.'\Admin')) {
    /**
     * Class Admin
     * @since ITD Web v1.1
     * @package App
     */
    class Admin
    {
        /**
         * Admin constructor
         */
        public function __construct()
        {
            add_action('after_setup_theme', [$this, 'customThemeSupport'], 20);
        }

        /**
         * Set up custom theme support
         */
        public function customThemeSupport()
        {
            // Add support for custom site logo
            $this->themeSupportCustomLogo();

            // Add SVG image support
            $this->themeSupportSVGs();

            // Add custom images sizes
            $this->themeSupportCustomImageSizes();
			
			// @todo: Hack to fix the width of the sidebar in the editor. Needs handling properly.
			add_action('admin_footer', function(){
				?><style>@media screen and (min-width:1500px){.interface-interface-skeleton__sidebar {width:400px}}</style><?php
			});
        }

        /**
         * Enable adding site logo through customizer
         * @link https://codex.wordpress.org/Theme_Logo
         */
        private function themeSupportCustomLogo()
        {
            add_theme_support('custom-logo', [
                'height'      => 50,
                'width'       => 200,
                'flex-height' => true,
                'flex-width'  => true,
                'header-text' => ['site-title', 'site-description'],
            ]);
        }

        /**
         * Add SVG support as image type
         */
        private function themeSupportSVGs()
        {
            add_filter('upload_mimes', function($mimes) {
                $mimes['svg'] = 'image/svg+xml';
                return $mimes;
            });
        }

        /**
         * Add custom image sizes
         */
        public function themeSupportCustomImageSizes()
        {
            /**
             * @see: https://calculateaspectratio.com/
             *
             * using aspect ratios:
             *      16:9
             *
             * defaults:
             *      "thumbnail"
             *      "medium"            to disable set as 0
             *      "medium_large"      to disable set as 0
             *      "large"             to disable set as 0
             * other (resmushit?):
             *      "1536x1536"
             *      "2048x2048"
             * custom:
             */

            // for profile photos
            // add_image_size('bio_pic',       375, 500, true);  // scales up to 768x1024 (tablet_pt)

            // add_image_size('thumbnail_ls',  240, 150, true);
            // add_image_size('card_ls',       360, 225, true);  // thumbnail_ls * 1.5
            // add_image_size('card_lg_ls',    528, 330, true);  // thumbnail_ls * 2.2
            // add_image_size('phone_pt',      375, 800, true);  // covers most mobiles
            // add_image_size('phone_ls',      512, 320, true); // covers most small tablets
            // add_image_size('tablet_pt',     768, 1024, true); // covers most small tablets
            // add_image_size('tablet_ls',     1024, 640, true); // covers most small tablets
            // add_image_size('laptop_ls',     1280, 800, true); // tablet_ls * 1.25
            // add_image_size('laptop_lg_ls',  1536, 960, true);// tablet_ls * 1.5
            // add_image_size('desktop_ls',    2048, 1280, true);// tablet_ls * 2
            // add_image_size('desktop_lg_ls',    2400, 2100, true);// imac
            // add_image_size('banner_ls',    2050, 1800, true);// imac

            //$this->setIntermediateImageSizesVar();
        }

        /**
         * Debugging only
         */
        private function setIntermediateImageSizesVar ()
        {
            // declare a global variable for all image sizes - for debugging
            global $itd_all_image_sizes;

            /**
             * assign values to global array of image sizes
             */
            foreach (get_intermediate_image_sizes() as $size) {
                $itd_all_image_sizes->size['width']    = intval(get_option("{$size}_size_w"));
                $itd_all_image_sizes->size['height']   = intval(get_option("{$size}_size_h"));
                $itd_all_image_sizes->size['crop']     = get_option("{$size}_crop" ) ? true : false;
            }
        }
    }

    new Admin();
}
