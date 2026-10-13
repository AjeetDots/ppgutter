<?php

namespace FluentFormPro\Components\DynamicField;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Manager\FormManagerService;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentFormPro\Payments\PaymentHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class FluentformSubmission extends DynamicBase
{

    public function __construct()
    {
        parent::__construct('fluentform_submission', 'fluentform_submissions', $this->joinTables());
        add_filter("fluentform/dynamic_field_basic_filters_{$this->source}", [$this, 'resolveBasicFilterQuery'], 10, 2);
    }

    public function resolveBasicFilterQuery($filters, $basicQuery)
    {
        $formId = Arr::get($basicQuery, 'form_id');
        $formField = Arr::get($basicQuery, 'form_field');
        if ($formId) {
            $filters = [
                [
                    [
                        'column'    => 'fluentform_submissions.form_id',
                        'custom'   => false,
                        'operator' => '=',
                        'value'    => $formId
                    ]
                ]
            ];
            if ($formField) {
                $filters[0][] = [
                    'column'    => 'field_name',
                    'custom'   => false,
                    'operator' => '=',
                    'value'    => $formField
                ];
            }
        }
        return $filters;
    }

    public function selectableColumns()
    {
        return [
            'fluentform_submissions.id',
            'fluentform_submissions.form_id',
            'fluentform_submissions.user_id',
            'serial_number',
            'response',
            'source_url',
            'status',
            'browser',
            'device',
            'ip',
            'payment_status',
            'payment_method',
            'payment_type',
        ];
    }

    public function getSupportedColumns()
    {
        return [
            'fluentform_submissions.id'      => __('Submission ID', 'fluentformpro'),
            'fluentform_submissions.user_id' => __('User ID', 'fluentformpro'),
            'fluentform_submissions.form_id' => __('Form Id', 'fluentformpro'),
            'serial_number'                  => __('Serial Number', 'fluentformpro'),
            'status'                         => __('Status', 'fluentformpro'),
            'is_favourite'                   => __('Favourite', 'fluentformpro'),
            'source_url'                     => __('Source URL', 'fluentformpro'),
            'browser'                        => __('Browser', 'fluentformpro'),
            'device'                         => __('Device', 'fluentformpro'),
            'ip'                             => __('Ip', 'fluentformpro'),
            'payment_status'                 => __('Payment Status', 'fluentformpro'),
            'payment_method'                 => __('Payment Method', 'fluentformpro'),
            'payment_type'                   => __('Payment Type', 'fluentformpro'),
            'field_name'                     => __('Form Field Name', 'fluentformpro'),
            'field_value'                    => __('Form Field Value', 'fluentformpro'),
            'created_at'                     => __('Created At', 'fluentformpro'),
            'updated_at'                     => __('Updated At', 'fluentformpro'),
        ];
    }

    protected function joinTables()
    {
        return [
            [
                'enable' => false,
                'columns' => ['field_value', 'field_name'],
                'join'   => ['fluentform_entry_details', 'fluentform_submissions.id', '=', 'fluentform_entry_details.submission_id']
            ]
        ];
    }

