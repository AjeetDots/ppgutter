<?php

namespace FluentFormPro\classes\Inventory;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Models\SubmissionMeta;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\Framework\Support\Arr;
use FluentForm\Framework\Validator\ValidationException;
use FluentForm\Framework\Validator\Validator;

/**
 * Handling Global Inventory Module.
 *
 * @since 4.3.13
 */
class InventoryController
{
    protected $key = 'inventory_module';
    
    public function boot()
    {
        $enabled = $this->isEnabled();
        
        add_filter('fluentform/global_addons', function ($addOns) use ($enabled) {
            $addOns[$this->key] = [
                'title'       => 'Inventory Module',
                'description' => __('Powerful Inventory Management. Manage resources for events booking, reservations, or for selling products and tickets!',
                    'fluentformpro'),
                'logo'        => fluentFormMix('img/integrations/inventory.png'),
                'enabled'     => ($enabled) ? 'yes' : 'no',
                'config_url'  => '',
                'category'    => '' //Category : All
            ];
            return $addOns;
        }, 9);
        
        if (!$enabled) {
            return;
        }
        add_filter('fluentform/global_settings_components', array($this, 'addGlobalMenu'), 1);
        add_action('fluentform/submission_inserted', [$this, 'insertGlobalInventory'], 10, 3);
        add_action('fluentform/after_transaction_status_change', [$this, 'maybeUpdateGlobalInventory'], 10, 3);

        // Keep the core server-side price resolver (#1164) from charging a sold-out item
        // the renderer withheld from the buyer.
        add_filter('fluentform/is_server_priced_payment_item', [$this, 'excludeSoldOutPaymentItem'], 10, 3);

        /**
         * Validate Inventory Form Fields
         */
        add_action('fluentform/before_insert_submission', function ($insertData, $formData, $form) {
            (new InventoryValidation($formData, $form))->validate();
        }, 10, 3);
        
        /**
         * Process Inventory Fields Options Comparing Previous Submissions
         */
        (new InventoryFieldsRenderer())->processBeforeFormRender();
        
        add_action('wp_ajax_fluentform_get_global_inventory_list', [$this, 'getGlobalInventoryList']);
        add_action('wp_ajax_fluentform_store_global_inventory_list', [$this, 'storeGlobalInventory']);
        add_action('wp_ajax_fluentform_delete_global_inventory_list', [$this, 'deleteGlobalInventory']);
        add_action('wp_ajax_fluentform_reset_global_inventory_item', [$this, 'resetGlobalInventory']);
        InventorySettingsManager::boot();
        InventoryList::boot();
    }

    /**
     * Drop a sold-out fixed-price item from #1164's server-side resolution so it is not
     * oversold. Only narrows; never re-includes what core already excluded.
     */
    public function excludeSoldOutPaymentItem($isServerPriced, $paymentInput, $form)
    {
        if (!$isServerPriced) {
            return $isServerPriced;
        }

        // Only the hide flag removes a single item's input at render; the disable flag
        // touches only its mapped quantity field, so the item itself is always submitted
        // and an omitted key is tampering. A still-visible sold-out item must keep core's
        // server-side resolution -- otherwise omitting it reopens the zero-total fail-open
        // that #1164 closes.
        if ('yes' !== ArrayHelper::get($paymentInput, 'settings.hide_input_when_stockout')) {
            return $isServerPriced;
        }

        if ((new InventoryValidation([], $form))->isSinglePaymentItemSoldOut($paymentInput)) {
            return false;
        }

        return $isServerPriced;
    }

    public function isEnabled()
    {
        $globalModules = get_option('fluentform_global_modules_status');
        $inventoryModule = ArrayHelper::get($globalModules, $this->key);
        if ($inventoryModule == 'yes') {
            return true;
        }
        return false;
    }
    
    public function addGlobalMenu($setting)
    {
        $setting['InventoryManager'] = [
            'hash'  => 'inventory_manager',
            'title' => 'Inventory Manager',
        ];
        return $setting;
    }
    
