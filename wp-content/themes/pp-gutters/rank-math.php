<?php

namespace MPX\RankMath;

if (!class_exists(__NAMESPACE__.'\RankMathMods')) {
    /**
     * A class to manage hooks into RankMath
     *
     * @todo: move all RankMath related hooks into here from theme's main classes and traits.
     *
     * @see: https://rankmath.com/kb/filters-hooks-api-developer/#how-to-add-code-snippets-to-rank-math-php
     */
    class RankMathMods
    {
        public function __construct()
        {
            // call methods
            /**
             * Filter to hide the Email Reporting Options for non-production environments
             */
            if (wp_get_environment_type() !== 'production') {
                add_filter('rank_math/analytics/hide_email_report_options', '__return_true');
            }

            // Filter/Modify Breadcrumb items.
            add_filter('rank_math/frontend/breadcrumb/items', [$this, 'modifyBreadcrumbs'], 10, 2);

        }

        /**
         * Filter/Modify Breadcrumb items.
         * @see https://rankmath.com/kb/filters-hooks-api-developer/#change-remove-breadcrumb-items
         * @see https://rankmath.com/kb/customize-breadcrumbs-in-rank-math/
         *
         * array $crumbs The crumbs array.
         * Breadcrumbs $class Current breadcrumb object.
         */
        public function modifyBreadcrumbs($crumbs, $class)
        {
            // check if we are viewing single posts
            //var_dump($crumbs);
            if (is_singular('service')) {
                $post_type_ob = get_post_type_object('service');
                //$slug = $post_type_ob->rewrite['slug'] ?? 'service';
                //echo get_permalink(get_page_by_path($slug)); '<pre style="font-size:12px;background:black;color:cyan;">'; var_dump($post_type_ob); echo '</pre>'; echo "$post_type_ob->label <br> $slug";
                $services_crumb = ['Services', home_url('services/')];
                array_splice($crumbs, 1, 0, array($services_crumb)); // inserts after Home
                //array_splice($crumbs, (count($crumbs) < 3 ? 0 : 1), 0, array($services_crumb));
            }
            return $crumbs;
        }
    }

    // let's go!
    add_action('wp_head', function () {
        new RankMathMods();
    });
}
