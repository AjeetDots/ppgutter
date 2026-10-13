<?php
namespace FluentFormPro\Components;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class NetPromoterScore extends BaseFieldManager
{

    public function __construct()
    {
        parent::__construct(
            'net_promoter_score',
            __('Net Promoter Score', 'fluentformpro'),
            ['net promoter score', 'score', 'survey', 'nps'],
            'advanced'
        );
    }

    public function getComponent()
    {
        return array(
            'index'          => 19,
            'element'        => $this->key,
            'attributes'     => array(
                'name'  => $this->key,
                'type' => 'radio',
                'value' => '',
                'id'    => '',
                'class' => ''
            ),
            'settings'       => array(
                'label'              => __('Net Promoter Score', 'fluentformpro'),
                'start_text'         => __('Not at all Likely', 'fluentformpro'),
                'end_text'           => __('Extremely Likely', 'fluentformpro'),
                'start_number'       => 0,
                'end_number'         => 10,
                'help_message'       => '',
                'label_placement'    => '',
                'admin_field_label'  => '',
                'container_class'    => '',
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
            'options' => array (
                '0' => __('0', 'fluentformpro'),
                '1' => __('1', 'fluentformpro'),
                '2' => __('2', 'fluentformpro'),
                '3' => __('3', 'fluentformpro'),
                '4' => __('4', 'fluentformpro'),
                '5' => __('5', 'fluentformpro'),
                '6' => __('6', 'fluentformpro'),
                '7' => __('7', 'fluentformpro'),
                '8' => __('8', 'fluentformpro'),
                '9' => __('9', 'fluentformpro'),
                '10'=> __('10', 'fluentformpro')
            ),
            'editor_options' => array(
                'title'      => __('Net Promoter Score', 'fluentformpro'),
                'icon_class' => 'ff-edit-rating',
                'template'   => 'net_promoter',
            )
        );
    }


    public function getGeneralEditorElements()
    {
        return [
            'label',
            'admin_field_label',
            'start_text',
            'end_text',
            'label_placement',
            'validation_rules',
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'name',
            'help_message',
            'container_class',
            'class',
            'value',
            'conditional_logics',
        ];
    }

    public function generalEditorElement()
    {
        return [
            'start_text' => [
                'template'  => 'inputText',
                'label'     => __('Promoter Start Text', 'fluentformpro'),
                'help_text' => __('Start Indicator Text for Promoter Scale', 'fluentformpro'),
            ],
            'end_text' => [
                'template'  => 'inputText',
                'label'     => __('Promoter End Text', 'fluentformpro'),
                'help_text' => __('End Indicator Text for Promoter Scale', 'fluentformpro'),
            ]
        ];
    }

    public function advancedEditorElement()
    {
        return [];
    }

    public function render($data, $form)
    {
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

        $options = range(0,10,1);

        $elMarkup = '<table class="ff_net_table">';
        $elMarkup .= $this->getThead(
            $options,
            ArrayHelper::get($data, 'settings.start_text'),
            ArrayHelper::get($data, 'settings.end_text')
        );
        $elMarkup .= $this->getTbody($data, $options);
        $elMarkup .= '</table>';

        $html = $this->buildElementMarkup($elMarkup, $data, $form);

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

    private function getThead($options, $textStart, $textEnd)
    {
        ob_start();
        ?>
        <thead>
            <tr>
                <th colspan="<?php echo count($options); ?>">
                    <span class="ff_not-likely"><?php echo fluentform_sanitize_html($textStart); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via fluentform_sanitize_html() ?></span>
                    <span class="ff_extremely-likely"><?php echo fluentform_sanitize_html($textEnd); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via fluentform_sanitize_html() ?></span>
                </th>
            </tr>
        </thead>
        <?php
        return ob_get_clean();
    }

    private function getTbody($data, $options)
    {
        ob_start();
        ?>
        <tbody>
            <tr>
                <?php foreach ($options as $option): ?>
                <?php
                    $id = $this->getUniqueid(str_replace(['[', ']'], ['', ''], $data['attributes']['name'].'_'.$option));

                    $ariaRequired = 'false';
                    if (ArrayHelper::get($data, 'settings.validation_rules.required.value')) {
                        $ariaRequired = 'true';
                    }

                    // This array is built from scratch, so the author's Element Class has
                    // to be merged in explicitly or it is dropped.
                    $atts = [
                        'type' => 'radio',
                        'class' => trim('ff-screen-reader-element ' . ArrayHelper::get($data, 'attributes.class', '')),
                        'value' => $option,
                        'data-calc_value' => $option,
                        'id' => $id,
                        'name' =>  $data['attributes']['name'],
                        'aria-invalid' => 'false',
                        'aria-required' => $ariaRequired
                    ];
                    $atts = $this->buildAttributes($atts);
                ?>
                <td>
                    <input <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $atts; ?>>
                    <label class='ff-el-net-label' for="<?php echo esc_attr($id); ?>">
                        <span><?php echo esc_html($option); ?></span>
                    </label>
                </td>
                <?php endforeach; ?>
            </tr>
        </tbody>
        <?php
        return ob_get_clean();
    }
}
