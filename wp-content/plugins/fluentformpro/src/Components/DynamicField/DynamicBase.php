<?php

namespace FluentFormPro\Components\DynamicField;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\App;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Database\Query\WPDBConnection;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentForm\Framework\Database\Query\Builder;


abstract class DynamicBase
{
    protected $config;

    // Identity the query is authorized against: the form owner on public
    // requests, the current user on admin requests. Null = use the current user.
    protected $effectiveAuthorId = null;

    /**
     * Populate Source Name
     * @var string
     */
    protected $source;

    /**
     * Global $wpdb
     * @var \wpdb
     */
    protected $wpdb;

    /**
     * The model associated with this object.
     *
     * @var Builder
     */
    protected $model;

    /**
     * The prefix used for filter keys.
     *
     * @var string
     */
    protected $filterPrefix;

    /**
     * The result of filters.
     *
     * @var array
     */
    protected $result = [];

    /**
     * Tables, maybe join.
     *
     * @var bool
     */
    protected $joinTables = [];

    /**
     * Constructor for the class.
     *
     * @param string $source The populate key.
     * @param string $tableName The table name for model.
     * @param array $config The configuration.
     * @return void
     */
    public function __construct($source, $tableName = '', $joins = [], $config = [])
    {
        $this->source = $source;
        $this->joinTables = $joins;
        $this->config = $config;
        /**
         * Database instance
         * @var $db WPDBConnection
         */
        $db = App::getInstance('db');
        $this->wpdb = $db->getWPDB();
        if ($tableName) {
            $this->model = $db->table($tableName);
        }
        $this->filterPrefix = "fluentform/dynamic_field_filter_{$this->source}";
    }


    /**
     * Get the selectable columns for the query.
     *
     * @return array
     */
    public abstract function selectableColumns();

    /**
     * Get the supported columns for the query.
     *
     * @return array
     */
    public abstract function getSupportedColumns();

    /**
     * Retrieve the default configuration settings.
     *
     * @return array
     */
    public abstract function getDefaultConfig();

    /**
     * Retrieve the value options.
     *
     * @return array
     */
    public abstract function getValueOptions();


    public function setEffectiveAuthorId($authorId)
    {
        $this->effectiveAuthorId = $authorId;
    }

    protected function resolveAuthorId()
    {
        return $this->effectiveAuthorId !== null ? (int) $this->effectiveAuthorId : get_current_user_id();
    }

