<?php
namespace FluentFormPro\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class Accordion extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'accordion',
            __('Accordion/Tab', 'fluentformpro'),
            ['accordion', 'tab', 'collapsible', 'section', 'group'],
            'container'
        );

        // Add assets for frontend
        add_action('fluentform/load_form_assets', [$this, 'registerAssets']);

        // Add assets for editor
        add_action('fluentform/loading_editor_assets', [$this, 'registerEditorAssets']);

        add_filter('fluentform/editor_i18n', [$this, 'addEditorI18n']);
        add_filter('fluentform/form_fields_update', [$this, 'validateAccordionFields']);
    }

    public function validateAccordionFields($fields)
    {
        if (empty($fields) || !is_string($fields)) {
            return $fields;
        }

        $fieldsArray = json_decode($fields, true);
        if (empty($fieldsArray['fields'])) {
            return $fields;
        }

        $formFields = $fieldsArray['fields'];
        $currentAccordion = null;
        $hasError = false;
        $errorMessage = '';

        // First pass: Check proper nesting and step conflicts
        foreach ($formFields as $key => $field) {
            if ($this->key == $field['element']) {
                $accordionType = ArrayHelper::get($field, 'settings.accordion_type');
                if ('start' == $accordionType) {
                    if ($currentAccordion) {
                        $hasError = true;
                        // translators: %s is the accordion title
                        $errorMessage = sprintf(
                            __('Error: Found a new accordion before closing the previous one. Please close the "%s" accordion before starting a new one.', 'fluentformpro'),
                            ArrayHelper::get($currentAccordion, 'title')
                        );
                        break;
                    }
                    $currentAccordion = [
                        'title' => ArrayHelper::get($field, 'settings.title', 'Untitled Accordion')
                    ];
                } elseif ('both' == $accordionType) {
                    if ($currentAccordion) {
                        $currentAccordion = [
                            'title' => ArrayHelper::get($field, 'settings.title', 'Untitled Accordion')
                        ];
                    } else {
                        $hasError = true;
                        // translators: %s is the accordion title
                        $errorMessage = sprintf(
                            __('Error: Found a closing accordion without a matching opening accordion. Please add an Accordion field type Start before "%s" accordion.', 'fluentformpro'),
                            ArrayHelper::get($field, 'settings.title', 'Untitled Accordion')
                        );
                        break;
                    }
                } else {
                    if ($currentAccordion) {
                        $currentAccordion = null;
                    } else {
                        $hasError = true;
                        $title = ArrayHelper::get($field, 'settings.title', 'Untitled Accordion');
                        // translators: %s is the accordion title
                        $errorMessage = sprintf(
                            __('Error: Found a closing accordion without a matching opening accordion. Please add an Accordion field type Start before "%s" accordion.', 'fluentformpro'),
                            $title
                        );
                        break;
                    }
                }
            } elseif ('form_step' == $field['element'] && $currentAccordion) {
                // Form steps inside accordions are not supported
                $hasError = true;
                // translators: %s is the accordion title
                $errorMessage = sprintf(
                    __('Error: Form steps cannot be placed inside accordions. Found a step inside "%s" accordion.', 'fluentformpro'),
                    $currentAccordion['title']
                );
                break;
            }
        }

        // Check if all accordions were properly closed
        if (!$hasError && $currentAccordion) {
            $hasError = true;
            // translators: %s is the accordion title
            $errorMessage = sprintf(
                __('Error: The accordion "%s" was not closed properly. Please add a closing accordion.', 'fluentformpro'),
                $currentAccordion['title']
            );
        }

        // Return error if validation failed
        if ($hasError) {
            wp_send_json([
                'message' => $errorMessage
            ], 423);
        }

        return $fields;
    }

    public function addEditorI18n($i18n)
    {
        $accordionI18n = [
            'Close of previous' => __('Close of previous', 'fluentformpro'),
            'ACCORDION' => __('ACCORDION', 'fluentformpro'),
            'TAB' => __('TAB', 'fluentformpro'),
            'Start of new' => __('Start of new', 'fluentformpro')
        ];

        return array_merge($i18n, $accordionI18n);
    }

    public function getComponent()
    {
        return array(
            'index'          => 30,
            'element'        => $this->key,
            'attributes'     => [
                'id' => '',
                'class' => ''
            ],
            'settings'       => array(
                'title'              => __('Accordion Title', 'fluentformpro'),
                'description'        => __('Section description goes here', 'fluentformpro'),
                'display_mode'       => 'accordion',
                'start_collapsed'    => 'yes',
                'accordion_type'     => 'start',
                'connected_design'   => 'no',
                'collapse_when_others_open' => 'yes',
                'container_class'    => '',
                'conditional_logics' => array(),
            ),
            'editor_options' => array(
                'title'         => __('Accordion/Tab', 'fluentformpro'),
                'icon_class'    => 'ff-edit-section-break',
                'componentName' => 'AccordionEditorField',
                'template'      => 'CustomEditorField'
            )
        );
    }

    public function getGeneralEditorElements()
    {
        return [
            'title',
            'description',
            'display_mode',
            'accordion_type',
            'start_collapsed',
            'connected_design',
            'collapse_when_others_open',
            'container_class'
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [];
    }

    public function generalEditorElement()
    {
        return [
            'title' => [
                'template'  => 'inputText',
                'label'     => __('Title', 'fluentformpro'),
                'help_text' => __('The title displayed in the header', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/accordion_type',
                    'operator' => '!=',
                    'value' => 'close'
                ]
            ],
            'description' => [
                'template'  => 'inputTextarea',
                'label'     => __('Description', 'fluentformpro'),
                'help_text' => __('Description text displayed inside the content area (optional)', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/accordion_type',
                    'operator' => '!=',
                    'value' => 'close'
                ]
            ],
            'display_mode' => [
                'template'  => 'radio',
                'label'     => __('Display Mode', 'fluentformpro'),
                'help_text' => __('Choose how to display the sections', 'fluentformpro'),
                'options'   => [
                    [
                        'value' => 'accordion',
                        'label' => __('Accordion', 'fluentformpro')
                    ],
                    [
                        'value' => 'tabs',
                        'label' => __('Tabs', 'fluentformpro')
                    ]
                ],
                'dependency' => [
                    'depends_on' => 'settings/accordion_type',
                    'operator' => '!=',
                    'value' => 'close'
                ]
            ],
            'accordion_type' => [
                'template'  => 'radio',
                'label'     => __('Accordion/Tab Type', 'fluentformpro'),
                'help_text' => __('Choose if this is a start point, end point, or both', 'fluentformpro'),
                'options'   => [
                    [
                        'value' => 'start',
                        'label' => __('Start', 'fluentformpro')
                    ],
                    [
                        'value' => 'both',
                        'label' => __('Both', 'fluentformpro')
                    ],
                    [
                        'value' => 'close',
                        'label' => __('Close', 'fluentformpro')
                    ]
                ],
            ],
            'start_collapsed' => [
                'template'  => 'inputYesNoCheckBox',
                'label'     => __('Start Collapsed', 'fluentformpro'),
                'help_text' => __('Whether this section should be collapsed/inactive by default', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/accordion_type',
                    'operator' => '!=',
                    'value' => 'close'
                ]
            ],
            'connected_design' => [
                'template'  => 'inputYesNoCheckBox',
                'label'     => __('Connected Design', 'fluentformpro'),
                'help_text' => __('Remove spacing between accordion/tabs for a connected appearance', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/accordion_type',
                    'operator' => '==',
                    'value' => 'both'
                ]
            ],
            'collapse_when_others_open' => [
                'template'  => 'inputYesNoCheckBox',
                'label'     => __('Collapse When Others Opened', 'fluentformpro'),
                'help_text' => __('Automatically collapse this section when any other section is opened', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/accordion_type',
                    'operator' => '!=',
                    'value' => 'close'
                ]
            ],
            'container_class' => [
                'template'  => 'inputText',
                'label'     => __('Container Class', 'fluentformpro'),
                'help_text' => __('Class for the field wrapper. This can be used to style current element.', 'fluentformpro'),
                'dependency' => [
                    'depends_on' => 'settings/accordion_type',
                    'operator' => '!=',
                    'value' => 'close'
                ]
            ]
        ];
    }

    public function pushFormInputType($types)
    {
        return $types;
    }

    public function render($data, $form)
    {
        $elementName = $data['element'];
        $data = apply_filters('fluentform/rendering_field_data_' . $elementName, $data, $form);

        $title = ArrayHelper::get($data, 'settings.title');
        $description = ArrayHelper::get($data, 'settings.description');
        $displayMode = ArrayHelper::get($data, 'settings.display_mode', 'accordion');
        $startCollapsed = ArrayHelper::get($data, 'settings.start_collapsed', 'yes');
        $accordionType = ArrayHelper::get($data, 'settings.accordion_type', 'both');

        // New options
        $connectedDesign = ArrayHelper::get($data, 'settings.connected_design', 'no');
        $collapseWhenOthersOpen = ArrayHelper::get($data, 'settings.collapse_when_others_open', 'yes');

        $containerClass = ArrayHelper::get($data, 'settings.container_class');
        $data['attributes']['class'] .= ' ff-accordion-container ff-accordion-type-' . $accordionType . ' ff-accordion-mode-' . $displayMode . ' ' . $containerClass;

        // Add connected design class if enabled
        if ($connectedDesign === 'yes' && $accordionType == 'both') {
            $data['attributes']['class'] .= ' ff-accordion-connected ff-accordion-connected-both';
        }

        $accordionId = $this->getUniqueid($displayMode);
        $data['attributes']['id'] = $accordionId;
        $data['attributes']['data-accordion_id'] = $accordionId;
        $data['attributes']['data-display_mode'] = $displayMode;
        $data['attributes']['data-start_collapsed'] = $startCollapsed;
        $data['attributes']['data-accordion_type'] = $accordionType;
        $data['attributes']['data-collapse_when_others_open'] = $collapseWhenOthersOpen;

        $atts = $this->buildAttributes(
            ArrayHelper::except($data['attributes'], 'name')
        );

        if ($accordionType === 'start') {
            $html = $this->startSection($atts, $accordionId, $title, $description, $startCollapsed, $displayMode);
        } elseif ($accordionType === 'both') {
            $html = $this->closeSection();
            $html .= $this->startSection($atts, $accordionId, $title, $description, $startCollapsed, $displayMode);
        } else {
            $html = $this->closeSection();
        }
        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form field HTML rendering
    }

    private function closeSection()
    {
        return '</div></div>';
    }

    /**
     * Helper method to generate HTML for starting a section (accordion or tab)
     */
    private function startSection($atts, $accordionId, $title, $description, $startCollapsed, $displayMode = 'accordion')
    {
        $isCollapsed = 'yes' == $startCollapsed;

        if ($displayMode === 'tabs') {
            return $this->startTab($atts, $accordionId, $title, $description, $isCollapsed);
        }

        return $this->startAccordion($atts, $accordionId, $title, $description, $isCollapsed);
    }

    /**
     * Helper method to generate HTML for starting an accordion
     */
    private function startAccordion($atts, $accordionId, $title, $description, $isCollapsed)
    {
        $displayStyle = $isCollapsed ? 'style="display:none"' : '';
        $expandedAttr = $isCollapsed ? 'false' : 'true';
        $escapedAccordionId = esc_attr($accordionId);
        $html = '<div ' . $atts . '>';
        $html .= '<div class="ff-accordion-header ' . ($isCollapsed ? '' : 'ff-accordion-header-open') . '" tabindex="0" role="button" aria-expanded="' . $expandedAttr . '" aria-controls="' . $escapedAccordionId . '-content">';
        $html .= '<h3 class="ff-accordion-title">' . fluentform_sanitize_html($title) . '</h3>';

        if ($description) {
            $html .= '<div class="ff-accordion-description">' . fluentform_sanitize_html($description) . '</div>';
        }

        $html .= '<span class="ff-accordion-toggle"><i class="ff-accordion-icon' . ($isCollapsed ? '' : ' ff-accordion-icon-open') . '"></i></span>';
        $html .= '</div>';

        // The content div
        $html .= '<div id="' . $escapedAccordionId . '-content" class="ff-accordion-content" ' . $displayStyle . ' role="region" aria-labelledby="' . $escapedAccordionId . '">';
        return $html;
    }

    /**
     * Helper method to generate HTML for starting a tab
     */
    private function startTab($atts, $tabId, $title, $description, $isInactive)
    {
        $displayStyle = $isInactive ? 'style="display:none"' : '';
        $selectedAttr = $isInactive ? 'false' : 'true';
        $escapedTabId = esc_attr($tabId);

        // For tabs, we need to render the header inline and content separately
        // The header goes in the container div, content follows
        $html = '<div ' . $atts . '>';
        $html .= '<div data-tab_id="' . $escapedTabId . '" class="ff-tab-header ' . ($isInactive ? '' : 'ff-tab-header-active ff-tab-header-border-none') . '" tabindex="0" role="tab" aria-selected="' . $selectedAttr . '" aria-controls="' . $escapedTabId . '-content">';
        $html .= '<h3 class="ff-tab-title">' . fluentform_sanitize_html($title) . '</h3>';
        $html .= '</div>'; // Close the header & container

        // Start the content div separately (will be closed by closeSection)
        $html .= '<div id="' . $escapedTabId . '-content" class="ff-tab-content" ' . $displayStyle . ' role="tabpanel" aria-labelledby="' . $escapedTabId . '">';

        if ($description) {
            $html .= '<div class="ff-tab-description">' . fluentform_sanitize_html($description) . '</div>';
        }

        return $html;
    }

    /**
     * Register required assets for accordion
     */
    public function registerAssets($form)
    {
        if (is_numeric($form)) {
            $form = Helper::getForm($form);
        }
        $hasAccordion = false;
        foreach (FormFieldsParser::getFields($form, true) as $field) {
            if ($this->key == $field['element']) {
                $hasAccordion = true;
                break;
            }
        }
        if ($hasAccordion) {
            if (!wp_script_is('ff_accordion', 'registered')) {
                wp_register_script(
                    'ff_accordion',
                    FLUENTFORMPRO_DIR_URL . 'public/js/ff_accordion.js',
                    ['jquery'],
                    FLUENTFORMPRO_VERSION,
                    true
                );
            }
            wp_enqueue_script('ff_accordion');

            if (!wp_style_is('ff_accordion', 'registered')) {
                wp_register_style(
                    'ff_accordion',
                    FLUENTFORMPRO_DIR_URL . 'public/css/ff_accordion.css',
                    [],
                    FLUENTFORMPRO_VERSION
                );
            }
            wp_enqueue_style('ff_accordion');
        }
    }

    /**
     * Register required assets for editor accordion component
     */
    public function registerEditorAssets()
    {
        wp_enqueue_script(
            'accordion_editor_component',
            FLUENTFORMPRO_DIR_URL . 'public/js/accordionEditorComponent.js',
            [],
            FLUENTFORMPRO_VERSION,
            true
        );
    }
}
