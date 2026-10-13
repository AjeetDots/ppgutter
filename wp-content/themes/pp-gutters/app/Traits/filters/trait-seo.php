<?php

namespace App\Filters\Traits;

/**
 * Trait navs
 * @package App\Traits
 */
trait seo
{
    /**
     * Filters:
     *  'rank_math/sitemap/remove_credit'
     *  'rank_math/sitemap/enable_caching'
     *  'rank_math/sitemap/entry'
     *  'rank_math/sitemap_url'
     */
    public function seoStuff()
    {
        add_filter('rank_math/sitemap/remove_credit', '__return_true');
        //add_filter('rank_math/sitemap/enable_caching', '__return_false');
        $this->rankMathMakeAbsoluteUrls();
    }

    /**
     * Makes relative URLs (from Soil) into absolute ones for terms and videos
     *
     * @return void
     */
    private function rankMathMakeAbsoluteUrls() : void
    {
        // makes 'term' sitemap urls absolute
        add_filter('rank_math/sitemap/entry', function($url, $type, $object) {
            if ($type === 'term' && isset($url['loc']))
                if (!str_starts_with(stripslashes($url['loc']), stripslashes(home_url())))
                    $url['loc'] = home_url($url['loc']);
            return $url;
        }, 10, 3);

        // makes local video urls absolute
        add_filter('rank_math/sitemap_url', function ($output, $url) {
            if (isset($url['videos'])) {
                // Appends the home url to the urls that start with '/wp-content'
                // NB: only works with default 'wp-content' directory
                $output = str_replace('>/wp-content/' , '>'. stripslashes(home_url()) .'/wp-content/', $output);
            }
            return $output;
        }, 10, 2);
    }
}
