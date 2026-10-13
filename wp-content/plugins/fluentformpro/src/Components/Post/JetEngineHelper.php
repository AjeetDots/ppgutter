<?php

namespace FluentFormPro\Components\Post;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Api\FormProperties;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentFormPro\classes\UserMetaProtector;

class JetEngineHelper
{

    use Getter;

    public static function hasJetEngine()
    {
        return class_exists('\Jet_Engine') && function_exists('jet_engine');
    }

    public static function getFields($postType)
    {
        return static::formatMetaFields(static::getPostMetas($postType));
    }

    public static function getUserFields()
    {
        return static::formatMetaFields(static::getUserMetas());
    }

    public static function prepareMetaValues($feed, $postType, $formData, $isUpdate, $postId = null)
    {
        $metaValues = [];
        if ($general = self::prepareGeneralFields(Arr::get($feed, 'jetengine_mappings', []), $postType, $isUpdate)) {
            $metaValues = $general;
        }

        if ($advance = self::prepareAdvanceFields(Arr::get($feed, 'advanced_jetengine_mappings', []), $postType, $formData, $isUpdate, null, $postId)) {
            $metaValues = array_merge($metaValues, $advance);
        }
        return $metaValues;
    }

    protected static function getPostMetas($post_type)
    {
        $fields = jet_engine()->meta_boxes->get_meta_fields_for_object($post_type);
        if (!$fields) {
            return [];
        }
        return array_filter($fields, function ($field) {
            return 'field' === Arr::get($field, 'object_type');
        });
    }

    protected static function getUserMetas()
    {
        $groups = jet_engine()->meta_boxes->get_fields_for_context('user');
        if (!$groups) {
            return [];
        }
        $fields = [];
        foreach ($groups as $group) {
            $fields = array_merge($fields, $group);
        }
        return array_filter($fields, function ($field) {
            return 'field' === Arr::get($field,'object_type');
        });
    }

    protected static function formatMetaFields($fields)
    {
        $formatted = [
            'general' => [],
            'advanced' => [],
        ];
        if (!$fields) {
            return $formatted;
        }
        $general = self::generalFields();
        $advance = self::advanceFields();
        foreach ($fields as $field) {
            if (in_array($field['type'], $general)) {
                $formatted['general'][$field['name']] = [
                    'type'  => $field['type'],
                    'label' => $field['title'],
                    'name'  => $field['name'],
                    'key'   => $field['name']
                ];
            } else {
                if (isset($advance[$field['type']])) {
                    $settings = $advance[$field['type']];
                    $formatted['advanced'][$field['name']] = [
                        'type'              => $field['type'],
                        'label'             => $field['title'],
                        'name'              => $field['name'],
                        'key'               => $field['name'],
                        'acceptable_fields' => $settings['acceptable_fields'],
                        'help_message'      => $settings['help']
                    ];
                }
            }
        }
        return $formatted;
    }

    protected static function prepareGeneralFields($fields, $postType, $isUpdate = false)
    {
        $metaValues = [];
        if (!$fields) {
            return $metaValues;
        }

        if ($postType) {
            $metaFields = array_column(static::getPostMetas($postType), null, 'name');
        } else {
            $metaFields = array_column(static::getUserMetas(), null, 'name');
        }

        foreach ($fields as $field) {
            $metaName = Arr::get($field, 'field_key');
            $metaValue = Arr::get($field, 'field_value');
            if (!$metaName || !Arr::exists($metaFields, $metaName)) {
                continue;
            }
            if (!$isUpdate && !$metaValue) {
                continue;
            }
            $config = Arr::get($metaFields, $metaName);
            if (in_array($config['type'], ['date', 'time', 'datetime', 'datetime-local'])) {
                $metaValue = self::prepareDateTimeField($field, $config, $metaValue);
            }
            if ($metaValue) {
                $metaValues[$metaName] = $metaValue;
            } elseif ($isUpdate) {
                $metaValues[$metaName] = '';
            }
        }
        return $metaValues;
    }