    public function getGlobalInventoryList()
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager'); // SECURITY (PRO-35): ff_inventory_list is a site-wide option under Global Settings
        $inventoryList = get_option('ff_inventory_list');
        if ($inventoryList !== false) {
            $formattedList = [];
            $items = array_filter(array_keys($inventoryList));
            $usedItems = InventoryValidation::getSubmittedGlobalInventories($items);
            $formattedUsedItems = InventoryValidation::calculateGlobalInventory($usedItems, true);
            foreach ($inventoryList as $inventoryKey => $value) {
                if (!empty($inventoryKey) && !empty($value['slug'])) {
                    if (array_key_exists($inventoryKey, $formattedUsedItems)) {
                        $totalUsed = array_reduce($formattedUsedItems[$inventoryKey], function ($res, $item) {
                            return $res + $item;
                        }, 0);
                        $value['remaining'] = max($value['quantity'] - $totalUsed, 0);
                        $value['details'] = $formattedUsedItems[$inventoryKey];
                    } elseif ($qty = Arr::get($value, 'quantity')) {
                        $value['remaining'] = $qty;
                    }
                    $formattedList[$inventoryKey] = $value;
                }
            }
            $inventoryList = $formattedList;
        } else {
            $inventoryList = [];
        }
        wp_send_json_success([
            'success'        => true,
            'inventory_list' => $inventoryList
        ]);
    }
    
    public function storeGlobalInventory()
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager'); // SECURITY (PRO-35): ff_inventory_list is a site-wide option under Global Settings
        try {
            $requestData = wpFluentForm()->request->all();
            
            $sanitizeMap = [
                'inventory' => function($value) {
                    if (is_array($value)) {
                        return [
                            'name' => sanitize_text_field(Arr::get($value, 'name', '')),
                            'slug' => sanitize_text_field(Arr::get($value, 'slug', '')),
                            'quantity' => intval(Arr::get($value, 'quantity', 0)),
                        ];
                    }
                    return $value;
                },
            ];
            $requestData = fluentform_backend_sanitizer($requestData, $sanitizeMap);
            
            $item = Arr::get($requestData, 'inventory');
            $this->validate($item);
            //If request has slug, requested for update
            $slug = Arr::get($item, 'slug');
            // Item is already sanitized by fluentform_backend_sanitizer above
            $postedInventory = [
                'name'     => $item['name'],
                'quantity' => $item['quantity'],
                'slug'     => $slug ? sanitize_title($slug) : sanitize_title($item['name']),
            ];

            $inventoryList = get_option('ff_inventory_list');
            if (is_array($inventoryList)) {
                if (Arr::exists($inventoryList, $postedInventory['slug'])) {
                    if ($slug) {
                        //Updating an existing item
                        $inventoryList[$slug] = $postedInventory;
                        $message = __('Inventory Updated', 'fluentformpro');
                    } else {
                        //Trying to create new, but inventory slug already exists
                        throw new ValidationException(__('Inventory name already exists', 'fluentformpro'));
                    }
                } else {
                    //Adding a new item
                    $inventoryList[$postedInventory['slug']] = $postedInventory;
                    $message = __('New Inventory Added', 'fluentformpro');
                }
            } else {
                //Adding the first item
                $message = __('New Inventory Added', 'fluentformpro');
                $inventoryList = [
                    $postedInventory['slug'] => $postedInventory
                ];
            }
            update_option('ff_inventory_list', $inventoryList, false);
            wp_send_json_success([
                'success'        => true,
                'message'        => $message,
                'inventory_list' => array_values($inventoryList)
            ]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (!$errors) {
                $errors = [
                    'errors' => [
                        'name' => $exception->getMessage()
                    ]
                ];
            }
            wp_send_json_error($errors, 422);
        }
    }
    
    public function deleteGlobalInventory()
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager'); // SECURITY (PRO-35): ff_inventory_list is a site-wide option under Global Settings
        $itemSlug = sanitize_text_field(wpFluentForm()->request->get('slug'));
        $inventoryList = get_option('ff_inventory_list');
        if ($itemSlug && is_array($inventoryList) && array_key_exists($itemSlug, $inventoryList)) {
            unset($inventoryList[$itemSlug]);
            update_option('ff_inventory_list', $inventoryList, false);
            wp_send_json_success([
                'success' => true,
                'message' => __("Deleted Successfully", 'fluentformpro'),
            ]);
        }
        wp_send_json_success([
            'success' => true,
            'message' => __("Invalid Item", 'fluentformpro'),
        ]);
    }
    
    public function resetGlobalInventory()
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager'); // SECURITY (PRO-35): ff_inventory_list is a site-wide option under Global Settings
        $itemSlug = sanitize_text_field(wpFluentForm()->request->get('slug'));
        $inventoryList = get_option('ff_inventory_list');
        if ($itemSlug && is_array($inventoryList) && array_key_exists($itemSlug, $inventoryList)) {
            SubmissionMeta::where('meta_key', 'ff_used_global_inventory_item')
                ->where('name', $itemSlug)
                ->delete();
            wp_send_json_success([
                'success' => true,
                'message' => __('Item was successfully reset', 'fluentformpro'),
            ]);
        }
        wp_send_json_error([
            'success' => true,
            'message' => __("Invalid Item", 'fluentformpro'),
        ]);
    }

    public function maybeUpdateGlobalInventory($status, $submission, $transitionId)
    {
        $form = Helper::getForm($submission->form_id);
        $inventoryFields = InventoryFieldsRenderer::getInventoryFields($form, ['global']);
        if (empty($inventoryFields)) {
            return;
        }

        try {
            $submissionInventoryItems = SubmissionMeta::where('meta_key', 'ff_used_global_inventory_item')
                ->where('response_id', $submission->id)
                ->where('form_id', $form->id);
            if ($submissionInventoryItems->count() > 0) {
                if ('refunded' === $status) {
                    $submissionInventoryItems->update(['status' => 'refunded']);
                } else {
                    $submissionInventoryItems->update(['status' => null]);
                }
            }
        } catch (\Exception $e) {}
    }
    
    public function insertGlobalInventory($insertId, $formData, $form)
    {
        $inventoryValidation = (new InventoryValidation($formData, $form));
        
        $inventoryFields = InventoryFieldsRenderer::getInventoryFields($form, ['global']);
        $usedInventoryFields = [];
        //@improve handle single payment input

        foreach ($inventoryFields as $inventoryFieldName => $field) {
            $value = ArrayHelper::get($formData, $inventoryFieldName);
            // A "0" option (e.g. a free ticket) is a selection and must be booked
            if (!empty($value) || ('0' === $value && !$inventoryValidation->isSingleInventoryField($field))) {
                $usedInventoryFields[$inventoryFieldName] = $formData[$inventoryFieldName];
            }
        }
        
        if (empty($usedInventoryFields)) {
            return;
        }
        foreach ($usedInventoryFields as $fieldName => $value) {
            $globalInventoryItems = [];
            $field = Arr::get($inventoryFields, $fieldName);
            if ($inventoryValidation->isSingleInventoryField($field)) {
                $inventorySlug = Arr::get($field,'settings.global_inventory');
                $label = Arr::get($field, 'settings.label');
                if (!$label) {
                    $label = Arr::get($field, 'settings.admin_field_label');
                }
                $globalInventoryItems[$inventorySlug] = $label ? $label : $value;
            } else {
                $options = $inventoryValidation->getOptions($field);
                $optionKey = $inventoryValidation->isPaymentField($field) ? 'label' : 'value';
                $options = array_filter($options, function ($option) use ($value, $optionKey) {
                    if (is_array($value)) {
                        return in_array($option[$optionKey], $value);
                    }
                    return $option[$optionKey] == $value;
                });
                foreach ($options as $option) {
                    if ($inventorySlug = Arr::get($option, 'global_inventory')) {
                        if (is_array($value)) {
                            $item = $option[$optionKey];
                            if (Arr::exists($globalInventoryItems, $inventorySlug)) {
                                $globalInventoryItems[$inventorySlug][] = $item;
                            } else {
                                $globalInventoryItems[$inventorySlug] = [$item];
                            }
                        } else {
                            $globalInventoryItems[$inventorySlug] = $value;
                        }
                    }
                }
            }
            $quantity = $inventoryValidation->getQuantity(
                $fieldName,
                $formData,
                $inventoryValidation->isPaymentField($field)
            );
            if ($quantity == 0) {
                continue;
            }
            foreach ($globalInventoryItems as $inventoryItemSlug => $dataValue) {
                SubmissionMeta::insertGetId([
                    'form_id'     => $form->id,
                    'response_id' => $insertId,
                    'meta_key'    => 'ff_used_global_inventory_item',
                    'value'       => json_encode([
                        'value'    => $dataValue,
                        'quantity' => $quantity,
                    ]),
                    'name'        => $inventoryItemSlug
                ]);
            }
        }
    }
    
    private function validate($inventory)
    {
        $rules = [
            'name'     => 'required',
            'quantity' => 'required|numeric|min:1',
        ];
        
        $validatorInstance = new Validator();
        $validator = $validatorInstance->make($inventory, $rules);
        
        $errors = null;
        
        if ($validator->validate()->fails()) {
            $errors = $validator->errors();
        }
        
        if ($errors) {
            throw new ValidationException('', 0, null, [ // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                'errors' => $errors,
            ]);
        }
    }
}
