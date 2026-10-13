<?php

namespace FluentFormPro\Components\Post\Components;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Components\Post\PopulatePostForm;
use FluentFormPro\Components\Post\PostFormHandler;

class PostUpdate extends BaseFieldManager
{
    public function __construct() {
        parent::__construct(
            'post_update',
            'Post Update',
            ['update', 'post_update', 'post update'],
            'post'
        );
        add_filter('fluentform/response_render_' . $this->key, function ($value) {
            if (!$value || !is_numeric($value)) {
                return $value;
            }
            $post = get_post($value);
            return (isset($post->post_title)) ? $post->post_title : $value;
        });

        add_filter('fluentform/editor_init_element_post_update', function ($item) {
            if (!isset($item['settings']['dynamic_default_value'])) {
                $item['settings']['dynamic_default_value'] = '';
            }
            if (!empty($item['settings']['placeholder'])) {
                $item['settings']['placeholder'] = '';
            }
            return $item;
        });
        add_filter('fluentform/white_listed_fields', [$this, 'addWhiteListedFields'], 10, 2);

        add_filter('fluentform/validation_post_update_errors', [$this, 'validateUpdatePostForm'], 10, 4);
        new PopulatePostForm();
    }

    public function addWhiteListedFields($whiteListKeys, $formId)
    {
        $postFeed = Helper::getFormMeta($formId, 'postFeeds');
        if (!$postFeed) {
            return $whiteListKeys;
        }
        if ('update' !== ArrayHelper::get($postFeed, 'post_form_type')) {
            return $whiteListKeys;
        }
        if ($form = Helper::getForm($formId)) {
            if (FormFieldsParser::getElement($form, ['featured_image'], ['raw'])) {
                $whiteListKeys[] = 'remove_featured_image';
            }
            $fileOrImageTypeFields = FormFieldsParser::getElement($form, ['input_file', 'input_image'], ['raw']);
            foreach (ArrayHelper::get($postFeed, 'advanced_acf_mappings', []) as $field) {
                $name = ArrayHelper::get($field, 'field_value', '');
                if ($name && isset($fileOrImageTypeFields[$name])) {
                    $whiteListKeys[] = "remove-attachment-key-$name";
                    $whiteListKeys[] = "existing-attachment-key-$name";
                }
            }
            foreach (ArrayHelper::get($postFeed, 'advanced_jetengine_mappings', []) as $field) {
                $name = ArrayHelper::get($field, 'field_value', '');
                if ($name && isset($fileOrImageTypeFields[$name])) {
                    $whiteListKeys[] = "remove-attachment-key-$name";
                    $whiteListKeys[] = "existing-attachment-key-$name";
                }
            }
            foreach (ArrayHelper::get($postFeed, 'advanced_metabox_mappings', []) as $field) {
                $name = ArrayHelper::get($field, 'field_value', '');
                if ($name && isset($fileOrImageTypeFields[$name])) {
                    $whiteListKeys[] = "remove-attachment-key-$name";
                    $whiteListKeys[] = "existing-attachment-key-$name";
                }
            }
            foreach (ArrayHelper::get($postFeed, 'meta_fields_mapping', []) as $field) {
                $name = Helper::getInputNameFromShortCode(ArrayHelper::get($field, 'meta_value', ''));
                if ($name && isset($fileOrImageTypeFields[$name])) {
                    $whiteListKeys[] = "remove-attachment-key-$name";
                }
            }
        }
        return $whiteListKeys;
    }
    public function getComponent()
    {
        return [
            'index' => 5,
            'element' => $this->key,
            'attributes' => [
                'name' => $this->key,
                'class' => '',
                'value' => '',
                'type' => 'select',
                'placeholder' => __('- Select -', 'fluentformpro')
            ],
            'settings' => [
                'container_class' => '',
                'placeholder' => '',
                'label' => __('Select Post', 'fluentformpro'),
                'label_placement' => '',
                'help_message' => '',
                'admin_field_label' => '',
                'post_extra_query_params' => '',
                'dynamic_default_value' => '',
                'infoBlock' => '',
                'allow_view_posts' => 'all_post',
                'enable_select_2'         => 'no',
                'validation_rules' => [
                    'required' => [
                        'value'          => true,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ]
                ],
                'conditional_logics' => []
            ],
            'editor_options' => [
                'title' => $this->title,
                'icon_class' => 'ff-edit-text',
                'element'    => 'select',
                'template'   => 'select'
            ],
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'admin_field_label',
            'placeholder',
            'post_extra_query_params',
            'label_placement',
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'name',
            'dynamic_default_value',
            'help_message',
            'container_class',
            'class',
            'conditional_logics',
            'enable_select_2'
        ];
    }

