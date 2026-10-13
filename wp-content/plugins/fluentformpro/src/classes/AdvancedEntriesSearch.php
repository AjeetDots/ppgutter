<?php

namespace FluentFormPro\classes;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentForm\App\Services\FormBuilder\EditorShortCode;
use FluentForm\App\Modules\Form\FormFieldsParser;

class AdvancedEntriesSearch
{
    /**
     * Hard ceiling on filter complexity. A single submission cannot
     * exceed this many top-level OR groups or this many conditions
     * within a single group. Each input condition adds a LEFT JOIN, so
     * the worst-case query size is bounded by GROUPS * CONDITIONS joins.
     */
    const MAX_FILTER_GROUPS = 20;
    const MAX_CONDITIONS_PER_GROUP = 20;

    protected $supportedColumns = [];
    protected $numericColumns = [];
    protected $entryDetailsTableAlies = [];

    /**
     * Per-form memoization of the supported column / numeric column
     * lookup so we don't re-walk the form schema on every filter call.
     */
    protected $columnsCache = [];

    public function init()
    {
        add_filter('fluentform/entries_vars', [$this, 'getAdvancedFilterOptions'], 10, 2);
        add_filter('fluentform/apply_entries_advance_filter', [$this, 'applyAdvancedFilter'], 10, 2);
    }

    public function getAdvancedFilterOptions($data, $form)
    {
        $submissionCodes = EditorShortCode::getSubmissionShortcodes();
        $fields = FormFieldsParser::getInputsByElementTypes($form, $this->supportedFields(), ['label', 'element', 'options', 'attributes', 'settings']);
        $fieldCodes = [];

        foreach ($fields as $name => $field) {
            // Remove repeater subfield
            if ('.*' === substr($name, -strlen('.*'))) {
                continue;
            }

            $arr = [
                'label' => $field['label'] ? $field['label'] : $name,
                'value' => $name,
            ];
            $element = Arr::get($field, 'element');

            if (in_array($element, ['input_number', 'item_quantity_component'])) {
                $arr['type'] = 'numeric';
            } elseif (in_array($element, ['input_radio', 'select', 'input_checkbox', 'multi_payment_component'])) {
                $arr['type'] = 'selections';
                $arr['options'] = Arr::get($field, 'options');
                $arr['is_multiple'] = false;
                if ('input_checkbox' == $element || Arr::isTrue($field, 'attributes.multiple')) {
                    $arr['is_multiple'] = true;
                }
            } elseif ('input_date' == $element) {
                $arr['type'] = 'dates';
                $format = Arr::get($field, 'settings.date_format');
                $arr['format'] = $format;
                $dateType = Arr::get($this->getDatesInfo(), $format . '.type');
                if ('time' == $dateType) {
                    $arr['type'] = 'time';
                }
                $arr['date_type'] = $dateType ?: 'date';
            } else {
                $arr['type'] = 'text';
            }
            $fieldCodes[] = $arr;
        }

        $formShortCodes = [
            'inputs' => [
                'label'    => __('Inputs', 'fluentformpro'),
                'value'     => 'inputs',
                'children' => array_filter($fieldCodes)
            ]
        ];


        $userCodes = $this->formatAdvancedFilters([
            'title'      => __('User', 'fluentformpro'),
            'value'       => 'user',
            'shortcodes' => $this->getUserCodes()
        ]);

        $submissionCodes['value'] = 'entry-attribute';
        $submissionCodes['shortcodes'] = array_filter($submissionCodes['shortcodes'], function ($key) {
            return !in_array($key, ['{submission.created_at}', '{submission.status}']);
        }, ARRAY_FILTER_USE_KEY);
        $submissionCodes = $this->formatAdvancedFilters($submissionCodes);
        $allCodes = array_merge($formShortCodes, $userCodes, $submissionCodes);
        $groups = [];

        foreach ($allCodes as $code) {
            $groups[] = $code;
        }

        $data['advanced_filters'] = array_values($groups);
        $data['advanced_filters_operators'] = $this->getOperators();
        $data['advanced_filters_columns'] = [
            'numeric' => [
                'user.ID', 'entry-attribute.id', 'entry-attribute.serial_number', 'entry-attribute.user_id',
            ]
        ];
        return $data;
    }

