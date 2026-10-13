<?php
namespace FluentFormPro\Components\Post;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class PostFeedSettings
{
    /**
     * Handles fluentform_get_post_types ajax action
     * @return Array Possible post types to create a post form
     */
    public function getPostTypes()
    {
        $ignoredPostTypes = ['attachment'];

        $args = [
            'public' => true
        ];

        $publicPostTypes = get_post_types(apply_filters('fluentform/get_post_type_on_form_create', $args));

        return array_keys(array_diff($publicPostTypes, $ignoredPostTypes));
    }

    /**
     * Handles fluentform_get_post_settings ajax action
     * @return array Possible post settings for post feed management
     */
    public function getPostSettings()
    {
        $formId = intval($_REQUEST['form_id']);

        $postSettings = $this->getFormSettings($formId);

        $data['comment_statuses'] = ['open', 'close'];

        $data['post_statuses'] = ['draft', 'pending', 'private', 'publish'];

        $data['post_fields'] = ['post_title', 'post_content', 'post_excerpt', 'featured_image'];

        $data['post_info'] = $this->getPostInfo();

        $data['taxonomies'] = $this->getPostTaxonomies($postSettings->post_type);

        $data['has_acf'] = AcfHelper::hasAcf();
        if ($data['has_acf']) {
            $acfFields = AcfHelper::getAcfFields($postSettings->post_type);
            $data['acf_fields'] = $acfFields['general'];
            $data['acf_fields_advanced'] = $acfFields['advanced'];
        }

        $data['has_jetengine'] = JetEngineHelper::hasJetEngine();
        if ($data['has_jetengine']) {
            $jetengineFields = JetEngineHelper::getFields($postSettings->post_type);
            $data['jetengine_fields'] = $jetengineFields['general'];
            $data['jetengine_fields_advanced'] = $jetengineFields['advanced'];
        }

        $data['has_metabox'] = MetaboxHelper::hasMetabox();
        if ($data['has_metabox']) {
            $metaboxFields = MetaboxHelper::getPostFields($postSettings->post_type);
            $data['metabox_fields'] = $metaboxFields['general'];
            $data['metabox_fields_advanced'] = $metaboxFields['advanced'];
        }

        $data['post_formats'] = $this->getPostFormats();

        $data['categories'] = $categories = $this->getCategories();

        $data['default_feed'] = $this->getDefaultFeed(
            $this->getDefaultCategory($categories)
        );

        $data = apply_filters_deprecated(
            'ff_post_feed_settings_data_response',
            [
                $data,
                $formId,
                $postSettings
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/post_feed_settings_data_response',
            'Use fluentform/post_feed_settings_data_response instead of ff_post_feed_settings_data_response.'
        );
        return apply_filters('fluentform/post_feed_settings_data_response', $data, $formId, $postSettings);
    }

    protected function getPostInfo()
    {
        $postInfo = wpFluent()->table('fluentform_form_meta')
            ->where('form_id', intval($_REQUEST['form_id']))
            ->where('meta_key', 'post_settings')
            ->first();

        $postInfo->value = json_decode($postInfo->value);

        return $postInfo;
    }

    protected function getPostTaxonomies($postType)
    {

        return array_map(function ($taxonomy) {
            return [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label
            ];
        }, get_object_taxonomies($postType, 'object'));
    }

    private function getFormSettings($formId)
    {
        $form = wpFluent()->table('fluentform_forms')->where('id', $formId)->first();

        $value = wpFluent()->table('fluentform_form_meta')
            ->where('form_id', $form->id)
            ->where('meta_key', 'post_settings')
            ->first()->value;

        return json_decode($value);
    }

    protected function getPostFormats()
    {
        $postFormats = [];

        if (current_theme_supports('post-formats')) {
            $postFormats = get_theme_support('post-formats');
            if (is_array($postFormats[0])) {
                $postFormats = array_merge(['standard'], $postFormats[0]);
            }
        }

        return $postFormats;
    }

    protected function getCategories()
    {
        $allCategories = get_categories([
            'hide_empty' => 0,
            'order' => 'DESC',
            'order_by' => 'cat_ID'
        ]);

        $categories = [];

        foreach ($allCategories as $category) {
            $categories[] = [
                'category_id' => $category->cat_ID,
                'category_name' => $category->name
            ];
        }

        return $categories;
    }

    protected function getDefaultCategory($categories)
    {
        if (!$defaultCategory = intval(get_option('default_category'))) {
            $defaultCategory = @$categories[0]['category_id'];
        }

        return $defaultCategory;
    }

    protected function getDefaultFeed($defaultCategory)
    {
        $data = [
            'feed_name' => '',
            'feed_status' => true,
            'post_status' => 'publish',
            'post_format' => 'standard',
            'comment_status' => 'open',
            'post_fields_mapping' => [
                ['post_field' => 'post_title', 'form_field' => ''],
                ['post_field' => 'post_content', 'form_field' => ''],
                ['post_field' => 'post_excerpt', 'form_field' => ''],
            ],
            'meta_fields_mapping' => [],
            'default_category' => $defaultCategory,
            'post_form_type' => 'new',
            'allowed_guest_user' => true,
            'conditionals' => [
                'conditions' => [],
                'status' => false,
                'type' => 'all'
            ]
        ];

        if(AcfHelper::hasAcf()) {
            $data['acf_mappings'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
            $data['advanced_acf_mappings'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
        }

        if(JetEngineHelper::hasJetEngine()) {
            $data['jetengine_mappings'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
            $data['advanced_jetengine_mappings'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
        }

        if(MetaboxHelper::hasMetabox()) {
            $data['metabox_mappings'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
            $data['advanced_metabox_mappings'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
        }

        return $data;
    }
}
