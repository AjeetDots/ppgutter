<?php
namespace FluentFormPro\classes;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\App\Modules\Form\FormDataParser;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Transfer\TransferService;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class StepFormEntries
{
    protected $formId = false;
    protected $per_page = 10;
    protected $page_number = 1;
    protected $status = false;
    protected $is_favourite = null;
    protected $sort_by = 'ASC';
    protected $search = false;
    protected $wheres = [];
    protected $formTable = 'fluentform_forms';
    protected $entryTable = 'fluentform_draft_submissions';
    protected $app;

    public static function boot($app)
    {
        return new static($app);
    }

    public function __construct($app)
    {
        $this->app = $app;

        $this->registerAjaxHandlers();

        $this->app->addFilter('fluentform/form_admin_menu', [$this, 'addAdminMenu'], 10, 2);

        $this->app->addFilter('fluentform/export_entry_metadata', [$this, 'appendDraftMetadata'], 10, 4);
        $this->app->addFilter('fluentform/export_entry_metadata_labels', [$this, 'appendDraftMetadataLabels'], 10, 3);

        $app->addAction('fluentform/form_application_view_msformentries', [$this, 'renderEntries']);

        add_filter('fluentform/form_inner_route_permission_set', array($this, 'setRoutePermission'));
    }

    protected function registerAjaxHandlers()
    {
        $this->app->addAdminAjaxAction('fluentform-step-form-entry-count', function () {
            Acl::verify('fluentform_entries_viewer', $this->getRequestedFormId());
            $this->getCountOfEntries();
        });

        $this->app->addAdminAjaxAction('fluentform-step-form-entries', function () {
            Acl::verify('fluentform_entries_viewer', $this->getRequestedFormId());
            $this->getEntries();
        });

        $this->app->addAdminAjaxAction('fluentform-step-form-delete-entry', function () {
            $entry = $this->resolveDraftEntry($this->getRequestedEntryId());
            Acl::verify('fluentform_forms_manager', $entry->form_id);
            $this->deleteEntry($entry);
        });

        $this->app->addAdminAjaxAction('fluentform-do_step_form_entry_bulk_actions', function () {
            $this->handleBulkAction();
        });

        $this->app->addAdminAjaxAction('fluentform-step-form-entries-export', function () {
            Acl::verify('fluentform_entries_viewer', $this->getRequestedFormId());
            if (class_exists(TransferService::class) && method_exists(TransferService::class, 'exportEntries')) {
                TransferService::exportEntries(array_merge(
                    $this->app->request->get(),
                    ['table' => $this->entryTable]
                ));
            } else {
                wp_send_json_error([
                    'message' => __('Export service is not available. Please update Fluent Forms.', 'fluentformpro'),
                ], 400);
            }
        });

        $this->app->addAdminAjaxAction('fluentform-step-form-get-entry', function () {
            $entry = $this->resolveDraftEntry($this->getRequestedEntryId());
            Acl::verify('fluentform_entries_viewer', $entry->form_id);
            $this->getEntry();
        });
    }

    protected function getRequestedFormId()
    {
        return absint($this->app->request->get('form_id'));
    }

    protected function getRequestedEntryId()
    {
        return absint($this->app->request->get('entry_id'));
    }

    protected function resolveDraftEntry($entryId)
    {
        if (!$entryId) {
            wp_send_json_error([
                'message' => __('No Entry found.', 'fluentformpro')
            ], 422);
        }

        $entry = wpFluent()->table($this->entryTable)
            ->select(['id', 'form_id'])
            ->find($entryId);

        if (!$entry) {
            wp_send_json_error([
                'message' => __('No Entry found.', 'fluentformpro')
            ], 422);
        }

        return $entry;
    }

