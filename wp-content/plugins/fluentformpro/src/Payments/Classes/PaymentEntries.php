<?php

namespace FluentFormPro\Payments\Classes;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Modules\Acl\Acl;
use FluentFormPro\Payments\PaymentHelper;
use FluentForm\Framework\Helpers\ArrayHelper;

class PaymentEntries
{

    public function init()
    {
        add_action('fluentform/render_payment_entries', array($this, 'loadApp'));
        add_action('wp_ajax_fluentform_get_payments', array($this, 'getPayments'));
        add_action('wp_ajax_fluentform-do_entry_bulk_actions_payment', array($this, 'handleBulkAction'));
        add_action('wp_ajax_fluentform_get_all_payments_entries_filters', array($this, 'getFilters'));

    }

    public function loadApp()
    {
        wp_enqueue_style('ff-payment-entries', FLUENTFORMPRO_DIR_URL.'public/css/payment_entries.css', [], FLUENTFORMPRO_VERSION);
        wp_enqueue_script('ff-payment-entries', FLUENTFORMPRO_DIR_URL . 'public/js/payment-entries.js', ['jquery'], FLUENTFORMPRO_VERSION, true);
        $settingsUrl = admin_url('admin.php?page=fluent_forms_settings&component=payment_settings');
        do_action_deprecated(
            'fluentform_global_menu',
            [
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/global_menu',
            'Use fluentform/global_menu instead of fluentform_global_menu.'
        );
        do_action('fluentform/global_menu');
        echo '<div id="ff_payment_entries"><ff-payment-entries settings_url="'.esc_url($settingsUrl).'"></ff-payment-entries><global-search></global-search></div>';
    }

    public function getPayments()
    {
        Acl::verify('fluentform_view_payments', ArrayHelper::get($_REQUEST, 'form_id'));
        $perPage = intval($_REQUEST['per_page']);
        if(!$perPage) {
            $perPage = 10;
        }
        $paymentsQuery = wpFluent()->table('fluentform_transactions')
            ->select([
                'fluentform_transactions.id',
                'fluentform_transactions.form_id',
                'fluentform_transactions.submission_id',
                'fluentform_transactions.transaction_type',
                'fluentform_transactions.payment_method',
                'fluentform_transactions.payment_mode',
                'fluentform_transactions.charge_id',
                'fluentform_transactions.card_brand',
                'fluentform_transactions.payment_total',
                'fluentform_transactions.created_at',
                'fluentform_transactions.payer_name',
                'fluentform_transactions.status',
                'fluentform_transactions.currency',
                'fluentform_forms.title'
            ])
            ->join('fluentform_forms', 'fluentform_forms.id', '=', 'fluentform_transactions.form_id')
            ->orderBy('fluentform_transactions.id', 'DESC');

        if ($selectedFormId = ArrayHelper::get($_REQUEST, 'form_id')) {
            $paymentsQuery = $paymentsQuery->where('fluentform_transactions.form_id', intval($selectedFormId));
        }

        $allowFormIds = apply_filters('fluentform/current_user_allowed_forms', false);
        if (false !== $allowFormIds) {
            $paymentsQuery = $paymentsQuery->whereIn('fluentform_transactions.form_id', $allowFormIds ?: [0]);
        }
        if ($paymentStatus = ArrayHelper::get($_REQUEST, 'payment_statuses')) {
            $paymentsQuery = $paymentsQuery->where('fluentform_transactions.status', sanitize_text_field($paymentStatus));
        }
        if ($paymentMethods = ArrayHelper::get($_REQUEST, 'payment_methods')) {
            $paymentsQuery = $paymentsQuery->where('fluentform_transactions.payment_method', sanitize_text_field($paymentMethods));
        }
        $paymentsPaginate = $paymentsQuery->paginate($perPage);

        $payments = $paymentsPaginate->items();
        foreach ($payments as $payment) {
            $payment->formatted_payment_total = PaymentHelper::formatMoney($payment->payment_total, $payment->currency);
            $payment->entry_url = admin_url('admin.php?page=fluent_forms&route=entries&form_id='.$payment->form_id.'#/entries/'.$payment->submission_id);
            if($payment->payment_method == 'test') {
                $payment->payment_method = 'offline';
            }
        }
        wp_send_json_success([
            'payments'     => $payments,
            'total'        => $paymentsPaginate->total(),
            'current_page' => $paymentsPaginate->currentPage(),
            'per_page'     => $paymentsPaginate->perPage(),
            'last_page'    => $paymentsPaginate->lastPage()
        ]);

    }
    
    public function handleBulkAction()
    {
        // Acl::verify() verifies the admin AJAX nonce via Acl::verifyNonce()
        // before checking the capability, so this state-changing action is
        // already protected against CSRF.
        Acl::verify('fluentform_forms_manager');

        $entries    = array_values(array_unique(array_map('intval', wp_unslash($_REQUEST['entries']))));
        $actionType = sanitize_text_field($_REQUEST['action_type']);
        if (!$actionType || !count($entries)) {
            wp_send_json_error([
                'message' => __('Please select entries & action first', 'fluentformpro')
            ], 400);
        }
        
        $message = __("Invalid action", 'fluentformpro');
        $statusCode = 400;
        // permanently delete payment entries from transactions
        if ($actionType == 'delete_items') {
            try {
                $resolvedTransactions = $this->resolveTransactionsForBulkAction($entries);

                if (!Acl::hasPermission('fluentform_forms_manager', $resolvedTransactions['form_ids'])) {
                    wp_send_json_error([
                        'message' => __('You do not have permission to perform this action.', 'fluentformpro')
                    ], 422);
                }

                do_action_deprecated(
                    'fluentform_before_entry_payment_deleted',
                    [
                        $resolvedTransactions['transaction_ids'],
                        $resolvedTransactions['rows']
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/before_entry_payment_deleted',
                    'Use fluentform/before_entry_payment_deleted instead of fluentform_before_entry_payment_deleted.'
                );
                do_action('fluentform/before_entry_payment_deleted', $resolvedTransactions['transaction_ids'], $resolvedTransactions['rows']);

                wpFluent()->transaction(function () use ($resolvedTransactions) {
                    $this->deleteRowsByIds('fluentform_transactions', 'id', $resolvedTransactions['transaction_ids']);
                    $this->deleteRowsByIds('fluentform_order_items', 'submission_id', $resolvedTransactions['submission_ids']);
                    $this->deleteRowsByIds('fluentform_subscriptions', 'submission_id', $resolvedTransactions['submission_ids']);
                });
                
                //add log in each form that payment record has been deleted
                foreach ($resolvedTransactions['rows'] as $data){
                    $logData = [
                        'parent_source_id' => $data->form_id,
                        'source_type'      => 'submission_item',
                        'source_id'        => $data->submission_id,
                        'component'        => 'payment',
                        'status'           => 'info',
                        'title'            => __('Payment data successfully deleted', 'fluentformpro'),
                        'description'      => __('Payment record cleared from transaction history and order items', 'fluentformpro'),
                    ];
                    do_action('fluentform/log_data', $logData);
                }
                do_action_deprecated(
                    'fluentform_after_entry_payment_deleted',
                    [
                        $resolvedTransactions['transaction_ids'],
                        $resolvedTransactions['rows']
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/after_entry_payment_deleted',
                    'Use fluentform/after_entry_payment_deleted instead of fluentform_after_entry_payment_deleted.'
                );
                do_action('fluentform/after_entry_payment_deleted', $resolvedTransactions['transaction_ids'], $resolvedTransactions['rows']);
                $message = __('Selected entries successfully deleted', 'fluentformpro');
                $statusCode = 200;
        
            } catch (\Exception $exception) {
                $message = $exception->getMessage();
                $statusCode = 400;
            }
        }
        
        wp_send_json_success([
            'message' => $message
        ], $statusCode);
    }

    protected function resolveTransactionsForBulkAction(array $transactionIds)
    {
        $transactionIds = array_values(array_unique(array_filter(array_map('intval', $transactionIds))));

        $rows = wpFluent()->table('fluentform_transactions')
            ->select(['id', 'form_id', 'submission_id'])
            ->whereIn('fluentform_transactions.id', $transactionIds)
            ->get();

        $resolvedTransactionIds = [];
        $submissionIds = [];
        $formIds = [];

        foreach ($rows as $row) {
            $resolvedTransactionIds[(int) $row->id] = (int) $row->id;
            $formIds[(int) $row->form_id] = (int) $row->form_id;

            if (null === $row->submission_id || '' === $row->submission_id) {
                continue;
            }

            $submissionId = (int) $row->submission_id;
            if ($submissionId > 0) {
                $submissionIds[$submissionId] = $submissionId;
            }
        }

        if (!$resolvedTransactionIds || count($resolvedTransactionIds) !== count($transactionIds)) {
            throw new \Exception(__('Invalid transaction id', 'fluentformpro'));
        }

        return [
            'rows'            => $rows,
            'transaction_ids' => array_values($resolvedTransactionIds),
            'submission_ids'  => array_values($submissionIds),
            'form_ids'        => array_values($formIds)
        ];
    }

    protected function deleteRowsByIds($table, $column, array $ids)
    {
        if (!$ids) {
            return;
        }

        $result = wpFluent()->table($table)->whereIn($column, $ids)->delete();
        if (false === $result) {
            throw new \Exception(__('Failed to delete related payment records.', 'fluentformpro'));
        }
    }

    public function getFilters()
    {
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_view_payments');
        $statuses = wpFluent()->table('fluentform_transactions')
            ->select('status')
            ->groupBy('status')
            ->get();
        $statusTypes = PaymentHelper::getPaymentStatuses();
        $formattedStatuses = [];
        foreach ($statuses as $status) {
            $formattedStatuses[] = ArrayHelper::get($statusTypes, $status->status, $status->status);
        }
        $allowFormIds = apply_filters('fluentform/current_user_allowed_forms', false);
        $forms = wpFluent()->table('fluentform_transactions')
            ->select('fluentform_transactions.form_id', 'fluentform_forms.title')
            ->when(false !== $allowFormIds, function ($q) use ($allowFormIds){
                return $q->whereIn('fluentform_transactions.form_id', $allowFormIds ?: [0]);
            })
            ->groupBy('fluentform_transactions.form_id')
            ->orderBy('fluentform_transactions.form_id', 'DESC')
            ->join('fluentform_forms', 'fluentform_forms.id', '=', 'fluentform_transactions.form_id')
            ->get();

        $formattedForms = [];
        foreach ($forms as $form) {
            $formattedForms[] = [
                'form_id' => $form->form_id,
                'title'   => $form->title
            ];
        }

        $paymentMethods = wpFluent()->table('fluentform_transactions')
            ->select('payment_method')
            ->groupBy('payment_method')
            ->get();

        $formattedMethods = [];
        foreach ($paymentMethods as $method) {
            if(!$method->payment_method){
                continue;
            }
            if ($method->payment_method == 'test') {
                $formattedMethods[] = ['value' => __('Offline', 'fluentformpro'), 'key' => $method->payment_method];
            } else {
                $formattedMethods[] = ['value' => ucfirst($method->payment_method), 'key' => $method->payment_method];
            }
        }

        wp_send_json_success([
            'available_statuses'   => $formattedStatuses,
            'available_forms'      => $formattedForms,
            'available_methods'    => array_filter($formattedMethods),
        ]);

    }
}
