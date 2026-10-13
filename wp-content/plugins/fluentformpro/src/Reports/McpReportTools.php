<?php

namespace FluentFormPro\Reports;

defined('ABSPATH') || exit;

use FluentForm\App\Modules\Acl\Acl;
use FluentForm\App\Modules\MCP\Support\ErrorCodes;
use FluentForm\App\Modules\MCP\Support\FormAccess;
use FluentForm\App\Modules\MCP\Support\MCPHelper;
use FluentForm\App\Modules\MCP\Support\PermissionGate;
use FluentFormPro\Reports\ReportHelperPro;

/**
 * Pro Advanced Reporting MCP tools, injected via the fluentform/mcp_tool_definitions filter.
 *
 * ReportHelperPro scopes only by an optional $formId (0 = all forms, no filter), so
 * the all-forms path is re-gated here to the admin ReportPolicy contract: a
 * specific-forms manager is refused the cross-form aggregate rather than leaking it.
 */
class McpReportTools
{
    const CAP_PAYMENTS = 'fluentform_view_payments';

    public static function defineTools($defs)
    {
        if (!is_array($defs)) {
            $defs = [];
        }

        return array_merge($defs, self::definitions());
    }

    public static function definitions()
    {
        return [
            'fluentform/get-revenue-analysis' => [
                'label'       => __('Get Revenue Analysis (Advanced/Pro)', 'fluentformpro'),
                'group'       => __('Reports', 'fluentformpro'),
                'description' => __('Advanced (Pro) NET revenue analytics — the same figures as the admin Reports → Revenue page. NET means paid minus refunded (contrast the free get-payment-summary tool, which reports GROSS per-status buckets). Breaks revenue down by form, payment method, or payment type (one-time vs subscription), each row carrying paid/pending/refunded/net amounts in whole currency units. At most the top 100 groups by net are returned (see data.truncated / data.total); net is also reported per currency under data.by_currency. Scope to one form with form_id, or omit it to cover every form you can access.', 'fluentformpro'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => ['type' => 'integer', 'description' => 'Optional. Omit to aggregate across all forms in your access scope.'],
                        'group_by'  => ['type' => 'string', 'enum' => ['forms', 'payment_method', 'payment_type'], 'description' => 'How to break down revenue. Defaults to "forms".'],
                        'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD (site timezone). Defaults to 30 days ago. The window may span at most 366 days.'],
                        'date_to'   => ['type' => 'string', 'description' => 'YYYY-MM-DD (site timezone). Defaults to today.'],
                    ],
                ],
                'execute_callback' => [self::class, 'getRevenueAnalysis'],
                'capability'       => self::CAP_PAYMENTS,
                'annotations'      => ['readonly' => true],
                'pro'              => true,
            ],

            'fluentform/get-completion-rate' => [
                'label'       => __('Get Completion Rate (Advanced/Pro)', 'fluentformpro'),
                'group'       => __('Reports', 'fluentformpro'),
                'description' => __('Advanced (Pro) form completion / funnel figures — the same numbers as the admin Reports → Completion Rate page. Returns completed submissions, incomplete (draft/abandoned) attempts, total attempts, and the completion_rate percentage (completed ÷ total attempts). Scope to one form with form_id, or omit it to cover every form you can access.', 'fluentformpro'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => ['type' => 'integer', 'description' => 'Optional. Omit to aggregate across all forms in your access scope.'],
                        'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD (site timezone). Defaults to 30 days ago. The window may span at most 366 days.'],
                        'date_to'   => ['type' => 'string', 'description' => 'YYYY-MM-DD (site timezone). Defaults to today.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getCompletionRate'],
                // Both caps required (AND), matching ReportPolicy::canAccessOptionalFormScopedReport;
                // the registrar's `capability` array is any-of (OR), which would be too permissive.
                'permission_callback' => [self::class, 'canAccessCompletionRate'],
                'annotations'         => ['readonly' => true],
                'pro'                 => true,
            ],