    protected function resolveBulkDraftEntries(array $entryIds, $requestedFormId = 0)
    {
        $normalizedEntryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));

        if (!$normalizedEntryIds) {
            wp_send_json_error([
                'message' => __('Please select entries first', 'fluentformpro')
            ], 400);
        }

        $entries = wpFluent()->table($this->entryTable)
            ->select(['id', 'form_id'])
            ->whereIn('id', $normalizedEntryIds)
            ->get();

        $resolvedEntryIds = [];
        $resolvedFormId = 0;

        foreach ($entries as $entry) {
            $resolvedEntryIds[(int) $entry->id] = (int) $entry->id;

            if (!$resolvedFormId) {
                $resolvedFormId = (int) $entry->form_id;
                continue;
            }

            if ($resolvedFormId !== (int) $entry->form_id) {
                wp_send_json_error([
                    'message' => __('Invalid entry selection', 'fluentformpro')
                ], 422);
            }
        }

        foreach ($normalizedEntryIds as $entryId) {
            if (!isset($resolvedEntryIds[$entryId])) {
                wp_send_json_error([
                    'message' => __('Invalid entry selection', 'fluentformpro')
                ], 422);
            }
        }

        if ($requestedFormId && $requestedFormId !== $resolvedFormId) {
            wp_send_json_error([
                'message' => __('Invalid entry selection', 'fluentformpro')
            ], 422);
        }

        return [
            'form_id'   => $resolvedFormId,
            'entry_ids' => array_values($resolvedEntryIds)
        ];
    }

    public function addAdminMenu($formAdminMenus, $form_id)
    {
        $hasPartialEntry =
            Helper::getFormMeta($form_id, 'step_data_persistency_status') == 'yes' ||
            Helper::getFormMeta($form_id, 'form_save_state_status') == 'yes' ||
            Helper::getFormMeta($form_id, 'conv_form_per_step_save');

        if ($hasPartialEntry) {
            $formAdminMenus['msformentries'] = [
                'hash' => '/',
                'slug' => 'msformentries',
                'title' => __('Partial Entries', 'fluentformpro'),
                'url' => admin_url(
                    'admin.php?page=fluent_forms&form_id=' . $form_id . '&route=msformentries'
                )
            ];
        }

        return $formAdminMenus;
    }

    public function renderEntries($form_id)
    {
        $this->enqueueScript();

        $form = wpFluent()->table($this->formTable)->find($form_id);
    
        $entryVars = [
            'form_id'             => $form->id,
            'current_form_title'  => $form->title,
            'has_pro'             => defined('FLUENTFORMPRO'),
            'all_forms_url'       => admin_url('admin.php?page=fluent_forms'),
            'printStyles'         => [fluentformMix('css/settings_global.css')],
            'entries_url_base'    => admin_url('admin.php?page=fluent_forms&route=msformentries&form_id='),
            'available_countries' => getFluentFormCountryList(),
            'no_found_text'       => __('Sorry! No entries found. All your entries will be shown here once you start getting form submissions', 'fluentformpro')
        ];
    
        if(class_exists('\FluentForm\App\Modules\Registerer\TranslationString')){
            $stepFormI18n = \FluentForm\App\Modules\Registerer\TranslationString::getEntriesI18n();
            $entryVars['form_entries_str'] = $stepFormI18n;
        }
    
        $entryVars = apply_filters_deprecated(
            'fluentform_step_form_entry_vars',
            [
                $entryVars,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/step_form_entry_vars',
            'Use fluentform/step_form_entry_vars instead of fluentform_step_form_entry_vars.'
        );

        $fluentformStepFormEntryVars = apply_filters('fluentform/step_form_entry_vars', $entryVars, $form);

        wp_localize_script(
            'fluentform_step_form_entries',
            'fluentform_step_form_entry_vars',
            $fluentformStepFormEntryVars
        );

        ob_start();
        require(FLUENTFORMPRO_DIR_PATH . 'src/views/step_form_entries.php');
        echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin page HTML from template
    }

    protected function enqueueScript()
    {
        wp_enqueue_script(
            'fluentform_step_form_entries',
            FLUENTFORMPRO_DIR_URL . 'public/js/step-form-entries.js',
            ['jquery'],
            FLUENTFORM_VERSION,
            true
        );
    }

    public function getCountOfEntries()
    {
        $formId = $this->getRequestedFormId();

        $count = wpFluent()->table($this->entryTable)
            ->select(wpFluent()->table($this->entryTable)->raw('COUNT(*) as count'))
            ->where('form_id', $formId)
            ->count();

        wp_send_json_success([
            'count' => $count
        ], 200);

    }

    public function getEntries()
    {
        if (!defined('FLUENTFORM_RENDERING_ENTRIES')) {
            define('FLUENTFORM_RENDERING_ENTRIES', true);
        }

        $entries = $this->getStepFormEntries(
            intval($this->app->request->get('form_id')),
            intval($this->app->request->get('page', 1)),
            intval($this->app->request->get('per_page', 10)),
            Helper::sanitizeOrderValue($this->app->request->get('sort_by', 'DESC')),
            sanitize_text_field($this->app->request->get('entry_type', 'all')),
            sanitize_text_field($this->app->request->get('search'))
        );
    
        $entries['formLabels'] = apply_filters_deprecated(
            'fluentform_all_entry_labels',
            [
                $entries['formLabels'],
                $this->app->request->get('form_id')
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/all_entry_labels',
            'Use fluentform/all_entry_labels instead of fluentform_all_entry_labels.'
        );

        $labels = apply_filters(
            'fluentform/all_entry_labels', $entries['formLabels'], $this->app->request->get('form_id')
        );

        $form = wpFluent()->table($this->formTable)->find($this->app->request->get('form_id'));
    
        $entries['submissions'] = apply_filters_deprecated(
            'fluentform_all_entries',
            [
                $entries['submissions']
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/all_entries',
            'Use fluentform/all_entries instead of fluentform_all_entries.'
        );
        wp_send_json_success([
            'submissions' => apply_filters('fluentform/all_entries', $entries['submissions']),
            'labels' => $labels
        ], 200);
    }

    public function getStepFormEntries(
        $formId,
        $currentPage,
        $perPage,
        $sortBy,
        $entryType,
        $search,
        $wheres = []
    )
    {
        $form = wpFluent()->table($this->formTable)->find($formId);
        $formMeta = $this->getFormInputsAndLabels($form);
        $formLabels = $formMeta['labels'];
        $formLabels = apply_filters_deprecated(
            'fluentfoform_entry_lists_labels',
            [
                $formLabels,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/entry_lists_labels',
            'Use fluentform/entry_lists_labels instead of fluentfoform_entry_lists_labels.'
        );
        $formLabels = apply_filters_deprecated(
            'fluentform_entry_lists_labels',
            [
                $formLabels,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/entry_lists_labels',
            'Use fluentform/entry_lists_labels instead of fluentform_entry_lists_labels.'
        );
        $formLabels = apply_filters('fluentform/entry_lists_labels', $formLabels, $form);
    
        $submissions = $this->getResponses($formId, $perPage, $sortBy, $currentPage, $search, $wheres);
        $submissions['data'] = FormDataParser::parseFormEntries($submissions['data'], $form);

        return compact('submissions', 'formLabels');
    }

    public function getResponses($formId, $perPage, $sortBy, $currentPage, $search = '', $wheres = [])
    {
        $baseQuery = wpFluent()->table($this->entryTable)->where('form_id', $formId);

        if ($search) {
            $searchString = $search;
            $baseQuery->where(function ($q) use ($searchString) {
                $q->where('id', 'LIKE', "%{$searchString}%")
                    ->orWhere('response', 'LIKE', "%{$searchString}%");
            });
        }

        if ($wheres) {
            foreach ($wheres as $where) {
                if (is_array($where) && count($where) > 1) {
                    if (count($where) > 2) {
                        $column = $where[0];
                        $operator = $where[1];
                        $value = $where[2];
                    } else {
                        $column = $where[0];
                        $operator = '=';
                        $value = $where[1];
                    }
                    $baseQuery->where($column, $operator, $value);
                }
            }
        }

        // Get total count before applying limit and offset
        $total = $baseQuery->count();

        // Now apply limit and offset for the actual data query
        $query = clone $baseQuery;
        $query = $query->orderBy('id', $sortBy);

        if ($perPage > 0) {
            $query = $query->limit($perPage);
        }

        if ($currentPage > 0) {
            $query = $query->offset(($currentPage - 1) * $perPage);
        }

        $responses = $query->get();
    
        $responses = apply_filters_deprecated(
            'fluentform/get_raw_responses',
            [
                $responses,
                $formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/get_raw_responses',
            'Use fluentform/get_raw_responses instead of fluentform_get_raw_responses.'
        );

        $responses = apply_filters('fluentform/get_raw_responses', $responses, $formId);

        return [
            'data' => $responses,
            'paginate' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => ceil($total / $perPage)
            ]
        ];
    }

    public function getEntry()
    {
        if (!defined('FLUENTFORM_RENDERING_ENTRY')) {
            define('FLUENTFORM_RENDERING_ENTRY', true);
        }

        $entryData = $this->getstepFormEntry();

        $entryData['widgets'] = apply_filters(
            'fluentform/submissions_widgets', [], $entryData, $entryData['submission']
        );

        wp_send_json_success($entryData, 200);
    }

    public function getstepFormEntry()
    {
        $entryId = $this->getRequestedEntryId();

        $this->sort_by = \FluentForm\App\Helpers\Helper::sanitizeOrderValue($this->app->request->get('sort_by', 'ASC'));

        $this->search = sanitize_text_field($this->app->request->get('search'));

        $submission = $this->getResponse($entryId);

        if (!$submission) {
            wp_send_json_error([
                'message' => __('No Entry found.', 'fluentformpro')
            ], 422);
        }

        $this->formId = (int) $submission->form_id;

        $form = wpFluent()->table($this->formTable)->find($this->formId);

        $formMeta = $this->getFormInputsAndLabels($form);

        $submission = FormDataParser::parseFormEntry($submission, $form, $formMeta['inputs'], true);

        if ($fields = FormFieldsParser::getInputsByElementTypes($form, ['input_file', 'input_image'])) {
            $response = \json_decode($submission->response, true);
            foreach ($fields as $name => $field) {
                if ($files = Arr::get($response, $name)) {
                    foreach ($files as $index => $file) {
                        $response[$name][$index] = Helper::maybeDecryptUrl($file);
                    }
                }
            }
            $submission->response = \json_encode($response);
        }

        if ($submission->user_id) {
            $user = get_user_by('ID', $submission->user_id);
            $user_data = [
                'name' => $user->display_name,
                'email' => $user->user_email,
                'ID' => $user->ID,
                'permalink' => get_edit_user_link($user->ID)
            ];
            $submission->user = $user_data;
        }
    
        $submission = apply_filters_deprecated(
            'fluentform_single_response_data',
            [
                $submission,
                $this->formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/single_response_data',
            'Use fluentform/single_response_data instead of fluentform_single_response_data.'
        );

        $submission = apply_filters('fluentform/single_response_data', $submission, $this->formId);
    
        $formMeta['inputs'] = apply_filters_deprecated(
            'fluentform_single_response_input_fields',
            [
                $formMeta['inputs'],
                $this->formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/single_response_input_fields',
            'Use fluentform/single_response_input_fields instead of fluentform_single_response_input_fields.'
        );
        $fields = apply_filters(
            'fluentform/single_response_input_fields', $formMeta['inputs'], $this->formId
        );
    
        $formMeta['labels'] = apply_filters_deprecated(
            'fluentform_single_response_input_labels',
            [
                $formMeta['labels'],
                $this->formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/single_response_input_labels',
            'Use fluentform/single_response_input_labels instead of fluentform_single_response_input_labels.'
        );

        $labels = apply_filters(
            'fluentform/single_response_input_labels', $formMeta['labels'], $this->formId
        );

        $order_data = false;

        $nextSubmissionId = $this->getNextResponse($entryId);

        $previousSubmissionId = $this->getPrevResponse($entryId);

        return [
            'submission' => $submission,
            'next' => $nextSubmissionId,
            'prev' => $previousSubmissionId,
            'labels' => $labels,
            'fields' => $fields,
            'order_data' => $order_data
        ];
    }

    protected function getResponse($entryId)
    {
        return wpFluent()->table($this->entryTable)->find($entryId);
    }

    protected function getFormInputsAndLabels($form, $with = ['admin_label', 'raw'])
    {
        $formInputs = FormFieldsParser::getEntryInputs($form, $with);

        $inputLabels = FormFieldsParser::getAdminLabels($form, $formInputs);

        return [
            'inputs' => $formInputs,
            'labels' => $inputLabels
        ];
    }

    protected function getNextResponse($entryId)
    {
        $query = $this->getNextPrevEntryQuery();

        $operator = $this->sort_by == 'ASC' ? '>' : '<';

        return $query->select('id')
            ->where('id', $operator, $entryId)
            ->orderBy('id', $this->sort_by)
            ->first();
    }

    protected function getPrevResponse($entryId)
    {
        $query = $this->getNextPrevEntryQuery();

        $operator = $this->sort_by == 'ASC' ? '<' : '>';

        $orderBy = $this->sort_by == 'ASC' ? 'DESC' : 'ASC';
    
        return $query->select('id')
            ->where('id', $operator, $entryId)
            ->orderBy('id', $orderBy)
            ->first();
    }

    protected function getNextPrevEntryQuery()
    {
        $query = wpFluent()->table($this->entryTable)->limit(1);

        if ($this->search) {
            $query->where('response', 'LIKE', "%{$this->search}%");
        }

        return $query->where('form_id', $this->formId);
    }

    public function deleteEntry($entry = null)
    {
        if (!$entry) {
            $entry = $this->resolveDraftEntry($this->getRequestedEntryId());
        }

        $formId = (int) $entry->form_id;
        $entryId = (int) $entry->id;
        $newStatus = sanitize_text_field($this->app->request->get('status'));

        $this->deleteEntryById($entryId, $formId);

        wp_send_json_success([
            'message' => __('Item Successfully deleted', 'fluentformpro'),
            'status' => $newStatus
        ], 200);
    }

    public function deleteEntryById($entryId, $formId = false)
    {
        do_action_deprecated(
            'fluentform_before_partial_entry_deleted',
            [
                $entryId,
                $formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/before_partial_entry_deleted',
            'Use fluentform/before_partial_entry_deleted instead of fluentform_before_partial_entry_deleted.'
        );
        do_action('fluentform/before_partial_entry_deleted', $entryId, $formId);

        ob_start();
        wpFluent()->table($this->entryTable)->where('id', $entryId)->delete();
        $errors = ob_get_clean();

        do_action_deprecated(
            'fluentform_after_partial_entry_deleted',
            [
                $entryId,
                $formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/after_partial_entry_deleted',
            'Use fluentform/after_partial_entry_deleted instead of fluentform_after_partial_entry_deleted.'
        );
        do_action('fluentform/after_partial_entry_deleted', $entryId, $formId);

        return true;
    }

    public function handleBulkAction()
    {
        $request = $this->app->request;
        $formId = $this->getRequestedFormId();
        $entries = fluentFormSanitizer($request->get('entries', []));
        $actionType = sanitize_text_field($request->get('action_type'));

        if (!$formId || !count($entries)) {
            wp_send_json_error([
                'message' => __('Please select entries first', 'fluentformpro')
            ], 400);
        }

        if ($actionType == 'delete_permanently') {
            // SECURITY (PRO-37): verify the capability against the requested form BEFORE resolving
            // the entries. resolveBulkDraftEntries() emits distinct "does not exist" / "belongs to a
            // different form" / "ok" errors, which an unauthorized logged-in user could otherwise use
            // to enumerate draft-submission IDs and their owning forms.
            Acl::verify('fluentform_manage_entries', $formId);
            $resolvedEntries = $this->resolveBulkDraftEntries((array) $entries, $formId);
            Acl::verify('fluentform_manage_entries', $resolvedEntries['form_id']);

            foreach ($resolvedEntries['entry_ids'] as $entryId) {
                if(!$this->deleteEntryById($entryId, $resolvedEntries['form_id'])){
                    continue;
                };
            }

            wp_send_json_success([
                'message' => __('Selected entries successfully deleted', 'fluentformpro')
            ], 200);
        }
    }

    protected function getSubmissionAttachments($submissionId, $form)
    {
        $fields = FormFieldsParser::getAttachmentInputFields($form, ['element', 'attributes']);

        $deletableFiles = [];

        if ($fields) {
            $submission = wpFluent()->table($this->entryTable)
                ->where('id', $submissionId)
                ->first();

            $data = json_decode($submission->response, true);

            foreach ($fields as $field) {
                if (!empty($data[$field['attributes']['name']])) {

                    $files = $data[$field['attributes']['name']];

                    if (is_array($files)) {
                        $deletableFiles = array_merge($deletableFiles, $files);
                    } else {
                        $deletableFiles = $files;
                    }

                }
            }
        }

        return $deletableFiles;
    }

    public function appendDraftMetadata($temp, $submission, $form, $args)
    {
        if (Arr::get($args, 'table') !== $this->entryTable) {
            return $temp;
        }

        $temp[] = $submission->id ?? '';
        $temp[] = $submission->step_completed ?? '';
        $temp[] = $submission->user_id ?? '';
        $temp[] = $submission->created_at ?? '';
        $temp[] = $submission->updated_at ?? '';

        return $temp;
    }

    public function appendDraftMetadataLabels($inputLabels, $form, $args)
    {
        if (Arr::get($args, 'table') !== $this->entryTable) {
            return $inputLabels;
        }

        $inputLabels[] = 'entry_id';
        $inputLabels[] = 'step_completed';
        $inputLabels[] = 'user_id';
        $inputLabels[] = 'created_at';
        $inputLabels[] = 'updated_at';

        return $inputLabels;
    }

    public function setRoutePermission($permissions)
    {
        $permissions['conversational_design'] = 'fluentform_forms_manager';

        return $permissions;
    }

}
