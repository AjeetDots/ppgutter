<?php
namespace FluentFormPro\Components;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class ColorPicker extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'color_picker',
            'Color Picker',
            ['color', 'picker'],
            'advanced'
        );
    }

    function getComponent()
    {
        return [
            'index' => 15,
            'element' => $this->key,
            'attributes' => [
                'name' => $this->key,
                'class' => '',
                'value' => '',
                'type' => 'text',
                'placeholder' => __('Choose Color', 'fluentformpro')
            ],
            'settings' => [
                'container_class' => '',
                'placeholder' => '',
                'label' => $this->title,
                'label_placement' => '',
                'help_message' => '',
                'admin_field_label' => '',
                'validation_rules' => [
                    'required' => [
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ]
                ],
                'conditional_logics' => []
            ],
            'editor_options' => [
                'title' => $this->title,
                'icon_class' => 'ff-edit-tint',
                'template' => 'inputText'
            ],
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'admin_field_label',
            'placeholder',
            'value',
            'label_placement',
            'validation_rules',
        ];
    }


    public function render($data, $form)
    {
        $elementName = $data['element'];

        $data = apply_filters_deprecated(
            'fluentform_rendering_field_data_' . $elementName,
            [
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_data_' . $elementName,
            'Use fluentform/rendering_field_data_' . $elementName . ' instead of fluentform_rendering_field_data_' . $elementName
        );

        $data = apply_filters('fluentform/rendering_field_data_' . $elementName, $data, $form);

        $data['attributes']['class'] = @trim('ff-el-form-control ff-el-color ' . $data['attributes']['class']);
        $data['attributes']['id'] = $this->makeElementId($data, $form);
        $data['attributes']['readonly'] = 'true';
        if ($tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex()) {
            $data['attributes']['tabindex'] = $tabIndex;
        }

        $ariaRequired = 'false';
        if (ArrayHelper::get($data, 'settings.validation_rules.required.value')) {
            $ariaRequired = 'true';
        }

        $elMarkup = "<input " . $this->buildAttributes($data['attributes'], $form) . " aria-invalid='false' aria-required={$ariaRequired}>";

        $html = $this->buildElementMarkup($elMarkup, $data, $form);
        $this->pushScripts($data, $form);
    
        $html = apply_filters_deprecated(
            'fluentform_rendering_field_html_' . $elementName,
            [
                $html,
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_html_' . $elementName,
            'Use fluentform/rendering_field_html_' . $elementName . ' instead of fluentform_rendering_field_html_' . $elementName
        );

        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form field HTML rendering
    }

    private function pushScripts($data, $form)
    {

        // We can add assets for this field
        wp_enqueue_style('pickr');
        wp_enqueue_script('pickr');

        add_action('wp_footer', function () use ($data, $form) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    function initColorPicker() {
                        var pickr = Pickr.create({
                            el: <?php echo wp_json_encode('#' . $data['attributes']['id'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                            theme: 'monolith', // or 'monolith', or 'nano'
                            useAsButton: true,
                            swatches: null,
                            default: <?php echo wp_json_encode($data['attributes']['value'] ? $data['attributes']['value'] : '#693030', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                            comparison: false,
                            lockOpacity: true,
                            autoReposition: false,
                            position: 'bottom-end',

                            components: {
                                // Main components
                                palette: true,
                                preview: true,
                                opacity: true,
                                hue: true,

                                // Input / output Options
                                interaction: {
                                    hex: false,
                                    rgba: false,
                                    hsla: false,
                                    hsva: false,
                                    cmyk: false,
                                    input: true,
                                    clear: true,
                                    save: true
                                }
                            },
                            strings: {
                                save: '<?php
                                    // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                                    _e('Save', 'fluentformpro');?>',  // Default for save button
                                clear: '<?php
                                    // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                                    _e('Clear', 'fluentformpro');?>', // Default for clear button
                                cancel: '<?php
                                    // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                                    _e('Cancel', 'fluentformpro');?>' // Default for cancel button
                            }
                        });

                        pickr.on('save', function (color, instance) {
                            if(!color) {
                                jQuery(instance._root.button).val('');
                            } else {
                                jQuery(instance._root.button).val(color.toHEXA().toString());
                            }
                            pickr.hide();
                        });
                    }


                    initColorPicker();
                    $(document).on('reInitExtras', '.<?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $form->instance_css_class; ?>', function () {
                        initColorPicker();
                    });
                });
            </script>
            <?php
        }, 9999);
    }
}
