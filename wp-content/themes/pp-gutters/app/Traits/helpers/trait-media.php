<?php

namespace App\Helpers\Traits;

trait media {
    /**
     * Site logo
     *
     * /wp-includes/general-template.php
     * @param $args[
     *      size mixed ,
     *      link_classes string,
     *      src url,
     *      custom_logo_class string,
     *      alt_logo bool
     * ]
     * @return string
     */
    public function getSiteLogo (array $args = []) : string {
        global $site_logo, $site_logo_alt;

        $atts = array_merge([
            'logo_size'         => 'logo',
            'link_classes'      => 'navbar-brand',
            'link_url'          => esc_url(home_url('/')),
            'logo_src'          => 'logo.png',
            'custom_logo_class' => 'static site-logo',
        ], $args);

        // the custom class(es), image source and image size for the <img> tag
        $logo_src           = ($atts['logo_src']) ? $atts['logo_src'] : 'logo.png';
        $logo_size          = ($atts['logo_size'] == 'logo') ? ['200', '80'] : $atts['logo_size'];
        $custom_logo_class  = $atts['custom_logo_class'];
        $custom_logo_id     = get_theme_mod('custom_logo');
        $custom_logo_alt_id = get_theme_mod('custom_logo_alt'); //TODO: uncomment to set up alternative logo usage
        $site_logo       = $site_logo_alt = '';

        // set image width for SVGs from the width set in customizer
        $custom_logo_svg_width   = '';
        if ($this->isSvg($custom_logo_id) || $this->isSvg($custom_logo_alt_id)) {
            // set the width from the slider
            $customizer_lw = get_theme_mod('logo_width', '40');
            $custom_logo_svg_width = "width: {$customizer_lw}px; ";
        }

        // return the custom logo or an empty string
        if ($custom_logo_id) {
            $site_logo = wp_get_attachment_image(
                $custom_logo_id,
                $logo_size,
                false,
                [
                    'class'    => "$custom_logo_class main-logo",
                    'alt'      => 'site-logo',
                    'itemprop' => 'logo',
                    'style'    => "{$custom_logo_svg_width} height: auto;",
                    'loading'   => 'eager'
                ]
            );
        }

        // return the custom alternative logo
        if ($custom_logo_alt_id) {
            $site_logo_alt = wp_get_attachment_image(
                $custom_logo_alt_id,
                $logo_size,
                false,
                [
                    'class'    => "$custom_logo_class $custom_logo_class-alt",
                    'alt'      => 'site-logo-alt',
                    'itemprop' => 'logo',
                    'style'    => "{$custom_logo_svg_width} height: auto;",
                    'loading'   => 'eager'
                ]
            );
        }

        // the custom class(es) and url for the wrapping link tag <a><img></a>
        $link_classes   = $atts['link_classes'];
        $link_url       = $atts['link_url'];
        $link_text      = get_bloginfo('name', 'display');

        // only wrap the link text in a screen-reader span if there's a logo to show
        $link_text      = is_null($site_logo) ? $link_text : "<span class='visually-hidden'>{$link_text}</span>";

        // the final html
        $html = "<a href=\"{$link_url}\" class=\"{$link_classes}\" rel=\"home\">{$site_logo}{$site_logo_alt}{$link_text}</a>";

        return apply_filters('get_custom_logo', $html, $blog_id = 0);
    }

    /**
     * @param $image_id
     * @return bool
     */
    public function isSvg($image_id) {
        return 'image/svg+xml' === get_post_mime_type($image_id);
    }

