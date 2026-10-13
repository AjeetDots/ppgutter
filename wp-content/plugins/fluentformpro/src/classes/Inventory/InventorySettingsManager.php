<?php

namespace FluentFormPro\classes\Inventory;

defined('ABSPATH') or die;

use FluentForm\Framework\Helpers\ArrayHelper as Arr;

/**
 *  Inventory Module Manager
 *
 * @since 4.3.13
 */
class InventorySettingsManager
{
    
    public static function boot()
    {
        return new self();
    }
    
    public function __construct()
    {
        $this->insertDefaultValues();
        
        $this->insertDefaultValuesToExistingForm();
        
        $this->insertEditorSettings();
        
    
    }
    
    public function insertDefaultValues()
    {
        $upgradableInputs = static::getInventoryInputs();
        add_filter('fluentform/editor_components', function ($components) use ($upgradableInputs) {
            //mapping select,input_radio,input_checkbox, multi_payment_component keys and groups
            $inventoryFieldsKeyGroupMaps = [
                8  => 'general',
                9  => 'general',
                10 => 'general',
                0  => 'payments',
            ];
            foreach ($inventoryFieldsKeyGroupMaps as $key => $group) {
                $item = Arr::get($components, $group . '.' . $key);
                if (in_array(Arr::get($item, 'element'), $upgradableInputs)) {
                    $inventorySettings = [
                        'inventory_type'             => false,
                        'inventory_stockout_message' => __('This Item is Stock Out', 'fluentformpro'),
                        'hide_choice_when_stockout'  => 'no',
                        'hide_input_when_stockout'   => 'no',
                        'disable_input_when_stockout'=> 'no',
                        'show_stock'                 => 'no',
                        'simple_inventory'           => '',
                        'single_inventory_stock'     => 10,
                        // translators: %s is the remaining stock quantity
                        'stock_quantity_label'       => sprintf(__(' - %s available', 'fluentformpro'),'{remaining_quantity}'),
                        'global_inventory'         => ''
                    ];

                    if (isset($components[$group][$key]['settings'])) {
                        $components[$group][$key]['settings'] = array_merge($components[$group][$key]['settings'], $inventorySettings);
                    }
                }
            }
            return $components;
        }, 10, 1);
    }
    
    public function insertEditorSettings()
    {
        add_filter('fluentform/editor_element_settings_placement', function ($placements) {
            $upgradableInputs = static::getInventoryInputs();
            
            foreach ($upgradableInputs as $inputKey) {
                if (!isset($placements[$inputKey])) {
                    break;
                }
                $placements[$inputKey]['advanced'][] = [
                    array_keys($this->getAdditionalSettings())
                ];
                $placements[$inputKey]['advancedExtras'] = $this->getAdditionalSettings();
            }
            return $placements;
        });
    }
    
