<?php

namespace FluentFormPro\Integrations\BuddyIntegration;

defined('ABSPATH') or die;

class FluentFormBuddyBoss
{
    public function init()
    {
        add_filter('fluentform/user_registration_feed_fields', array($this, 'pushSettingsFields'));
        add_filter('fluentform/user_update_feed_fields', array($this, 'pushSettingsFields'));
        add_filter('fluentform/user_registration_field_defaults', array($this, 'pushFieldDefaults'));
        add_action('fluentform/user_registration_completed', array($this, 'saveDataToUser'), 10, 3);
        add_action('fluentform/user_update_completed', array($this, 'saveDataToUser'), 10, 3);
    }

    public function pushFieldDefaults($defaults)
    {
        $defaults['bboss_profile_fields'] = [
            [
                'label'      => '',
                'item_value' => ''
            ]
        ];
        $defaults['bboss_profile_type'] = '';

        return $defaults;
    }

    public function pushSettingsFields($fields)
    {

        $groupField = false;
        if (function_exists('bp_xprofile_get_groups')) {
            $profileFields = [];
            $groups = bp_xprofile_get_groups(
                array(
                    'fetch_fields' => true
                )
            );

            foreach ($groups as $group) {
                foreach ($group->fields as $field) {
                    $profileFields[$field->id . ' '] = $field->name; // We need to key as string so adding an extra space
                }
            }

            $groupField = [
                'key'         => 'bboss_profile_fields',
                'label'       => __('Profile Fields', 'fluentformpro'),
                'component'   => 'dropdown_many_fields',
                'options'     => $profileFields,
                'remote_text' => __('X-Profile Field', 'fluentformpro'),
                'local_text'  => __('Form Field', 'fluentformpro'),
                'tips'        => __('Map your BuddyBoss x-profile fields with your form fields', 'fluentformpro')
            ];
        }

        $memberTypeFields = false;

        if (function_exists('bp_get_member_types')) {
            $profileTypes = [];
            $member_types = bp_get_member_types(array(), 'objects');
            foreach ($member_types as $typeName => $member_type) {
                $profileTypes[$typeName] = $member_type->labels['name'];
            }

            if ($profileTypes) {
                $memberTypeFields = [
                    'key'       => 'bboss_profile_type',
                    'label'     => __('BuddyBoss Profile Type', 'fluentformpro'),
                    'component' => 'select',
                    'options'   => $profileTypes,
                    'tips'      => __('Select BuddyBoss Profile Type', 'fluentformpro')
                ];
            }
        }

        if ($groupField || $memberTypeFields) {
            $fields[] = [
                'key'       => 'html_info',
                'label'     => '',
                'component' => 'html_info',
                'html_info' => __('<h3 style="margin-bottom: 0">BuddyPress / BuddyBoss Settings</h3><hr />', 'fluentformpro')
            ];
        }

        if ($groupField) {
            $fields[] = $groupField;
        }

        if ($memberTypeFields) {
            $fields[] = $memberTypeFields;
        }

        return $fields;
    }

    /*
     * This function will be called once user registration has been completed
     */
    public function saveDataToUser($userId, $feed, $entry)
    {
        $xProfileFields = \FluentForm\Framework\Helpers\ArrayHelper::get($feed, 'processedValues.bboss_profile_fields', []);
        $parsedXFields = [];
        foreach ($xProfileFields as $xProfileField) {
            if (!empty($xProfileField['item_value'])) {
                $fieldId = intval($xProfileField['label']);
                $parsedXFields[$fieldId] = esc_html($xProfileField['item_value']);
            }
        }

        if ($parsedXFields) {
            $this->setXProfileFields($userId, $parsedXFields);
        }

        $profileTypeSlug = \FluentForm\Framework\Helpers\ArrayHelper::get($feed, 'processedValues.bboss_profile_type');

        if ($profileTypeSlug) {
            $this->setProfileType($userId, $profileTypeSlug, $entry);
        }
    }

    private function setProfileType($userId, $profileSlug, $entry)
    {
        bp_set_member_type($userId, $profileSlug);

        $logData = [
            'title'            => __('BuddyBoss Profile has been created - User Id: ', 'fluentformpro') . $userId,
            'status'           => 'success',
            'description'      => __('Profile has been created in BuddyBoss. Profile Type: ', 'fluentformpro') . $profileSlug,
            'parent_source_id' => $entry->form_id,
            'source_id'        => $entry->id,
            'component'        => 'UserRegistration',
            'source_type'      => 'submission_item'
        ];

        do_action('fluentform/log_data', $logData);
    }

    private function setXProfileFields($userId, $fields)
    {
        foreach ($fields as $fieldKey => $value) {
            if ($fieldKey == bp_xprofile_nickname_field_id()) {
                update_user_meta($userId, 'nickname', $value); //sync wp user nickname with buddyBoss
            }
			if ( strpos($value, ', ') !== false && $field_type = \BP_XProfile_Field::get_type( $fieldKey )) {
				if ( $field_type == 'checkbox') {
					$value = explode(', ', $value);
				}
			}
            xprofile_set_field_data($fieldKey, $userId, $value);
        }
    }
}
