<?php

namespace FluentFormPro\classes;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Browser\Browser;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentForm\App\Models\Form;

class DraftSubmissionsManager
{
    protected $app = null;

    protected static $tableName = 'fluentform_draft_submissions';

    protected static $cookieName = 'fluentform_step_form_hash';

    public function __construct($app)
    {
        $this->app = $app;
        $this->init();
    }

    public function init()
    {
        add_action('init', [$this, 'maybeLoadSavedProgress'], 99);
        add_action('fluentform/submission_inserted', [$this, 'delete'], 10, 3);
        add_filter('fluentform/form_fields_update', [$this, 'checkPartialSettings'], 10, 2);
        $this->registerAjaxHandlers();

        // after partial submission using Save & Resume button, handle notifications from here
        add_action('fluentform/saved_progress_submission_added', [$this, 'sendAdminEmailNotification'], 10, 4);
        add_action('fluentform/saved_progress_submission_updated', [$this, 'sendAdminEmailNotificationWhenUpdate'], 10, 4);
    }

    public function sendAdminEmailNotification($formData, $response, $insertId, $formId)
    {
        $this->sendPartialEntryEmail($formData, $insertId, $formId, 'create');
    }

    public function sendAdminEmailNotificationWhenUpdate($formData, $activeStep, $updatedId, $formId)
    {
        $this->sendPartialEntryEmail($formData, $updatedId, $formId, 'update');
    }

    private function sendPartialEntryEmail($formData, $entryId, $formId, $action)
    {
        $form = Form::find($formId);
        $actionVerb = $action == 'create' ? 'created' : 'updated';
        $clickedBtnNameAttr = $this->app->request->get('save_progress_btn_name');
        $saveProgressButton = $this->getSaveProgressButtonData($formId, $clickedBtnNameAttr);

        if (Arr::get($saveProgressButton, "settings.send_email_on_entry_{$action}")) {
            $emailAddress = Arr::get($saveProgressButton, "settings.on_{$action}_email_address", get_option('admin_email'));

            if (!is_email($emailAddress)) {
                wp_send_json_error([
                    // phpcs:ignore WordPress.WP.I18n.InterpolatedVariableText
                    'Error' => __("Please provide a valid email address to send entry {$action} email",
                        'fluentformpro')
                ], 423);
            }

            $entry = wpFluent()->table(static::$tableName)
                               ->where('id', $entryId)
                               ->first();

            if (!$entry) {
                wp_send_json_error([
                    'Error' => __('No partial entry found!', 'fluentformpro')
                ], 423);
            }

            $entry = (array)$entry;

            $emailSubject = Arr::get(
                $saveProgressButton,
                "settings.on_{$action}_email_subject",
                sprintf(
                    // translators: %1$s is the action verb, %2$s is the form name, %3$s is the partial entry ID
                    __('A Partial entry %1$s on form: %2$s and partial entry ID: %3$s', 'fluentformpro'),
                    $actionVerb,
                    '{form_name}',
                    '{partial_entry_id}'
                )
            );

            $emailBody = Arr::get(
                $saveProgressButton,
                "settings.on_{$action}_email_body",
                "<p>Hello,</p>
                <p>A partial submission has been {$actionVerb} on {form_name}. See the submission using the link below.</p>
                <p>
                    <a style='color: #ffffff; background-color: #3f9eff; text-decoration: none; font-weight: normal; font-style: normal; padding: 0.5rem 1rem; border-color: #0072ff;' href='{partial_entry_link}'>View partial submission</a>
                </p>
                <p>Thank you</p>"
            );

            $emailFormat = $this->processAdminEmail($emailAddress, $emailSubject, $emailBody, $form, $entryId);
            $emailFormat = apply_filters('fluentform/partial_entry_admin_email_format', $emailFormat, $entry,
                $form);

            do_action('fluentform/before_partial_entry_admin_email', $emailFormat, $entry, $form);

            $notifier = $this->app->make('FluentForm\App\Services\FormBuilder\Notifications\EmailNotification');
            $notifier->notify($emailFormat, $entry, $form);
        }
    }

    public static function boot($app)
    {
        return new static($app);
    }

    public function registerAjaxHandlers()
    {
        $this->app->addAdminAjaxAction('fluentform_step_form_save_data', [$this, 'saveWithCookie']);
        $this->app->addPublicAjaxAction('fluentform_step_form_save_data', [$this, 'saveWithCookie']);
        $this->app->addAdminAjaxAction('fluentform_step_form_get_data', [$this, 'getEntry']);
        $this->app->addPublicAjaxAction('fluentform_step_form_get_data', [$this, 'getEntry']);
        $this->app->addAdminAjaxAction('fluentform_save_form_progress_with_link', [$this, 'saveWithLink']);
        $this->app->addPublicAjaxAction('fluentform_save_form_progress_with_link', [$this, 'saveWithLink']);
        $this->app->addAdminAjaxAction('fluentform_email_progress_link', [$this, 'emailProgressLink']);
        $this->app->addPublicAjaxAction('fluentform_email_progress_link', [$this, 'emailProgressLink']);
        $this->app->addAdminAjaxAction('fluentform_get_form_state', [$this, 'getEntryFromLink']);
        $this->app->addPublicAjaxAction('fluentform_get_form_state', [$this, 'getEntryFromLink']);
    }

