<?php

namespace FluentFormPro\classes\EntriesImport;

defined('ABSPATH') or die;

use Exception;
use FluentForm\App\Models\EntryDetails;
use FluentForm\App\Models\Form;
use FluentForm\App\Models\FormAnalytics;
use FluentForm\App\Models\Log;
use FluentForm\App\Models\Submission;
use FluentForm\App\Models\SubmissionMeta;
use FluentForm\App\Modules\Form\FormFieldsParser;
use Fluentform\App\Helpers\Helper;
use FluentForm\App\Services\Submission\SubmissionService;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class EntriesImportService
{
    protected $form = null;
    protected $file = null;
    protected $fileData = null;
    protected $hasLotsOfData = false;
    protected $formattedFileData = null;
    protected $attrs = [];
    
    /**
     * @return array
     * @throws Exception
     */
    public function mappingFields($attrs)
    {
        $this->prepare($attrs);
        if ($this->isCsvFile()) {
            $this->formatCsvFileData();
        } elseif ($this->isJsonFile()) {
            $this->formatJsonFileData();
        }
        return [
            'has_lots_of_entries'    => $this->hasLotsOfData,
            'mapping_fields'         => $this->formattedFileData,
            'form_fields'            => $this->getFormattedFormFields(),
            'submission_info_fields' => $this->getFormattedSubmissionInfoFields(),
        ];
    }
    
    /**
     * @throws Exception
     */
    public function importEntries($attrs)
    {
        $this->prepare($attrs);
        if (Arr::isTrue($attrs, 'delete_existing_submissions')) {
            $this->resetEntries();
        }
        if ($this->isCsvFile()) {
            return $this->importFromCsvFile();
        } elseif ($this->isJsonFile()) {
            return $this->importFromJsonFile();
        }
        $this->failed();
    }
    
    /**
     * @throws Exception
     */
    protected function prepare($attrs)
    {
        $this->attrs = $attrs;
        $this->setFile(Arr::get($attrs, 'file'));
        $fileType = Arr::get($attrs, 'file_type');
        if (
            (!$this->isCsvFile() && !$this->isJsonFile()) ||
            ($this->isCsvFile() && 'csv' != $fileType) ||
            ($this->isJsonFile() && 'json' != $fileType)
        ) {
            $this->failed();
        }
        $this->setForm(Arr::get($attrs, 'form_id'));
        $this->setFileData();
    }
    
    /**
     * @throws Exception
     */
    protected function formatCsvFileData()
    {
        $data = $this->getCsvData();
        if (is_array($data) && count($data) && is_array($data[0])) {
            $this->hasLotsOfData = count($data) > 1000;
            foreach ($data[0] as $value) {
                $this->formattedFileData[] = [
                    'label' => $value,
                    'value' => $value
                ];
            }
            return;
        }
        $this->failed("You have a faulty csv file, please import correct file.");
    }
    
    /**
     * @throws Exception
     */
    protected function formatJsonFileData()
    {
        $firstEntry = Arr::get($this->fileData, 0, []);
        if (!isset($firstEntry['response'])) {
            $this->failed();
        }
        Arr::forget($firstEntry, 'response');
        Arr::forget($firstEntry, 'id');
        Arr::forget($firstEntry, 'form_id');
        
        $formattedFileDate = [
            'form_fields'            => [],
            'submission_info_fields' => [],
        ];
        if ($userInputs = Arr::get($firstEntry, 'user_inputs')) {
            $formInputs = FormFieldsParser::getEntryInputs($this->form, ['admin_label', 'element']);
            foreach ($userInputs as $key => $value) {
                $formattedFileDate['form_fields'][] = [
                    'label' => Arr::get($formInputs, "$key.admin_label", $key),
                    'value' => $key
                ];
            }
            Arr::forget($firstEntry, 'user_inputs');
        }
        foreach ($firstEntry as $name => $value) {
            $formattedFileDate['submission_info_fields'][] = [
                'label' => $name,
                'value' => $name,
            ];
        }
        $this->formattedFileData = $formattedFileDate;
    }
    
    /**
     * @return array
     * @throws Exception
     */
    protected function importFromCsvFile()
    {
        $csvData = $this->getCsvData();
        if (is_array($csvData) && count($csvData) && is_array($csvData[0])) {
            list($formMappingFields, $submissionInfoMappingFields, $formInputs) = $this->getResponsibleMappingFields();
            $csvHeader = array_flip(array_shift($csvData));
            $newSubmissions = [];
            $this->hasLotsOfData = count($csvData) > 1000;
            $oldMaxExecutionTime = $this->maybeIncreaseMaxExecutionTime();
            foreach ($csvData as $data) {
                $newSubmission = [];
                // Form Field Mapping
                $newResponse = [];
                $this->resolveFormFieldMapping($formMappingFields, $data, $newResponse, $formInputs, $csvHeader);
                if (!$newResponse) {
                    continue;
                }
                $newSubmission['response'] = \json_encode($newResponse);
                
                // Submission Info Mapping
                $this->resolveSubmissionInfoMapping($submissionInfoMappingFields, $data, $newSubmission, $csvHeader);
                $newSubmissions[] = $newSubmission;
            }
            
            if ($newSubmissions) {
                $response = $this->insertSubmissions($newSubmissions);
                if ($oldMaxExecutionTime) {
                    ini_set('max_execution_time', $oldMaxExecutionTime);
                }
                return $response;
            }
        }
        $this->failed("File has not data to import");
    }
    
    /**
     * @throws Exception
     */
    protected function importFromJsonFile()
    {
        list($formMappingFields, $submissionInfoMappingFields, $formInputs) = $this->getResponsibleMappingFields();
        $newSubmissions = [];
        $oldMaxExecutionTime = $this->maybeIncreaseMaxExecutionTime();
        foreach ($this->fileData as $data) {
            $newSubmission = [];
            $response = Arr::get($data, 'response');
            if (!is_array($response)) {
                continue;
            }
            // Form Field Mapping
            $newResponse = [];
            $this->resolveFormFieldMapping($formMappingFields, $response, $newResponse, $formInputs);
            if (!$newResponse) {
                continue;
            }
            $newSubmission['response'] = \json_encode($newResponse);
            
            // Submission Info Mapping
            $this->resolveSubmissionInfoMapping($submissionInfoMappingFields, $data, $newSubmission);
            $newSubmissions[] = $newSubmission;
        }
        if ($newSubmissions) {
            $response = $this->insertSubmissions($newSubmissions);
            if ($oldMaxExecutionTime) {
                ini_set('max_execution_time', $oldMaxExecutionTime);
            }
            return $response;
        }
        $this->failed("File has not data to import");
    }
    
    protected function maybeIncreaseMaxExecutionTime()
    {
        $oldMaxExecutionTime = false;
        if ($this->hasLotsOfData && function_exists('ini_set')) {
            $oldMaxExecutionTime = ini_set('max_execution_time', '300'); // set max execution time 5 min
        }
        return $oldMaxExecutionTime;
    }
    
    /**
     * @throws Exception
     */
    protected function setForm($formId)
    {
        if ($form = Form::find(intval($formId))) {
            $this->form = $form;
            return;
        }
        $this->failed('Form not found, please select correct form.');
    }
    
    /**
     * @throws Exception
     */
    protected function setFile($file)
    {
        if (is_object($file) && (
            $file instanceof \FluentForm\Framework\Request\File
            || (class_exists('FluentForm\\Framework\\Http\\Request\\File') && $file instanceof \FluentForm\Framework\Http\Request\File)
        )) {
            $this->file = $file;
            return;
        }
        $this->failed();
    }
    
    /**
     * @throws Exception
     */
    protected function setFileData()
    {
        if ($data = $this->file->getContents()) {
            if ($this->isJsonFile()) {
                if (Helper::isJson($data)) {
                    $data = \json_decode($data, true);
                }
                if (!is_array($data)) {
                    $this->failed();
                }
                if (!count($data)) {
                    $this->failed();
                }
                $this->hasLotsOfData = count($data) > 1000;
            }
            $this->fileData = $data;
            return;
        }
        $this->failed();
    }
    
    protected function isCsvFile()
    {
        return 'csv' === $this->fileExtension();
    }

    protected function isJsonFile()
    {
        return 'json' === $this->fileExtension();
    }

    /**
     * The uploaded file's extension, lowercased — getClientOriginalExtension()
     * keeps the case the browser sent ("Entries.CSV").
     *
     * @return string
     */
    protected function fileExtension()
    {
        return strtolower($this->file->getClientOriginalExtension());
    }
    
    /**
     * Throws failed exception
     *
     * @throws Exception
     */
    protected function failed($message = '')
    {
        if ($message) {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            $message = __($message, 'fluentformpro');
        } else {
            $type = Arr::get($this->attrs, 'file_type', '');
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            // phpcs:ignore WordPress.WP.I18n.InterpolatedVariableText
            $message = __("You have a faulty $type file, please import correct file.", 'fluentformpro');
        }
        throw new Exception($message); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }
    
    /**
     * Return responsible mapping fields as array list
     *
     * @return array [$formMappingField, $submissionInfoMappingField, $formInputs]
     */
    protected function getResponsibleMappingFields()
    {
        $formMappingFields = Arr::get($this->attrs, 'form_fields', []);
        if (Helper::isJson($formMappingFields)) {
            $formMappingFields = \json_decode($formMappingFields, true);
        }
        $submissionInfoFields = Arr::get($this->attrs, 'submission_info_fields', []);
        if (Helper::isJson($submissionInfoFields)) {
            $submissionInfoFields = \json_decode($submissionInfoFields, true);
        }
        $formInputs = FormFieldsParser::getInputs($this->form, ['element', 'attributes', 'admin_label', 'raw']);
        return [$formMappingFields, $submissionInfoFields, $formInputs];
    }
    
    protected function resolveFormFieldMapping($fields, $data, &$newResponse, $formFields, $csvHeader = [])
    {
        foreach ($fields as $field) {
            $bindingField = Arr::get($field, 'binding_field');
            $fieldName = sanitize_text_field(Arr::get($field, 'value'), '');
            if (!$bindingField || !$fieldName) {
                continue;
            }
            $formField = Arr::get($formFields, $fieldName, []);
            $formField['name'] = $fieldName;
            if ($this->isCsvFile()) {
                if (!isset($csvHeader, $bindingField)) {
                    continue;
                }
                $fieldKey = $csvHeader[$bindingField];
            } else {
                if (!isset($data, $bindingField)) {
                    continue;
                }
                $fieldKey = $bindingField;
            }
            $fieldValue = $this->filterAndValidateFieldValue(Arr::get($data, $fieldKey), $formField);
            if (false !== $fieldValue) {
                $newResponse[$fieldName] = fluentFormSanitizer($fieldValue, $fieldName, $formFields);
            }
        }
    }
    
    protected function resolveSubmissionInfoMapping($fields, $data, &$newData, $csvHeader = [])
    {
        $supportedSubmissionInfoFields = $this->getSubmissionInfoFields();
        foreach ($fields as $field) {
            $bindingField = Arr::get($field, 'binding_field');
            $fieldName = sanitize_text_field(Arr::get($field, 'value'), '');
            if (!$bindingField || !$fieldName || !in_array($fieldName, $supportedSubmissionInfoFields)) {
                continue;
            }
            if ($this->isCsvFile()) {
                if (!isset($csvHeader, $bindingField)) {
                    continue;
                }
                $fieldKey = Arr::get($csvHeader, $bindingField);
            } else {
                if (!isset($data, $bindingField)) {
                    continue;
                }
                $fieldKey = $bindingField;
            }
            $fieldInfoValue = Arr::get($data, $fieldKey);
            // Skip, if submission info value is array, or object
            if (is_array($fieldInfoValue) || is_object($fieldInfoValue)) {
                continue;
            }
            $fieldInfoValue = fluentFormSanitizer($fieldInfoValue);
            if (in_array($fieldName, ['payment_total', 'total_paid'])) {
                $fieldInfoValue = self::parseCurrencyValue($fieldInfoValue);
                if (!is_numeric($fieldInfoValue)) {
                    if (isset($newData['payment_status'])) {
                        unset($newData['payment_status']);
                    }
                    if (isset($newData['payment_method'])) {
                        unset($newData['payment_method']);
                    }
                    continue;
                }
            }
            if (in_array($fieldName, ['payment_status', 'payment_method'])) {
                $fieldInfoValue = strtolower($fieldInfoValue);
            }
            $newData[$fieldName] = $fieldInfoValue;
        }
    }
    
    /**
     * Parse a currency-formatted string into a plain numeric value.
     * Handles all common formats:
     *   "$1,234.56"   → "1234.56"   (US/UK)
     *   "€1.234,56"   → "1234.56"   (European)
     *   "1 234,56 €"  → "1234.56"   (French/space-separated)
     *   "¥1,234"      → "1234"      (no decimals)
     *   "-$25.00"     → "-25.00"    (negative)
     *   "25.00"       → "25.00"     (already numeric)
     *
     * Known limitations:
     *   - Dot-only thousands (e.g. "1.000" for 1000) are treated as decimal 1.0.
     *   - Trailing minus ("25.00-") and parenthetical negatives ("(25.00)") are not supported.
     */
    protected static function parseCurrencyValue($value)
    {
        if (!is_scalar($value) || $value === '') {
            return '';
        }

        $value = trim((string) $value);

        if (is_numeric($value)) {
            return $value;
        }

        // Strip everything except digits, dots, commas, and minus
        $cleaned = preg_replace('/[^\d.,-]/', '', $value);

        if (!$cleaned || $cleaned === '-') {
            return $value;
        }

        // Detect decimal separator by finding the last dot or comma
        $lastDot = strrpos($cleaned, '.');
        $lastComma = strrpos($cleaned, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Both present — whichever comes last is the decimal separator
            if ($lastComma > $lastDot) {
                // European: 1.234,56 → remove dots, replace comma with dot
                $cleaned = str_replace('.', '', $cleaned);
                $cleaned = str_replace(',', '.', $cleaned);
            } else {
                // US/UK: 1,234.56 → remove commas
                $cleaned = str_replace(',', '', $cleaned);
            }
        } elseif ($lastComma !== false) {
            // Only commas — check if it's a decimal separator or thousands
            $afterComma = substr($cleaned, $lastComma + 1);
            if (strlen($afterComma) <= 2 && substr_count($cleaned, ',') === 1) {
                // Single comma with 1-2 digits after → decimal separator (e.g. "25,50")
                $cleaned = str_replace(',', '.', $cleaned);
            } else {
                // Multiple commas or 3+ digits after → thousands separator (e.g. "1,234,567")
                $cleaned = str_replace(',', '', $cleaned);
            }
        }
        // Only dots (or neither): dot is already the decimal separator, nothing to do

        // Preserve negative sign
        $negative = (isset($cleaned[0]) && $cleaned[0] === '-') ? '-' : '';
        $cleaned = ltrim($cleaned, '-');

        return $negative . $cleaned;
    }

    protected function filterAndValidateFieldValue($fieldValue, $formField)
    {
        $element = Arr::get($formField, 'element');
        if (('select' == $element && Arr::isTrue($formField, 'attributes.multiple'))) {
            $element = 'multi_select';
        }
        $formattedFieldValue = $fieldValue;
        $formattingFields = [
            'input_name',
            'input_checkbox',
            'input_file',
            'input_image',
            'multi_select',
            'address',
            'repeater_field',
            'tabular_grid'
        ];
        if (in_array($element, $formattingFields) && is_string($fieldValue)) {
            $formattedFieldValue = [];
            if ('input_name' == $element) {
                $formattedFieldValue['first_name'] = $fieldValue;
            } elseif (in_array($element, ['input_checkbox', 'multi_select', 'input_file', 'input_image'])) {
                if (strpos($fieldValue, ', ') !== false) {
                    $formattedFieldValue = explode(', ', $fieldValue);
                } else {
                    $formattedFieldValue[] = $fieldValue;
                }
            } elseif ('address' == $element) {
                $formattedFieldValue['address_line_1'] = $fieldValue;
            } elseif (in_array($element, ['repeater_field', 'tabular_grid'])) {
                $formattedFieldValue = false;
            }
        }
        $formData = [
            $formField['name'] => $formattedFieldValue
        ];
        if (method_exists(Helper::class, 'validateInput')) {
            if (Helper::validateInput($formField, $formData, $this->form)) {
                return false;
            }
        }
        return $formattedFieldValue;
    }
    
    /**
     * Return response array on success otherwise, throws fail Exception
     *
     * @param array $submissions
     * @return array response
     * @throws Exception
     */
    protected function insertSubmissions($submissions)
    {
        $previousItem = Submission::where('form_id', $this->form->id)
            ->orderBy('id', 'DESC')
            ->first();
        if ($previousItem) {
            $serialNumber = $previousItem->serial_number + 1;
        } else {
            $serialNumber = 1;
        }
        $isSubmissionInserted = false;
        foreach ($submissions as $submission) {
            if (!Arr::get($submission, 'user_id')) {
                $submission['user_id'] = get_current_user_id();
            }
            if (!isset($submission['source_url'])) {
                $submission['source_url'] = '';
            }
            if (!isset($submission['browser'])) {
                $submission['browser'] = '';
            }
            if (!isset($submission['device'])) {
                $submission['device'] = '';
            }
            if (!isset($submission['ip'])) {
                $submission['ip'] = '';
            }
            if (!isset($submission['created_at'])) {
                $submission['created_at'] = current_time('mysql');
            }
            $submission['updated_at'] = current_time('mysql');
            $submission['form_id'] = $this->form->id;
            $submission['serial_number'] = $serialNumber;
            if ($insertId = Submission::insertGetId($submission)) {
                if (!$isSubmissionInserted) {
                    $isSubmissionInserted = true;
                }
                if (isset($submission['response']) && Helper::isJson($submission['response']) && $submissionDetails = \json_decode($submission['response'], true)) {
                    (new SubmissionService())->recordEntryDetails($insertId, $this->form->id, $submissionDetails);
                }
                $serialNumber++;
            }
        }
        if ($isSubmissionInserted) {
            return [
                'message'          => __("Entries Imported Successfully", 'fluentformpro'),
                'entries_page_url' => admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . $this->form->id),
            ];
        }
        $this->failed("File has not data to import");
    }
    
    
    protected function getCsvData()
    {
        require_once(FLUENTFORMPRO_DIR_PATH . '/libs/CSVParser/CSVParser.php');
        $csvParser = new \CSVParser();
        $csvParser->load_data($this->fileData);
        $csvDelimiter = Arr::get($this->attrs, 'csv_delimiter');
        if ('comma' == $csvDelimiter) {
            $csvDelimiter = ",";
        } elseif ('semicolon' == $csvDelimiter) {
            $csvDelimiter = ";";
        } else {
            $csvDelimiter = $csvParser->find_delimiter();
        }
        return $csvParser->parse($csvDelimiter);
    }
    
    protected function getFormattedFormFields()
    {
        $fields = FormFieldsParser::getFields($this->form, true);
        $inputs = FormFieldsParser::getInputs($this->form, ['admin_label']);
        $labels = $this->getFileDataAdminLabels('form_fields');
        $flattenedFields = [];
        foreach ($fields as $field) {
            if ('container' === $field['element'] && $columns = Arr::get($field, 'columns')) {
                foreach ($columns as $column) {
                    $flattenedFields = array_merge($flattenedFields, Arr::get($column, 'fields', []));
                }
            } else {
                $flattenedFields[] = $field;
            }
        }
        $formattedFormFields = [];
        if ($this->isCsvFile()) {
            $flattenedFields = array_filter($flattenedFields, function ($field) {
                return !in_array($field['element'], ['tabular_grid', 'repeater_field']);
            });
        }
        
        foreach ($flattenedFields as $field) {
            $name = Arr::get($field, 'attributes.name');
            if (!$name) {
                continue;
            }
            $label = Arr::get($inputs, "$name.admin_label", $name);
            
            $bindingField = '';
            if (in_array($label, $labels)) {
                $bindingField = $name;
                if ($this->isCsvFile()) {
                    $bindingField = $label;
                }
            }
            $formattedFormFields[$name] = [
                'value'         => $name,
                'label'         => $label,
                'binding_field' => $bindingField,
            ];
        }
        return $formattedFormFields;
    }
    
    protected function getFormattedSubmissionInfoFields()
    {
        $labels = $this->getFileDataAdminLabels('submission_info_fields');
        $submissionInfoField = [];
        foreach ($this->getSubmissionInfoFields() as $field) {
            $bindingField = '';
            if (in_array($field, $labels)) {
                $bindingField = $field;
            }
            $submissionInfoField[$field] = [
                'value'         => $field,
                'label'         => $field,
                'binding_field' => $bindingField,
            ];
        }
        return $submissionInfoField;
    }
    
    protected function getFileDataAdminLabels($fieldKey = '')
    {
        $adminLabels = [];
        if ($this->formattedFileData) {
            if ($this->isJsonFile() && $fields = Arr::get($this->formattedFileData, $fieldKey, [])) {
                $adminLabels = array_column($fields, 'label');
            } elseif ($this->isCsvFile()) {
                $adminLabels = array_column($this->formattedFileData, 'label');
            }
        }
        return $adminLabels;
    }
    
    protected function getSubmissionInfoFields()
    {
        $submissionInfoFields = [
            'source_url',
            'user_id',
            'status',
            'is_favourite',
            'browser',
            'device',
            'ip',
            'city',
            'country',
            'created_at',
        ];
        
        if ($this->form->has_payment) {
            $submissionInfoFields = array_merge($submissionInfoFields,
                ['payment_status', 'payment_method', 'payment_type', 'currency', 'payment_total', 'total_paid']);
        }
        return $submissionInfoFields;
    }
    
    protected function resetEntries()
    {
        Submission::where('form_id', $this->form->id)
            ->delete();
        SubmissionMeta::where('form_id', $this->form->id)
            ->delete();
        EntryDetails::where('form_id', $this->form->id)
            ->delete();
        FormAnalytics::where('form_id', $this->form->id)
            ->delete();
        Log::where('parent_source_id', $this->form->id)
            ->whereIn('source_type', ['submission_item', 'form_item', 'draft_submission_meta'])
            ->delete();
        if ($this->form->has_payment) {
            wpFluent()->table('fluentform_order_items')
                ->where('form_id', $this->form->id)
                ->delete();
            
            wpFluent()->table('fluentform_transactions')
                ->where('form_id', $this->form->id)
                ->delete();
            
            wpFluent()->table('fluentform_subscriptions')
                ->where('form_id', $this->form->id)
                ->delete();
        }
    }
}
