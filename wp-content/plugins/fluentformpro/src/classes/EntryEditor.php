<?php

namespace FluentFormPro\classes;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Submission\SubmissionService;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class EntryEditor
{
    public static function editEntry()
    {
        $entryId = intval($_REQUEST['entry_id']);

        $entryData = \json_decode(wp_unslash($_REQUEST['entry']), true);

        if (!is_array($entryData)) {
            wp_send_json_error([
                'message' => __('Invalid entry data', 'fluentformpro')
            ], 422);
        }

        $formId = wpFluent()->table('fluentform_submissions')
            ->where('id', $entryId)
            ->value('form_id');

        // Authorize the STORED entry's form (nonce + capability). The AJAX
        // registration gates a request-supplied form the caller manages, but the
        // mutation targets entry_id — a delegated manager must not edit an entry
        // belonging to a form outside their assignment.
        \FluentForm\App\Modules\Acl\Acl::verify('fluentform_manage_entries', $formId);

        $fields = [];
        if ($formId) {
            $form = wpFluent()->table('fluentform_forms')->find($formId);
            if ($form) {
                $fields = FormFieldsParser::getEntryInputs($form, ['element']);
            }
        }
        $sanitizeMap = self::buildFieldAwareSanitizeMap($entryData, $fields);
        $entryData = fluentform_backend_sanitizer($entryData, $sanitizeMap);

        try {
            static::updateEntryResponse($entryId, $entryData);
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 423);
        }

        wp_send_json_success([
            'message' => __('Entry data successfully updated', 'fluentformpro'),
            'data' => $entryData
        ]);
    }

    /**
     * Build a sanitize map from entry data keys + form field definitions.
     * Rich text / post_content fields get wp_kses_post, textareas get
     * sanitize_textarea_field, URLs get sanitize_url. Everything else
     * (including nested sub-keys like first_name, city, etc.) gets
     * sanitize_text_field. The flat map works with fluentform_backend_sanitizer
     * because it recurses into arrays and looks up each inner key.
     */
    private static function buildFieldAwareSanitizeMap($data, $fields = [])
    {
        $map = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $map = array_merge($map, self::buildFieldAwareSanitizeMap($value));
            } else {
                $element = Arr::get($fields, $key . '.element', '');
                if (in_array($element, ['post_content', 'rich_text_input'])) {
                    $map[$key] = 'wp_kses_post';
                } elseif ($element === 'textarea') {
                    $map[$key] = 'sanitize_textarea_field';
                } elseif ($element === 'input_url') {
                    $map[$key] = 'sanitize_url';
                } else {
                    $map[$key] = 'sanitize_text_field';
                }
            }
        }
        return $map;
    }

    public static function updateEntryResponse($id, $response)
    {
        // Find the database Entry First
        $entry = wpFluent()->table('fluentform_submissions')
            ->where('id', $id)
            ->first();

        if (!$entry) {
            throw new \Exception('No Entry Found');
        }

        $origianlResponse = json_decode($entry->response, true);

        $diffs = [];
        foreach ($response as $resKey => $resvalue) {
            if (!isset($origianlResponse[$resKey]) || $origianlResponse[$resKey] != $resvalue) {
                $diffs[$resKey] = $resvalue;
            }
        }

        if (!$diffs) {
            return true;
        }

        $response = wp_parse_args($response, $origianlResponse);

        wpFluent()->table('fluentform_submissions')
            ->where('id', $id)
            ->update([
                'response' => json_encode($response, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql')
            ]);


        if (class_exists(SubmissionService::class) && method_exists(SubmissionService::class, 'updateEntryDiffs')) {
            (new SubmissionService())->updateEntryDiffs($id, $entry->form_id, $diffs);
        }

        $user = get_user_by('ID', get_current_user_id());

        $message = '';

        if ($user) {
            $message = __('Entry data has been updated by ', 'fluentformpro') . $user->user_login;
        }

        $logData = [
            'parent_source_id' => $entry->form_id,
            'source_type' => 'submission_item',
            'source_id' => $entry->id,
            'component' => 'EntryEditor',
            'status' => 'info',
            'title' => 'Entry Data Updated',
            'description' => $message,
        ];

        do_action('fluentform/log_data', $logData);

        return true;
    }
}
