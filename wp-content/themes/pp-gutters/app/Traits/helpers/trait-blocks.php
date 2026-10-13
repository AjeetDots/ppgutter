<?php

namespace App\Helpers\Traits;

trait blocks
{
  public bool $is_editor_mode;

    /**
     * Gets the ACF field's property
     * If there isn't one and in editing mode a block placeholder will be used
     *
     * @param $field_property
     * @param $fields
     * @param $block
     * @return array|mixed
     */
    public function getFieldDataOrBlockPlaceholder($field_property, $fields, $block) : mixed
    {
        if (!$this->is_editor_mode) return $fields->$field_property;
        return $fields->$field_property ?? $block['example']['attributes']['data'][$field_property] ?? [];
    }

    /**
     * Gets the ACF field's property
     * If there isn't one and in editing mode a default value can be set
     *
     * @param $field_property
     * @param $fields
     * @param $default_data
     * @return array|mixed
     */
    public function getFieldDataOrSetDefault($field_property, $fields, array $default_data) : mixed
    {
        if (!$this->is_editor_mode) return $fields->$field_property;
        return $fields->$field_property ?: $default_data;
    }
}
