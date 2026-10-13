<?php

namespace FluentFormPro\Components\DynamicField;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DynamicPost extends DynamicBase
{

    public function __construct()
    {
        parent::__construct('post', 'posts', $this->joinTables());
    }

    protected function joinTables()
    {
        return [
            [
                'enable'  => false,
                'columns' => ['meta_key', 'meta_value'],
                'join'    => ['postmeta', 'posts.ID', '=', 'postmeta.post_id']
            ]
        ];
    }

    public function selectableColumns()
    {
        return [
            'ID',
            'post_author',
            'post_parent',
            'post_title',
            'post_name',
            'post_excerpt',
            'post_status',
            'post_type',
        ];
    }

    public function getSupportedColumns()
    {
        return [
            'ID'           => __('Post ID', 'fluentformpro'),
            'post_author'  => __('Author', 'fluentformpro'),
            'post_parent'  => __('Parent Post', 'fluentformpro'),
            'post_content' => __('Post Content', 'fluentformpro'),
            'post_title'   => __('Post Title', 'fluentformpro'),
            'post_name'    => __('Post Slug', 'fluentformpro'),
            'post_excerpt' => __('Post Excerpt', 'fluentformpro'),
            'post_status'  => __('Post Status', 'fluentformpro'),
            'post_type'    => __('Post Type', 'fluentformpro'),
            'post_date'    => __('Post Date', 'fluentformpro'),
            'meta_key'     => __('Meta Key', 'fluentformpro'),
            'meta_value'   => __('Meta Value', 'fluentformpro'),
        ];
    }

    public function getDefaultConfig()
    {
        $filters = [
            [
                [
                    'column'    => 'post_type',
                    'custom'   => false,
                    'operator' => '=',
                    'value'    => 'post'
                ],
                [
                    'column'    => 'post_status',
                    'custom'   => false,
                    'operator' => '=',
                    'value'    => 'publish'
                ]
            ]
        ];
        return [
            'filters'        => $filters,
            'sort_by'        => 'ID',
            'order_by'       => 'DESC',
            'result_limit'   => $this->getResultLimit(),
            'template_value' => [
                'value'  => '{ID}',
                'custom' => false
            ],
            'template_label' => [
                'value'  => '{post_title} ({ID})',
                'custom' => true
            ]
        ];
    }

    public function setResult()
    {
        parent::setResult();

        if ($this->authorCan('read_private_posts')) {
            return;
        }

        $public = get_post_stati(['public' => true]);

        $filtered = [];
        foreach ($this->result as $row) {
            if (isset($row->post_status) && in_array($row->post_status, $public, true)) {
                $filtered[] = $row;
            }
        }

        $this->result = $filtered;
    }

    /**
     * Retrieve the value options for the editor.
     *
     * @return array The value options array.
     */
    public function getValueOptions()
    {
        $postTitles = $postName = $postExcerpt = $IDs = [];
        foreach (get_posts(['posts_per_page' => $this->getEditorValueOptionsLimit()]) as $post) {
            $IDs[$post->ID] = $post->ID;

            if ($post->post_title) {
                $postTitles[$post->post_title] = $post->post_title;
            }

            if ($post->post_excerpt) {
                $postExcerpt[$post->post_excerpt] = $post->post_excerpt;
            }

            if ($post->post_name) {
                $postName[$post->post_name] = $post->post_name;
            }
        }
        return [
            'ID'           => $IDs,
            'post_title'   => $postTitles,
            'post_excerpt' => $postExcerpt,
            'post_name'    => $postName,
            'post_author'  => $this->getPostAuthors(),
            'post_type'    => $this->getPostTypes(),
            'post_status'  => $this->getPostStatuses(),
        ];
    }

    /**
     * Retrieve the available post types.
     *
     * @return array An associative array of post types with their labels.
     */
    private function getPostTypes()
    {
        $types = [];
        foreach (get_post_types([], 'objects') as $type) {
            $types[$type->name] = "{$type->label} ({$type->name})";
        }
        return $types;
    }

    /**
     * Retrieve the available post statuses.
     *
     * @return array An associative array of post statuses with their labels.
     */
    private function getPostStatuses()
    {
        $statuses = [];
        foreach (get_post_stati([], 'objects') as $status) {
            $statuses[$status->name] = "{$status->label} ({$status->name})";
        }
        return $statuses;
    }

    /**
     * Retrieve the available post authors.
     *
     * @return array An associative array of post authors with their display names.
     */
    private function getPostAuthors()
    {
        // Get users with roles that typically can publish posts
        $users = get_users([
            'role__in' => ['administrator', 'editor', 'author'],
            'fields' => ['ID', 'display_name']
        ]);
        $authors = [];
        foreach ($users as $user) {
            $authors[$user->ID] = "{$user->display_name} ({$user->ID})";
        }
        return $authors;
    }

}