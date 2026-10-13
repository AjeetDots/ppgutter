<?php
/**
 * Plugin Name: Last Modified Column
 * Description: Adds a custom column in the WordPress admin area to show when posts/pages were last modified and by whom.
 * Author: MetaPixels
 * Author URI: https://metapixels.co.uk/
 * Version: 1.0
 */

// Ensure all functionality only runs in the admin area
if (is_admin()) {

    // Add a custom column for 'Last Modified'
    add_filter('manage_posts_columns', 'add_combined_column');
    add_filter('manage_pages_columns', 'add_combined_column');
    function add_combined_column($columns) {
        if (current_user_can('administrator'))
            $columns['last_modified_by'] = __('Last Edited', 'textdomain');
        return $columns;
    }

    // Populate the custom column with combined data and a line break
    add_action('manage_posts_custom_column', 'populate_combined_column', 10, 2);
    add_action('manage_pages_custom_column', 'populate_combined_column', 10, 2);
    function populate_combined_column($column_name, $post_id) {
        if ($column_name === 'last_modified_by') {
            $last_modified = get_post_field('post_modified', $post_id);
            $formatted_date = date_i18n('d/m/Y H:i', strtotime($last_modified));
            $elementor_editor_id = get_post_meta($post_id, '_elementor_last_editor', true);

            if ($elementor_editor_id) {
                $user_info = get_userdata($elementor_editor_id);
                if ($user_info)
                    $last_editor = esc_html($user_info->display_name);
                else
                    $last_editor = __('Unknown', 'textdomain');
            } else {
                $last_editor_id = get_post_meta($post_id, '_edit_last', true);
                if ($last_editor_id) {
                    $user_info = get_userdata($last_editor_id);
                    if ($user_info)
                        $last_editor = esc_html($user_info->display_name);
                    else
                        $last_editor = __('Unknown', 'textdomain');
                } else {
                    $last_editor = __('Unknown', 'textdomain');
                }
            }

            echo sprintf(
                __('%s<br> %s', 'textdomain'),
                esc_html($last_editor),
                esc_html($formatted_date)
            );
        }
    }

    add_filter('manage_edit-post_sortable_columns', 'make_combined_column_sortable');
    add_filter('manage_edit-page_sortable_columns', 'make_combined_column_sortable');
    function make_combined_column_sortable($columns) {
        if (current_user_can('administrator'))
            $columns['last_modified_by'] = 'post_modified';
        return $columns;
    }

    add_action('save_post', function ($post_id, $post, $update) {
        $current_user_id = get_current_user_id();
        update_post_meta($post_id, '_elementor_last_editor', $current_user_id);
    }, 10, 3);
}