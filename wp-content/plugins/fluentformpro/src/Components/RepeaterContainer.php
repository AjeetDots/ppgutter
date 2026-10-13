<?php
namespace FluentFormPro\Components;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class RepeaterContainer extends BaseFieldManager
{
    
    // @Todo : Add validation
    
    
    /**
     * Container column class
     *
     * @var string
     */
    protected $columnClass = 'ff-t-cell';
    
    /**
     * Container wrapper class
     *
     * @var string
     */
    protected $wrapperClass = 'ff-repeater-container ff-el-repeater ';
    
    
    public function __construct()
    {
        parent::__construct(
            'repeater_container',
            'Container Repeater',
            ['repeater', 'list', 'multiple'],
            'container'
        );

        add_filter('fluentform/response_render_repeater_container', array($this, 'renderResponse'), 10, 3);

    }

    public function getComponent()
    {
        return [
            'index'          => 17,
            'element'        => 'repeater_container',
            'attributes'     => [
                'name'  => $this->key,
                'class' => '',
                'value' => [],
            ],
            'settings'       => [
                'label'                       => __('Repeater Container', 'fluentformpro'),
                'admin_field_label'           => '',
                'container_class'             => '',
                'label_placement'             => '',
                'validation_rules'            => array(),
                'max_repeat_field'            => '',
                'conditional_logics'          => [],
                'repeater_columns'            => '2',
                'container_width'             => '',
                'repeater_container_settings' => ''
            ],
            'columns'        => [
                ['width' => 50, 'fields' => []],
                ['width' => 50, 'fields' => []],
            ],
            'editor_options' => array(
                'title'      => __('Repeat Container', 'fluentformpro'),
                'icon_class' => 'ff-edit-repeat',
                'template'   => ''
            ),
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'label_placement',
            'admin_field_label',
            'repeater_container_settings',
            'container_width'
        ];
    }

    public function getEditorCustomizationSettings()
    {
        return [
            'repeater_container_settings' => [
                'template'  => 'repeaterContainers',
                'label'     => __('Container Columns', 'fluentformpro'),
                'help_text' => __('Number of Columns in Container', 'fluentformpro')
            ]
        ];
    }
    public function getAdvancedEditorElements()
    {
        return [
            'container_class',
            'name',
            'conditional_logics',
            'max_repeat_field',
        ];
        
    }

  

    /**
     * Compile and echo the html element
     * @param array $data [element data]
     * @param stdClass $form [Form Object]
     * @return void
     */
    public function render($data, $form)
    {
        wp_enqueue_script('fluentform-advanced');

        $data = apply_filters('fluentform/rendering_field_data_' . $data['element'], $data, $form);
        
        $rootName = $data['attributes']['name'];
        
        $data['attributes']['class'] = $this->getContainerClass($data);
        $data['attributes']['data-max_repeat'] = ArrayHelper::get($data, 'settings.max_repeat_field');
        $data['attributes']['data-root_name'] = $rootName;
        $data['attributes']['data-name'] = $rootName;
        $data['attributes']['data-type'] = 'repeater_container';

        $atts = $this->buildAttributes(ArrayHelper::except($data['attributes'], 'name'));
        $ariaLabel = esc_attr(ArrayHelper::get($data, 'attributes.label'));
        $columns = $data['columns'];
        
        ob_start();
        ?>
        <div <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $atts; ?>>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $this->buildElementLabel($data, $form); ?>
            <div class='ff-el-input--content'>
                <div role="list" aria-label="<?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $ariaLabel; ?>" data-max_repeat="<?php echo esc_attr($data['attributes']['data-max_repeat']); ?>" data-root_name="<?php echo esc_attr($rootName); ?>" class="ff_repeater_container_list ff_flexible_list">
                    <div class="ff_repeater_body" role="rowgroup">
                        <div class="ff_repeater_cont_row" role="row">
                            <?php $this->renderColumns($columns, $rootName, $data, $form); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $html = ob_get_clean();
        
        echo apply_filters('fluentform/rendering_field_html_' . $data['element'], $html, $data, $form); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form field HTML rendering
        \FluentForm\App\Helpers\Helper::getNextTabIndex(50);
    }
    
