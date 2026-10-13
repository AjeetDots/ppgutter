<?php defined('ABSPATH') or die; ?>
<ul class="ffp_payment_info_table">
    <li>
        <b><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
            _e('Amount:', 'fluentformpro');?></b> <?php echo esc_html($orderTotal); ?></b>
    </li>
    <?php
    $paymentMethod = $submission->payment_method;
    if($paymentMethod): ?>
        <li>
            <b><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Payment Method:', 'fluentformpro');?></b> <?php
            $paymentMethod = apply_filters_deprecated(
                'fluentform_payment_method_public_name_' . $paymentMethod,
                [
                    $paymentMethod
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/payment_method_public_name_' . $paymentMethod,
                'Use fluentform/payment_method_public_name_' . $paymentMethod . ' instead of fluentform_payment_method_public_name_' . $paymentMethod
            );
            echo esc_html(ucfirst(
                apply_filters(
                    'fluentform/payment_method_public_name_' . $paymentMethod,
                    $paymentMethod
                )
            )); ?></b>
        </li>
    <?php endif; ?>
    <?php
    if ($submission->payment_status):
        $allStatus = \FluentFormPro\Payments\PaymentHelper::getPaymentStatuses();
        if (isset($allStatus[$submission->payment_status])) {
            $submission->payment_status = $allStatus[$submission->payment_status];
        }
        ?>
        <li>
            <b><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Payment Status:', 'fluentformpro');?></b> <?php echo esc_html($submission->payment_status); ?></b>
        </li>
    <?php endif; ?>
</ul>
