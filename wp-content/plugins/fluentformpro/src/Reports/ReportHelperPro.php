<?php

namespace FluentFormPro\Reports;

defined('ABSPATH') or die;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Models\Submission;
use FluentForm\App\Modules\Payments\PaymentHelper;
use FluentForm\App\Services\Report\ReportHelper as CoreReportHelper;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

/**
 * ReportHelperPro
 */

if (!class_exists('FluentForm\App\Services\Report\ReportHelper')) {
    return;
}
class ReportHelperPro extends CoreReportHelper
{
    /**
     * Get completion rate data
     */
    public static function getCompletionRateData($startDate, $endDate, $formId = 0)
    {
        $completeQuery = Submission::whereBetween('created_at', [$startDate, $endDate]);

        if ($formId) {
            $completeQuery->where('form_id', $formId);
        }

        $completeSubmissions = $completeQuery->count();

        $incompleteSubmissions = 0;
        if (Helper::hasPro()) {
            $incompleteQuery = wpFluent()->table('fluentform_draft_submissions')
                ->whereBetween('created_at', [$startDate, $endDate]);

            if ($formId) {
                $incompleteQuery->where('form_id', $formId);
            }

            $incompleteSubmissions = $incompleteQuery->count();
        }

        // Calculate totals - total_submissions should be complete submissions only
        // Total attempts = complete + incomplete (drafts)
        $totalAttempts = $completeSubmissions + $incompleteSubmissions;
        $completionRate = $totalAttempts > 0 ? round(($completeSubmissions / $totalAttempts) * 100, 1) : 0;

        return [
            'completion_rate' => $completionRate,
            'incomplete_submissions' => $incompleteSubmissions,
            'total_submissions' => $completeSubmissions, // This should be complete submissions only
            'total_attempts' => $totalAttempts // Total form attempts (complete + incomplete)
        ];
    }

    /**
     * Get submission heatmap data aggregated by recurring time periods
     *
     * This method returns cumulative submissions aggregated by 1-hour time slots within recurring periods.
     * (e.g., all Mondays combined, all Tuesdays combined, etc.) .
     *
     * @param string $startDate
     * @param string $endDate
     * @param int|null $formId Optional form ID to filter by
     * @return array Heatmap data with aggregated submissions by recurring time periods
     */
    public static function getSubmissionHeatmap($startDate, $endDate, $formId = 0)
    {

        list($startDate, $endDate) = self::processDateRange($startDate, $endDate);

        // Calculate date difference to determine aggregation type
        $startDateTime = new \DateTime($startDate);
        $endDateTime = new \DateTime($endDate);
        $interval = $startDateTime->diff($endDateTime);
        $daysInterval = $interval->days + 1;

        $aggregationType = 'day_of_week';

        $heatmapData = self::initializeHeatmapData($aggregationType);

        $results = self::getHeatmapSubmissionData($startDate, $endDate, $formId, $aggregationType);

        // Fill in actual submission data
        foreach ($results as $row) {
            $timeSlotIndex = (int)$row->submission_hour; // 1-hour intervals (0-23)
            $count = (int)$row->count;

            if ($aggregationType === 'day_of_week') {
                // Convert MySQL DAYOFWEEK (1=Sunday, 7=Saturday) to our format (0=Sunday, 6=Saturday)
                $dayOfWeek = ((int)$row->day_of_week - 1) % 7;
                $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $dayKey = $dayNames[$dayOfWeek];

                if (isset($heatmapData[$dayKey])) {
                    $heatmapData[$dayKey][$timeSlotIndex] += $count;
                }
            }
        }

        // Generate time slots for AM/PM display
        $timeSlots = [
            'am' => ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM",
                    "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM"],
            'pm' => ["12 PM", "1 PM", "2 PM", "3 PM", "4 PM", "5 PM",
                    "6 PM", "7 PM", "8 PM", "9 PM", "10 PM", "11 PM"]
        ];

        // Generate day labels
        $dayLabels = [
            'Sunday' => 'SUN', 'Monday' => 'MON', 'Tuesday' => 'TUE', 'Wednesday' => 'WED',
            'Thursday' => 'THU', 'Friday' => 'FRI', 'Saturday' => 'SAT'
        ];

        $result = [
            'heatmap_data'     => $heatmapData,
            'time_slots'       => $timeSlots,
            'day_labels'       => $dayLabels,
            'aggregation_type' => $aggregationType,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'days_in_range'    => $daysInterval
        ];

