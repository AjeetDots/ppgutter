<?php defined('ABSPATH') or die; ?>
<?php
/** @var object $transaction */
/** @var string $transactionTotal */
/** @var array $items */
/** @var array $discountItems */
/** @var string $subTotal */
/** @var string $orderTotal */
?>
<div class="ff_payment_transaction">
    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo \FluentFormPro\Payments\PaymentHelper::loadView('transaction_info', [
        'transaction' => $transaction,
        'transactionTotal' => $transactionTotal
    ]);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo \FluentFormPro\Payments\PaymentHelper::loadView('order_items_table', [
        'items' => $items,
        'discount_items' => $discountItems,
        'subTotal' => $subTotal,
        'orderTotal' => $orderTotal
    ]);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo \FluentFormPro\Payments\PaymentHelper::loadView('customer_details', [
        'transaction' => $transaction
    ]);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo \FluentFormPro\Payments\PaymentHelper::loadView('custom_css', []);

    ?>
</div>