    /**
     * Generate background images
     *
     * @param int       $bg_image                   The image ID
     * @param string    $section_name               Element that gets the background image (default is .has-bg)
     * @param null      $bg_image_sizes             Optionally provide the array of sizes
     * @param bool      $portrait                   Optionally returns portrait images for mobiles devices
     * @param bool      $important                  Optionally adds !important (default true)
     * @param boolean   $parallax                   Check for a Parallax requirement (default is false)
     *
     * @return string   $responsive_bg_stylesheet   CSS media queries for responsive images
     */
    public function getResponsiveBackgroundImages($bg_image, $section_name = '.bg-cover', $bg_image_sizes = null, $portrait = false, $important = true, $parallax = false) {
        // @todo We need to change these image sizes!!!
        if ($portrait) {
            $default_bg_image_sizes = [     // image width
                'phone_pt'      => '375',     //'375',
                'tablet_pt'     => '768',   //'768',
                'tablet_ls'     => '1024',   //'1024',
                'laptop_ls'     => '1280',  //'1280',
                'laptop_lg_ls'  => '1536',  //'1536',
                'desktop_ls'    => '1920',  //'1920',
                // 'desktop_lg_ls'    => '2100',  //'1920',
                // 'banner_ls'    => '2050',  //'1920',
            ];
        } else {
            $default_bg_image_sizes = [
                'card_ls'       => '375',     //'375',
                'card_lg_ls'    => '525',   //'525',
                'tablet_ls'     => '1024',   //'1024',
                'laptop_ls'     => '1280',  //'1280',
                'laptop_lg_ls'  => '1536',  //'1536',
                'desktop_ls'    => '1920',  //'1920',
                // 'desktop_lg_ls'    => '2100',  //'1920',
                // 'banner_ls'    => '2050',  //'1920',
            ];
        }

        $responsive_bg_stylesheet = '';
        $bg_image_smallest = true;
        foreach ( ($bg_image_sizes ?? $default_bg_image_sizes) as $device => $size) {
            $imgURL = wp_get_attachment_image_url($bg_image, $device, true);
            $has_parallax = $parallax ? ' .parallax' : '';
            $important = $important ? ' !important;' : '';
            if ($imgURL) {
                if ($bg_image_smallest) {
                    $media_query = "{$section_name}{$has_parallax}{background-image:url({$imgURL}){$important}}";
                    $bg_image_smallest = false;
                }
                else {
                    $media_query = "@media (min-width:{$size}px){{$section_name}{$has_parallax}{background-image:url({$imgURL}){$important}}}";
                }
                $responsive_bg_stylesheet .= $media_query;
            }
        }
        return $responsive_bg_stylesheet;
    }


    /**
     * @param null $post            The post, null will use the current post
     * @param string $image_size    The image size to return
     * @param bool $style_tag       True will wrap the background-image url() in a style tag
     * @return string               Inline css background-image
     */
    public function getInlineFeatureBackgroundImage($post = null, $image_size = 'medium', $style_tag = true) {
        $img = get_the_post_thumbnail_url($post, $image_size);
        if (!$img) return '';
        if ($style_tag) return "style=\"background-image: url({$img});\"";
        return "background-image: url({$img});";
    }

    /**
     * @param null $image_id        The attachment image ID
     * @param string $image_size    The image size to return
     * @param bool $style_tag       True will wrap the background-image url() in a style tag
     * @return string               Inline css background-image, or empty string
     */
    public function getInlineBackgroundImage($image_id = null, $image_size = 'medium', $style_tag = true) {
        if (is_null($image_id)) return '';

        $img = wp_get_attachment_image_url($image_id, $image_size);
        if (!$img) return '';
        if ($style_tag) return "style=\"background-image: url($img);\"";
        return "background-image: url($img);";
    }

    /**
     * Get image or fallback image
     *
     * @param $image_id
     * @param $fallback_id
     * @param string $size
     * @return string
     */
    public function getImageOrFallback($image_id, $fallback_id, $size = 'thumb') {
        $id = wp_attachment_is_image($image_id) ? $image_id : $fallback_id;
        return wp_get_attachment_image($id, $size);
    }

