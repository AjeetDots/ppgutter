<?php

namespace App\Helpers\Traits;

use WP_Query;

trait strings
{
    /**
     * Generate modal trigger data attributes
     *
     * @param null|object|string|int $post
     * @return string
     */
    public function getModalLinkAttrs($post = null) : string
    {
        $post_id = (is_object($post) && isset($post->ID)) ? $post->ID : ($post ??  get_the_ID());
        $permalink = get_permalink($post_id);

        if (!$permalink) return '';

        // Does is need to be more complex than this?
        // the type can be ascertained when fetching the content
        return sprintf('href="%s" data-toggle="modal" data-target="#content" data-content="%s"', $permalink, $post_id);
    }

    /**
     * Generate contact modal trigger data attributes
     *
     * @param string $link
     * @param string $modal
     * @return string
     */
    public function getLocalModalLinkAttrsMaybe(string $link, string $modal = '#contact-0') : string
    {
        // if (!str_contains($link, $modal) && !$this->isHomeUrl($link)) {
        //     if (defined('IS_ONE_PAGER') && IS_ONE_PAGER && $this->isLocalUrl($link)) {
        //         $post_id = url_to_postid($link);
        //         return $this->getModalLinkAttrs($post_id);
        //     }
        //     return sprintf('href="%s"', $link);
        // }
        return str_contains($link, $modal)
            ? sprintf('href="%s" data-toggle="modal"', $link)
            : sprintf('href="%s"', $link);
    }

    /**
     * Check if the url belongs to this site
     *
     * @param string $url
     * @return bool
     */
    public function isLocalUrl(string $url) : bool
    {
        $url_host      = str_replace('www.', '', parse_url($url,PHP_URL_HOST));
        $home_url_host = str_replace('www.', '', parse_url(home_url(),PHP_URL_HOST));
        return $url_host === $home_url_host;
    }

    /**
     * Check if the url is the home url
     *
     * @param string $url
     * @return bool
     */
    public function isHomeUrl(string $url) : bool
    {
        return trim(parse_url($url,PHP_URL_PATH),'/') === trim(parse_url(home_url(),PHP_URL_PATH),'/');
    }

    /**
     * Check if a section should be animated or not
     * @param bool $animated
     * @return string
     */
    public function animatedSection(bool $animated = false) : string
    {
        return ($animated ?? false) ? 'anm8' : '';
    }

    /**
     * Create Button
     *
     * @param $link
     * @param string $classes
     * @return bool|string
     */
    public function getButtonHtml($link, string $classes = '') : bool|string
    {
        if (empty($link['url']) || empty($link['title'])) return false;
        if (empty($link['target'])) return sprintf('<a class="%s" href="%s">%s</a>', $classes, $link['url'], $link['title']);
        return sprintf('<a class="%s" href="%s" target="%s">%s</a>', $classes, $link['url'], $link['target'], $link['title']);
    }

    /**
     * Transform a string into kebab-case
     *
     * @param $text
     * @return string
     */
    public function kebabCase($text) : string
    {
        $final = str_replace(' ', '-', strtolower($text));
        $final = str_ireplace( ['#038;', '&'], '', $final);
        return str_ireplace( '--', '-', $final);
    }

    /**
     * Transform an array of strings and trim multiple separator characters
     *
     * @param string $separator             default is a space
     * @param array|null $array_of_strings
     * @return string                       returns a clean string form the array of strings
     */
    public function implode(string $separator = ' ', ?array $array_of_strings = []) : string
    {
        if (empty($array_of_strings)) return '';
        return trim(preg_replace("/{$separator}{$separator}+/", $separator, implode($separator, $array_of_strings)));
    }

    /**
     * Return the sanitised tel for href:tel
     *
     * @param string $pretty_tel
     * @return string
     */
    public function getSanitisedTel(string $pretty_tel) : string
    {
        if (str_contains($pretty_tel, '+')) {
            $tel = "+" . preg_replace("/[(]0[)]/", "", $pretty_tel);
            return $tel = "+" . preg_replace( "/[^0-9$]/", "", $tel);
        }
        return preg_replace( "/[^0-9$]/", "", $pretty_tel);
    }

    /**
     * Separates classes
     *
     * @param string $classes
     * @param string $string
     * @param string $include
     * @return string
     */
    public function getClassesFromString(string $classes, string $string, bool $include = true) : string
    {
        if ($classes ?? false) {
            $word_array = explode(' ', $classes);

            $filtered_array = array_filter($word_array, function ($word) use ($string, $include) {
                return $include ? stripos($word, $string) === false : stripos($word, $string) !== false;
            });

            $resultString = implode(' ', $filtered_array);

            return $include ? $resultString : str_replace($string, "", $resultString);
        }
        return false;
    }

    /**
     * Trim string lenght
     */
    public function mpxStringTrim($string, $lenght = 200) {
        $string = strip_tags($string, '');
        if (strlen($string) < $lenght)
            return $string;

        $final_string = substr($string, 0, $lenght);
        $last_space = strrpos($string, ' ');
        $final_string = substr($final_string, 0, $last_space);

        return $final_string . '...';
    }

    /**
     * Get content from archive pages
     */
    public function mpxGetArchivePageContent($page_title) {
        $args = ['post_type' => 'page', 'title' => $page_title, 'posts_per_page' => 1];
        $query = new WP_Query($args);
        $hero_banner_block = '';
        $remaining_content = '';

        if ($query->have_posts()) {
            while($query->have_posts()) {
                $query->the_post();
                $page_content = get_the_content();
            }
        }

        if ($page_content ?? false) {
            $blocks = parse_blocks($page_content);

            foreach ($blocks as $block) {
                if ($block['blockName'] === 'acf/module-hero')
                    $hero_banner_block = render_block($block);
                else
                    $remaining_content .= render_block($block);
            }
        }

        return ['hero_banner_block' => $hero_banner_block, 'remaining_content' => $remaining_content];
    }
}
