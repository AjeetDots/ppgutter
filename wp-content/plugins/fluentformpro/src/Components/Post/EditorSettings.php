<?php

namespace FluentFormPro\Components\Post;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentFormPro\Components\Post\Components\DynamicTaxonomies;

class EditorSettings
{
    public function onNewFormCreated($formId)
    {
        if (isset($_REQUEST['post_type'])) {
            wpFluent()->table('fluentform_form_meta')->insert(array(
                'form_id' => $formId,
                'meta_key' => 'post_settings',
                'value' => json_encode([
                    'post_type' => sanitize_textarea_field($_REQUEST['post_type'])
                ])
            ));
        }
    }

    public function registerEditorTaxonomyFields($components, $formId)
    {
        if (!$formId = intval($formId)) {
            return $components;
        }

        $form = wpFluent()->table('fluentform_forms')->where('id', $formId)->first();

        if ($form->type != 'post') {
            return $components;
        }

        $meta = wpFluent()->table('fluentform_form_meta')
            ->where('form_id', $form->id)
            ->where('meta_key', 'post_settings')
            ->first();

        if (!$meta) return $components;

        $postSettings = json_decode($meta->value);

        $taxonomies = get_object_taxonomies($postSettings->post_type, 'object');

        return (new DynamicTaxonomies($taxonomies))->registerEditorTaxonomyFields($components);
    }

    public function elementPlacementSettings($placementSettings)
    {
        if (!isset($_REQUEST['form_id'])) {
            return $placementSettings;
        }
        $formId = intval($_REQUEST['form_id']);

        $meta = wpFluent()->table('fluentform_form_meta')
            ->where('form_id', $formId)
            ->where('meta_key', 'post_settings')
            ->first();

        if (!$meta) return $placementSettings;

        $value = json_decode($meta->value, true);

        if (!isset($value['post_type'])) {
            return $placementSettings;
        }

        $placementSettings['taxonomy'] = array(
            'general' => array(
                'label',
                'label_placement',
                'admin_field_label',
                'placeholder',
                'field_type',
                'validation_rules',
            ),
            'advanced' => array(
                'value',
                'container_class',
                'class',
                'help_message',
                'name',
                'conditional_logics',
            ),
            'generalExtras' => array(
                'field_type' => [
                    'template' => 'radio',
                    'label' => __('Taxonomy Field Type', 'fluentformpro'),
                    'help_text' => __('Select the field type you want to render in the form.', 'fluentformpro'),
                    'options' => [
                        array(
                            'value' => 'radio',
                            'label' => __('Radio', 'fluentformpro'),
                        ),
                        array(
                            'value' => 'select_single',
                            'label' => __('Select', 'fluentformpro'),
                        ),
                        array(
                            'value' => 'select_multiple',
                            'label' => __('Multi-Select', 'fluentformpro'),
                        ),
                        array(
                            'value' => 'checkbox',
                            'label' => __('Checkbox', 'fluentformpro'),
                        )
                    ]
                ]
            ),
            'advancedExtras' => array(),
        );

        return $placementSettings;
    }

    public function registerPostFormSettingsMenu($menuItems, $formId)
    {
        $form = wpFluent()->table('fluentform_forms')->where('id', $formId)->first();

        if ($form->type != 'post') {
            return $menuItems;
        }

        $newItem = [
            'post_creation' => [
                'title' => __('Post Feeds', 'fluentformpro'),
                'slug' => 'form_settings',
                'hash' => 'post_feeds',
	            'route' => '/post-feeds'
            ]
        ];

        $formSettings = $menuItems['form_settings'];

        unset($menuItems['form_settings']);

        return array_merge(
            ['form_settings' => $formSettings], $newItem, $menuItems
        );
    }
}