    protected static function prepareAdvanceFields($fields, $postType, $formData, $isUpdate = false, $form = null, $postId = null)
    {
        $metaValues = [];
        if (!$fields) {
            return $metaValues;
        }
        if ($postType) {
            $metaFields = array_column(static::getPostMetas($postType), null, 'name');
        } else {
            $metaFields = array_column(static::getUserMetas(), null, 'name');
        }
        $formInputs = [];
        if ($form) {
            $formInputs = FormFieldsParser::getInputs($form, ['element']);
        }

        foreach ($fields as $field) {
            $metaName = Arr::get($field, 'field_key');
            $metaValue = Arr::get($formData, Arr::get($field, 'field_value'));
            if (!$metaName || !Arr::exists($metaFields, $metaName)) {
                continue;
            }
            if (!$isUpdate && !$metaValue) {
                continue;
            }
            $config = Arr::get($metaFields, $metaName);
            $type = Arr::get($config, 'type');
            if ('media' == $type) {
                $format = Arr::get($config, 'value_format', 'id');
                $metaValue = self::prepareMediaField($format, $metaValue);
                if ($isUpdate && static::maybeDeleteAttachmentIds($field['field_value'], $formData, $postId) && !$metaValue) {
                    $metaValues[$metaName] = '';
                    continue;
                }
            } elseif ('gallery' == $type) {
                $format = Arr::get($config, 'value_format', 'id');
                $metaValue = self::prepareGalleryField($format, $metaValue, $formData, $field, $isUpdate, $postId);
                if ($isUpdate && !$metaValue) {
                    $metaValues[$metaName] = '';
                    continue;
                }
            } elseif ('repeater' == $type) {
                $metaValue = self::prepareRepeaterField($config, $metaValue);
            } elseif ('checkbox' == $type) {
                if (!is_array($metaValue)) {
                    continue;
                }
                $metaValue = self::prepareCheckboxField($config, $metaValue);
            } elseif ('switcher' == $type && 'on' == $metaValue) {
                $metaValue = 'true';
            }
            $isCheckBox = 'input_checkbox' === Arr::get($formInputs, Arr::get($field, 'field_value') . '.element');
            if ($metaValue || ($isCheckBox && $isUpdate)) {
                $metaValues[$metaName] = $metaValue;
            }
        }

        return $metaValues;
    }

    protected static function prepareDateTimeField($field, $config, $metaValue)
    {
        $format = Arr::get($field, 'format');
        if ($format && strpos($format, 'K') !== false) {
            $format = str_replace('K', 'A', $format);
        }
        if ($format && $date = \DateTime::createFromFormat($format, $metaValue)) {
            if (in_array($config['type'], ['datetime-local', 'datetime'])) {
                $metaValue = $date->format('Y-m-d H:i');
            } elseif ('time' == $config['type']) {
                $metaValue = $date->format('H:i');
            } else {
                $metaValue = $date->format('Y-m-d');
            }
            if (Arr::isTrue($config, 'is_timestamp')) {
                $metaValue = strtotime($metaValue);
            }
        }
        return $metaValue;
    }

    protected static function prepareRepeaterField($config, $metaValue)
    {
        if (!is_array($metaValue)) {
            return [];
        }
        $subFields = Arr::get($config, 'repeater-fields', []);
        $formatted = [];
        foreach ($metaValue as $metaIndex => $value) {
            $item = [];
            foreach ($subFields as $fieldIndex => $field) {
                $itemValue = Arr::get($value, $fieldIndex, '');
                if ('number' == $field['type'] && !is_numeric($itemValue)) {
                    $itemValue = '';
                }
                if ('select' == $field['type']) {
                    $options = array_column(Arr::get($field, 'options', []), 'key');
                    if (!in_array($itemValue, $options)) {
                        $itemValue = '';
                    }
                }
                $item[$field['name']] = $itemValue;
            }
            $formatted['item-' . $metaIndex] = $item;
        }
        return $formatted;
    }

