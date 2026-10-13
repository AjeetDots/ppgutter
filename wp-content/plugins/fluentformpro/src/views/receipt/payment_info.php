<?php defined('ABSPATH') or die; ?>
<div class="ffp_payment_info">
    <div class="ffp_payment_info_item ffp_payment_info_item_order_id">
        <div class="ffp_item_heading"><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
            _e('Order ID:', 'fluentformpro');?></div>
        <div class="ffp_item_value">#<?php echo intval($submission->id); ?></div>
    </div>
    <div class="ffp_payment_info_item ffp_payment_info_item_date">
        <div class="ffp_item_heading"><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
            _e('Date:' ,'fluentformpro');?></div>
        <div class="ffp_item_value"><?php echo esc_html(date(get_option( 'date_format' ), strtotime($submission->created_at))); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended ?></div>
    </div>
    <div class="ffp_payment_info_item ffp_payment_info_item_total">
        <div class="ffp_item_heading"><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
            _e('Total:','fluentformpro');?></div>
        <div class="ffp_item_value"><?php echo esc_html($totalPaid); ?></div>
    </div>
    <?php
    $paymentMethod = $submission->payment_method;
    if($paymentMethod): ?>
        <div class="ffp_payment_info_item ffp_payment_info_item_payment_method">
            <div class="ffp_item_heading"><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Payment Method:','fluentformpro');?></div>
            <div class="ffp_item_value"><?php
                $paymentMethod = apply_filters_deprecated(
                    'fluentform_payment_method_public_name_' . $paymentMethod,
                    [
                        $paymentMethod
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/payment_method_public_name_' . $paymentMethod,
                    'Use fluentform/payment_method_public_name_' . $paymentMethod. ' instead of fluentform_payment_method_public_name_' . $paymentMethod
                );
                echo esc_html(ucfirst(
                    apply_filters(
                        'fluentform/payment_method_public_name_' . $paymentMethod,
                        $paymentMethod
                    )
                )); ?></div>
        </div>
    <?php endif; ?>
    <?php
    if ($submission->payment_status):
        $allStatus = \FluentFormPro\Payments\PaymentHelper::getPaymentStatuses();
        if (isset($allStatus[$submission->payment_status])) {
            $submission->payment_status = $allStatus[$submission->payment_status];
        }
        ?>
        <div class="ffp_payment_info_item ffp_payment_info_item_payment_status">
            <div class="ffp_item_heading"><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Payment Status:','fluentformpro');?></div>
            <div class="ffp_item_value"><?php echo esc_html($submission->payment_status); ?></div>
        </div>
    <?php endif; ?>
</div>
