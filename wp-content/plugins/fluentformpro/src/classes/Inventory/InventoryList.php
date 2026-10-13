<?php
namespace FluentFormPro\classes\Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Models\Form;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Report\ReportHelper;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;

/**
 *  Inventory Entries List
 *
 * @since 5.0.8
 */
class InventoryList
{
    
    public static function boot()
    {
        return new static();
    }

    public function __construct()
    {
    
        add_filter('fluentform/form_admin_menu', [$this, 'maybeAddAdminMenu'], 10, 2);

        add_action('fluentform/form_application_view_inventory_list', [$this, 'renderInventoryList']);

        add_filter('fluentform/form_inner_route_permission_set', array($this, 'setRoutePermission'));
    }

  
    public function maybeAddAdminMenu($formAdminMenus, $form_id)
    {
        $form = Form::find($form_id);
        $hasInventoryFields = InventoryFieldsRenderer::getInventoryFields($form,['simple']);
        if (count($hasInventoryFields) > 0) {
            $formAdminMenus['inventory_list'] = [
                'hash' => '/',
                'slug' => 'inventory_list',
                'title' => __('Inventory', 'fluentformpro'),
                'url' => admin_url(
                    'admin.php?page=fluent_forms&form_id=' . $form_id . '&route=inventory_list'
                )
            ];
        }

        return $formAdminMenus;
    }

    public function renderInventoryList($form_id)
    {
        $this->enqueueScript();

        $form = Form::find($form_id);
        $inventoryFields = $this->getInventoryFields($form, ['simple']);
        $fields = FormFieldsParser::getInputs($form, ['element', 'settings', 'label','attributes']);
        $submissionRecords = ReportHelper::getInputReport($form->id, array_keys($inventoryFields));
        $formattedSubmissions = [];
        if (!$submissionRecords || count($submissionRecords) < count($inventoryFields)) {
            $submissionRecords = array_merge($inventoryFields, $submissionRecords);
        }
        $quantityMappingFields = InventoryFieldsRenderer::getQuantityFieldsMapping($form);
        foreach ($submissionRecords as $name => $submission) {
            $submissionItems = ArrayHelper::get($submission, 'reports', []);
            $input = ArrayHelper::get($fields, $name);
            $isSinglePaymentItem = ArrayHelper::get($input, 'element') == 'multi_payment_component' && ArrayHelper::get($input, 'attributes.type') == 'single';
            $isPaymentItem = ArrayHelper::get($input, 'element') == 'multi_payment_component';
            $label = ArrayHelper::get($input, 'label');
            if ($submissionItems && ($isPaymentItem || ArrayHelper::exists($quantityMappingFields, $name))) {
                $this->resolveSubmissionQuantity($submissionItems, $name, $isSinglePaymentItem, $input, $form);
            }
            $formattedSubmissions[$name]['label'] = $label;
            if ($isSinglePaymentItem) {
                $formattedSubmissions[$name]['options'][] = $this->formatSinglePaymentSubmission($name, $input, $submissionItems);
            } else {
                $formattedSubmissions[$name]['options'] = $this->formatOtherSubmissions($name, $input, $submissionItems);
            }
        }
        $vars = [
            'no_found_text'    => __('Sorry! No Inventory record found.', 'fluentformpro'),
            'submissions'      => $formattedSubmissions,
            'inventory_fields' => InventoryFieldsRenderer::getInventoryFields($form, ['simple'])
        ];
        $ffInventoryListVars = apply_filters('fluentform/step_form_entry_vars', $vars, $form);
        wp_localize_script(
            'fluentform_inventory_list',
            'fluentform_inventory_list_vars',
            $ffInventoryListVars
        );
        
        ob_start();
        require(FLUENTFORMPRO_DIR_PATH . 'src/views/inventory_list.php');
        echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin page HTML from template
    }
    
    public function formatSinglePaymentSubmission($name, $input,$submissionItems)
    {
        $quantity = ArrayHelper::get($input,'settings.single_inventory_stock');
        $usedCount = (int)ArrayHelper::get($submissionItems, '0.count', 0);
        $quantity = (int)$quantity;
        $remaining = $quantity - $usedCount;
        return  [
            'key'        => $name,
            'label'      => ArrayHelper::get($input, 'settings.label'),
            'used_count' => $usedCount,
            'quantity'   => $quantity,
            'remaining'  => $this->stockMessage($remaining)
        ];
        
    }