    protected static function prepareCheckboxField($config, $metaValue)
    {
        $options = array_column(Arr::get($config, 'options', []), 'key');
        $metaValue = array_filter($metaValue, function ($value) use ($options) {
            return in_array($value, $options);
        });
        return Arr::isTrue($config, 'is_array') ? $metaValue : array_fill_keys(array_values($metaValue), 'true');
    }

    protected static function prepareMediaField($format, $metaValue)
    {
        if (!$metaValue || !is_array($metaValue) || !$firstItem = Arr::get($metaValue, 0)) {
            return '';
        }
        return self::prepareMediaFile($format, $firstItem);
    }

    protected static function prepareGalleryField($format, $metaValue, $formData, $field, $isUpdate, $postId = null)
    {
        if (!$metaValue || !is_array($metaValue)) {
            $metaValue = [];
        }
        $formatted = [];
        foreach ($metaValue as $value) {
            $formatted[] = self::prepareMediaFile($format, $value);
        }
        if ($isUpdate && $existingIds = static::maybeDeleteAndGetExistingAttachmentIds($field['field_value'], $formData, $postId)) {
            $formatted = array_merge(self::prepareExistingIds($existingIds, $format), $formatted);
        }

        if ('both' !== $format) {
            $formatted = join(',', array_filter($formatted));
        }

        return $formatted;
    }

    protected static function prepareExistingIds($ids, $format)
    {
        if ('id' != $format) {
            foreach ($ids as $index => $id) {
                $item = wp_get_attachment_url($id);
                if ('both' == $format) {
                    $item = [
                        'id'  => $id,
                        'url' => $item
                    ];
                }
                $ids[$index] = $item;
            }
        }
        return $ids;
    }

    protected static function prepareMediaFile($format, $value)
    {
        $attachmentId = (new PostFormHandler())->getAttachmentToImageUrl($value);
        $url = wp_get_attachment_url($attachmentId);
        if ('both' == $format) {
            $value = [
                'id'  => $attachmentId,
                'url' => $url
            ];
        } elseif ('url' == $format) {
            $value = $url;
        } else {
            $value = $attachmentId;
        }
        return $value;
    }

    //post update mapping
    public static function maybePopulateMetaFields(&$meta_fields, $feed, $postId, $formId)
    {
        if (!$form = Helper::getForm($formId)) {
            return;
        }
        $formFields = (new FormProperties($form))->inputs(['raw']);
        if ($generalMetas = self::populateGeneralMetas(Arr::get($feed->value, 'jetengine_mappings', []), $postId, $formFields)) {
            $meta_fields['jetengine_metas'] = $generalMetas;
        }
        if ($advanceMetas = self::populateAdvanceMetas(Arr::get($feed->value, 'advanced_jetengine_mappings', []), $postId, $formFields)) {
            $meta_fields['advanced_jetengine_metas'] = $advanceMetas;
        }
    }

    protected static function formatDate($date, $format)
    {
        if (!is_numeric($date)) {
            $date = strtotime($date);
        }
        if ($format) {
            if (strpos($format, 'K') !== false) {
                $format = str_replace('K', 'A', $format);
            }
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
            $date = date($format, $date);
        }
        return $date ?: '';
    }

    /**
     * Object-injection-safe unserialize with a backward-compatible fallback.
     *
     * Prefers Helper::safeUnserialize() (free plugin), but older free versions
     * may not ship it yet, so fall back to the same object-free unserialize
     * inline to avoid a fatal on those installs.
     *
     * TODO: Remove this shim and call Helper::safeUnserialize() directly once the
     * minimum required free plugin version guarantees Helper::safeUnserialize().
     */
    protected static function unserializeSafely($data)
    {
        if (method_exists(Helper::class, 'safeUnserialize')) {
            return Helper::safeUnserialize($data);
        }
        if (is_serialized($data)) {
            return @unserialize(trim($data), ['allowed_classes' => false]);
        }
        return $data;
    }

    protected static function populateMediaFile($value)
    {
        $attachmentId = self::unserializeSafely($value);
        if (!is_numeric($value)) {
            if (is_array($value)) {
                $attachmentId = Arr::get($value, 'id', '');
            } else {
                $attachmentId = attachment_url_to_postid($value);
            }
        }
        return wp_prepare_attachment_for_js($attachmentId);
    }

