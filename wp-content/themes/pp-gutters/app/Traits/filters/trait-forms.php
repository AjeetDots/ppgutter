<?php

namespace App\Filters\Traits;

trait forms
{
    /**
     * Add Bootstrap form classes to WP search form
     */
    public function searchFormAddBootstrapClasses()
    {
        add_filter('get_search_form', function ($form) {
            $search_form = "components/search-form";
            if (file_exists(get_stylesheet_directory() . "/views/{$search_form}.blade.php")) {
                return template($search_form);
            }
            return $form;
        });
    }

    /**
     * Remove enqueuing of frontend scripts (this is not dequeuing, as it prevent the enqueuing)
     */
    public function contactForm7_PreventEnqueingAssets()
    {
        add_filter('wpcf7_load_css', '__return_false');
        add_filter('wpcf7_autop_or_not', '__return_false');

        // conditional fields extension
        // @todo: conditionally load these if required
        // add_filter('wpcf7cf_load_js', '__return_false');
        add_filter('wpcf7cf_load_css', '__return_false');
    }
}

