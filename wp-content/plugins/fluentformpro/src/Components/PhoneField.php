<?php

namespace FluentFormPro\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\Framework\Helpers\ArrayHelper;

class PhoneField extends BaseFieldManager
{
    public function __construct()
    {
        parent::__construct(
            'phone',
            'Phone/Mobile',
            ['phone', 'telephone', 'mobile'],
            'general'
        );

        /*
         * Upgrading component settings
         */
        add_filter('fluentform/editor_init_element_phone', function ($element) {
            if (!isset($element['settings']['phone_country_list'])) {
                $element['settings']['phone_country_list'] = array(
                    'active_list'  => 'all',
                    'visible_list' => array(),
                    'hidden_list'  => array(),
                );
                $element['settings']['default_country'] = '';
            }

            // todo:: remove the 'with_extended_validation' check in future.
            $enabled = ArrayHelper::get($element, 'settings.int_tel_number') == 'with_extended_validation';

            if ($enabled) {
                ArrayHelper::set($element, 'settings.validation_rules.valid_phone_number.value', true);
                ArrayHelper::forget($element, 'settings.int_tel_number');
            }

            return $element;
        });
    }

    function getComponent()
    {
        return [
            'index'          => 18,
            'element'        => $this->key,
            'attributes'     => [
                'name'         => $this->key,
                'class'        => '',
                'value'        => '',
                'type'         => 'tel',
                'autocomplete' => '',
                'placeholder'  => __('Mobile Number', 'fluentformpro')
            ],
            'settings'       => [
                'container_class'     => '',
                'placeholder'         => '',
                'auto_select_country' => 'no',
                'label'               => $this->title,
                'label_placement'     => '',
                'help_message'        => '',
                'admin_field_label'   => '',
                'phone_country_list'  => array(
                    'active_list'  => 'all',
                    'visible_list' => array(),
                    'hidden_list'  => array(),
                ),
                'default_country'     => '',
                'validation_rules'    => [
                    'required'           => [
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    ],
                    'valid_phone_number' => [
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('valid_phone_number'),
                        'global_message' => Helper::getGlobalDefaultMessage('valid_phone_number'),
                    ]
                ],
                'conditional_logics'  => []
            ],
            'editor_options' => [
                'title'      => $this->title,
                'icon_class' => 'el-icon-phone-outline',
                'template'   => 'inputText'
            ],
        ];
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'label_placement',
            'admin_field_label',
            'placeholder',
            'value',
            'auto_select_country',
            'phone_country_list',
            'validation_rules',
        ];
    }

    public function generalEditorElement()
    {
        return [
            'auto_select_country' => [
                'template'   => 'radio',
                'label'      => __('Enable Auto Country Select', 'fluentformpro'),
                'help_text'  => __('If you enable this, The country will be selected based on user\'s ip address. ipinfo.io service will be used here', 'fluentformpro'),
                'options'    => [
                    [
                        'label' => __('No', 'fluentformpro'),
                        'value' => 'no'
                    ],
                    [
                        'label' => __('Yes', 'fluentformpro'),
                        'value' => 'yes'
                    ]
                ],
                'dependency' => array(
                    'depends_on' => 'settings/validation_rules/valid_phone_number/value',
                    'value'      => false,
                    'operator'   => '!='
                )
            ],
            'phone_country_list'  => array(
                'template'       => 'customCountryList',
                'label'          => __('Available Countries', 'fluentformpro'),
                'disable_labels' => true,
                'key'            => 'phone_country_list',
                'dependency'     => array(
                    'depends_on' => 'settings/validation_rules/valid_phone_number/value',
                    'value'      => false,
                    'operator'   => '!='
                )
            )
        ];
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

        $data['attributes']['class'] = @trim(
            'ff-el-form-control ff-el-phone ' . $data['attributes']['class']
        );

        $data['attributes']['id'] = $this->makeElementId($data, $form);

        if ($tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex()) {
            $data['attributes']['tabindex'] = $tabIndex;
        }

        $data['attributes']['inputmode'] = 'tel';

        // todo:: remove the 'with_extended_validation' check in future.
        $enabled = ArrayHelper::get($data, 'settings.validation_rules.valid_phone_number.value');
		if (!$enabled) {
			$enabled = ArrayHelper::get($data, 'settings.int_tel_number') == 'with_extended_validation';
		}

        if ($enabled) {
            // $data['attributes']['placeholder'] = '';
            $data['attributes']['class'] .= ' ff_el_with_extended_validation';
            $this->pushScripts($data, $form);
        }

        $ariaRequired = 'false';
        if (ArrayHelper::get($data, 'settings.validation_rules.required.value')) {
            $ariaRequired = 'true';
        }

        $elMarkup = "<input " . $this->buildAttributes($data['attributes'], $form) . " aria-invalid='false' aria-required={$ariaRequired}>";


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

    private function pushScripts($data, $form)
    {
        // We can add assets for this field
        wp_enqueue_style('intlTelInput');
        wp_enqueue_script('intlTelInput');


        add_action('wp_footer', function () use ($data, $form) {
            $this->ensureFluentFormIntlTelInputPrecedence();
            $geoLocate = ArrayHelper::get($data, 'settings.auto_select_country') == 'yes';

            $itlOptions = [
                'separateDialCode' => false,
                'nationalMode'     => true,
                'autoPlaceholder'  => 'polite',
                'useFullscreenPopup' => false,
                'formatOnDisplay'  => true,
                'validationNumberTypes' => [
                    'MOBILE',
                    'FIXED_LINE_OR_MOBILE',
                    'FIXED_LINE',
                    'TOLL_FREE',
                ]
            ];

            if ($geoLocate) {
                $itlOptions['initialCountry'] = 'auto';
            } else {
                $itlOptions['initialCountry'] = ArrayHelper::get($data, 'settings.default_country', '');
            }
            $activeList = ArrayHelper::get($data, 'settings.phone_country_list.active_list');

            if ($activeList == 'priority_based') {
                $selectCountries = ArrayHelper::get($data, 'settings.phone_country_list.priority_based', []);
                $priorityCountries = $this->getSelectedCountries($selectCountries);
                $itlOptions['countryOrder'] = array_keys($priorityCountries);
            } else if ($activeList == 'visible_list') {
                $onlyCountries = ArrayHelper::get($data, 'settings.phone_country_list.visible_list', []);
                $itlOptions['onlyCountries'] = $onlyCountries;
            } else if ($activeList == 'hidden_list') {
                $countries = $this->loadCountries($data);
                $itlOptions['onlyCountries'] = array_keys($countries);
            }

            $itlOptions = apply_filters_deprecated(
                'fluentform_itl_options',
                [
                    $itlOptions,
                    $data,
                    $form
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/itl_options',
                'Use fluentform/itl_options instead of fluentform_itl_options'
            );

            $itlOptions = apply_filters('fluentform/itl_options', $itlOptions, $data, $form);
            $itlOptions = json_encode($itlOptions);
            $strictValidation = apply_filters('fluentform/phone_field_strict_validation', true, $data, $form) ? 'yes' : 'no';

            $settings = get_option('_fluentform_global_form_settings');
            $token = ArrayHelper::get($settings, 'misc.geo_provider_token');

            $url = 'https://ipinfo.io';
            if ($token) {
                $url = 'https://ipinfo.io/?token=' . $token;
            }
            $url = apply_filters_deprecated(
                'fluentform_ip_provider',
                [
                    $url
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/ip_provider',
                'Use fluentform/ip_provider instead of fluentform_ip_provider'
            );
            $ipProviderUrl = apply_filters('fluentform/ip_provider', $url);

            ?>
            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    function initTelInput() {
                        var intlTelInputFunc = window.intlTelInput;
                        if (typeof intlTelInputFunc == 'undefined') {
                            return;
                        }
                        var telInput = jQuery('.<?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo $form->instance_css_class; ?>').find("#<?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo $data['attributes']['id']; ?>");
                        if (!telInput.length) {
                            return;
                        }

                        var itlOptions = JSON.parse('<?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo $itlOptions; ?>');
                        <?php if($geoLocate && $ipProviderUrl): ?>
                        itlOptions.geoIpLookup = function (success, failure) {
                            jQuery.get("<?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                echo $ipProviderUrl; ?>", function (res) {
                                return true;
                            }, "json").always(function (resp) {
                                var countryCode = (resp && resp.country) ? resp.country : "";
                                if (countryCode) {
                                    success(countryCode);
                                }
                            });
                        };
                        <?php endif; ?>
                        if (telInput.val()) {
                            itlOptions.initialValue = telInput.val();
                        }
                        var iti = intlTelInputFunc(telInput[0], itlOptions);
                        telInput.data('iti', iti);
                        telInput.data('strict_validation', '<?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo $strictValidation; ?>');
                    }

                    initTelInput();
                    $(document).on('reInitExtras', '.<?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $form->instance_css_class; ?>', function () {
                        initTelInput();
                    });
                });
            </script>
            <?php
        }, 9999);
    }

    /**
     * Load countt list from file
     * @param array $data
     * @return array
     */
    protected function loadCountries($data)
    {
        $data['options'] = array();
        $activeList = ArrayHelper::get($data, 'settings.phone_country_list.active_list');
        $countries = getFluentFormCountryList();
        $filteredCountries = [];
        if ($activeList == 'visible_list') {
            $selectCountries = ArrayHelper::get($data, 'settings.phone_country_list.' . $activeList, []);
            foreach ($selectCountries as $value) {
                $filteredCountries[$value] = $countries[$value];
            }
        } elseif ($activeList == 'hidden_list' || $activeList == 'priority_based') {
            $filteredCountries = $countries;
            $selectCountries = ArrayHelper::get($data, 'settings.phone_country_list.' . $activeList, []);
            foreach ($selectCountries as $value) {
                unset($filteredCountries[$value]);
            }
        } else {
            $filteredCountries = $countries;
        }

        return $filteredCountries;
    }

    protected function getSelectedCountries($keys = [])
    {

        $options = [];
        $countries = getFluentFormCountryList();
        foreach ($keys as $value) {
            $options[$value] = $countries[$value];
        }

        return $options;
    }

    /**
     * Ensure FluentForm's intlTelInput takes precedence over other plugins
     */
    private function ensureFluentFormIntlTelInputPrecedence()
    {
        ?>
        <script type="text/javascript">
        if (typeof window.intlTelInput !== 'undefined' && !window.fluentFormIntlTelInput) {
            window.fluentFormIntlTelInput = window.intlTelInput;
        }
        
        jQuery(document).ready(function($) {
            if (window.fluentFormIntlTelInput && typeof window.fluentFormIntlTelInput === 'function') {
                window.intlTelInput = window.fluentFormIntlTelInput;
            }
        });
        </script>
        <?php
    }
}