    /**
     * Get an HTML img element representing an image attachment
     *
     * Filters:         wp_get_attachment_image()
     * Uses filter:     wp_calculate_image_srcset_meta
     *
     * returns wp_get_attachment_image without a srcset
     * @param int          $attachment_id Image attachment ID.
     * @param string|array $size          (Optional)
     * @param bool         $icon          (Optional)
     * @param string|array $attr          (Optional)
     * @return string      HTML img element or empty string.
     */
    public function getAttachmentImageHtmlNoSrcset(int $attachment_id, $size = 'thumbnail', bool $icon = false, $attr = '') : string {
        // add a filter to return null for srcset
        add_filter( 'wp_calculate_image_srcset_meta', '__return_null' );
        // get the srcset-less img html
        $html = wp_get_attachment_image($attachment_id, $size, $icon, $attr);
        // remove the above filter
        remove_filter( 'wp_calculate_image_srcset_meta', '__return_null' );
        return $html;
    }

    /**
     * Get an HTML img element representing an image attachment
     *
     * Filters:         wp_get_attachment_image()       via getAttachmentImageHtmlNoSrcset()
     * Uses filter:     wp_calculate_image_srcset_meta  via getAttachmentImageHtmlNoSrcset()
     *
     * returns wp_get_attachment_image without a srcset, and width and height attributes
     * @param int          $attachment_id Image attachment ID.
     * @param string|array $size          (Optional)
     * @param bool         $icon          (Optional)
     * @param string|array $attr          (Optional)
     * @return string      HTML img element or empty string.
     */
    public function getAttachmentImageHtmlNoSrcsetOrHw(int $attachment_id, $size = 'thumbnail', bool $icon = false, $attr = '') : string {
        // get image html
        $html = $this->getAttachmentImageHtmlNoSrcset($attachment_id, $size, $icon, $attr);
        // remove width and height atts
        return $this->removeImageHwAtts($html);
    }

    /**
     * Removes the width and height attributes from an image tag
     *
     * Useful for scenarios where the image is presented based on other factors, such as within <picture>
     *
     * @param string $html      the <img> html tag
     * @return string $html     the <img> html tag without width="" and height=""
     */
    public function removeImageHwAtts($html) {
        return preg_replace('/(width|height)=(\"|\')\d*(\"|\')/', '', $html);
    }

    /**
     * Wrapper for wp_get_attachment_image() that adds a 'static' class
     *
     * @param $attachment_id
     * @param string $size
     * @param bool $icon
     * @param string|array $attr
     * @return string
     */
    public function getAttachmentImageStatic($attachment_id, $size = 'thumbnail', $icon = false, $attr = '') {
        return wp_get_attachment_image($attachment_id, $size = 'thumbnail', $icon = false, wp_parse_args($attr, ['class' => 'static']));
    }

    /**
     * Calculate the aspect ratio of an embed/image from its width and height
     *
     * @uses \App\Helpers\Traits\media::calculateRatio()
     *
     * @param string $embed_html
     * @param bool $as_style_prop
     * @return string
     */
    public function calculateEmbedRatio (string $embed_html, bool $as_style_prop = false) : string {
        preg_match('/width="(.+?)"/', $embed_html, $matches);
        $w = $matches[1] ?? false;
        if ($w) {
            preg_match('/height="(.+?)"/', $embed_html, $matches);
            $h = $matches[1] ?? false;
            if ($h) {
                return $this->calculateRatio($w, $h, $as_style_prop);
            }
        }
        return $as_style_prop ? '' : 'ratio-16x9';
    }

    /**
     * Calculate the aspect ratio from a given width and height
     *   .embed-responsive-21by9:before {padding-top: 42.85714%}
     *   .embed-responsive-16by9:before {padding-top: 56.25%}
     *   .embed-responsive-4by3:before  {padding-top: 75%}
     *   .embed-responsive-1by1:before  {padding-top: 100%}
     **
     * @param string $width
     * @param string $height
     * @param bool $as_ratio_value
     * @return string
     */
    public function calculateRatio (string $width, string $height, bool $as_ratio_value = false) : string {
        // err on the side of caution with each size
        $ratio = ((int) $height / (int) $width) * 100;
        if ($as_ratio_value) return $ratio;
        if ($ratio < 45) return 'ratio-21x9';
        if ($ratio < 60) return 'ratio-16x9';
        if ($ratio < 80) return 'ratio-4x3';
        if ($ratio > 98 && $ratio < 102) return 'ratio-1x1';
        if ($ratio > 140) return 'ratio-9x16';
        if ($ratio > 125) return 'ratio-3x4';
        return "ratio-{$ratio}";
    }

