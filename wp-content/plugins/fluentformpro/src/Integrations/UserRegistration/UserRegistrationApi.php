<?php

namespace FluentFormPro\Integrations\UserRegistration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\classes\AdminApproval\AdminApproval;
use FluentFormPro\Components\Post\AcfHelper;
use FluentFormPro\classes\UserMetaProtector;

class UserRegistrationApi
{
    use Getter;

    public function getUserRoles()
    {
        if ( ! function_exists( 'get_editable_roles' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $roles = get_editable_roles();

        $validRoles = [];
        foreach ($roles as $roleKey => $role) {
            if (!ArrayHelper::get($role, 'capabilities.manage_options')) {
                $validRoles[$roleKey] = $role['name'];
            }
        }
    
        $validRoles = apply_filters_deprecated(
            'fluentorm_UserRegistration_creatable_roles',
            [
                $validRoles
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/user_registration_creatable_roles',
            'Use fluentform/user_registration_creatable_roles instead of fluentorm_UserRegistration_creatable_roles.'
        );

        return apply_filters('fluentform/user_registration_creatable_roles', $validRoles);
    }
    public function validateSubmittedForm($errors, $data, $form)
    {
        $feeds = $this->getFormUserFeeds($form);

        if (!count($feeds)) {
            return $errors;
        }

        foreach ($feeds as $feed) {
            $parsedValue = json_decode($feed->value, true);

            if (
                ArrayHelper::get($parsedValue, 'list_id') === 'user_update' ||
                !ArrayHelper::isTrue($parsedValue, 'validateForUserEmail')
            ) {
                continue;
            }

            if ($parsedValue && ArrayHelper::isTrue($parsedValue, 'enabled')) {
                // Now check if conditions matched or not
                $isConditionMatched = $this->checkCondition($parsedValue, $data);
                if (!$isConditionMatched) {
                    continue;
                }
                $email = ArrayHelper::get($data, $parsedValue['Email']);
                if (!$email) {
                    continue;
                }

                if (email_exists($email)) {
                    if (!isset($errors['restricted'])) {
                        $errors['restricted'] = [];
                    }
                    $validationMsg = __('This email is already registered. Please choose another one.', 'fluentformpro');
                    $errors['restricted'][] = apply_filters('fluentform/email_exists_validation_message', $validationMsg, $form, $feed, $email);
                    return $errors;
                }

                if(!empty($parsedValue['username'])) {
                    $userName = $this->getUsername($parsedValue['username'], $data);

                    if ($userName) {
                        if (username_exists($userName)) {
                            if (!isset($errors['restricted'])) {
                                $errors['restricted'] = [];
                            }
                            $errors['restricted'][] = __('This username is already registered. Please choose another one.', 'fluentformpro');
                            return $errors;
                        }
                    }
                }
            }
        }

        return $errors;
    }

    public function registerUser($feed, $formData, $entry, $form, $integrationKey)
    {
        $notFromAdminApproval = !(new AdminApproval())->isEntryOnAdminApprovalMode($entry->status);
        $isBypassLogin = apply_filters('fluentform/user_registration_bypass_login', false);

        if (!$isBypassLogin) {
            if ($notFromAdminApproval && get_current_user_id()) {
                return $this->addLog(
                    $feed['settings']['name'],
                    'failed',
                    __("user registration skip because form submitted from login session.", 'fluentformpro'),
                    $form->id,
                    $entry->id,
                    $integrationKey
                );
            }
        }

        $parsedValue = $feed['processedValues'];

        if (!is_email($parsedValue['Email'])) {
            $parsedValue['Email'] = ArrayHelper::get(
                $formData, $parsedValue['Email']
            );
        }

        if (!is_email($parsedValue['Email'])) return $this->addLog(
	        $feed['settings']['name'],
	        'failed',
	        __("user registration skip because email is not valid.", 'fluentformpro'),
	        $form->id,
	        $entry->id,
	        $integrationKey
        );

        if (email_exists($parsedValue['Email'])) return $this->addLog(
	        $feed['settings']['name'],
	        'failed',
	        __("user registration skip because email is already taken other.", 'fluentformpro'),
	        $form->id,
	        $entry->id,
	        $integrationKey
        );

        if (!empty($parsedValue['username'])) {
            $userName = $this->getUsername($parsedValue['username'], $formData);

            if (is_array($userName)) {
	            return $this->addLog(
		            $feed['settings']['name'],
		            'failed',
		            __("user registration skip because username is not valid data-type.", 'fluentformpro'),
		            $form->id,
		            $entry->id,
		            $integrationKey
	            );
            }

            if ($userName && username_exists(sanitize_user($userName))) {
	            return $this->addLog(
		            $feed['settings']['name'],
		            'failed',
		            __("user registration skip because username is already taken other.", 'fluentformpro'),
		            $form->id,
		            $entry->id,
		            $integrationKey
	            );
            }
            if ($userName) {
                $parsedValue['username'] = sanitize_user($userName);
            }
        }

        if (empty($parsedValue['username'])) {
            $parsedValue['username'] = sanitize_user($parsedValue['Email']);
        }

        $feed['processedValues'] = $parsedValue;

        do_action_deprecated(
            'fluentform_user_registration_before_start',
            [
                $feed,
                $entry,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/user_registration_before_start',
            'Use fluentform/user_registration_before_start instead of fluentform_user_registration_before_start.'
        );

        do_action('fluentform/user_registration_before_start', $feed, $entry, $form);

        $this->createUser($feed, $formData, $entry, $form, $integrationKey);
    }

    protected function createUser($feed, $formData, $entry, $form, $integrationKey)
    {
        $feed = apply_filters_deprecated(
            'fluentform_user_registration_feed',
            [
                $feed,
                $entry,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/user_registration_feed',
            'Use fluentform/user_registration_feed instead of fluentform_user_registration_feed.'
        );

        $feed = apply_filters('fluentform/user_registration_feed', $feed, $entry, $form);

        $parsedData = $feed['processedValues'];

        $email = $parsedData['Email'];
        $userName = $parsedData['username'];

        if (empty($parsedData['password'])) {
            $password = wp_generate_password(8);
        } else {
            $password = $parsedData['password'];
        }

        $userId = wp_create_user($userName, $password, $email);

        if (is_wp_error($userId)) {
            return $this->addLog(
                $feed['settings']['name'],
                'failed',
                $userId->get_error_message(),
                $form->id,
                $entry->id,
                $integrationKey
            );
        }

        do_action_deprecated(
            'fluentform_created_user',
            [
                $userId,
                $feed,
                $entry,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/created_user',
            'Use fluentform/created_user instead of fluentform_created_user.'
        );

        do_action('fluentform/created_user', $userId, $feed, $entry, $form);

        Helper::setSubmissionMeta($entry->id, '__created_user_id', $userId);

        $this->updateUser($parsedData, $userId);

        $this->addUserRole($parsedData, $userId);

        $this->addUserMeta($parsedData, $userId, $form->id, [
            'form_id'     => $form->id,
            'entry_id'    => $entry->id,
            'integration' => $integrationKey,
        ]);

        // Update acf, jet-engine, metabox metas
        $this->updatePluginsMetas($formData, $userId, $feed, $form);

        $this->maybeLogin($parsedData, $userId, $entry);

        $this->maybeSendEmail($parsedData, $userId);

        $this->addLog(
            $feed['settings']['name'],
            'success',
            __('user has been successfully created. Created User ID: ', 'fluentformpro') . $userId,
            $form->id,
            $entry->id,
            $integrationKey
        );

        wpFluent()->table('fluentform_submissions')
            ->where('id', $entry->id)
            ->update([
                'user_id' => $userId
            ]);

        do_action_deprecated(
            'fluentform_user_registration_completed',
            [
                $userId,
                $feed,
                $entry,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/user_registration_completed',
            'Use fluentform/user_registration_completed instead of fluentform_user_registration_completed.'
        );

	    do_action('fluentform/user_registration_completed', $userId, $feed, $entry, $form);
    }



    protected function addUserRole($parsedData, $userId)
    {
        $userRoles = $this->getUserRoles();
        $assignedRole = $parsedData['userRole'];

        if (!isset($userRoles[$assignedRole])) {
            $assignedRole = 'subscriber';
        }

        $user = new \WP_User($userId);
        $user->set_role($assignedRole);
    }

    protected function addUserMeta($parsedData, $userId, $formId, $context = [])
    {
        $userMetas = [];
        foreach ($parsedData['userMeta'] as $userMeta) {
            $userMetas[$userMeta['label']] = $userMeta['item_value'];
        }

        $firstName = ArrayHelper::get($parsedData, 'first_name');
        $lastName = ArrayHelper::get($parsedData, 'last_name');

        $userMetas = array_merge($userMetas, [
            'first_name' => $firstName,
            'last_name' => $lastName
        ]);

        if (!isset($userMetas['nickname'])) {
	        $nickname = $firstName . ' ' . $lastName;
			// filter nickname for buddyboss integration
	        $userMetas['nickname'] = $this->filteredNickname($nickname, $parsedData);
        }

        foreach ($userMetas as $metaKey => $metaValue) {
            if (UserMetaProtector::isProtected($metaKey, $userId, $context)) {
                continue;
            }
            if (AcfHelper::maybeUpdateWithAcf($metaKey, $metaValue, 'user_'.$userId)) {
                continue;
            }
            $metaValue = maybe_serialize($metaValue);
            if ($metaValue && $value = trim($metaValue)) {
                update_user_meta($userId, $metaKey, $value);
            }
        }

        update_user_meta($userId, 'fluentform_user_id', $formId);
    }

    protected function maybeLogin($parsedData, $userId, $entry = false)
    {
        if (ArrayHelper::isTrue($parsedData, 'enableAutoLogin')) {
            // check if it's payment success page
            // or direct url
            if(isset($_REQUEST['fluentform_payment_api_notify']) && $entry) {
                // This payment IPN request so let's keep a reference for real request
                Helper::setSubmissionMeta($entry->id, '_make_auto_login', $userId, $entry->form_id);
                return;
            }

            wp_clear_auth_cookie();
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId);
        }
    }

    protected function maybeSendEmail($parsedData, $userId)
    {
        if (ArrayHelper::isTrue($parsedData, 'sendEmailToNewUser')) {
            // This will send an email with password setup link
            \wp_new_user_notification($userId, null, 'user');
        }
    }

    public function userRegistrationMapFields()
    {
        $fields = [
            [
                'key'           => 'Email',
                'label'         => __('Email Address', 'fluentformpro'),
                'input_options' => 'emails',
                'required'      => true,
            ],
            [
                'key'       => 'username',
                'label'     => __('Username', 'fluentformpro'),
                'required'  => false,
                'input_options'  => 'all',
                'help_text' => __('Keep empty if you want the username and user email to be the same', 'fluentformpro'),
            ],
            [
                'key'   => 'first_name',
                'label' => __('First Name', 'fluentformpro')
            ],
            [
                'key'   => 'last_name',
                'label' => __('Last Name', 'fluentformpro')
            ],
            [
                'key'       => 'password',
                'label'     => __('Password', 'fluentformpro'),
                'help_text' => __('Keep empty to be auto generated', 'fluentformpro')
            ]
        ];
    
        $fields = apply_filters_deprecated(
            'fluentform_user_registration_map_fields',
            [
                $fields
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/user_registration_map_fields',
            'Use fluentform/user_registration_map_fields instead of fluentform_user_registration_map_fields.'
        );
        return apply_filters('fluentform/user_registration_map_fields', $fields);
    }
}
