<?php

namespace FluentFormPro\Components;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\Components\BaseComponent;
use FluentForm\Framework\Helpers\ArrayHelper;

class Uploader extends BaseComponent
{
	/**
	 * Compile and echo the html element
	 * @param  array $data [element data]
	 * @param  stdClass $form [Form Object]
	 * @return viod
	 */
	public function compile($data, $form)
	{
        $elementName = $data['element'];
        
        $data = apply_filters_deprecated(
            'fluentform_rendering_field_data_' . $elementName,
            [
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_data_' . $elementName,
            'Use fluentform/rendering_field_data_' . $elementName . ' instead of fluentform_rendering_field_data_' . $elementName
        );

        $data = apply_filters('fluentform/rendering_field_data_' . $elementName, $data, $form);

        $data['attributes']['class'] = @trim('ff-el-form-control '. $data['attributes']['class'].' ff-screen-reader-element');
        $data['attributes']['id'] = $this->makeElementId($data, $form).'_'.Helper::$formInstance;

        $maxFileCount = (int) ArrayHelper::get($data, 'settings.validation_rules.max_file_count.value', 0);
        $data['attributes']['multiple'] = $maxFileCount !== 1;

        if($tabIndex = \FluentForm\App\Helpers\Helper::getNextTabIndex()) {
            $data['attributes']['tabindex'] = $tabIndex;
        }
        
        $btnText = ArrayHelper::get($data, 'settings.btn_text');
        if(!$btnText) {
            $btnText = __('Choose File', 'fluentformpro');
        }

        // Apply file upload message filters
        $btnText = apply_filters('fluentform/file_upload_button_text', $btnText, $form);

        // Apply file upload messages filter for all upload-related text
        $uploadMessages = apply_filters('fluentform/file_upload_messages', [
            'drag_drop_text' => __('Drag & Drop files here or click to browse', 'fluentformpro'),
            'upload_text' => $btnText,
            'max_file_error' => __('Maximum file size exceeded', 'fluentformpro'),
            'file_type_error' => __('Invalid file type', 'fluentformpro'),
            'file_size_error' => __('File size too large', 'fluentformpro'),
            'upload_failed_text' => __('Sorry! The upload failed for some unknown reason.', 'fluentformpro'),
            'upload_error_text' => __('Something is wrong when uploading the file! Please try again', 'fluentformpro')
        ], $form);

        // Pass translated messages to frontend JavaScript
        wp_localize_script('fluentform-uploader', 'fluentform_upload_messages_' . $form->id, $uploadMessages);
        

        $ariaRequired = 'false';
        if (ArrayHelper::get($data, 'settings.validation_rules.required.value')) {
            $ariaRequired = 'true';
        }
        
        if (ArrayHelper::get($data, 'settings.upload_bttn_ui') == 'dropzone') {
            $data['settings']['container_class'] .= ' ff-dropzone';
        }

        $elMarkup = "<label for='" . esc_attr($data['attributes']['id']) . "' class='ff_file_upload_holder'><span class='ff_upload_btn ff-btn' tabindex='0'>" . fluentform_sanitize_html($btnText) . "</span> <input %s aria-invalid='false' aria-required=" . esc_attr($ariaRequired) . "></label>";

        $elMarkup = sprintf($elMarkup, $this->buildAttributes($data['attributes'], $form));
		$html = $this->buildElementMarkup($elMarkup, $data, $form);
        
        $html = apply_filters_deprecated(
            'fluentform_rendering_field_html_' . $elementName,
            [
                $html,
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_html_' . $elementName,
            'Use fluentform/rendering_field_html_' . $elementName . ' instead of fluentform_rendering_field_html_' . $elementName
        );

        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form field HTML rendering
        $this->enqueueProScripts();
    }

	/**
     * Enqueue required scripts
     * @return void
     */
    public function enqueueProScripts()
    {
        wp_enqueue_style('lity');
        wp_enqueue_style('fluentform-cropperjs-style');
        wp_enqueue_script('lity');
        wp_enqueue_script('fluentform-uploader-jquery-ui-widget');
        wp_enqueue_script('fluentform-uploader-iframe-transport');
        wp_enqueue_script('fluentform-uploader');
        wp_enqueue_script('fluentform-cropperjs');
    }
}