    public function generalEditorElement()
    {

        return [
            'allow_view_posts'     => [
                'template'  => 'radio',
                'label'     => __('Filter Posts', 'fluentformpro'),
                'help_text' => __('Select Which Post you want to show', 'fluentformpro'),
                'options'   => array(
                    [
                        "label" => __("All Post", 'fluentformpro'),
                        "value" => 'all_post'
                    ],
                    [
                        "label" => __("Current User Post", 'fluentformpro'),
                        "value" => 'current_user_post'
                    ],
                )
            ],
            'post_extra_query_params' => [
                'template'         => 'inputTextarea',
                'label'            => __('Extra Query Parameter', 'fluentformpro'),
                'help_text'        => __('Extra Query Parameter for Update Post', 'fluentformpro'),
                'inline_help_text' => __('You can provide post query parameters for further filter. <a target="_blank" href="https://wpmanageninja.com/?p=1520634">Check the doc here</a>', 'fluentformpro')
            ],
            'infoBlock' => [
                'text' => __('Post update field will only work when Post Feeds Submission Type is set to Update Post.', 'fluentformpro'),
                'template' => 'infoBlock'
            ]
        ];
    }

    public function render($data, $form)
    {
        if ($form->type != 'post') return;
        $postFormHandler = new PostFormHandler();
        $feeds = $postFormHandler->getFormFeeds($form);

        if (!count($feeds)) {
            return;
        }
        foreach ($feeds as $feed) {
            $feed->value = json_decode($feed->value, true);
            if (ArrayHelper::get($feed->value, 'post_form_type') == 'update') {
                if (!ArrayHelper::isTrue($feed->value, 'allowed_guest_user') && !get_current_user_id()) {
                    return;
                }
                $data['attributes']['id'] = 'post-selector-' . time();
                $data['settings']['placeholder'] = $data['attributes']['placeholder'];
                $data['settings']['post_type_selection'] = (new \FluentFormPro\Components\Post\PostFormHandler())->getPostType($form);

                if (ArrayHelper::get($data, 'settings.allow_view_posts') === 'current_user_post') {
                    if (!get_current_user_id()) {
                        return;
                    }
                    $data['settings']['post_extra_query_params'] .= '&author=' . get_current_user_id();
                }
                (new PopulatePostForm())->renderPostSelectionField($data, $form);
                return;
            }
        }

    }

    public function pushTags($tags, $form)
    {
        if ($form->type != 'post') {
            return $tags;
        }
        $tags[$this->key] = $this->tags;
        return $tags;
    }

    public function validateUpdatePostForm($errors, $formData, $form, $fields)
    {
        $postUpdateFieldName = ArrayHelper::get(array_column($fields, null, 'element'), 'post_update.raw.attributes.name');
        $postId = ArrayHelper::get($formData, $postUpdateFieldName);
        foreach ($errors as $filedName => $message) {
            $elementType = ArrayHelper::get($fields, $filedName . '.element');
            // handle required validation
            if (in_array($elementType, ['featured_image', 'input_file', 'input_image'])) {
                $field = ArrayHelper::get($fields, $filedName);
                $isRequired = ArrayHelper::isTrue($field, 'rules.required.value');
                $hasValue = ArrayHelper::get($formData, $filedName);
                if ($isRequired) {
                    if ($hasValue) {
                        // Error is not on require rule
                        continue;
                    }
                    if ('featured_image' === $elementType) {
                        if (ArrayHelper::isTrue($formData, 'remove_' . $filedName)) {
                            continue;
                        } elseif (has_post_thumbnail($postId)) {
                            unset($errors[$filedName]['required']);
                        }
                    } else {
                        $hasExistingValue = ArrayHelper::get($formData, 'existing-attachment-key-' . $filedName, []);
                        $removeExistingValue = ArrayHelper::get($formData, 'remove-attachment-key-' . $filedName, []);
                        $stillHasValue = array_diff($hasExistingValue, $removeExistingValue);
                        if ($stillHasValue) {
                            unset($errors[$filedName]['required']);
                        }
                    }
                }
            }
            if (empty($errors[$filedName])) {
               ArrayHelper::forget($errors, $filedName);
            }
        }
        return $errors;
    }
}
