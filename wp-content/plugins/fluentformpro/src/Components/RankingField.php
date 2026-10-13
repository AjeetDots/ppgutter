<?php

namespace FluentFormPro\Components;

if (!defined('ABSPATH')) {
    exit;
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class RankingField extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'input_ranking',
            __('Ranking Field', 'fluentformpro'),
            ['ranking', 'rank', 'sortable', 'order', 'preference'],
            'advanced'
        );

        add_filter('fluentform/editor_init_element_' . $this->key, [$this, 'initEditorElement']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        add_filter('fluentform/response_render_' . $this->key, [$this, 'renderResponse'], 10, 4);
    }

    public function registerAssets()
    {
        wp_register_script(
            'fluentform-ranking-field',
            FLUENTFORMPRO_DIR_URL . 'public/js/ff_ranking.js',
            ['jquery'],
            FLUENTFORMPRO_VERSION,
            true
        );

        wp_register_style(
            'fluentform-ranking-field',
            FLUENTFORMPRO_DIR_URL . 'public/css/ff_ranking.css',
            [],
            FLUENTFORMPRO_VERSION
        );
    }

    public function getComponent()
    {
        return [
            'index'          => 22,
            'element'        => $this->key,
            'attributes'     => [
                'name'  => $this->key,
                'class' => '',
                'value' => [],
            ],
            'settings'       => [
                'label'              => __('Ranking Field', 'fluentformpro'),
                'admin_field_label'  => '',
                'help_message'       => '',
                'container_class'    => '',
                'label_placement'    => '',
                'ranking_display_type' => 'list',
                'ranking_grid_columns' => '3',
                'show_reset_icon'   => 'no',
                'show_position_serial' => 'no',
                'accent_color'      => '',
                'values_visible'    => false,
                'advanced_options'   => [
                    [
                        'label'      => __('Option 1', 'fluentformpro'),
                        'value'      => 'Option 1',
                        'calc_value' => '',
                        'image'      => '',
                    ],
                    [
                        'label'      => __('Option 2', 'fluentformpro'),
                        'value'      => 'Option 2',
                        'calc_value' => '',
                        'image'      => '',
                    ],
                    [
                        'label'      => __('Option 3', 'fluentformpro'),
                        'value'      => 'Option 3',
                        'calc_value' => '',
                        'image'      => '',
                    ],
                ],
                'enable_image_input' => false,
                'randomize_options'  => 'no',
                'validation_rules'   => [
                    'required' => [
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ]
                ],
                'conditional_logics' => []
            ],
            'editor_options' => [
                'title'      => __('Ranking Field', 'fluentformpro'),
                'icon_class' => 'dashicons dashicons-sort',
                'template'   => 'rankingField'
            ],
        ];
    }

    public function initEditorElement($item)
    {
        if (!isset($item['settings']['advanced_options']) || !is_array($item['settings']['advanced_options'])) {
            $item['settings']['advanced_options'] = ArrayHelper::get($this->getComponent(), 'settings.advanced_options', []);
        }

        if (!isset($item['settings']['enable_image_input'])) {
            $item['settings']['enable_image_input'] = false;
        }

        if (!isset($item['settings']['randomize_options'])) {
            $item['settings']['randomize_options'] = 'no';
        }

        if (!isset($item['settings']['ranking_display_type'])) {
            $item['settings']['ranking_display_type'] = 'list';
        }

        if (!isset($item['settings']['ranking_grid_columns'])) {
            $item['settings']['ranking_grid_columns'] = '3';
        }

        if (!isset($item['settings']['show_reset_icon'])) {
            $item['settings']['show_reset_icon'] = 'no';
        }

        if (!isset($item['settings']['show_position_serial'])) {
            $item['settings']['show_position_serial'] = 'no';
        }

        if (!isset($item['settings']['accent_color'])) {
            $item['settings']['accent_color'] = '';
        }

        if (!isset($item['settings']['values_visible'])) {
            $item['settings']['values_visible'] = false;
        }

        return $item;
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'admin_field_label',
            'ranking_display_type',
            'ranking_grid_columns',
            'show_position_serial',
            'accent_color',
            'show_reset_icon',
            'advanced_options',
            'randomize_options',
            'label_placement',
            'validation_rules',
        ];
    }

    public function generalEditorElement()
    {
        return [
            'ranking_display_type' => [
                'template'  => 'radio',
                'label'     => __('Ranking Layout', 'fluentformpro'),
                'help_text' => __('Choose whether ranking items should be displayed in a vertical list or a multi-column grid.', 'fluentformpro'),
                'options'   => [
                    [
                        'value' => 'list',
                        'label' => __('List Ranking', 'fluentformpro')
                    ],
                    [
                        'value' => 'grid',
                        'label' => __('Grid Ranking', 'fluentformpro')
                    ]
                ]
            ],
            'ranking_grid_columns' => [
                'template'   => 'select',
                'label'      => __('Grid Columns', 'fluentformpro'),
                'help_text'  => __('Select how many columns should be used when Grid Ranking is enabled.', 'fluentformpro'),
                'options'    => [
                    ['label' => '2', 'value' => '2'],
                    ['label' => '3', 'value' => '3'],
                    ['label' => '4', 'value' => '4'],
                    ['label' => '5', 'value' => '5'],
                    ['label' => '6', 'value' => '6']
                ],
                'dependency' => [
                    'depends_on' => 'settings/ranking_display_type',
                    'value'      => 'grid',
                    'operator'   => '=='
                ]
            ],
            'show_position_serial' => [
                'template'  => 'inputYesNoCheckBox',
                'label'     => __('Show Position Number', 'fluentformpro'),
                'help_text' => __('Display the rank number badge on each option. Turn off for a label-only layout. The rank stays in the DOM for screen readers either way.', 'fluentformpro')
            ],
            'accent_color' => [
                'template'  => 'inputColor',
                'label'     => __('Accent Color', 'fluentformpro'),
                'help_text' => __('Tints the rank badge and move-button hover/focus. Leave empty to use the form primary color. Only meaningful while the position number is visible.', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/show_position_serial',
                    'value'      => 'yes',
                    'operator'   => '=='
                ]
            ],
            'show_reset_icon' => [
                'template'  => 'inputYesNoCheckBox',
                'label'     => __('Show Reset Icon', 'fluentformpro'),
                'help_text' => __('Show a reset control beside the field label so users can restore the original ranking order.', 'fluentformpro')
            ]
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'name',
            'help_message',
            'container_class',
            'class',
            'conditional_logics',
        ];
    }

    public function render($data, $form)
    {
        wp_enqueue_script('fluentform-ranking-field');
        wp_enqueue_style('fluentform-ranking-field');

        $elementName = $data['element'];

        $data = apply_filters_deprecated(
            'fluentform_rendering_field_data_' . $elementName,
            [
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_data_' . $elementName,
            'Use fluentform/rendering_field_data_' . $elementName . ' instead of fluentform_rendering_field_data_' . $elementName
        );

        $data = apply_filters('fluentform/rendering_field_data_' . $elementName, $data, $form);
        $options = $this->resolveDisplayOptions($data);
        $fieldName = ArrayHelper::get($data, 'attributes.name');

        $attributes = ArrayHelper::get($data, 'attributes', []);
        $rankingDisplayType = ArrayHelper::get($data, 'settings.ranking_display_type', 'list');
        $rankingDisplayType = in_array($rankingDisplayType, ['list', 'grid'], true) ? $rankingDisplayType : 'list';
        $rankingGridColumns = (int) ArrayHelper::get($data, 'settings.ranking_grid_columns', 3);
        if ($rankingGridColumns < 2 || $rankingGridColumns > 6) {
            $rankingGridColumns = 3;
        }
        $data['settings']['container_class'] = trim(
            ArrayHelper::get($data, 'settings.container_class', '') . ' ff-ranking-field-container'
        );
        $attributes['id'] = $this->makeElementId($data, $form);
        $attributes['class'] = trim(
            'ff-ranking-field ff-ranking-field--' . $rankingDisplayType .
            ' ff-ranking-field--grid-cols-' . $rankingGridColumns . ' ' .
            ArrayHelper::get($attributes, 'class', '')
        );
        $attributes['data-name'] = $fieldName;
        $attributes['data-type'] = 'ranking';
        $attributes['data-ranking-required'] = ArrayHelper::isTrue($data, 'settings.validation_rules.required.value') ? 'yes' : 'no';
        $attributes['data-randomized'] = ArrayHelper::get($data, 'settings.randomize_options', 'no');
        $attributes['data-ranking-layout'] = $rankingDisplayType;
        $attributes['data-ranking-columns'] = $rankingGridColumns;
        $attributes['data-ranking-required'] = ArrayHelper::get($data, 'settings.validation_rules.required.value') ? 'yes' : 'no';
        $attributes['data-ranking-submit-default'] = ArrayHelper::get($data, 'settings.require_interaction_for_submission') === 'yes' ? 'no' : 'yes';
        $style = ArrayHelper::get($attributes, 'style', '') . ';--ff-ranking-grid-columns:' . $rankingGridColumns . ';';
        $accentColor = trim((string) ArrayHelper::get($data, 'settings.accent_color', ''));
        if ($accentColor !== '' && $this->isSafeColorValue($accentColor)) {
            $style .= '--ff-ranking-accent:' . $accentColor . ';';
        }
        $attributes['style'] = trim($style);
        $showPositionSerial = ArrayHelper::get($data, 'settings.show_position_serial', 'yes') !== 'no';
        unset($attributes['value'], $attributes['name']);

        if ($tabIndex = Helper::getNextTabIndex()) {
            $attributes['tabindex'] = $tabIndex;
        }

        $wrapperAttributes = $this->buildAttributes($attributes, $form);
        $content = '';

        foreach ($options as $index => $option) {
            $value = (string) ArrayHelper::get($option, 'value');
            $label = (string) ArrayHelper::get($option, 'label', $value);
            $image = ArrayHelper::get($option, 'image');

            $itemClass = $image ? 'ff-ranking-field__item--has-image' : 'ff-ranking-field__item--no-image';
            $content .= '<div class="ff-ranking-field__item ' . esc_attr($itemClass) . '" draggable="true" data-ranking-value="' . esc_attr($value) . '" data-ranking-initial-index="' . esc_attr($index) . '">';
            $content .= '<div class="ff-ranking-field__item-main">';
            // Always emit the rank in the DOM so screen readers can
            // still announce position; the modifier hides it
            // visually when the builder opts out of the badge.
            $positionClass = $showPositionSerial
                ? 'ff-ranking-field__position'
                : 'ff-ranking-field__position ff-ranking-field__position--visually-hidden';
            $content .= '<span class="' . esc_attr($positionClass) . '">' . esc_html($index + 1) . '</span>';
            if ($image) {
                $content .= '<span class="ff-ranking-field__image"><img src="' . esc_url($image) . '" alt="' . esc_attr($label) . '"></span>';
            }
            $content .= '<span class="ff-ranking-field__label">' . esc_html($label) . '</span>';
            $content .= '</div>';
            $chevronUp = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="3 8 6 5 9 8"/></svg>';
            $chevronDown = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="3 5 6 8 9 5"/></svg>';
            $upDisabled = $index === 0 ? ' disabled="disabled"' : '';
            $downDisabled = $index === count($options) - 1 ? ' disabled="disabled"' : '';
            $content .= '<div class="ff-ranking-field__actions">';
            /* translators: %1$s is the ranking option label, e.g. "Move Option 1 up". */
            $upLabel = sprintf(__('Move %1$s up', 'fluentformpro'), $label);
            /* translators: %1$s is the ranking option label, e.g. "Move Option 1 down". */
            $downLabel = sprintf(__('Move %1$s down', 'fluentformpro'), $label);
            if (false === $upLabel) {
                $upLabel = __('Move up', 'fluentformpro');
            }
            if (false === $downLabel) {
                $downLabel = __('Move down', 'fluentformpro');
            }
            $content .= '<button type="button"' . $upDisabled . ' class="ff-ranking-field__move ff-ranking-field__move--up" aria-label="' . esc_attr($upLabel) . '">' . $chevronUp . '</button>';
            $content .= '<button type="button"' . $downDisabled . ' class="ff-ranking-field__move ff-ranking-field__move--down" aria-label="' . esc_attr($downLabel) . '">' . $chevronDown . '</button>';
            $content .= '</div>';
            $content .= '<span class="ff-ranking-field__handle" aria-hidden="true">&#8942;</span>';
            $isRequired = ArrayHelper::isTrue($data, 'settings.validation_rules.required.value');
            $hiddenValue = $isRequired ? '' : $value;
            $disabled = $isRequired ? ' disabled="disabled"' : '';
            $content .= '<input type="hidden" name="' . esc_attr($fieldName) . '[]" value="' . esc_attr($hiddenValue) . '" data-ranking-input="1" data-ranking-hidden-value="' . esc_attr($value) . '"' . $disabled . '>';
            $content .= '</div>';
        }

        $elMarkup = '<div ' . $wrapperAttributes . '>' . $content . '</div>';
        $html = $this->buildElementMarkup($elMarkup, $data, $form);
        if (ArrayHelper::get($data, 'settings.show_reset_icon') === 'yes') {
            $html = $this->injectResetButton($html);
        }

        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function injectResetButton($html)
    {
        $label = esc_html__('Reset order', 'fluentformpro');
        $icon = '<svg class="ff-ranking-field__reset-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>';
        $button = '<button type="button" class="ff-ranking-field__reset">' .
            $icon . '<span class="ff-ranking-field__reset-text">' . $label . '</span></button>';

        return preg_replace('/<\/label>/', '</label>' . $button, $html, 1);
    }

    public function renderResponse($response, $field, $formId, $isHtml = false)
    {
        if (!$response) {
            return $response;
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $response = $decoded;
            }
        }

        if (empty($response)) {
            return '';
        }

        $response = is_array($response) ? array_values($response) : [$response];
        $availableOptions = ArrayHelper::get($field, 'raw.settings.advanced_options', ArrayHelper::get($field, 'settings.advanced_options', []));
        if (!$availableOptions) {
            $legacyOptions = ArrayHelper::get($field, 'raw.options', ArrayHelper::get($field, 'options', []));
            foreach ($legacyOptions as $value => $label) {
                $availableOptions[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        if (method_exists(Helper::class, 'flattenAdvancedOptions')) {
            $availableOptions = Helper::flattenAdvancedOptions($availableOptions);
        }
        $options = [];

        foreach ($availableOptions as $option) {
            $value = (string) ArrayHelper::get($option, 'value');
            if ($value === '') {
                continue;
            }

            $options[$value] = [
                'label' => (string) ArrayHelper::get($option, 'label', $value),
                'image' => ArrayHelper::get($option, 'image'),
            ];
        }

        $items = [];

        foreach ($response as $index => $value) {
            $value = sanitize_text_field($value);
            if ($value === '') {
                continue;
            }

            $option = ArrayHelper::get($options, $value, []);
            $items[] = [
                'index' => $index + 1,
                'label' => (string) ArrayHelper::get($option, 'label', $value),
                'image' => ArrayHelper::get($option, 'image'),
            ];
        }

        if (!$items) {
            return '';
        }

        $shouldReturnHtml = $isHtml || (defined('FLUENTFORM_RENDERING_ENTRIES') && FLUENTFORM_RENDERING_ENTRIES);

        if ($shouldReturnHtml) {
            $rankingDisplayType = ArrayHelper::get($field, 'raw.settings.ranking_display_type', ArrayHelper::get($field, 'settings.ranking_display_type', 'list'));
            $rankingDisplayType = in_array($rankingDisplayType, ['list', 'grid'], true) ? $rankingDisplayType : 'list';
            $rankingGridColumns = (int) ArrayHelper::get($field, 'raw.settings.ranking_grid_columns', ArrayHelper::get($field, 'settings.ranking_grid_columns', 3));
            if ($rankingGridColumns < 2 || $rankingGridColumns > 6) {
                $rankingGridColumns = 3;
            }
            $showPositionSerial = ArrayHelper::get(
                $field,
                'raw.settings.show_position_serial',
                ArrayHelper::get($field, 'settings.show_position_serial', 'yes')
            ) !== 'no';
            $accentColor = trim((string) ArrayHelper::get(
                $field,
                'raw.settings.accent_color',
                ArrayHelper::get($field, 'settings.accent_color', '')
            ));
            $extraStyle = $accentColor !== '' && $this->isSafeColorValue($accentColor)
                ? '--ff-ranking-accent:' . $accentColor . ';'
                : '';

            $html = '<div class="ff-ranking-response ff-ranking-response--entry ff-ranking-response--' . esc_attr($rankingDisplayType) . ' ff-ranking-response--grid-cols-' . esc_attr($rankingGridColumns) . '" style="--ff-ranking-response-grid-columns:' . esc_attr($rankingGridColumns) . ';' . esc_attr($extraStyle) . '">';
            foreach ($items as $item) {
                $html .= '<div class="ff-ranking-response__item">';
                $positionClass = $showPositionSerial
                    ? 'ff-ranking-response__position'
                    : 'ff-ranking-response__position ff-ranking-response__position--visually-hidden';
                $html .= '<span class="' . esc_attr($positionClass) . '">' . esc_html($item['index']) . '</span>';
                if (!empty($item['image'])) {
                    $html .= '<span class="ff-ranking-response__image"><img src="' . esc_url($item['image']) . '" alt="' . esc_attr($item['label']) . '"></span>';
                }
                $html .= '<span class="ff-ranking-response__label">' . esc_html($item['label']) . '</span>';
                $html .= '</div>';
            }
            $html .= '</div>';
            return $html;
        }

        return implode(' | ', array_map(function ($item) {
            return $item['index'] . '. ' . $item['label'];
        }, $items));
    }

    private function resolveDisplayOptions($data)
    {
        $advancedOptions = ArrayHelper::get($data, 'settings.advanced_options', []);
        if (!$advancedOptions) {
            $legacyOptions = ArrayHelper::get($data, 'options', []);
            foreach ($legacyOptions as $value => $label) {
                $advancedOptions[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        $flattened = method_exists(Helper::class, 'flattenAdvancedOptions')
            ? Helper::flattenAdvancedOptions($advancedOptions)
            : (array) $advancedOptions;
        $options = array_values($flattened);

        if (!$options) {
            return [];
        }

        $configuredOrder = $this->normalizeConfiguredOrder(ArrayHelper::get($data, 'attributes.value', []));
        if ($configuredOrder) {
            $options = $this->sortOptionsByOrder($options, $configuredOrder);
        } elseif (ArrayHelper::get($data, 'settings.randomize_options') === 'yes') {
            shuffle($options);
        }

        return $options;
    }

    private function normalizeConfiguredOrder($value)
    {
        if (is_string($value) && $value) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                $value = array_map('trim', explode(',', $value));
            }
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('sanitize_text_field', $value), function ($item) {
            return $item !== '';
        }));
    }

    private function sortOptionsByOrder($options, $order)
    {
        $orderedOptions = [];
        $remaining = [];

        foreach ($options as $option) {
            $value = (string) ArrayHelper::get($option, 'value');
            if (in_array($value, $order, true)) {
                $orderedOptions[$value] = $option;
            } else {
                $remaining[] = $option;
            }
        }

        $sorted = [];
        foreach ($order as $value) {
            if (isset($orderedOptions[$value])) {
                $sorted[] = $orderedOptions[$value];
            }
        }

        return array_merge($sorted, $remaining);
    }

    /**
     * Whitelist the accent color before interpolating it into an
     * inline style attribute. Allows the formats el-color-picker
     * emits (hex, hex+alpha, rgb/rgba, hsl/hsla) and rejects
     * anything that could break out of the style declaration.
     *
     * @param string $value
     * @return bool
     */
    private function isSafeColorValue($value)
    {
        return (bool) preg_match(
            '/^(#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|rgba?\([0-9.,\s%]+\)|hsla?\([0-9.,\s%]+\))$/',
            $value
        );
    }
}
