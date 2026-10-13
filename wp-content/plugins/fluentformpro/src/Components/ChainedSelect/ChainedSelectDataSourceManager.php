<?php

namespace FluentFormPro\Components\ChainedSelect;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use Exception;
use FluentForm\App\Modules\Form\Form;
use FluentForm\App\Modules\Form\FormFieldsParser;

class ChainedSelectDataSourceManager
{
    protected $app = null;

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function saveDataSource($csvParser)
    {
        try {
            $request = $this->app->request;
            $type = $request->get('type');
            $formId = intval($request->get('form_id'));
            $metaKey = sanitize_file_name($request->get('meta_key'));
            $file = null;

            if (empty($formId)) {
                throw new Exception(__('Invalid form ID.', 'fluentformpro'), 400);
            }

            if (empty($metaKey)) {
                throw new Exception(__('Invalid meta key.', 'fluentformpro'), 400);
            }

            // Ensure meta_key always starts with chained_select_
            if (strpos($metaKey, 'chained_select_') !== 0) {
                throw new Exception(__('Invalid meta key format.', 'fluentformpro'), 400);
            }

            $this->ensureUploadDirectory();

            if ($url = $request->get('url')) {
                $this->validateUrl($url);
                
                $parsedUrl = wp_parse_url($url);
                $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
                
                $hasCsvInPath = preg_match('/\.csv(\?|$|#|&|%)/i', $path);
                
                $hasCsvQueryParam = isset($parsedUrl['query']) && (
                    strpos($parsedUrl['query'], 'output=csv') !== false ||
                    strpos($parsedUrl['query'], 'format=csv') !== false ||
                    strpos($parsedUrl['query'], 'exportFormat=csv') !== false
                );
                
                if ($hasCsvInPath || $hasCsvQueryParam) {
                    // SECURITY (PRO-32) + COMPAT: legitimate CSV sources such as a Google Sheets
                    // "Publish to web" URL 307-redirect to googleusercontent.com, so we must follow
                    // redirects — but each hop is re-validated (validateUrl blocks link-local /
                    // private / non-http(s)), which wp_http_validate_url alone does not do for
                    // 169.254.x. Following manually with redirection => 0 lets us screen every hop
                    // and preserves the redirecting-source flow while keeping the SSRF closed.
                    $response = $this->fetchWithValidatedRedirects($url);

                    if (is_wp_error($response)) {
                        throw new Exception(__('Failed to fetch URL.', 'fluentformpro'), 400);
                    }

                    $responseCode = wp_remote_retrieve_response_code($response);
                    if ($responseCode !== 200) {
                        throw new Exception(__('Failed to fetch URL.', 'fluentformpro'), 400);
                    }

                    $data = wp_remote_retrieve_body($response);

                    if (empty($data)) {
                        throw new Exception(__('Empty file content.', 'fluentformpro'), 400);
                    }

                    // SECURITY: reject an oversized body before writing it to disk or parsing it.
                    if (strlen($data) > self::maxCsvBytes()) {
                        throw new Exception(__('CSV file is too large.', 'fluentformpro'), 400); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }

                    $path = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR;
                    $target = $path . '/' . $metaKey . '_' . $formId . '.csv';
                    
                    $realPath = realpath($path);
                    $realTarget = realpath(dirname($target));
                    if ($realTarget === false || strpos($realTarget, $realPath) !== 0) {
                        throw new Exception(__('Invalid file path.', 'fluentformpro'), 400);
                    }
                    
                    file_put_contents(
                        $target,
                        $data
                    );
                } else {
                    throw new Exception(__('File URL is not valid. URL must point to a CSV file.', 'fluentformpro'), 400);
                }
            } else {
                $file = $request->file('file.file') ?: $request->file('file');

                // Fallback for legacy payload shape.
                if (!$file && is_array($request->files('file'))) {
                    $file = $request->files('file')['file'] ?? null;
                }

                if (!$file || !method_exists($file, 'getContents')) {
                    throw new Exception(__('No file uploaded.', 'fluentformpro'), 400);
                }

                $name = method_exists($file, 'getClientOriginalName')
                    ? $file->getClientOriginalName()
                    : ($_FILES['file']['name'] ?? '');

                // SECURITY (review #243): reject an oversized upload from its on-disk size BEFORE
                // reading the whole file into memory, so a file over the cap cannot exhaust the PHP
                // worker before the check runs.
                if (method_exists($file, 'getPathname')) {
                    $uploadPath = $file->getPathname();
                    if ($uploadPath && is_readable($uploadPath) && filesize($uploadPath) > self::maxCsvBytes()) {
                        throw new Exception(__('CSV file is too large.', 'fluentformpro'), 400); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }
                }

                $data = $file->getContents();

                // Defense in depth: re-check the actual byte length after reading.
                if (strlen((string) $data) > self::maxCsvBytes()) {
                    throw new Exception(__('CSV file is too large.', 'fluentformpro'), 400); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
            }

            if (!isset($data) || !$data) {
                throw new Exception(__('Oops! Something went wrong.', 'fluentformpro'), 400);
            }

            $csvParser->load_data($data);

            $result = $csvParser->parse($csvParser->find_delimiter());

            if (is_array($result) && count($result)) {
//                 $this->saveFieldoptions($result, $formId, $metaKey);
                $success = true;
                if ($file) {
                    $success = $this->saveFile($file, $formId, $metaKey);
                }
                if ($success) {
                    wp_send_json_success([
                        'url'      => $url,
                        'name'     => $type === 'file' ? $name : '',
                        'headers'  => $result[0],
                        'type'     => $type,
                        'meta_key' => $metaKey
                    ]);
                }
            }

            throw new Exception(__('Oops! Something went wrong.', 'fluentformpro'), 400);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()], $e->getCode());
        }
    }

    /**
     * SECURITY (PRO-32): fetch a remote CSV while following redirects manually so every hop —
     * including the redirect targets that wp_http_validate_url does not screen for link-local /
     * cloud-metadata (169.254.x) — is validated by validateUrl(). This keeps legitimate redirecting
     * sources (e.g. Google Sheets "Publish to web") working without reopening the SSRF.
     *
     * @param string $url
     * @param int    $maxRedirects
     * @return array|\WP_Error
     * @throws Exception If a redirect target fails validation
     */
    /**
     * SECURITY: maximum accepted CSV body size (remote fetch and uploaded file). Filterable.
     *
     * @return int bytes
     */
    protected static function maxCsvBytes()
    {
        return (int) apply_filters('fluentform/chained_select_max_csv_bytes', 5 * 1024 * 1024); // 5 MB
    }

    protected function fetchWithValidatedRedirects($url, $maxRedirects = 3)
    {
        $currentUrl = $url;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            // Re-validate every hop (initial + each redirect target).
            $this->validateUrl($currentUrl);

            $response = wp_safe_remote_get($currentUrl, [
                'timeout'             => 10,
                'redirection'         => 0, // we follow manually so each target is screened
                'reject_unsafe_urls'  => true,
                'sslverify'           => true,
                // SECURITY: cap the downloaded body so a permitted remote source cannot return an
                // arbitrarily large payload and exhaust memory/disk/CPU when written and parsed.
                'limit_response_size' => self::maxCsvBytes() + 1, // +1 so we can detect an overflow
                'user-agent'          => 'FluentForm/' . FLUENTFORMPRO_VERSION,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            if (!in_array($code, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location) {
                return $response;
            }

            // Resolve a possibly-relative Location against the current URL, then loop to validate it.
            $currentUrl = \WP_Http::make_absolute_url($location, $currentUrl);
        }

        // Too many redirects.
        return new \WP_Error('http_request_failed', __('Too many redirects.', 'fluentformpro'));
    }

    /**
     * Validate URL format
     *
     * @param string $url The URL to validate
     * @throws Exception If URL is invalid
     */
    protected function validateUrl($url)
    {
        if (empty($url)) {
            throw new Exception(__('URL is required.', 'fluentformpro'), 400); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // Use WordPress URL validation
        if (!function_exists('wp_http_validate_url')) {
            require_once(ABSPATH . WPINC . '/http.php');
        }

        if (!wp_http_validate_url($url)) {
            throw new Exception(__('Invalid URL format.', 'fluentformpro'), 400); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // Parse URL to validate scheme
        $parsedUrl = wp_parse_url($url);

        if (!$parsedUrl || !isset($parsedUrl['scheme'])) {
            throw new Exception(__('Invalid URL format.', 'fluentformpro'), 400); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // Only allow http and https schemes
        $allowedSchemes = ['http', 'https'];
        if (!in_array(strtolower($parsedUrl['scheme']), $allowedSchemes)) {
            throw new Exception(__('Only HTTP and HTTPS URLs are allowed.', 'fluentformpro'), 400); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        // SECURITY (PRO-32): wp_http_validate_url blocks the standard private ranges but not
        // link-local 169.254.0.0/16 (cloud-metadata) or IPv6 link-local. Reject the host if it is,
        // or resolves to, a link-local address.
        $host = isset($parsedUrl['host']) ? strtolower($parsedUrl['host']) : '';
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        $blocked = strpos($ip, '169.254.') === 0 || $host === 'metadata.google.internal';

        // IPv6 link-local (fe80::/10) and unique-local (fc00::/7 → fc../fd..) — check the fd/fe80/fc
        // prefixes ONLY against a validated IPv6 literal, never the hostname string, so a normal
        // domain that merely starts with those letters (e.g. fdic.gov) is not a false positive.
        if (!$blocked && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $blocked = strpos($ip, 'fe80:') === 0 || strpos($ip, 'fc') === 0 || strpos($ip, 'fd') === 0;
        }

        if ($blocked) {
            throw new Exception(__('This URL host is not allowed.', 'fluentformpro'), 400); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    protected function formatOptions($options)
    {
        $headers = array_shift($options);

        $headers = array_map('trim', $headers);

        $formatOptions = [];
        foreach ($options as $key => $option) {
            if (count($headers) == count($option)) {
                $formatOptions[$key] = @array_combine($headers, $option);
            }
        }
        return array_filter($formatOptions);
    }

    protected function ensureUploadDirectory()
    {
        $path = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR;

        if (!is_dir($path)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct mkdir needed for upload directory creation
            mkdir($path, 0755);
            file_put_contents(
                wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR . '/.htaccess',
                file_get_contents(FLUENTFORMPRO_DIR_PATH . 'src/Stubs/htaccess.stub')
            );
        }

        if(!file_exists($path . '/index.php')) {
            file_put_contents(
                wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR . '/index.php',
                file_get_contents(FLUENTFORMPRO_DIR_PATH . 'src/Stubs/index.stub')
            );
        }

    }

    protected function saveFile($file, $formId, $metaKey)
    {
        $fileArray = $file->toArray();

        $path = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR;

        $target = $path . '/' . $metaKey . '_' . $formId . '.csv';
        
        $realPath = realpath($path);
        $realTarget = realpath(dirname($target));
        if ($realTarget === false || strpos($realTarget, $realPath) !== 0) {
            return false;
        }

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Required for CSV file upload handling
        if (move_uploaded_file($fileArray['tmp_name'], $target)) {
            return $target;
        }
        
        return false;
    }

    public function deleteDataSource()
    {
        $request = $this->app->request;

        $formId = intval($request->get('form_id'));
        $name = sanitize_text_field($request->get('name'));
        $metaKey = sanitize_file_name($request->get('meta_key'));

        if (empty($formId)) {
            wp_send_json_error(['message' => __('Invalid form ID.', 'fluentformpro')], 400);
            return;
        }

        if (empty($metaKey)) {
            wp_send_json_error(['message' => __('Invalid meta key.', 'fluentformpro')], 400);
            return;
        }

        if (strpos($metaKey, 'chained_select_') !== 0) {
            wp_send_json_error(['message' => __('Invalid meta key format.', 'fluentformpro')], 400);
            return;
        }

        $path = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR;

        $target = $path . '/' . $metaKey . '_' . $formId . '.csv';
        
        $realPath = realpath($path);
        $realTarget = realpath(dirname($target));
        if ($realTarget === false || strpos($realTarget, $realPath) !== 0) {
            wp_send_json_error(['message' => __('Invalid file path.', 'fluentformpro')], 400);
            return;
        }

        $this->maybeDeleteDataSource($formId, $name, $metaKey, $target);

        wp_send_json_success([
            'message' => __('Data source deleted successfully.', 'fluentformpro'),
            'headers' => ['Parent', 'Child', 'Grand Child'],
            'type'    => 'file',
            'url'     => ''
        ]);

        // wpFluent()->table('fluentform_form_meta')
        //     ->where('meta_key', $metaKey)
        //     ->where('form_id', $formId)
        //     ->delete();

        // wp_send_json_success([
        //     'message' => 'Data source deleted successfully.',
        //     'headers' => ['Parent', 'Child', 'Grand Child']
        // ]);
    }

    protected function maybeDeleteDataSource($formId, $name, $metaKey, $target)
    {
        $form = (new Form($this->app))->fetchForm($formId);

        $fields = FormFieldsParser::getFields($form);

        $chainedSelects = array_filter($fields, function ($field) {
            return $field->element == 'chained_select';
        });

        $currentField = array_filter($chainedSelects, function ($field) use ($name) {
            return $field->attributes->name == $name;
        });

        $currentField = reset($currentField);

        if (count($chainedSelects) == 1) {
            file_exists($target) && wp_delete_file($target);
        } else {
            $metaKeys = array_map(function ($field) {
                return $field->settings->data_source->meta_key;
            }, $chainedSelects);

            if (count(array_intersect($metaKeys, [$metaKey])) < 2) {
                file_exists($target) && wp_delete_file($target);
            }
        }

        if ($currentField) {
            return $this->updateCurrentField($currentField, $form, $formId);
        }
    }

    protected function updateCurrentField($currentField, $form, $formId)
    {
        $formFields = json_decode($form->form_fields, true);

        $currentField->settings->data_source = [
            'url'      => '',
            'name'     => '',
            'headers'  => ['Parent', 'Child', 'Grand Child'],
            'type'     => 'file',
            'meta_key' => null
        ];

        foreach ($formFields['fields'] as $key => $field) {
            if (!empty($field['attributes']['name']) && $field['attributes']['name'] == $currentField->attributes->name) {
                $formFields['fields'][$key] = $currentField;
            }
        }

        wpFluent()->table('fluentform_forms')->where('id', $formId)->update([
            'form_fields' => json_encode($formFields)
        ]);
    }

    public function getOptionsForNextField($csvParser)
    {
        $result = [];

        $options = $this->formatOptions($this->getOptions($csvParser));

        if (@$_REQUEST['filter_options'] == 'all') {
            return $this->sendAllOptions($options);
        }

        $params = $this->app->request->get('params');

        foreach ($options as $option) {
            $statuses = [];

            foreach ($params as $param) {
                $statuses[] = $option[$param['key']] == $param['value'];
            }

            if (count(array_filter($statuses)) == count($statuses)) {
                $result[] = $option;
            }
        }

        $response = array_unique(
            array_column($result, str_replace('\\' , '' , $_REQUEST['target_field']))
        );

        wp_send_json_success(array_combine($response, $response));
    }

    protected function sendAllOptions($options)
    {
        $keys = array_keys($_REQUEST['keys']);

        $keysToMatch = [$keys[0] => $_REQUEST['keys'][$keys[0]]];

        $result = array_fill_keys($keys, []);

        foreach ($keys as $index => $key) {
            if ($index == 0) {
                $result[$key] = array_values(array_unique(
                    array_column($options, $key)
                ));
            } else {
                $result[$key] = $this->getNextMatchingOptions(
                    $keysToMatch,
                    $key,
                    $options,
                    $result
                );

                $nextKey = next($keys);
                $keysToMatch = array_merge(
                    $keysToMatch,
                    [$nextKey => $_REQUEST['keys'][$nextKey]]
                );
            }
        }

        wp_send_json($result, 200);
    }

    protected function getNextMatchingOptions($keysToMatch, $targetKey, $options, $result)
    {
        foreach ($options as $option) {
            if ($keysToMatch === array_intersect_key($option, $keysToMatch)) {
                if (!in_array($option[$targetKey], $result[$targetKey])) {
                    $result[$targetKey][] = $option[$targetKey];
                }
            }
        }

        return $result[$targetKey];
    }

    protected function getOptions($csvParser)
    {
        $metaKey = sanitize_file_name($_REQUEST['meta_key']);
        $formId = intval($_REQUEST['form_id']);

        if (empty($formId) || $formId <= 0) {
            return [];
        }

        if (empty($metaKey) || preg_match('/[^a-zA-Z0-9_-]/', $metaKey)) {
            return [];
        }

        if (strpos($metaKey, 'chained_select_') !== 0) {
            return [];
        }

        $path = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR . '/';

        $file = $path . $metaKey . '_' . $formId . '.csv';
        
        $realPath = realpath($path);
        $realFile = realpath($file);
        if ($realFile === false || strpos($realFile, $realPath) !== 0) {
            return [];
        }
        
        if (!file_exists($file)) {
            return [];
        }
        
        $csvParser->load_data($data = file_get_contents($file));
        return $csvParser->parse($csvParser->find_delimiter());
    }
}
