<?php

namespace FluentFormPro\Components\Post;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Api\FormProperties;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\ConditionAssesor;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Components\Post\Components\HierarchicalTaxonomy;
use FluentFormPro\Components\Post\Components\NonHierarchicalTaxonomy;
use FluentFormPro\Components\PostSelectionField;

class PostFormHandler
{
    use Getter;
    public function renderTaxonomyFields($data, $form)
    {
        if ($form->type != 'post') return;

        if (isset($data['taxonomy_settings'])) {
            if ($data['taxonomy_settings']['hierarchical']) {
                return (new HierarchicalTaxonomy)->compile($data, $form);
            } else {
                return (new NonHierarchicalTaxonomy)->compile($data, $form);
            }
        }
    }

    public function maybeRenderPostSelectionField($form)
    {
        if ($form->type != 'post') return;

        $feeds = $this->getFormFeeds($form);

        if (!count($feeds)) {
            return;
        }

        foreach ($feeds as $feed) {
            $feed->value = json_decode($feed->value, true);

            if (ArrayHelper::get($feed->value, 'post_form_type') == 'update') {
                $postType = $this->getPostType($form);
                do_action_deprecated(
                    'fluentformpro_populate_post_form_values',
                    [
                        $form,
                        $feed->value,
                        $postType
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/populate_post_form_values',
                    'Use fluentform/populate_post_form_values instead of fluentformpro_populate_post_form_values.'
                );
                do_action('fluentform/populate_post_form_values', $form, $feed->value, $postType);

                return;
            }
        }


    }

    public function onFormSubmissionInserted($entryId, $formData, $form)
    {
        $feeds = $this->getFormFeeds($form);

        if (!count($feeds)) {
            return;
        }

        $postType = $this->getPostType($form);

        $entry = wpFluent()->table('fluentform_submissions')
            ->where('id', $entryId)
            ->first();

        foreach ($feeds as $feed) {
            $feed->value = json_decode($feed->value, true);

            if (!ArrayHelper::get($feed->value, 'feed_status')) continue;
            if (isset($feed->value['acf_mappings'])) {
                $feed->value['acf_mappings'] = AcfHelper::resolveDateFieldFormat($feed->value['acf_mappings'], $form);
            }
            if (isset($feed->value['jetengine_mappings'])) {
                $feed->value['jetengine_mappings'] = JetEngineHelper::resolveDateFieldFormat($feed->value['jetengine_mappings'], $form);
            }
            if (isset($feed->value['meta_fields_mapping'])) {
                $updatePostId = $this->resolveAuthorizedUpdatePostId($feed->value, $form, $formData);
                $feed->value['meta_fields_mapping'] = static::resolveCustomMetaFileTypeField($feed->value['meta_fields_mapping'], $form, $formData, $updatePostId);
            }
            $feed = ShortCodeParser::parse($feed->value, $entryId, $formData, $form);

            if (!$this->isConditionMet($feed, $formData)) {
                continue;
            }

            $this->createPostFromFeed($feed, $entry, $formData, $form, $postType);
        }
    }

    public function createPostFromFeed($feed, $entry, $formData, $form, $postType = false)
    {
        if (!is_object($entry)) {
            $entry = wpFluent()->table('fluentform_submissions')
                ->where('id', $entry)
                ->first();
        }
        if (!$postType) {
            $postType = $this->getPostType($form);
        }

        $postData = [
            'post_type'      => $postType,
            'post_status'    => ArrayHelper::get($feed, 'post_status'),
            'comment_status' => ArrayHelper::get($feed, 'comment_status'),
            'post_category'  => array(ArrayHelper::get($feed, 'default_category'))
        ];

        if (!empty($entry->user_id)) {
            $postData['post_author'] = $entry->user_id;
        }

        $postData = $this->mapPostFields($feed, $postData);
        $isUpdatePost = 'update' == ArrayHelper::get($feed, 'post_form_type');
        $postId = $this->resolveUpdatePostId($feed, $form, $formData);
        $authorizedPostId = $this->resolveAuthorizedUpdatePostId($feed, $form, $formData);
        $postData = $this->mapMetaFields($feed, $postData, $postType, $formData, $authorizedPostId);

        $formInputs = FormFieldsParser::getInputs($form, ['element', 'raw', 'attributes']);

        foreach ($formInputs as $field) {
            if ($field['element'] == 'featured_image') {
                $fieldName = $field['attributes']['name'];
                if (!empty($formData[$fieldName][0])) {
                    $postData['featured_Image'] = $formData[$fieldName][0];
                } elseif (
                    ArrayHelper::isTrue($formData, 'remove_featured_image') &&
                    $isUpdatePost &&
                    $authorizedPostId
                ) {
                    if (delete_post_thumbnail($authorizedPostId) && $attachmentId = get_post_thumbnail_id($authorizedPostId)) {
                        wp_delete_attachment($attachmentId);
                    };
                }
            }

            if (isset($field['raw']['taxonomy_settings'])) {
                $postData = $this->mapTaxonomyFields(
                    $field['raw']['taxonomy_settings'], $postData, $formData
                );
            }
        }
        if ($isUpdatePost && $postId) {
            $postData['ID'] = $postId;
        }
        $this->insertPost($feed, $postData, $form, $entry->id);
    }

    protected function isConditionMet($feed, $formData)
    {
        // We have to check if the feed meets the conditional Logic
        $conditionSettings = ArrayHelper::get($feed, 'conditionals');

        if (
            !$conditionSettings ||
            !ArrayHelper::isTrue($conditionSettings, 'status')
        ) {
            return true;
        }

        return ConditionAssesor::evaluate($feed, $formData);
    }

    public function getFormFeeds($form)
    {
        $formId = is_object($form) ? $form->id : $form;

        return wpFluent()->table('fluentform_form_meta')
            ->where('form_id', $formId)
            ->where('meta_key', 'postFeeds')
            ->get();
    }

    /**
     * The submit nonce is opt-in plugin-wide because page caches serve stale
     * nonces, but a post feed creates or overwrites a post using the submitter's
     * own capabilities, so a submit forged from a logged-in victim's browser
     * must not go through. Anonymous submits have no privileged session to abuse
     * and keep the default.
     */
    public function requireNonceForLoggedInPostFeed($shouldVerify, $formId)
    {
        if ($shouldVerify || !get_current_user_id()) {
            return $shouldVerify;
        }

        return $this->formHasEnabledPostFeed($formId);
    }

    protected function formHasEnabledPostFeed($formId)
    {
        foreach ($this->getFormFeeds($formId) as $feed) {
            $value = json_decode($feed->value, true);
            if ($value && ArrayHelper::get($value, 'feed_status')) {
                return true;
            }
        }

        return false;
    }

    public function getPostType($form)
    {
        $postSettings = wpFluent()->table('fluentform_form_meta')
            ->where('form_id', $form->id)
            ->where('meta_key', 'post_settings')
            ->first()->value;

        $postSettings = json_decode($postSettings);

        return $postSettings->post_type;
    }

    protected function mapPostFields($feed, $postData)
    {
        foreach ($feed['post_fields_mapping'] as $postFieldMapping) {
            $postField = $postFieldMapping['post_field'];

            if ($postField != 'post_title') {
                $postData[$postField] = $postFieldMapping['form_field'];
            } else {
                $postData[$postField] = wp_strip_all_tags($postFieldMapping['form_field']);
            }
        }

        return $postData;
    }

    protected function resolveUpdatePostId($feed, $form, $formData)
    {
        if ('update' != ArrayHelper::get($feed, 'post_form_type')) {
            return null;
        }
        if ($postField = FormFieldsParser::getElement($form, 'post_update', ['raw'])) {
            $postFieldName = ArrayHelper::get(array_pop($postField), 'raw.attributes.name', '');
            return ArrayHelper::get($formData, $postFieldName);
        }
        return null;
    }

    // The featured-image and attachment removals run before insertPost() decides whether
    // this actor may update the target at all, so they need the same edit_post answer first.
    protected function resolveAuthorizedUpdatePostId($feed, $form, $formData)
    {
        $postId = (int) $this->resolveUpdatePostId($feed, $form, $formData);

        if (!$postId || !current_user_can('edit_post', $postId)) {
            return null;
        }

        // Same ownership rule insertPost() applies: an owner-only field refuses non-authors
        // even when they could otherwise edit the post.
        $allowedGuest = !!ArrayHelper::get($feed, 'allowed_guest_user', true);
        if (!$allowedGuest && !$this->ifUserCanUpdate($form, ['ID' => $postId])) {
            return null;
        }

        return $postId;
    }

    protected function mapMetaFields($feed, $postData, $postType, $formData = [], $postId = null)
    {
        $metaInputs = ArrayHelper::get($feed, 'meta_fields_mapping', []);

        $isUpdate = ('update' == ArrayHelper::get($feed, 'post_form_type'));

        foreach ($metaInputs as $metaFieldMapping) {
            if (!isset($metaFieldMapping['meta_value'])) {
                continue;
            }
            $isFile = (false !== strpos($metaFieldMapping['meta_value'], 'uploads/fluentform'));

            //store old attachment's id when update file meta
            $existingAttachmentIds = [];
            if ($isFile && $isUpdate && $postId) {
                $existingValue = get_post_custom_values($metaFieldMapping['meta_key'], $postId);
                if ($existingValue && ($existingValue) > 0) {
                    $existingAttachmentIds = explode(',', $existingValue[0]);
                }
            }

            if ($isFile) {
                //make attachment's
                $files = explode(', ', $metaFieldMapping['meta_value']);
                $attachmentIds = [];
                foreach ($files as $file) {
                    if ($attachmentId = $this->getAttachmentToImageUrl($file)) {
                        $attachmentIds[] = $attachmentId;
                    }
                }
                // merge old and new attachment's
                if ($existingAttachmentIds) {
                    $attachmentIds = array_filter(array_merge($attachmentIds, $existingAttachmentIds));
                }
                $metaFieldMapping['meta_value'] = join(',', $attachmentIds);
            }
            $metaKey = $metaFieldMapping['meta_key'];
            $postData['meta_input'][$metaKey] = $metaFieldMapping['meta_value'];
        }

        if (AcfHelper::hasAcf()) {
            $postData['acf_mappings'] = [];

            if ($acfFields = ArrayHelper::get($feed, 'acf_mappings', [])) {
                $metaValues = AcfHelper::prepareGeneralFieldsData($acfFields, $postType, $isUpdate);
                if ($metaValues) {
                    $postData['acf_mappings'] = array_merge($postData['acf_mappings'], $metaValues);
                }
            }

            if ($advancedAcfFields = ArrayHelper::get($feed, 'advanced_acf_mappings', [])) {
                $metaValues = AcfHelper::prepareAdvancedFieldsData($advancedAcfFields, $formData, $postType, $isUpdate, $postId);
                if ($metaValues) {
                    $postData['acf_mappings'] = array_merge($postData['acf_mappings'], $metaValues);
                }
            }
        }

        if (JetEngineHelper::hasJetEngine() && $metaValues = JetEngineHelper::prepareMetaValues($feed, $postType, $formData, $isUpdate, $postId)) {
           $postData['meta_input'] = array_merge(ArrayHelper::get($postData, 'meta_input', []), $metaValues);
        }

        if (MetaboxHelper::hasMetabox()) {
            $metaboxFields = [
                'general'  => ArrayHelper::get($feed, 'metabox_mappings', []),
                'advanced' => ArrayHelper::get($feed, 'advanced_metabox_mappings', [])
            ];
            $metaBoxValues = MetaboxHelper::prepareFieldsData($metaboxFields, $postType, $formData, $isUpdate, $postId);
            if ($metaBoxValues) {
                $postData['metabox_mappings'] = $metaBoxValues;
            }
        }

        return $postData;
    }

    protected function mapTaxonomyFields($taxonomySettings, $postData, $formData)
    {
        $taxonomyFieldName = $taxonomySettings['name'];

        if (!isset($formData[$taxonomyFieldName])) {
            return $postData;
        }

        $taxonomyData = $formData[$taxonomyFieldName];

        if ($taxonomyFieldName == 'category') {
            if ($taxonomyData) {
                $postData['post_category'] = (array)$taxonomyData;
            }
        } else if ($taxonomyFieldName == 'post_tag') {
            $tags = explode(',', $taxonomyData);
            $postData['tags_input'] = array_map('trim', $tags);
        } else {
            $postData['tax_input'][$taxonomyFieldName] = $taxonomyData;
        }

        return $postData;
    }

    protected function insertPost($feed, $postData, $form, $entryId)
    {
        $acfFields = [];
        if (isset($postData['acf_mappings'])) {
            $acfFields = $postData['acf_mappings'];
            unset($postData['acf_mappings']);
        }

        $metaBoxFields = [];
        if(isset($postData['metabox_mappings'])) {
            $metaBoxFields = $postData['metabox_mappings'];
            unset($postData['metabox_mappings']);
        }

        $taxInput = null;
        if (isset($postData['tax_input'])) {
            $taxInput = $postData['tax_input'];
            unset($postData['tax_input']);
        }
        $updatingPost = false;
        $allowedGuest = !!ArrayHelper::get($feed, 'allowed_guest_user', true);
        if (isset($feed['post_form_type']) && $feed['post_form_type'] == 'update') {
            if (empty($postData['ID'])) {
                $this->logAction($form->id, $entryId, "Update Skipped because of Empty Post ID",
                    'Post Updated Skipped', 'failed');
                return;
            }
            if (!$allowedGuest && empty($postData['post_author'])) {
                $this->logAction($form->id, $entryId, "Update not allowed from Guest User",
                    'Post Updated Skipped', 'info');
                return;
            }

            if (!$allowedGuest && !$userCanUpdate = $this->ifUserCanUpdate($form, $postData)) {
                $this->logAction($form->id, $entryId, "Update skipped because current user isn't Author of this post.",
                    'Post Updated Skipped', 'failed');
                return;
            }

            // SECURITY (PRO-24): the target post id comes from the submission's post_update
            // field, and the ownership check above was skipped whenever allowed_guest_user was
            // on (the default) — letting an unauthenticated visitor overwrite any post/page by
            // choosing its id. Always require the current actor to be able to edit this exact
            // post before updating it.
            if (!current_user_can('edit_post', (int) $postData['ID'])) {
                $this->logAction($form->id, $entryId, "Update skipped: not authorized to edit the target post.",
                    'Post Updated Skipped', 'failed');
                return;
            }

            $updatingPost = true;
            $postId = wp_update_post($postData, true);
            //@todo popuplate custom fields and taxonomies

        } else {
            if (!$allowedGuest && empty($postData['post_author'])) {
                $this->logAction($form->id, $entryId, "Create not allowed from Guest User",
                    'Post Created Skipped', 'info');
                return;
            }
            $postId = wp_insert_post($postData);
        }

        if (is_wp_error($postId)) {
            $this->logAction($form->id, $entryId, $postId->get_error_message(), 'Post Integration Failed', 'failed');
            return;
        }

        // Since WP doesn't allow setting terms for logged-out user,
        // we'll handle it manually by saving the terms by looping.
        if ($taxInput) {
            foreach ($taxInput as $taxonomy => $tags) {
                wp_set_post_terms($postId, $tags, $taxonomy);
            }
        }

        if ($acfFields && function_exists('update_field')) {
            foreach ($acfFields as $fieldKey => $value) {
                update_field($fieldKey, $value, $postId);
            }
            if (function_exists('acf_save_post_revision') && !apply_filters('fluentform/disable_acf_post_meta_revision', false)) {
                acf_save_post_revision($postId);
            }
        }

        if($metaBoxFields && function_exists('rwmb_set_meta')) {
            foreach ($metaBoxFields as $fieldKey => $value) {
                rwmb_set_meta($postId, $fieldKey, $value);
            }
        }

        $editLink = false;
        $postType = get_post_type($postId);

        if ($postType && post_type_exists($postType)) {
            $editLink = get_edit_post_link($postId);
        }

        if (!$editLink) {
            $editLink = admin_url('post.php?post=' . $postId . '&action=edit');
        }
        $type = $updatingPost ?' Updated' : ' Created ';
        $info = 'WP Post/CPT'.$type.' from submission. Post ID: ' . $postId . '. <a href="' . $editLink . '" target="_blank">Edit Post/CPT</a>';

        $this->logAction($form->id, $entryId, $info ,"Post $type from form submission", 'success' );

        wpFluent()->table('fluentform_submission_meta')
            ->insert([
                'response_id' => $entryId,
                'form_id'     => $form->id,
                'meta_key'    => '__postFeeds_created_id',
                'value'       => $postId,
                'name'        => 'Post/CPT Created',
                'created_at'  => current_time('mysql'),
                'updated_at'  => current_time('mysql'),
            ]);

        update_post_meta($postId, '_fluentform_id', $form->id);

        if (!function_exists('set_post_thumbnail')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        if (!empty($feed['post_format'])) {
            $format = 'post-format-' . $feed['post_format'];
            wp_set_post_terms($postId, $format, 'post_format');
        }

        if (isset($postData['featured_Image'])) {
            $this->setFeaturedImage($postId, $postData['featured_Image']);
        }

        do_action_deprecated(
            'fluentform_post_integration_success',
            [
                $postId,
                $postData,
                $entryId,
                $form,
                $feed
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/post_integration_success',
            'Use fluentform/post_integration_success instead of fluentform_post_integration_success.'
        );
        do_action('fluentform/post_integration_success', $postId, $postData, $entryId, $form, $feed);
    }

    protected function setFeaturedImage($postId, $featuredImage)
    {
        $attach_id = $this->getAttachmentToImageUrl($featuredImage);
        if ($attach_id) {
            // And finally assign featured image to post
            return set_post_thumbnail($postId, $attach_id);
        }

        return false;
    }

    public function getAttachmentToImageUrl($fileUrl, $postId = 0)
    {
        //for media upload check if already uploaded as media
        $attachmentId = attachment_url_to_postid($fileUrl);
        if ($attachmentId) {
            return $attachmentId;
        }
        $path = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR;
        $path = apply_filters('fluentform/post_form_default_upload_path', $path, $postId);

        $file = $path . substr($fileUrl, strrpos($fileUrl, '/'));

        $upload_dir = wp_upload_dir();

        if (strpos($file, '-ff-') !== false) {
            $fileArray = explode('-ff-', $file);
            $actualName = array_pop($fileArray);
        } else {
            $actualName = basename($file);
        }

        $unique_file_name = wp_unique_filename($upload_dir['path'], $actualName); // Generate unique name
        $destination = $upload_dir['path'] . '/' . $unique_file_name;

        $newFileUrl = $upload_dir['url'] . '/' . $unique_file_name;

        $result = copy($file, $destination);


        $filePath = $file;
        if ($result) {
            $filePath = $destination;
        }

        // Check image file type
        $wp_filetype = wp_check_filetype($unique_file_name, null);


        // Set attachment data
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name($unique_file_name),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $newFileUrl
        );

        // Create the attachment
        $attach_id = wp_insert_attachment($attachment, $filePath, $postId);


        if (!function_exists('wp_generate_attachment_metadata')) {
            // Include image.php
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        // Define attachment metadata
        $attach_data = wp_generate_attachment_metadata($attach_id, $filePath);

        // Assign metadata to attachment
        wp_update_attachment_metadata($attach_id, $attach_data);
        return $attach_id;
    }

    /**
     * Log Integration result
     *
     * @param $formId
     * @param $entryId
     * @param string $info
     * @param string $title
     * @param string $status
     *
     * @return void
     */
    protected function logAction($formId, $entryId, $info, $title, $status)
    {
        $logData = [
            'parent_source_id' => $formId,
            'source_type'      => 'submission_item',
            'source_id'        => $entryId,
            'component'        => 'postFeeds',
            'status'           => $status,
            'title'            => $title,
            'description'      => $info
        ];
        do_action('fluentform/log_data', $logData);
    }
    
    private function ifUserCanUpdate($form, $postData)
    {
        $status = true;
        $formInputs = (new FormProperties($form))->inputs(['raw']);
        if ($postUpdateField = ArrayHelper::get($formInputs, 'post_update')) {
            if (ArrayHelper::get($postUpdateField, 'raw.settings.allow_view_posts') === 'current_user_post') {
                if (get_post_field('post_author', $postData['ID']) != get_current_user_id()) {
                    $status = false;
                }
            }
        }
        return $status;
    }
    
    public function parsePostShortCodes($property, $parser)
    {
        $entry = $parser::getEntry();
        $entryId = $entry->id;
        $postIdObject = wpFluent()->table('fluentform_submission_meta')
            ->select(['value'])
            ->where(
                [
                    'response_id' => $entryId,
                    'meta_key'    => '__postFeeds_created_id'
                ]
            )
            ->first();
        $post = get_post($postIdObject->value);
        if (!$post) {
            return '';
        }
        if ($property == 'ID') {
            return $post->ID;
        } elseif ($property == 'post_title') {
            return $post->post_title;
        } elseif ($property == 'post_permalink') {
            return get_permalink($post);
        }
        return '';
    }
}