    protected function formatAdvancedFilters($shortCodes)
    {
        $codes = [];
        foreach ($shortCodes['shortcodes'] as $code => $label) {
            preg_match('/{+(.*?)}/', $code, $matches);
            if ($matches && false !== strpos($matches[1], 'submission.')) {
                $code = substr($matches[1], strlen('submission.'));
            }

            $codes[] = [
                'label' => $label,
                'value' => $code,
            ];
        }
        return [
            $shortCodes['value'] => [
                'label'    => $shortCodes['title'],
                'value'     => $shortCodes['value'],
                'children' => $codes
            ]
        ];
    }

    protected function getUserCodes()
    {
        return [
            'ID'           => "ID",
            'user_login'   => "Username",
            'display_name' => 'Display Name',
            'user_email'   => 'Email'
        ];
    }

    public function applyAdvancedFilter($query, $attributes)
    {
        // Prepare filter groups
        $advanceFilters = Arr::get($attributes, 'advanced_filter');
        $formId = Arr::get($attributes, 'form_id');
        $form = Helper::getForm($formId);
        if (!$form) {
            return $query;
        }
        $this->setSupportedColumns($form);

        $filters = $this->formatAndSanitizeFilters($advanceFilters);
        if (!$filters) {
            return $query;
        }
        return $this->applyFiltersQuery($query, $filters);
    }

    protected function setSupportedColumns($form)
    {
        $formId = is_object($form) && isset($form->id) ? $form->id : 0;

        // Memoize per form_id. getAdvancedFilterOptions walks every form
        // field through FormFieldsParser; on a form with many fields this
        // is expensive, and we'd otherwise repeat it on every filter call
        // (and again later for the entry-vars hook).
        if (!isset($this->columnsCache[$formId])) {
            $data = $this->getAdvancedFilterOptions([], $form);
            $filters = Arr::get($data, 'advanced_filters');
            $columns = [];
            foreach ((array) $filters as $filter) {
                $children = Arr::get($filter, 'children');
                $columns = array_merge($columns, array_column((array) $children, null, 'value'));
            }
            $this->columnsCache[$formId] = [
                'supportedColumns' => $columns,
                'numericColumns'   => Arr::get($data, 'advanced_filters_columns.numeric', []),
            ];
        }

        $this->supportedColumns = $this->columnsCache[$formId]['supportedColumns'];
        $this->numericColumns   = $this->columnsCache[$formId]['numericColumns'];
    }


    protected function formatAndSanitizeFilters($filters)
    {
        // Bail on empty / non-array input (PHP 8+ would warn on foreach over
        // a non-array even if empty() doesn't catch all such cases).
        if (empty($filters) || !is_array($filters)) {
            return false;
        }

        // Cap filter complexity to prevent a malicious or accidental
        // submission from generating an unbounded query (each input
        // condition adds a LEFT JOIN; large groups can multiply that fast).
        $filters = array_slice($filters, 0, self::MAX_FILTER_GROUPS);

        $formattedFilters = [];
        foreach ($filters as $groupsIndex => $groups) {
            if (!is_array($groups)) {
                continue;
            }
            $groups = array_slice($groups, 0, self::MAX_CONDITIONS_PER_GROUP);
            foreach ($groups as $group) {
                if ($group = $this->sanitizeFilterGroup($group)) {
                    $sourceType = isset($group[0][0]) ? $group[0][0] : null;
                    if (!$sourceType) {
                        continue;
                    }
                    $formattedFilters[$groupsIndex][$sourceType][] = $group;
                }
            }
        }
        return $formattedFilters;
    }


    protected function sanitizeFilterGroup($group)
    {
        if (!$this->isValidGroup($group)) {
            return false;
        }
        $source = Arr::get($group, 'source');
        $operator = Arr::get($group, 'operator');
        $value = Arr::get($group, 'value');
        $value = $this->sanitizeValueBySource($value, $source, $operator);
        if (null === $value || (is_array($value) && empty($value))) {
            return false;
        }
        return $this->sanitizeFilterGroupForEluquentModel($source, $operator, $value);
    }

    protected function sanitizeValueBySource($value, $source, $operator)
    {
        $callback = null;
        $column = join('.', $source);
        if (in_array($column, $this->numericColumns)) {
            $callback = 'absint';
        } elseif ('entry-attribute.is_favourite' == $column) {
            $value = (int)Arr::isTrue(['is_favorites' => $value],'is_favorites');
        }
        return $this->sanitizeValue($value, $callback, $operator);
    }

