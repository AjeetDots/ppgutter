<?php
namespace FluentFormPro\classes;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Calculation
{
    public function enqueueScripts()
    {
        wp_enqueue_script('fluentform-advanced');
        wp_enqueue_script('fluent-math-expression', FLUENTFORMPRO_DIR_URL.'public/libs/math-expression.min.js', array(), '1.2.17');
    }

    public function localizeCalculationMessages($form)
    {
        // Add calculation field messages for translation
        $calculationMessages = [
            'calculation_error' => __('Calculation error occurred', 'fluentformpro'),
            'invalid_formula' => __('Invalid formula provided', 'fluentformpro'),
            'division_by_zero' => __('Division by zero error', 'fluentformpro')
        ];

        $calculationMessages = apply_filters('fluentform/calculation_field_messages', $calculationMessages, $form);

        // Use form-specific variable name to match JavaScript expectations
        wp_localize_script('fluentform-advanced', 'fluentform_calculation_messages_' . $form->id, $calculationMessages);
    }
}