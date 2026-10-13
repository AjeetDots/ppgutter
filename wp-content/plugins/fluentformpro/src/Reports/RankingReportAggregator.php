<?php

namespace FluentFormPro\Reports;

defined('ABSPATH') or die;

use FluentForm\App\Models\EntryDetails;
use FluentForm\Framework\Helpers\ArrayHelper;

/**
 * Aggregates Ranking field submissions into a per-option x per-rank
 * distribution so the free Reports tab can render the new
 * RankingReportCard.
 *
 * Hooks the per-element filter exposed by free
 * (`fluentform/reports/format_field_input_ranking`) and registers
 * `input_ranking` as a reportable input so the free generator
 * picks the field up in the first place.
 */
class RankingReportAggregator
{
    const ELEMENT = 'input_ranking';

    public function init()
    {
        add_filter('fluentform/reportable_inputs', [$this, 'registerReportable']);
        add_filter('fluentform/reports/format_field_' . self::ELEMENT, [$this, 'aggregate'], 10, 4);
    }

    /**
     * @param array $inputs
     * @return array
     */
    public function registerReportable($inputs)
    {
        if (!in_array(self::ELEMENT, $inputs, true)) {
            $inputs[] = self::ELEMENT;
        }
        return $inputs;
    }

    /**
     * Replace the generic GROUP BY aggregation with a per-position
     * distribution: each option gets a count of how often it landed
     * at each rank position plus an average rank.
     *
     * @param array $defaultReport  The free-side GROUP BY output (discarded for ranking).
     * @param array $field          Field definition including options + element.
     * @param int   $formId
     * @param array $statuses       Submission statuses to include.
     * @return array Ranking report shape.
     */
    public function aggregate($defaultReport, $field, $formId, $statuses)
    {
        $fieldName = ArrayHelper::get($field, 'name') ?: ArrayHelper::get($field, 'attributes.name');
        if (!$fieldName) {
            return $defaultReport;
        }

        $label = ArrayHelper::get($defaultReport, 'label', '')
            ?: ArrayHelper::get($field, 'admin_label', '')
            ?: ArrayHelper::get($field, 'label', '');

        $options = $this->extractOptions($field);
        if (empty($options)) {
            return $defaultReport;
        }

        // SQL-side GROUP BY: the DB returns at most (positions x options)
        // rows regardless of how many submissions exist, so memory use
        // is bounded by the field shape (small) instead of by submission
        // volume. A second cheap COUNT(DISTINCT) query gives the total.
        $aggregateRows = $this->fetchAggregateCounts($formId, $fieldName, $statuses);
        if (empty($aggregateRows) || count($aggregateRows) === 0) {
            return [
                'type'              => 'ranking',
                'options'           => [],
                'total_submissions' => 0,
                'total_entry'       => 0,
                'label'             => $label,
                'element'           => self::ELEMENT,
            ];
        }

        $optionCount = count($options);
        $stats = [];
        foreach ($options as $option) {
            $stats[(string) $option['value']] = [
                'value'           => $option['value'],
                'label'           => $option['label'],
                'positions'       => array_fill(1, $optionCount, 0),
                'rank_sum'        => 0,
                'total_responses' => 0,
            ];
        }

        foreach ($aggregateRows as $row) {
            // '' is what SubmissionService stores for a non-array value; it and
            // any other non-position cast to 0 and would land on position 1.
            if (!ctype_digit((string) $row->sub_field_name)) {
                continue;
            }

            $position = ((int) $row->sub_field_name) + 1;
            $choiceKey = (string) $row->field_value;
            $count = (int) $row->cnt;

            if (!isset($stats[$choiceKey])) {
                continue;
            }
            if ($position < 1 || $position > $optionCount) {
                continue;
            }
            $stats[$choiceKey]['positions'][$position] += $count;
            $stats[$choiceKey]['rank_sum'] += $position * $count;
            $stats[$choiceKey]['total_responses'] += $count;
        }

        $totalSubmissions = (int) ArrayHelper::get($defaultReport, 'total_entry', 0);
        if ($totalSubmissions === 0) {
            $totalSubmissions = $this->fetchTotalSubmissions($formId, $fieldName, $statuses);
        }
        if ($totalSubmissions === 0) {
            foreach ($stats as $entry) {
                if ($entry['total_responses'] > $totalSubmissions) {
                    $totalSubmissions = $entry['total_responses'];
                }
            }
        }

        $optionsOut = [];
        foreach ($stats as $entry) {
            $average = $entry['total_responses'] > 0
                ? round($entry['rank_sum'] / $entry['total_responses'], 2)
                : null;

            $distribution = [];
            foreach ($entry['positions'] as $position => $count) {
                $pct = $entry['total_responses'] > 0
                    ? round(($count / $entry['total_responses']) * 100, 2)
                    : 0;
                $distribution[] = [
                    'position' => $position,
                    'count'    => (int) $count,
                    'pct'      => $pct,
                ];
            }

            $optionsOut[] = [
                'value'           => $entry['value'],
                'label'           => $entry['label'],
                'average_rank'    => $average,
                'total_responses' => $entry['total_responses'],
                'distribution'    => $distribution,
            ];
        }

        usort($optionsOut, function ($a, $b) {
            if ($a['average_rank'] === null && $b['average_rank'] === null) {
                return 0;
            }
            if ($a['average_rank'] === null) {
                return 1;
            }
            if ($b['average_rank'] === null) {
                return -1;
            }
            return $a['average_rank'] <=> $b['average_rank'];
        });

        return [
            'type'              => 'ranking',
            'options'           => $optionsOut,
            'total_submissions' => $totalSubmissions,
            'total_entry'       => $totalSubmissions,
            'label'             => $label,
            'element'           => self::ELEMENT,
        ];
    }

