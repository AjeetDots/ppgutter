<?php

namespace FluentForm\App\Services\FormBuilder;

defined('ABSPATH') || die;

/**
 * Decodes date configuration tokens stored by Fluent Forms 6.2.7-6.2.12.
 *
 * This is intentionally render-only. New saves retain the submitted advanced
 * configuration verbatim, while forms saved by those releases keep working
 * without a migration or resave.
 */
class LegacyDateConfigDecoder
{
    const JS_STRING_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /**
     * Decode a legacy tokenized JSON object to executable Flatpickr config.
     *
     * Returns null when the value is not strict JSON or contains no exact
     * legacy token node, allowing callers to preserve the original value.
     *
     * @param string $json
     *
     * @return string|null
     */
    public static function decode($json)
    {
        if (!is_string($json) || '' === $json) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            return null;
        }

        $foundLegacyToken = false;
        $parts = [];

        foreach ($decoded as $key => $value) {
            $encodedKey = wp_json_encode((string) $key, self::JS_STRING_FLAGS);
            $rendered = in_array($key, ['defaultDate', 'minDate', 'maxDate', 'enable', 'disable'], true)
                ? self::renderOption($key, $value, $foundLegacyToken)
                : wp_json_encode($value, self::JS_STRING_FLAGS);
            $parts[] = $encodedKey . ': ' . $rendered;
        }

        $javascript = '{' . implode(', ', $parts) . '}';

        return $foundLegacyToken ? $javascript : null;
    }

    /**
     * Convert the restricted legacy expression tree to JavaScript.
     *
     * @param mixed $node
     *
     * @return string|null
     */
    private static function renderPredicate($node)
    {
        static $functions = ['getDay' => 1, 'getMonth' => 1, 'getDate' => 1, 'getFullYear' => 1];
        static $comparators = ['==' => 1, '===' => 1, '!=' => 1, '!==' => 1, '>=' => 1, '<=' => 1, '>' => 1, '<' => 1];

        if (!is_array($node)) {
            return null;
        }

        foreach (['or' => ' || ', 'and' => ' && '] as $operator => $glue) {
            if (isset($node[$operator])) {
                if (1 !== count($node) || !is_array($node[$operator]) || [] === $node[$operator]) {
                    return null;
                }

                $parts = [];
                foreach ($node[$operator] as $child) {
                    $javascript = self::renderPredicate($child);
                    if (null === $javascript) {
                        return null;
                    }
                    $parts[] = $javascript;
                }

                return '(' . implode($glue, $parts) . ')';
            }
        }

        if (3 !== count($node) || !isset($node['fn'], $node['cmp'], $node['val'])) {
            return null;
        }

        if (
            !is_string($node['fn']) ||
            !is_string($node['cmp']) ||
            !isset($functions[$node['fn']], $comparators[$node['cmp']])
        ) {
            return null;
        }

        return 'date.' . $node['fn'] . '() ' . $node['cmp'] . ' ' . (int) $node['val'];
    }

    /**
     * Convert a decoded legacy config node to JavaScript.
     *
     * @param string $option
     * @param mixed  $value
     * @param bool   $foundLegacyToken
     *
     * @return string
     */
    private static function renderOption($option, $value, &$foundLegacyToken)
    {
        if (in_array($option, ['defaultDate', 'minDate', 'maxDate'], true)) {
            if (is_array($value) && array_keys($value) === range(0, count($value) - 1)) {
                $parts = [];
                foreach ($value as $item) {
                    $rendered = self::renderToken($item, ['date', 'fp_incr'], $foundLegacyToken);
                    $parts[] = null === $rendered ? wp_json_encode($item, self::JS_STRING_FLAGS) : $rendered;
                }

                return '[' . implode(', ', $parts) . ']';
            }
            $rendered = self::renderToken($value, ['date', 'fp_incr'], $foundLegacyToken);

            return null === $rendered ? wp_json_encode($value, self::JS_STRING_FLAGS) : $rendered;
        }

        if (is_array($value) && array_keys($value) === range(0, count($value) - 1)) {
            $parts = [];
            foreach ($value as $item) {
                $rendered = self::renderOptionItem($item, $foundLegacyToken);
                $parts[] = null === $rendered ? wp_json_encode($item, self::JS_STRING_FLAGS) : $rendered;
            }

            return '[' . implode(', ', $parts) . ']';
        }

        $rendered = self::renderToken($value, ['date', 'fp_incr', 'disable_days', 'disable_expr'], $foundLegacyToken);

        return null === $rendered ? wp_json_encode($value, self::JS_STRING_FLAGS) : $rendered;
    }

    private static function renderOptionItem($item, &$foundLegacyToken)
    {
        $rendered = self::renderToken($item, ['date', 'fp_incr', 'disable_days', 'disable_expr'], $foundLegacyToken);
        if (null !== $rendered || !is_array($item) || [] === $item) {
            return $rendered;
        }

        $keys = array_keys($item);
        sort($keys);
        if (['from', 'to'] !== $keys) {
            return null;
        }

        $parts = [];
        foreach (['from', 'to'] as $key) {
            $value = self::renderToken($item[$key], ['date', 'fp_incr'], $foundLegacyToken);
            $parts[] = wp_json_encode($key, self::JS_STRING_FLAGS) . ': ' . (null === $value
                ? wp_json_encode($item[$key], self::JS_STRING_FLAGS)
                : $value);
        }

        return '{' . implode(', ', $parts) . '}';
    }

    private static function renderToken($node, array $allowed, &$foundLegacyToken)
    {
        if (!is_array($node) || 1 !== count($node)) {
            return null;
        }

        if (isset($node['__ff_fp_incr']) && in_array('fp_incr', $allowed, true)) {
            $foundLegacyToken = true;
            $days = (int) $node['__ff_fp_incr'];
            return $days ? "new Date().fp_incr({$days})" : 'new Date()';
        }

        if (isset($node['__ff_disable_days']) && in_array('disable_days', $allowed, true) && is_array($node['__ff_disable_days'])) {
            $foundLegacyToken = true;
            $days = implode(', ', array_map('intval', $node['__ff_disable_days']));
            return "function(date) { return [{$days}].indexOf(date.getDay()) !== -1; }";
        }

        if (isset($node['__ff_date']) && in_array('date', $allowed, true)) {
            $foundLegacyToken = true;
            $date = is_string($node['__ff_date']) ? $node['__ff_date'] : '';
            if (!preg_match('/^[0-9A-Za-z ,:.\\/\\-]{1,40}$/', $date)) {
                return 'null';
            }

            return 'new Date(' . wp_json_encode($date, self::JS_STRING_FLAGS) . ')';
        }

        if (isset($node['__ff_disable_expr']) && in_array('disable_expr', $allowed, true)) {
            $foundLegacyToken = true;
            $expression = self::renderPredicate($node['__ff_disable_expr']);
            return null === $expression ? 'null' : "function(date) { return {$expression}; }";
        }

        return null;
    }
}