    /**
     * Maybe get thumbnail from embed
     *
     * @param $string
     * @return bool
     */
    public function getEmbedThumbnailFromStringMaybe ($string) {

        $found = preg_match_all('|^\s*(https?://[^\s"]+)\s*$|im', $string, $matches);
        if (!$found) return false;

        // nb: this can be used if we're just checking an embed exists and is supported
        //foreach ($matches[1] as $match) if (wp_oembed_get($match)) return $match;

        $o_embed = new \WP_oEmbed();
        foreach ($matches[1] as $match) {
            if ($o_embed->get_html($match)) {
                return $o_embed->get_data($match)->thumbnail_url;
            }
        }
        return false;
    }

    /**
     * Prepares iFrame for playing Youtube/Vimeo with additional attributes
     *
     * NB: all ['class' => 'lozad'] for lazy loading
     *
     * @param $embed
     * @param int $embed_params     default is set to 2 (includes controls)
     * @param bool $auto_play       default is set to false
     * @param array $iframe_attrs   class, data-[whatever], etc.
     * @return string
     */
    public function getIframeYoutubeVimeoWithAtts(string $embed, int $embed_params = 2, bool $auto_play = false, array $iframe_attrs = []) : string {
        // get any attributes
        extract($iframe_attrs);

        // remove autoplay if not required
        if (!$auto_play) $embed = str_replace(['autoplay; ', 'autoplay;', 'autoplay'], '', $embed);

        // add lazy loading if required
        if (isset($class) && str_contains($class, 'lozad')) $embed = str_replace('src=', 'data-src=', $embed);

        $src = wp_extract_urls($embed)[0];
        $new_src = $this->getYoutubeVimeoSrcFromString($src, $embed_params);
        $iframe = str_replace($src, $new_src, $embed);

        // add extra attributes to iframe html
        if ($this->isVimeo($new_src)) {
            $class = isset($class) ? "$class is-vimeo" : "is-vimeo";
        } elseif ($this->isYoutube($new_src)) {
            $class = isset($class) ? "$class is-youtube" : "is-youtube";
        }

        // TODO: consider adding BS embed code here like so:
        // $class .= ' embed-responsive-item';

        $attributes = '';
        if (isset($class)) $attributes .= sprintf('class="%s"', $class);
        if (isset($id)) $attributes .= sprintf('id="%s"', $id);
        $attributes .= sprintf('frameborder="0" allowscriptaccess="always" allow="%s" allowfullscreen', $auto_play ? 'autoplay; fullscreen' : 'fullscreen');

        return str_replace('></iframe>', " $attributes ></iframe>", $iframe);
    }

    /**
     * Test for Youtube url
     *
     * @param string $string
     * @return bool
     */
    public function isYoutube(string $string) : bool {
        $yt_pattern = '#^https?://(?:www\.)?(?:youtu[be.com\.be/])#';
        return preg_match($yt_pattern, $string);
    }

    /**
     * Get filename from string
     *
     * @param string $url
     * @return string
     */
    public function getFilenameFromUrl(string $url) : string {
        $url = wp_parse_url($url, -1);
        $path = $url['path'];
        $regex = '/[^\/]+$/'; // match anything after the last '/'

        preg_match($regex, $path, $matches);

        unset($url, $path, $regex);
        return $matches[0];
    }

    /**
     * Test for Vimeo url
     *
     * @param string $string
     * @return bool
     */
    public function isVimeo(string $string) : bool {
        $vimeo_pattern  = '#^https?://(.+\.)?vimeo\.com/.*#';
        return preg_match($vimeo_pattern, $string);
    }

