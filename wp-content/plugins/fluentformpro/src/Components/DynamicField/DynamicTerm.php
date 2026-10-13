<?php


namespace FluentFormPro\Components\DynamicField;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DynamicTerm extends DynamicBase
{

    public function __construct()
    {
        parent::__construct('term', 'terms', $this->joinTables());
    }

    protected function joinTables()
    {
        return [
            [
                'enable'  => false,
                'columns' => ['meta_key', 'meta_value'],
                'join'    => ['termmeta', 'terms.term_id', '=', 'termmeta.term_id']
            ],
            [
                'enable'  => false,
                'columns' => ['taxonomy', 'parent', 'description'],
                'join'    => ['term_taxonomy', 'terms.term_id', '=', 'term_taxonomy.term_id']
            ],
        ];
    }

    public function selectableColumns()
    {
        return [
            'terms.term_id',
            'name',
            'slug',
        ];
    }

    /**
     * Retrieve the value options for the editor.
     *
     * @return array The value options array.
     */
    public function getValueOptions()
    {
        $termIDs = $names = $taxonomy = $slugs = [];
        foreach (get_terms(['number' => $this->getEditorValueOptionsLimit()]) as $term) {
            if ($term->slug) {
                $slugs[$term->slug] = $term->slug;
            }
            if ($term->term_id) {
                $termIDs[$term->term_id] = $term->term_id;
            }

            if ($term->name) {
                $names[$term->name] = $term->name;
            }
            if ($term->taxonomy) {
                $taxonomy[$term->taxonomy] = $term->taxonomy;
            }
        }
        return [
            'terms.term_id'  => $termIDs,
            'name'     => $names,
            'taxonomy' => $taxonomy,
            'slug'     => $slugs,
        ];
    }

    public function getSupportedColumns()
    {
        return [
            'terms.term_id'=> __('Term ID', 'fluentformpro'),
            'name'        => __('Name', 'fluentformpro'),
            'parent'      => __('Parent Term', 'fluentformpro'),
            'slug'        => __('Slug', 'fluentformpro'),
            'taxonomy'    => __('Taxonomy', 'fluentformpro'),
            'description' => __('Term Description', 'fluentformpro'),
            'meta_key'    => __('Meta Key', 'fluentformpro'),
            'meta_value'  => __('Meta Value', 'fluentformpro'),
        ];
    }

    public function getDefaultConfig()
    {
        $filters = [
            [
                [
                    'column'    => 'taxonomy',
                    'operator' => '=',
                    'custom'   => false,
                    'value'    => 'category'
                ]
            ]
        ];
        return [
            'filters'        => $filters,
            'sort_by'        => 'terms.term_id',
            'order_by'       => 'DESC',
            'result_limit'   => $this->getResultLimit(),
            'template_value' => [
                'value'  => '{term_id}',
                'custom' => false
            ],
            'template_label' => [
                'value'  => '{name} ({term_id})',
                'custom' => true
            ]
        ];
    }
}