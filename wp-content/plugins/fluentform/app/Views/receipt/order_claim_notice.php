<?php

defined('ABSPATH') or die;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables in view files
// $message is pre-escaped in OrderClaim (translator text + esc_html'd email), so it is echoed as-is.
?>
<div class="ff_order_claim_notice<?php echo $is_error ? ' ff_order_claim_notice--error' : ''; ?>" role="<?php echo $is_error ? 'alert' : 'status'; ?>">
    <p class="ff_order_claim_message"><?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
    <?php if ($show_form): ?>
    <form method="post" class="ff_order_claim_form">
        <?php wp_nonce_field('ff_order_claim_send', 'ff_order_claim_nonce'); ?>
        <input type="hidden" name="ff_order_claim_send" value="1" />
        <button type="submit" class="wp-element-button button ff_order_claim_btn"><?php esc_html_e('Add my past orders', 'fluentform'); ?></button>
    </form>
    <?php endif; ?>
</div>