    /**
     * @param array $field
     * @return array<int, array{value: string, label: string}>
     */
    private function extractOptions($field)
    {
        $raw = ArrayHelper::get($field, 'options', []);
        if (empty($raw)) {
            $raw = ArrayHelper::get($field, 'raw.settings.advanced_options', []);
        }

        $normalized = [];
        foreach ($raw as $key => $option) {
            if (is_array($option)) {
                $value = ArrayHelper::get($option, 'value');
                $label = ArrayHelper::get($option, 'label', $value);
            } else {
                $value = $key;
                $label = $option;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $normalized[] = [
                'value' => (string) $value,
                'label' => (string) ($label !== '' ? $label : $value),
            ];
        }
        return $normalized;
    }

    /**
     * @param int    $formId
     * @param string $fieldName
     * @param array  $statuses
     * @return \FluentForm\Framework\Database\Orm\Collection
     */
    private function fetchAggregateCounts($formId, $fieldName, $statuses)
    {
        // SQL-side GROUP BY caps the result at (positions x options)
        // rows regardless of submission volume, so memory is bounded
        // by the field shape -- not by how many people filled it in.
        $query = EntryDetails::select(['sub_field_name', 'field_value'])
            ->where('form_id', $formId)
            ->where('field_name', $fieldName);

        if (is_array($statuses) && count($statuses)) {
            $query->whereHas('submission', function ($q) use ($statuses) {
                $q->whereIn('status', $statuses);
            });
        }

        return $query
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy(['sub_field_name', 'field_value'])
            ->get();
    }

    /**
     * Single-scalar COUNT(DISTINCT submission_id). Avoids pulling any
     * submission_id values into PHP.
     *
     * @param int    $formId
     * @param string $fieldName
     * @param array  $statuses
     * @return int
     */
    private function fetchTotalSubmissions($formId, $fieldName, $statuses)
    {
        $query = EntryDetails::where('form_id', $formId)
            ->where('field_name', $fieldName);

        if (is_array($statuses) && count($statuses)) {
            $query->whereHas('submission', function ($q) use ($statuses) {
                $q->whereIn('status', $statuses);
            });
        }

        return (int) $query->distinct()->count('submission_id');
    }
}
