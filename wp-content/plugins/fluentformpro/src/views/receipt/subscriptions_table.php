<?php defined('ABSPATH') or die; ?>
<table style="width: 100%;border: 1px solid #cbcbcb;margin-top: 0; margin-bottom: 15px;" class="table ffp_order_items_table ffp_table table_bordered">
    <thead>
    <tr>
        <th><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
            _e('Item', 'fluentformpro'); ?></th>
        <th><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
            _e('Status', 'fluentformpro'); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($subscriptions as $subscription): ?>
        <tr>
            <td>
                <?php echo esc_html($subscription->plan_name . ' (' . $subscription->item_name . ')'); ?>
                <?php if($subscription->original_plan): ?>
                <p style="margin: 5px 0px 0px;font-size: 90%;"><?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo \FluentFormPro\Payments\PaymentHelper::getPaymentSummaryText($subscription->original_plan, $subscription->form_id, $submission->currency, false); ?></p>
                <?php endif; ?>
            </td>
            <td><span class="ff_trial_badge"><?php echo esc_html(ucfirst($subscription->status)); ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
