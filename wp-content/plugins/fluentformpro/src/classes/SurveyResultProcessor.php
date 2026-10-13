<?php
namespace FluentFormPro\classes;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Report\ReportHelper;
use FluentForm\Framework\Helpers\ArrayHelper;

class SurveyResultProcessor
{
    public function getSurveyResultHtml($attributes)
    {
        $attributes = wp_parse_args($attributes, array(
            'form_id'    => null,
            'field_name' => '',
            'label'      => 'yes',
            'counts'     => 'yes'
        ));
        if (empty($attributes['form_id'])) {
            return '';
        }

        $form = wpFluent()->table('fluentform_forms')->find($attributes['form_id']);

        if (!$form) {
            return '';
        }

        $reportingFields = $this->getReportableFields($form, $attributes['field_name']);

        // The generic report is discarded for ranking fields, and it is not one
        // query: ReportHelper calls getEntryTotal() inside its row loop, so it
        // costs one grouped query plus one per distinct option value. Ask for
        // it only for the fields that use it.
        $rankingFields = [];
        if (has_filter('fluentform/reports/format_field_input_ranking')) {
            foreach ($reportingFields as $fieldName => $field) {
                if ('input_ranking' === ArrayHelper::get($field, 'element')) {
                    $rankingFields[$fieldName] = $field;
                }
            }
        }

        $reports = ReportHelper::getInputReport($form->id, array_keys(array_diff_key($reportingFields, $rankingFields)));

        $formattedReports = [];
        foreach ($reportingFields as $reportKey => $field) {
            if (isset($rankingFields[$reportKey])) {
                $ranking = $this->formatRankingReport([], $field, $reportKey, $form->id);
                if ($ranking) {
                    $formattedReports[$reportKey] = $ranking;
                }
                continue;
            }

            if (!isset($reports[$reportKey])) {
                continue;
            }

            $report = $reports[$reportKey];
            $formattedValues = [];

            $options = $reportingFields[$reportKey]['options'];;

            foreach ($report['reports'] as $reportItem) {
                $reportItem['percent'] = round( ($reportItem['count'] / $report['total_entry']) * 100);
                if (isset($options[$reportItem['value']])) {
                    $itemKey = $reportItem['value'];
                    $reportItem['value'] = $options[$itemKey];
                    $formattedValues[$itemKey] = $reportItem;
                }
            }

            $shotableArray = array_intersect(array_keys($options), array_keys($formattedValues));

            $formattedValues = array_replace(array_flip($shotableArray), $formattedValues);

            $formattedValues = array_filter($formattedValues, 'is_array');

            $report['reports'] = $formattedValues;
            $report['label'] = $reportingFields[$reportKey]['label'];
            $report['options'] = $options;
            $report['element'] = $reportingFields[$reportKey]['element'];
            $formattedReports[$reportKey] = $report;
        }



        $reportHtml = '<div class="ff_poll_wrapper">';
        foreach ($formattedReports as $formattedReport) {
            $reportHtml .= $this->getGereratedReortHtml($formattedReport, $attributes['label'], $attributes['counts'], $form);
        }
        $reportHtml .= '</div>';

        $css = $this->getPollCss();
        return  $css.$reportHtml;

    }

    private function formatRankingReport($report, $field, $fieldName, $formId)
    {
        $aggregated = apply_filters(
            'fluentform/reports/format_field_input_ranking',
            $report,
            array_merge($field, ['name' => $fieldName]),
            $formId,
            ['read', 'unread', 'unapproved', 'approved', 'declined', 'unconfirmed', 'confirmed']
        );

        if ('ranking' !== ArrayHelper::get($aggregated, 'type')) {
            return null;
        }

        $totalSubmissions = (int) ArrayHelper::get($aggregated, 'total_submissions', 0);

        $formattedValues = [];
        foreach (ArrayHelper::get($aggregated, 'options', []) as $option) {
            // An option added after these submissions has no rows at all. A 0%
            // bar would claim nobody ranked it first, which is a different
            // statement from having no data.
            if (0 === (int) ArrayHelper::get($option, 'total_responses', 0)) {
                continue;
            }

            $firstPlaceCount = 0;
            foreach (ArrayHelper::get($option, 'distribution', []) as $slot) {
                if (1 === (int) ArrayHelper::get($slot, 'position')) {
                    $firstPlaceCount = (int) ArrayHelper::get($slot, 'count', 0);
                    break;
                }
            }

            $label = ArrayHelper::get($option, 'label', ArrayHelper::get($option, 'value'));
            $averageRank = ArrayHelper::get($option, 'average_rank');
            if (null !== $averageRank) {
                // str_replace, not sprintf: a translation carrying a second
                // placeholder would make sprintf throw ArgumentCountError on
                // PHP 8, fataling the page and the submission response.
                $label .= ' (' . str_replace(
                    '%s',
                    number_format((float) $averageRank, 2),
                    /* translators: %s: average rank position, e.g. 1.33 */
                    __('avg #%s', 'fluentformpro')
                ) . ')';
            }

            $formattedValues[] = [
                'value'   => $label,
                'count'   => $firstPlaceCount,
                'percent' => $totalSubmissions > 0
                    ? (int) round(($firstPlaceCount / $totalSubmissions) * 100)
                    : 0,
            ];
        }

        if (!$formattedValues) {
            return null;
        }

        // The aggregator orders by average rank, which the admin card wants. Here
        // the bar length is the message, so it has to descend.
        usort($formattedValues, function ($a, $b) {
            return $b['percent'] <=> $a['percent'];
        });

        $report['reports'] = $formattedValues;
        $report['total_entry'] = $totalSubmissions;
        $report['label'] = ArrayHelper::get($field, 'label');
        $report['options'] = ArrayHelper::get($field, 'options', []);
        $report['element'] = 'input_ranking';

        return $report;
    }

