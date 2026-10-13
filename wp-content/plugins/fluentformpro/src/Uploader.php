<?php

namespace FluentFormPro;

defined('ABSPATH') or die;

use FluentForm\App\Modules\Form\FormHandler;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper;

class Uploader extends FormHandler
{
    /**
     * Uploads files to the server.
     */
    public function upload()
    {
        $formId = intval($this->request->get('formId'));
        
        $attributes = $this->request->all();
        
        $sanitizeMap = [
            'formId' => 'intval',
        ];
        $attributes = fluentform_backend_sanitizer($attributes, $sanitizeMap);
        
        $this->formData = $attributes;
        
        if ($formId) {
            $this->setForm($formId);

            $this->validateNonce();

            if ($this->form) {
                // Get the HTTP files. It'll be an array with always one item.
                $files = $this->request->files();

                do_action_deprecated(
                    'fluentform_starting_file_upload',
                    [
                        $files,
                        $this->form
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/starting_file_upload',
                    'Use fluentform/starting_file_upload instead of fluentform_starting_file_upload.'
                );

                do_action('fluentform/starting_file_upload', $files, $this->form);

                // Get the form attribute name then.
                $arrayKeys = array_keys($files);
                $attribute = array_pop($arrayKeys);

                // SECURITY (PRO-28): only the last $_FILES key resolves the field whose rules are
                // validated, but uploadToTemp() wrote EVERY key — letting an attacker smuggle an
                // unvalidated file (wrong type, oversize) under an extra key while the last key
                // satisfies the form's rules. Restrict all downstream processing to the resolved
                // field's single key so no unvalidated file is ever written.
                if (isset($files[$attribute])) {
                    $files = [$attribute => $files[$attribute]];
                }

                // Get the specific form field by using the element type and it's attribute name.
                $field = FormFieldsParser::getField(
                    $this->form,
                    ['input_file', 'input_image', 'featured_image'],
                    $attribute,
                    ['rules', 'settings']
                );

                if ($field) {
                    // Extract the validation rules & messages for file upload element.
                    list($rules, $messages) = FormFieldsParser::getValidations(
                        $this->form,
                        $files,
                        $field
                    );
                    /**
                     * Delegate 'max_file_size', 'allowed_file_types' rules & messages to
                     * 'max', 'mimes' since the validation library doesn't recognise those
                     */
                    list($rules, $messages) = $this->delegateValidations($rules, $messages);

                    // SECURITY (PRO-29) + COMPAT: a freshly added File Upload field ships with an
                    // empty allowed_file_types, which produces NO mimes rule — so the upload accepts
                    // every type in get_allowed_mime_types(), including the dangerous SVG/HTML (stored
                    // XSS) types. Default to EVERY extension WordPress already allows MINUS the
                    // script/markup types, so forms that intentionally left the list empty keep
                    // accepting what they always did (video, audio, design files, archives, …) while
                    // the XSS-capable svg/html/xhtml types are excluded. Both lists are filterable.
                    if (isset($rules[$attribute]) && is_string($rules[$attribute]) && false === strpos($rules[$attribute], 'mimes:')) {
                        $defaultMimes = $this->defaultAllowedMimes($attribute);
                        if ($defaultMimes) {
                            $rules[$attribute] = $rules[$attribute] ? $rules[$attribute] . '|mimes:' . $defaultMimes : 'mimes:' . $defaultMimes;
                            if (!isset($messages[$attribute . '.mimes'])) {
                                $messages[$attribute . '.mimes'] = __('The uploaded file type is not allowed.', 'fluentformpro');
                            }
                        }
                    }

                    $delegateValidations = apply_filters_deprecated(
                        'fluentform_file_upload_validations',
                        [
                            [$rules, $messages],
                            $this->form
                        ],
                        FLUENTFORM_FRAMEWORK_UPGRADE,
                        'fluentform/file_upload_validations',
                        'Use fluentform/file_upload_validations instead of fluentform_file_upload_validations.'
                    );

                    // Fire an event so that one can hook into it to work with the rules & messages.
                    $validations = $this->app->applyFilters(
                        'fluentform/file_upload_validations',
                        $delegateValidations,
                        $this->form
                    );
                    
                    $validator = fluentValidator(
                        $files,
                        $validations[0],
                        $validations[1]
                    );
                    
                    if ($validator->validate()->fails()) {
                        $errors =  $validator->errors();
                        $errors = apply_filters_deprecated(
                            'fluentform_file_upload_validation_error',
                            [
                                $errors,
                                $this->form
                            ],
                            FLUENTFORM_FRAMEWORK_UPGRADE,
                            'fluentform/file_upload_validation_error',
                            'Use fluentform/file_upload_validation_error instead of fluentform_file_upload_validation_error.'
                        );
                        // Fire an event so that one can hook into it to work with the errors.
                        $errors = $this->app->applyFilters(
                            'fluentform/file_upload_validation_error',
                            $errors,
                            $this->form
                        );
                        
                        wp_send_json([
                            'errors' => $errors
                        ], 422);
                    }
    
                    // let's upload to a temp location
                    $field = current($field);
                    $this->validateImageCropRequirements($files, $field, $attribute);
    
                    //add default upload location for old inputs
                    if (!$uploadLocation = ArrayHelper::get($field, 'settings.upload_file_location')) {
                        $uploadLocation = 'default';
                    }

                    $uploadedFiles = [];
                    if (!empty($uploadLocation)) {
                        $this->overrideUploadDir();
                        $uploadedFiles = $this->uploadToTemp($files, $field);
                    }
    
                    wp_send_json_success([
                        'files' => $uploadedFiles
                    ], 200);
                }
            }
        }
    }
    
    /**
     * The comma-separated mimes list applied when a File Upload field leaves its
     * allowed_file_types empty. Split out of upload(), which ends in
     * wp_send_json() and so cannot be called from a test.
     *
     * @param string $attribute
     * @return string
     */
    protected function defaultAllowedMimes($attribute)
    {
        $blockedExtensions = apply_filters(
            'fluentform/upload_blocked_mime_extensions',
            ['svg', 'svgz', 'html', 'htm', 'xhtml', 'xht', 'shtml', 'phtml'],
            $this->form,
            $attribute
        );

        // Third parties supply this list, so an uppercase entry must still block.
        $blockedExtensions = array_map('strtolower', (array) $blockedExtensions);

        $allowedExtensions = [];
        foreach (array_keys(get_allowed_mime_types()) as $extGroup) {
            foreach (explode('|', $extGroup) as $ext) {
                if ('' !== $ext && !in_array(strtolower($ext), $blockedExtensions, true)) {
                    $allowedExtensions[] = $ext;
                }
            }
        }

        return apply_filters(
            'fluentform/default_upload_allowed_mimes',
            implode(',', array_unique($allowedExtensions)),
            $this->form,
            $attribute
        );
    }

    /**
     * Uploads files to its target locations
     * @return void
     */
    public function processFiles()
    {
        $fileTypes = ['input_file', 'input_image', 'featured_image'];
        
        foreach ($fileTypes as $fileType) {
            add_filter('fluentform/input_data_' . $fileType, function ($files, $field, $formData, $form) {
                if (!$this->form) {
                    $this->setForm($form->id);
                }
                $uploadLocation = $this->getUploadLocation($field);
                $files = is_array($files) ? $files : [$files];

                $isSkip = apply_filters('fluentform/skip_cloud_file_decryption', false);
         
                if (!$isSkip) {
                    $files = $this->maybeDecrypt($files);
                }

                $this->validateStoredImageCropRequirements($files, $field);
               
                do_action_deprecated(
                    'fluentform_starting_file_processing',
                    [
                        $files,
                        $uploadLocation,
                        $formData,
                        $form
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/starting_file_processing',
                    'Use fluentform/starting_file_processing instead of fluentform_starting_file_processing.'
                );
                do_action('fluentform/starting_file_processing', $files, $uploadLocation, $formData, $form);
                $this->initUploads($files, $uploadLocation, $formData);
                $formattedFiles = [];
                foreach ($files as $file) {
                    $formattedFiles[] = $this->getProcessedUrl($file, $uploadLocation);
                }
                return $formattedFiles;
            }, 10, 4);
        }
    }

    /**
     * Validate uploaded image crop requirements before temp upload.
     *
     * @param array $files
     * @param array $field
     * @param string $attribute
     * @return void
     */
    protected function validateImageCropRequirements($files, $field, $attribute)
    {
        $cropSettings = $this->getImageCropRequirements($field);

        if (!$cropSettings['enabled']) {
            return;
        }

        foreach ($files as $file) {
            $fileArray = $file->toArray();
            $this->assertImageMatchesCropRequirements(
                ArrayHelper::get($fileArray, 'tmp_name'),
                $cropSettings,
                $attribute
            );
        }
    }

    /**
     * Validate stored temp images before final processing.
     *
     * @param array $files
     * @param array $field
     * @return void
     */
    protected function validateStoredImageCropRequirements($files, $field)
    {
        $cropSettings = $this->getImageCropRequirements($field);
        $attribute = ArrayHelper::get($field, 'attributes.name', ArrayHelper::get($field, 'raw.attributes.name'));

        if (!$cropSettings['enabled']) {
            return;
        }

        $basePath = wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR . '/temp/';

        foreach ($files as $file) {
            $tempPath = $basePath . basename($file);
            $this->assertImageMatchesCropRequirements($tempPath, $cropSettings, $attribute);
        }
    }

    /**
     * Parse configured crop requirements from field settings.
     *
     * @param array $field
     * @return array
     */
    protected function getImageCropRequirements($field)
    {
        $settings = ArrayHelper::get($field, 'settings', []);
        if (!$settings) {
            $settings = ArrayHelper::get($field, 'raw.settings', []);
        }

        return [
            'enabled'      => ArrayHelper::get($settings, 'enable_crop') === 'yes',
            'mode'         => ArrayHelper::get(
                $settings,
                'crop_mode',
                ArrayHelper::get($settings, 'enforce_image_dimensions') === 'yes' ? 'dimensions' : 'ratio'
            ),
            'crop_ratio'   => ArrayHelper::get($settings, 'crop_ratio', 'free'),
            'enforce_size' => ArrayHelper::get(
                $settings,
                'crop_mode',
                ArrayHelper::get($settings, 'enforce_image_dimensions') === 'yes' ? 'dimensions' : 'ratio'
            ) === 'dimensions',
            'width'        => (int) ArrayHelper::get($settings, 'crop_width'),
            'height'       => (int) ArrayHelper::get($settings, 'crop_height')
        ];
    }

    /**
     * Validate an image file against crop requirements.
     *
     * @param string $filePath
     * @param array $cropSettings
     * @param string|null $attribute
     * @return void
     */
    protected function assertImageMatchesCropRequirements($filePath, $cropSettings, $attribute = null)
    {
        if (!$filePath || !file_exists($filePath)) {
            return;
        }

        $imageSize = @getimagesize($filePath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (!$imageSize || empty($imageSize[0]) || empty($imageSize[1])) {
            return;
        }

        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];

        if (
            $cropSettings['enforce_size'] &&
            $cropSettings['width'] > 0 &&
            $cropSettings['height'] > 0
        ) {
            if ($width !== $cropSettings['width'] || $height !== $cropSettings['height']) {
                $this->throwImageCropValidationError(
                    sprintf(
                        __('Cropped image must be %1$d x %2$d pixels.', 'fluentformpro'),
                        $cropSettings['width'],
                        $cropSettings['height']
                    ),
                    $attribute
                );
            }

            return;
        }

        if ($cropSettings['crop_ratio'] && $cropSettings['crop_ratio'] !== 'free') {
            $ratioParts = array_map('intval', explode(':', $cropSettings['crop_ratio']));
            if (count($ratioParts) === 2 && $ratioParts[0] > 0 && $ratioParts[1] > 0) {
                $expectedRatio = $ratioParts[0] / $ratioParts[1];
                $actualRatio = $width / $height;

                // Cropper canvas output can round by a few pixels even when the selected crop box is correct.
                if (abs($actualRatio - $expectedRatio) > 0.02) {
                    $this->throwImageCropValidationError(
                        sprintf(
                            __('Cropped image must match the %s crop ratio.', 'fluentformpro'),
                            $cropSettings['crop_ratio']
                        ),
                        $attribute
                    );
                }
            }
        }
    }

    /**
     * Send a validation error response for image crop rules.
     *
     * @param string $message
     * @param string|null $attribute
     * @return void
     */
    protected function throwImageCropValidationError($message, $attribute = null)
    {
        $errorKey = $attribute ?: 'error';

        wp_send_json([
            'errors' => [
                $errorKey => [$message]
            ]
        ], 422);
    }
    
    /**
     * Register filters for custom upload dir
     */
    public function overrideUploadDir()
    {
        add_filter('wp_handle_upload_prefilter', function ($file) {
            add_filter('upload_dir', [$this, 'setCustomUploadDir']);

            add_filter('wp_handle_upload', function ($fileinfo) {
                remove_filter('upload_dir', [$this, 'setCustomUploadDir']);
                $fileinfo['file'] = basename($fileinfo['file']);
                return $fileinfo;
            });
            
            return $this->renameFileName($file);
        });
    }
    
    /**
     * Set plugin's custom upload dir
     * @param array $param
     * @return array $param
     */
    public function setCustomUploadDir($param)
    {
        $param['url'] = $param['baseurl'] . FLUENTFORM_UPLOAD_DIR . '/temp';
        $param['path'] = $param['basedir'] . FLUENTFORM_UPLOAD_DIR . '/temp';
    
        $param = apply_filters_deprecated(
            'fluentform_file_upload_params',
            [
                $param,
                $this->formData,
                $this->form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/file_upload_params',
            'Use fluentform/file_upload_params instead of fluentform_file_upload_params.'
        );
        
        $param = apply_filters('fluentform/file_upload_params', $param, $this->formData, $this->form);

        // SECURITY (PRO-30): secure both the parent /fluentform dir and the /temp dir. The upload
        // target is /fluentform/temp; guarding only the leaf left the parent listing unguarded.
        $this->secureDirectory($param['basedir'] . FLUENTFORM_UPLOAD_DIR);
        $this->secureDirectory($param['path']);

        return $param;
    }
    
    /**
     * Rename the uploaded file name before saving
     * @param array $file
     * @return array $file
     */
    public function renameFileName($file)
    {
        $originalFileArray = $file;
        $prefix = 'ff-' . md5(uniqid(wp_rand())) . '-ff-';
        
        $file['name'] = $prefix . $file['name'];
    
        $file = apply_filters_deprecated(
            'fluentform_uploaded_file_name',
            [
                $file,
                $originalFileArray,
                $this->formData,
                $this->form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/uploaded_file_name',
            'Use fluentform/uploaded_file_name instead of fluentform_uploaded_file_name.'
        );
        
        return apply_filters('fluentform/uploaded_file_name', $file, $originalFileArray, $this->formData, $this->form);
    }
    
    /**
     * Prepare the validation rules & messages specific to
     * file type inputs when actual form is submitted.
     *
     * @param $validations
     * @param $form \stdClass
     * @return array
     */
    public function prepareValidations($validations, $form)
    {
        $element = FormFieldsParser::getElement($form, ['input_file', 'input_image']);
        
        if (count($element)) {
            // Delegate the `max_file_count` validation to `max`
            $validations = $this->delegateValidations(
                $validations[0],
                $validations[1],
                ['max_file_count'],
                ['max']
            );
        }
        
        return $validations;
    }
    
    /**
     * Process uploads from temp directory location to its final location
     *
     * @param $formData
     * @param $form
     * @return void
     */
    public function initUploads($files, $uploadLocation, $formData)
    {
        if (empty($files)) {
            return;
        }
        if ($uploadLocation == 'wp_media') {
            $this->copyToWpMedia($files);
        } elseif ($uploadLocation == 'default') {
            $this->copyToDefault($files);
        } elseif ($uploadLocation == 'cloud_storage') {
            //for file upload addons
            do_action('fluentform/upload_to_cloud', $files, $uploadLocation, $formData, $this->form);
        }
    }
    
    /**
     * Copy files to default location
     *
     * @param array $files
     */
    protected function copyToDefault($files)
    {
        $uploadedFiles = [];
        $wpUploadDir = wp_upload_dir();
        
        foreach ($files as $file) {
            $fileInfo = pathinfo($file);
            $filename = $fileInfo['basename'];
    
            $filePath = $wpUploadDir['basedir'] . FLUENTFORM_UPLOAD_DIR;
            $tempFilePath = $filePath . '/temp/' . $filename;
            $filePath = apply_filters_deprecated(
                'fluentform_default_upload_path',
                [
                    $filePath,
                    $this->form
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/default_upload_path',
                'Use fluentform/default_upload_path instead of fluentform_default_upload_path.'
            );
            $destinationFilePath = apply_filters('fluentform/default_upload_path', $filePath, $this->form);
            $this->secureDirectory($filePath);
    
            $destinationFilePath = trailingslashit($destinationFilePath) . $filename;
            
            self::copyFile($tempFilePath, $destinationFilePath);
        }
        return $uploadedFiles;
    }
    
    
    /**
     * Copy files to WordPress Media
     *
     * @param $files
     * @return void
     */
    public function copyToWpMedia($files)
    {
        $uploadedFiles = [];
        $wpUploadDir = wp_upload_dir();
    
        foreach ($files as $file) {
            $fileInfo = pathinfo($file);
            $filename = $fileInfo['basename'];
            $tempFilePath = $wpUploadDir['basedir'] . FLUENTFORM_UPLOAD_DIR . '/temp/' . $filename;
            $destinationFilePath = $wpUploadDir['path'] . '/' . $filename;
        
            $mimeType = wp_check_filetype($tempFilePath);
            //Copy this file into the wp uploads dir
            $move = self::copyFile($tempFilePath, $destinationFilePath);
            if (!$move) {
                continue;
            }
            $destinationFileFileUrl = $wpUploadDir['url'] . '/' . $filename;
            $uploadId = wp_insert_attachment(
                [
                    'guid' => $destinationFileFileUrl,
                    'post_mime_type' => $mimeType['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
                    'post_content' => '',
                    'post_status' => 'inherit',
                ],
                $destinationFilePath
            );
        
            // wp_generate_attachment_metadata() needs this file.
            require_once ABSPATH . 'wp-admin/includes/image.php';
            if (!is_wp_error($uploadId)) {
                wp_update_attachment_metadata(
                    $uploadId,
                    wp_generate_attachment_metadata($uploadId, $destinationFilePath)
                );
            }
        }
    }
    
    /**
     * Upload files to temp directory
     *
     * @param array $files
     * @param array $field
     * @return array
     */
    private function uploadToTemp($files, $field)
    {
        $uploadedFiles = [];
        foreach ($files as $file) {
            /**
             * @var $file \FluentForm\Framework\Request\File|\FluentForm\Framework\Http\Request\File
             */
            $filesArray = $file->toArray();
    
            $args = [
                'test_form' => false
            ];
            $args = apply_filters_deprecated(
                'fluentform_uploader_args',
                [
                    $args,
                    $filesArray,
                    $this->form
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/uploader_args',
                'Use fluentform/uploader_args instead of fluentform_uploader_args.'
            );
            
            $uploaderArgs = apply_filters('fluentform/uploader_args', $args, $filesArray, $this->form);
            
            $uploadFile = wp_handle_upload(
                $filesArray,
                $uploaderArgs
            );

            if ($error = ArrayHelper::get($uploadFile, 'error')) {
                wp_send_json([
                    'errors' => [
                        'error' => $error
                    ]
                ], 422);
            }

            $file = ArrayHelper::get($uploadFile, 'file');

            if ($file) {
                $uploadFile['file'] = \FluentForm\App\Helpers\Protector::encrypt($file);
                $uploadFile['url'] = str_replace($file, $uploadFile['file'], $uploadFile['url']);

                $uploadFile = apply_filters_deprecated(
                    'fluent_file_uploaded',
                    [
                        $uploadFile,
                        $this->formData,
                        $this->form
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/file_uploaded',
                    'Use fluentform/file_uploaded instead of fluent_file_uploaded.'
                );

                $uploadedFiles[] = apply_filters('fluentform/file_uploaded', $uploadFile, $this->formData, $this->form);
            }
        }
        return $uploadedFiles;
    }
    
    private static function copyFile($fromPath = null, $toPath = null)
    {
        $status = false;
        
        if (!empty($fromPath) && is_file($fromPath)) {
            //if destination dir exists if not make it
            if (!file_exists(dirname($toPath))) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct mkdir needed for upload directory creation
                mkdir(dirname($toPath));
            }
            if (file_exists(dirname($toPath))) {
                //Move file into dir
                if (copy($fromPath, $toPath)) {
                    if (file_exists($toPath)) {
                        $status = true;
                    }
                }
            }
        }
        
        return $status;
    }
    
    
    /**
     * Get File url after processing uploads
     *
     * @param $file
     * @param $uploadLocations
     * @return string|void
     */
    public function getProcessedUrl($file, $location)
    {
        $wpUploadDir = wp_upload_dir();
        $fileInfo = pathinfo($file);
        $filename = $fileInfo['basename'];
        $filePath = $wpUploadDir['baseurl'] . FLUENTFORM_UPLOAD_DIR;
        if ($location == 'wp_media') {
            $fileUrl = $wpUploadDir['url'] . '/' . $filename;
        } elseif ($location == 'default') {
            $filePath = apply_filters_deprecated(
                'fluentform_default_file_upload_url',
                [
                    $filePath,
                    $this->form
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/default_file_upload_url',
                'Use fluentform/default_file_upload_url instead of fluentform_default_file_upload_url.'
            );
            $fileUrl = apply_filters('fluentform/default_file_upload_url', $filePath, $this->form);
            $fileUrl = trailingslashit($fileUrl) .$filename;
        } else if ($location == 'cloud_storage') {
            return $file;
        } else {
            //if not location found store temp file url for other file uploader
            $fileUrl = $filePath . '/temp/' . $filename;
        }
        return $fileUrl;
    }
    
    /**
     * Cleanup temp Directory
     *
     * @return void
     */
    public static function removeOldTempFiles()
    {
        $maxFileAge = apply_filters('fluentform/temp_file_delete_time', 2 * 3600);
        $wpUploadDir = wp_upload_dir();
        $tempDir = $wpUploadDir['basedir'] . FLUENTFORM_UPLOAD_DIR . '/temp/';

        // Remove old temp files
        if (is_dir($tempDir) && ($dir = opendir($tempDir))) {
            $deletedCount = 0;
            $maxDeletions = apply_filters('fluentform/temp_file_cleanup_batch_size', 50);

            while (($file = readdir($dir)) !== false) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $tempFilePath = $tempDir . $file;
                if (is_file($tempFilePath) && (filemtime($tempFilePath) < time() - $maxFileAge)) {
                    wp_delete_file($tempFilePath);
                    if (!file_exists($tempFilePath)) {
                        $deletedCount++;
                    }

                    // Limit batch size to prevent timeouts
                    if ($deletedCount >= $maxDeletions) {
                        break;
                    }
                }
            }
            closedir($dir);

            do_action('fluentform/temp_files_cleaned', $tempDir, $deletedCount, $maxFileAge, $maxDeletions);
        }
    }
    
    /**
     * Adds htaccess file to directory
     * @param $path
     * @return void
     */
    private function secureDirectory($path)
    {
        // SECURITY (PRO-30): create the directory tree recursively. The previous non-recursive
        // mkdir() failed with ENOENT because the parent (/fluentform) did not exist yet on a clean
        // install, and its unchecked failure meant the guard files were never written — leaving the
        // web-reachable upload dir (and /temp) with no .htaccess/index.php at all.
        if (!is_dir($path)) {
            wp_mkdir_p($path);
        }

        if (!is_dir($path)) {
            return;
        }

        // Write the guard files whenever they are missing, so a pre-existing unguarded directory
        // is also secured (not only a freshly created one).
        if (!file_exists($path . '/.htaccess')) {
            file_put_contents(
                $path . '/.htaccess',
                file_get_contents(__DIR__ . '/Stubs/htaccess.stub')
            );
        }

        if (!file_exists($path . '/index.php')) {
            file_put_contents(
                $path . '/index.php',
                file_get_contents(__DIR__ . '/Stubs/index.stub')
            );
        }

        $this->writeIisGuard($path);
    }

    /**
     * Mirror the .htaccess guard for IIS, where the .htaccess is inert.
     *
     * COMPAT (PRO-30): this deliberately does NOT deny all access. secureDirectory() also runs on
     * the parent /fluentform dir, which is exactly where 'default' location uploads are served
     * from (getProcessedUrl), so a blanket <deny users="*" /> would break every entry file link
     * and front-end image on IIS. Request Filtering matches what the .htaccess actually does —
     * no script execution, static downloads still allowed — and is available without the URL
     * Authorization role that <authorization> requires (missing role => IIS 500 for the tree).
     *
     * @param string $path
     * @return void
     */
    private function writeIisGuard($path)
    {
        $file = $path . '/web.config';

        if (file_exists($file)) {
            $existing = (string) file_get_contents($file);

            // Only replace the deny-all guard shipped by an earlier build of this fix. Anything
            // else in this file belongs to the site owner and is left untouched.
            if (false === strpos($existing, '<deny users="*"')) {
                return;
            }
        }

        file_put_contents(
            $file,
            file_get_contents(__DIR__ . '/Stubs/webconfig.stub')
        );
    }
    
    
    /**
     * Maybe Decrypt file names
     *
     * @param $files
     * @return array
     */
    private function maybeDecrypt($files)
    {
        $decrypted = [];
        foreach ($files as $file) {
            $decrypted[] = \FluentForm\App\Helpers\Helper::maybeDecryptUrl($file);
        }
        return $decrypted;
    }

    /**
     * Resolve an encrypted Fluent Forms file token to an absolute path.
     *
     * @param string $fileToken
     * @return string|null
     */
    private function resolveFilePathFromToken($fileToken)
    {
        if (!is_string($fileToken) || $fileToken === '') {
            return null;
        }

        $filePath = \FluentForm\App\Helpers\Protector::decrypt($fileToken);

        if (!$filePath || !is_string($filePath)) {
            return null;
        }

        return wp_normalize_path($filePath);
    }

    /**
     * Verify that an attachment delete request is bound to the exact
     * Fluent Forms-owned file represented by the encrypted token.
     *
     * @param int    $attachmentId
     * @param string $fileToken
     * @return bool
     */
    private function canDeleteAttachment($attachmentId, $fileToken)
    {
        if (!$attachmentId || !$fileToken) {
            return false;
        }

        $resolvedPath = $this->resolveFilePathFromToken($fileToken);
        if (!$resolvedPath) {
            return false;
        }

        $attachedFile = get_attached_file($attachmentId);
        if (!$attachedFile || !is_string($attachedFile)) {
            return false;
        }

        return hash_equals(
            wp_normalize_path($attachedFile),
            $resolvedPath
        );
    }
    
    public function deleteFile()
    {
        $nonce = $this->request->get('_fluentform_file_delete_nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'fluentform_file_delete')) {
            wp_send_json_error(['message' => __('Security check failed', 'fluentformpro')], 403);
        }

        if (!empty($file_name = $this->request->get('path'))) {
            $attachmentId = intval($this->request->get('attachment_id'));

            if (
                $attachmentId &&
                $this->canDeleteAttachment($attachmentId, $file_name) &&
                wp_delete_attachment($attachmentId)
            ) {
                wp_die();
            } else {
                $file_name = $this->resolveFilePathFromToken($file_name);

                if (!$file_name) {
                    wp_send_json_error(['message' => __('Invalid file token', 'fluentformpro')], 403);
                }

                $file_name = basename($file_name);
                wp_die(@unlink(wp_upload_dir()['basedir'] . FLUENTFORM_UPLOAD_DIR . '/temp/' . $file_name)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.unlink_unlink -- unlink returns boolean for wp_die; wp_delete_file cannot be used inside wp_die
            }
        }
    }
    
    /**
     * @param $upload_file_location
     * @return mixed
     */
    public function getUploadLocation($field)
    {
        if (!$locationType = ArrayHelper::get($field, 'raw.settings.file_location_type')) {
            $locationType = 'follow_global_settings';
        }
        if ($locationType == 'follow_global_settings') {
            $settings = get_option('_fluentform_global_form_settings', false);
            $location = ArrayHelper::get($settings, 'misc.file_upload_locations');
        } else {
            $location = ArrayHelper::get($field, 'raw.settings.upload_file_location');
        }
    
        if (empty($location)) {
            $location = 'default';
        }

        $location = apply_filters('fluentform/change_file_upload_location', $location);
        return $location;
    }
}