    protected function sanitizeValue($value, $callback, $operator)
    {
        if (null === $value) {
            return null;
        }
        $value = $this->resolveValueForSanitize($value, $operator);
        if (is_array($value)) {
            if (is_callable($callback)) {
                $value = array_filter(array_map($callback, $value));
            } else {
                $value = array_filter(array_map(function ($v) {
                    return $this->sanitizeWithWPDB($v);
                }, $value));
            }
        } elseif (is_callable($callback)) {
            $value = $callback($value);
        } else {
            $value = $this->sanitizeWithWPDB($value);
        }
        return $value;
    }

    protected function resolveValueForSanitize($value, $operator)
    {
        switch ($operator) {
            case 'IN':
            case 'NOT IN':
                if (is_string($value)) {
                    $value = explode(',', $value);
                }
                // Guard against non-array inputs (null / scalar) reaching
                // array_map, which is a fatal TypeError in PHP 8+.
                if (!is_array($value)) {
                    $value = [];
                }
                $value = array_map('trim', $value);
                break;
            default:
                break;
        }
        return $value;
    }
    protected function sanitizeWithWPDB($value)
    {
        // We rely on the query builder's bindings to escape values, so all
        // we need here is normalization. The previous implementation called
        // $wpdb->prepare('%s', $value) and then trim()'d the surrounding
        // quotes back off, which would also strip a leading/trailing quote
        // that was part of the user's actual input. Keep it simple:
        // coerce to string and strip slashes that REST adds.
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return wp_unslash((string) $value);
    }

    protected function sanitizeFilterGroupForEluquentModel($source, $operator, $value)
    {
        global $wpdb;
        switch ($operator) {
            case 'contains':
            case 'doNotContains':
            case 'startsWith':
            case 'endsWith':
                if (is_array($value)) {
                    $value = join('', $value);
                }
                $value = $wpdb->esc_like($value);
                // LIKE 'X%' matches strings beginning with X (startsWith);
                // LIKE '%X' matches strings ending with X (endsWith).
                // The two were previously swapped, inverting both operators.
                if ('startsWith' === $operator) {
                    $value = $value . "%";
                } elseif ('endsWith' === $operator) {
                    $value = "%" . $value;
                } else {
                    $value = "%" . $value . "%";
                }
                $operator = 'doNotContains' === $operator ? 'NOT LIKE' : 'LIKE';
                break;
            default:
                break;
        }
        return [$source, $operator, $value];
    }


    protected function isValidGroup($group)
    {
        if (!$this->isSupportedColumn(Arr::get($group, 'source.1'))) {
            return false;
        }
        if (!$this->isValidOperator(Arr::get($group, 'operator'))) {
            return false;
        }
        return true;
    }

    protected function isValidOperator($operator)
    {
        return Arr::exists($this->getOperators(), $operator);
    }


    protected function isSupportedColumn($column)
    {
        return Arr::exists($this->supportedColumns, $column);
    }


    protected function applyFiltersQuery($query, $filters)
    {
        // Reset between calls. The class is instantiated once per request
        // (see fluentformpro.php) and held in memory; without this reset,
        // a second filter call in the same request would inherit stale
        // join aliases from the previous call and resolve them to the
        // wrong index in applyWhereConditions.
        $this->entryDetailsTableAlies = [];

        $entryDetailsJoinCount = 0;
        $hasUserJoin = false;
        // Determine the number of entry details joins required
        foreach ($filters as $groups) {
            if ($inputsConditions = Arr::get($groups, 'inputs')) {
                if ($entryDetailsJoinCount < count($inputsConditions)) {
                    $entryDetailsJoinCount = count($inputsConditions);
                }
            }
            if (Arr::isTrue($groups, 'user')) {
                $hasUserJoin = true;
            }
        }
        // Apply the necessary joins
        for ($i = 0; $i < $entryDetailsJoinCount; $i++) {
            $entryDetailsTableAlies = 'entry_details_' . $i;
            $this->entryDetailsTableAlies[] = $entryDetailsTableAlies;
            $query->leftJoin("fluentform_entry_details as {$entryDetailsTableAlies}", "{$entryDetailsTableAlies}.submission_id", '=', 'fluentform_submissions.id');
        }
        if ($hasUserJoin) {
            $query->leftJoin('users', 'users.ID', '=', 'fluentform_submissions.user_id');
        }

        // Apply filters
        // Wrap all filter groups in an outer where() so the OR conditions
        // are properly grouped. Without this, AND binds tighter than OR which causes
        // the OR groups to escape the form_id and status filters, returning entries
        // from other forms.
        $query->where(function ($outerQuery) use ($filters) {
            foreach ($filters as $index => $groups) {
                $method = 0 === $index ? 'where' : 'orWhere';
                $outerQuery->{$method}(function ($query) use ($groups) {
                    if ($inputsConditions = Arr::get($groups, 'inputs')) {
                        $this->applyWhereConditions($query, $inputsConditions, 'entryDetails');
                    }
                    if ($userConditions = Arr::get($groups, 'user')) {
                        $this->applyWhereConditions($query, $userConditions, 'user');
                    }
                    if ($entryAttrConditions = Arr::get($groups, 'entry-attribute')) {
                        $this->applyWhereConditions($query, $entryAttrConditions);
                    }
                    return $query;
                });
            }
        });

        // Only apply DISTINCT when there are joins that could produce duplicate
        // submission rows. Without joins, distinct just adds an unnecessary
        // sort/hash pass on every query.
        $hasJoins = $entryDetailsJoinCount > 0 || $hasUserJoin;
        if ($hasJoins) {
            $query->distinct()->select('fluentform_submissions.*');
        }
        return $query;
    }


