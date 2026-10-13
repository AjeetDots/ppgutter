<?php

namespace FluentFormPro\Components\Post\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;

class DynamicTaxonomies
{
    protected $taxonomyObjects = null;

    public function __construct($taxonomyObjects)
    {
        $this->taxonomyObjects = $taxonomyObjects;
    }

    public function registerEditorTaxonomyFields($components)
    {
        $index = 0;

        $dynamicTags = [];

        foreach ($this->taxonomyObjects as $object) {
            $isHi = $object->hierarchical;
            $dynamicTags[] = $object->name;
            $taxonomy = [
                'index' => $index,
                'element' => 'taxonomy',
                'attributes' => [
                    'name' => $object->name,
                    'value' => '',
                    'id' => '',
                    'class' => '',
                    'type' => 'text',
                ],
                'taxonomy_settings' => [
                    'name' => $object->name,
                    'hierarchical' => $isHi
                ],
                'settings' => [
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'label' => __($object->label, 'fluentformpro'),
                    'admin_field_label' => '',
                    'help_message' => '',
                    'container_class' => '',
                    'label_placement' => '',
                    'placeholder' => $isHi ? __('- Select -', 'fluentformpro') : __('Type...', 'fluentformpro'),
                    'advanced_options' => [],
                    'validation_rules' => [
                        'required' => [
                            'value'          => false,
                            'global'         => true,
                            'message'        => Helper::getGlobalDefaultMessage('required'),
                            'global_message' => Helper::getGlobalDefaultMessage('required'),
                        ],
                    ],
                    'conditional_logics' => [],
                ],
                'editor_options' => [
                    'title' => $object->label,
                    'icon_class' => $isHi ? 'dashicons dashicons-category' : 'dashicons dashicons-tag',
                    'template' => 'taxonomy'
                ]
            ];

            if ($isHi) {
                $taxonomy['settings']['field_type'] = 'select_single';

                $terms = get_terms([
                    'order' => 'ASC',
                    'hide_empty' => false,
                    'taxonomy' => $object->name
                ]);

                foreach (TaxonomyOptionFormatter::formatLabels($terms) as $termId => $label) {
                    $taxonomy['settings']['options'][$termId] = $label;
                }
            }

            $components['taxonomy'][] = $taxonomy;

            $index++;
        }

        return $components;
    }
}