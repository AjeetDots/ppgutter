<?php

namespace FluentFormPro\Components\Post;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Components\Post\EditorSettings;
use FluentFormPro\Components\Post\PostFormHandler;

class Bootstrap
{
    public static function boot()
    {
        return new static;
    }

    public function __construct()
    {
        $isEnabled = $this->isEnabled();

        add_filter('fluentform/global_addons', function ($addons) use ($isEnabled) {
            $app = wpFluentForm();

            $addons['postFeeds'] = [
                'title' => __('Post/CPT Creation', 'fluentformpro'),
                'category' => 'wp_core',
                'description' => __('Create post/any cpt on form submission. It will enable many new features including dedicated post fields', 'fluentformpro'),
                'logo' => fluentFormMix('img/integrations/post-creation.png'),
                'enabled' => ($isEnabled) ? 'yes' : 'no'
            ];

            return $addons;
        });

        if (!$isEnabled) {
            return;
        }

        $this->registerHooks();

        $this->registerPostFields();
    }

    protected function registerHooks()
    {
        add_filter('fluentform/all_forms_vars', function ($settings) {
            $settings['has_post_feature'] = true;
            return $settings;
        });

        add_filter('fluentform/response_render_taxonomy', [$this, 'formatResponse'], 10, 4);

        $editorSettings = new EditorSettings;

        add_action('fluentform/inserted_new_form', [
            $editorSettings, 'onNewFormCreated'
        ]);

        add_filter('fluentform/editor_components', [
            $editorSettings, 'registerEditorTaxonomyFields'
        ], 10, 2);

        add_filter('fluentform/editor_element_settings_placement', [
            $editorSettings, 'elementPlacementSettings'
        ]);

        add_filter('fluentform/form_settings_menu', [
            $editorSettings, 'registerPostFormSettingsMenu'
        ], 10, 2);

        $postFormHandler = new PostFormHandler;
    
        add_filter('fluentform/smartcode_group_post', [
            $postFormHandler, 'parsePostShortCodes'
        ], 10, 2);
        
        add_action('fluentform/render_item_taxonomy', [
            $postFormHandler, 'renderTaxonomyFields'
        ], 10, 2);

        $createPostBeforeFormActions = apply_filters('fluentform/create_post_before_form_actions_processing', true);
        if ($createPostBeforeFormActions) {
            add_action('fluentform/before_form_actions_processing', [
                $postFormHandler, 'onFormSubmissionInserted'
            ], 10, 3);
        } else {
            add_action('fluentform/submission_inserted_post_form', [
                $postFormHandler, 'onFormSubmissionInserted'
            ], 10, 3);
        }

        add_filter('fluentform/nonce_verify', [
            $postFormHandler, 'requireNonceForLoggedInPostFeed'
        ], 10, 2);

        add_action('fluentform/form_element_start', [
            $postFormHandler, 'maybeRenderPostSelectionField'
        ], 10, 3);
    
        
        $postFormPopulate = new PopulatePostForm;

        add_action('wp_ajax_fluentformpro_get_post_details', [$postFormPopulate, 'getPostDetails']);
        add_action('wp_ajax_nopriv_fluentformpro_get_post_details', [$postFormPopulate, 'getPostDetails']);


        add_filter('fluentform/all_editor_shortcodes', function ($shortCodes) {
            $shortCodes[] = [
                'title' => __('Post', 'fluentformpro'),
                'shortcodes' => [
                    '{post.ID}' => 'Post ID',
                    '{post.post_title}' => 'Post Title',
                    '{post.post_permalink}' => 'Post Permalink'
                ]
            ];

            return $shortCodes;
        });
    }

    protected function registerPostFields()
    {
        new \FluentFormPro\Components\Post\Components\PostTitle;
        new \FluentFormPro\Components\Post\Components\PostContent('post_content', 'Post Content', ['content', 'post_content', 'post', 'editor'], 'post');
        new \FluentFormPro\Components\Post\Components\PostExcerpt;
        new \FluentFormPro\Components\Post\Components\FeaturedImage;
        new \FluentFormPro\Components\Post\Components\PostUpdate;
    }

    private function isEnabled()
    {
        $globalModules = (array)get_option('fluentform_global_modules_status');

        if ($globalModules) {
            return ArrayHelper::get($globalModules, 'postFeeds') == 'yes';
        }

        return false;
    }

    public function formatResponse($response, $field, $form_id, $isHtml)
    {
        if (!$response) {
            return;
        }

        if (!is_array($response) && !is_numeric($response)) {
            return $response;
        }

        $options = ArrayHelper::get($field, 'raw.settings.options', []);

        if (!is_array($response)) {
            $response = [$response];
        }

        // Bulk-fetch any term IDs not found in the options map
        $termMap = [];
        $missingIds = [];
        foreach ($response as $term_id) {
            if (!isset($options[$term_id]) && is_numeric($term_id)) {
                $missingIds[] = (int) $term_id;
            }
        }

        if ($missingIds) {
            $terms = get_terms([
                'include'    => array_unique($missingIds),
                'hide_empty' => false,
            ]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $termMap[$term->term_id] = $term->name;
                }
            }
        }

        $formattedResponse = [];

        foreach ($response as $term_id) {
            if (isset($options[$term_id])) {
                $formattedResponse[] = $options[$term_id];
            } elseif (isset($termMap[(int) $term_id])) {
                $formattedResponse[] = $termMap[(int) $term_id];
            } else {
                $formattedResponse[] = $term_id;
            }
        }

        if (!$isHtml) {
            return fluentImplodeRecursive(', ', $formattedResponse);
        }

        $html = '<ul class="ff_entry_list">';
        foreach ($formattedResponse as $response) {
            $html .= '<li>' . esc_html($response) . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
}