    protected function applyWhereConditions($query, $conditions, $relationship = null)
    {
        if ($relationship) {
            foreach ($conditions as $index => $condition) {
                list($source, $operator, $value) = $condition;
                $column = $source[1];
                if ($relationship === 'entryDetails') {
                    // Resolve entry details join alies prefix
                    if ($entryDetailsTableAlias = Arr::get($this->entryDetailsTableAlies, $index)) {
                        $this->applyEntryDetailsQuery($query, $column, $operator, $value, $entryDetailsTableAlias);
                    }
                } elseif ($relationship === 'user') {
                    $this->applyCondition($query, 'users.' . $column, $operator, $value);
                }
            }
        } else {
            foreach ($conditions as $condition) {
                list($source, $operator, $value) = $condition;
                $column = 'fluentform_submissions.' . $source[1];
                $this->applyCondition($query, $column, $operator, $value);
            }
        }
    }

    protected function applyEntryDetailsQuery($query, $column, $operator, $value, $entryDetailsTableAlias)
    {
        foreach ($this->buildEntryDetailsWheres($column, $operator, $value) as $where) {
            if (Arr::get($where, 'format')) {
                $this->filterDates($query, $where, $entryDetailsTableAlias);
                continue;
            }
            $_column = Arr::get($where, 'column');
            $_value = Arr::get($where, 'value');
            $_operator = Arr::get($where, 'operator');
            // entry_details.field_value is TEXT and may hold non-numeric values,
            // so numeric comparisons must coerce via REGEXP + CAST.
            $isFieldValueColumn = ('field_value' === $_column);
            $this->applyCondition($query, "{$entryDetailsTableAlias}.{$_column}", $_operator, $_value, $isFieldValueColumn);
        }
    }

    protected function buildEntryDetailsWheres($column, $operator, $value)
    {
        $valueWhere = [
            'column' => 'field_value',
            'operator' => $operator,
            'value' => $value
        ];
        if ($field = Arr::get($this->supportedColumns, $column)) {
            if ($format = Arr::get($field, 'format')) {
                $dateInfo = [
                    'format' => $format,
                    'type' => Arr::get($field, 'type'),
                ];
                $valueWhere = array_merge($valueWhere, $dateInfo);
            }
        }
        $wheresGroup = [
            [
                'column' => 'field_name',
                'operator' => '=',
                'value' => $column,
            ],
            $valueWhere
        ];
        // Resolve sub_field_name
        if (preg_match('/\[(.*?)\]/', $column, $matches)) {
            $wheresGroup[] = [
                'column' => 'sub_field_name',
                'operator' => '=',
                'value' => $matches[1],
            ];
            $wheresGroup[0]['value'] = preg_replace('/\[(.*?)\]/', '', $column);
        }
        return $wheresGroup;
    }

