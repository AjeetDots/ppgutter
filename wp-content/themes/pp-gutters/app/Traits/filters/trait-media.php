<?php

namespace App\Filters\Traits;

trait media
{
    /**
     * Add attachment/feature image attributes
     *
     *  Wrapper for 'wp_get_attachment_image_attributes' filter that fires inside wp_get_attachment_image().
     *  Adds 'img-fluid' class and optionally adds attributes for lazy features of Lozad and Slick Slider
     *
     * @param bool $add_lazy_attrs
     * @return void
     */
    public function responsiveAttachmentImages(bool $add_lazy_attrs = false) : void
    {
        if ($add_lazy_attrs) $this->addLazyAttributes();
        else {
            add_filter('wp_get_attachment_image_attributes', function ($attrs) {
                // Bail on admin
                if (is_admin()) return $attrs;

                if (!isset($attrs['class'])) $attrs['class'] = '';
                $classes = &$attrs['class'];

                // Add (if not there already) img-fluid for responsive images
                if (!$classes) $classes = 'img-fluid';
                elseif (!str_contains('img-fluid', $classes)) $classes .= ' img-fluid';

                return $attrs;
            }, 10);
        }
    }

    /**
     * Add lazy loading images to attachment/feature attributes
     *
     * Adds 'img-fluid' class and attributes for lazy loading
     * Will omit image from lazy loading if the 'static' class exists
     * Works with Lozad (data-src) and Slick (data-lazy)
     *
     * @see: https://github.com/ApoorvSaxena/lozad.js
     * @see: https://roots.io/guides/how-to-use-lozad-js-in-sage/
     *
     * @return void
     */
    private function addLazyAttributes()
    {
        add_filter('wp_get_attachment_image_attributes', function ($attrs) {
            // Bail on admin
            if (is_admin()) return $attrs;

            if (!isset($attrs['class'])) $attrs['class'] = '';
            $classes = &$attrs['class'];

            // Non-lazy attributes
            // Add img-fluid for responsiveness
            if (!$classes) $classes = 'img-fluid';
            elseif (!str_contains('img-fluid', $classes)) $classes .= ' img-fluid';

            // Bail if the image has 'static' class
            if ($classes && str_contains($classes, 'static')) return $attrs;

            // Replace src with data-src/data-lazy
            if ($classes && (str_contains($classes, 'slick-lazy'))) {
                // Slick: replace src with data-lazy
                if (isset($attrs['src'])) $attrs['data-lazy'] = $attrs['src'];
            } else {
                // Lozad: replace src with data-src
                if (isset($attrs['src'])) $attrs['data-src'] = $attrs['src'];
            }

            // Replace srcset with data-srcset
            if (isset($attrs['srcset'])) $attrs['data-srcset'] = $attrs['srcset'];
            unset($attrs['src'], $attrs['srcset']);
            return $attrs;
        }, 10);
    }
}
