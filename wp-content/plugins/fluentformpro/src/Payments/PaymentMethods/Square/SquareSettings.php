<?php

namespace FluentFormPro\Payments\PaymentMethods\Square;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class SquareSettings
{
    public static function getSettings()
    {
        $defaults = [
            'is_active' => 'no',
            'payment_mode' => 'test',
            'test_application_id' => '',
            'test_access_key' => '',
            'test_location_id' => '',
            'live_application_id' => '',
            'live_access_key' => '',
            'live_location_id' => '',
            'notifications' => []
        ];
        
        return wp_parse_args(get_option('fluentform_payment_settings_square', []), $defaults);
    }
    
    public static function isLive()
    {
        $settings = self::getSettings();
        return $settings['payment_mode'] == 'live';
    }
    
    public static function getApiKeys()
    {
        $isLive = self::isLive();
        $settings = self::getSettings();
        
        if($isLive) {
            return [
                'application_id' => $settings['live_application_id'],
                'access_key' => $settings['live_access_key'],
                'location_id' => $settings['live_location_id'],
                'api_url' => "https://connect.squareup.com/v2/locations/{$settings['live_location_id']}/"
            ];
        }
        
        return [
            'application_id' => $settings['test_application_id'],
            'access_key' => $settings['test_access_key'],
            'location_id' => $settings['test_location_id'],
            'api_url' => "https://connect.squareupsandbox.com/v2/locations/{$settings['test_location_id']}/"
        ];
    }
}
