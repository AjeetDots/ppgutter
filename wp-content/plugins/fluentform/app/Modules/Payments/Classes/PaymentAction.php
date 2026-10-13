<?php

namespace FluentForm\App\Modules\Payments\Classes;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Models\OrderItem;
use FluentForm\App\Models\Submission;
use FluentForm\App\Models\Subscription;
use FluentForm\App\Models\SubmissionMeta;
use FluentForm\App\Models\Transaction;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\ConditionAssesor;
use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Modules\Payments\PaymentHelper;

class PaymentAction
{
    private $form;

    private $data;

    private $submissionData;

    private $submissionId = null;

    private $orderItems = [];

    private $hookedOrderItems = [];

    private $subscriptionItems = [];

    private $quantityItems = [];

    public $selectedPaymentMethod = '';

    public $methodSettings = [];

    protected $paymentInputs = null;

    protected $subscriptionInputs = null;

    protected $currency = null;

    protected $methodField = null;

    protected $discountCodes = [];

    protected $couponField = [];

    private $decodedFormFields = null;

    public function __construct($form, $insertData, $data)
    {
        $this->form = $form;
        $this->data = $data;
        $this->setSubmissionData($insertData);
        $this->setupData();
    }

    private function setSubmissionData($insertData)
    {
        $insertData = (array)$insertData;
        $insertData['response'] = json_decode($insertData['response'], true);
        $this->submissionData = $insertData;
    }

    private function setupData()
    {
        $formFields = FormFieldsParser::getPaymentFields($this->form, ['admin_label', 'attributes', 'settings']);

        $paymentInputElements = ['custom_payment_component', 'multi_payment_component'];
        $quantityItems = [];
        $paymentInputs = [];
        $subscriptionInputs = [];
        $paymentMethod = false;
        $couponField = false;
        foreach ($formFields as $fieldKey => $field) {
            $element = ArrayHelper::get($field, 'element');
            if (in_array($element, $paymentInputElements)) {
                $paymentInputs[$fieldKey] = $field;
            } else if ($element == 'item_quantity_component' || $element == 'rangeslider') {
                if ('rangeslider' == $element && 'yes' != ArrayHelper::get($field, 'settings.enable_target_product')) {
                    continue;
                }
                if ($targetProductName = ArrayHelper::get($field, 'settings.target_product')) {
                    $quantityItems[$targetProductName] = [
                        'name'  => ArrayHelper::get($field, 'attributes.name'),
                        'field' => $field,
                    ];
                }
            } else if ($element == 'payment_method') {
                $paymentMethod = $field;
            } else if ($element == 'payment_coupon' && Helper::hasPro()) {
                $couponField = $field;
            } else if ($element === 'subscription_payment_component') {
                $subscriptionInputs[$fieldKey] = $field;
            }
        }

        $this->paymentInputs = $paymentInputs;
        $this->quantityItems = $quantityItems;
        $this->subscriptionInputs = $subscriptionInputs;

        if ($paymentMethod) {
            $this->methodField = $paymentMethod;
            if ($this->isConditionPass()) {
                $methodName = ArrayHelper::get($paymentMethod, 'attributes.name');
                $this->selectedPaymentMethod = ArrayHelper::get($this->data, $methodName);
                $this->methodSettings = ArrayHelper::get($paymentMethod, 'settings.payment_methods.' . $this->selectedPaymentMethod);
            }
        }

        if ($couponField && $this->isCouponFieldVisible($couponField)) {
            $couponCodes = ArrayHelper::get($this->data, '__ff_all_applied_coupons', '');
            if ($couponCodes) {
                $couponCodes = \json_decode($couponCodes, true);
                if ($couponCodes && class_exists('FluentFormPro\Payments\Classes\CouponModel')) {
                    $couponCodes = array_unique($couponCodes);
                    $this->discountCodes = (new \FluentFormPro\Payments\Classes\CouponModel())->getCouponsByCodes($couponCodes);
                    $this->couponField = $couponField;
                }
            }
        }

        if ($this->subscriptionInputs) {
            // Maybe we have subscription items with bill times = 1
            // Or if we have discount codes then we have to apply the discount codes
            $this->validateSubscriptionInputs();
        }

        $this->applyDiscountCodes();
    }

    public function isConditionPass()
    {
        $conditionSettings = ArrayHelper::get($this->methodField, 'settings.conditional_logics', []);
        if (
            !$conditionSettings ||
            !ArrayHelper::isTrue($conditionSettings, 'status')
        ) {
            return true;
        }

        $conditionFeed = ['conditionals' => $conditionSettings];
        return ConditionAssesor::evaluate($conditionFeed, $this->data);
    }

    public function isFieldConditionPass($field)
    {
        $conditionSettings = ArrayHelper::get($field, 'settings.conditional_logics', []);
        if (
            !$conditionSettings ||
            !ArrayHelper::isTrue($conditionSettings, 'status')
        ) {
            return true;
        }

        $conditionFeed = ['conditionals' => $conditionSettings];
        return ConditionAssesor::evaluate($conditionFeed, $this->data);
    }

    /**
     * Visible only when the coupon field's own conditions and every ancestor
     * container's conditions pass — so a coupon inside a hidden container is
     * not honored.
     */
    public function isCouponFieldVisible($couponField)
    {
        return $this->isFieldVisible($couponField);
    }

