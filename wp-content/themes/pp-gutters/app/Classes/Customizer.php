<?php

namespace App\Customizer;

if (!class_exists(__NAMESPACE__.'\Customizer')) {
    /**
     * Class Customizer
     * @since ITD Web v1.1
     * @package App\Customizer
     */
    class Customizer
    {
        // the site logo's primary class
        protected $site_logo_container_class = '.navbar-brand-link';
        protected $site_logo_class = '.navbar-brand img';

        /**
         * Customizer constructor
         */
        public function __construct() {
            // add Customizer settings
            add_action('customize_register', [$this, 'themeCustomizer']);
            // add Customizer styles
            add_action('customize_controls_print_styles', [$this, 'customizerControlsPrintStyles'], 999);
        }

        /**
         * themeCustomizer
         *
         * @link https://codex.wordpress.org/Class_Reference/WP_Customize_Manager
         * Also see app/admin.php
         *
         * @param \WP_Customize_Manager $wp_customize
         */
        public function themeCustomizer($wp_customize)
        {
            // Controls site logo
            $this->customizerSiteLogo($wp_customize);

            // Controls for alternative site logo
            // $this->customizerSiteLogoAlternative($wp_customize);
        }

        /**
         * Custom Logo
         *
         * Upload and resize main site logo in Customizer
         * @param \WP_Customize_Manager $wp_customize
         */
        private function customizerSiteLogo($wp_customize)
        {
            // add selective refresh
            $wp_customize->selective_refresh->add_partial( 'custom_logo', ['selector' => $this->site_logo_container_class]);

            // add new 'Site Identity' section (in 'title_tagline' panel)
            $wp_customize->add_section('title_tagline',
                [
                    'title'           => __('Site Identity'),
                    'priority'        => 0,
                    'description'     => __('<b>Optionally</b> upload custom logos. Note: these will replace the defaults loaded by the theme'),
                ]
            );

            /**
             * Custom logo resizing (for SVGs)
             */
            // add setting
            $wp_customize->add_setting('logo_width', [
                'default' => 150,
                'type' => 'theme_mod',
                'capability' => 'edit_theme_options',
                'transport' => 'postMessage',
                'sanitize_callback' => [$this, 'sanitiseNumberRange'],
            ]);

            // add control
            $wp_customize->add_control('logo_width', [
                'type' => 'range',
                'section' => 'title_tagline',
                'label' => __('Logo width', 'sage'),
                'input_attrs' => [
                    'min' => 30,
                    'max' => 380,
                    'step' => 1,
                    'class' => 'customise-logo-width',
                    'style' => 'width: 100%;',
                ],
                'description' => __('<strong>*</strong> SVG image sizing only', 'sage'),
                'priority' => 8,
            ]);

            // add partial refresh
            $wp_customize->selective_refresh->add_partial( 'logo_width', ['selector' => $this->site_logo_class]);
        }

        /**
         * Custom Alternative Logo
         *
         * A secondary logo for use cases where a inverted  logo is required
         * @param \WP_Customize_Manager $wp_customize
         */
        private function customizerSiteLogoAlternative($wp_customize){
            // add selective refresh
            $wp_customize->selective_refresh->add_partial( 'custom_logo_alt', ['selector' => $this->site_logo_container_class]);

            // add setting
            $wp_customize->add_setting('custom_logo_alt', [
                'type' => 'theme_mod',
                'capability' => 'edit_theme_options',
                'transport' => 'postMessage',
            ]);

            // add control
            $alt_logo_label = 'logo (alt)';
            $custom_logo_args = get_theme_support('custom-logo');
            $wp_customize->add_control(
                new \WP_Customize_Cropped_Image_Control(
                    $wp_customize,
                    'alt_logo',
                    [
                        'label'         => __('Alternative Logo (optional)', 'sage'),

                        // the section we're adding the control to - we could add a new section instead
                        'section'       => 'title_tagline',
                        'settings'      => 'custom_logo_alt',
                        'priority'      => 9,
                        'height'        => $custom_logo_args[0]['height'] ?: null,
                        'width'         => $custom_logo_args[0]['width'] ?: null,
                        'flex_height'   => $custom_logo_args[0]['flex-height'] ?: null,
                        'flex_width'    => $custom_logo_args[0]['flex-width'] ?: null,

                        // optionally set button labels
                        'button_labels'  => [
                            'select'       => __("Select $alt_logo_label"),
                            'change'       => __("Change $alt_logo_label"),
                            'default'      => __('Default'),
                            'remove'       => __('Remove'),
                            'placeholder'  => __("No $alt_logo_label selected"),
                            'frame_title'  => __("Select $alt_logo_label"),
                            'frame_button' => __("Choose $alt_logo_label"),
                        ],
                    ]
                )
            );
        }

        /**
         * Custom number sanitation
         *
         * Not strictly needed when using a range, but still prevents anything but an int value
         * with the specified range getting entered through the backdoor.
         *
         * @param $number
         * @param $setting
         * @return int      - if valid, the input number, if invalid the default value
         */
        public function sanitiseNumberRange($number, $setting)
        {
            $number = absint($number);
            return ($number >= 30 && $number <= 380) ? $number : $setting->default;
        }

        /**
         * Add styles to Customizer
         */
        public function customizerControlsPrintStyles()
        {
            ?>
            <style>
                #customize-control-custom_logo img,
                #customize-control-alt_logo img {
                    max-height: 100px;
                }
            </style>
            <?php
        }
    }

    new Customizer();
}
