<?php

namespace FluentFormPro\Integrations\UserRegistration;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormDataParser;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentFormPro\Components\Post\AcfHelper;
use FluentFormPro\Components\Post\JetEngineHelper;
use FluentFormPro\Components\Post\MetaboxHelper;
use FluentFormPro\classes\UserMetaProtector;


class UserUpdateFormHandler
{
    use Getter;

    public function maybePopulateUserUpdateForm($form)
    {
        $feeds = $this->getFormUserFeeds($form);
        if (!count($feeds)) {
            return $form;
        }
        foreach ($feeds as $feed) {
            $feed->value = json_decode($feed->value, true);
            if ($this->isValidFeed($feed->value)) {
                return $this->populateUserUpdateForm($form, $feed->value);
            }
        }

        return $form;
    }

    public function addWhiteListedFields($whiteListKeys, $formId)
    {
        $feeds = $this->getFormUserFeeds($formId);
        if (!count($feeds)) {
            return $whiteListKeys;
        }
        foreach ($feeds as $feed) {
            $feed->value = json_decode($feed->value, true);
            if ($this->isValidFeed($feed->value) && $this->hasPluginsMetas($feed->value)) {
                if ($form = Helper::getForm($formId)) {
                    $fileOrImageTypeFields = FormFieldsParser::getElement($form, ['input_file', 'input_image'], ['raw']);
                    $acfFields = array_merge(Arr::get($feed->value, 'acf_mappings.general', []), Arr::get($feed->value, 'acf_mappings.advanced', []));
                    $jetEngineFields = array_merge(Arr::get($feed->value, 'jetengine_mappings.general', []), Arr::get($feed->value, 'jetengine_mappings.advanced', []));
                    $metaboxFields = array_merge(Arr::get($feed->value, 'metabox_mappings.general', []), Arr::get($feed->value, 'metabox_mappings.advanced', []));
                    foreach (array_merge($acfFields, $jetEngineFields, $metaboxFields) as $field) {
                        $name = Arr::get($field, 'field_value');
                        if ($name && preg_match('/{+(.*?)}/', $name)) {
                            $name = Helper::getInputNameFromShortCode($name);
                        }
                        if ($name &&  Arr::has($fileOrImageTypeFields, $name)) {
                            $whiteListKeys[] = "remove-attachment-key-$name";
                            $whiteListKeys[] = "existing-attachment-key-$name";
                        }
                    }
                    break;
                }
            }
        }
        return $whiteListKeys;
    }

    public function maybeEnqueueUserUpdateScript($form)
    {
        $feeds = $this->getFormUserFeeds($form);
        if (!count($feeds)) {
            return;
        }
        foreach ($feeds as $feed) {
            $feed->value = json_decode($feed->value, true);
            if ($this->isValidFeed($feed->value) && $this->hasPluginsMetas($feed->value)) {
                if (!wp_script_is('fluentformpro_user_update', 'registered')) {
                    wp_register_script(
                        'fluentformpro_user_update',
                        FLUENTFORMPRO_DIR_URL . 'public/js/fluentformproUserUpdate.js',
                        ['jquery'],
                        FLUENTFORMPRO_VERSION,
                        true
                    );
                }
                wp_enqueue_script('fluentformpro_user_update');
                wp_localize_script('fluentformpro_user_update', 'fluentformpro_user_update_vars', array_merge([
                    'form_id' => $form->id,
                ], $this->getMetaPluginsMappingValues($feed->value, $form)));
            }
        }
    }

    protected function getMetaPluginsMappingValues($feed, $form)
    {
        $metas = [];
        if (AcfHelper::hasAcf() && $acfMapping = Arr::get($feed, 'acf_mappings')) {
            $metas['acf_mappings'] = AcfHelper::getUserMappingValue($acfMapping);
        }

        if (JetEngineHelper::hasJetEngine() && $jetEngineMapping = Arr::get($feed, 'jetengine_mappings')) {
            $metas['jetengine_mappings'] = JetEngineHelper::getUserMappingValue($form, $jetEngineMapping);
        }

        if (MetaboxHelper::hasMetabox() && $metaboxMapping = Arr::get($feed, 'metabox_mappings')) {
            $metas['metabox_mappings'] = MetaboxHelper::getUserMappingValue($metaboxMapping);
        }

        return $metas;
    }

