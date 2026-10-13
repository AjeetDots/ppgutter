<?php

namespace FluentFormPro\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentFormPro\Components\Post\PopulatePostForm;

class PostSelectionField extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'cpt_selection',
            'Post/CPT Selection',
            ['post', 'cpt', 'custom post type'],
            'advanced'
        );

        add_filter('fluentform/response_render_' . $this->key, function ($value) {
            if (!$value || !is_numeric($value)) {
                return $value;
            }
            $post = get_post($value);
            return (isset($post->post_title)) ? $post->post_title : $value;
        });
    }

    function getComponent()
    {
        return [
            'index'          => 29,
            'element'        => $this->key,
            'attributes'     => [
                'name'  => $this->key,
                'value' => '',
                'id'    => '',
                'class' => '',
            ],
            'settings'       => array(
                'dynamic_default_value'   => '',
                'label'                   => __('Post Selection', 'fluentformpro'),
                'admin_field_label'       => '',
                'help_message'            => '',
                'container_class'         => '',
                'label_placement'         => '',
                'placeholder'             => __('- Select -', 'fluentformpro'),
                'post_type_selection'     => 'post',
                'post_extra_query_params' => '',
                'enable_select_2'         => 'no',
                'validation_rules'        => array(
                    'required' => [
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ],
                ),
                'conditional_logics'      => array(),
            ),
            'editor_options' => array(
                'title'      => __('Post/CPT Selection', 'fluentformpro'),
                'icon_class' => 'ff-edit-dropdown',
                'element'    => 'select',
                'template'   => 'select'
            )
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'post_type_selection',
            'post_extra_query_params',
            'admin_field_label',
            'placeholder',
            'label_placement',
            'validation_rules'
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'name',
            'dynamic_default_value',
            'help_message',
            'container_class',
            'class',
            'conditional_logics',
            'enable_select_2'
        ];
    }

    public function generalEditorElement()
    {
        $args = [
            'public' => true
        ];
        $args = apply_filters_deprecated(
            'fluentform_post_type_selection_types_args',
            [
                $args
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/post_type_selection_types_args',
            'Use fluentform/post_type_selection_types_args instead of fluentform_post_type_selection_types_args.'
        );
        $postTypes = get_post_types(apply_filters('fluentform/post_type_selection_types_args', $args));

        $formattedTypes = [];
        foreach ($postTypes as $typeName => $label) {
            $formattedTypes[] = [
                'label' => ucfirst($label),
                'value' => $typeName
            ];
        }
    
        $formattedTypes = apply_filters_deprecated(
            'fluentform_post_selection_types',
            [
                $formattedTypes
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/post_selection_types',
            'Use fluentform/post_selection_types instead of fluentform_post_selection_types.'
        );

        $formattedTypes = apply_filters('fluentform/post_selection_types', $formattedTypes);

        return [
            'post_type_selection'     => [
                'template'  => 'select',
                'label'     => __('Select Post Type', 'fluentformpro'),
                'help_text' => __('Select Post Type that you want to show', 'fluentformpro'),
                'options'   => $formattedTypes
            ],
            'post_extra_query_params' => [
                'template'         => 'inputTextarea',
                'label'            => __('Extra Query Parameter', 'fluentformpro'),
                'help_text'        => __('Extra Query Parameter for CPT Query', 'fluentformpro'),
                'inline_help_text' => __('You can provide post query parameters for further filter. <a target="_blank" href="https://wpmanageninja.com/?p=1520634">Check the doc here</a>', 'fluentformpro')
            ]
        ];
    }

    public function render($data, $form)
    {
        (new PopulatePostForm())->renderPostSelectionField($data, $form);
    }
}