    /**
     * Retrieve the value options for the editor.
     *
     * @return array The value options array.
     */
    public function getValueOptions()
    {
        $usersId = $formsId = [];

        // These options are used by the builder UI when configuring submission-based
        // dynamic fields, so they should only expose metadata from forms the current
        // manager is allowed to inspect.
        $allowedFormIds = $this->getCurrentUserAllowedFormIds();

        if ($forms = Helper::getForms()) {
            Arr::forget($forms, 0);

            if (false !== $allowedFormIds) {
                $forms = array_intersect_key($forms, array_flip($allowedFormIds));
            }

            $formsId = $forms;
        }

        foreach (get_users(array('number' => $this->getEditorValueOptionsLimit())) as $user) {
            $label = $user->ID;
            if ($user->display_name) {
                $label = $user->display_name . '(' . $user->ID . ')';
            }
            $usersId[$user->ID] = $label;
        }

        $paymentMethods = [];
        $availableMethods = apply_filters('fluentform/available_payment_methods', []);
        foreach ($availableMethods as $method) {
            $paymentMethods[Arr::get($method, 'method_value')] = Arr::get($method, 'title');
        }

        $statuses = wp_parse_args(Helper::getEntryStatuses(), [
            'confirmed'   => __('Confirmed', 'fluentformpro'),
            'unconfirmed' => __('Unconfirmed', 'fluentformpro'),
        ]);
        Arr::forget($statuses, 'favorites');

        $submissionsQuery = wpFluent()->table('fluentform_submissions')
            ->select(['device', 'browser', 'ip'])
            ->groupBy(['device', 'browser', 'ip']);

        if (false !== $allowedFormIds) {
            $submissionsQuery->whereIn('form_id', $allowedFormIds ?: [0]);
        }

        $submissions = $submissionsQuery
            ->limit(1000)
            ->get();

        $formattedDevice = $formattedBrowser = $ips = [];
        foreach ($submissions as $submission) {
            if ($submission->device) {
                $formattedDevice[$submission->device] = $submission->device;
            }
            if ($submission->browser) {
                $formattedBrowser[$submission->browser] = $submission->browser;
            }
            if ($submission->ip) {
                $ips[$submission->ip] = $submission->ip;
            }
        }

        return [
            'fluentform_submissions.user_id' => $usersId,
            'fluentform_submissions.form_id' => $formsId,
            'payment_status'                 => PaymentHelper::getPaymentStatuses(),
            'payment_method'                 => $paymentMethods,
            'payment_type'                   => [
                'product'      => __('Product', 'fluentformpro'),
                'subscription' => __('Subscription', 'fluentformpro'),
                'donation'     => __('Donation', 'fluentformpro')
            ],
            'is_favourite'                   => [
                '1' => __('True', 'fluentformpro'),
                '0' => __('False', 'fluentformpro')
            ],
            'status'                         => $statuses,
            'browser'                        => $formattedBrowser,
            'device'                         => $formattedDevice,
            'ip'                             => $ips,
        ];
    }

    public function setResult()
    {
        parent::setResult();

        $allowedFormIds = $this->getCurrentUserAllowedFormIds();

        if (false === $allowedFormIds) {
            return;
        }

        $allowed = array_map('intval', $allowedFormIds);

        $filtered = [];
        foreach ($this->result as $row) {
            if (isset($row->form_id) && in_array((int) $row->form_id, $allowed, true)) {
                $filtered[] = $row;
            }
        }

        $this->result = $filtered;
    }

    protected function getCurrentUserAllowedFormIds()
    {
        return DynamicFieldHelper::allowedFormIdsForAuthor($this->resolveAuthorId());
    }

    public function getDefaultConfig()
    {
        return [
            'filters'        => [],
            'sort_by'        => 'fluentform_submissions.id',
            'order_by'       => 'DESC',
            'query_type'     => 'basic',
            'basic_query'    => [
                'form_id'    => '',
                'form_field' => ''
            ],
            'result_limit'   => $this->getResultLimit(),
            'template_value' => [
                'value'  => '{id}',
                'custom' => false
            ],
            'template_label' => [
                'value'  => 'Submission ({id})',
                'custom' => true
            ]
        ];
    }

    /**
     * @throws \Exception
     */
    public function getFormFields($formId)
    {
        if ($formId && $form = Helper::getForm($formId)) {
            $fields = [];
            foreach (FormFieldsParser::getFields($form, true) as $field) {
                $element = Arr::get($field, 'element');
                $name = Arr::get($field, 'attributes.name');
                if (!$name || in_array($element, $this->unSupportedFormFields())) {
                    continue;
                }
                $label = Arr::get($field, 'settings.label');
                if (!$label) {
                    $label = Arr::get($field, 'settings.admin_field_label');
                }
                $fields[$name] = $label ? $label : $name;
            }
            return $fields;
        }
        throw new \Exception('Invalid Form');
    }

    protected function unSupportedFormFields()
    {
        return ['input_repeat', 'input_hidden', 'repeater_field', 'tabular_grid', 'input_file', 'input_image'];
    }

}