    protected function isValidFeed($feed)
    {
        return Arr::isTrue($feed, 'enabled') &&
            Arr::get($feed, 'list_id') === 'user_update';
    }

    protected function hasPluginsMetas($feed)
    {
        return Arr::isTrue($feed, 'acf_mappings.general') ||
            Arr::isTrue($feed, 'acf_mappings.advanced') ||
            Arr::isTrue($feed, 'jetengine_mappings.general') ||
            Arr::isTrue($feed, 'jetengine_mappings.advanced') ||
            Arr::isTrue($feed, 'metabox_mappings.general') ||
            Arr::isTrue($feed, 'metabox_mappings.advanced');
    }

    protected function populateUserUpdateForm($form, $feed)
    {
        if (!get_current_user_id()) {
           return $form;
        }
        $populateFieldsKeys = $this->getFilterUserUpdateFieldsKey($feed);
        $formFields = $form->fields['fields'];
        $populateFields = [];
        foreach ($formFields as $index => $formField) {
            if ('container' == Arr::get($formField, 'element')) {
                foreach ($formField['columns'] as &$column) {
                    foreach ($column['fields'] as &$field) {
                        $this->populateUserUpdateFormField($field, $populateFieldsKeys);
                    }
                }
            } else {
                $this->populateUserUpdateFormField($formField, $populateFieldsKeys);
            }
            $populateFields[$index] = $formField;
        }
        $form->fields['fields'] = $populateFields;
        return $form;
    }

    protected function populateUserUpdateFormField(&$formField, $populateFieldsKeys)
    {
        foreach ($populateFieldsKeys as $key => $value) {
            $name = '';
            if (preg_match('/\./', $value)) {
                $value = explode('.', $value);
                $name = $value[0];
                $value = $value[1];
            }

            if (
                (
                    Arr::get($formField, 'element') === 'input_name' ||
                    Arr::get($formField, 'element') === 'address'
                ) &&
                Arr::get($formField, 'attributes.name') === $name &&
                Arr::has($formField['fields'], $value)
            ) {
                $subValue = 'fields.' . $value;
                $subFiled = Arr::get($formField, $subValue);
                if (
                    $subFiled &&
                    Arr::get($subFiled,'attributes.name') === $value
                ) {
                    $subFiled['attributes']['value'] = $this->getUserMetaValue($key);
                    $subFiled['attributes']['data-user-update-key'] = $key;
                    if ($key === 'username') {
                        $subFiled['attributes']['readonly'] = true;
                        $subFiled['attributes']['disable'] = true;
                    }
                    $formField['fields'][$value] = $subFiled;
                }
            } elseif (Arr::get($formField, 'attributes.name') === $value) {
                $formField['attributes']['value'] = $this->getUserMetaValue($key);
                $formField['attributes']['data-user-update-key'] = $key;
                if ($key === 'username') {
                    $formField['attributes']['readonly'] = true;
                    $formField['attributes']['disable'] = true;
                }
            }
        }
    }

    public function validateSubmittedForm($errors, $data, $form)
    {
        $feeds = $this->getFormUserFeeds($form);

        if (!count($feeds)) {
            return $errors;
        }

        foreach ($feeds as $feed) {
            $feed->value = json_decode($feed->value, true);
            if (
                $feed->value &&
                Arr::isTrue($feed->value, 'enabled') &&
                Arr::get($feed->value, 'list_id') === 'user_update'
            ) {
                $isConditionMatched = $this->checkCondition($feed->value, $data);
                if (!$isConditionMatched) {
                    continue;
                }

                $updateFields = $this->getFilterUserUpdateFieldsKey($feed->value);
                if ($errors = $this->handleUsernameErrors($errors, $data, $updateFields)){
                    return $errors;
                }

                if ($errors = $this->handleEmailErrors($errors, $data, $updateFields)){
                    return $errors;
                }

                if (
                    Arr::get($updateFields, 'password') &&
                    $errors =  $this->handlePasswordErrors($errors, $data, $updateFields)
                ) {
                   return $errors;
                }
            }
        }

        return $errors;
    }