    protected static function populateGalleryField($value)
    {
        $value = self::unserializeSafely($value);
        if (!is_array($value)) {
            $value = explode(',', $value);
        }
        $files = [];
        foreach ($value as $file) {
            if ($attachment = self::populateMediaFile($file)) {
                $files[] = $attachment;
            }
        }
        return $files;
    }

    public static function getUserMappingValue($form, $mappingFields)
    {
        $metas = [];
        $formFields = FormFieldsParser::getEntryInputs($form, ['raw']);
        if ($generalMetas = self::populateGeneralMetas(Arr::get($mappingFields, 'general', []), null, $formFields)) {
            $metas = $generalMetas;
        }
        if ($advanceMetas = JetEngineHelper::populateAdvanceMetas(Arr::get($mappingFields, 'advanced', []), null, $formFields)) {
            $metas = array_merge($metas, $advanceMetas);
        }
        return $metas;
    }

    public static function maybeUpdateUserMetas($userId, $formData, $form, $feed)
    {
        if (self::hasJetEngine() && $jetEngineFields = Arr::get($feed, 'processedValues.jetengine_mappings')) {
            $isUserUpdate = 'user_update' == Arr::get($feed, 'settings.list_id');
            $jetEngineMeta = [];
            if ($generalJetEngineFields = Arr::get($jetEngineFields, 'general')) {
                $rawJetEngineGeneralFields = Arr::get($feed, 'settings.jetengine_mappings.general');
                $generalJetEngineFields = self::maybeResolveProcessedFeedDateFormat(self::resolveDateFieldFormat($rawJetEngineGeneralFields, $form), $generalJetEngineFields);
                $jetEngineMeta = self::prepareGeneralFields($generalJetEngineFields, null, $isUserUpdate);
            }

            if ($advancedJetEngineFields = Arr::get($jetEngineFields, 'advanced')) {
                $advancedJetEngineMeta = self::prepareAdvanceFields($advancedJetEngineFields, null, $formData, $isUserUpdate, $form);
                if ($advancedJetEngineMeta) {
                    $jetEngineMeta = array_merge($jetEngineMeta, $advancedJetEngineMeta);
                }
            }

            if ($jetEngineMeta) {
                foreach ($jetEngineMeta as $metaKey => $metaValue) {
                    if (UserMetaProtector::isProtected($metaKey, $userId, ['form_id' => $form->id, 'integration' => 'jetengine_mappings'])) {
                        continue;
                    }
                    if (is_string($metaValue) && strpos($metaValue, '<br />') !== false) {
                        $metaValue = str_replace('<br />', "", $metaValue);
                    }
                    update_user_meta($userId, $metaKey, $metaValue);
                }
            }
        }
    }

    protected static function populateGeneralMetas($fields, $postId, $formFields)
    {
        $metas = [];
        if ($postId) {
            $postMetas = get_post_custom($postId);
        } else if ($userId = get_current_user_id()) {
            $postMetas = get_user_meta($userId);
        } else {
            return $metas;
        }
        foreach ($fields as $field) {
            $metaName = Arr::get($field, 'field_key');
            $fieldName = Helper::getInputNameFromShortCode(Arr::get($field, 'field_value', ''));
            if ($fieldName && $value = Arr::get($postMetas, $metaName . '.0')) {
                $type = Arr::get($formFields, $fieldName . '.raw.attributes.type', 'text');
                $element = Arr::get($formFields, $fieldName . '.element', '');
                if ('input_date' == $element) {
                    $format = Arr::get($formFields, $fieldName . '.raw.settings.date_format');
                    $value = self::formatDate($value, $format);
                    $type = 'jetengine_date_type';
                } elseif ('rich_text_input' == $element) {
                    $type = 'wysiwyg';
                }
                $metas[] = [
                    "name"  => $fieldName,
                    "type"  => $type,
                    "value" => $value
                ];
            }
        }
        return $metas;
    }

