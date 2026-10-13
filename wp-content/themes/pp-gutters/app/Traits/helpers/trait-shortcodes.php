<?php

namespace App\Helpers\Traits;

trait shortcodes
{
    /**
     * Return the shortcode for Contact Form 7 form by id
     *
     * @param string|int $form_id
     * @param string $title
     * @param string $atts  dynamic shortcode atts, eg: ref=1234
     * @return string
     */
    public function getShortcodeContactForm7($form_id, $title = 'Generic Contact Form', $atts = '')
    {
        return do_shortcode(sprintf('[contact-form-7 id="%s" title="%s" %s]', $form_id, $title, $atts));
    }
}
