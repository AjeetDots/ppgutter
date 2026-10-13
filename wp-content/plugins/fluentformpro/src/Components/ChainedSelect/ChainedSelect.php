<?php

namespace FluentFormPro\Components\ChainedSelect;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class ChainedSelect extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'chained_select',
            'Chained Select',
            ['chained', 'select', 'csv', 'progressive'],
            'advanced'
        );

        add_action('wp_enqueue_scripts', function () {
            wp_register_script(
                'fluentform-chained-element-script',
                FLUENTFORMPRO_DIR_URL . 'public/js/chainedSelectScript.js',
                ['jquery'],
                FLUENTFORMPRO_VERSION,
                true
            );
        });

        add_filter(
            'fluentform/response_render_' . $this->key, array($this, 'renderResponse'), 10, 4
        );
    }

    public function getComponent()
    {
        return [
            'index'          => 20,
            'element'        => $this->key,
            'attributes'     => [
                'name'        => $this->key,
                'class'       => '',
                'value'       => '',
                'data-type'   => 'chained-select',
                'type'        => 'chained-select',
                'placeholder' => __('Select Parent | Select Child', 'fluentformpro')
            ],
            'settings'       => [
                'container_class'    => '',
                'label'              => $this->title,
                'label_placement'    => '',
                'help_message'       => '',
                'data_source'        => [
                    'name'     => '',
                    'url'      => '',
                    'type'     => 'file',
                    'meta_key' => null,
                    'headers'  => ['Parent', 'Child', 'Grand Child'],
                    'options'  => []
                ],
                'admin_field_label'  => '',
                'enable_select_2' => 'no',
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
                'title'      => $this->title,
                'icon_class' => 'ff-edit-link',
                'template'   => 'chainedSelect'
            ],
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'admin_field_label',
            'data_source',
            'value',
            'label_placement',
            'validation_rules',
        ];
    }
    public function getAdvancedEditorElements()
    {
        return [
            'name',
            'dynamic_default_value',
            'help_message',
            'container_class',
            'class',
            'enable_select_2',
            'conditional_logics',
        ];
    }

    public function generalEditorElement()
    {
        return [
            'data_source' => [
                'template'  => 'chainSelectDataSource',
                'label'     => __('CSV Data Source', 'fluentformpro'),
                'help_text' => __('Upload your chained select CSV file or provide a remote URL', 'fluentformpro')
            ]
        ];
    }

    function render($data, $form)
    {

        $isSmartSearch = ArrayHelper::get($data, 'settings.enable_select_2') == 'yes';
        if ($isSmartSearch) {
            wp_enqueue_script('choices');
            wp_enqueue_style('ff_choices');
        }
        wp_enqueue_script('fluentform-chained-element-script');

        $i18n = [
            'Loading...' => __('Loading...', 'fluentformpro')
        ];

        wp_localize_script('fluentform-chained-element-script', 'fluentform_chained_select', [
            'i18n' => $i18n
        ]);

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

        $rootName = $data['attributes']['name'];

        $hasConditions = $this->hasConditions($data) ? 'has-conditions ' : '';

        $elementClass = ArrayHelper::get($data, 'attributes.class');

        $data['attributes']['class'] = '';

        $data['attributes']['class'] .= $hasConditions;

        $data['attributes']['class'] .= ' ff-field_container ff-chained-select-field-wrapper';

        if ($wrapperClass = ArrayHelper::get($data, 'settings.container_class')) {
            $data['attributes']['class'] .= ' ' . $wrapperClass;
        }
        $data['attributes']['data-source-type'] = $data['settings']['data_source']['type'];
        $data['attributes']['data-source-url'] =  $data['settings']['data_source']['url'];

        $atts = $this->buildAttributes(
            ArrayHelper::except($data['attributes'], ['name', 'type', 'placeholder'])
        );

        $html = "<div {$atts}>";

        $html .= "<div class='ff-t-container'>";

        $labelPlacement = ArrayHelper::get($data, 'settings.label_placement');

        $labelPlacementClass = '';

        if ($labelPlacement) {
            $labelPlacementClass = ' ff-el-form-' . $labelPlacement;
        }

        list($headers, $content) = $this->getCsvData($data, $form);

        $inited = false;
        foreach ($headers as $key => $field) {
            $element = [
                'settings'   => $data['settings'],
                'attributes' => $data['attributes']
            ];

            $element['settings']['label'] = $field;

            $element['attributes']['name'] = $rootName . '[' . $field . ']';

            $element['attributes']['class'] = 'ff-el-form-control ' . $elementClass;

            if ($tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex()) {
                $element['attributes']['tabindex'] = $tabIndex;
            }

            $element['settings']['container_class'] = $labelPlacementClass;

            $element['attributes']['id'] = $this->makeElementId($element, $form);

            // SECURITY (PRO-27): $field (column header) and $value (cell label) come from an
            // uploaded/remote CSV and were interpolated raw into <option> text, so a cell like
            // </option></select><script>… broke out and executed on every public render. Escape
            // the option text (the value attribute was already esc_attr'd).
            $options = "<option value=''>" . esc_html($field) . "</option>";

            if ($key == 0) {
                foreach (array_unique(array_column($content, $field)) as $value) {
                    $options .= "<option value='". esc_attr($value). "'>" . esc_html($value) . "</option>";
                }
            }

            $elementAttributes = ArrayHelper::except(
                    $element['attributes'], ['type', 'data-type', 'placeholder']
                ) + [
                    'data-index' => $key,
                    'data-meta_key' => $element['settings']['data_source']['meta_key'],
                    'data-source-type' => $element['settings']['data_source']['type'],
                    'data-source-url' => $element['settings']['data_source']['url']
                ];

            $elementAttributes['class'] = $elementAttributes['class'] . ' el-chained-select';

            if ($isSmartSearch) {
                $elementAttributes['class'] .= ' ff_el-chained-select-smart-search';
            }

            $elementAttributes['data-key'] = $field;

            $elementAttributes = $this->buildAttributes($elementAttributes);

            $elMarkup = "<select disabled " . $elementAttributes . ">$options</select>";

            if($inited) {
                $element['settings']['help_message'] = '';
            }
            $inited = true;

            $markup = $this->buildElementMarkup($elMarkup, $element, $form);

            $html .= "<div class='ff-t-cell'>{$markup}</div>";
        }

        $html .= "</div>";
        $html .= "</div>";
    
        $html = apply_filters_deprecated(
            'fluentform_rendering_field_html_' . $elementName,
            [
                $html,
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_html_' . $elementName,
            'Use fluentform/rendering_field_html_' . $elementName . ' instead of fluentform_rendering_field_html_' . $elementName
        );

        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form field HTML rendering
    }

    protected function getCsvData($element, $form)
    {
        if (!class_exists('CSVParser')) {
            require_once(FLUENTFORMPRO_DIR_PATH . 'libs/CSVParser/CSVParser.php');
        }

        $csvParser = new \CSVParser;

        $path = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR;

        $metaKey = $element['settings']['data_source']['meta_key'];

        $target = $path . '/' . $metaKey . '_' . $form->id . '.csv';

        if (file_exists($target)) {
            $content = file_get_contents($target);

            $csvParser->load_data($content);

            $result = $csvParser->parse($csvParser->find_delimiter());

            $headers = array_shift($result);

            foreach ($result as $key => $value) {
                if (count($headers) !== count($value)) {
                    continue;
                }
                $result[$key] = array_combine($headers, $value);
            }

            return [$headers, $result];
        }

        return [['Parent', 'Child', 'Grand Child'], []];
    }

    public function renderResponse($response, $field, $form_id, $isHtml = false) {
        if (!$response) {
            return $response;
        }

        $response = (array) $response;
        $response = array_filter($response);
        
        if (!$response) {
            return '';
        }

        if (!$isHtml) {
            return fluentImplodeRecursive(', ', $response);
        }

        $html = $html = '<ul class="ff_entry_list">';
        foreach ($response as $label => $response) {
            $html .= '<li>'.$label.': '.$response.'</li>';
        }
        $html .= '</ul>';
        
        return $html;
    }
}
