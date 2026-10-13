<?php

namespace App\Helpers\Traits;

trait widgets {
    protected $widget_class;
    /**
     * Return the number of widgets in a sidebar, or 0
     *
     * @param string $sidebar   the id of the target sidebar
     * @param bool   $stacked   default false. whether the widgets should stacked or be in the grid
     *
     * @return int              number of widgets
     */
    public function getWidgetCount($sidebar = 'sidebar-primary', $stacked = false)
    {
        global $_wp_sidebars_widgets;
        if (!isset($_wp_sidebars_widgets[$sidebar])) return 0;

        $count = count($_wp_sidebars_widgets[$sidebar]);

        // update $widget_class so it can be accessed by the filter
        if ($stacked) {
            $this->widget_class = 'col-12';
        } else {
            $this->widget_class = 'col';
            if ($count > 3) {
                if ($count % 3 === 0) $this->widget_class .= ' col-md-4';
                else $this->widget_class .= ' col-md-3';
            }
        }

        /**
         * Update each widget's html class
         */
        add_filter('dynamic_sidebar_params', function ($params) {
            // add $widget_class to widget classes
            // NB: append the new class so that it doesn't get looped in the array of classes
            $params[0]['before_widget'] = preg_replace('~class="(.*?)">~im', sprintf('class="%s"', "$1 $this->widget_class"), $params[0]['before_widget']);
            return $params;
        }, 100, 1);

        // return the count
        return $count;
    }
}
