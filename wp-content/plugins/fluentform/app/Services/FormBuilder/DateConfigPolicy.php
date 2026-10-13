<?php

namespace FluentForm\App\Services\FormBuilder;

use FluentForm\Framework\Helpers\ArrayHelper;

class DateConfigPolicy
{
    public static function preserveStored($fields, $storedFields = [])
    {
        $stored = [];
        self::walk($storedFields, function ($field) use (&$stored) {
            $key = (string) ArrayHelper::get($field, 'uniqElKey');
            if ('input_date' === ArrayHelper::get($field, 'element') && '' !== $key) {
                $stored[$key] = (string) ArrayHelper::get($field, 'settings.date_config');
            }

            return $field;
        });

        return self::walk($fields, function ($field) use ($stored) {
            if ('input_date' !== ArrayHelper::get($field, 'element')) {
                return $field;
            }
            $key = (string) ArrayHelper::get($field, 'uniqElKey');
            $field['settings']['date_config'] = $stored[$key] ?? '';

            return $field;
        });
    }

    public static function dropExecutableConfigs($fields, &$dropped = 0)
    {
        return self::walk($fields, function ($field) use (&$dropped) {
            if ('input_date' !== ArrayHelper::get($field, 'element')) {
                return $field;
            }
            $config = (string) ArrayHelper::get($field, 'settings.date_config');
            if ('' !== trim($config) && !self::isPlainJsonObject($config)) {
                $dropped++;
                $field['settings']['date_config'] = '';
            }

            return $field;
        });
    }

    // Strict JSON cannot carry functions, so it is safe to emit into the picker's script sink verbatim.
    private static function isPlainJsonObject($config)
    {
        $config = trim($config);
        if ('{' !== substr($config, 0, 1) || false !== strpos($config, '__ff_')) {
            return false;
        }
        $decoded = json_decode($config, true);

        return JSON_ERROR_NONE === json_last_error() && is_array($decoded);
    }

    private static function walk($fields, $callback)
    {
        if (!is_array($fields)) {
            return $fields;
        }

        foreach ($fields as &$field) {
            if (isset($field['columns']) && is_array($field['columns'])) {
                foreach ($field['columns'] as &$column) {
                    if (isset($column['fields'])) {
                        $column['fields'] = self::walk($column['fields'], $callback);
                    }
                }
                unset($column);
            }
            if (isset($field['fields'])) {
                $field['fields'] = self::walk($field['fields'], $callback);
            }
            $field = call_user_func($callback, $field);
        }
        unset($field);

        return $fields;
    }
}