    /**
     * Object-injection-safe unserialize with a backward-compatible fallback.
     *
     * Prefers Helper::safeUnserialize() (free plugin), but older free versions
     * may not ship it yet, so fall back to the same object-free unserialize
     * inline to avoid a fatal on those installs.
     *
     * TODO: Remove this shim and call Helper::safeUnserialize() directly once the
     * minimum required free plugin version guarantees Helper::safeUnserialize().
     */
    protected static function unserializeSafely($data)
    {
        if (method_exists(Helper::class, 'safeUnserialize')) {
            return Helper::safeUnserialize($data);
        }
        if (is_serialized($data)) {
            return @unserialize(trim($data), ['allowed_classes' => false]);
        }
        return $data;
    }

    protected function getUserMetaValue($key) {
        $userId = get_current_user_id();
        $profileUser = get_userdata($userId);

        if ($customMetaKey = $this->getOriginalMetaKey('u_custom_meta_', $key)) {
            $value = get_user_meta($userId, $customMetaKey);
            if(count($value)) {
                return self::unserializeSafely($value[0]);
            }
            return '';
        }

	    if ($bbFieldKey = $this->getOriginalMetaKey('u_buddyboss_field_key_', $key)) {
			if (defined('BP_VERSION')) {
				return xprofile_get_field_data(trim($bbFieldKey), get_current_user_id());
			}
			return '';
		}

        switch ($key) {
            case 'username' :
                return $profileUser->user_login;
            case 'email':
                return $profileUser->user_email;
            case 'first_name':
                return $profileUser->first_name;
            case 'last_name':
                return $profileUser->last_name;
            case 'nickname':
                return $profileUser->nickname;
            case 'user_url':
                return $profileUser->user_url;
            case 'description':
                return $profileUser->description;
            default:
                return '';
        }
    }

	protected function getOriginalMetaKey ($filterStr, $key)
	{
		if (preg_match("/^$filterStr/", $key, $matches)) {
			return str_replace($filterStr, '', $key);
		}
		return false;
	}

    protected function getFilterUserUpdateFieldsKey($feed) {
        $keys = $this->supportedFieldsKey();
        $keys[] = 'userMeta';
        $keys[] = 'bboss_profile_fields';
        $formatFields = [];
        foreach ($keys as $key) {
            if ($value = Arr::get($feed, $key)) {
                if ($key === 'userMeta') {
                    foreach ($value as $meta) {
                        $formatFields[ "u_custom_meta_" . $meta['label'] ] = $this->getUserUpdateFormFieldName( $meta['item_value'] );
                    }
                } else if ($key === 'bboss_profile_fields') {
	                foreach ($value as $meta) {
		                $formatFields[ "u_buddyboss_field_key_" . $meta['label'] ] = $this->getUserUpdateFormFieldName( $meta['item_value'] );
	                }
                } else {
                    $formatFields[$key] = $this->getUserUpdateFormFieldName($value);
                }
            }
        }
        return $formatFields;
    }

    protected function getUserUpdateFormFieldName($value)
    {
        $formFieldName = '';
        if (!$value) return $formFieldName;
        preg_match('/{+(.*?)}/', $value, $matches);
        if (count($matches) > 1 && strpos($matches[1], 'inputs.') !== false) {
            $field = substr( $matches[1], strlen( 'inputs.' ) );
            $formFieldName = $field;
        }
        return $formFieldName;
    }


    public function supportedFieldsKey ()
    {
        $supportedFields =  $this->userUpdateMapFields();
        $fieldsKey = [];
        foreach ($supportedFields as $supportedField) {
            $fieldsKey[] = $supportedField['key'];
        }
        return $fieldsKey;
    }