    protected function enqueueScript()
    {
        wp_enqueue_script(
            'fluentform_inventory_list',
            FLUENTFORMPRO_DIR_URL . 'public/js/inventory-list.js',
            ['jquery'],
            FLUENTFORM_VERSION,
            true
        );
    }


    public function setRoutePermission($permissions)
    {
        $permissions['inventory_list'] = 'fluentform_forms_manager';

        return $permissions;
    }
    
    private function getInventoryFields($form, $types = ['simple', 'global'])
    {
        return InventoryFieldsRenderer::getInventoryFields($form, $types);
    }
    
    public function getFormattedItems($submissionItems, $inputItems, $isPaymentItem)
    {
        $mergedArray = [];
        $tempInputItems = $inputItems;
        //for payment items merge with label
        $attribute = $isPaymentItem ? 'label' : 'value';

        foreach ($inputItems as $index => $item) {
            foreach ($submissionItems as $submission) {
                if ($submission["value"] === $item[$attribute]) {
                    $mergedArray[] = array_merge($submission, $item);
                    ArrayHelper::forget($tempInputItems, $index);
                }
            }
        }
        return array_merge($mergedArray, $tempInputItems);
    }
    
    private function formatOtherSubmissions( $name, $input, $submissionItems)
    {
        $isPaymentItem = ArrayHelper::get($input, 'element') == 'multi_payment_component';
        
        if ($isPaymentItem) {
            $inputItems = ArrayHelper::get($input, 'settings.pricing_options');
        } else {
            $rawAdvancedOptions = ArrayHelper::get($input, 'settings.advanced_options');
            $inputItems = method_exists(Helper::class, 'flattenAdvancedOptions')
                ? Helper::flattenAdvancedOptions($rawAdvancedOptions)
                : (array) $rawAdvancedOptions;
        }
        $formattedItems = $this->getFormattedItems($submissionItems, $inputItems, $isPaymentItem);
    
        $items= [];
        foreach ($formattedItems as $item) {
            $usedCount = (int)ArrayHelper::get($item, 'count', 0);
            $quantity = (int)ArrayHelper::get($item, 'quantity');
            $remaining = $quantity - $usedCount;
            $items[] = [
                'key'        => $name,
                'label'      => ArrayHelper::get($item, 'label'),
                'used_count' => $usedCount,
                'quantity'   => $quantity,
                'remaining'  => $this->stockMessage($remaining)
            ];
        }
        return $items;
    }

    private function resolveSubmissionQuantity(&$submissionItems, $name, $isSinglePaymentItem, $input, $form)
    {
        if ($isSinglePaymentItem) {
            $adminLabel = ArrayHelper::get($input, 'settings.admin_field_label');
            $label = $adminLabel ? $adminLabel : ArrayHelper::get($input, 'label', '');
            $price = PaymentHelper::convertToCents(ArrayHelper::get($input, 'attributes.value', 0));
            if (isset($submissionItems[0])) {
                $submissionItems[0]['count'] = InventoryValidation::getPaymentItemSubmissionQuantity($form->id, $name, $label, $price);
            }
        } else {
            $options = ArrayHelper::get($input, 'settings.pricing_options', []);
            foreach ($options as $option) {
                $optionLabel = ArrayHelper::get($option, 'label', '');
                $price = PaymentHelper::convertToCents(ArrayHelper::get($option, 'value', 0));
                $used = InventoryValidation::getPaymentItemSubmissionQuantity($form->id, $name, $optionLabel, $price);
                foreach ($submissionItems as &$item) {
                    if ($optionLabel === $item['value']) {
                        $item['count'] = $used;
                        break;
                    }
                }
            }
        }
    }

    private function stockMessage($remaining) {
        $inStock = __('In Stock', 'fluentformpro');
        $outOfStock = __('Out of Stock', 'fluentformpro');

        return $remaining > 0
            ? sprintf('<span class="text-success">%s</span><span> (%d)</span>', $inStock, $remaining)
            : sprintf('<span class="text-danger">%s</span><span> (%d)</span>', $outOfStock, $remaining);
    }
}