    public function getAdditionalSettings()
    {
        return [
            
            'inventory_type'             => [
                'template' => 'radio',
                'label'    => __('Inventory Settings', 'fluentformpro'),
                'options'  => [
                    [
                        'value' => false,
                        'label' => __('Disable', 'fluentformpro'),
                    ],
                    [
                        'value' => 'simple',
                        'label' => __('Simple', 'fluentformpro'),
                    ],
                    [
                        'value' => 'global',
                        'label' => __('Global', 'fluentformpro'),
                    ],
                ],
            ],
            'global_inventory'   => [
                'template'  => 'inventoryStock',
                'label'     => __('Global Inventory', 'fluentformpro'),
                'help_text' => __('Select a Global Inventory', 'fluentformpro'),
                'options'   => $this->getGloablInventoryList(),
                'dependency'       => [
                    'depends_on' => 'settings/inventory_type',
                    'value'      => 'global',
                    'operator'   => '=='
                ]
            ],
            'simple_inventory'   => [
                'template'   => 'inventoryStock',
                'label'      => __('Stock Quantity', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/inventory_type',
                    'value'      => 'simple',
                    'operator'   => '=='
                ],
            ],
            'inventory_stockout_message' => [
                'template'   => 'inputText',
                'label'      => __('Stock Out Message', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/inventory_type',
                    'value'      => false,
                    'operator'   => '!='
                ]
            ],
            'hide_choice_when_stockout'  => [
                'template'   => 'inputYesNoCheckBox',
                'label'      => __('Hide Choice When Stock is Out', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/inventory_type',
                    'value'      => false,
                    'operator'   => '!='
                ]
            ],
            'hide_input_when_stockout'   => [
                'template'   => 'inputYesNoCheckBox',
                'label'      => __('Hide Input When Stock is Out', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/inventory_type',
                    'value'      => false,
                    'operator'   => '!='
                ]
            ],
            'disable_input_when_stockout'   => [
                'template'   => 'inputYesNoCheckBox',
                'label'      => __('Disable Input When Stock is Out', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/inventory_type',
                    'value'      => false,
                    'operator'   => '!='
                ]
            ],
            'show_stock'                 => [
                'template'   => 'inputYesNoCheckBox',
                'label'      => __('Show Available Stock', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/inventory_type',
                    'value'      => false,
                    'operator'   => '!='
                ]
            ],
            'stock_quantity_label'       => [
                'template'         => 'inputText',
                'label'            => __('Inventory Label', 'fluentformpro'),
                'inline_help_text' => __('This Label will be appended to the field’s label or field’s Options',
                    'fluentformpro'),
                'dependency'       => [
                    'depends_on' => 'settings/inventory_type',
                    'value'      => false,
                    'operator'   => '!='
                ]
            ],
        ];
    }
    
    public static function getInventoryInputs()
    {
        $fields = [
            'select',
            'input_radio',
            'input_checkbox',
            'multi_payment_component'
        ];
        $fields = apply_filters_deprecated(
            'fluentform_inventory_inputs',
            [
                $fields
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/inventory_inputs',
            'Use fluentform/inventory_inputs instead of fluentform_inventory_inputs.'
        );
        return apply_filters('fluentform/inventory_inputs', $fields);
    }
    public function getGloablInventoryList()
    {
        $inventoryList = get_option('ff_inventory_list');
        if ($inventoryList !== false) {
            $formattedList = [];
           foreach ($inventoryList as $inventoryKey => $item){
            if(!empty($inventoryKey) &&  !empty($item['slug'])){
                $formattedList[] = [
                    'value' => $item['slug'],
                    'label' => $item['name']
                ];
            }
           }
           $inventoryList = $formattedList;
        
        } else {
            $inventoryList = [];
        }
        return $inventoryList;
    }
    protected function insertDefaultValuesToExistingForm()
    {
        $upgradableInputs = static::getInventoryInputs();
        foreach ($upgradableInputs as $inputKey) {
            add_filter('fluentform/editor_init_element_' . $inputKey, function ($field) {
                $defaultSettings = [
                    'inventory_stockout_message'  => __('This Item is Stock Out', 'fluentformpro'),
                    'inventory_type'              => '',
                    'hide_choice_when_stockout'   => '',
                    'disable_input_when_stockout' => '',
                    'hide_input_when_stockout'    => '',
                    'show_stock'                  => '',
                    'simple_inventory'            => '',
                    'single_inventory_stock'      => 1,
                    // translators: %s is the remaining stock quantity
                    'stock_quantity_label'        => sprintf(__(' - %s available', 'fluentformpro'), '{remaining_quantity}')
                ];
    
                foreach ($defaultSettings as $settingKey => $defaultValue) {
                    if (!isset($field['settings'][$settingKey])) {
                        $field['settings'][$settingKey] = $defaultValue;
                    }
                }
                if (!isset($field['settings']['global_inventory'])) {
                    $field['settings']['global_inventory'] = '';
                }
    
                return $field;
            });
        }
    }
    
}