    protected function authorCan($capability)
    {
        $authorId = $this->resolveAuthorId();

        return $authorId ? user_can($authorId, $capability) : false;
    }

    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * Magic method to access object properties dynamically.
     *
     * @param string $name
     * @return mixed|null The value of the property if it exists, otherwise null.
     */
    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->{$name};
        }
        return null;
    }

    /**
     * Retrieves the result data including result counts and valid options.
     *
     * @return array The result data including total counts, valid counts, valid options, and all options.
     */
    public function getResult()
    {
        $this->setResult();
        $validOptions = $this->getAdvanceOptions();
        return [
            'result_counts' => [
                'total' => count($this->result),
                'valid' => count($validOptions),
            ],
            'valid_options' => $validOptions,
            'all_options'   => $this->result,
        ];
    }


    /**
     * Sets the result based on the configured filters.
     *
     * @return void
     */
    public function setResult()
    {
        // Prepare filter groups
        $filters = $this->formatAndSanitizeFilters();

        // Clone the model to ensure a fresh instance
        $query = clone $this->model;

        $select = $this->selectableColumns();
        // Adjust select and join tables if join tables filter present
        foreach ($this->joinTables as $table) {
            if (Arr::isTrue($table, 'enable') && $joinInfo = Arr::get($table, 'join')) {
                $select = array_merge($select, Arr::get($table, 'columns', []));
                list ($table, $one, $operator, $two) = $joinInfo;
                $query->join($table, $one, $operator, $two);
            }
        }

        // Apply filter groups
        $this->applyFiltersQuery($filters, $query);

        // Apply sorting, ordering, and limit
        $this->applySortingOrderAndLimit($query);

        // Execute the query
        $result = $query->get($select);
        $this->result = $result ?: [];
    }

    /**
     * Apply the filters to the query.
     *
     * This method applies the given filters to the query using dynamic WHERE clauses.
     *
     * @param array $filters The filters to apply.
     * @return void
     */
    protected function applyFiltersQuery($filters, $query)
    {
        $query->where(function ($query) use ($filters) {
            foreach ($filters as $groups) {
                $query->orWhere(function ($query) use ($groups) {
                    foreach ($groups as $group) {
                        list ($column, $operator, $value) = $group;
                        if (is_array($value)) {
                            if ('IN' === $operator) {
                                $query->whereIn($column, $value);
                            } elseif ('NOT IN' === $operator) {
                                $query->whereNotIn($column, $value);
                            } elseif ('BETWEEN' === $operator) {
                                $query->whereBetween($column, $value);
                            } elseif ('NOT BETWEEN' === $operator) {
                                $query->whereNotBetween($column, $value);
                            }
                        } else {
                            $query->where($column, $operator, $value);
                        }
                    }
                });
            }
        });
    }

    /**
     * Apply sorting, ordering, and limit clauses to the query based on the configuration.
     *
     * @return void
     */
    protected function applySortingOrderAndLimit($query)
    {
        $defaultConfig = $this->getDefaultConfig();
        $defaultSortBy = Arr::get($defaultConfig, 'sort_by');

        // Ensure unique results by ID or term_id if enable
        $uniqueResult = Arr::get($this->config, 'unique_result');
        if ('yes' === $uniqueResult && $defaultSortBy) {
            $query->groupBy($defaultSortBy);
        }

        // Sort by column
        $sortBy = Arr::get($this->config, 'sort_by', $defaultSortBy);
        if (!$this->isSupportedColumn($sortBy)) {
            $sortBy = $defaultSortBy;
        }

        // If sorting by a join table column and there is no join table filter, use the default sort by column
        foreach ($this->joinTables as $table) {
            if (!Arr::isTrue($table, 'enable') && in_array($sortBy, Arr::get($table, 'columns'))) {
                $sortBy = $defaultSortBy;
                break;
            }
        }
        // Order by
        $defaultOrderBy = Arr::get($defaultConfig, 'order_by');
        $orderBy = Arr::get($this->config, 'order_by', $defaultOrderBy);
        if (!in_array($orderBy, ['ASC', 'DESC'])) {
            $orderBy = $defaultOrderBy;
        }

        // Result Limit
        $limit = intval(Arr::get($this->config, 'result_limit', 0));
        if (!$limit) {
            $limit = $this->getResultLimit();
        }
        $query->orderBy($sortBy, $orderBy)->limit($limit);
    }

    /**
     * Format and sanitize the filters based on the configuration.
     *
     * This method prepares the filter groups, sanitizes them, and update tables join.
     *
     * @return array The formatted and sanitized filters.
     */
    protected function formatAndSanitizeFilters()
    {
        if ('basic' === Arr::get($this->config, 'query_type')) {
            $filters = apply_filters("fluentform/dynamic_field_basic_filters_{$this->source}", [], Arr::get($this->config, 'basic_query', []));
        } else {
            $filters = Arr::get($this->config, 'filters', []);
        }
        // Use default filters if not has a valid filter
        if (empty($filters)) {
            $filters = Arr::get($this->getDefaultConfig(), 'filters', []);
        }

        $formattedFilters = [];
        foreach ($filters as $groupsIndex => $groups) {
            if (!is_array($groups)) {
                continue;
            }
            foreach ($groups as $group) {
                if ($group = $this->sanitizeFilterGroup($group)) {
                    list ($column) = $group;
                    $this->maybeEnableJoinTablesByColumn($column);
                    $formattedFilters[$groupsIndex][] = $group;
                }
            }
        }
        return $formattedFilters;
    }

    /**
     * Maybe Enable join tables base on filter group column.
     *
     * @param string $column The filter group column.
     */
    protected function maybeEnableJoinTablesByColumn($column)
    {
        foreach ($this->joinTables as &$join) {
            if (!Arr::isTrue($join, 'enable') && in_array($column, Arr::get($join, 'columns'))) {
                $join['enable'] = true;
            }
        }
    }

    /**
     * Sanitizes the provided filter group.
     *
     * @param array $group The filter group to sanitize.
     * @return array|false The sanitized filter group, or false if the group is not valid or cannot be sanitized.
     */
    protected function sanitizeFilterGroup($group)
    {
        if (!$this->isValidGroup($group)) {
            return false;
        }
        $column = Arr::get($group, 'column');
        $operator = Arr::get($group, 'operator');
        $value = Arr::get($group, 'value');
        $value = $this->sanitizeValueByColumn($value, $column, $operator);
        if (null === $value || (is_array($value) && empty($value))) {
            return false;
        }
        return $this->sanitizeFilterGroupForEluquentModel($column, $operator, $value);
    }

    /**
     * Checks if the provided group is valid.
     *
     * @param array $group The group to check.
     * @return bool True if the group is valid, false otherwise.
     */
    protected function isValidGroup($group)
    {
        if (!$this->isSupportedColumn(Arr::get($group, 'column'))) {
            return false;
        }
        if (!$this->isValidOperator(Arr::get($group, 'operator'))) {
            return false;
        }
        return true;
    }

    /**
     * Checks if the provided column is supported.
     *
     * @param string $column
     * @return bool
     */
    protected function isSupportedColumn($column)
    {
        if (!$column) {
            return false;
        }
        return Arr::exists($this->getSupportedColumns(), $column);
    }

    /**
     * Checks if the provided operator is valid.
     *
     * @param string $operator The operator to check.
     * @return bool True if the operator is valid, false otherwise.
     */
    protected function isValidOperator($operator)
    {
        if (!$operator) {
            return false;
        }
        return Arr::exists(DynamicFieldHelper::getOperators(), $operator);
    }


    /**
     * Sanitizes a value based on the column and operator.
     *
     * This method determines the appropriate sanitization callback function based on the column,
     * and then sanitizes the value using the provided callback function or the WordPress database prepare method.
     *
     * @param mixed $value The value to sanitize.
     * @param string $column The column name.
     * @param string $operator The operator used for sanitization.
     * @return mixed The sanitized value.
     */
    protected function sanitizeValueByColumn($value, $column, $operator)
    {
        $callback = null;
        if (in_array($column, DynamicFieldHelper::numericColumns())) {
            $callback = 'absint';
        } elseif (in_array($column, DynamicFieldHelper::dateColumns())) {
            $value = $this->sanitizeDate($value);
        } elseif ('is_favourite' == $column) {
            $value = (int)Arr::isTrue(['is_favorites' => $value],'is_favorites');
        }
        return $this->sanitizeValue($value, $callback, $operator);
    }

    protected function sanitizeDate($date)
    {
        if (is_array($date)) {
            foreach ($date as $i => &$d) {
                // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
                $d = date('Y-m-d H:i:s', strtotime($d));
                if (0 !== $i) {
                    $d = str_replace('00:00:00', '23:59:59', $d);
                }
            }
        } else {
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Local timezone intended
            $date = date('Y-m-d H:i:s', strtotime($date));
        }
        return $date;
    }

    /**
     * Sanitizes a value based on a callback function or the WordPress database prepare method.
     *
     * @param mixed $value The value to sanitize.
     * @param callable|null $callback The callback function to apply to the value.
     * @param string $operator The operator used for sanitization.
     * @return mixed The sanitized value or null if the input value is null.
     */
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

    /**
     * Resolves the value for sanitization based on the provided operator.
     *
     * @param mixed $value The value to resolve for sanitization.
     * @param string $operator The operator to determine how the value should be resolved.
     * @return mixed The resolved value for sanitization.
     */
    protected function resolveValueForSanitize($value, $operator)
    {
        switch ($operator) {
            case 'IN':
            case 'NOT IN':
                if (is_string($value)) {
                    $value = explode(',', $value);
                }
                $value = array_map('trim', $value);
                break;
            default:
                break;
        }
        return $value;
    }


    /**
     * Sanitizes a value using the WordPress database prepare method.
     * It also trims leading and trailing quotes from the sanitized value.
     *
     * @param mixed $value The value to sanitize.
     * @return string|null The sanitized value or null if the input value is null.
     */
    protected function sanitizeWithWPDB($value)
    {
        $value = $this->wpdb->prepare('%s', $value);
        if ($value) {
            // Trim leading and trailing quotes
            $value = trim($value, "'\"");
        }
        return $value;
    }


    /**
     * Sanitizes a filter group for an Eloquent model query.
     *
     * @param string $column The column to filter on.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return array An array containing the sanitized column, operator, and value.
     */
    protected function sanitizeFilterGroupForEluquentModel($column, $operator, $value)
    {
        switch ($operator) {
            case 'contains':
            case 'doNotContains':
            case 'startsWith':
            case 'endsWith':
                if (is_array($value)) {
                    $value = join('', $value);
                }
                $value = $this->wpdb->esc_like($value);
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
        return [$column, $operator, $value];
    }

    /**
     * Get advance options based on template mapping.
     *
     * @return array
     */
    public function getAdvanceOptions()
    {
        $value = sanitize_text_field(Arr::get($this->config, 'template_value.value', ''));
        $label = sanitize_text_field(Arr::get($this->config, 'template_label.value', ''));
        if (!$value || !$this->result) {
            return [];
        }
        $validOptions = [];
        $uniqueValueSet = [];

        foreach ($this->result as $index => $result) {
            $response = [];
            if (isset($result->response) && Helper::isJson($result->response)) {
                $response = \json_decode($result->response, true);
                unset($result->response);
            }
            // Replace placeholders in value
            $valueResult = $this->replacePlaceholders($value, $result, $response);
            if (!is_string($value)) {
                continue;
            }
            $valueResult = esc_attr($valueResult);
            if (!$valueResult) {
                continue;
            }

            // Replace placeholders in label
            $labelResult = $this->replacePlaceholders($label, $result, $response);

            // Use value result as label if label result is not a string or empty
            if (!is_string($labelResult)) {
                $labelResult = $valueResult;
            }
            $labelResult = esc_html($labelResult);
            if (!$labelResult) {
                $labelResult = $valueResult;
            }

            // Check for duplicate values and skip if found
            if (in_array($valueResult, $uniqueValueSet)) {
                continue;
            }
            $validOptions[] = ['id' => $index, 'label' => $labelResult, 'value' => $valueResult];
            $uniqueValueSet[] = $valueResult;
        }

        return $validOptions;
    }

    /**
     * Replaces placeholders in a string with corresponding values from a object.
     *
     * @param string $string The string containing placeholders.
     * @param object $obj The object containing values to replace placeholders.
     * @param array $response The submission response array.
     * @return string The string with placeholders replaced by corresponding values.
     */
    protected function replacePlaceholders($string, $obj, $response = [])
    {
        static $advanceOptions = null;
        if (!$advanceOptions && false !== strpos($string, 'option_label')) {
            $formId = Arr::get($this->config, 'basic_query.form_id');
            $fieldName = Arr::get($this->config, 'basic_query.form_field');
            if ($form = Helper::getForm($formId)) {
                $inputs = FormFieldsParser::getField($form, ['input_radio', 'input_checkbox', 'multi_payment_component', 'select'], $fieldName, ['options']);
                if ($inputs) {
                    $advanceOptions = Arr::get($inputs, $fieldName . '.options');
                }
            }
        }

        return preg_replace_callback('/\{([\w.]+)\}/', function ($matches) use ($obj, $response, $advanceOptions) {
            $value = isset($obj->{$matches[1]}) ? trim($obj->{$matches[1]}) : '';
            // resolve {option_label} value by response
            if ('option_label' == $matches[1] && isset($obj->field_value)) {
                if ($advanceOptions && $label = Arr::get($advanceOptions, $obj->field_value)) {
                    $value = $label;
                }
            }

            // resolve {inputs.field_name} value by response
            if (false !== strpos($matches[1], 'inputs.')) {
                $name = substr($matches[1], strlen('inputs.'));
                $name = isset($obj->{$name}) ? trim($obj->{$name}) : $name;
                if ($name && isset($response[$name])) {
                    if (is_array($response[$name])) {
                        $value = fluentImplodeRecursive(' ', $response[$name]);
                    } elseif (is_string($response[$name])) {
                        $value = $response[$name];
                    }
                }
            }
            return $value;
        }, $string);
    }


    /**
     * Gets the maximum number of editor value options allowed.
     *
     * @return int The maximum number of editor value options allowed.
     */
    protected function getEditorValueOptionsLimit()
    {
        return apply_filters("{$this->filterPrefix}_editor_value_options_limit", 200);
    }


    /**
     * Gets the maximum number of result allowed in the query.
     *
     * @return int The maximum number of result allowed in the query, default 500.
     */
    protected function getResultLimit()
    {
        return apply_filters("{$this->filterPrefix}_record_limit", 500);
    }
}