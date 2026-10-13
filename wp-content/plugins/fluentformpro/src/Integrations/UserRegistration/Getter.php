<?php

namespace FluentFormPro\Integrations\UserRegistration;

use FluentForm\App\Services\ConditionAssesor;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentFormPro\Components\Post\AcfHelper;
use FluentFormPro\Components\Post\JetEngineHelper;
use FluentFormPro\Components\Post\MetaboxHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

trait Getter
{
    /**
     * Get the username value from the form data
     * by formatting the shortcode properly.
     */
    public function getUsername($username, $data)
    {
        $username = str_replace(
            ['[', ']'], 
            ['.', ''], 
            $username
        );

        return Arr::get($data, $username);
    }
    public function checkCondition($parsedValue, $formData)
    {
        $conditionSettings = Arr::get($parsedValue, 'conditionals');
        if (
            !$conditionSettings ||
            !Arr::isTrue($conditionSettings, 'status') ||
            !count(Arr::get($conditionSettings, 'conditions'))
        ) {
            return true;
        }

        return ConditionAssesor::evaluate($parsedValue, $formData);
    }
    protected function resetErrormessage($errors, $msg)
    {
        if (!isset($errors['restricted'])) {
            $errors['restricted'] = [];
        }
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
        $errors['restricted'][] = __($msg, 'fluentformpro');
        return $errors;
    }
    public function getFormUserFeeds($formId)
    {
        if (is_object($formId)) {
            $formId = $formId->id;
        }
        return wpFluent()->table('fluentform_form_meta')
            ->where('form_id', $formId)
            ->where('meta_key', 'user_registration_feeds')
            ->get();
    }

    public function formHasUserUpdateFeed($formId)
    {
        foreach ($this->getFormUserFeeds($formId) as $feed) {
            $value = json_decode($feed->value, true);
            if ($value && Arr::isTrue($value, 'enabled') && Arr::get($value, 'list_id') === 'user_update') {
                return true;
            }
        }
        return false;
    }

    public function validate($settings, $settingsFields)
    {
        foreach ($settingsFields['fields'] as $field) {

            if ($field['key'] != 'CustomFields') continue;

            $errors = [];

            foreach ($field['primary_fileds'] as $primaryField) {
                if (!empty($primaryField['required'])) {
                    if (empty($settings[$primaryField['key']])) {
                        $errors[$primaryField['key']] = $primaryField['label'] . ' is required.';
                    }
                }
            }

            if ($errors) {
                wp_send_json_error([
                    'message' => array_shift($errors),
                    'errors' => $errors
                ], 422);
            }
        }

        return $settings;
    }

    protected function updateUser($parsedData, $userId, $feed = [])
    {
        $firstName = trim(Arr::get($parsedData, 'first_name', ''));
        $lastName = trim(Arr::get($parsedData, 'last_name', ''));
        $name = $firstName;
        if ($lastName) {
            $name .= ' ' . $lastName;
        }

        $data = array_filter([
            'ID' => $userId,
            'user_nicename' => Arr::get($parsedData, 'username'),
            'display_name' => trim($name),
            'user_url' => Arr::get($parsedData, 'user_url'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'nickname' => $this->filteredNickname(Arr::get($parsedData, 'nickname', ''), $parsedData),
            'description' => Arr::get($parsedData, 'description'),
        ]);
    
        $listId = Arr::get($feed, 'settings.list_id');
        if ($listId === 'user_update') {
            $data = array_merge($data, array_filter([
                'user_pass'  => Arr::get($parsedData, 'password'),
                'user_email' => Arr::get($parsedData, 'email')
            ]));
        }
		
        if ($data) {
            return wp_update_user($data);
        }

        return new \WP_Error(301, 'Update Failed: Invalid Data');
    }

    protected function addLog($title, $status, $description, $formId, $entryId, $integrationKey)
    {
        $logData = [
            'title' => $title,
            'status' => $status,
            'description' => $description,
            'parent_source_id' => $formId,
            'source_id' => $entryId,
            'component' => $integrationKey,
            'source_type' => 'submission_item'
        ];

        do_action('fluentform/log_data', $logData);

        return true;
    }

	protected function filteredNickname($nickname, $parseDate)
	{
		if (defined('BP_VERSION')){
			$nickname = trim($nickname);
			if (!$nickname) {
				$nickname = Arr::get($parseDate, 'username', '');
			}
			$nickname = preg_replace("/\\s/", '-', trim($nickname));
			return sanitize_user($nickname, true);
		}
		return $nickname;
	}

    protected function updatePluginsMetas($formData, $userId, $feed, $form)
    {
        // Maybe update Acf metas
        AcfHelper::maybeUpdateUserMetas($userId, $formData, $form, $feed);
        // Maybe update Jet Engine metas
        JetEngineHelper::maybeUpdateUserMetas($userId, $formData, $form, $feed);
        // Maybe update Metabox metas
        MetaboxHelper::maybeUpdateUserMetas($userId, $formData, $form, $feed);
    }

}