    /**
     * Apply a single WHERE condition to the query.
     *
     * @param mixed  $query
     * @param string $column            Column name (may include table alias)
     * @param string $operator
     * @param mixed  $value
     * @param bool   $needsNumericCoercion When true, the column is a TEXT
     *               column (e.g. entry_details.field_value) that may hold
     *               non-numeric values. Numeric comparisons are wrapped in a
     *               REGEXP guard and a DECIMAL CAST so the DB does not error
     *               on rows with text values. For typed numeric columns
     *               (submission ids, user ids, etc.) this guard is wasteful
     *               and is skipped.
     */
    function applyCondition($query, $column, $operator, $value, $needsNumericCoercion = false)
    {
        if (is_array($value)) {
            if ('IN' === $operator) {
                $query->whereIn($column, $value);
            } elseif ('NOT IN' === $operator) {
                $query->whereNotIn($column, $value);
            } else {
                foreach ($value as $v) {
                    $query->where($column, $operator, $v);
                }
            }
            return;
        }

        $isNumericCompare = in_array($operator, ['=', '!=', '<', '>', '<=', '>=']) && is_numeric($value);

        if ($isNumericCompare && $needsNumericCoercion) {
            // $column already includes the join alias (e.g. entry_details_0.field_value).
            // The framework registers join aliases without the table prefix, so the raw
            // SQL must reference the alias as-is. Prepending $wpdb->prefix here would
            // produce wp_entry_details_0.field_value, which is an Unknown column error
            // and silently returns no rows for the entire filter.
            $query->where(function ($query) use ($column) {
                $query->whereRaw("{$column} REGEXP ?", ['^-?[0-9]+(\\.[0-9]+)?$']);
            })->where(function ($query) use ($column, $value, $operator) {
                $query->whereRaw("CAST({$column} AS DECIMAL(20,4)) {$operator} ?", [$value]);
            });
            return;
        }

        $query->where($column, $operator, $value);
    }

    protected function filterDates($query, $where, $entryDetailsTableAlias)
    {
        // The join alias is registered without the table prefix; raw SQL must
        // reference it as-is. Prepending $wpdb->prefix here would produce a
        // non-existent identifier like wp_entry_details_0.field_value.
        $dateFormats = $this->getDatesInfo();
        $format = Arr::get($where, 'format');
        if (!Arr::exists($dateFormats, $format)) {
            return $query;
        }
        $column = Arr::get($where, 'column');
        $column = "{$entryDetailsTableAlias}.{$column}";
        $operator = Arr::get($where, 'operator');
        $value = Arr::get($where, 'value');
        $regex = Arr::get($dateFormats, $format . '.regex');
        $mysqlDateFormat = Arr::get($dateFormats, $format . '.mysql_format');
        $dateInputFormat = 'time' === Arr::get($where, 'type') ? '%H:%i:%s' : '%Y-%m-%d %H:%i:%s';
        $query->where(function ($query) use ($column, $mysqlDateFormat, $regex, $dateInputFormat, $operator, $value) {
            // Use REGEXP to filter valid date/time strings
            $query->whereRaw("{$column} REGEXP ?", [$regex]);
            if (is_array($value) && count($value) === 2) {
                // Use BETWEEN or NOT BETWEEN for date ranges
                if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
                    $query->whereRaw(
                        "STR_TO_DATE({$column}, '{$mysqlDateFormat}') {$operator} STR_TO_DATE(?, '{$dateInputFormat}') AND STR_TO_DATE(?, '{$dateInputFormat}')",
                        [$value[0], $value[1]]
                    );
                }
            } elseif (is_string($value)) {
                // Use regular comparison for single date value
                $query->whereRaw(
                    "STR_TO_DATE({$column}, '{$mysqlDateFormat}') {$operator} STR_TO_DATE(?, '{$dateInputFormat}')",
                    [$value]
                );
            }
        });

