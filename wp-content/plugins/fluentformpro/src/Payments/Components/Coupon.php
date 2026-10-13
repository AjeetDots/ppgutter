<?php

namespace FluentFormPro\Payments\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\BaseFieldManager;
use FluentForm\App\Services\FormBuilder\Components\Text;
use FluentForm\Framework\Helpers\ArrayHelper;

class Coupon extends BaseFieldManager
{
    public function __construct(
        $key = 'payment_coupon',
        $title = 'Coupon',
        $tags = [ 'coupon', 'discount'],
        $position = 'payments'
    )
    {
        parent::__construct(
            $key,
            $title,
            $tags,
            $position
        );

        add_filter('fluentform/input_data_payment_coupon', array($this, 'addCouponsToSubmission'), 10, 3);

        add_filter('fluentform/disabled_components', function ($disables) {
            $isEnabled = get_option('fluentform_coupon_status') == 'yes';
            if(!$isEnabled) {
                $couponUrl = admin_url('admin.php?page=fluent_forms_settings&component=payment_settings#coupon');
                $disables['payment_coupon'] = [
                    'disabled' => true,
                    'disable_html' => '<div class="text-align-center"><h4 class="mb-5">Please Activate Coupon Module First</h4><a target="_blank" class="el-button el-button--primary" href="'.$couponUrl.'">'. __('Activate Coupon Module', 'fluentformpro') . '</a><p class="mt-3">' . __('After creating your first coupon please reload this page.', 'fluentformpro') . '</p></div>'
                ];
            }

            return $disables;
        });
    }

    function getComponent()
    {
        return array(
            'index' => 6,
            'element' => $this->key,
            'attributes' => array(
                'type' => 'text',
                'name' => 'payment-coupon',
                'value' => '',
                'id' => '',
                'class' => '',
                'placeholder' => '',
                'data-is_coupon' => 'yes'
            ),
            'settings' => array(
                'container_class' => '',
                'is_payment_field' => 'yes',
                'label' => __('Coupon', 'fluentformpro'),
                'admin_field_label' => '',
                'label_placement' => '',
                'suffix_label' => __('Apply Coupon', 'fluentformpro'),
                'help_message' => '',
                'validation_rules' => array(
                    'required' => array(
                        'value'          => false,
                        'global'         => true,
                        'message'        => Helper::getGlobalDefaultMessage('required'),
                        'global_message' => Helper::getGlobalDefaultMessage('required'),
                    )
                ),
                'conditional_logics' => array()
            ),
            'editor_options' => array(
                'title' => __('Coupon', 'fluentformpro'),
                'icon_class' => 'el-icon-postcard',
                'template' => 'inputText'
            ),
        );
    }

    public function getGeneralEditorElements()
    {
        return [
            'label',
            'label_placement',
            'admin_field_label',
            'placeholder',
            'suffix_label'
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'container_class',
            'class',
            'help_message',
            'name',
            'conditional_logics',
            'calculation_settings'
        ];
    }

    public function render($data, $form)
    {
        $data['attributes']['class'] .= ' ff_coupon_item';
        $data['settings']['container_class'] .= ' ff_coupon_wrapper';
        return (new Text())->compile($data, $form);
    }

    public function addCouponsToSubmission($value, $field, $submissionData)
    {
        if(isset($submissionData['__ff_all_applied_coupons'])) {
            $allCoupons = $submissionData['__ff_all_applied_coupons'];
            $allCoupons = \json_decode($allCoupons, true);
            if($allCoupons) {
                $value = implode(', ', $allCoupons);
            }
        }
        return $value;
    }

}