    /**
     * Builds a Youtube iFrame that will autoplay across devices.
     *
     * @param string $vid   The Youtube video ID
     * @return string       The full HTML to build the iFrame
     */
    public function getIframeYoutubeAutoplayer($vid) : string
    {
        ob_start();
        ?>
        <div id="player<?= $vid ?>"></div>
        <script src="https://www.youtube.com/iframe_api"></script>
        <script>
            var v='<?= $vid ?>';
            function onYouTubeIframeAPIReady(){new YT.Player('player'+v,{width:'100%',videoId:v,playerVars:{'autoplay':1,'playsinline':1,'loop':1,'autohide':1,'controls':0,'rel':0,'showinfo':0,'playlist':v,'modestbranding':1},events:{'onReady':onPlayerReady}})}
            function onPlayerReady(e){e.target.mute();e.target.playVideo()}
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Prepare Youtube/Vimeo url
     *
     * - optionally extracts url from a string
     * - appends additional GET parameters
     *
     * @see: https://developer.vimeo.com/player/sdk/embed
     * @see: https://developers.google.com/youtube/player_parameters
     * @see: https://developers.google.com/youtube/iframe_api_reference
     * @see: https://developers.google.com/youtube/youtube_player_demo
     *
     * @param string    $string
     * @param int       $settings
     *                      1: Ideal for background videos;
     *                          - minimal UI,
     *                          - muted
     *                          - autoplay
     *                          - loop
     *                      2: Ideal for modal videos
     *                          - minimal UI,
     *                          - includes controls
     *                          - autoplay
     *                          - loop
     * @param bool $raw
     * @return string
     */
    function getYoutubeVimeoSrcFromString(string $string, int $settings = 1, bool $raw = true) : string {
        // get src, if $raw extract it from the string
        $src            = $raw ? wp_extract_urls($string)[0] : $string;
        if (empty($src)) return $string;

        $provider = $this->isYoutube($src) ? 'youtube' : ($this->isVimeo($src) ? 'vimeo' : 'other');

        // add extra params to iframe src
        $settings = (int) $settings;
        $params = [
            'site_pvd' => $provider, // to know how to interact via JS?
            'autohide'  => 1, // vimeo?, youtube? // can't find a reference to it, but keep it anyway
            'loop'      => 1, // vimeo, youtube
        ];

        // relatively provider-agnostic params
        if ($settings === 1) {
            $params['controls'] = 0; // vimeo, youtube
            $params['autoplay'] = 1; // vimeo, youtube
        } else {
            $params['controls'] = 1; // vimeo, youtube
        }

        // provider specific params
        if ('vimeo' === $provider) {
            // vimeo defaults
            $params['byline']       = 0;
            $params['muted']        = 1;
            $params['portrait']     = 0;
            $params['background']   = 1;

            // vimeo conditionals
            if ($settings === 1) {
                $params['muted']    = 1;
            } else {
                //$params['muted']    = 0;
            }
        } elseif ('youtube' === $provider) {
            // youtube defaults
            $params['rel']              = 0;
            $params['version']          = 3;
            $params['showinfo']         = 0; // deprecated as of 25/09/2018
            $params['enablejsapi']      = 1;
            $params['playerapiid']      = 'ytplayer';
            $params['modestbranding']   = 1;
            $params['playlist']         = $this->getFilenameFromUrl($src); // youtube - makes a single item loop

            // youtube conditionals
            if ($settings === 1) {
                $params['mute']         = 1;
            } else {
                $params['mute']         = 0;
            }
        } else {
            // defaults - cater for both
            $params['title']            = 0;
            $params['byline']           = 0;
            $params['muted']            = 1;
            $params['portrait']         = 0;
            $params['background']       = 1;
            $params['showinfo']         = 0; // deprecated as of 25/09/2018
            $params['rel']              = 0;
            $params['version']          = 3;
            $params['enablejsapi']      = 1;
            $params['playerapiid']      = 'ytplayer';
            $params['modestbranding']   = 1;
            $params['playlist']         = $this->getFilenameFromUrl($src); // youtube - makes a single item loop

            //conditionals
            if ($settings === 1) {
                $params['mute']          = 1;
                $params['muted']         = 1;
            } else {
                $params['mute']          = 0;
                $params['muted']         = 0;
            }
            // other providers can go here
        }

        return add_query_arg($params, $src);
    }
}