    public static function get($hash, $formId = false)
    {
        if ($formId) {
            return wpFluent()->table(static::$tableName)
                ->where('hash', $hash)
                ->where('form_id', $formId)
                ->first();
        }
        return wpFluent()->table(static::$tableName)->where('hash', $hash)->first();
    }

    public function getEntry()
    {
        $this->verify();
        $data = null;
        $entry = false;
        $attributes = $this->app->request->all();
        $formId = intval(Arr::get($attributes, 'form_id'));
        $userId = (int)get_current_user_id();

        // Logged-in users: always prefer their own latest draft for this form.
        // This makes cross-device restore work and prevents stale cookie hashes
        // from pinning a user to an older row.
        if ($userId) {
            $entry = wpFluent()->table(static::$tableName)
                ->where('user_id', $userId)
                ->where('form_id', $formId)
                ->orderBy('updated_at', 'desc')
                ->first();
        }

        // Cookie-hash fallback. Required for anonymous users; also lets logged-in
        // users pick up a draft they started anonymously on the same browser.
        // Privacy guard discards a hash that points to another user's draft.
        if (!$entry && ($hash = $this->getHash())) {
            $candidate = $this->get($hash, $formId);
            if ($this->isDraftAccessibleToCurrentUser($candidate)) {
                $entry = $candidate;
            }
        }

        if (!$entry) {
            $this->getEntryFromLink();
        }

        if ($entry) {
            $data['step_completed'] = (int)$entry->step_completed;
            $data['response'] = json_decode($entry->response, true);
            $form = wpFluent()->table('fluentform_forms')->where('id', $formId)->first();
            if ($form) {
                $fields = FormFieldsParser::getInputsByElementTypes($form, ['input_file', 'input_image']);
                foreach ($fields as $name => $field) {
                    if ($urls = Arr::get($data['response'], $name)) {
                        foreach ($urls as $index => $url) {
                            $data['response'][$name][$index] = [
                                "data_src" => $url,
                                "url" => \FluentForm\App\Helpers\Helper::maybeDecryptUrl($url)
                            ];
                        }
                    }
                }
            }
            unset(
                $data['response']['_wp_http_referer'],
                $data['response']['__fluent_form_embded_post_id'],
                $data['response']['_fluentform_' . $entry->form_id . '_fluentformnonce']
            );
        }

        wp_send_json($data, 200);
    }

    public function getEntryFromLink()
    {
        $this->verify();
        $data = null;
        $requestData = $this->app->request->all();
        $hash = sanitize_text_field(Arr::get($requestData, 'hash', ''));
        $formId = intval(Arr::get($requestData, 'form_id'));
        $entry = $this->get($hash, $formId);

        // SECURITY (PRO-33): the same privacy guard applied to the cookie hash must also apply to a
        // request-supplied hash. The hash is a bearer uuid that leaks via the ?fluent_state resume
        // URL (Referer, shared links, history); without this check a user-owned draft (user_id > 0)
        // — including its decrypted upload URLs — was served to anyone presenting the hash.
        if ($entry && !$this->isDraftAccessibleToCurrentUser($entry)) {
            $entry = null;
        }

        if (!$entry && $userId = get_current_user_id()) {
            $entry = wpFluent()->table(static::$tableName)
                ->where('user_id', $userId)
                ->where('form_id', $formId)
                ->first();
        }

        if ($entry) {
            // Check if this form has "use link once" enabled
            $form = Form::find($formId);
            $useOnce = false;

            // Parse form fields to find save progress buttons
            $fields = FormFieldsParser::getFields($form, true);
            foreach ($fields as $field) {
                if (Arr::get($field, 'element') == 'save_progress_button') {
                    if (Arr::get($field, 'settings.use_link_once', false)) {
                        $useOnce = true;
                        break;
                    }
                }
            }

            // If use once is enabled and the link has been used, reject it
            if ($useOnce && $entry->used) {
                wp_send_json_error([
                    'message' => __('This resume link has already been used and is no longer valid.', 'fluentformpro')
                ], 403);
                return;
            }

            // If use once is enabled, mark as used
            if ($useOnce) {
                wpFluent()->table(static::$tableName)
                    ->where('id', $entry->id)
                    ->update(['used' => 1]);
            }
            
            $data['step_completed'] = (int)$entry->step_completed;
            $data['response'] = json_decode($entry->response, true);
            $form = wpFluent()->table('fluentform_forms')->where('id', $formId)->first();
            if ($form) {
                $fields = FormFieldsParser::getInputsByElementTypes($form, ['input_file', 'input_image']);
                foreach ($fields as $name => $field) {
                    if ($urls = Arr::get($data['response'], $name)) {
                        foreach ($urls as $index => $url) {
                            $data['response'][$name][$index] = [
                                "data_src" => $url,
                                "url" => \FluentForm\App\Helpers\Helper::maybeDecryptUrl($url)
                            ];
                        }
                    }
                }
            }
            unset(
                $data['response']['_wp_http_referer'],
                $data['response']['__fluent_form_embded_post_id'],
                $data['response']['_fluentform_' . $entry->form_id . '_fluentformnonce']
            );
        }

        wp_send_json($data, 200);
    }

