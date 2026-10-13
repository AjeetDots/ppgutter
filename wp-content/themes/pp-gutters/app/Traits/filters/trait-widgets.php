<?php

namespace App\Filters\Traits;

/**
 * Trait navs
 * @package App\Traits
 */
trait widgets
{
    /**
     * Filters:
     *  'widget_nav_menu_args'
     */
    public function widgetFilterFooterNavs()
    {
        /**
         * Replace footer menu widget classes with bootstrap classes.
         *
         * @param $nav_menu_args
         * @param $nav_menu
         * @param $args
         * @param $instance
         * @return array
         */
        add_filter('widget_nav_menu_args', function ($nav_menu_args, $nav_menu, $args, $instance){
            $nav_menu_args['menu_class'] = 'nav flex-column flex-sm-row';
            return $nav_menu_args;
        }, 10, 4);
    }
}