    public function userUpdateMapFields()
    {
        $fields = [
            [
                'key'       => 'username',
                'label'     => __('Username', 'fluentformpro'),
                'required'  => false,
                'help_text' => __('simple text field for username reference.', 'fluentformpro')
            ],
            [
                'key' => 'email',
                'label' => __('Email Address', 'fluentformpro'),
                'required'  => false,
                'help_text' => __('email reference field', 'fluentformpro')
            ],
            [
                'key'   => 'first_name',
                'label' => __('First Name', 'fluentformpro'),
                'help_text'  => __('first name reference field', 'fluentformpro')
            ],
            [
                'key'   => 'last_name',
                'label' => __('Last Name', 'fluentformpro'),
                'help_text'  => __('last name reference field', 'fluentformpro')
            ],
            [
                'key'  => 'nickname',
                'label'  => __('Nickname', 'fluentformpro'),
                'help_text'  => __('nickname reference field', 'fluentformpro')
            ],
            [
                'key'  => 'user_url',
                'label'  => __('Website Url', 'fluentformpro'),
                'help_text'  => __('website reference field', 'fluentformpro')
            ],
            [
                'key'  => 'description',
                'label'  => __('Biographical Info', 'fluentformpro'),
                'help_text'  => __('description reference field', 'fluentformpro')
            ],
            [
                'key'       => 'password',
                'label'     => __('Password', 'fluentformpro'),
                'help_text' => __('password reference field', 'fluentformpro')
            ],
            [
                'key' => 'repeat_password',
                'label'     => __('Repeat Password', 'fluentformpro'),
                'help_text' => __('repeat password reference field', 'fluentformpro')
            ]
        ];
    
        $fields = apply_filters_deprecated(
            'fluentform_user_update_map_fields',
            [
                $fields
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/user_update_map_fields',
            'Use fluentform/user_update_map_fields instead of fluentform_user_update_map_fields.'
        );

        return apply_filters('fluentform/user_update_map_fields', $fields);
    }

    protected function getDataValueByKey($data, $fields, $key) {
        if (Arr::get($fields, $key)) {
            if ($value = Arr::get($data, $fields[$key])) {
                return $value;
            }
        }
        if ('username' === $key) {
            return $this->getUserMetaValue($key);
        }
        return '';
    }

    protected function handleEmailErrors($errors = [], $data = [], $fields = [])
    {
        $email = $this->getDataValueByKey($data, $fields, 'email');
        if ($email && !is_email($email)) {
            return $this->resetErrormessage($errors, __('Invalid Email. Please provide an valid email', 'fluentformpro'));
        }

        if ($email != $this->getUserMetaValue('email') && email_exists($email)) {
            return $this->resetErrormessage($errors, __('This email is already registered. Please choose another one.', 'fluentformpro'));
        }
        return $errors;
    }

    protected function handleUsernameErrors($errors = [], $data = [], $fields = [])
    {
        $username = $this->getDataValueByKey($data, $fields, 'username');

        if (!$username || $username != $this->getUserMetaValue('username')) {
            return $this->resetErrormessage($errors, __('Username cannot be changed.', 'fluentformpro'));
        }
        return $errors;
    }

    protected function handlePasswordErrors($errors, $data, $fields) {
        $password = $this->getDataValueByKey($data, $fields, 'password');

        $confirmPass = $this->getDataValueByKey($data, $fields, 'repeat_password');
        if(
            Arr::get($fields, 'repeat_password') &&
            $password &&
            $password !== $confirmPass
        ) {
            return $this->resetErrormessage($errors, __('Confirm password not match', 'fluentformpro'));
        }
        return $errors;
    }

    public function captureUpdateTarget($insertId, $formData, $form)
    {
        if (!$this->formHasUserUpdateFeed($form->id)) {
            return;
        }
        // Write once. On notify_on_form_submit this fires only at submit, so the guard is
        // belt-and-suspenders; '0' (anonymous) is a real value, so test row absence, not falsiness.
        if (null !== Helper::getSubmissionMeta($insertId, '__update_target_user_id', null)) {
            return;
        }
        Helper::setSubmissionMeta($insertId, '__update_target_user_id', (string) get_current_user_id(), $form->id);
    }

    /**
     * The submit nonce is opt-in plugin-wide because page caches serve stale
     * nonces, but a user_update feed rewrites the SUBMITTER's own account, so a
     * submit forged from a logged-in victim's browser must not go through.
     * Anonymous submissions have no account to rewrite and keep the default.
     */
    public function requireNonceForSelfUpdate($shouldVerify, $formId)
    {
        if ($shouldVerify || !get_current_user_id()) {
            return $shouldVerify;
        }

        return $this->formHasUserUpdateFeed($formId);
    }

    public function handleUpdateUser($feed, $formData, $entry, $form, $integrationKey)
    {
        FormDataParser::parseFormEntry($entry, $form, null, true);

        // Target from submission-tied state, never the session: the created account, then
        // the submitter captured at submit. A missing capture skips — never the
        // reassignable submission->user_id (an entries-manager can rebind it to a victim).
        $createdUserId = (int) Helper::getSubmissionMeta($entry->id, '__created_user_id');
        $capturedTarget = Helper::getSubmissionMeta($entry->id, '__update_target_user_id', null);

        $userId = $createdUserId ?: (int) $capturedTarget;

        if (!$userId) {
            return $this->addLog(
                $feed['settings']['name'],
                'info',
                __("User update skipped: the submission has no associated account.", 'fluentformpro'),
                $form->id,
                $entry->id,
                $integrationKey
            );
        }

        // A logged-in processor must be the target user or have explicit authority
        // to edit that account. Keep sessionless payment/opt-in processing intact.
        $currentUserId = get_current_user_id();
        if (
            $currentUserId &&
            $currentUserId !== $userId &&
            !current_user_can('edit_user', $userId)
        ) {
            return $this->addLog(
                $feed['settings']['name'],
                'failed',
                __('User update skipped: the current user cannot edit the target account.', 'fluentformpro'),
                $form->id,
                $entry->id,
                $integrationKey
            );
        }

        $parsedData = Arr::get($feed, 'processedValues');

        $mappingUsername = Arr::get($parsedData, 'username');
        if ($mappingUsername) {
            $targetUser = get_userdata($userId);
            $username = $targetUser ? $targetUser->user_login : '';
            if ($mappingUsername !== $username) {
                return $this->addLog(
                    $feed['settings']['name'],
                    'failed',
                    __('User update skip because trying to change username.', 'fluentformpro'),
                    $form->id,
                    $entry->id,
                    $integrationKey
                );
            }
        }

        $email = Arr::get($parsedData, 'email');
        if ($email && !is_email($email)) {
            return $this->addLog(
                $feed['settings']['name'],
                'failed',
                __("User update skip because of invalid email.", 'fluentformpro'),
                $form->id,
                $entry->id,
                $integrationKey
            );
        }
        $userId = $this->updateUser($parsedData, $userId, $feed);
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

        Helper::setSubmissionMeta($entry->id, '__updated_user_id', $userId);

        $this->updateUserMeta($parsedData, $userId, $form->id, [
            'form_id'     => $form->id,
            'entry_id'    => $entry->id,
            'integration' => $integrationKey,
        ]);

        // Update acf, jet-engine, metabox metas
        $this->updatePluginsMetas($formData, $userId, $feed, $form);

        $this->addLog(
            $feed['settings']['name'],
            'success',
            __('User has been successfully updated. Updated User ID: ', 'fluentformpro') . $userId,
            $form->id,
            $entry->id,
            $integrationKey
        );

        do_action_deprecated(
            'fluentform_user_update_completed',
            [
                $userId,
                $feed,
                $entry,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/user_update_completed',
            'Use fluentform/user_update_completed instead of fluentform_user_update_completed.'
        );

	    do_action('fluentform/user_update_completed', $userId, $feed, $entry, $form);
    }

    protected function updateUserMeta($parsedData, $userId, $formId, $context = [])
    {
        $userMetas = [];
        foreach ($parsedData['userMeta'] as $userMeta) {
            $userMetas[$userMeta['label']] = $userMeta['item_value'];
        }

        foreach ($userMetas as $metaKey => $metaValue) {
            if (UserMetaProtector::isProtected($metaKey, $userId, $context)) {
                continue;
            }
            // Explicit target: omitting it defaults to the session user, which on the
            // admin-approval path is the approving admin, not the submitter.
            if (AcfHelper::maybeUpdateWithAcf($metaKey, $metaValue, 'user_' . $userId)) {
                continue;
            }
            $metaValue = maybe_serialize($metaValue);
            update_user_meta($userId, $metaKey, trim($metaValue));
        }

        update_user_meta($userId, 'fluentform_user_id', $formId);
    }
}