    public function saveWithLink()
    {
        $formData = $this->app->request->get();
        $formId = intval($this->app->request->get('form_id'));
        $clickedBtnNameAttr = $this->app->request->get('save_progress_btn_name');
        $saveProgressButton = $this->getSaveProgressButtonData($formId, $clickedBtnNameAttr);
        if (!$saveProgressButton) {
            wp_send_json_error(
                [
                    'message' => __('No Save & Resume button found.', 'fluentformpro'),
                ]
            );
        }

        $successMessage = Arr::get($saveProgressButton,'settings.save_success_message');
        $this->verify();
        // A resume hash is a server-generated uuid used as a DB key and later echoed into a
        // frontend attribute. sanitize_key() restricts it to [a-z0-9_-], stripping the quotes,
        // spaces and '=' an attribute-breakout XSS needs, while leaving a real uuid untouched.
        $hash = isset($formData['hash']) ? sanitize_key($formData['hash']) : -1;

        if (!$hash || $hash == -1) {
            $hash = $this->getHash();
        }
        $form = Form::find($formId);
        $useOnce = false;

        $fields = FormFieldsParser::getFields($form, true);
        foreach ($fields as $field) {
            if (Arr::get($field, 'element') == 'save_progress_button') {
                if (Arr::get($field, 'settings.use_link_once', false)) {
                    $useOnce = true;
                    break;
                }
            }
        }

        // Reset the 'used' flag when saving/updating if needed. Apply the same
        // privacy guard as saveState — never touch another user's row even if
        // a stale cookie/hash matches one.
        $existingEntry = $this->get($hash);
        if ($existingEntry && $useOnce && $this->isDraftAccessibleToCurrentUser($existingEntry)) {
            wpFluent()->table(static::$tableName)
                ->where('hash', $hash)
                ->update(['used' => 0]);
        }

        // saveState may rebind $hash to the canonical row for this user
        // (cross-device reconciliation). Use the returned value so the share
        // URL points at a row that actually exists.
        $canonicalHash = $this->saveState($hash);
        if ($canonicalHash) {
            $hash = $canonicalHash;
        }
        $sourceUrl = $this->getSavedLink($hash);
        wp_send_json_success(
            [
                'saved_url' => $sourceUrl,
                'hash'      => $hash,
                'message'   => $successMessage,
            ]
        );
    }

    public function saveWithCookie()
    {
        $this->verify();
        $hash = $this->getHash();
        $this->saveState($hash);
        wp_send_json_success();
    }

