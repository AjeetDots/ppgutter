<?php

namespace FluentFormPro\classes;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ConditionalContent
{
    private static $entryId;
    private static $formData;
    private static $form;

    public static function handle($atts, $content)
    {
        $atts['to'] = html_entity_decode($atts['to']);
        $default = [
            'field' => '',
            'is'    => '',
            'to'    => ''
        ];
        $default = apply_filters_deprecated(
            'fluentform_conditional_shortcode_defaults',
            [
                $default,
                $atts
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/conditional_shortcode_defaults',
            'Use fluentform/conditional_shortcode_defaults instead of fluentform_conditional_shortcode_defaults.'
        );
        $shortcodeDefaults = apply_filters('fluentform/conditional_shortcode_defaults', $default, $atts);

        extract(shortcode_atts($shortcodeDefaults, $atts));

        if(!$field || !$is || !isset($to)) {
            return '';
        }

        $operatorMaps = [
            'equal' => '=',
            'not_equal' => '!=',
            'greater_than' => '>',
            'less_than' => '<',
            'greater_or_equal' => '>=',
            'less_or_equal' => '<=',
            'starts_with' => 'startsWith',
            'ends_with' => 'endsWith',
            'contains' => 'contains',
            'not_contains' => 'doNotContains'
        ];

        if(!isset($operatorMaps[$is])) {
            return '';
        }

        $is = $operatorMaps[$is];

        $condition = [
            'conditionals' => [
                'status'     => true,
                'type'       => 'any',
                'conditions' => [
                    [
                        "field"    => $field,
                        "operator" => $is,
                        "value"    => $to
                    ]
                ]
            ]
        ];

        if (\FluentForm\App\Services\ConditionAssesor::evaluate($condition, static::$formData)) {
            $content = apply_filters('fluentform/conditional_content', $content, static::$formData, static::$form);
            return do_shortcode($content);
        }

        return '';
    }


    public static function initiate($content, $entryId, $formData, $form)
    {
        if(!has_shortcode($content, 'ff_if') || !$content) {
            return $content;
        }

        static::$entryId = $entryId;
        static::$formData = $formData;

        static::$form = $form;
        return do_shortcode($content);
    }
}