    protected static function populateAdvanceMetas($fields, $postId, $formFields)
    {
        $metas = [];
        if ($postId) {
            $postMetas = get_post_custom($postId);
            $metaFields = static::getPostMetas(get_post_type($postId));
        } else if ($userId = get_current_user_id()) {
            $postMetas = get_user_meta($userId);
            $metaFields = Arr::get(static::getUserFields(), 'advanced');
        } else {
            return $metas;
        }
        $metaFields = array_column($metaFields, null, 'name');
        foreach ($fields as $field) {
            $metaName = Arr::get($field, 'field_key');
            $fieldName = Arr::get($field, 'field_value', '');
            if ($fieldName && $value = Arr::get($postMetas, $metaName . '.0')) {
                $config = Arr::get($metaFields, $metaName, []);
                $type = Arr::get($config, 'type', '');
                $element = Arr::get($formFields, $fieldName . '.element', '');
                if ('checkbox' == $type) {
                    $value = self::unserializeSafely($value);
                    if (!Arr::isTrue($config, 'is_array')) {
                        $value = array_filter($value, function ($v) {
                            return $v == 'true';
                        });
                        $value = array_keys($value);
                    }
                } elseif ('switcher' == $type) {
                    if ('true' == $value) {
                        $value = ['on'];
                    } else {
                        $value = ['off'];
                    }
                    $type = 'checkbox';
                } elseif ('media' == $type) {
                    $value = self::populateMediaFile($value);
                    if ('input_file' == $element) {
                        $type = 'file';
                    }
                } elseif ('gallery' == $type) {
                    $value = self::populateGalleryField($value);
                } elseif ('repeater' == $type) {
                    $value = self::populateRepeaterField($value);
                }
                if ($value) {
                    $metas[] = [
                        "name"  => $fieldName,
                        "type"  => $type,
                        "value" => $value
                    ];
                }
            }
        }
        return $metas;
    }

    protected static function populateRepeaterField($value)
    {
        if (!$value = self::unserializeSafely($value)) {
            return [];
        }
        $value = array_map(function ($v) {
            return array_values($v);
        }, $value);
        return array_values($value);
    }

    protected static function generalFields()
    {
        $generalFields = [
            'text',
            'textarea',
            'number',
            'wysiwyg',
            'date',
            'time',
            'datetime',
            'datetime-local',
        ];
        return apply_filters('fluentform/post_jetengine_accepted_general_fields', $generalFields);
    }

    protected static function advanceFields()
    {
        $advancedFields = [
            'select'      => [
                'acceptable_fields' => ['select'],
                'help'              => __('Select select field for this mapping', 'fluentformpro')
            ],
            'checkbox'    => [
                'acceptable_fields' => ['input_checkbox'],
                'help'              => __('Select checkbox field for this mapping', 'fluentformpro')
            ],
            'radio'       => [
                'acceptable_fields' => ['input_radio'],
                'help'              => __('Select radio field for this mapping', 'fluentformpro')
            ],
            'switcher'    => [
                'acceptable_fields' => ['terms_and_condition', 'gdpr_agreement'],
                'help'              => __('Select T&C or GDPR field for this mapping', 'fluentformpro')
            ],
            'colorpicker' => [
                'acceptable_fields' => ['color_picker'],
                'help'              => __('Select color picker field for this mapping', 'fluentformpro')
            ],
            'media'       => [
                'acceptable_fields' => ['input_file', 'input_image'],
                'help'              => __('Select Image or File upload field for this mapping', 'fluentformpro')
            ],
            'gallery'     => [
                'acceptable_fields' => ['input_image', 'input_file'],
                'help'              => __('Select Image or File upload field for this mapping', 'fluentformpro')
            ],
            'repeater'    => [
                'acceptable_fields' => ['repeater_field'],
                'help'              => __('Please select repeat field. Your Jet Engine repeater field and form field columns need to be equal',
                    'fluentformpro')
            ]
        ];
        return apply_filters('fluentform/post_jetengine_accepted_advanced_fields', $advancedFields);
    }
}
