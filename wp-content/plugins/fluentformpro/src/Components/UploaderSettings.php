<?php

namespace FluentFormPro\Components;

use FluentForm\App\Services\Parser\Form;
use FluentForm\Framework\Helpers\ArrayHelper;

if (!defined('ABSPATH')) {
    exit;
}

class UploaderSettings
{
    public function boot()
    {
        add_filter('fluentform/file_upload_settings_for_js', [$this, 'getFileUploadSettingsForJs'], 10, 2);
        add_filter('fluentform/global_form_vars', [$this, 'addCropGlobalFormVars']);
        add_action('fluentform/conversational_enqueue_assets', [$this, 'enqueueConversationalCropAssets'], 10, 2);
    }

    /**
     * Build Pro upload field settings used by the public uploader.
     *
     * @param array    $settings
     * @param \stdClass $form
     * @return array
     */
    public function getFileUploadSettingsForJs($settings, $form)
    {
        $inputs = (new Form($form))
            ->getInputs(['element', 'attributes', 'settings']);

        foreach ($inputs as $name => $input) {
            if (!in_array(ArrayHelper::get($input, 'element'), ['input_image', 'featured_image'], true)) {
                continue;
            }

            $fieldSettings = ArrayHelper::get($input, 'settings', []);
            $mode = ArrayHelper::get(
                $fieldSettings,
                'crop_mode',
                ArrayHelper::get($fieldSettings, 'enforce_image_dimensions') === 'yes' ? 'dimensions' : 'ratio'
            );

            $settings[$name] = [
                'enabled'      => ArrayHelper::get($fieldSettings, 'enable_crop') === 'yes',
                'mode'         => $mode,
                'crop_ratio'   => ArrayHelper::get($fieldSettings, 'crop_ratio', 'free'),
                'enforce_size' => $mode === 'dimensions',
                'width'        => (int) ArrayHelper::get($fieldSettings, 'crop_width'),
                'height'       => (int) ArrayHelper::get($fieldSettings, 'crop_height'),
                'button_ui'    => ArrayHelper::get($fieldSettings, 'upload_bttn_ui', ''),
            ];
        }

        return $settings;
    }

    public function addCropGlobalFormVars($vars)
    {
	    $vars['crop_image_title'] = __('Crop Image', 'fluentformpro');
	    $vars['crop_confirm_txt'] = __('Crop & Upload', 'fluentformpro');
	    $vars['crop_cancel_txt'] = __('Cancel', 'fluentformpro');
	    $vars['crop_close_txt'] = __('Close', 'fluentformpro');
	    $vars['crop_reset_txt'] = __('Reset', 'fluentformpro');
	    $vars['crop_ratio_txt'] = __('Crop ratio', 'fluentformpro');
	    $vars['crop_ratio_free_txt'] = __('Free', 'fluentformpro');
	    $vars['crop_invalid_dimensions_txt'] = __('The selected image is smaller than the required crop size.',
		    'fluentformpro');
	    $vars['crop_exact_dimensions_txt'] = __('The cropped image must match the required width and height.',
		    'fluentformpro');
	    $vars['crop_dimension_instruction_txt'] = __('Crop the image to exactly %1$s px x %2$s px.', 'fluentformpro');
	    $vars['crop_invalid_image_txt'] = __('Unable to process the selected image.', 'fluentformpro');
	    $vars['crop_loading_txt'] = __('Preparing image...', 'fluentformpro');

        return $vars;
    }

    public function enqueueConversationalCropAssets($form, $settings)
    {
        if (!$this->hasEnabledCropSettings($settings)) {
            return;
        }

        $this->registerConversationalCropAssets();

        wp_enqueue_style('lity');
        wp_enqueue_style('fluentform-cropperjs-style');
        wp_enqueue_script('lity');
        wp_enqueue_script('fluentform-cropperjs');
    }

    protected function registerConversationalCropAssets()
    {
        if (!wp_style_is('lity', 'registered')) {
            wp_register_style('lity', FLUENTFORMPRO_DIR_URL . 'public/libs/lity/lity.min.css', [], '2.3.1');
        }

        if (!wp_script_is('lity', 'registered')) {
            wp_register_script('lity', FLUENTFORMPRO_DIR_URL . 'public/libs/lity/lity.min.js', ['jquery'], '2.3.1', true);
        }

        if (!wp_style_is('fluentform-cropperjs-style', 'registered')) {
            wp_register_style(
                'fluentform-cropperjs-style',
                FLUENTFORMPRO_DIR_URL . 'public/libs/cropperjs/cropper.min.css',
                [],
                '1.6.2'
            );
        }

        if (!wp_script_is('fluentform-cropperjs', 'registered')) {
            wp_register_script(
                'fluentform-cropperjs',
                FLUENTFORMPRO_DIR_URL . 'public/libs/cropperjs/cropper.min.js',
                [],
                '1.6.2',
                true
            );
        }
    }

    protected function hasEnabledCropSettings($settings)
    {
        foreach ((array) $settings as $fieldSettings) {
            if (ArrayHelper::get($fieldSettings, 'enabled')) {
                return true;
            }
        }

        return false;
    }
}