    /**
     * Whether a field is actually shown to the submitter: its own conditional
     * logic has to pass, and so does that of every container it sits inside.
     */
    protected function isFieldVisible($field)
    {
        if (!$this->isFieldConditionPass($field)) {
            return false;
        }

        $fieldName = ArrayHelper::get($field, 'attributes.name');
        $ancestors = $this->getFieldAncestorContainers($this->getDecodedFormFields(), $fieldName);

        foreach ((array) $ancestors as $container) {
            if (!$this->isFieldConditionPass($container)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The form definition does not change during a request, but this is now
     * consulted once per payment input and per subscription input, and
     * `applyDiscountCodes()` forces a full recompute -- so decoding it each time
     * meant re-parsing the whole form many times over on a multi-item order.
     */
    private function getDecodedFormFields()
    {
        if (is_null($this->decodedFormFields)) {
            $formFields = $this->form->form_fields;
            if (is_string($formFields)) {
                $formFields = json_decode($formFields, true);
            }
            $this->decodedFormFields = ArrayHelper::get($formFields, 'fields', []);
        }

        return $this->decodedFormFields;
    }

    /**
     * Which plan a subscription input is for.
     *
     * A submitted choice always wins, including an empty one. When the key never
     * arrived at all, the plan is inferred only from a plan the form actually
     * renders pre-selected -- which is exactly `is_default`, and nothing else:
     * a select leads with a blank "--Select Plan--" option and a radio group
     * starts unchecked, so on any other form not choosing is a real answer.
     * Where nothing is pre-selected the input is skipped rather than guessed at.
     *
     * @return string|int|null the plan key, or null when there is none to use
     */
    private function resolvePlanKey($subscriptionInput, $subscriptionOptions)
    {
        if (!$subscriptionOptions) {
            return null;
        }

        $name = ArrayHelper::get($subscriptionInput, 'attributes.name');
        $data = $this->submissionData['response'];

        // An empty submitted value is an answer, not a missing one. A select
        // renders a blank "--Select Plan--" placeholder first and the field is
        // optional by default, so choosing it is a genuine "no subscription".
        // Only a key that never arrived at all can be inferred.
        if (isset($data[$name])) {
            if ('' === $data[$name]) {
                return null;
            }

            return isset($subscriptionOptions[$data[$name]]) ? $data[$name] : null;
        }

        if (!$this->isFieldVisible($subscriptionInput)) {
            return null;
        }

        // Only a plan the form renders pre-selected may be inferred. The renderer
        // sets checked/selected solely for is_default and skips expired-hidden plans,
        // so any other plan is a choice the submitter still had to make -- and not
        // making it is legitimate, not tampering. Definitions carry no single-default
        // constraint, so a stale/imported form can mark several defaults or leave the
        // first default expired-hidden. Collect every inferable default and infer only
        // when exactly one remains; zero or many is ambiguous and needs an explicit
        // choice, so it resolves to no subscription rather than the wrong plan.
        $inferableDefaults = [];
        foreach ($subscriptionOptions as $planKey => $plan) {
            if ('yes' === ArrayHelper::get($plan, 'is_default') && $this->isInferablePlan($plan)) {
                $inferableDefaults[] = $planKey;
            }
        }

        return 1 === count($inferableDefaults) ? $inferableDefaults[0] : null;
    }

    /**
     * A plan may only be inferred when the form already knows what it costs and
     * still offers it.
     *
     * A "name your price" plan takes its amount from a companion input, so with
     * nothing submitted there is no amount to charge -- and inferring the plan
     * anyway would create a subscription at 0. Trial days make that worse: the
     * zero-amount guard further down is skipped while a trial is configured, so
     * the result would be a free perpetual subscription.
     *
     * An expired plan set to hide is never rendered at all, so its absence is
     * the admin closing sales, not a dropped input.
     */
    private function isInferablePlan($plan)
    {
        if ('yes' === ArrayHelper::get($plan, 'user_input')) {
            return false;
        }

        return !PaymentHelper::isPlanExpiredAndHidden($plan);
    }

    /**
     * Whether the server already knows this item's price, i.e. the request only
     * ever echoed back a value taken from the form definition.
     *
     * Those items must not be skippable by leaving the input out of the request,
     * because the submitter never supplied the amount in the first place. Items
     * the submitter genuinely chooses -- an option, a typed amount, a dynamic
     * default -- are excluded, so leaving those out stays a legitimate
     * zero-total submission.
     */
    private function isServerPricedItem($paymentInput, $inputType)
    {
        if ('single' !== $inputType) {
            return false;
        }

        if (ArrayHelper::get($paymentInput, 'settings.dynamic_default_value')) {
            return false;
        }

        $price = ArrayHelper::get($paymentInput, 'attributes.value');
        if (!is_numeric($price) || !$price) {
            return false;
        }

        if (
            'yes' === ArrayHelper::get($paymentInput, 'settings.hide_input_when_stockout')
            && $this->proCannotJudgeHiddenStock()
        ) {
            return false;
        }

        if (!$this->isFieldVisible($paymentInput)) {
            return false;
        }

        // Lets an add-on that removes an input at render time -- for reasons the
        // stored definition cannot express -- keep it out of the order too.
        return (bool) apply_filters(
            'fluentform/is_server_priced_payment_item',
            true,
            $paymentInput,
            $this->form
        );
    }

    /**
     * Pro before its renderer-count veto hides a sold-out item at render but judges
     * stock from a different count on submit, so an omitted hidden item can still read
     * as in stock and be charged. Until that Pro is updated its sites stay on the
     * presence check for the hide flag: the fail-open that leaves is the one they
     * already have, and charging a buyer for an item they never saw is worse.
     */
    protected function proCannotJudgeHiddenStock()
    {
        return defined('FLUENTFORMPRO')
            && !method_exists('\FluentFormPro\classes\Inventory\InventoryValidation', 'getRenderedEntryReport');
    }

    /**
     * Return the ancestor container fields wrapping $targetName (containers nest
     * children under columns[].fields[]), or null if not found in this branch.
     */
    public function getFieldAncestorContainers($fields, $targetName, $ancestors = [])
    {
        foreach ($fields as $field) {
            if (ArrayHelper::get($field, 'attributes.name') === $targetName) {
                return $ancestors;
            }
            foreach (ArrayHelper::get($field, 'columns', []) as $column) {
                $found = $this->getFieldAncestorContainers(
                    ArrayHelper::get($column, 'fields', []),
                    $targetName,
                    array_merge($ancestors, [$field])
                );
                if (!is_null($found)) {
                    return $found;
                }
            }
        }

        return null;
    }

    public function draftFormEntry()
    {
        // Record Payment Items
        $subscriptionItems = $this->getSubscriptionItems();

        if (count($subscriptionItems) >= 2) {
            // We are not supporting multiple subscription items at this moment
            wp_send_json_error([
                'message' => __('Sorry, multiple subscription item is not supported', 'fluentform')
            ]);
        }

        $items = $this->getOrderItems();

        $existingSubmission = $this->checkForExistingSubmission();

        if (is_wp_error($existingSubmission)) {
            wp_send_json([
                'errors' => __('This payment is already complete or still processing. Please wait for confirmation.', 'fluentform'),
                'append_data' => [
                    '__entry_intermediate_hash' => ArrayHelper::get($this->submissionData, 'response.__entry_intermediate_hash')
                ]
            ], 423);
        }

        $formSettings = PaymentHelper::getFormSettings($this->form->id, 'public');
        $submission = $this->submissionData;
        $submission['payment_status'] = 'pending';
        $submission['payment_method'] = $this->selectedPaymentMethod;
        $submission['payment_type'] = $this->getPaymentType();
        $submission['currency'] = $formSettings['currency'];
        $submission['response'] = json_encode($submission['response']);
        $submission['payment_total'] = $this->getCalculatedAmount();
        $submission = apply_filters_deprecated(
            'fluentform_with_payment_submission_data',
            [
                $submission,
                $this->form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_submission_data',
            'Use fluentform/payment_submission_data instead of fluentform_with_payment_submission_data.'
        );
        $submission = apply_filters('fluentform/payment_submission_data', $submission, $this->form);

        if ($existingSubmission) {
            $insertId = $this->updateExistingSubmission($existingSubmission, $submission);
        } else {
            $insertId = Submission::create($submission)->id;
            $uidHash = md5(wp_generate_uuid4() . $insertId);
            Helper::setSubmissionMeta($insertId, '_entry_uid_hash', $uidHash, $this->form->id);
            $intermediatePaymentHash = md5('payment_' . wp_generate_uuid4() . '_' . $insertId . '_' . $this->form->id);
            Helper::setSubmissionMeta($insertId, '__entry_intermediate_hash', $intermediatePaymentHash, $this->form->id);
        }

        $submission['id'] = $insertId;
        $this->setSubmissionData($submission);
        $this->submissionId = $insertId;


        $paymentTotal = 0;
        if ($items) {
            foreach ($items as $index => $item) {
                if ($item['type'] == 'discount') {
                    $paymentTotal -= $item['line_total'];
                } else {
                    $paymentTotal += $item['line_total'];
                }
                $items[$index]['submission_id'] = $insertId;
                $items[$index]['form_id'] = $submission['form_id'];
            }
        }

        $this->insertOrderItems($items, $existingSubmission);

        $subsTotal = 0;
        if ($subscriptionItems && $existingSubmission) {
            Subscription::where('submission_id', $existingSubmission->id)->delete();
        }

        foreach ($subscriptionItems as $subscriptionItem) {
            $quantity = isset($subscriptionItem['quantity']) ? $subscriptionItem['quantity'] : 1;
            $linePrice = $subscriptionItem['recurring_amount'] * $quantity;
            $subsTotal += intval($linePrice);
            $subscriptionItem['submission_id'] = $insertId;
            Subscription::create($subscriptionItem);
        }

        do_action('fluentform/notify_on_form_submit', $this->submissionId, $this->submissionData['response'], $this->form);

        $totalPayable = $paymentTotal + $subsTotal;

        // We should make a transaction for subscription

        if ($this->selectedPaymentMethod) {
            Helper::setSubmissionMeta($insertId, '_selected_payment_method', $this->selectedPaymentMethod);
            do_action_deprecated(
                'fluentform_process_payment',
                [
                    $this->submissionId,
                    $this->submissionData,
                    $this->form,
                    $this->methodSettings,
                    !!$subscriptionItems,
                    $totalPayable
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/process_payment',
                'Use fluentform/process_payment instead of fluentform_process_payment.'
            );
            do_action('fluentform/process_payment', $this->submissionId, $this->submissionData, $this->form, $this->methodSettings, !!$subscriptionItems, $totalPayable);

            do_action_deprecated(
                'fluentform_process_payment_' . $this->selectedPaymentMethod,
                [
                    $this->submissionId,
                    $this->submissionData,
                    $this->form,
                    $this->methodSettings,
                    !!$subscriptionItems,
                    $totalPayable
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/process_payment_' . $this->selectedPaymentMethod,
                'Use fluentform/process_payment_' . $this->selectedPaymentMethod . ' instead of fluentform_process_payment_' . $this->selectedPaymentMethod
            );
            do_action('fluentform/process_payment_' . $this->selectedPaymentMethod, $this->submissionId, $this->submissionData, $this->form, $this->methodSettings, !!$subscriptionItems, $totalPayable);
        }

        /*
         * The following code will run only if no payment method catch and process the payment
         * In the payment method, ideally they will send the response. But if no payment method exist then
         * we will handle here
         */
        $submission = Submission::find($insertId);
    
        $returnData = (new SubmissionHandlerService())->processSubmissionData(
            $submission->id, $this->submissionData['response'], $this->form
        );

        wp_send_json_success($returnData, 200);
    }

    public function getOrderItems($forced = false)
    {
        if ($forced) {
            $this->orderItems = [];
        }

        if ($this->orderItems) {
            return $this->orderItems;
        }

        $paymentInputs = $this->paymentInputs;

        if (!$paymentInputs && !$this->hookedOrderItems) {
            return [];
        }

        $data = $this->submissionData['response'];

        foreach ($paymentInputs as $paymentInput) {
            $name = ArrayHelper::get($paymentInput, 'attributes.name');
            if (!$name) {
                continue;
            }
            $price = 0;
            $inputType = ArrayHelper::get($paymentInput, 'attributes.type');

            // A server-priced item is resolved from the form definition, so an
            // absent or falsy request value must not drop it -- otherwise an
            // unauthenticated submitter zeroes the order simply by omitting the
            // input. Every other item still needs a submitted value.
            if (!$this->isServerPricedItem($paymentInput, $inputType)) {
                if (!isset($data[$name]) || !$data[$name]) {
                    continue;
                }
            }

            if ($inputType == 'number') {
                $price = $data[$name];
            } else if ($inputType == 'single') {
                $price = ArrayHelper::get($paymentInput, 'attributes.value');
                if (ArrayHelper::get($paymentInput, 'settings.dynamic_default_value')) {
                    $price = $data[$name];
                }
            } else if ($inputType == 'radio' || $inputType == 'select') {
                $item = $this->getItemFromVariables($paymentInput, $data[$name]);
                if ($item) {
                    $quantity = $this->getQuantity($item['parent_holder']);
                    if (!$quantity) {
                        continue;
                    }
                    $item['quantity'] = $quantity;
                    $this->pushItem($item);
                }
                continue;
            } else if (ArrayHelper::get($paymentInput, 'attributes.type') == 'checkbox') {
                $selectedItems = $data[$name];
                foreach ($selectedItems as $selectedItem) {
                    $item = $this->getItemFromVariables($paymentInput, $selectedItem);
                    if ($item) {
                        $quantity = $this->getQuantity($item['parent_holder']);
                        if (!$quantity) {
                            continue;
                        }
                        $item['quantity'] = $quantity;
                        $this->pushItem($item);
                    }
                }
                continue;
            }

            if (!is_numeric($price) || !$price) {
                continue;
            }

            $productName = ArrayHelper::get($paymentInput, 'attributes.name');
            $quantity = $this->getQuantity($productName);
            if (!$quantity) {
                continue;
            }

            $this->pushItem([
                'parent_holder' => $productName,
                'item_name'     => ArrayHelper::get($paymentInput, 'admin_label'),
                'item_price'    => $price,
                'quantity'      => $quantity
            ]);
        }

        // We may have initial amount from the subscription
        if ($this->hookedOrderItems) {
            $this->orderItems = array_merge($this->orderItems, $this->hookedOrderItems);
        }
    
        $this->orderItems = apply_filters_deprecated(
            'fluentform_submission_order_items',
            [
                $this->orderItems,
                $this->submissionData,
                $this->form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/submission_order_items',
            'Use fluentform/submission_order_items instead of fluentform_submission_order_items.'
        );

        $this->orderItems = apply_filters('fluentform/submission_order_items', $this->orderItems, $this->submissionData, $this->form, $this->selectedPaymentMethod);

        return $this->orderItems;
    }

    private function getQuantity($productName)
    {
        $quantity = 1;
        if (!$this->quantityItems) {
            return $quantity;
        }
        if (!isset($this->quantityItems[$productName])) {
            return $quantity;
        }
        $quantityField = $this->quantityItems[$productName]['field'];
        $inputName = $this->quantityItems[$productName]['name'];
        $data = $this->submissionData['response'];

        // An absent input is either hidden by conditional logic or stripped to zero
        // the order; only the field's own visibility separates the two. A submitted
        // 0 or blank box still means none.
        if (!isset($data[$inputName])) {
            return $this->isFieldVisible($quantityField) ? 1 : 0;
        }

        $quantity = ArrayHelper::get($data, $inputName);
        if (!$quantity) {
            return 0;
        }
        // SECURITY (FINDING-22): clamp a user-supplied quantity to a non-negative integer so a
        // negative quantity cannot flip a line total and subtract from the order.
        return max(0, intval($quantity));
    }

    private function pushItem($data)
    {
        // SECURITY (FINDING-22): reject non-positive prices. A user-controlled "name your price"
        // / donation amount (or a dynamic-default numeric field) is otherwise taken verbatim, and
        // a negative value subtracts from the order total — forcing it to exactly 0 makes
        // maybeHandlePayment() skip the gateway entirely, yielding a free fulfilled order.
        if (!is_numeric($data['item_price']) || floatval($data['item_price']) <= 0) {
            return;
        }
        $data['item_price'] = floatval($data['item_price'] * 100);

        $defaults = [
            'type'       => 'single',
            'form_id'    => $this->form->id,
            'quantity'   => !empty($data['quantity']) ? $data['quantity'] : 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $item = wp_parse_args($data, $defaults);

        $item['line_total'] = $item['item_price'] * $item['quantity'];

        if (!$this->orderItems) {
            $this->orderItems = [];
        }

        $this->orderItems[] = $item;
    }

    private function getItemFromVariables($item, $key)
    {
        $elementName = $item['element'];
        $pricingOptions = ArrayHelper::get($item, 'settings.pricing_options');
        $pricingOptions = apply_filters_deprecated(
            'fluentform_payment_field_' . $elementName . '_pricing_options',
            [
                $pricingOptions,
                $item,
                $this->form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_field_' . $elementName . '_pricing_options',
            'Use fluentform/payment_field_' . $elementName . '_pricing_options instead of fluentform_payment_field_' . $elementName . '_pricing_options.'
        );
        $pricingOptions = apply_filters('fluentform/payment_field_' . $elementName . '_pricing_options', $pricingOptions, $item, $this->form);

        $selectedOption = [];
        foreach ($pricingOptions as $priceOption) {
            $label = sanitize_text_field($priceOption['label']);
            $value = sanitize_text_field($priceOption['value']);
            if ($label == $key || $value == $key) {
                $selectedOption = $priceOption;
            }
        }

        if (!$selectedOption || empty($selectedOption['value']) || !is_numeric($selectedOption['value'])) {
            return false;
        }

        return [
            'parent_holder' => ArrayHelper::get($item, 'attributes.name'),
            'item_name'     => $selectedOption['label'],
            'item_price'    => $selectedOption['value']
        ];
    }

    public function getCalculatedAmount()
    {
        $items = $this->getOrderItems();

        $total = 0;
        foreach ($items as $item) {
            if ($item['type'] == 'discount') {
                $total -= $item['line_total'];
            } else {
                $total += $item['line_total'];
            }
        }
        return $total;
    }

    // A $0 order is a completed free order when a real priced product was zeroed by a
    // server-validated discount (both a non-discount and a discount line are present;
    // discount lines come solely from getValidCoupons), or when the visitor chose one of
    // the form's own $0 options. Otherwise the $0 total is an omitted or zeroed input.
    public function isZeroTotalFreeOrder()
    {
        $hasProduct = $hasDiscount = false;
        foreach ($this->getOrderItems() as $item) {
            if (ArrayHelper::get($item, 'type') === 'discount') {
                $hasDiscount = true;
            } else {
                $hasProduct = true;
            }
        }
        return ($hasProduct && $hasDiscount) || $this->hasSelectedFreeOption();
    }

    // Validation rejects any option the form does not offer, so a selected $0 option is the site's own.
    protected function hasSelectedFreeOption()
    {
        $data = (array) ArrayHelper::get($this->submissionData, 'response');
        foreach ((array) $this->paymentInputs as $input) {
            $selected = (array) ArrayHelper::get($data, ArrayHelper::get($input, 'attributes.name'), []);
            if (!$selected || !$this->isFieldVisible($input)) {
                continue;
            }
            foreach (ArrayHelper::get($input, 'settings.pricing_options', []) as $option) {
                if (in_array(sanitize_text_field($option['label']), $selected) && !(float) $option['value']) {
                    return true;
                }
            }
        }
        return false;
    }

    // The entry is not inserted yet; stamp a free order the moment it is, before any
    // payment-success consumer runs. An absent flag means an empty order.
    public function flagZeroTotalOrder()
    {
        if (!$this->isZeroTotalFreeOrder()) {
            return;
        }

        add_action('fluentform/notify_on_form_submit', function ($insertId, $formData, $form) {
            Helper::setSubmissionMeta($insertId, '_ff_zero_total_free_order', 'yes', $form->id);
        }, 1, 3);
    }

    public function getPaymentType()
    {
        return count($this->getSubscriptionItems()) ? 'subscription' : 'product'; // return value product|subscription|donation
    }

    private function getCurrency()
    {
        if ($this->currency !== null) {
            return $this->currency;
        }
        $this->currency = 'usd';

        return $this->currency;
    }

    public function getSubscriptionItems()
    {
        if ($this->subscriptionItems) {
            return $this->subscriptionItems;
        }

        $data = $this->submissionData['response'];
        $subscriptionInputs = $this->subscriptionInputs;

        if (!$subscriptionInputs) {
            return [];
        }

        foreach ($subscriptionInputs as $subscriptionInput) {
            $name = ArrayHelper::get($subscriptionInput, 'attributes.name');
            $quantity = $this->getQuantity($name);

            if (!$name || $quantity === 0) {
                continue;
            }

            $label = ArrayHelper::get($subscriptionInput, 'settings.label', $name);

            $subscriptionOptions = ArrayHelper::get($subscriptionInput, 'settings.subscription_options');

            $planKey = $this->resolvePlanKey($subscriptionInput, $subscriptionOptions);

            if (is_null($planKey)) {
                continue;
            }

            $plan = ArrayHelper::get($subscriptionOptions, $planKey);

            if (!$plan) {
                continue;
            }

            if (ArrayHelper::get($plan, 'user_input') === 'yes') {
                $plan['subscription_amount'] = $this->getCustomSubscriptionAmount($data, $name, $planKey);
            }

            $noTrial = ArrayHelper::get($plan, 'has_trial_days') === 'no' ||
                       !ArrayHelper::get($plan, 'trial_days');
                       
            if (!$plan['subscription_amount'] && $noTrial) {
                continue;
            }

            if (ArrayHelper::get($plan, 'bill_times') == 1 && ArrayHelper::get($plan, 'has_trial_days') != 'yes') {
                // Since the billing times is 1 and no trial days,
                // the subscription acts like as an one time payment.
                // We'll convert this as a payment item.
                $signupFee = 0;

                if ($plan['has_signup_fee'] === 'yes') {
                    $signupFee = PaymentHelper::convertToCents($plan['signup_fee']);
                }

                $onetimeTotal = $signupFee + PaymentHelper::convertToCents($plan['subscription_amount']);

                $this->pushItem([
                    'parent_holder' => $name,
                    'item_name'     => $label,
                    'quantity'      => $quantity,
                    'item_price'    => $onetimeTotal,
                    'line_total'    => $quantity * $onetimeTotal,
                    'created_at'    => current_time('mysql'),
                    'updated_at'    => current_time('mysql')
                ]);
            } else {
                $billTimes = (isset($plan['bill_times'])) ? $plan['bill_times'] : 0;

                // If end date is set, dynamically calculate bill_times from today
                if (
                    ArrayHelper::get($plan, 'has_end_date') === 'yes'
                    && ($endDateStr = ArrayHelper::get($plan, 'subscription_end_date'))
                ) {
                    $endDate = strtotime($endDateStr . ' +1 day');
                    $now = current_time('timestamp');
                    if (!$endDate || $endDate <= $now) {
                        if (ArrayHelper::get($plan, 'expire_behavior') === 'hide') {
                            continue;
                        }
                        wp_send_json([
                            'errors' => [__('This subscription plan was expired', 'fluentform')]
                        ], 423);
                    }
                    $diffDays = max(1, ceil(($endDate - $now) / 86400));
                    $intervalMap = ['day' => 1, 'week' => 7, 'month' => 30, 'year' => 365];
                    $interval = isset($intervalMap[$plan['billing_interval']]) ? $intervalMap[$plan['billing_interval']] : 30;
                    $billTimes = max(1, ceil($diffDays / $interval));
                }

                $subscription = array(
                    'element_id'       => $name,
                    'item_name'        => $label,
                    'form_id'          => $this->form->id,
                    'plan_name'        => $plan['name'],
                    'billing_interval' => $plan['billing_interval'],
                    'trial_days'       => 0,
                    'recurring_amount' => PaymentHelper::convertToCents($plan['subscription_amount']),
                    'bill_times'       => $billTimes,
                    'initial_amount'   => 0,
                    'status'           => 'pending',
                    'original_plan'    => maybe_serialize($plan),
                    'created_at'       => current_time('mysql'),
                    'updated_at'       => current_time('mysql'),
                );

                if (ArrayHelper::get($plan, 'has_signup_fee') === 'yes' && ArrayHelper::get($plan, 'signup_fee')) {
                    $subscription['initial_amount'] = PaymentHelper::convertToCents($plan['signup_fee']);
                }

                if (ArrayHelper::get($plan, 'has_trial_days') === 'yes' && ArrayHelper::get($plan, 'trial_days')) {
                    $subscription['trial_days'] = $plan['trial_days'];
                    $dateTime = current_datetime();
                    $localtime = $dateTime->getTimestamp() + $dateTime->getOffset();
                    $expirationDate = date('Y-m-d H:i:s', $localtime + absint($plan['trial_days']) * 86400);
                    $subscription['expiration_at'] = $expirationDate;
                }

                if ($quantity > 1) {
                    $subscription['quantity'] = $quantity;
                }

                $this->subscriptionItems[] = $subscription;
            }
        }
        $this->subscriptionItems = apply_filters_deprecated(
            'fluentform_submission_subscription_items',
            [
                $this->subscriptionItems,
                $this->submissionData,
                $this->form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/submission_subscription_items',
            'Use fluentform/submission_subscription_items instead of fluentform_submission_subscription_items.'
        );
        $this->subscriptionItems = apply_filters('fluentform/submission_subscription_items', $this->subscriptionItems, $this->submissionData, $this->form);

        return $this->subscriptionItems;
    }

    private function checkForExistingSubmission()
    {
        $entryUid = ArrayHelper::get($this->submissionData, 'response.__entry_intermediate_hash');

        if (!$entryUid) {
            return false;
        }

        $meta = SubmissionMeta::where('meta_key', '__entry_intermediate_hash')
            ->where('value', $entryUid)
            ->where('form_id', $this->form->id)
            ->first();

        if (!$meta) {
            return false;
        }

        $submission = Submission::find($meta->response_id);

        if ($submission && ($submission->payment_status == 'failed' || $submission->payment_status == 'pending' || $submission->payment_status == 'draft')) {
            // A settled charge means the earlier attempt went through after the error
            // was shown; reusing the row would delete that ledger entry.
            $hasPaidOrProcessingCharge = Transaction::where('submission_id', $submission->id)
                ->whereIn('status', ['paid', 'processing'])
                ->exists();
            if ($hasPaidOrProcessingCharge) {
                return new \WP_Error('payment_retry_blocked');
            }

            return $submission;
        }

        return false;
    }

    /**
     * Update a retry in place while retaining its transaction rows for
     * processor reuse and delayed webhook settlement.
     */
    private function updateExistingSubmission($existingSubmission, $submission)
    {
        $submissionId = $existingSubmission->id;
        Submission::where('id', $submissionId)->update($submission);
        Transaction::where('submission_id', $submissionId)
            ->where('status', 'failed')
            ->delete();

        return $submissionId;
    }

    private function insertOrderItems($items, $existing = false)
    {
        if (!$existing) {
            foreach ($items as $item) {
                OrderItem::create($item);
            }
            return true;
        }

        if (!$items && $existing) {
            OrderItem::where('submission_id', $existing->id)->delete();
            return true;
        }

        $exitingItems = OrderItem::where('submission_id', $existing->id)->get();

        if (!$exitingItems || count($exitingItems) === 0) {
            foreach ($items as $item) {
                OrderItem::create($item);
            }
            return true;
        }

        $existingHashes = [];
        foreach ($exitingItems as $exitingItem) {
            $hash = md5($exitingItem->type . ':' . $exitingItem->parent_holder . ':' . $exitingItem->item_name . ':' . $exitingItem->quantity . ':' . $exitingItem->item_price);
            $existingHashes[$exitingItem->id] = $hash;
        }

        $verifiedIds = [];
        $newIds = [];
        foreach ($items as $item) {
            $hash = md5($item['type'] . ':' . $item['parent_holder'] . ':' . $item['item_name'] . ':' . $item['quantity'] . ':' . $item['item_price']);
            if (in_array($hash, $existingHashes)) {
                // already exist no need to add
                $verifiedIds[] = array_search($hash, $existingHashes);
            } else {
                $newId = OrderItem::create($item)->id;
                $verifiedIds[] = $newId;
                $newIds[] = $newId;
            }
        }

        if ($verifiedIds) {
            // SECURITY (PRO-06): scope this stale-item cleanup to the current submission; the
            // unscoped whereNotIn deleted every other submission's order_items site-wide (and
            // fired on ordinary payment retries — a live data-loss bug).
            OrderItem::where('submission_id', $existing->id)
                ->whereNotIn('id', $verifiedIds)
                ->delete();
        }

        return true;
    }

    private function getCustomSubscriptionAmount($data, $name, $planKey)
    {
        $amount = ArrayHelper::get($data, $name . '_custom_' . $planKey) ?: 0;

        // Past PHP_INT_MAX cents, convertToCents() returns 0 or wraps negative, which drops the plan and leaves the order unpaid.
        if (abs((float) $amount) >= PHP_INT_MAX / 100) {
            wp_send_json([
                'errors' => [__('This subscription plan value is invalid', 'fluentform')]
            ], 423);
        }

        return $amount;
    }

    private function validateSubscriptionInputs()
    {
        $subscriptionInputs = $this->subscriptionInputs;
        if (!$subscriptionInputs) {
            return;
        }
        $data = $this->submissionData['response'];

        $discountCodes = $this->discountCodes;

        foreach ($subscriptionInputs as $inputIndex => $subscriptionInput) {
            $name = ArrayHelper::get($subscriptionInput, 'attributes.name');
            $quantity = $this->getQuantity($name);

            if (!$quantity) {
                continue;
            }

            if (!$name) {
                continue;
            }

            $subscriptionOptions = ArrayHelper::get($subscriptionInput, 'settings.subscription_options');

            $planKey = $this->resolvePlanKey($subscriptionInput, $subscriptionOptions);

            if (is_null($planKey)) {
                continue;
            }

            $plan = ArrayHelper::get($subscriptionOptions, $planKey);

            if (!$plan) {
                continue;
            }

            if ($discountCodes) {

            }

            if (ArrayHelper::get($plan, 'has_trial_days') == 'yes' && ArrayHelper::get($plan, 'trial_days')) {
                continue; // this is a valid subscription
            }

            if (ArrayHelper::get($plan, 'bill_times') != 1) {
                continue;
            }

            // We have bill times 1 so we have to remove this and push to  hooked inputs and later merged to payment inputs

            if (ArrayHelper::get($plan, 'user_input') === 'yes') {
                $plan['subscription_amount'] = $this->getCustomSubscriptionAmount($data, $name, $planKey);
            }

            $amount = PaymentHelper::convertToCents($plan['subscription_amount']);

            // Bypasses pushItem()'s positive-price guard, so a negative custom amount would
            // otherwise become a line item that drags the order total down. Checked before
            // the signup fee so the fee cannot mask it.
            if ($amount < 0) {
                continue;
            }

            if (ArrayHelper::get($plan, 'has_signup_fee') === 'yes' && ArrayHelper::get($plan, 'signup_fee')) {
                $amount += PaymentHelper::convertToCents($plan['signup_fee']);
            }

            $this->hookedOrderItems[] = [
                'type'          => 'single',
                'form_id'       => $this->form->id,
                'parent_holder' => $name,
                'item_name'     => ArrayHelper::get($subscriptionInput, 'admin_label') . ' (' . $plan['name'] . ')',
                'item_price'    => $amount,
                'quantity'      => $quantity,
                'line_total'    => $quantity * $amount,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql'),
            ];

            unset($this->subscriptionInputs[$inputIndex]);
        }

    }

    protected function applyDiscountCodes()
    {
        if (!$this->discountCodes) {
            return false;
        }

        $orderItems = $this->getOrderItems(true);


        $subTotal = array_sum(array_column($orderItems, 'line_total')) / 100;

        $subscriptionItems = $this->getSubscriptionItems();

        $subInitialTotal = 0;
        foreach ($subscriptionItems as $subscriptionItem) {
            if ($subscriptionItem['trial_days']) {
                continue; // it's a trial
            }
            $subInitialTotal += $subscriptionItem['recurring_amount'] + $subscriptionItem['initial_amount'];
        }

        $grandTotal = $subTotal;
        if ($subInitialTotal) {
            $grandTotal += ($subInitialTotal / 100);
        }

        $fixedAmountApplied = 0; // in cents

        if (Helper::hasPro() && class_exists('FluentFormPro\Payments\Classes\CouponModel')) {
            $couponModel = new \FluentFormPro\Payments\Classes\CouponModel();
            $this->discountCodes = $couponModel->getValidCoupons($this->discountCodes, $this->form->id, $grandTotal);
        } else {
            $this->discountCodes = [];
        }

        foreach ($this->discountCodes as $coupon) {
            $discountAmount = $coupon->amount;
            if ($coupon->coupon_type == 'percent') {
                $discountAmount = (floatval($coupon->amount) / 100) * $subTotal;
            } else {
                if ($subTotal >= $discountAmount) {
                    $fixedAmountApplied += $discountAmount;
                } else {
                    $discountAmount = $subTotal;
                    $fixedAmountApplied += $subTotal;
                }
            }

            $this->pushItem([
                'parent_holder' => ArrayHelper::get($this->couponField, 'attributes.name'),
                'item_name'     => $coupon->title,
                'item_price'    => $discountAmount, // this is not cent. We convert to cent at pushItem method
                'quantity'      => 1,
                'type'          => 'discount'
            ]);

            $subTotal = $subTotal - $discountAmount;
        }


        // let's convert to cents now as all subscriptions calculations are on cents
        $fixedAmountApplied = intval($fixedAmountApplied * 100);

        if (!$subscriptionItems) {
            return true;
        }

        $fixedMaxTotal = 0;
        $hasFixedDiscounts = false;
        foreach ($this->discountCodes as $discountCode) {
            if($discountCode->coupon_type == 'fixed') {
                $fixedMaxTotal += intval($coupon->amount * 100);
                $hasFixedDiscounts = true;
            }
        }

        $fixedMaxTotal = $fixedMaxTotal - $fixedAmountApplied;

        foreach ($subscriptionItems as $subIndex => $subscriptionItem) {
            $recurringAmount = $subscriptionItem['recurring_amount'];
            $signupFee = 0;
            if ($subscriptionItem['initial_amount']) {
                $signupFee = $subscriptionItem['initial_amount'];
            }
            // Let's process the percentile discounts first
            foreach ($this->discountCodes as $coupon) {
                $discountAmount = $coupon->amount;
                if ($coupon->coupon_type == 'percent') {
                    $discountRecurringAmount = floatval((floatval($discountAmount) / 100) * $recurringAmount);
                    $recurringAmount -= $discountRecurringAmount;
                    if ($signupFee) {
                        $discountSignupDiscountAmount = floatval((floatval($discountAmount) / 100) * $signupFee);
                        $signupFee -= $discountSignupDiscountAmount;
                    }
                }
            }

            if($hasFixedDiscounts && $fixedMaxTotal > 0) {
                if($fixedMaxTotal >= $subInitialTotal) {
                    $recurringAmount = 0;
                    $signupFee = 0;
                } else {
                    $recurringAmount = $recurringAmount - ($fixedMaxTotal / $subInitialTotal) * $recurringAmount;
                    if($signupFee > 0) {
                        $signupFee = $signupFee - ($fixedMaxTotal / $subInitialTotal) * $signupFee;
                    }
                }
            }

            $subscriptionItems[$subIndex]['recurring_amount'] = intval($recurringAmount);
            $subscriptionItems[$subIndex]['initial_amount'] = intval($signupFee);

            $originalPlan = Helper::safeUnserialize($subscriptionItem['original_plan']);

            $originalPlan['subscription_amount'] = round($recurringAmount / 100, 2);
            $originalPlan['signup_fee'] = round($recurringAmount / 100, 2);
            $subscriptionItems[$subIndex]['original_plan'] = maybe_serialize($originalPlan);
        }

        $this->subscriptionItems = $subscriptionItems;
        return true;
    }
}
