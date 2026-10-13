<?php

namespace FluentFormPro\Components\Post;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentFormPro\classes\UserMetaProtector;

class MetaboxHelper
{
    use Getter;

    public static function hasMetabox()
    {
        return defined('RWMB_VER');
    }

    public static function getUserFields()
    {
        if (!self::hasMetabox()) {
            return [
                'general'  => [],
                'advanced' => []
            ];
        }
        $meta_box_registry = rwmb_get_registry('meta_box');
        $args = [
            'object_type' => 'user'
        ];
        $meta_boxes = $meta_box_registry->get_by($args);
        $validBoxes = [];
        foreach ($meta_boxes as $key => $meta_box) {
            if (empty($meta_box->meta_box)) {
                continue;
            }
            $validBoxes[$key] = $meta_box;
        }
        return self::classifyFields($validBoxes);
    }

    public static function getPostFields($postType, $withRaw = false)
    {
        if(!self::hasMetabox()) {
            return [
                'general' => [],
                'advanced' => []
            ];
        }

        $meta_box_registry = rwmb_get_registry( 'meta_box' );
        $args = [
            'object_type' => 'post'
        ];
        $meta_boxes = $meta_box_registry->get_by( $args );

        $validBoxes = [];

        foreach ($meta_boxes as $key => $meta_box) {
            if(empty($meta_box->meta_box)) {
                continue;
            }
            $postTypes = Arr::get($meta_box->meta_box, 'post_types',  []);
            if($postTypes && in_array($postType, $postTypes)) {
                $validBoxes[$key] = $meta_box;
            }
        }
        return self::classifyFields($validBoxes, $withRaw);
    }

    public static function classifyFields($metaBoxes, $withRaw = false)
    {
        $generalAcfFields = self::getGeneralFields();
        $advancedAcfFields = self::getAdvancedFields();

        $generalFields = [];
        $advancedFields = [];

        foreach ($metaBoxes as $field_group) {
            $fields = $field_group->meta_box['fields'];
            foreach ($fields as $field) {
                if (in_array($field['type'], $generalAcfFields)) {
                    $generalFields[$field['field_name']] = [
                        'type' => $field['type'],
                        'label' => $field['name'],
                        'name' => $field['field_name'],
                        'key' => $field['id']
                    ];
                    if($withRaw) {
                        $generalFields[$field['field_name']]['raw'] = $field;
                    }
                } else if (isset($advancedAcfFields[$field['type']])) {
                    $settings = $advancedAcfFields[$field['type']];
                    $advancedFields[$field['field_name']] = [
                        'type' => $field['type'],
                        'label' => $field['name'],
                        'name' => $field['field_name'],
                        'key' => $field['id'],
                        'acceptable_fields' => $settings['acceptable_fields'],
                        'help_message' => $settings['help']
                    ];

                    if ($withRaw) {
                        $advancedFields[$field['field_name']]['raw'] = $field;
                    }
                }
            }
        }

        return [
            'general' => $generalFields,
            'advanced' => $advancedFields
        ];
    }