    public function saveState($hash)
    {
        $formData = $this->app->request->get();
        parse_str($formData['data'], $formData['data']);

        $formId = (int)$formData['form_id'];
        $form = wpFluent()->table('fluentform_forms')->find($formId);
        if (!$form) {
            return;
        }
        $fields = FormFieldsParser::getEntryInputs($form);
        $formData['data'] = fluentFormSanitizer($formData['data'], null, $fields);

        $isStepForm = true;
        $activeStep = (int)($formData['active_step'] ?? -1);

        if ($formData['active_step'] == 'no') {
            $activeStep = -1;
            $isStepForm = false;
        }

        $hasSaveProgressBtn = false;
        if (isset($formData['save_progress_btn_name'])) {
            $hasSaveProgressBtn = true;
        }

        $response = json_encode($formData['data']);
        $userId = (int)get_current_user_id();
        $exist = $this->get($hash);

        // Privacy: a cookie hash pointing to another user's draft must not be
        // updated as if it were ours. Treat as no-existing and fall through.
        if ($exist && !$this->isDraftAccessibleToCurrentUser($exist)) {
            $exist = null;
        }

        // Cross-device consistency: when a logged-in user starts saving on a
        // new browser/device (no matching cookie hash), reuse their existing
        // draft for this form instead of inserting a duplicate row. Rewrite the
        // local hash so the cookie is rebound to the canonical row.
        if (!$exist && $userId) {
            $existingForUser = wpFluent()->table(static::$tableName)
                ->where('user_id', $userId)
                ->where('form_id', $formId)
                ->orderBy('updated_at', 'desc')
                ->first();
            if ($existingForUser) {
                $exist = $existingForUser;
                $hash = $existingForUser->hash;
            }
        }

        if (!$exist) {
            $browser = new Browser();
            $ipAddress = $this->app->request->getIp();
            $status = apply_filters_deprecated(
                'fluentform_disable_ip_logging',
                [
                    false,
                    $formId
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/disable_ip_logging',
                'Use fluentform/disable_ip_logging instead of fluentform_disable_ip_logging.'
            );
            if ((defined('FLUENTFROM_DISABLE_IP_LOGGING') && FLUENTFROM_DISABLE_IP_LOGGING) || apply_filters('fluentform/disable_ip_logging', $status, $formId)) {
                $ipAddress = false;
            }

            $response = [
                'form_id'        => $formId,
                'hash'           => $hash,
                'response'       => $response,
                'source_url'     => site_url(Arr::get($formData, 'data._wp_http_referer')),
                'user_id'        => get_current_user_id(),
                'browser'        => $browser->getBrowser(),
                'device'         => $browser->getPlatform(),
                'ip'             => $ipAddress,
                'step_completed' => $activeStep,
                'created_at'     => current_time('mysql'),
                'type'           => $isStepForm ? 'step_data' : 'saved_state_data',
                'updated_at'     => current_time('mysql')
            ];
            $insertId = wpFluent()->table(static::$tableName)->insertGetId($response);

            if ($isStepForm) {
                do_action_deprecated(
                    'fluentform_partial_submission_added',
                    [
                        $formData['data'],
                        $response,
                        $insertId,
                        $formId
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/partial_submission_added',
                    'Use fluentform/partial_submission_added instead of fluentform_partial_submission_added.'
                );
                do_action('fluentform/partial_submission_added', $formData['data'], $response, $insertId, $formId);

                do_action_deprecated(
                    'fluentform_partial_submission_step_completed',
                    [
                        1,
                        $formData['data'],
                        $insertId,
                        $formId
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/partial_submission_step_completed',
                    'Use fluentform/partial_submission_step_completed instead of fluentform_partial_submission_step_completed.'
                );
                do_action('fluentform/partial_submission_step_completed', 1, $formData['data'], $insertId, $formId);
            }

            if ($hasSaveProgressBtn) {
                do_action_deprecated(
                    'fluentform_saved_progress_submission_added',
                    [
                        $formData['data'],
                        $response,
                        $insertId,
                        $formId
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/saved_progress_submission_added',
                    'Use fluentform/saved_progress_submission_added instead of fluentform_saved_progress_submission_added.'
                );
                do_action('fluentform/saved_progress_submission_added', $formData['data'], $response, $insertId, $formId);
            }
        }
        else {
            $updates = [
                'response'       => $response,
                'step_completed' => $activeStep,
                'updated_at'     => current_time('mysql')
            ];
            // Claim an anonymous-owned draft for the current user so future
            // sessions resolve via the user-bound lookup.
            if ($userId && (int)$exist->user_id === 0) {
                $updates['user_id'] = $userId;
            }
            wpFluent()->table(static::$tableName)->where('id', $exist->id)->update($updates);
            if ($isStepForm) {
                do_action_deprecated(
                    'fluentform_partial_submission_step_completed',
                    [
                        $activeStep,
                        $formData['data'],
                        $exist->id,
                        $formId
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/partial_submission_step_completed',
                    'Use fluentform/partial_submission_step_completed instead of fluentform_partial_submission_step_completed.'
                );
                do_action('fluentform/partial_submission_step_completed', $activeStep, $formData['data'], $exist->id, $formId);

                do_action_deprecated(
                    'fluentform_partial_submission_updated',
                    [
                        $formData['data'],
                        $activeStep,
                        $exist->id,
                        $formId
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/partial_submission_updated',
                    'Use fluentform/partial_submission_updated instead of fluentform_partial_submission_updated.'
                );
                do_action('fluentform/partial_submission_updated', $formData['data'], $activeStep, $exist->id, $formId);
            }

            if ($hasSaveProgressBtn) {
                do_action_deprecated(
                    'fluentform_saved_progress_submission_updated',
                    [
                        $formData['data'],
                        $activeStep,
                        $exist->id,
                        $formId
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/saved_progress_submission_updated',
                    'Use fluentform/saved_progress_submission_updated instead of fluentform_saved_progress_submission_updated.'
                );
                do_action('fluentform/saved_progress_submission_updated', $formData['data'], $activeStep, $exist->id, $formId);
            }
        }

        if ($isStepForm) {
            $this->setcookie($this->getCookieName($formId), $hash, $this->getExpiryDate());
        }

        return $hash;
    }

    public function delete($insertId, $formData, $form)
    {
        $this->deleteSavedStateDraft($form, $formData);
        $this->deleteStepFormDraft($form);
    }

    protected function getHash()
    {
        $name = $this->getCookieName();
        if (isset($_COOKIE[$name])) {
            return sanitize_text_field(wp_unslash($_COOKIE[$name]));
        }
        return wp_generate_uuid4();
    }

    /**
     * Returns true when a draft row is allowed to be loaded by the current request.
     *
     * Privacy rule: a draft owned by a real user (user_id > 0) may only be
     * loaded by that same user. Anonymous-owned drafts (user_id = 0) stay
     * cookie-bound and may be claimed by a logged-in user later.
     */
    protected function isDraftAccessibleToCurrentUser($entry)
    {
        if (!$entry) {
            return false;
        }
        $entryUserId = (int)($entry->user_id ?? 0);
        if ($entryUserId === 0) {
            return true;
        }
        return (int)get_current_user_id() === $entryUserId;
    }

    protected function getCookieName($formId = false)
    {
        $formId = $formId ? $formId : $this->app->request->get('form_id');

        return static::$cookieName . '_' . $formId;
    }

    protected function getExpiryDate($previousTime = false)
    {
        $offset = 7 * 24 * 60 * 60;
        return ($previousTime) ? time() - $offset : time() + $offset;
    }

    protected function setCookie($name, $value, $expiryDate)
    {
        setcookie(
            $name,
            $value,
            $expiryDate,
            COOKIEPATH,
            COOKIE_DOMAIN
        );
    }

    protected function deleteCookie($formId = false)
    {
        $this->setcookie($this->getCookieName($formId), '', $this->getExpiryDate(true));
    }

    public function checkPartialSettings($fields, $formId)
    {
        $fieldsArray = \json_decode($fields, true);
        $isPartialEnabled = 'no';
        if (isset($fieldsArray['stepsWrapper'])) {
            $isPartialEnabled = Arr::get($fieldsArray, 'stepsWrapper.stepStart.settings.enable_step_data_persistency',
                'no');
        }
        self::migrate();
        Helper::setFormMeta($formId, 'step_data_persistency_status', $isPartialEnabled);

        $savedStateButton = array_filter($fieldsArray['fields'], function ($field) {
            return Arr::get($field, 'element') == 'save_progress_button';
        });
        if (!empty($savedStateButton)) {
            Helper::setFormMeta($formId, 'form_save_state_status', 'yes');
        } else {
            Helper::setFormMeta($formId, 'form_save_state_status', 'no');
        }
        return $fields;
    }

    /**
     * Migrate the table.
     *
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . static::$tableName;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $sql = "CREATE TABLE $table (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `form_id` INT UNSIGNED NULL,
            `hash` VARCHAR(255) NOT NULL,
            `type` VARCHAR(255) DEFAULT 'step_data',
            `step_completed` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `response` LONGTEXT NULL,
            `source_url` VARCHAR(255) NULL,
            `browser` VARCHAR(45) NULL,
            `device` VARCHAR(45) NULL,
            `ip` VARCHAR(45) NULL,
            `used` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP NULL,
            `updated_at` TIMESTAMP NULL,
             PRIMARY KEY (`id`),
             KEY `user_form_updated_idx` (`user_id`, `form_id`, `updated_at`),
             KEY `hash_idx` (`hash`(191)) ) $charsetCollate;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        } else {
            // Check if 'used' column exists
            $row = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'used'");
            if (empty($row)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN `used` TINYINT(1) DEFAULT 0 AFTER `ip`");
            }

            // Composite index for the (user_id, form_id) latest-draft lookup
            // used by getEntry() and saveState() reconciliation. Built once
            // via SHOW INDEX guard so subsequent migrate() calls no-op.
            $userFormIdx = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'user_form_updated_idx'");
            if (empty($userFormIdx)) {
                $wpdb->query("ALTER TABLE {$table} ADD INDEX `user_form_updated_idx` (`user_id`, `form_id`, `updated_at`)");
            }

            // Index on hash for DraftSubmissionsManager::get($hash) lookups.
            // Prefix length 191 keeps it utf8mb4-safe under InnoDB row formats.
            $hashIdx = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'hash_idx'");
            if (empty($hashIdx)) {
                $wpdb->query("ALTER TABLE {$table} ADD INDEX `hash_idx` (`hash`(191))");
            }
        }
    }

    /**
     * @param $hash
     */
    private function getSavedLink($hash, $sourceUrl = null)
    {
        if (is_null($sourceUrl)) {
            $postData = $this->app->request->get();
            $sourceUrl = Arr::get($postData, 'source_url', '');
        }

        $sourceUrl = sanitize_url($sourceUrl);
        $slug = 'fluent_state';
        if (strpos($sourceUrl, '?') !== false) {
            $sourceUrl .= '&';
        } else {
            $sourceUrl .= '?';
        }
        $pattern = "/(?<={$slug}=).*$/";

        preg_match($pattern, $sourceUrl, $match);
        if (!empty($match)) {
            return str_replace($match[0], base64_encode($hash), $sourceUrl);
        }

        return $sourceUrl . "{$slug}=" . base64_encode($hash);
    }

    private function getHashFromResumeLink($link)
    {
        $link = sanitize_url($link);
        if (!$link) {
            return '';
        }

        $linkHost = wp_parse_url($link, PHP_URL_HOST);
        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$linkHost || !$siteHost || strtolower($linkHost) !== strtolower($siteHost)) {
            return '';
        }

        $query = wp_parse_url($link, PHP_URL_QUERY);
        if (!$query) {
            return '';
        }

        parse_str($query, $queryArgs);
        $encodedHash = Arr::get($queryArgs, 'fluent_state', '');
        if (!$encodedHash) {
            return '';
        }

        $hash = base64_decode(sanitize_text_field($encodedHash), true);
        return $hash ? sanitize_text_field($hash) : '';
    }

    public function maybeLoadSavedProgress()
    {
        $key = isset($_GET['fluent_state']) ? sanitize_text_field($_GET['fluent_state']) : false;

        if (!$key) {
            return;
        }

        $key = base64_decode($key);
        // Output boundary: the stored hash is echoed into form_state_save_vars / conversational
        // inputs, so constrain it to [a-z0-9_-] here too. Neutralises rows poisoned before the
        // store-time guard shipped, without relying on the frontend to escape it.
        $key = sanitize_key($key);
        $draftForm = \FluentFormPro\classes\DraftSubmissionsManager::get($key);
        if (!$draftForm) {
            return;
        }

        $form = Form::find($draftForm->form_id);
        $fields = FormFieldsParser::getFields($form, true);
        foreach ($fields as $field) {
            if (Arr::get($field, 'element') == 'save_progress_button') {
                if (
                    Arr::get($field, 'settings.save_resume_for_logged_in_user')
                    && !is_user_logged_in()
                ) {
                    return;
                }
            }
        }
    
        add_filter('fluentform/save_progress_vars', function ($vars) use ($key) {
            $vars['key'] = $key;
            return $vars;
        });

        add_filter('fluentform/conversational_extra_inputs', function ($inputs) use ($key) {
            $inputs['__fluent_state_hash'] = $key;
            return $inputs;
        });

        add_action('wp_enqueue_scripts', function () use ($key) {
            $vars = apply_filters('fluentform/save_progress_vars', [
                'source_url'          => esc_url(home_url(sanitize_text_field($_SERVER['REQUEST_URI']))),
                'key'                 => $key,
                'nonce'               => wp_create_nonce(),
                'copy_button'         => sprintf("<img src='%s' >", fluentFormMix('img/copy.svg')),
                'copy_success_button' => sprintf("<img src='%s' >", fluentFormMix('img/check.svg')),
                'email_button'        => sprintf("<img src='%s' >", fluentFormMix('img/email.svg')),
            ]);
            wp_localize_script('form-save-progress', 'form_state_save_vars', $vars);

            add_filter('fluentform/global_form_vars', function($data) use ($key) {
                $data['hash'] = $key;
                return $data;
            }, 9, 1);
        });
    }
    
    public function emailProgressLink()
    {
        $this->verify();

        // SECURITY (PRO-34): this nopriv endpoint sends mail to a request-supplied recipient, and
        // the gating hash can be minted by the attacker for their own draft — an open mail relay
        // from the site's own address (reputation damage / blacklisting). The feature is anonymous
        // self-service so recipient ownership cannot be required; throttle it hard per IP instead.
        $rlIp = $this->app->request->getIp();
        $rlKey = 'ff_resume_link_email_rl_' . md5((string) $rlIp);
        $rlCount = (int) get_transient($rlKey);
        if ($rlCount >= apply_filters('fluentform/resume_link_email_hourly_limit', 5)) {
            wp_send_json_error([
                'Error' => __('Too many requests. Please try again later.', 'fluentformpro')
            ], 429);
        }
        set_transient($rlKey, $rlCount + 1, HOUR_IN_SECONDS);

        $requestData = $this->app->request->get();
        $hash = sanitize_text_field(Arr::get($requestData, 'hash', ''));
        if (!$hash || $hash == '-1') {
            $hash = $this->getHashFromResumeLink(Arr::get($requestData, 'link', ''));
        }

        $formId = intval(Arr::get($requestData, 'form_id'));
        $toEmail = trim(Arr::get($requestData, 'to_email', ''));
        if (!is_email($toEmail)) {
            wp_send_json_error([
                'Error' => __('Please provide a valid email address','fluentformpro')
            ], 423);
        }
        
        $form = wpFluent()->table('fluentform_forms')->find($formId);
        if (!$form) {
            wp_send_json_error([
                'Error' => __('Invalid form request.', 'fluentformpro')
            ], 423);
        }

        $settings = FormFieldsParser::getElement($form, ['save_progress_button'], ['raw']);
        if (empty($settings) || !is_array($settings)) {
            wp_send_json_error([
                'Error' => __('Element Not Found.Please check again!', 'fluentformpro')
            ], 423);
        }
        $settings = array_pop($settings);

        if (!Arr::get($settings, 'raw.settings.email_resume_link_enabled')) {
            wp_send_json_error([
                'Error' => __('Email resume link is not enabled for this form.', 'fluentformpro')
            ], 423);
        }

        $entry = $this->get($hash, $formId);
        if (!$hash || !$entry) {
            wp_send_json_error([
                'Error' => __('Invalid or expired saved progress link.', 'fluentformpro')
            ], 423);
        }

        $link = $this->getSavedLink($hash, $entry->source_url);
        $submittedData = null;
        if ($entry) {
            $submittedData['step_completed'] = (int)$entry->step_completed;
            $submittedData['response'] = json_decode($entry->response, true);
            unset(
                $submittedData['response']['_wp_http_referer'],
                $submittedData['response']['__fluent_form_embded_post_id'],
                $submittedData['response']['_fluentform_' . $entry->form_id . '_fluentformnonce']
            );
        }
        
        
        $emailFormat = $this->processEmail($settings, $form, $link, $toEmail);
        $emailFormat =  apply_filters_deprecated(
            'fluentform_email_form_resume_link_config',
            [
                $emailFormat,
                $submittedData,
                $requestData,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/email_form_resume_link_config',
            'Use fluentform/email_form_resume_link_config instead of fluentform_email_form_resume_link_config.'
        );
        $emailFormat = apply_filters('fluentform/email_form_resume_link_config', $emailFormat, $submittedData, $requestData, $form);
        do_action_deprecated(
            'fluentform_email_form_resume_link_before_sent',
            [
                $emailFormat,
                $submittedData,
                $requestData,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/email_form_resume_link_before_sent',
            'Use fluentform/email_form_resume_link_before_sent instead of fluentform_email_form_resume_link_before_sent.'
        );
        do_action('fluentform/email_form_resume_link_before_sent', $emailFormat, $submittedData, $requestData, $form);
        $notifier = $this->app->make(
            'FluentForm\App\Services\FormBuilder\Notifications\EmailNotification'
        );
        $notify = $notifier->notify($emailFormat, $submittedData, $form);
        
        if ($notify) {
            // translators: %s is the recipient email address
            $message = sprintf(__('Email Successfully Sent to %s', 'fluentformpro'),$toEmail);
            $message = apply_filters_deprecated(
                'fluentform_email_resume_link_response',
                [
                    $message
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/email_resume_link_response',
                'Use fluentform/email_resume_link_response instead of fluentform_email_resume_link_response.'
            );
            wp_send_json_success([
                'response' => apply_filters('fluentform/email_resume_link_response', $message)
            ]);
        }
        
        wp_send_json_error([
            'Error' => __('Error Occurred while sending email', 'fluentformpro')
        ], 423);
    }

    private function deleteSavedStateDraft($form, $formData)
    {
        $hash = '';
        if (isset($formData['__fluent_state_hash'])) {
            $hash = $formData['__fluent_state_hash'];
        } else {
            $requestData = $this->app->request->get('data');
            if (is_array($requestData) && isset($requestData['__fluent_state_hash'])) {
                $hash = $requestData['__fluent_state_hash'];
            }
        }

        if (!$hash) {
            return;
        }
        $hash = sanitize_text_field($hash);
        ob_start();
        $draft = $this->get($hash, $form->id);
        if ($draft) {
            wpFluent()->table(static::$tableName)
                ->where('hash', $hash)
                ->delete();
            ob_get_clean();
            do_action_deprecated(
                'fluentform_saved_progress_submission_deleted',
                [
                    $draft,
                    $form->id
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/saved_progress_submission_deleted',
                'Use fluentform/saved_progress_submission_deleted instead of fluentform_saved_progress_submission_deleted.'
            );
            do_action('fluentform/saved_progress_submission_deleted', $draft, $form->id);
        }
    }

    private function deleteStepFormDraft($form)
    {
        if (
            Helper::getFormMeta($form->id, 'step_data_persistency_status') != 'yes' &&
            !Helper::getFormMeta($form->id, 'conv_form_per_step_save', false)
        ) {
            return;
        }

        if ($hash = Arr::get($_COOKIE, $this->getCookieName($form->id))) {
            $draft = $this->get($hash, $form->id);
            if ($draft) {
                ob_start();
                wpFluent()->table(static::$tableName)
                    ->where('id', $draft->id)
                    ->delete();
                wpFluent()->table('fluentform_logs')
                    ->where('parent_source_id', $form->id)
                    ->where('source_id', $draft->id)
                    ->where('source_type', 'draft_submission_meta')
                    ->delete();

                $this->deleteCookie($form->id);
                $errors = ob_get_clean();
                do_action_deprecated(
                    'fluentform_partial_submission_deleted',
                    [
                        $draft,
                        $form->id
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/partial_submission_step_completed',
                    'Use fluentform/partial_submission_step_completed instead of fluentform_partial_submission_deleted.'
                );
                do_action('fluentform/partial_submission_deleted', $draft, $form->id);
            }
        }

        if ($userId = get_current_user_id()) {
            wpFluent()->table(static::$tableName)
                ->where('user_id', $userId)
                ->where('form_id', $form->id)
                ->delete();
        }
    }

    private function processAdminEmail($emailAddress, $emailSubject, $emailBody, $form, $entryId)
    {
        if (false !== strpos($emailSubject, '{form_name}')) {
            $emailSubject = str_replace('{form_name}', $form->title, $emailSubject);
        }

        if (false !== strpos($emailSubject, '{partial_entry_id}')) {
            $emailSubject = str_replace('{partial_entry_id}', $entryId, $emailSubject);
        }

        if (false !== strpos($emailBody, '{form_name}')) {
            $emailBody = str_replace('{form_name}', $form->title, $emailBody);
        }

        if (false !== strpos($emailBody, '{partial_entry_link}')) {
            $link = admin_url("admin.php?page=fluent_forms&form_id={$form->id}&route=msformentries#/entries/{$entryId}");
            $emailBody = str_replace('{partial_entry_link}', $link, $emailBody);
            $emailBody = apply_filters('fluentform/partial_entry_admin_email_body', $emailBody, $form, $link);
        }

        return [
            'fromEmail'   => get_option('admin_email'),
            'fromName'    => get_bloginfo(),
            'asPlainText' => 'no',
            'message'     => $emailBody,
            'replyTo'     => '',
            'subject'     => $emailSubject,
            'sendTo'      => [
                'email' => $emailAddress,
            ]
        ];
    }
    
    private function processEmail($settings, $form, $link, $toEmail)
    {
        $emailSubject = Arr::get($settings, 'raw.settings.email_subject');
        $emailBody = Arr::get($settings, 'raw.settings.email_body', '<p>Hi there,</p><p>Please Continue To Your Submission Process of the Form by clicking on the link below.</p><p>{email_resume_link}</p><p>Thanks</p>');
        if (false !== strpos($emailSubject, '{form_name}')) {
            $emailSubject = str_replace('{form_name}', $form->title, $emailSubject);
        }
        
        if (false !== strpos($emailBody, '{email_resume_link}')) {
            $emailBody = str_replace('{email_resume_link}', $link, $emailBody);
            $emailBody = apply_filters_deprecated(
                'fluentform_email_resume_link_body',
                [
                    $emailBody,
                    $form,
                    $link
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/email_resume_link_body',
                'Use fluentform/email_resume_link_body instead of fluentform_email_resume_link_body.'
            );
            $emailBody = apply_filters('fluentform/email_resume_link_body', $emailBody, $form, $link);
        }
        if (false !== strpos($emailBody, '{form_name}')) {
            $emailBody = str_replace('{form_name}', $form->title, $emailBody);
        }
        return [
            'fromEmail'   => get_option('admin_email'),
            'fromName'    => get_bloginfo(),
            'asPlainText' => 'no',
            'message'     => $emailBody,
            'replyTo'     => '',
            'subject'     => $emailSubject,
            'sendTo'      => [
                'email' => $toEmail,
            ]
        ];
    }
    
    private function getSaveProgressButtonData($formId, $clickedBtnNameAttr)
    {
        $form = wpFluent()->table('fluentform_forms')->find($formId);
        $formFields = FormFieldsParser::getFields($form, true);

        // Function to search for the 'save_progress_button' inside fields or columns
        $findSaveProgressButton = function($fields) use ($clickedBtnNameAttr) {
            foreach ($fields as $field) {
                // Check if it's a 'save_progress_button' directly in the field
                if (
                    isset($field['element']) &&
                    $field['element'] === 'save_progress_button' &&
                    isset($field['attributes']['name']) &&
                    $field['attributes']['name'] === $clickedBtnNameAttr
                ) {
                    return $field;
                }

                // If it's a container, search inside the columns
                if (
                    isset($field['element']) &&
                    $field['element'] === 'container' &&
                    isset($field['columns'])
                ) {
                    foreach ($field['columns'] as $column) {
                        if (isset($column['fields']) && is_array($column['fields'])) {
                            foreach ($column['fields'] as $columnField) {
                                if (
                                    isset($columnField['element']) &&
                                    $columnField['element'] === 'save_progress_button' &&
                                    isset($columnField['attributes']['name']) &&
                                    $columnField['attributes']['name'] === $clickedBtnNameAttr
                                ) {
                                    return $columnField;
                                }
                            }
                        }
                    }
                }
            }

            return null;
        };

        // Find the save progress button in form fields
        $saveProgressButton = $findSaveProgressButton($formFields);

        // If no button is found, return false
        if (!$saveProgressButton) {
            return false;
        }

        return $saveProgressButton;
    }
    
    private function shouldVerifyNonce()
    {
        $formId = intval($this->app->request->get('form_id'));

        // Defaults ON: these nopriv endpoints always enforced the nonce, so the
        // check stays on unless a full-page-cached site explicitly opts out. Kept
        // separate from submission's fluentform/nonce_verify (which defaults off)
        // to avoid one filter carrying two conflicting defaults.
        return apply_filters('fluentform/draft_nonce_verify', true, $formId);
    }

    private function verify()
    {
        if (!$this->shouldVerifyNonce()) {
            return;
        }

        $nonce = $this->app->request->get('nonce');
        if (!wp_verify_nonce($nonce)) {
            $nonceMessage =  __('Nonce verification failed, please try again.', 'fluentformpro');
            $nonceMessage = apply_filters_deprecated(
                'fluentform_nonce_error',
                [
                    $nonceMessage
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/nonce_error',
                'Use fluentform/nonce_error instead of fluentform_nonce_error.'
            );
            $message = apply_filters('fluentform/nonce_error', $nonceMessage);
            
            wp_send_json_error([
                'message' => $message,
            ], 422);
        }
    }
}
