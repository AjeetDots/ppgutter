<?php

namespace FluentFormPro\Components\DynamicField;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\App\Services\FormBuilder\Components\Checkable;
use FluentForm\App\Services\FormBuilder\Components\Select;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Manager\FormManagerService;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class DynamicField extends BaseFieldManager
{

    /**
     * @var DynamicUser
     */
    private $user;

    /**
     * @var DynamicTerm
     */
    private $term;

    /**
     * @var DynamicPost
     */
    private $post;

    /**
     * @var FluentformSubmission
     */
    private $fluentform_submission;

    // Identity a query is authorized against: current user on admin requests,
    // the rendered form's owner on public requests. Null = use the current user.
    private $effectiveAuthorId = null;

    public function __construct()
    {
        parent::__construct(
            'dynamic_field',
            __('Dynamic Field', 'fluentformpro'),
            ['dynamic', 'populate', 'lookup'],
            'advanced'
        );
        $this->user = new DynamicUser();
        $this->term = new DynamicTerm();
        $this->post = new DynamicPost();
        $this->fluentform_submission = new FluentformSubmission();
        $this->registerFilters();
        $this->registerAjax();
        $this->registerScripts();
        new DynamicCsv();
    }

    private function registerScripts()
    {
        add_action('wp_enqueue_scripts', function () {
            wp_register_script(
                'fluentform-dynamic-autocomplete',
                FLUENTFORMPRO_DIR_URL . 'public/js/dynamicAutocomplete.js',
                [],
                FLUENTFORMPRO_VERSION,
                true
            );

            wp_register_style(
                'fluentform-dynamic-autocomplete',
                FLUENTFORMPRO_DIR_URL . 'public/css/dynamicAutocomplete.css',
                [],
                FLUENTFORMPRO_VERSION
            );
        });
    }

    private function registerAjax()
    {
        add_action('wp_ajax_fluentform-get-dynamic-filter-value-options', [$this, 'getFilterValueOptions']);
        add_action('wp_ajax_fluentform-get-dynamic-filter-form-fields', [$this, 'getFormFields']);
        add_action('wp_ajax_fluentform-get-dynamic-filter-result', [$this, 'getResult']);
        add_action('wp_ajax_fluentform-get-dynamic-autocomplete-options', [$this, 'getAutocompleteOptions']);
        add_action('wp_ajax_nopriv_fluentform-get-dynamic-autocomplete-options', [$this, 'getAutocompleteOptions']);
    }

    private function registerFilters()
    {
        add_filter('fluentform/editor_i18n', [
            $this, 'mergerI18n',
        ]);

        add_filter('fluentform/dynamic_field_re_fetch_result_and_resolve_value', [
            $this, 'reFetchResultAndResolveValue',
        ], 10, 2);

        add_filter('fluentform/editor_init_element_' . $this->key, function ($element) {
            if (!isset($element['settings']['dynamic_default_value'])) {
                $element['settings']['dynamic_default_value'] = '';
            }
            return $element;
        });
    }

    public function mergerI18n($i18n)
    {
        return wp_parse_args(DynamicFieldHelper::getI18n(), $i18n);
    }

    public function getComponent()
    {
        $default = $this->fluentform_submission->getDefaultConfig();
        return array(
            'index'          => 19,
            'element'        => $this->key,
            'attributes'     => array(
                'name'     => $this->key,
                'type'     => 'radio',
                'multiple' => false,
                'value'    => '',
                'id'       => '',
                'class'    => ''
            ),
            'settings'       => array(
                'label'              => __('Dynamic Field', 'fluentformpro'),
                'help_message'       => '',
                'label_placement'    => '',
                'admin_field_label'  => '',
                'container_class'    => '',
                'placeholder'        => '',
                'dynamic_default_value'=> '',
                'field_type'         => 'select',
                'advanced_options'   => array(),
                'dynamic_config'     => [
                    'source'           => 'fluentform_submission',
                    'unique_result'  => 'yes',
                    'query_type'     => Arr::get($default, 'query_type'),
                    'basic_query'    => Arr::get($default, 'basic_query'),
                    'filters'        => Arr::get($default, 'filters'),
                    'sort_by'        => Arr::get($default, 'sort_by'),
                    'order_by'       => Arr::get($default, 'order_by'),
                    'template_value' => Arr::get($default, 'template_value'),
                    'template_label' => Arr::get($default, 'template_label'),
                    'result_limit'   => Arr::get($default, 'result_limit'),
                ],
                'dynamic_fetch'      => 'no',
                'enable_select_2'    => 'no',
                'randomize_options'  => 'no',
                'min_chars'          => '2',
                'max_suggestions'    => '10',
                'conditional_logics' => array(),
                'validation_rules'   => array(
                    'required' => array(
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ),
                ),
            ),
            'editor_options' => array(
                'title'      => __('Dynamic Field', 'fluentformpro'),
                'icon_class' => 'ff-edit-repeat',
                'template'   => 'dynamic_field',
            )
        );
    }


    public function getGeneralEditorElements()
    {
        return [
            'label',
            'admin_field_label',
            'placeholder',
            'label_placement',
            'validation_rules',
            'field_type',
            'dynamic_config',
            'dynamic_fetch',
            'enable_select_2',
            'randomize_options',
            'min_chars',
            'max_suggestions',
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'dynamic_default_value',
            'help_message',
            'container_class',
            'class',
            'name',
            'conditional_logics',
        ];
    }

    public function generalEditorElement()
    {
        return [
            'field_type'        => [
                'template'  => 'select',
                'label'     => __('Field Type', 'fluentformpro'),
                'help_text' => __('Choose the type of field to be used for this dynamic field.', 'fluentformpro'),
                'options'   => [
                    [
                        'value' => 'radio',
                        'label' => __('Radio', 'fluentformpro'),
                    ],
                    [
                        'value' => 'checkbox',
                        'label' => __('Checkbox', 'fluentformpro'),
                    ],
                    [
                        'value' => 'select',
                        'label' => __('Select', 'fluentformpro'),
                    ],
                    [
                        'value' => 'multi_select',
                        'label' => __('Multi-Select', 'fluentformpro'),
                    ],
                    [
                        'value' => 'autocomplete',
                        'label' => __('Text Autocomplete', 'fluentformpro'),
                    ],
                ],
            ],
            'dynamic_config'    => [
                'template'        => 'dynamicFilter',
                'label'           => __('Populate Dynamically', 'fluentformpro'),
                'help_text'       => __('Populate values option dynamically based on the selected source. Customize behavior by specifying field types, filters, sorting, ordering, and mapping.', 'fluentformpro'),
                'sources'         => $this->getSources(),
                'columns'         => $this->getFilterColumns(),
                'numeric_columns' => DynamicFieldHelper::numericColumns(),
                'date_columns'    => DynamicFieldHelper::dateColumns(),
                'operators'       => DynamicFieldHelper::getOperators(),
                'order'           => [
                    'ASC'  => __('Ascending', 'fluentformpro'),
                    'DESC' => __('Descending', 'fluentformpro'),
                ]
            ],
            'dynamic_fetch'   => [
                'template'   => 'inputYesNoCheckBox',
                'label'      => __('Dynamic Retrieval', 'fluentformpro'),
                'help_text'  => __('When checked, result are dynamically fetched based on filters during rendering. If unchecked, the current valid value remain unchanged.', 'fluentformpro'),
            ],
            'enable_select_2'   => [
                'template'   => 'inputYesNoCheckBox',
                'label'      => __('Enable Searchable Smart Options', 'fluentformpro'),
                'help_text'  => __('If you enable this then options will be searchable with smart search functionality (Only for Select and Multi-Select field types)', 'fluentformpro'),
            ],
            'randomize_options' => [
                'template'   => 'inputYesNoCheckBox',
                'label'      => __('Shuffle the available options', 'fluentformpro'),
                'help_text'  => __('If you enable this then the options will be shuffled (Not available for Text Autocomplete)', 'fluentformpro'),
            ],
            'placeholder'       => [
                'template'   => 'inputText',
                'label'      => __('Placeholder', 'fluentformpro'),
                'help_text'  => __('Placeholder text for Select, Multi-Select, and Autocomplete field types', 'fluentformpro'),
            ],
            'min_chars'         => [
                'template'   => 'inputText',
                'label'      => __('Minimum Characters', 'fluentformpro'),
                'help_text'  => __('Minimum number of characters before showing suggestions (default: 2)', 'fluentformpro'),
                'badge'      => __('New', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/field_type',
                    'operator'   => '==',
                    'value'      => 'autocomplete',
                ],
            ],
            'max_suggestions'   => [
                'template'   => 'inputText',
                'label'      => __('Max Suggestions', 'fluentformpro'),
                'help_text'  => __('Maximum number of suggestions to display (default: 10)', 'fluentformpro'),
                'badge'      => __('New', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/field_type',
                    'operator'   => '==',
                    'value'      => 'autocomplete',
                ],
            ],
        ];
    }

    /**
     * Get sources with their corresponding labels.
     *
     * @return array Sources with labels.
     */
    private function getSources()
    {
        $sources = [
            'post'                  => __('Post', 'fluentformpro'),
            'term'                  => __('Taxonomy Term', 'fluentformpro'),
            'user'                  => __('User', 'fluentformpro'),
            'fluentform_submission' => __('Fluent Forms Submission', 'fluentformpro'),
        ];
        return apply_filters('fluentform/dynamic_field_sources', $sources);
    }

    /**
     * Get filter value options based on the specified source.
     *
     * @return void Sends the options as JSON response.
     */
    public function getFilterValueOptions()
    {
        try {
            Acl::verify('fluentform_forms_manager');
            $source = sanitize_text_field($this->app->request->get('source'));
            if (Arr::exists($this->getSources(), $source)) {
                if (isset($this->{$source}) && $this->{$source} instanceof DynamicBase) {
                    $options = $this->{$source}->getValueOptions();
                    $defaultConfig = $this->{$source}->getDefaultConfig();
                } else {
                    $options = apply_filters('fluentform/dynamic_field_filter_value_options' . $source, []);
                    $defaultConfig = apply_filters('fluentform/dynamic_field_filter_default_config' . $source, []);
                }
                $options = apply_filters('fluentform/dynamic_field_filter_value_options', $options, $source);
                wp_send_json_success([
                    'options'        => $options,
                    'default_config' => $defaultConfig,
                ], 200);
            }
            throw new \Exception(__('Invalid Field Config', 'fluentformpro'));
        } catch (\Exception|\Error $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], $e->getCode());
        }
    }


    /**
     * Get form fields.
     *
     * @return void Sends the options as JSON response.
     */
    public function getFormFields()
    {
        try {
            $formId = absint($this->app->request->get('form_id'));
            $allowedFormIds = $this->getCurrentUserAllowedFormIds();

            if (!$formId) {
                throw new \Exception(__('Invalid Form', 'fluentformpro'));
            }

            if (false !== $allowedFormIds && !$allowedFormIds) {
                throw new \Exception(__('You do not have permission to access this form.', 'fluentformpro'));
            }

            Acl::verify('fluentform_forms_manager', $formId);
            wp_send_json_success([
                'options'   => $this->fluentform_submission->getFormFields($formId),
            ], 200);
        } catch (\Exception|\Error $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], $e->getCode());
        }
    }

    /**
     * Get the result based on the provided configuration.
     *
     * @return void Sends the result as JSON response.
     */
    public function getResult()
    {
        try {
            Acl::verify('fluentform_forms_manager');
            $config = Arr::get($_REQUEST, 'config', []);
            $source = Arr::get($config, 'source');
            if (!$config || !$source) {
                throw new \Exception(__('Invalid Field Config', 'fluentformpro'));
            }

            // This AJAX endpoint is used by the admin-side preview in the builder,
            // so it must stay scoped to forms the current manager is allowed to access.
            $this->verifySubmissionSourceAccess($config, $source);

            $result = $this->getFieldResult($config, $source);
            wp_send_json_success($result, 200);
        } catch (\Exception|\Error $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], $e->getCode());
        }
    }

    /**
     * Retrieve the result data for a specific source based on the provided configuration.
     *
     * @param array $config The configuration to populate.
     * @param string $source The source of to populate.
     * @return array The result data for the specified source.
     * @throws \Exception If the field configuration is invalid.
     */
    private function getFieldResult($config, $source)
    {
        if (Arr::exists($this->getSources(), $source)) {
            if (isset($this->{$source}) && $this->{$source} instanceof DynamicBase) {
                $this->{$source}->setConfig($config);
                $this->{$source}->setEffectiveAuthorId($this->effectiveAuthorId);
                return $this->{$source}->getResult();
            } else {
                return apply_filters('fluentform/dynamic_field_filter_get_result' . $source, [], $config);
            }
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        throw new \Exception(__('Invalid Field Config', 'fluentformpro'));
    }

    protected function verifySubmissionSourceAccess(array &$config, $source)
    {
        if ('fluentform_submission' !== $source) {
            return;
        }

        $allowedFormIds = $this->getCurrentUserAllowedFormIds();

        if (false !== $allowedFormIds && !$allowedFormIds) {
            throw new \Exception(__('You do not have permission to preview submission data.', 'fluentformpro'));
        }

        if ('basic' !== Arr::get($config, 'query_type')) {
            $this->scopeSubmissionSourceToAllowedForms($config);
        }

        $formIds = $this->resolveSubmissionSourceFormIds($config);

        if ($formIds) {
            Acl::verify('fluentform_forms_manager', $formIds);
        } elseif ('basic' === Arr::get($config, 'query_type')) {
            throw new \Exception(__('Please select a form to preview Fluent Forms submission data.', 'fluentformpro'));
        }
    }

    protected function resolveSubmissionSourceFormIds($config)
    {
        if ('basic' === Arr::get($config, 'query_type')) {
            $formId = absint(Arr::get($config, 'basic_query.form_id'));
            return $formId ? [$formId] : [];
        }

        $formIds = [];
        $filters = Arr::get($config, 'filters', []);

        foreach ($filters as $groups) {
            if (!is_array($groups)) {
                continue;
            }

            foreach ($groups as $group) {
                if ('fluentform_submissions.form_id' !== Arr::get($group, 'column')) {
                    continue;
                }

                foreach ((array) Arr::get($group, 'value', []) as $formId) {
                    $formId = absint($formId);

                    if ($formId) {
                        $formIds[$formId] = $formId;
                    }
                }
            }
        }

        return array_values($formIds);
    }

    protected function scopeSubmissionSourceToAllowedForms(array &$config)
    {
        $allowedFormIds = $this->getCurrentUserAllowedFormIds();

        if (false === $allowedFormIds) {
            return;
        }

        if (!$allowedFormIds) {
            throw new \Exception(__('You do not have permission to preview submission data.', 'fluentformpro'));
        }

        $scopedFilters = [];
        $filters = Arr::get($config, 'filters', []);

        foreach ($filters as $groups) {
            if (!is_array($groups)) {
                continue;
            }

            $groups[] = [
                'column'   => 'fluentform_submissions.form_id',
                'custom'   => false,
                'operator' => 'IN',
                'value'    => $allowedFormIds,
            ];

            $scopedFilters[] = $groups;
        }

        if (!$scopedFilters) {
            $scopedFilters[] = [[
                'column'   => 'fluentform_submissions.form_id',
                'custom'   => false,
                'operator' => 'IN',
                'value'    => $allowedFormIds,
            ]];
        }

        $config['filters'] = $scopedFilters;
    }

    protected function getCurrentUserAllowedFormIds()
    {
        $authorId = $this->effectiveAuthorId !== null ? (int) $this->effectiveAuthorId : get_current_user_id();

        return DynamicFieldHelper::allowedFormIdsForAuthor($authorId);
    }

    protected function resolveFormOwnerId($formId)
    {
        $formId = (int) $formId;

        if (!$formId) {
            return 0;
        }

        $form = \FluentForm\App\Models\Form::find($formId);

        if (!$form) {
            return 0;
        }

        // An owner-less form (created_by NULL) resolves to 0, which fails closed. A missing
        // owner is not evidence that an administrator authorized the query.
        return (int) $form->created_by;
    }

    /**
     * Get autocomplete options via AJAX
     *
     * @return void Sends the options as JSON response.
     */
    public function getAutocompleteOptions()
    {
        try {
            $formId = (int)$this->app->request->get('form_id');
            $fieldName = sanitize_text_field($this->app->request->get('field_name'));
            $nonce = sanitize_text_field($this->app->request->get('nonce'));

            // Verify nonce for security.
            if (!$nonce || !$formId || !$fieldName || !wp_verify_nonce($nonce, 'fluentform_dynamic_autocomplete_' . $formId . '_' . $fieldName)) {
                throw new \Exception(__('Invalid security token', 'fluentformpro'));
            }

            $this->effectiveAuthorId = $this->resolveFormOwnerId($formId);

            $searchTerm = sanitize_text_field($this->app->request->get('search'));

            if (!$formId || !$fieldName) {
                throw new \Exception(__('Invalid request parameters', 'fluentformpro'));
            }

            $form = Helper::getForm($formId);
            if (!$form) {
                throw new \Exception(__('Form not found', 'fluentformpro'));
            }

            $element = FormFieldsParser::getField($form, ['dynamic_field'], $fieldName, ['settings']);
            if (!$element) {
                throw new \Exception(__('Field not found', 'fluentformpro'));
            }

            $fieldData = Arr::get($element, $fieldName, Arr::get($element, 'dynamic_field', $element));
            
            $config = Arr::get($fieldData, 'settings.dynamic_config');
            if (!$config) {
                $config = Arr::get($fieldData, 'raw.settings.dynamic_config');
            }

            if (!$config) {
                throw new \Exception(__('Invalid field configuration', 'fluentformpro'));
            }

            $source = Arr::get($config, 'source');
            if (!$source) {
                throw new \Exception(__('Invalid data source', 'fluentformpro'));
            }

            // Frontend autocomplete is public by design for forms rendered to guests.
            // Do not reuse the admin preview ACL gate here, or public dynamic-field
            // autocomplete will stop working for legitimate form submissions.
            $result = $this->getFieldResult($config, $source);
            $options = Arr::get($result, 'valid_options', []);

            // Apply fuzzy matching if search term is provided
            if ($searchTerm && !empty($options)) {
                $maxSuggestions = (int)$this->app->request->get('max_suggestions', 10);
                $options = $this->fuzzyMatchOptions($options, $searchTerm, $maxSuggestions);
            }

            wp_send_json_success([
                'options' => array_values($options),
            ], 200);
        } catch (\Exception|\Error $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Apply fuzzy matching to options based on search term
     *
     * @param array $options Array of options with 'label' and 'value' keys
     * @param string $searchTerm The search query
     * @param int $maxSuggestions Maximum number of results to return
     * @return array Filtered and ranked options
     */
    private function fuzzyMatchOptions($options, $searchTerm, $maxSuggestions = 10)
    {
        $searchTerm = trim(strtolower($searchTerm));
        $searchLength = mb_strlen($searchTerm);

        if ($searchLength < 10) {
            $filtered = array_filter($options, function ($option) use ($searchTerm) {
                $label = strtolower($option['label'] ?? '');
                $value = strtolower($option['value'] ?? '');
                return mb_strpos($label, $searchTerm) !== false || mb_strpos($value, $searchTerm) !== false;
            });
            return array_slice(array_values($filtered), 0, $maxSuggestions);
        }

        $scored = [];
        foreach ($options as $option) {
            $label = strtolower($option['label'] ?? '');
            $value = strtolower($option['value'] ?? '');

            $labelScore = $this->calculateFuzzyScore($label, $searchTerm);
            $valueScore = $this->calculateFuzzyScore($value, $searchTerm);
            $score = max($labelScore, $valueScore);

            if ($score > 0) {
                $scored[] = [
                    'option' => $option,
                    'score' => $score
                ];
            }
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $results = array_map(function ($item) {
            return $item['option'];
        }, array_slice($scored, 0, $maxSuggestions));

        return $results;
    }

    /**
     * Calculate fuzzy matching score between two strings
     *
     * @param string $text The text to search in
     * @param string $query The search query
     * @return float Score between 0 and 1, where 1 is exact match
     */
    private function calculateFuzzyScore($text, $query)
    {
        if (empty($text) || empty($query)) {
            return 0;
        }

        if ($text === $query) {
            return 1.0;
        }

        $textLength = mb_strlen($text);
        $queryLength = mb_strlen($query);

        if ($queryLength > $textLength) {
            return 0;
        }

        $pos = mb_stripos($text, $query);
        if ($pos !== false) {
            $positionScore = 1.0 - ($pos / max($textLength, 1));
            $lengthScore = $queryLength / $textLength;
            return ($positionScore * 0.4) + ($lengthScore * 0.6);
        }

        $similarity = 0;
        $queryChars = mb_str_split($query);
        $textChars = mb_str_split($text);

        $matchedChars = 0;
        $textIndex = 0;

        foreach ($queryChars as $queryChar) {
            $found = false;
            for ($i = $textIndex; $i < count($textChars); $i++) {
                if (mb_strtolower($textChars[$i]) === mb_strtolower($queryChar)) {
                    $matchedChars++;
                    $textIndex = $i + 1;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                break;
            }
        }

        if ($matchedChars === $queryLength) {
            $similarity = $matchedChars / max($textLength, 1);
            return $similarity * 0.35;
        }

        return 0;
    }

    /**
     * Get the filter columns for all sources of dynamic fields.
     *
     * @return array An associative array containing filter columns for each dynamic field source.
     */
    private function getFilterColumns()
    {
        $fields = [
            'post'                  => $this->post->getSupportedColumns(),
            'term'                  => $this->term->getSupportedColumns(),
            'user'                  => $this->user->getSupportedColumns(),
            'fluentform_submission' => $this->fluentform_submission->getSupportedColumns(),
        ];
        return apply_filters("fluentform/dynamic_field_filter_fields", $fields);
    }

    public function advancedEditorElement()
    {
        return [];
    }

    /**
     * Refetches the result and resolves the value for a dynamic field.
     *
     * @param array $field The field data.
     * @return array The modified field data.
     */
    public function reFetchResultAndResolveValue($field, $formId = null)
    {
        $this->effectiveAuthorId = $formId !== null ? $this->resolveFormOwnerId($formId) : null;

        // By default, reference to the original field data
        $fieldRef = &$field;

        // Check if settings exist directly or within 'raw' key
        if (!$settings = Arr::get($field, 'settings')) {
            if (!$settings = Arr::get($field, 'raw.settings')) {
                return $field;
            }
            // If 'settings' not found directly, reference to 'raw' key
            $fieldRef = &$field['raw'];
        }

        // Check if dynamic fetching is enabled
        if ('yes' == Arr::get($settings, 'dynamic_fetch')) {
            $config = Arr::get($settings, 'dynamic_config');
            $source = Arr::get($config, 'source');
            try {
                // Retrieve valid options based on dynamic configuration
                $validOptions = Arr::get($this->getFieldResult($config, $source), 'valid_options');
                if (is_array($validOptions)) {
                    $fieldRef['settings']['advanced_options'] = $validOptions;
                    if ($defaultValue = Arr::get($fieldRef, 'attributes.value')) {
                        // Remove default value if not found in valid options
                        $validOptionsValues = array_column($validOptions, 'value');
                        if (is_array($defaultValue)) {
                            foreach ($defaultValue as $index => $value) {
                                if (!in_array($value, $validOptionsValues)) {
                                    Arr::forget($defaultValue, $index);
                                }
                            }
                            $fieldRef['attributes']['value'] = $defaultValue;
                        } else {
                            if (!in_array($defaultValue, $validOptionsValues)) {
                                $fieldRef['attributes']['value'] = '';
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                //..
            }
        }
        return $field;
    }


    public function render($data, $form)
    {
        $fieldType = Arr::get($data, 'settings.field_type', 'select');

        // Enqueue Choices.js for smart search if enabled
        $isSmartSearch = Arr::get($data, 'settings.enable_select_2') == 'yes';
        if ($isSmartSearch && in_array($fieldType, ['select', 'multi_select'])) {
            wp_enqueue_script('choices');
            wp_enqueue_style('ff_choices');
        }

        // Enqueue autocomplete assets if field type is autocomplete
        if ($fieldType === 'autocomplete') {
            wp_enqueue_script('fluentform-dynamic-autocomplete');
            wp_enqueue_style('fluentform-dynamic-autocomplete');
        }

        if ('yes' == Arr::get($data, 'settings.dynamic_fetch')) {
            $data = $this->reFetchResultAndResolveValue($data, $form->id);
        }

        if (in_array($fieldType, ['checkbox', 'radio'])) {
            (new Checkable)->compile($data, $form);
        } elseif (in_array($fieldType, ['select', 'multi_select'])) {
            (new Select)->compile($data, $form);
        } elseif ($fieldType === 'autocomplete') {
            $this->renderAutocomplete($data, $form);
        }
    }

    /**
     * Render autocomplete field type
     *
     * @param array $data Field data
     * @param object $form Form object
     * @return void
     */
    private function renderAutocomplete($data, $form)
    {
        $elementName = $data['element'];

        $data = apply_filters('fluentform/rendering_field_data_' . $elementName, $data, $form);

        $minChars = (int)Arr::get($data, 'settings.min_chars', 2);
        $maxSuggestions = (int)Arr::get($data, 'settings.max_suggestions', 10);
        $placeholder = Arr::get($data, 'settings.placeholder', '');

        // Override type attribute to text
        $data['attributes']['type'] = 'text';
        $data['attributes']['autocomplete'] = 'off';
        $data['attributes']['placeholder'] = $placeholder;

        // Generate a field-specific nonce to reduce the chance of parameter tampering.
        // Keep the legacy nonce behavior accepted server-side for backwards compatibility.
        $fieldNameForNonce = Arr::get($data, 'attributes.name');
        $data['attributes']['data-autocomplete-config'] = wp_json_encode([
            'formId'         => $form->id,
            'fieldName'      => $fieldNameForNonce,
            'minChars'       => $minChars,
            'maxSuggestions' => $maxSuggestions,
            'nonce'          => wp_create_nonce('fluentform_dynamic_autocomplete_' . $form->id . '_' . $fieldNameForNonce),
            'ajaxUrl'        => admin_url('admin-ajax.php'),
        ]);

        $data['attributes']['class'] = trim(
            'ff-el-form-control ff-dynamic-autocomplete ' .
            Arr::get($data, 'attributes.class', '')
        );

        $elMarkup = '<div class="ff-dynamic-autocomplete-wrap">';
        $elMarkup .= "<input " . $this->buildAttributes($data['attributes'], $form) . ">";
        $elMarkup .= '<div class="ff-autocomplete-suggestions" style="display:none;"></div>';
        $elMarkup .= '</div>';

        $html = $this->buildElementMarkup($elMarkup, $data, $form);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form);
    }
}
