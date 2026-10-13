<?php

namespace FluentFormPro\Components\Post\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Components\Uploader;

class FeaturedImage extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'featured_image',
            'Featured Image',
            ['image', 'featured_image'],
            'post'
        );

        add_filter('fluentform/response_render_featured_image', function ($response, $field, $form_id, $isHtml = false) {
            return \FluentForm\App\Modules\Form\FormDataParser::formatImageValues($response, $isHtml);
        }, 10, 4);

        add_filter('fluentform/editor_init_element_featured_image', function ($element) {
            if (!isset($element['settings']['enable_crop'])) {
                $element['settings']['enable_crop'] = 'no';
            }
            if (!isset($element['settings']['crop_mode'])) {
                $element['settings']['crop_mode'] = 'ratio';
            }
            if (!isset($element['settings']['crop_ratio'])) {
                $element['settings']['crop_ratio'] = 'free';
            }
            if (!isset($element['settings']['crop_width'])) {
                $element['settings']['crop_width'] = '';
            }
            if (!isset($element['settings']['crop_height'])) {
                $element['settings']['crop_height'] = '';
            }
            return $element;
        });

    }

    function getComponent()
    {
        return [
            'index'          => 3,
            'element'        => $this->key,
            'attributes'     => [
                'name'        => $this->key,
                'class'       => '',
                'value'       => '',
                'type'        => 'file',
                'placeholder' => '',
                'accept' => 'image/*'
            ],
            'settings'       => [
                'container_class'    => '',
                'placeholder'        => '',
                'label'              => $this->title,
                'label_placement'    => '',
                'help_message'       => '',
                'admin_field_label'  => '',
                'btn_text' => __('Choose File', 'fluentformpro'),
                'enable_crop'        => 'no',
                'crop_mode'          => 'ratio',
                'crop_ratio'         => 'free',
                'crop_width'         => '',
                'crop_height'        => '',
                'validation_rules'   => [
                    'required'            => [
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ],
                    'max_file_size'       => [
                        'value'          => 1048576,
                        '_valueFrom'     => 'MB',
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('max_file_size'),
                        'global_message' => Helper::getGlobalDefaultMessage('max_file_size'),
                    ],
                    'max_file_count'      => [
                        'value'          => 1,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('max_file_count'),
                        'global_message' => Helper::getGlobalDefaultMessage('max_file_count'),
                    ],
                    'allowed_image_types' => [
                        'value'          => array(),
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('allowed_image_types'),
                        'global_message' => Helper::getGlobalDefaultMessage('allowed_image_types'),
                    ]
                ],
                'conditional_logics' => []
            ],
            'editor_options' => [
                'title' => $this->title,
                'icon_class' => 'ff-edit-images',
                'template' => 'inputFile'
            ],
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'admin_field_label',
            'btn_text',
            'placeholder',
            'value',
            'label_placement',
            'validation_rules',
            'enable_crop',
            'crop_mode',
            'crop_ratio',
            'crop_width',
            'crop_height',
        ];
    }

    public function render($data, $form)
    {
        $elementName = $data['element'];

        $data = apply_filters_deprecated(
            'fluentform_rendering_field_data_'.$elementName,
            [
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_data_'.$elementName,
            'Use fluentform/rendering_field_data_'.$elementName . ' instead of fluentform_rendering_field_data_'.$elementName
        );

        $data = apply_filters('fluentform/rendering_field_data_'.$elementName, $data, $form);

        $data['attributes']['class'] = @trim(
            'ff-el-form-control '. $data['attributes']['class'].' ff-screen-reader-element'
        );

        $data['attributes']['id'] = $this->makeElementId($data, $form);

        $data['attributes']['multiple'] = false;

        if($tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex()) {
            $data['attributes']['tabindex'] = $tabIndex;
        }
        
        $btnText = ArrayHelper::get($data, 'settings.btn_text');

        if(!$btnText) {
            $btnText = __('Choose File', 'fluentformpro');
        }

        $elMarkup = "<label for='" . esc_attr($data['attributes']['id']) . "' class='ff_file_upload_holder'><span class='ff_upload_btn ff-btn'>" . fluentform_sanitize_html($btnText) . "</span> <input %s></label>";

        $elMarkup = sprintf($elMarkup, $this->buildAttributes($data['attributes'], $form));

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
            'Use fluentform/rendering_field_html_' . $elementName . ' instead of fluentform_rendering_field_html_'. $elementName
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form);

        $this->enqueueProScripts();
    }

    public function enqueueProScripts()
    {
        (new Uploader())->enqueueProScripts();
    }

    public function pushTags($tags, $form)
    {
        if ($form->type != 'post') {
            return $tags;
        }
        $tags[$this->key] = $this->tags;
        return $tags;
    }
}
