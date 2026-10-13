<?php

namespace FluentFormPro\Components\DynamicField;

use FluentForm\Framework\Helpers\ArrayHelper as Arr;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DynamicCsv
{
    protected $source = 'dynamic_csv';
    public function __construct()
    {
        add_filter('fluentform/dynamic_field_sources', [$this, 'addDynamicCsvOnSource']);
        add_filter('fluentform/dynamic_field_filter_get_result' . $this->source, [$this, 'getResult'], 10, 2);
    }

    public function addDynamicCsvOnSource($sources) {
        $sources[$this->source] = __('Dynamic CSV', 'fluentformpro');
        return $sources;
    }

    public function getResult($_, $config)
    {
        $csvUrl = Arr::get($config, 'csv_url', '');
        $csvUrl = esc_url_raw(trim((string) $csvUrl), ['http', 'https']);
        if (!$csvUrl) {
            throw new \Exception('invalid error');
        }

        // SECURITY (PRO-25): the CSV source was fetched with file_get_contents(), and
        // esc_url_raw() leaves a bare filesystem path unchanged — so csv_url=/etc/wp-config
        // read arbitrary local files, and any http(s) URL was a full SSRF with the response
        // reflected back to the caller. Require an http/https URL, reject private/reserved
        // destinations, and fetch via wp_safe_remote_get (which re-validates redirects).
        $scheme = strtolower((string) wp_parse_url($csvUrl, PHP_URL_SCHEME));

        // COMPAT (PRO-25 review #243): wp_http_validate_url also rejects private/reserved hosts and
        // non-standard ports, so a Dynamic CSV pointed at an intranet endpoint stops resolving after
        // upgrade with no way back. Mirror the integration allowlists (activecampaign_allow_http,
        // salesforce/zoho hosts) and give site PHP an explicit, default-off opt-in for a known-good
        // internal source. A form manager cannot reach this — only code in the site can.
        // The http/https requirement below is NOT filterable, so the local-file read this fix closed
        // (csv_url=/etc/wp-config) stays closed no matter what the filter returns.
        $allowInternal = (bool) apply_filters('fluentform/dynamic_csv_allow_internal_url', false, $csvUrl, $config);

        if (!in_array($scheme, ['http', 'https'], true) || (!wp_http_validate_url($csvUrl) && !$allowInternal)) {
            throw new \Exception(__('Invalid url', 'fluentformpro')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if (!class_exists('CSVParser')) {
            require_once(FLUENTFORMPRO_DIR_PATH . 'libs/CSVParser/CSVParser.php');
        }
        $csvParser = new \CSVParser;

        $requestArgs = [
            'timeout'     => 15,
            'redirection' => 2,
        ];

        // wp_safe_remote_get would re-block the very host the filter just permitted, so an opted-in
        // internal source has to go through wp_remote_get. Everything else keeps the safe client.
        $response = $allowInternal
            ? wp_remote_get($csvUrl, $requestArgs)
            : wp_safe_remote_get($csvUrl, $requestArgs);
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            throw new \Exception(__('Invalid url', 'fluentformpro')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $content = wp_remote_retrieve_body($response);
        if (!$content) {
            throw new \Exception(__('Invalid url', 'fluentformpro')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $csvParser->load_data($content);
        $csvDelimiter = Arr::get($config, 'csv_delimiter');
        if ('comma' == $csvDelimiter) {
            $csvDelimiter = ",";
        } elseif ('semicolon' == $csvDelimiter) {
            $csvDelimiter = ";";
        } else {
            $csvDelimiter = $csvParser->find_delimiter();
        }
        $result = $csvParser->parse($csvDelimiter);

        if(!$result) {
            throw new \Exception(__('Empty data', 'fluentformpro')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $headers = array_shift($result);
        $limit = (int)Arr::get($config, 'result_limit');
        if ($limit && $limit < count($result)) {
            $result = array_slice($result, 0, $limit);
        }

        $value = sanitize_text_field(Arr::get($config, 'template_value.value', "{{$headers[0]}}"));
        $label = sanitize_text_field(Arr::get($config, 'template_label.value', "{{$headers[0]}}"));
        if (!$value) {
            return [];
        }
        $validOptions = [];
        $uniqueResult = 'yes' === Arr::get($config, 'unique_result');
        $uniqueValueSet = [];
        foreach ($result as $key  => $row) {
            if (count($headers) !== count($row)) {
                continue;
            }
            $result[$key] = array_combine($headers, $row);

            // Replace placeholders in value
            $valueResult = $this->replacePlaceholders($value, $result[$key]);
            if (!is_string($value)) {
                continue;
            }
            $valueResult = esc_attr($valueResult);
            if (!$valueResult) {
                continue;
            }
            // Replace placeholders in label
            $labelResult = $this->replacePlaceholders($label, $result[$key]);

            // Use value result as label if label result is not a string or empty
            if (!is_string($labelResult)) {
                $labelResult = $valueResult;
            }
            $labelResult = esc_html($labelResult);
            if (!$labelResult) {
                $labelResult = $valueResult;
            }
            // Check for duplicate values and skip if found
            if ($uniqueResult && in_array($valueResult, $uniqueValueSet)) {
                continue;
            }
            $validOptions[] = ['id' => $key, 'label' => $labelResult, 'value' => $valueResult];
            $uniqueValueSet[] = $valueResult;
        }

        return [
            'result_counts'  => [
                'total' => count($result),
                'valid' => count($validOptions),
            ],
            'valid_options'  => $validOptions,
            'all_options'    => $result,
        ];
    }

    protected function replacePlaceholders($string, $row)
    {
        return preg_replace_callback('/\{([^\}]+)\}/', function ($matches) use ($row) {
            return isset($row[$matches[1]]) ? trim($row[$matches[1]]) : '';
        }, $string);
    }
}