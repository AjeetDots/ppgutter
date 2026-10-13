<?php

namespace FluentFormPro\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class RangeSliderField extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'rangeslider',
            'Range Slider',
            ['range', 'number', 'slider'],
            'advanced'
        );

        add_filter('fluentform/editor_init_element_rangeslider', function ($item) {
            if (!isset($item['settings']['number_step'])) {
                $item['settings']['number_step'] = '';
            }
            // @todo : Fix order of the following
            if (!isset($item['settings']['enable_target_product'])) {
                $item['settings']['enable_target_product'] = 'no';
            }
            if (!isset($item['settings']['target_product'])) {
                $item['settings']['target_product'] = '';
            }
            return $item;
        });
        /* Todo : add to validation rules */
        add_filter('fluentform/validate_input_item_rangeslider', function ($error, $field, $formData) {
            $min = ArrayHelper::get($field, 'raw.attributes.min');
            $max = ArrayHelper::get($field, 'raw.attributes.max');
            $name = ArrayHelper::get($field, 'raw.attributes.name');
            if ($userValue = ArrayHelper::get($formData, $name)) {
                if (!$min) {
                    $min = 0;
                }
                if (($max !== "" && $max < $userValue)  || $min > $userValue) {
                    $error = __('Value is not within range', 'fluentformpro');
                }
            }
            return $error;
        }, 10, 3);
        
    }

    function getComponent()
    {
        return [
            'index'          => 15,
            'element'        => $this->key,
            'attributes'     => [
                'name'  => $this->key,
                'class' => '',
                'value' => '',
                'min'   => 0,
                'max'   => 10,
                'type'  => 'range'
            ],
            'settings'       => [
                'number_step'           => '',
                'container_class'       => '',
                'placeholder'           => '',
                'label'                 => $this->title,
                'label_placement'       => '',
                'help_message'          => '',
                'admin_field_label'     => '',
                'enable_target_product' => 'no',
                'target_product'        => '',
                'validation_rules'      => [
                    'required' => [
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ],
                ],
                'conditional_logics'    => [],
            ],
            'editor_options' => [
                'title'      => $this->title,
                'icon_class' => 'dashicons dashicons-leftright',
                'template'   => 'inputSlider'
            ],
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'label_placement',
            'admin_field_label',
            'value',
            'min',
            'max',
            'number_step',
            'validation_rules',
            'enable_target_product',
            'target_product',
        ];
    }
    public function generalEditorElement()
    {
        return [
            'enable_target_product' => [
                'template'  => 'radio',
                'options'   => [
                    [
                        'value' => 'yes',
                        'label' => __('Yes', 'fluentformpro'),
                    ],
                    [
                        'value' => 'no',
                        'label' => __('No', 'fluentformpro'),
                    ],
                ],
                'label'      => __('Enable Quantity Mapping', 'fluentformpro'),
            ],
            'target_product' => [
                'template'  => 'targetProduct',
                'label' => __('Target Product Field', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/enable_target_product',
                    'value'      => 'yes',
                    'operator'   => '==',
                ],
            ],
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

        if ($tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex()) {
            $data['attributes']['tabindex'] = $tabIndex;
        }

        $data['attributes']['class'] = @trim('ff-el-form-control ' . $data['attributes']['class']);
        $data['attributes']['id'] = $this->makeElementId($data, $form);

        $this->registerScripts($form, $data['attributes']['id']);


        $data['attributes']['data-default_value'] = $data['attributes']['value'];
        if ($data['attributes']['value'] == '') {
            $data['attributes']['value'] = 0;
        }

        if ($step = ArrayHelper::get($data, 'settings.number_step')) {
            $data['attributes']['step'] = $step;
        }

        $data['attributes']['type'] = 'range';
        $data['attributes']['data-calc_value'] = $data['attributes']['value'];
        if (is_rtl()) {
            $data['attributes']['data-direction'] = 'rtl';
        }
        if(ArrayHelper::get($data,'settings.enable_target_product') == 'yes' && ArrayHelper::get($data,'settings.target_product') != '' ){
            $data['attributes']['class'] .= ' ff_quantity_item ff_quantity_item_slider';
            $data['attributes']['data-target_product'] = ArrayHelper::get($data, 'settings.target_product');
        }
        $ariaRequired = 'false';
        if (ArrayHelper::get($data, 'settings.validation_rules.required.value')) {
            $ariaRequired = 'true';
        }

        $elMarkup = "<div class='ff_slider_wrapper'><input " . $this->buildAttributes($data['attributes'], $form) . " aria-invalid='false' aria-required={$ariaRequired}><div class='ff_range_value'>" . $data['attributes']['value'] . "</div></div>";

        $html = $this->buildElementMarkup($elMarkup, $data, $form);
    
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

    private function registerScripts($form, $elementId)
    {
        wp_enqueue_script(
            'rangeslider',
            FLUENTFORMPRO_DIR_URL . 'public/libs/rangeslider/rangeslider.js',
            array('jquery'),
            '2.3.0',
            true
        );

        wp_enqueue_style(
            'rangeslider',
            FLUENTFORMPRO_DIR_URL . 'public/libs/rangeslider/rangeslider.css',
            array(),
            '2.3.0',
            'all'
        );

        if (did_action('wp_footer')) {
            $this->printJS($form, $elementId);
        } else {
            add_action('wp_footer', function () use ($form, $elementId) {
                $this->printJS($form, $elementId);
            }, 99);
        }
    }

    private function printJS($form, $elementId)
    {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                function initRangeSlider() {
                    var $element = $('.<?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $form->instance_css_class; ?>').find("#<?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $elementId ?>");

                    if (!$element.length) {
                        return;
                    }
                    
                    // Destroy any existing rangeslider instance first
                    if ($element.rangeslider !== undefined) {
                        $element.rangeslider('destroy');
                        $element.parent().find('.rangeslider').remove();
                    }

                    if ($element.data('plugin_rangeslider')) {
                        $element.rangeslider('destroy');
                    }

                    var hasCondition = $element.closest('.ff-el-group').hasClass('has-conditions');
                    var $valueWrapper = $element.parent().find('.ff_range_value');
                    var prevValue = 0;
                    var isRequired = !!$element.parents('.ff-el-group').children('.ff-el-is-required').length;

                    $element.rangeslider({
                        polyfill: false,
                        onSlide: function (position, value) {
                            if (prevValue != value) {
                                //attr added to check range slider is required or not
                                $element.attr('is-changed', true);
                                prevValue = value;
                                $element.trigger('keyup');
                                $valueWrapper.text(value);
                                $element.rangeslider('update', true);
                            }
                        },
                        onInit: function () {
                            if (isRequired) {
                                $element.attr('is-changed', false);
                                if ($element.data('default_value') !== undefined) {
                                    $element.attr('is-changed', true);
                                }
                                $element.val() ? $valueWrapper.text($element.val()) : $valueWrapper.text('');
                            } else {
                                $valueWrapper.text($element.val());
                            }
                        }
                    });

                    $element.on('change', function () {
                        $valueWrapper.text($element.val());
                        if (hasCondition) {
                            $element.rangeslider('update', true);
                        }
                    });
                }

                initRangeSlider();

                $(document).on('reInitExtras reInitRangeSliders', '.<?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $form->instance_css_class; ?>', function () {
                    initRangeSlider();
                });
            });
        </script>
        <?php
    }
}
