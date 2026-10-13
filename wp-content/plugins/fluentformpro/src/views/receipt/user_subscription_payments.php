<?php defined('ABSPATH') or die; ?>
<div class="ff_subscription_payments">
    <table style="width: 100%;border: 1px solid #cbcbcb;margin-top: 0;" class="table ffp_order_items_table ffp_table table_bordered">
        <thead>
        <tr>
            <th><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Amount', 'fluentformpro'); ?></th>
            <th><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Date', 'fluentformpro'); ?></th>
            <?php if($config['has_view_action']): ?>
            <th><?php
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction
                _e('Actions', 'fluentformpro'); ?></th>
            <?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($transactions as $transaction): ?>
        <tr>
            <td>
                <span class="table_payment_amount"><?php echo esc_html($transaction->formatted_amount); ?> <?php echo esc_html(strtoupper($transaction->currency)) ?></span>
                <span class="ff_pay_status_badge ff_pay_status_<?php echo esc_attr($transaction->status); ?>">
                    <?php echo esc_html($transaction->status); ?>
                </span>
            </td>
            <td>
                <?php echo esc_html($transaction->formatted_date); ?>
            </td>
            <?php if($transaction->view_url): ?>
            <td>
                <a class="ff_pat_action_view" href="<?php echo esc_url($transaction->view_url) ?>"><?php echo esc_html($config['view_text']); ?></a>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
