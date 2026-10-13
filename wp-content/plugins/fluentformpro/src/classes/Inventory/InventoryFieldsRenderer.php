<?php

namespace FluentFormPro\classes\Inventory;

defined('ABSPATH') or die;

use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class InventoryFieldsRenderer
{
    public function adjustOptions($attr)
    {
        $field = $attr['field'];
        $inventoryValidation = (new InventoryValidation([], $attr['form']));
        $isPaymentInput = $inventoryValidation->isPaymentField($field);
        $optionKey = $isPaymentInput ? 'pricing_options' : 'advanced_options';
    
        $options = Arr::get($field, 'settings.' . $optionKey);
        if (empty($options)) {
            return $options;
        }
        $inventoryType = Arr::get($attr, 'inventoryType');
        $maybeAllOptionStockOut = 'yes';
        $options = $this->adjustOptionCollection(
            $options,
            $field,
            $attr,
            $inventoryValidation,
            $inventoryType,
            $isPaymentInput,
            $maybeAllOptionStockOut
        );
        
        // Hide Inputs When All Option Is Stock-out
        $hideStockInput = Arr::get($field, 'settings.hide_input_when_stockout') == 'yes';
        $disableStockInput = Arr::get($field, 'settings.disable_input_when_stockout') == 'yes';
        if ($maybeAllOptionStockOut == 'yes') {
            if ($hideStockInput) {
                $field['settings']['container_class'] .= 'has-conditions ff_excluded ';

                //condition to return false always if Stock-out for conversational form
                $field['settings']['conditional_logics'] = [
                    'status'     => true,
                    'type'       => 'all',
                    'conditions' => [
                        [
                            'field'    => Arr::get($field, 'attributes.name'),
                            'operator' => '!=',
                            'value'    => null
                        ]
                    ]
                ];
            }

            $this->maybeUpdateMappingQuantityField($attr['form'], $attr['quantityFieldName'], $hideStockInput, $disableStockInput);
        }

    
        $field['settings'][$optionKey] = array_values($options);
        return $field;
    }

    private function adjustOptionCollection($options, $field, $attr, $inventoryValidation, $inventoryType, $isPaymentInput, &$maybeAllOptionStockOut)
    {
        foreach ($options as $key => $option) {
            if (Arr::get($option, 'type') === 'group' && is_array(Arr::get($option, 'options'))) {
                $option['options'] = $this->adjustOptionCollection(
                    Arr::get($option, 'options', []),
                    $field,
                    $attr,
                    $inventoryValidation,
                    $inventoryType,
                    $isPaymentInput,
                    $maybeAllOptionStockOut
                );

                if (empty($option['options'])) {
                    unset($options[$key]);
                    continue;
                }

                $options[$key] = $option;
                continue;
            }

            $item = [
                'parent_input_name' => Arr::get($field, 'attributes.name'),
                'item_name'         => Arr::get($option, 'label'),
                'item_value'        => Arr::get($option, 'value'),
                'quantity'          => Arr::get($option, 'quantity'),
            ];

            if ('simple' == $inventoryType) {
                if ($isPaymentInput) {
                    $itemPrice = \FluentFormPro\Payments\PaymentHelper::convertToCents($item['item_value']);
                    $used = InventoryValidation::getPaymentItemSubmissionQuantity(
                        $attr['formId'],
                        $item['parent_input_name'],
                        $item['item_name'],
                        $itemPrice
                    );
                } else {
                    $used = InventoryValidation::getRegularItemUsedQuantity($attr['previousSubmissionData'], $item);
                }
                $totalQuantity = Arr::get($option, 'quantity');
            } else {
                $inventorySlug = Arr::get($option, 'global_inventory');
                if (!$inventorySlug) {
                    continue;
                }
                list ($totalQuantity, $used) = $inventoryValidation->getGlobalInventoryInfo($inventorySlug);
            }

            if (empty($totalQuantity)) {
                $totalQuantity = 0;
            }

            $remaining = max($totalQuantity - $used, 0);

            if ($attr['showStock'] && $isPaymentInput) {
                $option['quantity_remaining'] = $remaining;
                $option['quantiy_label'] = str_replace('{remaining_quantity}', $remaining, $attr['stockLabel']);
            } elseif ($attr['showStock']) {
                $option['label'] .= str_replace('{remaining_quantity}', $remaining, $attr['stockLabel']);
            }

            if ($remaining > 0) {
                $maybeAllOptionStockOut = 'no';
            }

            if (Arr::get($field, 'settings.disable_input_when_stockout') == 'yes' && $remaining <= 0) {
                $option['disabled'] = true;
            }

            if ($attr['hideChoice'] && $remaining == 0) {
                unset($options[$key]);
                continue;
            }

            $options[$key] = $option;
        }

        return array_values($options);
    }
    
    public function adjustSinglePaymentItem($field, $form, $stockLabel, $inventoryType, $quantityFieldName, $previousSubmissionData)
    {
        if ($inventoryType == 'simple') {
            $remaining = InventoryValidation::simpleSingleItemRemaining($field, $previousSubmissionData);
        } else {
            $inventoryValidation = (new InventoryValidation([], $form));
            $inventorySlug =  Arr::get($field, 'settings.global_inventory');
            list ($totalQuantity, $usedQuantity) = $inventoryValidation->getGlobalInventoryInfo($inventorySlug);
            if (!$inventoryValidation->isGlobalInventoryConfigured($inventorySlug)) {
                return $field;
            }
            $remaining = max($totalQuantity - $usedQuantity, 0);
        }
        if ('yes' == Arr::get($field, 'settings.show_stock')) {
            $field['settings']['label'] .= str_replace('{remaining_quantity}', $remaining, $stockLabel);
        }
        $field['settings']['quantity_remaining'] = $remaining;
        $hideStockoutInput = Arr::get($field, 'settings.hide_input_when_stockout') == 'yes';
        $disableStockoutInput = Arr::get($field, 'settings.disable_input_when_stockout') == 'yes';
        if ($remaining == 0) {
            if ($hideStockoutInput) {
                $field['settings']['container_class'] .= 'has-conditions ff_excluded ';
            }
            $this->maybeUpdateMappingQuantityField($form, $quantityFieldName, $hideStockoutInput, $disableStockoutInput);
        }
        return $field;
    }

    private function maybeUpdateMappingQuantityField($form, $quantityFieldName, $hideInput, $disableInput)
    {
        $quantityField = FormFieldsParser::getField($form, ['item_quantity_component', 'rangeslider'],$quantityFieldName, ['element']);
        if ($quantityField) {
            $quantityFieldElement = Arr::get($quantityField, $quantityFieldName . '.element');
            add_filter('fluentform/rendering_field_data_' . $quantityFieldElement,
                function ($field) use ($quantityFieldName, $hideInput, $disableInput) {
                    if (Arr::get($field, 'attributes.name') == $quantityFieldName) {
                        if ($hideInput) {
                            $field['settings']['container_class'] .= ' has-conditions ff_excluded ';
                        }
                        if ($disableInput) {
                            $field['attributes']['class'] .= ' ff-read-only ';
                            $field['attributes']['disabled'] = '1';
                        }
                    }
                    return $field;
                });
        }
    }
    
    public function processInventoryFields($field, $form, $previousSubmissionData, $inventoryType, $quantityFieldName) {
        
        list($inputType, $showStock, $stockLabel, $hideChoice) = $this->validationMessages($field);
        
        if ($inputType == 'single') {
            $field = $this->adjustSinglePaymentItem($field, $form, $stockLabel, $inventoryType, $quantityFieldName, $previousSubmissionData);
        } elseif ($inputType == 'radio' || $inputType == 'checkbox' || $inputType == 'select') {
            $attr = [
                'formId'                 => $form->id,
                'field'                  => $field,
                'form'                   => $form,
                'previousSubmissionData' => $previousSubmissionData,
                'stockLabel'             => $stockLabel,
                'showStock'              => $showStock,
                'hideChoice'             => $hideChoice,
                'inventoryType'          => $inventoryType,
                'quantityFieldName'      => $quantityFieldName,
            ];
            $field = $this->adjustOptions($attr);
        }
        
        $field = apply_filters_deprecated(
            'fluentform_inventory_fields_before_render',
            [
                $field,
                $form,
                $previousSubmissionData
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/inventory_fields_before_render',
            'Use fluentform/inventory_fields_before_render instead of fluentform_survey_shortcode_defaults'
        );
        return apply_filters('fluentform/inventory_fields_before_render', $field, $form, $previousSubmissionData);
    }
    
    /**
     * Get Inventory Settings Activated Fields
     * @param $form
     * @param $type simple|global
     * @return array
     */
    public static function getInventoryFields($form, $types = ['simple', 'global'])
    {
        $inventoryAllowedInputs = InventorySettingsManager::getInventoryInputs();
        $inventoryFields = FormFieldsParser::getElement($form, $inventoryAllowedInputs, ['element', 'attributes', 'settings','label']);
        
        $inventoryActivatedFields = [];
        foreach ($inventoryFields as $fieldName => $field) {
            $inventoryType = Arr::get($field, 'settings.inventory_type');
            if (in_array($inventoryType, $types)) {
                $inventoryActivatedFields[$fieldName] = $field;
            }
        }
        return $inventoryActivatedFields;
    }

    /**
     * Get Inventory Quantity Activated Fields
     * @param $form
     * @return array
     */
    public static function getQuantityFieldsMapping($form)
    {
        $quantityItems = [];
        $quantityFields = FormFieldsParser::getElement($form, ['item_quantity_component', 'rangeslider'], ['element', 'attributes', 'settings']);
        foreach ($quantityFields as $field) {
            if ('rangeslider' == Arr::get($field, 'element') && 'yes' != Arr::get($field, 'settings.enable_target_product')) {
                continue;
            }
            if ($targetProductName = Arr::get($field, 'settings.target_product')) {
                $quantityItems[$targetProductName] = Arr::get($field, 'attributes.name');
            }
        }
        return $quantityItems;
    }
    
    /**
     * Show or Hide Remaining Inventory Options Comparing Previous Submissions
     * @return void
     * @throws \Exception
     */
    public function processBeforeFormRender()
    {
        static $previousSubmissionCache = [];
        
        add_filter('fluentform/rendering_form', function ($form) use ($previousSubmissionCache) {
            $simpleInventory = static::getInventoryFields($form, ['simple']);
            if (!isset($previousSubmissionCache[$form->id]) && !empty($simpleInventory)) {
                $inventoryFieldsNames = array_keys($simpleInventory);
                $previousSubmissionCache[$form->id] = InventoryValidation::getRenderedEntryReport($form, $inventoryFieldsNames);
            }
            $globalInventory = static::getInventoryFields($form, ['global']);
            if (empty($globalInventory) && empty($simpleInventory)) {
                return $form;
            }
            $inventoryFields = array_merge($simpleInventory, $globalInventory);
            $quantityFields = static::getQuantityFieldsMapping($form);

            foreach ($inventoryFields as $inventoryField) {
                $element = $inventoryField['element'];
                $inventoryType = Arr::get($inventoryField, 'settings.inventory_type', false);
                add_filter('fluentform/rendering_field_data_' . $element,
                    function ($field, $form) use (
                        $previousSubmissionCache,
                        $inventoryField,
                        $quantityFields,
                        $inventoryType
                    ) {
                        if (Arr::get($inventoryField, 'attributes.name') == Arr::get($field, 'attributes.name')) {
                            $quantityFieldName = Arr::get($quantityFields, Arr::get($inventoryField, 'attributes.name'));
                            $submissionCache = isset($previousSubmissionCache[$form->id]) ? $previousSubmissionCache[$form->id] : [];
                            $field = $this->processInventoryFields($field, $form, $submissionCache, $inventoryType, $quantityFieldName);
                        }
                        return $field;
                    }, 10, 2);
            }
            
            return $form;
        });
    }
    
    private function validationMessages($field)
    {
        $inputType = Arr::get($field, 'attributes.type') ? Arr::get($field, 'attributes.type') : Arr::get($field,
            'element');
        $showStock = Arr::get($field, 'settings.show_stock') == 'yes';
        $stockLabel = wp_kses_post((string) Arr::get($field, 'settings.stock_quantity_label'));
        $hideChoice = Arr::get($field, 'settings.hide_choice_when_stockout') == 'yes';

        
        return [$inputType, $showStock, $stockLabel, $hideChoice];
    }
    
}
