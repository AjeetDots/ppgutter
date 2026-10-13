<?php

namespace FluentFormPro\classes;

use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class AddressAutoComplete
{
    private $optionKey = 'ff_google_maps_autocomplete';
    private $integrationKey = 'google_maps_autocomplete';

    public function init()
    {
        add_filter('fluentform/global_settings_components', array($this, 'addGlobalMenu'), 1);
        add_filter('fluentform/global_integration_settings_' . $this->optionKey, array($this, 'getSettings'), 10);
        add_filter('fluentform/global_integration_fields_' . $this->optionKey, array($this, 'getSettingsFields'), 10, 1);
        add_action('fluentform/save_global_integration_settings_' . $this->optionKey, array($this, 'saveSettings'), 10, 1);

        add_filter('fluentform/editor_vars', function ($vars) {
            if ($this->isEnabled()) {
                $vars['has_address_gmap_api'] = true;
            }
            return $vars;
        });

        add_action('fluentform/address_map_autocomplete', array($this, 'addGmapJs'), 99, 2);
        add_filter('fluentform/conversational_form_address_gmap_api_key', array($this, 'convJsGmapApiKey'), 10, 1);

        add_filter('fluentform/editor_init_element_address', function ($element) {
            // Legacy settings for Pro compatibility @todo remove in future
            if (!isset($element['settings']['enable_g_autocomplete'])) {
                $element['settings']['enable_g_autocomplete'] = '';
            }
            if (!isset($element['settings']['enable_g_map'])) {
                $element['settings']['enable_g_map'] = '';
            }

            return $element;
        });

        add_filter('fluentform/rendering_field_data_address', function ($data, $form) {
            $provider = ArrayHelper::get($data, 'settings.autocomplete_provider');
            $legacyGoogleEnable = ArrayHelper::get($data, 'settings.enable_g_autocomplete', 'no') === 'yes';
            // Treat missing provider as legacy
            $isLegacyProvider = !ArrayHelper::has($data, 'settings.autocomplete_provider');

            // Add locate button icon for supported providers
            if (
                $this->ifShowLocateButton($data) &&
                (
                    $provider === 'google' ||
                    $provider === 'html5' ||
                    ($isLegacyProvider && $legacyGoogleEnable)
                )
            ) {
                $inputName =  ArrayHelper::get(current($data['fields']),'attributes.name');
                $locateIcon = $this->getLocateIcon();
                ArrayHelper::set($data, 'fields.'.$inputName.'.settings.suffix_label',$locateIcon);
            }
            return $data;
        },10,2);

        add_action('fluentform/before_form_entry_app', array($this, 'addGmapScript'), 10, 1);

        // Add coordinate fields to address elements for shortcode support
        add_filter('fluentform/extractor_parser_custom_fields', array($this, 'addCoordinateFields'), 10, 2);
    }

    public function addGmapScript($formId)
    {
        $apiKey = $this->convJsGmapApiKey('');
        if(empty($apiKey)) {
            return;
        }
        wp_enqueue_script(
            'google-maps',
            'https://maps.googleapis.com/maps/api/js?key=' . $apiKey . '&loading=async',
            array(),
            null,
            true
        );
    }

    public function addGlobalMenu($setting)
    {
        $setting['google_maps_autocomplete'] = [
            'hash'         => $this->integrationKey,
            'component'    => 'general-integration-settings',
            'settings_key' => $this->optionKey,
            'title'        => 'Google Maps Integration'
        ];
        return $setting;
    }

    public function getSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);
        if (!$globalSettings) {
            $globalSettings = [];
        }

        $defaults = [
            'api_key'         => '',
            'status'          => '',
            'places_api_new'  => 'no',
            'advanced_marker' => 'no',
            'map_id'          => '',
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function getSettingsFields($fields)
    {
        return [
            'logo'             => FLUENTFORMPRO_DIR_URL . 'public/images/google_map.png',
            'menu_title'       => __('Google Map Integration Settings', 'fluentformpro'),
            'menu_description' => __('For address autocomplete feature you may setup google map API details', 'fluentformpro'),
            'valid_message'    => __('Google Map API is set.', 'fluentformpro'),
            'invalid_message'  => __('Google Map API key is not valid', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'api_key'         => [
                    'type'        => 'password',
                    'placeholder' => '',
                    'label_tips'  => __("Enter your Google Map API Key", 'fluentformpro'),
                    'label'       => __('Google Map API Key', 'fluentformpro'),
                ],
                'places_api_new'  => [
                    'type'           => 'checkbox_yes_no',
                    'checkbox_label' => __('Use Google Places API (New)', 'fluentformpro'),
                    'label'          => __('Places API (New)', 'fluentformpro'),
                    'label_tips'     => __('Use the new Google Places API for address autocomplete. Requires "Places API (New)" to be enabled in your Google Cloud Console. Leave off to keep the legacy Places autocomplete.', 'fluentformpro'),
                ],
                'advanced_marker' => [
                    'type'           => 'checkbox_yes_no',
                    'checkbox_label' => __('Use Advanced Marker for the map', 'fluentformpro'),
                    'label'          => __('Advanced Marker', 'fluentformpro'),
                    'label_tips'     => __('Use AdvancedMarkerElement instead of the deprecated Marker. Requires a Map ID. Only applies when Places API (New) is enabled.', 'fluentformpro'),
                    'dependency'     => [
                        [
                            'depends_on' => 'places_api_new',
                            'value'      => 'yes',
                            'operator'   => '==',
                        ],
                    ],
                ],
                'map_id'          => [
                    'type'        => 'text',
                    'placeholder' => '',
                    'label'       => __('Google Map ID', 'fluentformpro'),
                    'label_tips'  => __('Required for the Advanced Marker. Create a Map ID in your Google Cloud Console (Map Management).', 'fluentformpro'),
                    'dependency'  => [
                        [
                            'depends_on' => 'advanced_marker',
                            'value'      => 'yes',
                            'operator'   => '==',
                        ],
                    ],
                ],
            ],
            // Keep the form (with these toggles) visible after a key is saved so
            // the options stay reachable on this screen.
            'hide_on_valid'    => false,
        ];
    }

    public function saveSettings($settings)
    {
        $key = $settings['api_key'];

        if (!$key) {
            $defaults = [
                'api_key'         => '',
                'status'          => '',
                'places_api_new'  => 'no',
                'advanced_marker' => 'no',
                'map_id'          => '',
            ];
            update_option($this->optionKey, $defaults, 'no');
            wp_send_json_success([
                'message' => __('Your settings has been updated and discarded', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        $placesApiNew = 'yes' === ArrayHelper::get($settings, 'places_api_new') ? 'yes' : 'no';
        $advancedMarker = ('yes' === $placesApiNew && 'yes' === ArrayHelper::get($settings, 'advanced_marker')) ? 'yes' : 'no';

        update_option($this->optionKey, [
            'api_key'         => sanitize_text_field($settings['api_key']),
            'status'          => true,
            'places_api_new'  => $placesApiNew,
            'advanced_marker' => $advancedMarker,
            'map_id'          => sanitize_text_field(ArrayHelper::get($settings, 'map_id', '')),
        ], 'no');

        wp_send_json_success([
            'message' => __('Google Map Api key has been saved', 'fluentformpro'),
            'status'  => true
        ], 200);

    }

    public function isEnabled()
    {
        $settings = $this->getSettings([]);

        return !empty($settings['api_key']) && $settings['status'];
    }

    public function addGmapJs($data, $form)
    {
        $provider = ArrayHelper::get($data, 'settings.autocomplete_provider', 'none');
        $legacyGoogleEnable = 'yes' == ArrayHelper::get($data, 'settings.enable_g_autocomplete', 'no');
        $isLegacyProvider = !ArrayHelper::has($data, 'settings.autocomplete_provider');

        // Only enqueue if needed
        if (in_array($provider, ['google', 'html5']) || ($isLegacyProvider && $legacyGoogleEnable)) {
            wp_enqueue_script(
                'ff_address_autocomplete',
                FLUENTFORMPRO_DIR_URL . 'public/js/ff_address_autocomplete.js',
                ['jquery'],
                FLUENTFORM_VERSION,
                true
            );
            $settings = $this->getSettings([]);
            wp_localize_script('ff_address_autocomplete', 'ff_gmap_vars', [
                'api_key'         => $this->convJsGmapApiKey(''),
                'places_api_new'  => ArrayHelper::get($settings, 'places_api_new', 'no'),
                'advanced_marker' => $this->useAdvancedMarker() ? 'yes' : 'no',
                'map_id'          => ArrayHelper::get($settings, 'map_id', ''),
            ]);
        }

        // Only enqueue Google Maps API if provider is google and API key is present
        if ($provider === 'google' || ($isLegacyProvider && $legacyGoogleEnable)) {
            $apiKey = $this->convJsGmapApiKey('');
            if ($apiKey) {
                global $wp_scripts;
                $shouldEnqueue = true;
                $hasValidKey = false;
                
                // Check if Google Maps is already registered/enqueued
                if ($wp_scripts && isset($wp_scripts->registered['google-maps'])) {
                    $existingSrc = $wp_scripts->registered['google-maps']->src;
                    
                    // Extract API key from existing script URL
                    if (preg_match('/[?&]key=([^&]*)/', $existingSrc, $matches)) {
                        $existingKey = trim($matches[1]);
                        
                        // If existing key is valid, use it instead of re-enqueuing
                        if (!empty($existingKey)) {
                            $shouldEnqueue = false;
                            $hasValidKey = true;
                        } else {
                            // Empty key found - remove the bad registration
                            wp_dequeue_script('google-maps');
                            wp_deregister_script('google-maps');
                        }
                    } else {
                        // No key parameter found - remove and re-enqueue with key
                        wp_dequeue_script('google-maps');
                        wp_deregister_script('google-maps');
                    }
                }
                
                // Enqueue with Fluent Forms API key if needed
                if ($shouldEnqueue) {
                    wp_enqueue_script(
                        'google-maps',
                        $this->mapsLoaderSrc($apiKey),
                        ['ff_address_autocomplete'],
                        FLUENTFORM_VERSION,
                        true
                    );
                } elseif ($hasValidKey) {
                    // Script already loaded with valid key - ensure callback is called
                    add_action('wp_footer', function() {
                        ?>
                        <script>
                        if (typeof google !== 'undefined' && google.maps && google.maps.places && typeof window.fluentform_gmap_callback === 'function') {
                            window.fluentform_gmap_callback();
                        }
                        </script>
                        <?php
                    }, 999);
                }
            }
        }
    }

    public function convJsGmapApiKey($apiKey)
    {
        $settings = $this->getSettings([]);

        if (!empty($settings['api_key'])) {
            $apiKey = $settings['api_key'];
        }
        return $apiKey;
    }

    /**
     * Build the Google Maps JS loader URL. Loads the `marker` library in
     * addition to `places` only when the Advanced Marker is fully configured.
     *
     * @param string $apiKey
     * @return string
     */
    public function mapsLoaderSrc($apiKey)
    {
        $libraries = $this->useAdvancedMarker() ? 'places,marker' : 'places';

        return 'https://maps.googleapis.com/maps/api/js?key=' . $apiKey
            . '&loading=async&libraries=' . $libraries
            . '&callback=fluentform_gmap_callback';
    }

    /**
     * Advanced Marker is used only when Places API (New) is on, the toggle is
     * enabled, and a Map ID is provided (AdvancedMarkerElement requires one).
     *
     * @return bool
     */
    public function useAdvancedMarker()
    {
        $settings = $this->getSettings([]);

        return 'yes' === ArrayHelper::get($settings, 'places_api_new')
            && 'yes' === ArrayHelper::get($settings, 'advanced_marker')
            && !empty(ArrayHelper::get($settings, 'map_id'));
    }

    /**
     * @param $data
     * @return bool
     */
    private function ifShowLocateButton($data)
    {
        $provider = ArrayHelper::get($data, 'settings.autocomplete_provider');
        $autoLocate = ArrayHelper::get($data, 'settings.enable_auto_locate');

        if ($provider === 'google') {
            return $this->isEnabled() && ($autoLocate == 'on_load' || $autoLocate == 'on_click');
        } elseif ($provider === 'html5') {
            return ($autoLocate == 'on_load' || $autoLocate == 'on_click');
        }

        // Fallback to legacy logic
        if (ArrayHelper::get($data, 'settings.enable_g_autocomplete') == 'yes' &&
            ($autoLocate == 'on_load' || $autoLocate == 'on_click')
        ) {
            return $this->isEnabled();
        }

        return false;
    }

    /**
     * Add coordinate fields to address elements for shortcode support.
     *
     *
     * @param array $customFields Array of address sub-fields
     * @param array $field The parent address field configuration
     * @return array Modified custom fields with coordinate fields added
     */
    public function addCoordinateFields($customFields, $field)
    {
        if (ArrayHelper::get($field, 'element') !== 'address') {
            return $customFields;
        }

        $hasLatitude = false;
        $hasLongitude = false;

        foreach ($customFields as $subField) {
            $fieldName = ArrayHelper::get($subField, 'attributes.name');
            if ($fieldName === 'latitude') $hasLatitude = true;
            if ($fieldName === 'longitude') $hasLongitude = true;
        }

        if (!$hasLatitude) {
            $customFields['latitude'] = [
                'attributes' => ['name' => 'latitude'],
                'settings' => ['label' => 'Latitude']
            ];
        }
        if (!$hasLongitude) {
            $customFields['longitude'] = [
                'attributes' => ['name' => 'longitude'],
                'settings' => ['label' => 'Longitude']
            ];
        }

        return $customFields;
    }

    /**
     * @return string
     */
    private function getLocateIcon()
    {
        return '<span class="ff-locate-icon " style="cursor:pointer;"><svg   xmlns="http://www.w3.org/2000/svg" width="20" viewBox="0 0 30 30"><path d="M 14.984375 0.98632812 A 1.0001 1.0001 0 0 0 14 2 L 14 3.0507812 C 8.1822448 3.5345683 3.5345683 8.1822448 3.0507812 14 L 2 14 A 1.0001 1.0001 0 1 0 2 16 L 3.0507812 16 C 3.5345683 21.817755 8.1822448 26.465432 14 26.949219 L 14 28 A 1.0001 1.0001 0 1 0 16 28 L 16 26.949219 C 21.817755 26.465432 26.465432 21.817755 26.949219 16 L 28 16 A 1.0001 1.0001 0 1 0 28 14 L 26.949219 14 C 26.465432 8.1822448 21.817755 3.5345683 16 3.0507812 L 16 2 A 1.0001 1.0001 0 0 0 14.984375 0.98632812 z M 14 5.0488281 L 14 6 A 1.0001 1.0001 0 1 0 16 6 L 16 5.0488281 C 20.732953 5.5164646 24.483535 9.2670468 24.951172 14 L 24 14 A 1.0001 1.0001 0 1 0 24 16 L 24.951172 16 C 24.483535 20.732953 20.732953 24.483535 16 24.951172 L 16 24 A 1.0001 1.0001 0 0 0 14.984375 22.986328 A 1.0001 1.0001 0 0 0 14 24 L 14 24.951172 C 9.2670468 24.483535 5.5164646 20.732953 5.0488281 16 L 6 16 A 1.0001 1.0001 0 1 0 6 14 L 5.0488281 14 C 5.5164646 9.2670468 9.2670468 5.5164646 14 5.0488281 z"></path></svg></span>';
    }
}