            'fluentform/get-subscription-report' => [
                'label'       => __('Get Subscription Report (Advanced/Pro)', 'fluentformpro'),
                'group'       => __('Reports', 'fluentformpro'),
                'description' => __('Advanced (Pro) recurring-revenue report — the same figures as the admin Reports → Subscriptions page. Returns total recurring amount, active subscription count, period-over-period growth percentage, and the top recurring plans by amount. Amounts are in whole currency units. Scope to one form with form_id, or omit it to cover every form you can access.', 'fluentformpro'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => ['type' => 'integer', 'description' => 'Optional. Omit to aggregate across all forms in your access scope.'],
                        'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD (site timezone). Defaults to 30 days ago. The window may span at most 366 days.'],
                        'date_to'   => ['type' => 'string', 'description' => 'YYYY-MM-DD (site timezone). Defaults to today.'],
                    ],
                ],
                'execute_callback' => [self::class, 'getSubscriptionReport'],
                'capability'       => self::CAP_PAYMENTS,
                'annotations'      => ['readonly' => true],
                'pro'              => true,
            ],
        ];
    }

    public static function getRevenueAnalysis($params = [])
    {
        $formId = self::resolveScopedFormId($params);
        if (is_wp_error($formId)) {
            return $formId;
        }

        $disabled = self::paymentModuleGuard();
        if (is_wp_error($disabled)) {
            return $disabled;
        }

        $window = self::window($params);
        if (is_wp_error($window)) {
            return $window;
        }
        list($from, $to, $start, $end) = $window;

        $groupBy = isset($params['group_by']) ? sanitize_text_field($params['group_by']) : 'forms';
        if (!in_array($groupBy, ['forms', 'payment_method', 'payment_type'], true)) {
            return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('group_by must be one of: forms, payment_method, payment_type.', 'fluentformpro'), ['fields' => ['group_by']]);
        }

        switch ($groupBy) {
            case 'payment_method':
                $result = ReportHelperPro::getNetRevenueByPaymentMethod($start, $end, $formId, self::MAX_ROWS, 1);
                break;
            case 'payment_type':
                $result = ReportHelperPro::getNetRevenueByPaymentType($start, $end, $formId, self::MAX_ROWS, 1);
                break;
            default:
                $result = ReportHelperPro::getNetRevenueByForms($start, $end, $formId, self::MAX_ROWS, 1);
        }

        $totals = isset($result['totals']) ? $result['totals'] : [];
        $net    = isset($totals['net']) ? (float) $totals['net'] : 0;

        // Grouped rows/totals sum payment_total across currencies. Report NET per
        // currency so a multi-currency window is never a single unlabelled figure.
        $byCurrency    = ReportHelperPro::getNetRevenueByCurrency($start, $end, $formId);
        $multiCurrency = count($byCurrency) > 1;

        $rows      = isset($result['data']) ? $result['data'] : [];
        $total     = isset($result['total']) ? (int) $result['total'] : 0;
        $truncated = $total > self::MAX_ROWS;

        $data = [
            'form_id'        => $formId ? $formId : null,
            'from'           => $from,
            'to'             => $to,
            'group_by'       => $groupBy,
            'basis'          => 'net',
            'rows'           => $rows,
            'totals'         => $totals,
            'total'          => $total,
            'returned'       => count($rows),
            'truncated'      => $truncated,
            'by_currency'    => $byCurrency,
            'multi_currency' => $multiCurrency,
        ];

        // Rows are capped at MAX_ROWS with no paging input; say so rather than let the
        // agent read a partial breakdown as complete.
        $notes = [];
        if ($multiCurrency) {
            $notes[] = __('The window spans multiple currencies; per-currency NET is under "by_currency". The grouped rows and totals are cross-currency sums and must not be read as a single amount.', 'fluentformpro');
        }
        if ($truncated) {
            $notes[] = sprintf(
                /* translators: 1: max rows returned, 2: total groups matched */
                __('Only the top %1$d groups by net revenue are returned (%2$d matched). Narrow the form scope or date range to see the rest.', 'fluentformpro'),
                self::MAX_ROWS,
                $total
            );
        }
        if ($notes) {
            $data['note'] = implode(' ', $notes);
        }

        if ($multiCurrency) {
            $parts = [];
            foreach ($byCurrency as $c) {
                $parts[] = number_format($c['net'], 2) . ' ' . $c['currency'];
            }

            return MCPHelper::envelope(
                sprintf(
                    /* translators: 1: per-currency net list, 2: group_by dimension, 3: start date, 4: end date */
                    __('Net revenue by currency, grouped by %2$s, between %3$s and %4$s: %1$s.', 'fluentformpro'),
                    implode('; ', $parts),
                    $groupBy,
                    $from,
                    $to
                ),
                $data
            );
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: net revenue, 2: group_by dimension, 3: start date, 4: end date */
                __('Net revenue %1$s (paid − refunded), grouped by %2$s, between %3$s and %4$s.', 'fluentformpro'),
                number_format($net, 2),
                $groupBy,
                $from,
                $to
            ),
            $data
        );
    }

    public static function getCompletionRate($params = [])
    {
        $formId = self::resolveScopedFormId($params);
        if (is_wp_error($formId)) {
            return $formId;
        }

        $window = self::window($params);
        if (is_wp_error($window)) {
            return $window;
        }
        list($from, $to, $start, $end) = $window;

        $result = ReportHelperPro::getCompletionRateData($start, $end, $formId);

        $rate = isset($result['completion_rate']) ? (float) $result['completion_rate'] : 0;

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: completion rate percent, 2: completed count, 3: total attempts, 4: start date, 5: end date */
                __('%1$s%% completion rate: %2$d of %3$d attempts completed between %4$s and %5$s.', 'fluentformpro'),
                number_format($rate, 1),
                isset($result['total_submissions']) ? (int) $result['total_submissions'] : 0,
                isset($result['total_attempts']) ? (int) $result['total_attempts'] : 0,
                $from,
                $to
            ),
            [
                'form_id'                => $formId ? $formId : null,
                'from'                   => $from,
                'to'                     => $to,
                'completion_rate_pct'    => $rate,
                'completed_submissions'  => isset($result['total_submissions']) ? (int) $result['total_submissions'] : 0,
                'incomplete_submissions' => isset($result['incomplete_submissions']) ? (int) $result['incomplete_submissions'] : 0,
                'total_attempts'         => isset($result['total_attempts']) ? (int) $result['total_attempts'] : 0,
            ]
        );
    }

    public static function getSubscriptionReport($params = [])
    {
        $formId = self::resolveScopedFormId($params);
        if (is_wp_error($formId)) {
            return $formId;
        }

        $disabled = self::paymentModuleGuard();
        if (is_wp_error($disabled)) {
            return $disabled;
        }

        $window = self::window($params);
        if (is_wp_error($window)) {
            return $window;
        }
        list($from, $to, $start, $end) = $window;

        $result     = ReportHelperPro::getSubscriptions($start, $end, $formId);
        $byCurrency = (is_array($result) && isset($result['by_currency'])) ? $result['by_currency'] : [];

        // Per currency, never blended across currencies (a cross-currency SUM is
        // meaningless); highest recurring first. Recurring totals + growth only — a
        // plan ranking is not surfaced to the agent (it would either blend currencies
        // or need a per-currency query loop), so no misleading top-plans amount ships.
        $currencies = array_values($byCurrency);
        usort($currencies, function ($a, $b) {
            return $b['total_recurring'] <=> $a['total_recurring'];
        });

        $data = [
            'form_id'        => $formId ? $formId : null,
            'from'           => $from,
            'to'             => $to,
            'multi_currency' => count($currencies) > 1,
            'currencies'     => $currencies,
        ];

        if (empty($currencies)) {
            $data['total_recurring']    = 0;
            $data['subscription_count'] = 0;
            $data['currency_symbol']    = '';

            return MCPHelper::envelope(
                sprintf(
                    /* translators: 1: start date, 2: end date */
                    __('No active subscriptions between %1$s and %2$s.', 'fluentformpro'),
                    $from,
                    $to
                ),
                $data
            );
        }

        // Single currency (the common case): mirror it at the top level too.
        if (1 === count($currencies)) {
            $only                       = $currencies[0];
            $data['currency']           = $only['currency'];
            $data['currency_symbol']    = $only['currency_symbol'];
            $data['total_recurring']    = $only['total_recurring'];
            $data['subscription_count'] = $only['subscription_count'];
            $data['growth_percentage']  = $only['growth_percentage'];

            return MCPHelper::envelope(
                sprintf(
                    /* translators: 1: subscription count, 2: currency symbol, 3: recurring amount, 4: start date, 5: end date */
                    __('%1$d subscriptions totalling %2$s%3$s recurring between %4$s and %5$s.', 'fluentformpro'),
                    $only['subscription_count'],
                    $only['currency_symbol'],
                    number_format($only['total_recurring'], 2),
                    $from,
                    $to
                ),
                $data
            );
        }

        $data['note'] = __('Amounts span multiple currencies and are reported separately under "currencies"; they are not summed.', 'fluentformpro');

        $parts = [];
        foreach ($currencies as $c) {
            $parts[] = $c['currency_symbol'] . number_format($c['total_recurring'], 2) . ' ' . $c['currency'];
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: comma-separated per-currency recurring totals, 2: start date, 3: end date */
                __('Recurring by currency between %2$s and %3$s: %1$s.', 'fluentformpro'),
                implode('; ', $parts),
                $from,
                $to
            ),
            $data
        );
    }

    const MAX_ROWS = 100;

    const MAX_SPAN_DAYS = 366;

    /** @return int|\WP_Error */
    private static function resolveScopedFormId($params)
    {
        if (!empty($params['form_id'])) {
            $form = FormAccess::resolveForm($params);
            if (is_wp_error($form)) {
                return $form;
            }

            return (int) $form->id;
        }

        $scope = PermissionGate::formScope();
        if (false !== $scope) {
            return MCPHelper::error(
                ErrorCodes::FORBIDDEN,
                __('You may only view advanced reports for a specific form. Pass a form_id you have access to.', 'fluentformpro')
            );
        }

        return 0;
    }

    public static function canAccessCompletionRate()
    {
        return Acl::hasPermission('fluentform_dashboard_access')
            && Acl::hasPermission('fluentform_entries_viewer');
    }

    /** @return true|\WP_Error */
    private static function paymentModuleGuard()
    {
        $settings = get_option('__fluentform_payment_module_settings');
        // Disabled is persisted as the string 'no' (empty('no') is false), so match the
        // canonical enabled value the admin ReportHelper checks: status === 'yes'.
        if (!$settings || 'yes' !== ($settings['status'] ?? '')) {
            return MCPHelper::error(ErrorCodes::FEATURE_DISABLED, __('The payment module is disabled, so there is no revenue or subscription data to report.', 'fluentformpro'));
        }

        return true;
    }

    /**
     * Returns [fromYmd, toYmd, startDateTime, endDateTime]. Full-day bounds
     * (00:00:00 / 23:59:59) match the admin Reports filters so figures line up.
     *
     * @return array|\WP_Error
     */
    private static function window($params)
    {
        $to = !empty($params['date_to']) ? sanitize_text_field($params['date_to']) : gmdate('Y-m-d', current_time('timestamp'));
        if (!MCPHelper::isYmd($to)) {
            return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('date_to must be a valid date in YYYY-MM-DD format.', 'fluentformpro'), ['fields' => ['date_to']]);
        }

        $from = !empty($params['date_from']) ? sanitize_text_field($params['date_from']) : gmdate('Y-m-d', strtotime('-30 days', strtotime($to)));
        if (!MCPHelper::isYmd($from)) {
            return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('date_from must be a valid date in YYYY-MM-DD format.', 'fluentformpro'), ['fields' => ['date_from']]);
        }

        if ($from > $to) {
            return MCPHelper::error(ErrorCodes::INVALID_PARAM, __('date_from must be on or before date_to.', 'fluentformpro'), ['fields' => ['date_from', 'date_to']]);
        }

        // Bound the window: subscription/revenue queries materialize rows, so an
        // unbounded span is unbounded work/memory. Agents chunk longer ranges.
        $spanDays = (int) (new \DateTime($from))->diff(new \DateTime($to))->days;
        if ($spanDays > self::MAX_SPAN_DAYS) {
            return MCPHelper::error(
                ErrorCodes::INVALID_PARAM,
                sprintf(
                    /* translators: %d: maximum number of days */
                    __('The date range is too large; it may span at most %d days. Narrow date_from/date_to.', 'fluentformpro'),
                    self::MAX_SPAN_DAYS
                ),
                ['fields' => ['date_from', 'date_to']]
            );
        }

        return [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59'];
    }
}
