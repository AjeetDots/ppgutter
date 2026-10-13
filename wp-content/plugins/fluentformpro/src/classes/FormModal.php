<?php
namespace FluentFormPro\classes;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class FormModal
{

    public static $instanceId = 0;

    public static function renderModal($atts)
    {
        self::$instanceId += 1;
        $uniqueId = 'ff_modal_instance_'.self::$instanceId;

        if(self::$instanceId == 1) {
            wp_enqueue_script('lity', FLUENTFORMPRO_DIR_URL.'public/libs/lity/lity.min.js', array('jquery'), '2.3.1', true);
            wp_enqueue_style('lity', FLUENTFORMPRO_DIR_URL.'public/libs/lity/lity.min.css', array(), '2.3.1', 'all');
            add_action('wp_footer', function () {
                ?>
                <script type="text/javascript">
                    jQuery(document).ready(function () {
                        jQuery('.ff_modal_btn').on('click', function (e) {
                            if(!jQuery(this).data('instance')) {
                                return;
                            }
                            var instanceId = jQuery(this).data('instance');
                            jQuery(document).on('click', '#'+instanceId, lity);
                        });
                    });
                </script>
                <?php
            }, 999999);
        }

        $formShortcodeAtts = 'id="'.intval($atts['form_id']).'"';
        if (!empty($atts['form_class'])) {
            $formShortcodeAtts .= ' css_classes="'.esc_attr($atts['form_class']).'"';
        }

        return '<div class="ff_form_modal"><button data-lity data-lity-target="#'.esc_attr($uniqueId).'" class="ff_modal_btn '.esc_attr($atts['css_class']).'">'.wp_kses_post($atts['btn_text']).'</button><div style="display: none" class="ff_form_modal_body"><div style="background-color: '.esc_attr($atts['bg_color']).'" class="ff_modal_container lity-hide" id="'.esc_attr($uniqueId).'">'.do_shortcode('[fluentform '.$formShortcodeAtts.']').'</div></div></div>';
    }

}