        return $query;
    }


    public function getOperators()
    {
        return [
            '='             => __('Equal', 'fluentformpro'),
            '!='            => __('Not Equal', 'fluentformpro'),
            'IN'            => __('Equal In', 'fluentformpro'),
            'NOT IN'        => __('Not Equal In', 'fluentformpro'),
            '>'             => __('Greater Than', 'fluentformpro'),
            '<'             => __('Less Than', 'fluentformpro'),
            '>='            => __('Greater Than or Equal', 'fluentformpro'),
            '<='            => __('Less Than or Equal', 'fluentformpro'),
            'contains'      => __('Contains', 'fluentformpro'),
            'doNotContains' => __('Does Not Contain', 'fluentformpro'),
            'startsWith'    => __('Starts With', 'fluentformpro'),
            'endsWith'      => __('Ends With', 'fluentformpro'),
            'BETWEEN'       => __('Between', 'fluentformpro'),
            'NOT BETWEEN'   => __('Not Between', 'fluentformpro'),
        ];
    }

    protected function getDatesInfo()
    {
        return [
            'd/m/Y' => [
                'regex' => '^\d{2}/\d{2}/\d{4}$',
                'mysql_format' => '%d/%m/%Y',
                'type' => 'date'
            ],
            'm/d/Y' => [
                'regex' => '^\d{2}/\d{2}/\d{4}$',
                'mysql_format' => '%m/%d/%Y',
                'type' => 'date'
            ],
            'd.m.Y' => [
                'regex' => '^\d{2}\.\d{2}\.\d{4}$',
                'mysql_format' => '%d.%m.%Y',
                'type' => 'date'
            ],
            'm.d.Y' => [
                'regex' => '^\d{2}\.\d{2}\.\d{4}$',
                'mysql_format' => '%m.%d.%Y',
                'type' => 'date'
            ],
            'n/j/Y' => [
                'regex' => '^\d{1,2}/\d{1,2}/\d{4}$',
                'mysql_format' => '%c/%e/%Y',
                'type' => 'date'
            ],
            'm/d/y' => [
                'regex' => '^\d{2}/\d{2}/\d{2}$',
                'mysql_format' => '%m/%d/%y',
                'type' => 'date'
            ],
            'd/m/y' => [
                'regex' => '^\d{2}/\d{2}/\d{2}$',
                'mysql_format' => '%d/%m/%y',
                'type' => 'date'
            ],
            'M/d/Y' => [
                'regex' => '^[A-Za-z]{3}/\d{2}/\d{4}$',
                'mysql_format' => '%b/%d/%Y',
                'type' => 'date'
            ],
            'y/m/d' => [
                'regex' => '^\d{2}/\d{2}/\d{2}$',
                'mysql_format' => '%y/%m/%d',
                'type' => 'date'
            ],
            'Y-m-d' => [
                'regex' => '^\d{4}-\d{2}-\d{2}$',
                'mysql_format' => '%Y-%m-%d',
                'type' => 'date'
            ],
            'd-M-y' => [
                'regex' => '^\d{2}-[A-Za-z]{3}-\d{2}$',
                'mysql_format' => '%d-%b-%y',
                'type' => 'date'
            ],
            'm/d/Y h:i K' => [
                'regex' => '^\d{2}/\d{2}/\d{4} \d{1,2}:\d{2} [APap][Mm]$',
                'mysql_format' => '%m/%d/%Y %l:%i %p',
                'type' => 'datetime'
            ],
            'm/d/Y H:i' => [
                'regex' => '^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$',
                'mysql_format' => '%m/%d/%Y %H:%i',
                'type' => 'datetime'
            ],
            'd/m/Y h:i K' => [
                'regex' => '^\d{2}/\d{2}/\d{4} \d{1,2}:\d{2} [APap][Mm]$',
                'mysql_format' => '%d/%m/%Y %l:%i %p',
                'type' => 'datetime'
            ],
            'd/m/Y H:i' => [
                'regex' => '^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$',
                'mysql_format' => '%d/%m/%Y %H:%i',
                'type' => 'datetime'
            ],
            'd.m.Y h:i K' => [
                'regex' => '^\d{2}\.\d{2}\.\d{4} \d{1,2}:\d{2} [APap][Mm]$',
                'mysql_format' => '%d.%m.%Y %l:%i %p',
                'type' => 'datetime'
            ],
            'd.m.Y H:i' => [
                'regex' => '^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$',
                'mysql_format' => '%d.%m.%Y %H:%i',
                'type' => 'datetime'
            ],
            'h:i K' => [
                'regex' => '^\d{1,2}:\d{2} [APap][Mm]$',
                'mysql_format' => '%l:%i %p',
                'type' => 'time'
            ],
            'H:i' => [
                'regex' => '^\d{2}:\d{2}$',
                'mysql_format' => '%H:%i',
                'type' => 'time'
            ],
        ];
    }



    protected function supportedFields()
    {
        return [
            "input_name",
            "input_email",
            "input_text",
            "input_mask",
            "textarea",
            "address",
            "input_number",
            "select",
            "input_radio",
            "input_checkbox",
            "multi_select",
            "input_url",
            "input_date",
            "select_country",
            "custom_html",
            "ratings",
            "input_hidden",
            "terms_and_condition",
            "gdpr_agreement",
            'custom_payment_component',
            'multi_payment_component',
            'payment_method',
            'item_quantity_component',
            'rangeslider',
            'payment_coupon',
        ];
    }
}