<?php

namespace App\Filters\Traits;

use function App\helpers;

/**
 * Trait navs
 * @package App\Traits
 */
trait navs
{
    /**
     * Filters:
     *  'nav_menu_css_class'
     *  'nav_menu_item_title'
     *  'nav_menu_submenu_css_class'
     *  'nav_menu_link_attributes'
     */
    public function navFilters()
    {
        /**
         * Add 'dropdown' class to menu items with submenus
         * Remove 'active' from page anchors that start point to the homepage ('/#') links
         *
         * @param $classes
         * @param $item
         * @return array
         */
        add_filter('nav_menu_css_class', function($classes, $item) {
            if (in_array('menu-item-has-children', $classes)) {
                $classes[] = 'dropdown';
            }
            if (in_array('active', $classes) && strpos($item->url, '#') === 1) {
                $classes = array_diff($classes, ['active']);
            }
            return $classes;
        }, 100, 2);

        /**
         * Add custom icon to sub menu navs (dropdown menus)
         *
         * @param $title
         * @param $item
         * @param $args
         * @param $depth
         * @return string
         */
        /* uncomment to use SVG caret
        add_filter('nav_menu_item_title', function($title, $item, $args, $depth) {
            if (!method_exists($this, 'getSvgIcon')) return $title;
            if ($item->is_subitem) $title = $title . $this->getSvgIcon('arrow-down');
            return $title;
        }, 10, 4);
        */

        /**
         * Add Bootstrap's classes to WP sub-menus
         *
         * add .dropdown-menu class to .sub-menu
         * add .dropdown-menu-right class to .sub-menu
         *
         * For simple fade transitions see: https://codepen.io/MyNerv/full/povKbBg
         * For sliding dropdown sub-menus see: https://www.jqueryscript.net/menu/sliding-bootstrap-dropdown.html
         *
         * @param array   $classes  the menu's class(es)
         * @param array   $args     wp_nav_menu() arguments
         * @param int     $depth    the current item's depth
         * @return array  $classes  the menu's class(es)
         */
        add_filter('nav_menu_submenu_css_class', function($classes, $args, $depth) {
            $classes[] = 'dropdown-menu';
            return $classes;
        }, 10, 4);

        /**
         * Add Bootstrap's atts to WP menu item links
         *
         * add .nav-link class to all links
         * add .dropdown-toggle class and markup attributes to sub-menus
         * add _blank target to social links
         *
         * @param array     $atts  the menu's class(es)
         * @param \WP_Post  $item  the current menu item.
         * @param object    $args  wp_nav_menu() arguments
         * @param int       $depth the current item's depth
         * @return array    $atts  the menu item's attributes
         */
        add_filter('nav_menu_link_attributes', function($atts, $item, $args, $depth) {

            // Leave early if this is a button
            // TODO: this may need further conditionals if a button is to have further processing, ie icons, dropdown...
            $btn_link = function($string = '', $classes = '') {
                return str_replace("nav-link", "nav-link-btn btn {$string} mx-3", $classes);
            };

            $btn_title = function($string = '', $classes = '') {
                return str_replace("nav-link", "{$string}", $classes);
            };

            // Prepare $atts for additional classes
            // if class already exists then add a space before concatenating any new classes
            $new_atts['class'] = isset($atts['class']) ? ' nav-link' : 'nav-link';

            // Check of social or channel menus
            $is_social = 'social_navigation' === ($args->theme_location ?? '') || ($args->menu->slug ?? '') === 'footer-menu-socials';

            if ($item->classes ?? false) {
                foreach ($item->classes as $class) {
                    // Add Bootstrap markup and button markup
                    // if (strpos($class, 'menu-btn') !== false) {
                        
                    //     if (strpos($class, 'menu-btn-po') !== false) $new_atts['class'] = $btn_link('btn-outline-primary', $new_atts['class']);
                    //     elseif (strpos($class, 'menu-btn-p') !== false) $new_atts['class'] = $btn_link('btn-primary', $new_atts['class']);
                    //     elseif (strpos($class, 'menu-btn-t') !== false) $new_atts['class'] = $btn_link('btn-tertiary', $new_atts['class']);
                    //     else $new_atts['class'] = $btn_link('btn-outline-secondary', $new_atts['class']);
                    // }

                    if (strpos($class, 'menu-title') !== false) {
                        $new_atts['class'] = $btn_title('menu-title', $new_atts['class']);
                    }
    
                    // add modal markup
                    if (strpos($class, 'modal-trigger') !== false) $new_atts['data-toggle'] = 'modal';
                }
            }

            // add modal markup
            if (in_array($item->url, ['#contact-default', '#contact-0'])) $new_atts['data-toggle'] = 'modal';

            // add modal markup for internal links if the site is a one-pager
            if (defined('IS_ONE_PAGER') && IS_ONE_PAGER && $item->type === 'post_type') {
                // check if the link is the home_url
                if (! helpers()->isHomeUrl($item->url)) {
                    $new_atts['data-toggle'] = 'modal';
                    $new_atts['data-target'] = '#content';
                    $new_atts['data-content'] = $item->object_id ?? 0;
                }
            }

            // add dropdown markup to sub menus
            if ($item->is_subitem) {
                $new_atts['class'] .= ' dropdown-toggle'; // TODO: add/remove this to use the Bootstrap Caret.
                $new_atts['data-bs-toggle'] = 'dropdown';
                $new_atts['data-bs-auto-close'] = 'outside';
                $new_atts['aria-haspopup'] = 'true';
                $new_atts['aria-expanded'] = 'false';
            }

            // add link class to social and channel links
            if ($is_social) {
                $new_atts['class'] .= ' nav-link-social';
                $new_atts['target'] = '_blanks';
            }

            // merge with existing attributes
            return wp_parse_args($new_atts, $atts);
        }, 10, 4);

        /**
         * Display SVG icons in social links menu.
         *
         * @param  string   $title  The menu item output.
         * @param  \WP_Post $item   Menu item object.
         * @param  object   $args   wp_nav_menu() arguments.
         * @param  int      $depth  Depth of the menu.
         * @return string  $title   The menu item's title with social icon.
         */
        add_filter('nav_menu_item_title', function($title, $item, $args, $depth) {
            // Change SVG icon inside social links menu if there is supported URL.
            if ('social_navigation' === ($args->theme_location ?? '') || ($args->menu->slug ?? '') === 'footer-menu-socials') {
                // Get supported social icons.
                $social_icons = helpers()->brandIcons();

                // find the matching social icon
                foreach ($social_icons as $attr => $value) if (false !== strpos($item->url, $attr)) {
                    return sprintf('<span class="visually-hidden">%s</span> %s', $title, helpers()->getSvgIcon(esc_attr($value)));
                }
            }
            return $title;
        }, 10, 4);       
    }
}