    protected function renderColumns($columns, $rootName, $data, $form)
    {
        $fieldIndex = 0;
        $columnClass = $this->columnClass;
        $totalWidth = 0;
        
        foreach ($columns as $columnIndex => $column) {
            if (!isset($column['width'])) {
                $column['width'] = ceil(100 / count($data['columns']));
            }
            $totalWidth += $column['width'];
            
            $newColumnClass = $columnClass . ' ff-t-column-' . ($columnIndex + 1);
            echo "<div class='" . esc_attr($newColumnClass) . "' style='flex-basis: " . esc_attr($column['width']) . "%;'>";
    
            foreach ($column['fields'] as $scopeFieldIndex => $field) {
                $conditionFieldName = $rootName . '_condition_' . $columnIndex . '_' . $scopeFieldIndex;
                $field['attributes'] = $this->prepareFieldAttributes($field, $rootName, $fieldIndex, $data, $form, $conditionFieldName);
                if($labelPlacement = ArrayHelper::get($field, 'settings.label_placement')) {
                    $data['attributes']['class'] .= ' ff-el-form-'.$labelPlacement;
                }
                
                // Normalize field element type
                $field['element'] = $field['element'] === 'input_mask' ? 'input_text' : $field['element'];
                ?>

                <div class="ff_repeater_cell" role="cell">
<!--                    <div class="--><?php //echo esc_attr($labelClass); ?><!--">-->
<!--                        <label for="--><?php //echo esc_attr($field['attributes']['id']); ?><!--">-->
<!--                            --><?php //echo esc_html($itemLabel); ?>
<!--                        </label>-->
<!--                    </div>-->
            
                    <?php
                    // Render the field element using a dynamic action
                    do_action('fluentform/render_item_' . $field['element'], $field, $form);
                    ?>
                </div>
                <?php
        
                $fieldIndex++;
            }
    
    
            echo '</div>';
        }
        
        $buttonWidth = 100 - $totalWidth;
        echo "<div class='ff_repeater_cell repeat_btn' role='cell' style='flex-basis: " . esc_attr($buttonWidth) . "%;'>";
        echo $this->getRepeater($data['element']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Repeater button HTML
        echo "</div>";
    }
    
    protected function getContainerClass($data)
    {
        $containerClass = ArrayHelper::get($data, 'settings.container_class', '');
        $hasConditions = $this->hasConditions($data) ? 'has-conditions ' : '';
        $containerClass .= ' ' . $hasConditions;
        
        $container_css_class = $this->wrapperClass . ' ff_columns_total_' . count($data['columns']);
        $container_css_class .= ' ' . wp_strip_all_tags($containerClass);
        
        return esc_attr($container_css_class);
    }
    
    protected function prepareFieldAttributes($field, $rootName, $index, $data, $form, $conditionFieldName)
    {
        $fieldLabel = ArrayHelper::get($field, 'settings.label', $rootName);
        return wp_parse_args([
            'aria-label' => 'repeater level 1 and field ' . $fieldLabel,
            'name' => $rootName . '[0][' . $index . ']',
            'id' => $this->makeElementId($data, $form) . '_' . $index,
            'data-repeater_index' => $index,
            'data-type' => 'repeater_container',
            'data-name' => $rootName . '_' . $index . '_0',
            'data-error_index' => $rootName . '[' . $index . ']',
            'data-condition_field_name' => $conditionFieldName,
            'data-default' => ArrayHelper::get($field, 'attributes.value')
        ], ArrayHelper::get($field, 'attributes'));
    }


    /**
     * Compile repeater buttons
     * @param string $el [element name]
     * @return string
     */
    protected function getRepeater($el)
    {
        $div = '<div class="ff-el-repeat-buttons-list js-container-repeat-buttons">';
        $div .= '<span role="button" aria-label="add repeater" class="repeat-plus"><svg tabindex="0" width="20" height="20" viewBox="0 0 512 512"><path d="m256 48c-115 0-208 93-208 208 0 115 93 208 208 208 115 0 208-93 208-208 0-115-93-208-208-208z m107 229l-86 0 0 86-42 0 0-86-86 0 0-42 86 0 0-86 42 0 0 86 86 0z"></path></svg></span>';
        $div .= '<span role="button" aria-label="remove repeater" class="repeat-minus"><svg tabindex="0" width="20" height="20" viewBox="0 0 512 512"><path d="m256 48c-115 0-208 93-208 208 0 115 93 208 208 208 115 0 208-93 208-208 0-115-93-208-208-208z m107 229l-214 0 0-42 214 0z""></path></svg></span>';
        $div .= '</div>';
        return $div;
    }
    

    public function renderResponse($response, $field, $form_id)
    {
        if (defined('FLUENTFORM_RENDERING_ENTRIES')) {
            return __('....', 'fluentformpro');
        }

        if (is_string($response) || empty($response) || !is_array($response)) {
            return '';
        }

        $columns = ArrayHelper::get($field, 'raw.columns');
        $fields = [];
        foreach ($columns as $column) {
            $columnFields = ArrayHelper::get($column, 'fields', []);
            $fields = array_merge($fields, $columnFields);
        }
        
        // Filter out non-input fields
        $nonInputElements = ['custom_html', 'section_break'];
        $filteredFields = [];
        foreach ($fields as $index => $field) {
            if (!in_array(ArrayHelper::get($field, 'element'), $nonInputElements)) {
                $filteredFields[] = $field;
            }
        }
        
        // Use filtered fields instead of all fields
        $fields = $filteredFields;
        
        $columnCount = count($fields);
        $columns = array_fill(0, $columnCount, 'column');
   
        if (defined('FLUENTFORM_DOING_CSV_EXPORT')) {
            return self::getResponseAsText($response, $fields, $columns);
        }

        return $this->getResponseHtml($response, $fields, $columns);
    }

    protected function getResponseHtml($response, $fields, $columns)
    {
        ob_start();
        ?>
        <table class="ff_entry_table_field" style="width: 100%; border-collapse: collapse; border: 1px solid #cbcbcb; table-layout: fixed;">
            <thead>
            <tr>
                <?php foreach ($fields as $index => $field): ?>
                    <th style="padding: 8px; background-color: #f8f8f8; border: 1px solid #cbcbcb; text-align: left;"><?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo ArrayHelper::get($field, 'settings.label'); ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($response as $responseIndex => $item): ?>
                <tr>
                    <?php
                    foreach ($fields as $fieldIndex => $field):
                        $value = '';

                        // Handle different data formats
                        if (is_array($item)) {
                            if (isset($item[$fieldIndex])) {
                                // Format: [0 => value]
                                $value = $item[$fieldIndex];
                            } elseif (isset($item[0]) && is_array($item[0]) && isset($item[0][$fieldIndex])) {
                                // Format: [[0 => value]]
                                $value = $item[0][$fieldIndex];
                            } else {
                                // Try numeric keys (1-based index)
                                $oneBasedIndex = $fieldIndex + 1;
                                if (isset($item[$oneBasedIndex])) {
                                    $value = $item[$oneBasedIndex];
                                }
                            }
                        } elseif (is_object($item)) {
                            // Handle object format
                            $oneBasedIndex = $fieldIndex + 1;
                            if (isset($item->{$oneBasedIndex})) {
                                $value = $item->{$oneBasedIndex};
                            } elseif (isset($item->{$fieldIndex})) {
                                $value = $item->{$fieldIndex};
                            }
                        }
                    ?>
                        <td style="padding: 8px; border: 1px solid #cbcbcb;"><?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo $value; ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }
    

    public static function getResponseAsText($response, $fields, $columns = [])
    {
        if (!is_array($response) || !is_array($fields)) {
            return '';
        }
        if (!$columns) {
            $columnCount = (count($fields) > count((array)ArrayHelper::get($response, 0))) ? count($fields) : count((array)ArrayHelper::get($response, 0));
            $columns = array_fill(0, $columnCount, 'column');
        }
        $totalColumns = count($columns);
        $text  = '';
        foreach ($columns as $index => $count) {
            $text .= trim(ArrayHelper::get($fields, $index . '.settings.label', ' '));
            if( $index+1 != $totalColumns ) {
                $text .= " | ";
            }
        }
        $text .= "\n";
        foreach ($response as $responseIndex => $item):
            foreach ($columns as $index => $count):
                $text .= ArrayHelper::get($item, $index);
                if( $index+1 != $totalColumns ) {
                    $text .= " | ";
                }
            endforeach;
            $text .= "\n";
        endforeach;
        return $text;
    }
}