    public function getReportableFields($form, $reportFieldName)
    {

        $reportFieldNames = explode(',', $reportFieldName);

        $fields = FormFieldsParser::getInputs($form, ['element', 'options', 'label']);
        $reportableFields = Helper::getReportableInputs();
        $reportableItems = [];

        foreach ($fields as $fieldName => $field) {
            if ($field['element'] == 'select_country') {
                $field['options'] = getFluentFormCountryList();
            }
            if (in_array($field['element'], $reportableFields) && !empty($field['options'])) {
                $reportableItems[$fieldName] = $field;
            }
        }

        if (!$reportFieldName) {
            return $reportableItems;
        }

        $returnItems = [];
        foreach ($reportFieldNames as $reportFieldName) {
            if (isset($reportableItems[$reportFieldName])) {
                $returnItems[$reportFieldName] = $reportableItems[$reportFieldName];
            }
        }

        return $returnItems;
    }

    public function getGereratedReortHtml($data, $showLabel = 'yes', $showCount = 'yes', $form)
    {
        $showLabel = $showLabel == 'yes';
        $showCount = $showCount == 'yes';
        ob_start();
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Survey result HTML rendering with sanitized values
        ?>
        <div class="ff_poll_result ff_poll_<?php echo $data['element']; ?>">
            <?php if ($showLabel): ?>
                <div class="ff-poll-label"><?php echo apply_filters('fluentform/survey_field_label', $data['label'], $form); ?></div>
            <?php endif; ?>
            <?php foreach ($data['reports'] as $report): ?>
                <div class="ff-poll-answer">
                    <div class="ff-poll-answer-label-wrap">
                        <div class="ff-poll-answer-percent">
                            <span><?php echo $report['percent']; ?>%</span>
                            <?php if($showCount):  ?>
                            <div class="ff-poll-answer-count">(<?php echo $report['count'] . apply_filters('fluentform/survey_votes_text', __(' votes', 'fluentformpro'), $form) ; ?>)</div>
                            <?php endif; ?>
                        </div>
                        <div class="ff-poll-answer-label"><?php echo $report['value']; ?></div>
                    </div>
                    <div class="ff-poll-answer-bar-wrap">
                        <div class="ff-poll-answer-bar" style="width:<?php echo $report['percent']; ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if($showCount): ?>
                <div class="ff-poll-total"><?php echo __('Total Votes: ', 'fluentformpro') . $data['total_entry']; ?></div>
            <?php endif; ?>
        </div>
        <?php
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        return ob_get_clean();
    }

    public function getPollCss()
    {
        ob_start();
        ?>
        <style type="text/css">
            .ff_poll_wrapper .ff_poll_result {
                background-color: #f7fafc;
                border: 1px solid #e3e8ee;
                margin: 26px 0;
                padding: 20px
            }

            .ff_poll_result .ff-poll-label {
                text-align: center;
                font-weight: 700;
                font-size: 18px;
                margin: 15px 0 0
            }

            .ff_poll_result .ff-poll-answer {
                margin: 15px 0
            }

            .ff_poll_result .ff-poll-answer-label-wrap {
                overflow: hidden
            }

            .ff_poll_result .ff-poll-answer-label {
                font-size: 16px;
                color: #333;
                margin-right: 150px
            }

            .ff_poll_result .ff-poll-answer-percent {
                font-size: 14px;
                color: #333;
                float: right
            }

            .ff_poll_result .ff-poll-answer-count {
                display: inline;
                color: #999
            }

            .ff_poll_result .ff-poll-answer-bar-wrap {
                height: 20px;
                position: relative;
                background-color: #e9ecef;
                border-radius: 4px;
            }

            .ff_poll_result .ff-poll-answer-bar {
                position: absolute;
                top: 0;
                left: 0;
                height: 100%;
                background-image: linear-gradient(45deg,rgba(255,255,255,.15) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.15) 50%,rgba(255,255,255,.15) 75%,transparent 75%,transparent);
                background-size: 1rem 1rem;
                white-space: nowrap;
                background-color: #007bff;
                transition: width .6s ease;
                border-radius: 4px;
            }

            .ff_poll_result .ff-poll-total {
                font-size: 18px;
                text-align: center;
                margin: 0 0 15px 0
            }
        </style>
        <?php
        return ob_get_clean();
    }

}