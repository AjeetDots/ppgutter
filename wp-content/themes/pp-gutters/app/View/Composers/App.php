<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;
use Roots\Acorn\View\Composers\Concerns\AcfFields;
use function App\helpers;

class App extends Composer
{
    use AcfFields;

    /**
     * List of views served by this composer.
     *
     * @var array
     */
    protected static $views = [
        '*',
    ];

    /**
     * Data to be passed to view before rendering.
     *
     * @return array
     */
    public function with()
    {
        return [
            'siteName' => $this->siteName(),
            'site_logo' => $this->siteLogo(),
            'page_title' => $this->title(),
            'fields' => (object) collect($this->fields())->toArray(),
        ];
    }

    /**
     * Returns the site name.
     *
     * @return string
     */
    public function siteName()
    {
        return get_bloginfo('name', 'display');
    }

    public static function title()
    {
        if (is_home()) {
            if ($home = get_option('page_for_posts', true)) {
                return get_the_title($home);
            }
            return __('Latest Posts', 'sage');
        }
//        if (is_archive()) {
//            return is_post_type_archive() ? post_type_archive_title() : get_the_archive_title();
//        }
        if (is_search()) {
            return sprintf(__('<span class="h3">Search Results for:</span><br>%s', 'sage'), get_search_query());
        }
        if (is_404()) {
            return __('Not Found', 'sage');
        }
        return get_the_title();
    }

    /**
     * The site logo
     *
     * @return string <a><img></a> html
     */
    public function siteLogo()
    {
        return self::getSiteLogo();
    }

    public static function getSiteLogo() {
        global $site_logo;

        return $site_logo ?? helpers()->getSiteLogo() ?? '';
    }

    /**
     * @return object site logo size
     */
    public function getSiteLogoSize()
    {
        $logo_size =  get_theme_mod('logo_width', '40') ?: 40;
        return (object) ['w'=>$logo_size,'h'=>$logo_size * 0.4];
    }
}
