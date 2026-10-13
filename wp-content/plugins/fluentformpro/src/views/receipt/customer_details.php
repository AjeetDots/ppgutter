<?php defined('ABSPATH') or die; ?>
<?php if($transaction->payer_name || $transaction->payer_email) : ?>
<div class="ffp_customer_details">
    <h4><?php
        // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
        _e('Customer Details', 'fluentformpro'); ?></h4>
    <div  class="ffp_submission_details">
        <ul>
            <?php if($transaction->payer_name): ?>
            <li><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Customer Name:', 'fluentformpro');?> <?php echo esc_html($transaction->payer_name); ?></li>
            <?php endif; ?>
            <?php if($transaction->payer_email): ?>
            <li><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Customer Email:', 'fluentformpro');?> <?php echo esc_html($transaction->payer_email); ?></li>
            <?php endif; ?>
            <?php if($transaction->billing_address && strlen($transaction->billing_address) > 2): ?>
            <li><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Billing Address:', 'fluentformpro');?> <?php echo esc_html($transaction->billing_address); ?></li>
            <?php endif; ?>
            <?php if($transaction->shipping_address && strlen($transaction->shipping_address) > 2): ?>
            <li><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Shipping Address:', 'fluentformpro');?> <?php echo esc_html($transaction->shipping_address); ?></li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?php endif; ?>