        return $result;
    }

    /**
     * Get submissions grouped by country
     */
    public static function getSubmissionsByCountry($startDate, $endDate, $formId = 0)
    {
        if (apply_filters('fluentform/disable_submission_country_detection', false, $formId)) {
            return [
                'country_data' => [],
                'disable' => true
            ];
        }

        list($startDate, $endDate) = self::processDateRange($startDate, $endDate);

        $query = Submission::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('UPPER(country) as country_code, COUNT(*) as count')
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->groupBy('country_code')
            ->orderBy('count', 'DESC');

        if ($formId) {
            $query->where('form_id', $formId);
        }

        $results = $query->get();
        $countryNames = getFluentFormCountryList();

        $countryData = [];
        foreach ($results as $result) {
            $countryCode = $result->country_code;
            $countryData[] = [
                // The map joins on the ISO code. `name` is localized by
                // getFluentFormCountryList(), so it is only safe for display.
                'code'  => $countryCode,
                'name'  => $countryNames[$countryCode] ?? $countryCode,
                'value' => (int)$result->count
            ];
        }

        return [
            'country_data' => $countryData
        ];
    }

    /**
     * Get subscriptions data
     */
    public static function getSubscriptions($startDate, $endDate, $formId = 0)
    {
        $paymentSettings = get_option('__fluentform_payment_module_settings');
        // Disabled persists as the string 'no' (!'no' is false), so match the canonical
        // enabled value the admin ReportHelper checks: status === 'yes'.
        if (!$paymentSettings || 'yes' !== Arr::get($paymentSettings, 'status')) {
            return []; // Return empty if payment module is disabled
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        list($startDate, $endDate) = self::processDateRange($startDate, $endDate);

        $previousStartDate = (new \DateTime($startDate))->modify('-' . self::getDateDifference($startDate, $endDate) . ' days')->format('Y-m-d H:i:s');
        $previousEndDate = (new \DateTime($startDate))->modify('-1 day')->format('Y-m-d H:i:s');

        $defaultCode = strtoupper((string) Arr::get(PaymentHelper::getCurrencyConfig(), 'currency', 'USD'));

        // Currency is the value captured on the subscription's SUBMISSION at checkout
        // (fluentform_submissions.currency) — the historical currency, not the form's
        // mutable _payment_settings and never a cross-currency blend. Joined on the
        // submission PRIMARY KEY (submission_id), so the lookup costs one indexed row
        // per in-scope subscription and never scans the transaction history; an
        // empty/missing submission currency falls back to the site default.
        $currencyExpr   = "UPPER(COALESCE(NULLIF(sub.currency, ''), ?))";
        $submissionJoin = "LEFT JOIN {$prefix}fluentform_submissions sub ON sub.id = s.submission_id";

        // Same status population for the window and its growth baseline — a cancelled
        // subscription is not recurring revenue. form_id 0 = all forms.
        $statusWhere = "(s.status = 'active' OR s.status = 'trialling')";
        $formWhere   = $formId ? "AND s.form_id = ?" : "";
        $bindingsFor = function ($from, $to) use ($defaultCode, $formId) {
            $b = [$defaultCode, $from, $to];
            if ($formId) {
                $b[] = $formId;
            }
            return $b;
        };

        // Bounded by the number of DISTINCT currencies — GROUP BY collapses volume in
        // SQL, never a per-subscription materialization in PHP. GROUP BY the select
        // ORDINAL, not the alias: the `currency`/`plan_name` aliases shadow real
        // columns (submissions.currency, subscriptions.plan_name) and MySQL would
        // otherwise group by the raw column instead of the normalization expression.
        $currentRows = wpFluent()->select(
            "SELECT {$currencyExpr} AS currency, COUNT(*) AS cnt, SUM(s.recurring_amount) AS amount_cents
             FROM {$prefix}fluentform_subscriptions s
             {$submissionJoin}
             WHERE s.created_at BETWEEN ? AND ? AND {$statusWhere} {$formWhere}
             GROUP BY 1",
            $bindingsFor($startDate, $endDate)
        );

        $previousRows = wpFluent()->select(
            "SELECT {$currencyExpr} AS currency, SUM(s.recurring_amount) AS amount_cents
             FROM {$prefix}fluentform_subscriptions s
             {$submissionJoin}
             WHERE s.created_at BETWEEN ? AND ? AND {$statusWhere} {$formWhere}
             GROUP BY 1",
            $bindingsFor($previousStartDate, $previousEndDate)
        );

        $symbolOf  = [];
        $symbolFor = function ($code) use (&$symbolOf) {
            if (!isset($symbolOf[$code])) {
                $symbolOf[$code] = html_entity_decode(PaymentHelper::getCurrencySymbol($code), ENT_QUOTES);
            }
            return $symbolOf[$code];
        };
        $growth = function ($nowCents, $prevCents) {
            if ($prevCents > 0) {
                return round((($nowCents - $prevCents) / $prevCents) * 100, 1);
            }
            return $nowCents > 0 ? 100 : 0;
        };
        $byCur  = [];
        $ensure = function ($code) use (&$byCur) {
            if (!isset($byCur[$code])) {
                $byCur[$code] = ['cents' => 0.0, 'count' => 0, 'prev_cents' => 0.0];
            }
        };

        $blendedCents = 0.0;
        $blendedCount = 0;
        foreach ($currentRows as $row) {
            $code  = (string) $row->currency;
            $ensure($code);
            $cents = (float) $row->amount_cents;
            $count = (int) $row->cnt;
            $byCur[$code]['cents'] += $cents;
            $byCur[$code]['count'] += $count;
            $blendedCents += $cents;
            $blendedCount += $count;
        }

        // The previous period is only a growth baseline. Its total feeds the blended
        // growth, but a currency seen ONLY in the previous period must not create a
        // current-period block (that would surface an empty zero-total currency and
        // wrongly flip the MCP report to "multi-currency" / non-empty).
        $prevBlendedCents = 0.0;
        foreach ($previousRows as $row) {
            $code  = (string) $row->currency;
            $cents = (float) $row->amount_cents;
            $prevBlendedCents += $cents;
            if (isset($byCur[$code])) {
                $byCur[$code]['prev_cents'] += $cents;
            }
        }

        // A single blended top-5 plan chart (the admin "Top Subscriptions by Plan"
        // view), ranked and capped in SQL so the plan catalogue is never materialized.
        // Per-currency plan splits are deliberately NOT computed — that needs either a
        // per-currency query loop or MySQL 8 window functions; the currency-correct
        // figures that matter (totals + growth) are already in by_currency below.
        $planLabel    = "COALESCE(NULLIF(s.plan_name, ''), NULLIF(s.item_name, ''), 'Unnamed Plan')";
        $planBindings = [$startDate, $endDate];
        if ($formId) {
            $planBindings[] = $formId;
        }

        $blendedPlanRows = wpFluent()->select(
            "SELECT {$planLabel} AS plan_name, SUM(s.recurring_amount) AS amount_cents
             FROM {$prefix}fluentform_subscriptions s
             WHERE s.created_at BETWEEN ? AND ? AND {$statusWhere} {$formWhere}
             GROUP BY 1
             ORDER BY amount_cents DESC
             LIMIT 5",
            $planBindings
        );
        $chartData = [];
        foreach ($blendedPlanRows as $row) {
            $chartData[] = ['name' => $row->plan_name, 'value' => (float) $row->amount_cents / 100];
        }

        $byCurrency = [];
        foreach ($byCur as $code => $b) {
            $byCurrency[$code] = [
                'currency'           => $code,
                'currency_symbol'    => $symbolFor($code),
                'total_recurring'    => round($b['cents'] / 100, 2),
                'subscription_count' => $b['count'],
                'growth_percentage'  => $growth($b['cents'], $b['prev_cents']),
            ];
        }

        // Top-level symbol: the site default for the all-forms view (unchanged), or the
        // scoped form's own immutable currency (dominant block) when a form is selected.
        $topCode = $defaultCode;
        if ($formId && $byCurrency) {
            $topCode = null;
            foreach ($byCurrency as $code => $b) {
                if (null === $topCode || $b['total_recurring'] > $byCurrency[$topCode]['total_recurring']) {
                    $topCode = $code;
                }
            }
        }

        // The top-level total_recurring / growth_percentage / chart_data are the
        // INTENTIONALLY blended figures the admin Reports → Subscriptions page has
        // always shown (kept backward-compatible for that single-summary UI). They are
        // a display aggregate, not an authoritative per-currency amount. Any consumer
        // that must not blend currencies — the MCP agent tool included — reads
        // by_currency instead, which carries the currency-correct totals and growth.
        return [
            'total_recurring'    => $blendedCents / 100,
            'growth_percentage'  => $growth($blendedCents, $prevBlendedCents),
            'subscription_count' => $blendedCount,
            'chart_data'         => $chartData,
            'start_date'         => $startDate,
            'currency_symbol'    => $symbolFor($topCode),
            'end_date'           => $endDate,
            'by_currency'        => $byCurrency,
        ];
    }

    /**
     * NET revenue (paid − refunded) split by the transaction's own currency, so a
     * multi-currency window is never reported as one blended, unlabelled figure.
     * Bounded by the number of distinct currencies (GROUP BY collapses in SQL).
     *
     * @return array[] list of ['currency', 'net', 'paid', 'refunded']
     */
    public static function getNetRevenueByCurrency($startDate, $endDate, $formId = 0)
    {
        $defaultCode = strtoupper((string) Arr::get(PaymentHelper::getCurrencyConfig(), 'currency', 'USD'));

        $query = wpFluent()->table('fluentform_transactions')
            ->select('currency')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN payment_total ELSE 0 END) as paid")
            ->selectRaw("SUM(CASE WHEN status = 'refunded' THEN payment_total ELSE 0 END) as refunded")
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'refunded'])
            ->groupBy('currency');
        if ($formId) {
            $query->where('form_id', $formId);
        }

        $byCode = [];
        foreach ($query->get() as $row) {
            $code = strtoupper((string) $row->currency);
            if ('' === $code) {
                $code = $defaultCode;
            }
            if (!isset($byCode[$code])) {
                $byCode[$code] = ['paid' => 0.0, 'refunded' => 0.0];
            }
            $byCode[$code]['paid']     += (float) $row->paid;
            $byCode[$code]['refunded'] += (float) $row->refunded;
        }

        $out = [];
        foreach ($byCode as $code => $v) {
            $out[] = [
                'currency' => $code,
                'net'      => round(($v['paid'] - $v['refunded']) / 100, 2),
                'paid'     => round($v['paid'] / 100, 2),
                'refunded' => round($v['refunded'] / 100, 2),
            ];
        }

        return $out;
    }

    public static function getNetRevenueByForms($startDate, $endDate, $formId = 0, $perPage = 10, $currentPage = 1)
    {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $query = wpFluent()
            ->table('fluentform_transactions')
            ->join('fluentform_forms', 'fluentform_transactions.form_id', '=', 'fluentform_forms.id')
            ->select(
                'fluentform_forms.id as form_id',
                'fluentform_forms.title as form_title',
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as paid_amount"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'pending' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as pending_amount"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as refunded_amount"),
                wpFluent()->raw("(SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) - SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END)) as net_revenue")
            )
            ->whereBetween('fluentform_transactions.created_at', [$startDate, $endDate])
            ->whereIn('fluentform_transactions.status', ['paid', 'pending', 'refunded'])
            ->groupBy('fluentform_forms.id', 'fluentform_forms.title')
            ->orderBy('net_revenue', 'DESC');

        // Conditional form scope: a scoped manager passes their authorized form;
        // form_id 0 (a full manager) intentionally reports across all forms.
        if ($formId) {
            $query->where('fluentform_transactions.form_id', $formId);
        }

        $results = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $total = $results->total();

        // Get totals for all data
        $totalsQuery = wpFluent()
            ->table('fluentform_transactions')
            ->select(
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as paid"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'pending' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as pending"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as refunded"),
                wpFluent()->raw("(SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) - SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END)) as net")
            )
            ->whereBetween('fluentform_transactions.created_at', [$startDate, $endDate])
            ->whereIn('fluentform_transactions.status', ['paid', 'pending', 'refunded']);

        if ($formId) {
            $totalsQuery->where('fluentform_transactions.form_id', $formId);
        }

        $totals = $totalsQuery->first();
        if ($totals) {
            $formattedTotals = [
                'paid'     => round($totals->paid / 100, 2),
                'pending'  => round($totals->pending / 100, 2),
                'refunded' => round($totals->refunded / 100, 2),
                'net'      => round($totals->net / 100, 2)
            ];
        } else {
            $formattedTotals = [
                'paid'     => 0,
                'pending'  => 0,
                'refunded' => 0,
                'net'      => 0
            ];
        }

        $formattedResults = [];
        foreach ($results->items() as $row) {
            $formattedResults[] = [
                'form_id'         => $row->form_id,
                'form_title'      => $row->form_title ?: 'Untitled Form',
                'paid_amount'     => round($row->paid_amount / 100, 2),
                'pending_amount'  => round($row->pending_amount / 100, 2),
                'refunded_amount' => round($row->refunded_amount / 100, 2),
                'net_revenue'     => round($row->net_revenue / 100, 2)
            ];
        }

        return [
            'data'   => $formattedResults,
            'totals' => $formattedTotals,
            'total'  => $total
        ];
    }

    public static function getNetRevenueByPaymentMethod(
        $startDate,
        $endDate,
        $formId = null,
        $perPage = 10,
        $currentPage = 1
    ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $query = wpFluent()
            ->table('fluentform_transactions')
            ->select(
                'fluentform_transactions.payment_method',
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as paid_amount"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'pending' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as pending_amount"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as refunded_amount"),
                wpFluent()->raw("(SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) - SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END)) as net_revenue"),
                wpFluent()->raw("COUNT(*) as transaction_count")
            )
            ->whereBetween('fluentform_transactions.created_at', [$startDate, $endDate])
            ->whereIn('fluentform_transactions.status', ['paid', 'pending', 'refunded'])
            ->whereNotNull('fluentform_transactions.payment_method');

        if ($formId) {
            $query->where('fluentform_transactions.form_id', $formId);
        }

        $query->groupBy('fluentform_transactions.payment_method')
            ->orderBy('net_revenue', 'DESC');

        $results = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $total = $results->total();

        // Get totals for all data
        $totalsQuery = wpFluent()
            ->table('fluentform_transactions')
            ->select(
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as paid"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'pending' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as pending"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as refunded"),
                wpFluent()->raw("(SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) - SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END)) as net")
            )
            ->whereBetween('fluentform_transactions.created_at', [$startDate, $endDate])
            ->whereIn('fluentform_transactions.status', ['paid', 'pending', 'refunded']);

        if ($formId) {
            $totalsQuery->where('fluentform_transactions.form_id', $formId);
        }

        $totals = $totalsQuery->first();
        if ($totals) {
            $formattedTotals = [
                'paid'     => round($totals->paid / 100, 2),
                'pending'  => round($totals->pending / 100, 2),
                'refunded' => round($totals->refunded / 100, 2),
                'net'      => round($totals->net / 100, 2)
            ];
        } else {
            $formattedTotals = [
                'paid'     => 0,
                'pending'  => 0,
                'refunded' => 0,
                'net'      => 0
            ];
        }

        $formattedResults = [];
        foreach ($results->items() as $row) {
            $paymentMethodName = self::formatPaymentMethodName($row->payment_method);
            $formattedResults[] = [
                'payment_method'      => $row->payment_method,
                'payment_method_name' => $paymentMethodName,
                'paid_amount'         => round($row->paid_amount / 100, 2),
                'pending_amount'      => round($row->pending_amount / 100, 2),
                'refunded_amount'     => round($row->refunded_amount / 100, 2),
                'net_revenue'         => round($row->net_revenue / 100, 2),
                'transaction_count'   => $row->transaction_count
            ];
        }
        return [
            'data'   => $formattedResults,
            'totals' => $formattedTotals,
            'total'  => $total
        ];
    }

    public static function getNetRevenueByPaymentType(
        $startDate,
        $endDate,
        $formId = null,
        $perPage = 10,
        $currentPage = 1
    ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $query = wpFluent()
            ->table('fluentform_transactions')
            ->select(
                'fluentform_transactions.transaction_type',
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as paid_amount"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'pending' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as pending_amount"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as refunded_amount"),
                wpFluent()->raw("(SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) - SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END)) as net_revenue"),
                wpFluent()->raw("COUNT(*) as transaction_count")
            )
            ->whereBetween('fluentform_transactions.created_at', [$startDate, $endDate])
            ->whereIn('fluentform_transactions.status', ['paid', 'pending', 'refunded'])
            ->whereIn('fluentform_transactions.transaction_type', ['onetime', 'subscription']);

        if ($formId) {
            $query->where('fluentform_transactions.form_id', $formId);
        }

        $query->groupBy('fluentform_transactions.transaction_type')
            ->orderBy('net_revenue', 'DESC');

        $results = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $total = $results->total();

        // Get totals for all data
        $totalsQuery = wpFluent()
            ->table('fluentform_transactions')
            ->select(
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as paid"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'pending' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as pending"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) as refunded"),
                wpFluent()->raw("(SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'paid' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END) - SUM(CASE WHEN {$prefix}fluentform_transactions.status = 'refunded' THEN {$prefix}fluentform_transactions.payment_total ELSE 0 END)) as net")
            )
            ->whereBetween('fluentform_transactions.created_at', [$startDate, $endDate])
            ->whereIn('fluentform_transactions.status', ['paid', 'pending', 'refunded']);

        if ($formId) {
            $totalsQuery->where('fluentform_transactions.form_id', $formId);
        }

        $totals = $totalsQuery->first();
        if ($totals) {
            $formattedTotals = [
                'paid'     => round($totals->paid / 100, 2),
                'pending'  => round($totals->pending / 100, 2),
                'refunded' => round($totals->refunded / 100, 2),
                'net'      => round($totals->net / 100, 2)
            ];
        } else {
            $formattedTotals = [
                'paid'     => 0,
                'pending'  => 0,
                'refunded' => 0,
                'net'      => 0
            ];
        }

        $formattedResults = [];
        foreach ($results->items() as $row) {
            $typeName = $row->transaction_type === 'onetime' ? 'One-time Payment' : 'Subscription';
            $formattedResults[] = [
                'payment_type'      => $row->transaction_type,
                'payment_type_name' => $typeName,
                'paid_amount'       => round($row->paid_amount / 100, 2),
                'pending_amount'    => round($row->pending_amount / 100, 2),
                'refunded_amount'   => round($row->refunded_amount / 100, 2),
                'net_revenue'       => round($row->net_revenue / 100, 2),
                'transaction_count' => $row->transaction_count
            ];
        }

        return [
            'data'   => $formattedResults,
            'totals' => $formattedTotals,
            'total'  => $total
        ];
    }

    public static function getSubmissionAnalysisByForms($startDate, $endDate, $formId = 0, $perPage = 10, $currentPage = 1)
    {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $query = wpFluent()
            ->table('fluentform_submissions')
            ->join('fluentform_forms', 'fluentform_submissions.form_id', '=', 'fluentform_forms.id')
            ->select(
                'fluentform_forms.id as form_id',
                'fluentform_forms.title as form_title',
                wpFluent()->raw('COUNT(*) as total_submissions'),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as read_submissions"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as unread_submissions"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as spam_submissions"),
                wpFluent()->raw("ROUND((SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as conversion_rate")
            )
            ->whereBetween('fluentform_submissions.created_at', [$startDate, $endDate])
            ->groupBy('fluentform_forms.id', 'fluentform_forms.title')
            ->orderBy('total_submissions', 'DESC');

        // Conditional form scope: a scoped manager passes their authorized form;
        // form_id 0 (a full manager) intentionally reports across all forms.
        if ($formId) {
            $query->where('fluentform_submissions.form_id', $formId);
        }

        $results = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $total = $results->total();

        // Get totals for all data
        $totalsQuery = wpFluent()
            ->table('fluentform_submissions')
            ->select(
                wpFluent()->raw('COUNT(*) as `total`'),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as `read_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as `unread_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as `spam_count`")
            )
            ->whereBetween('fluentform_submissions.created_at', [$startDate, $endDate]);

        if ($formId) {
            $totalsQuery->where('fluentform_submissions.form_id', $formId);
        }

        $totalsData = $totalsQuery->first();
        if ($totalsData) {
            $totals = [
                'total'    => (int)$totalsData->total,
                'read'     => (int)$totalsData->read_count,
                'unread'   => (int)$totalsData->unread_count,
                'spam'     => (int)$totalsData->spam_count,
                'readRate' => $totalsData->total > 0 ? round(($totalsData->read_count / $totalsData->total) * 100,
                    2) : 0
            ];
        } else {
            $totals = [
                'total'    => 0,
                'read'     => 0,
                'unread'   => 0,
                'spam'     => 0,
                'readRate' => 0
            ];
        }

        $formattedResults = [];
        foreach ($results->items() as $row) {
            $formattedResults[] = [
                'form_id'            => $row->form_id,
                'form_title'         => $row->form_title ?: 'Untitled Form',
                'total_submissions'  => (int)$row->total_submissions,
                'read_submissions'   => (int)$row->read_submissions,
                'unread_submissions' => (int)$row->unread_submissions,
                'spam_submissions'   => (int)$row->spam_submissions,
                'conversion_rate'    => (float)$row->conversion_rate
            ];
        }

        return [
            'data'   => $formattedResults,
            'total'  => $total,
            'totals' => $totals
        ];
    }

    public static function getSubmissionAnalysisBySource(
        $startDate,
        $endDate,
        $formId = null,
        $perPage = 10,
        $currentPage = 1
    ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        // Build the query using wpFluent with full table names
        $query = wpFluent()
            ->table('fluentform_submissions')
            ->select(
                'fluentform_submissions.source_url',
                wpFluent()->raw('COUNT(*) as total_submissions'),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as read_submissions"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as unread_submissions"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as spam_submissions"),
                wpFluent()->raw("ROUND((SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as conversion_rate")
            )
            ->whereBetween('fluentform_submissions.created_at', [$startDate, $endDate]);

        if ($formId) {
            $query->where('fluentform_submissions.form_id', $formId);
        }
        // Group by source URL and order by total submissions descending
        $query->groupBy('fluentform_submissions.source_url')
            ->orderBy('total_submissions', 'DESC');

        $results = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $total = $results->total();

        // Get totals for all data
        $totalsQuery = wpFluent()
            ->table('fluentform_submissions')
            ->select(
                wpFluent()->raw('COUNT(*) as `total`'),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as `read_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as `unread_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as `spam_count`")
            )
            ->whereBetween('fluentform_submissions.created_at', [$startDate, $endDate]);

        if ($formId) {
            $totalsQuery->where('fluentform_submissions.form_id', $formId);
        }

        $totalsData = $totalsQuery->first();
        if ($totalsData) {
            $totals = [
                'total'    => (int)$totalsData->total,
                'read'     => (int)$totalsData->read_count,
                'unread'   => (int)$totalsData->unread_count,
                'spam'     => (int)$totalsData->spam_count,
                'readRate' => $totalsData->total > 0 ? round(($totalsData->read_count / $totalsData->total) * 100,
                    2) : 0
            ];
        } else {
            $totals = [
                'total'    => 0,
                'read'     => 0,
                'unread'   => 0,
                'spam'     => 0,
                'readRate' => 0
            ];
        }

        $formattedResults = [];
        foreach ($results->items() as $row) {
            $formattedResults[] = [
                'source_url'         => $row->source_url ?: 'Direct Access',
                'total_submissions'  => (int)$row->total_submissions,
                'read_submissions'   => (int)$row->read_submissions,
                'unread_submissions' => (int)$row->unread_submissions,
                'spam_submissions'   => (int)$row->spam_submissions,
                'conversion_rate'    => (float)$row->conversion_rate
            ];
        }

        return [
            'data'   => $formattedResults,
            'total'  => $total,
            'totals' => $totals
        ];
    }

    public static function getSubmissionAnalysisByEmail(
        $startDate,
        $endDate,
        $formId = null,
        $perPage = 10,
        $currentPage = 1
    ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $formCondition = $formId ? "AND {$prefix}fluentform_submissions.form_id = ?" : "";
        $bindings = [$startDate, $endDate];
        if ($formId) {
            $bindings[] = $formId;
        }

        // First, get the total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM (
            SELECT
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.email')), ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.email_1')), ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.user_email')), ''),
                    'No Email'
                ) as email
            FROM {$prefix}fluentform_submissions
            WHERE {$prefix}fluentform_submissions.created_at BETWEEN ? AND ?
            {$formCondition}
            GROUP BY COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.email')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.email_1')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.user_email')), ''),
                'No Email'
            )
        ) as email_count";

        $totalResult = wpFluent()->selectOne($countQuery, $bindings);
        $total = $totalResult ? (int)$totalResult->total : 0;

        // Now get the actual data with pagination
        $perPage = absint($perPage);
        $offset = absint(($currentPage - 1) * $perPage);
        $dataQuery = "SELECT
            COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.email')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.email_1')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.user_email')), ''),
                'No Email'
            ) as email,
            COUNT(*) as total_submissions,
            SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as read_submissions,
            SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as unread_submissions,
            SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as spam_submissions,
            ROUND((SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as conversion_rate
        FROM {$prefix}fluentform_submissions
        WHERE {$prefix}fluentform_submissions.created_at BETWEEN ? AND ?
        {$formCondition}
        GROUP BY COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.email')), ''),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.email_1')), ''),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$prefix}fluentform_submissions.response, '$.user_email')), ''),
            'No Email'
        )
        ORDER BY total_submissions DESC
        LIMIT {$perPage} OFFSET {$offset}";

        $results = wpFluent()->select($dataQuery, $bindings);

        // Get totals for all data
        $totalsQuery = wpFluent()
            ->table('fluentform_submissions')
            ->select(
                wpFluent()->raw('COUNT(*) as `total`'),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as `read_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as `unread_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as `spam_count`")
            )
            ->whereBetween('fluentform_submissions.created_at', [$startDate, $endDate]);

        if ($formId) {
            $totalsQuery->where('fluentform_submissions.form_id', $formId);
        }

        $totalsData = $totalsQuery->first();
        if ($totalsData) {
            $totals = [
                'total'    => (int)$totalsData->total,
                'read'     => (int)$totalsData->read_count,
                'unread'   => (int)$totalsData->unread_count,
                'spam'     => (int)$totalsData->spam_count,
                'readRate' => $totalsData->total > 0 ? round(($totalsData->read_count / $totalsData->total) * 100,
                    2) : 0
            ];
        } else {
            $totals = [
                'total'    => 0,
                'read'     => 0,
                'unread'   => 0,
                'spam'     => 0,
                'readRate' => 0
            ];
        }

        $formattedResults = [];
        foreach ($results as $row) {
            $formattedResults[] = [
                'email'              => $row->email,
                'total_submissions'  => (int)$row->total_submissions,
                'read_submissions'   => (int)$row->read_submissions,
                'unread_submissions' => (int)$row->unread_submissions,
                'spam_submissions'   => (int)$row->spam_submissions,
                'conversion_rate'    => (float)$row->conversion_rate
            ];
        }

        return [
            'data'   => $formattedResults,
            'total'  => $total,
            'totals' => $totals
        ];
    }

    public static function getSubmissionAnalysisByCountry(
        $startDate,
        $endDate,
        $formId = null,
        $perPage = 10,
        $currentPage = 1
    ) {
        if (apply_filters('fluentform/disable_submission_country_detection', false, $formId)) {
            return [
                'is_country_disable' => __('Country detection is disabled. Please enable it to see the submission analysis by country.', 'fluentformpro')
            ];
        }

        global $wpdb;
        $prefix = $wpdb->prefix;
        $query = wpFluent()
            ->table('fluentform_submissions')
            ->select(
                'fluentform_submissions.country',
                wpFluent()->raw('COUNT(*) as total_submissions'),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as read_submissions"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as unread_submissions"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as spam_submissions"),
                wpFluent()->raw("ROUND((SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as conversion_rate")
            )
            ->whereBetween('fluentform_submissions.created_at', [$startDate, $endDate]);

        if ($formId) {
            $query->where('fluentform_submissions.form_id', $formId);
        }

        // Group by country and order by total submissions descending
        $query->groupBy('fluentform_submissions.country')
            ->orderBy('total_submissions', 'DESC');

        $results = $query->paginate($perPage, ['*'], 'page', $currentPage);

        $total = $results->total();

        // Get totals for all data
        $totalsQuery = wpFluent()
            ->table('fluentform_submissions')
            ->select(
                wpFluent()->raw('COUNT(*) as `total`'),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as `read_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as `unread_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as `spam_count`")
            )
            ->whereBetween('fluentform_submissions.created_at', [$startDate, $endDate]);

        if ($formId) {
            $totalsQuery->where('fluentform_submissions.form_id', $formId);
        }

        $totalsData = $totalsQuery->first();
        if ($totalsData) {
            $totals = [
                'total'    => (int)$totalsData->total,
                'read'     => (int)$totalsData->read_count,
                'unread'   => (int)$totalsData->unread_count,
                'spam'     => (int)$totalsData->spam_count,
                'readRate' => $totalsData->total > 0 ? round(($totalsData->read_count / $totalsData->total) * 100,
                    2) : 0
            ];
        } else {
            $totals = [
                'total'    => 0,
                'read'     => 0,
                'unread'   => 0,
                'spam'     => 0,
                'readRate' => 0,
            ];
        }

        $formattedResults = [];
        $countryNames = getFluentFormCountryList();
        foreach ($results->items() as $row) {
            $countryCode = null;
            if ($row->country && strlen($row->country) === 2 && ctype_alpha($row->country)) {
                $upper = strtoupper($row->country);
                if (isset($countryNames[$upper])) {
                    // Stored as ISO2 code
                    $countryCode = strtolower($upper);
                }
            }
            $formattedResults[] = [
                'country'            => $row->country ? Arr::get($countryNames, strtoupper($row->country), $row->country) : 'Unknown',
                'country_code'       => $countryCode, // ISO2 lower-case (e.g., 'us', 'gb') for flags
                'total_submissions'  => (int)$row->total_submissions,
                'read_submissions'   => (int)$row->read_submissions,
                'unread_submissions' => (int)$row->unread_submissions,
                'spam_submissions'   => (int)$row->spam_submissions,
                'conversion_rate'    => (float)$row->conversion_rate
            ];
        }

        return [
            'data'   => $formattedResults,
            'total'  => $total,
            'totals' => $totals
        ];
    }

    public static function getSubmissionAnalysisByDate(
        $startDate,
        $endDate,
        $formId = null,
        $perPage = 10,
        $currentPage = 1
    ) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $formCondition = $formId ? "AND {$prefix}fluentform_submissions.form_id = ?" : "";
        $bindings = [$startDate, $endDate];
        if ($formId) {
            $bindings[] = $formId;
        }

        // First, get the total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM (
            SELECT DATE({$prefix}fluentform_submissions.created_at) as submission_date
            FROM {$prefix}fluentform_submissions
            WHERE {$prefix}fluentform_submissions.created_at BETWEEN ? AND ?
            {$formCondition}
            GROUP BY DATE({$prefix}fluentform_submissions.created_at)
        ) as date_count";

        $totalResult = wpFluent()->selectOne($countQuery, $bindings);
        $total = $totalResult ? (int)$totalResult->total : 0;

        $perPage = absint($perPage);
        $offset = absint(($currentPage - 1) * $perPage);
        $dataQuery = "SELECT
            DATE({$prefix}fluentform_submissions.created_at) as submission_date,
            COUNT(*) as total_submissions,
            SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as read_submissions,
            SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as unread_submissions,
            SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as spam_submissions,
            ROUND((SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as conversion_rate
        FROM {$prefix}fluentform_submissions
        WHERE {$prefix}fluentform_submissions.created_at BETWEEN ? AND ?
        {$formCondition}
        GROUP BY DATE({$prefix}fluentform_submissions.created_at)
        ORDER BY submission_date DESC
        LIMIT {$perPage} OFFSET {$offset}";

        $results = wpFluent()->select($dataQuery, $bindings);

        // Get totals for all data
        $totalsQuery = wpFluent()
            ->table('fluentform_submissions')
            ->select(
                wpFluent()->raw('COUNT(*) as `total`'),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'read' THEN 1 ELSE 0 END) as `read_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'unread' THEN 1 ELSE 0 END) as `unread_count`"),
                wpFluent()->raw("SUM(CASE WHEN {$prefix}fluentform_submissions.status = 'spam' THEN 1 ELSE 0 END) as `spam_count`")
            )
            ->whereBetween('fluentform_submissions.created_at', [$startDate, $endDate]);

        if ($formId) {
            $totalsQuery->where('fluentform_submissions.form_id', $formId);
        }

        $totalsData = $totalsQuery->first();
        if ($totalsData) {
            $totals = [
                'total'    => (int)$totalsData->total,
                'read'     => (int)$totalsData->read_count,
                'unread'   => (int)$totalsData->unread_count,
                'spam'     => (int)$totalsData->spam_count,
                'readRate' => $totalsData->total > 0 ? round(($totalsData->read_count / $totalsData->total) * 100,
                    2) : 0
            ];
        } else {
            $totals = [
                'total'    => 0,
                'read'     => 0,
                'unread'   => 0,
                'spam'     => 0,
                'readRate' => 0,
            ];
        }

        $formattedResults = [];
        foreach ($results as $row) {
            $formattedResults[] = [
                'submission_date'    => $row->submission_date,
                'total_submissions'  => (int)$row->total_submissions,
                'read_submissions'   => (int)$row->read_submissions,
                'unread_submissions' => (int)$row->unread_submissions,
                'spam_submissions'   => (int)$row->spam_submissions,
                'conversion_rate'    => (float)$row->conversion_rate
            ];
        }

        return [
            'data'   => $formattedResults,
            'total'  => $total,
            'totals' => $totals
        ];
    }


}