    private static function getGeneralFields()
    {
        $acceptedFields = [
            'hidden',
            'password',
            'text',
            'textarea',
            'url',
            'wysiwyg',
            'time',
            'slider',
            'color',
            'email',
            'number',
            'range',
            'tel'
        ];
        $acceptedFields = apply_filters_deprecated(
            'fluent_post_metabox_accepted_general_fields',
            [
                $acceptedFields
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/post_metabox_accepted_general_fields',
            'Use fluentform/post_metabox_accepted_general_fields instead of fluent_post_metabox_accepted_general_fields.'
        );
        return apply_filters('fluentform/post_metabox_accepted_general_fields', $acceptedFields);
    }

    public static function getAdvancedFields()
    {
        $acceptedFields = [
            'select' => [
                'acceptable_fields' => ['select'],
                'help' => __('Select select field for this mapping', 'fluentformpro')
            ],
            'select_advanced' => [
                'acceptable_fields' => ['select'],
                'help' => __('Select appropriate field for this mapping', 'fluentformpro')
            ],
            'checkbox' => [
                'acceptable_fields' => ['gdpr_agreement', 'terms_and_condition'],
                'help' => __('Select checkbox field for this mapping', 'fluentformpro')
            ],
            'checkbox_list' => [
                'acceptable_fields' => ['input_checkbox'],
                'help' => __('Select checkbox field for this mapping', 'fluentformpro')
            ],
            'radio' => [
                'acceptable_fields' => ['input_radio'],
                'help' => __('Select radio field for this mapping', 'fluentformpro')
            ],
            'button_group' => [
                'acceptable_fields' => ['input_radio'],
                'help' => __('Select radio field for this mapping', 'fluentformpro')
            ],
            'image_select' => [
                'acceptable_fields' => ['input_radio', 'input_checkbox', 'select'],
                'help' => __('Select appropriate field for this mapping', 'fluentformpro')
            ],
            'datetime-local' => [
                'acceptable_fields' => ['input_date'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'date' => [
                'acceptable_fields' => ['input_date'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'datetime' => [
                'acceptable_fields' => ['input_date'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'switch' => [
                'acceptable_fields' => ['gdpr_agreement', 'terms_and_condition'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'file' => [
                'acceptable_fields' => ['input_file'],
                'help' => __('Select File Upload field for this mapping', 'fluentformpro')
            ],
            'file_advanced' => [
                'acceptable_fields' => ['input_file'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'file_upload' => [
                'acceptable_fields' => ['input_file'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'file_input' => [
                'acceptable_fields' => ['input_file', 'input_image'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'image' => [
                'acceptable_fields' => ['input_image'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'image_advanced' => [
                'acceptable_fields' => ['input_image'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'image_upload' => [
                'acceptable_fields' => ['input_image'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
            'single_image' => [
                'acceptable_fields' => ['input_image'],
                'help' => __('Select Date field for this mapping', 'fluentformpro')
            ],
        ];
        $acceptedFields = apply_filters_deprecated(
            'fluent_post_metabox_accepted_advanced_fields',
            [
                $acceptedFields
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/post_metabox_accepted_advanced_fields',
            'Use fluentform/post_metabox_accepted_advanced_fields instead of fluent_post_metabox_accepted_advanced_fields.'
        );
        return apply_filters('fluentform/post_metabox_accepted_advanced_fields', $acceptedFields);
    }

    public static function prepareFieldsData($fields, $postType, $formData, $isUpdate, $postId = null)
    {
        if ('user' == $postType) {
            $fieldGroups = self::getUserFields();
        } else {
            $fieldGroups = self::getPostFields($postType, true);
        }
        $metaValues = [];
        $generalFields = $fieldGroups['general'];
        foreach ($fields['general'] as $field) {
            $fieldValue = Arr::get($field, 'field_value');
            $fieldKey = Arr::get($field, 'field_key');

            if (!$fieldKey || !$fieldValue || !isset($generalFields[$fieldKey])) {
                continue;
            }

            $fieldConfig = $generalFields[$fieldKey];
            $mataName = $fieldConfig['name'];
            $metaValues[$mataName] = $fieldValue;
        }

        $advancedFields = $fieldGroups['advanced'];

        foreach ($fields['advanced'] as $field) {
            $fieldValue = Arr::get($formData, $field['field_value']);
            $fieldKey = Arr::get($field, 'field_key');

            if (!$fieldKey || !isset($advancedFields[$fieldKey])) {
                continue;
            }
            $fieldConfig = $advancedFields[$fieldKey];
            $type = $fieldConfig['type'];

            if ('checkbox_list' === $type && !$fieldValue && $isUpdate) {
                $fieldValue = [0 => "0"];   //make metabox field empty
            }
            if (!$fieldValue && !$isUpdate) {
                continue;
            }

            $fieldKey = str_replace('[]', '', $fieldKey);
            $rawTypes = ['checkbox_list', 'button_group', 'select', 'select_advanced', 'radio', 'datetime-local', 'image_select', 'date', 'datetime'];
            if(in_array($type, $rawTypes)) {
                $metaValues[$fieldKey] = $fieldValue;
                continue;
            }

            $fileIdsTypes = ['file', 'file_advanced', 'file_upload', 'image', 'image_advanced', 'image_upload', 'single_image'];

            if (in_array($type, $fileIdsTypes)) {
                $existingAttachmentIds = static::maybeDeleteAndGetExistingAttachmentIds($field['field_value'], $formData, $postId);
                $fileIds = self::getFileIdsFromUrls($fieldValue);
                if($fileIds) {
                    if ($type == 'single_image') {
                        $metaValues[$fieldKey] = $fileIds[0];
                    } else {
                        if ($existingAttachmentIds) {
                            $fileIds = array_merge($existingAttachmentIds, $fileIds);
                        }
                        $metaValues[$fieldKey] = $fileIds;
                    }
                }
                continue;
            }
            if ($type == 'file_input') {
                if(is_array($fieldValue)) {
                    $metaValues[$fieldKey] = $fieldValue[0];
                }
                continue;
            }

            $booleanTypes = ['switch', 'checkbox'];
            if (in_array($type, $booleanTypes)) {
                $metaValues[$fieldKey] = 1;
            }
        }


        return $metaValues;

    }

    // Post update mapping
    public static function maybePopulatePostUpdateMetaFields(&$metaFields, $feed, $postId, $formId)
    {
        $mbFields = self::getPostFields(self::getPostType($formId));
        $args = [
            'object_type' => 'post'
        ];
        if ($generalMetas = self::getMetBoxFieldsValue($mbFields['general'], Arr::get($feed->value, 'metabox_mappings', []), $args, $postId)) {
            $metaFields['mb_general_metas'] = $generalMetas;
        }
        if ($advanceMetas = self::getMetBoxFieldsValue($mbFields['advanced'], Arr::get($feed->value, 'advanced_metabox_mappings', []), $args, $postId, 'advanced')) {
            $metaFields['mb_advanced_metas'] = $advanceMetas;
        }
    }

    // User update mapping
    public static function getUserMappingValue($mappingFields)
    {
        $metas = [];
        $mbFields = self::getUserFields();
        $args = [
            'object_type' => 'user'
        ];

        $userId = get_current_user_id();
        if ($generalMetas = self::getMetBoxFieldsValue($mbFields['general'], Arr::get($mappingFields, 'general', []), $args, $userId)) {
            $metas = $generalMetas;
        }
        if ($advanceMetas = self::getMetBoxFieldsValue($mbFields['advanced'], Arr::get($mappingFields, 'advanced', []), $args, $userId, 'advanced')) {
            $metas = array_merge($metas, $advanceMetas);
        }

        return $metas;
    }


    private static function getMetBoxFieldsValue($mbFields, $mappingFields, $args, $objectId, $from = 'general')
    {
        $metaFields = [];
        $mbFieldsKeys = array_keys($mbFields);
        foreach ($mappingFields as $field) {
            if (!in_array($field['field_key'], $mbFieldsKeys) || !function_exists('rwmb_get_value')) {
                continue;
            }

            $fieldName = Arr::get($field, 'field_value', '');
            $field = Arr::get($mbFields, $field['field_key'], '');

            if ($from === 'advanced') {
                $name = $fieldName;
            } else {
                $name = Helper::getInputNameFromShortCode($fieldName);
            }

            if (!$name && !$field) {
                continue;
            }
            $value = rwmb_get_value($field['key'], $args, $objectId);

            if (in_array($field['type'], ['file_upload', 'image_upload', 'image', 'file_advanced', 'file'])) {
                if (count($value) > 0) {
                    $value = array_values($value);
                }
            }
            $metaFields[] = [
                "name"  => $name,
                "type"  => $field['type'],
                "value" => $value
            ];
        }
        return $metaFields;
    }

    public static function maybeUpdateUserMetas($userId, $formData, $form, $feed)
    {
        if (self::hasMetabox() && $metaboxFields = Arr::get($feed, 'settings.metabox_mappings')) {
            $isUserUpdate = 'user_update' == Arr::get($feed, 'settings.list_id');
            if (Arr::get($metaboxFields, 'general')) {
                $metaboxFields['general'] = Arr::get($feed, 'processedValues.metabox_mappings.general');
            }
            $metaboxMeta = self::prepareFieldsData($metaboxFields, 'user', $formData, $isUserUpdate);
            if ($metaboxMeta && function_exists('rwmb_set_meta')) {
                $args = [
                    'object_type' => 'user'
                ];
                foreach ($metaboxMeta as $fieldKey => $value) {
                    if (UserMetaProtector::isProtected($fieldKey, $userId, ['form_id' => $form->id, 'integration' => 'metabox_mappings'])) {
                        continue;
                    }
                    rwmb_set_meta($userId, $fieldKey, $value, $args);
                }
            }
        }
    }


    private static function getFileIdsFromUrls($fieldValue)
    {
        if (!is_array($fieldValue)) {
            return [];
        }

        $attachmentIds = [];
        foreach ($fieldValue as $item) {
            $attachmentId = (new PostFormHandler())->getAttachmentToImageUrl($item);
            if ($attachmentId) {
                $attachmentIds[] = $attachmentId;
            }
        }

       return $attachmentIds;
    }

}
