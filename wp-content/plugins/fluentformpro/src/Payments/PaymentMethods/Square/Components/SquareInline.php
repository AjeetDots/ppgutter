<?php

namespace FluentFormPro\Payments\PaymentMethods\Square\Components;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentFormPro\Payments\PaymentMethods\Square\SquareSettings;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class SquareInline extends BaseFieldManager
{
    /**
     * This is not a standalone editor components
     * rather just a frontend rendering.
     */
    public function __construct()
    {
        parent::__construct(
            'square_inline',
            'Inline Square Payment Method',
            ['payment methods', 'payment', 'methods', 'square', 'square inline'],
            'payments'
        );

        add_filter('fluentform/payment_method_contents_square', array($this, 'maybePushPaymentInputs'), 10, 4);
    }

    public function maybePushPaymentInputs($inlineContents, $method, $data, $form)
    {
        if (ArrayHelper::get($method, 'settings.embedded_checkout.value') !== 'yes') {
            return $inlineContents;
        }

        add_filter('fluentform/form_class', function ($classes, $targetForm) use ($form) {
            if ($form->instance_index == $targetForm->instance_index) {
                $classes .= ' ff_has_square_inline';
            }

            return $classes;
        }, 10, 2);

        $elementId = $data['attributes']['name'] . '_' . $form->id . '_' . $form->instance_index . '_square_inline';
        $label = ArrayHelper::get($method, 'settings.option_label.value', __('Pay with Square', 'fluentformpro'));
        $display = $method['is_default'] ? 'block' : 'none';

        $markup = '<div class="square-inline-wrapper ff_pay_inline ff_pay_inline_square" style="display: ' . $display . '">';
        $markup .= '<div class="ff-el-input--label">';
        $markup .= '<label for="' . $elementId . '">' . $label . '</label>';
        $markup .= '</div>';

        $attributes = [
            'name'                    => 'square_card_element',
            'class'                   => 'ff_square_card_element ff-el-form-control',
            'data-wpf_payment_method' => 'square',
            'id'                      => $elementId,
            'data-checkout_style'     => 'embedded_form',
            'data-verify_zip'         => ArrayHelper::get($method, 'settings.verify_zip.value') === 'yes'
        ];

        $markup .= '<div ' . $this->buildAttributes($attributes) . '></div>';
        $markup .= '<div class="ff_card-errors text-danger" role="alert"></div>';

        if (!SquareSettings::isLive()) {
            $squareTestModeMessage = __('Square test mode activated', 'fluentformpro');
            $markup .= '<span style="margin-top: 5px;padding: 0;font-style: italic;font-size: 12px">' . $squareTestModeMessage . '</span>';
        }

        $markup .= '</div>';

        return $inlineContents . $markup;
    }


    /**
     * We don't need to return anything from here
     */
    function getComponent()
    {
    }

    /**
     * We don't need to return anything from here
     */
    function render($element, $form)
    {
    }
}
