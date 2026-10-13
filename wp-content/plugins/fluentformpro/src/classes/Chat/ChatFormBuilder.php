<?php

namespace FluentFormPro\classes\Chat;

defined('ABSPATH') or die;

use Exception;
use FluentForm\App\Models\Form;
use FluentForm\App\Models\FormMeta;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\App\Services\FluentConversational\Classes\Converter\Converter;
use FluentForm\App\Services\Form\FormService;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

/**
 * @todo after some release replace comment methods with FluentForm\App\Modules\Ai\AiFormBuilder
 */
class ChatFormBuilder extends FormService
{
    public function __construct()
    {
        parent::__construct();
        add_action('wp_ajax_fluentform_chat_gpt_create_form', [$this, 'buildForm'], 11, 0);
    }

    public function buildForm()
    {
        try {
            Acl::verify('fluentform_forms_manager');

            $attributes = $this->app->request->all();
            
            $form = $this->generateForm($attributes);
            $form = $this->prepareAndSaveForm($form);
            wp_send_json_success([
                'formId'       => $form->id,
                'redirect_url' => admin_url(
                    'admin.php?page=fluent_forms&form_id=' . $form->id . '&route=editor'
                ),
                'message' => __('Successfully created a form.', 'fluentformpro'),
            ], 200);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * @param array $form
     * @throws Exception
     * @return Form|\FluentForm\Framework\Database\Query\Builder
     */
    protected function prepareAndSaveForm($form)
    {
        $allFields = $this->getDefaultFields();
        $fluentFormFields = [];
        $fields = Arr::get($form, 'fields', []);
        $isConversational = Arr::isTrue($form, 'is_conversational');
        $hasStep = false;
        $lastFieldIndex = count($fields) - 1;
        foreach ($fields as $index => $field) {
            if ($inputKey = $this->resolveInput($field)) {
                if (!$hasStep && 'form_step' === $inputKey) {
                    if (0 === $index || $lastFieldIndex === $index) {
                        continue;
                    }
                    $hasStep = true;
                }
                $fluentFormFields[] = $this->processField($inputKey, $field, $allFields);
            }
        }
        $fluentFormFields = $this->maybeAddPayments($fluentFormFields, $allFields);
        $fluentFormFields = array_filter($fluentFormFields);
        if (!$fluentFormFields) {
            throw new Exception(__('Empty form. Please try again!', 'fluentformpro')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $title = Arr::get($form, 'title', '');
        return $this->saveForm($fluentFormFields, $title, $hasStep, $isConversational);
    }

    /**
     * @param array $args
     * @throws Exception
     * @return array response form fields
     */
    protected function generateForm($args)
    {
        $startingQuery = "Create a form for ";
        $query = \FluentForm\Framework\Support\Sanitizer::sanitizeTextField(Arr::get($args, 'query'));
        if (empty($query)) {
            throw new Exception(__('Query is empty!', 'fluentformpro')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        
        $additionalQuery = \FluentForm\Framework\Support\Sanitizer::sanitizeTextField(Arr::get($args, 'additional_query'));
        
        if ($additionalQuery) {
            $query .= "\n including questions for information like  " . $additionalQuery . ".";
        }

        $query .= "\nField includes 'type', 'name', 'label', 'placeholder', 'required' status. 
        \nIf field has 'options', it will be format 'value' 'label' pair.
        \nIf has field like full name, first name, last name, field key type will be 'name'. If has field like phone, field key type will be 'phone'.
        \nIf has field like payment, field key type will be 'payment'.
        \nIf has field like rating, review, feedback, star, field key type will be 'ratings' and include options as label value pair.
        \nIf has field like slider, range, scale, field key type will be 'range' and include min and max value as property of field array.
        \nIf has field like layout, container, field key type will be 'container' and include fields on 'fields' array.
        \nIf form has steps, tabs, multipage or multi-page, add a field key with type 'steps' on every step break.
        \nAdd 'is_conversational' key to define the form is conversational form or not.
        \nAdd 'title' key to define the form title.
        \nIgnore my previous chat history.
        \nReturn the form data in JSON format, adhering to FluentForm's structure. Only include the form fields inside the 'fields' array.";

        $args = [
            "role"    => 'system',
            "content" => $startingQuery . $query,
        ];
        $result = (new ChatApi())->makeRequest($args);
        $response = trim(Arr::get($result, 'choices.0.message.content'), '"');
        $response = json_decode($response, true);

        if (is_wp_error($response) || empty($response['fields'])) {
            throw new Exception(__('Invalid response: Please try again! :', 'fluentformpro')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        
        return $response;
    }
    
    protected function getDefaultFields()
    {
        $components = wpFluentForm()->make('components')->toArray();
        //todo remove disabled elements
        $disabledComponents = $this->getDisabledComponents();
        if(!(isset($components['payments']))){
            $components['payments'] = [];
        }
        $general = Arr::get($components, 'general', []);
        $advanced = Arr::get($components, 'advanced', []);
        $container = Arr::get($components, 'container', []);
        $payments = Arr::get($components, 'payments', []);
        return array_merge($general, $advanced, $payments, ['container' => $container]);
    }
    
    protected function getElementByType($allFields, $type) {
        foreach ($allFields as $element) {
                if (isset($element['element']) && $element['element'] === $type) {
                    return $element;
                }
        }
        return null;
    }
    
    protected function processField($inputKey, $field, $allFields)
    {
        if ('container' == $inputKey) {
            $fields = Arr::get($field, 'fields', []);
            return $this->resolveContainerFields($fields, $allFields);
        }
        $matchedField = $this->getElementByType($allFields, $inputKey);
        if (!$matchedField) {
            return [];
        }
        $matchedField['uniqElKey'] = "el_" . uniqid();
        $matchedFieldType = Arr::get($matchedField, 'element');

        if ('form_step' === $inputKey) {
            return $matchedField;
        }

        if ($fieldName = Arr::get($field, 'name')) {
            $matchedField['attributes']['name'] = $fieldName;
        }

        if ($label = Arr::get($field, 'label')) {
            $required = Arr::isTrue($field, 'required');
            if (isset($matchedField['settings']['label'])) {
                $matchedField['settings']['label'] = $label;
                if (isset($matchedField['settings']['validation_rules']['required']['value'])) {
                    $matchedField['settings']['validation_rules']['required']['value'] = $required;
                }
            }

            $placeholder = Arr::get($field, 'placeholder');
            if ($placeholder) {
                if (isset($matchedField['attributes']['placeholder'])) {
                    $matchedField['attributes']['placeholder'] = $placeholder;
                } elseif (isset($matchedField['settings']['placeholder'])) {
                    $matchedField['settings']['placeholder'] = $placeholder;
                }
            }

            if (isset($matchedField['fields'])) {
                $subFields = $matchedField['fields'];
                $subNames = explode(" ", $label);
                if (count($subNames) > 1) {
                    $counter = 0;
                    foreach ($subFields as $subFieldkey => $subFieldValue) {
                        if (Arr::get($subNames, $counter)) {
                            if (Arr::has($subFieldValue, 'settings.visible') && !Arr::isTrue($subFieldValue, 'settings.visible')) {
                                continue;
                            }
                            if (isset($subFieldValue['settings']['label'])) {
                                $subFields[$subFieldkey]['settings']['label'] = Arr::get($subNames, $counter);
                                $subFields[$subFieldkey]['settings']['validation_rules']['required']['value'] = $required;
                            }
                            $counter++;
                        }
                    }
                }
                $matchedField['fields'] = $subFields;
            }
        }
        
        if ($options = $this->getOptions(Arr::get($field, 'options'))) {
            if (isset($matchedField['settings']['advanced_options'])) {
                $matchedField['settings']['advanced_options'] = $options;
            }
            if ('ratings' == $matchedFieldType) {
                $matchedField['options'] = array_column($options, 'label', 'value');
            }
        }

        if ('rangeslider' == $matchedFieldType) {
            if ($min = Arr::get($field, 'min')) {
                $matchedField['attributes']['min'] = intval($min);
            }
            if ($max = intval(Arr::get($field, 'max', 10))) {
                $matchedField['attributes']['max'] = $max;
            }
        }
        
        return $matchedField;
    }
    
    protected function resolveInput($field)
    {
        $type = Arr::get($field, 'type');
        $searchTags = fluentformLoadFile('Services/FormBuilder/ElementSearchTags.php');
        $form = ['type'=>''];
        $form = json_decode(json_encode($form));
        $searchTags = apply_filters('fluentform/editor_element_search_tags', $searchTags, $form);
        foreach ($searchTags as $inputKey => $tags) {
            if (array_search($type, $tags) !== false) {
                return $inputKey;
            } else {
                foreach ($tags as $tag) {
                    if (strpos($tag, $type) !== false) {
                        return $inputKey;
                    }
                }
            }
        }
        return false;
    }
    
    protected function getOptions($options = [])
    {
        $formattedOptions = [];
        if (empty($options) || !is_array($options)) {
            return $options;
        }
        foreach ($options as $key => $option) {
            if (is_string($option) || is_numeric($option)) {
                $value = $label = $option;
            } elseif (is_array($option)) {
                $label = Arr::get($option, 'label');
                $value = Arr::get($option, 'value');
            } else {
                continue;
            }
            if (!$value || !$label) {
                $value = $value ?? $label;
                $label = $label ?? $value;
            }
            if (!$value || !$label) {
                continue;
            }
            $formattedOptions[] = [
                'label' => $label,
                'value' => $value,
            ];
        }
        
        return $formattedOptions;
    }
    
    protected function getBlankFormConfig()
    {
        $attributes = ['type' => 'form', 'predefined' => 'blank_form'];
        $customForm = Form::resolvePredefinedForm($attributes);
        $customForm['form_fields'] = json_decode($customForm['form_fields'], true);
        $customForm['form_fields']['submitButton'] = $customForm['form']['submitButton'];
        $customForm['form_fields'] = json_encode($customForm['form_fields']);
        return $customForm;
    }
    
    protected function saveForm($formattedInputs, $title, $isStepFrom = false, $isConversational = false)
    {
        $customForm = $this->getBlankFormConfig();
        $fields = json_decode($customForm['form_fields'], true);
        $submitButton = $customForm['form']['submitButton'];
        $fields['form_fields']['fields'] = $formattedInputs;
        $fields['form_fields']['submitButton'] = $submitButton;
        if ($isStepFrom) {
            $fields['form_fields']['stepsWrapper'] = $this->getStepWrapper();
        }
        $customForm['form_fields'] = json_encode($fields['form_fields']);
        $data = Form::prepare($customForm);
        $form = $this->model->create($data);
        $form->title = $title ?: $form->title . ' (ChatGPT#' . $form->id . ')';
        if ($isConversational) {
            $formMeta = FormMeta::prepare(['type' => 'form', 'predefined' => 'conversational'], $customForm);
            $form->fill([
                'form_fields' => Converter::convertExistingForm($form),
            ])->save();
        } else {
            $form->save();
            $formMeta = FormMeta::prepare(['type' => 'form', 'predefined' => 'blank_form'], $customForm);
        }
        FormMeta::store($form, $formMeta);
        
        do_action('fluentform/inserted_new_form', $form->id, $data);
        return $form;
    }

    protected function maybeAddPayments($fluentFormFields, $allFields)
    {
        $paymentElements = ['payment_method', 'multi_payment_component'];
        $foundElements = [];
        if (empty($fluentFormFields)) {
            return [];
        }
        foreach ($fluentFormFields as $item) {
            if (in_array(Arr::get($item, "element", []), $paymentElements)) {
                $foundElements[] = $item["element"];
            }
        }
        // Find the elements in $paymentElements that are not in $foundElements
        $remainingElements = array_diff($paymentElements, $foundElements);
        $formPaymentElm = [];
        if ($foundElements && !empty($remainingElements)) {
            foreach ($remainingElements as $elmKey) {
                $formPaymentElm[] = $this->getElementByType($allFields, $elmKey);
            }
            return array_merge($fluentFormFields, $formPaymentElm);
        }
        return $fluentFormFields;
    }

    protected function resolveContainerFields($fields, $allFields)
    {
        $fieldsCount = count($fields);
        if (!$fieldsCount || $fieldsCount > 6) {
            return [];
        }
        $matchedField = Arr::get($allFields, 'container.container_' . $fieldsCount . '_col');
        if (!$matchedField) {
            return [];
        }
        $columnWidth = round(100 / $fieldsCount, 2);
        $formattedColumnsFields = [];
        foreach ($fields as $field) {
            if ($fieldKey = $this->resolveInput($field)) {
                if ($columnField = $this->processField($fieldKey, $field, $allFields)) {
                    $formattedColumnsFields[] = [
                        'width' => $columnWidth,
                        'fields' => [$columnField],
                    ];
                }
            }
        }
        if ($formattedColumnsFields) {
            $matchedField['columns'] = $formattedColumnsFields;
        } else {
            return [];
        }
        return $matchedField;
    }

    /**
     * @return array
     */
    protected function getStepWrapper()
    {
        return [
            'stepStart' => [
                'element'        => 'step_start',
                'attributes'     => [
                    'id'    => '',
                    'class' => '',
                ],
                'settings'       => [
                    'progress_indicator'           => 'progress-bar',
                    'step_titles'                  => [],
                    'disable_auto_focus'           => 'no',
                    'enable_auto_slider'           => 'no',
                    'enable_step_data_persistency' => 'no',
                    'enable_step_page_resume'      => 'no',
                ],
                'editor_options' => [
                    'title' => 'Start Paging'
                ],
            ],
            'stepEnd'   => [
                'element'        => 'step_end',
                'attributes'     => [
                    'id'    => '',
                    'class' => '',
                ],
                'settings'       => [
                    'prev_btn' => [
                        'type'    => 'default',
                        'text'    => 'Previous',
                        'img_url' => ''
                    ]
                ],
                'editor_options' => [
                    'title' => 'End Paging'
                ],
            ]
        ];
    }
    
}
