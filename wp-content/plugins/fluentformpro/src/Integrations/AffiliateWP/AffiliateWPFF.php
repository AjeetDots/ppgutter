<?php

namespace FluentFormPro\Integrations\AffiliateWP;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Modules\Form\FormDataParser;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentForm\App\Helpers\Helper;
use Affiliate_WP_Base;
use FluentFormPro\Payments\Orders\OrderData;

class AffiliateWPFF extends Affiliate_WP_Base
{
    public $context = 'fluentforms';

    public function init()
    {
        add_filter('affwp_referral_reference_column', [$this, 'referenceLink'], 10, 2);
    }

    public function boot()
    {
        $enabled = $this->isModuleEnabled();

        add_filter('fluentform/global_addons', function ($addOns) use ($enabled) {
            $addOns['affiliateWp'] = [
                'title'       => __('AffiliateWP', 'fluentformpro'),
                'description' => __('Generate AffiliateWP referrals automatically when a customer is referred to your site via an affiliate link.', 'fluentformpro'),
                'logo'        => fluentFormMix('img/integrations/affiliatewp.png'),
                'enabled'     => ($enabled) ? 'yes' : 'no',
                'config_url'  => '',
                'category'    => 'crm'
            ];
            return $addOns;
        }, 99);

        if (!$enabled) {
            return;
        }

        $this->initFormSettings();
        /* Regular Payments */
        add_action('fluentform/submission_inserted', [$this, 'addPendingReferral'], 99, 3);
        add_action('fluentform/process_payment', [$this, 'onProcesPayment'], 99, 6);
        add_action('fluentform/after_transaction_status_change', [$this, 'markReferralComplete'], 10, 3);
        add_action('fluentform/payment_refunded', [$this, 'revokeReferralOnRefund'], 10, 3);

        /* Subscription Payments */
        add_action('fluentform/subscription_payment_canceled', [$this, 'revokeReferralOnRefund'], 10, 3);
        add_action('fluentform/subscription_payment_received', function ($subscription, $submission) {
            $this->markReferralComplete('paid', $submission, '');
        }, 10, 2);

        add_action('fluentform/subscription_payment_active', function ($subscription, $submission) {
            $this->markReferralComplete('paid', $submission, '');
        }, 10, 2);
    }

    public function onProcesPayment($submissionId, $submissionData, $form, $methodSettings, $subscriptionItems, $totalPayable)
    {
        $this->addPendingReferral($submissionId, $submissionData, $form, $totalPayable);
    }

    public function addPendingReferral($insertId, $formData, $form, $totalPayable = 0)
    {
        if (!self::isEnabledInForm($form->id)) {
            return;
        }

        if (!$this->was_referred() && empty($this->affiliate_id)) {
            return;
        }

        $settings = $this->getAffiliateWpSettings($form->id);
        $referralType = Arr::get($settings, 'selected_type', 'sale');
        $this->referral_type = $referralType;

        $isAffiliateEmail = $this->isAffiliateEmail($formData, $form);
        if ($isAffiliateEmail) {
            $this->log('Referral not created because affiliate\'s own account was used.');
            return;
        }

        $description = $form->title;
        $submission = $this->getSubmission($insertId);

        if ($totalPayable) {
            $amountInCents = $totalPayable;
        } else {
            $amountInCents = $submission->payment_total;
        }

        $totalPayment = number_format($amountInCents / 100);

        $referral_total = $this->calculate_referral_amount($totalPayment, $insertId);
        $formattedProducts = $this->getFormattedProducts($insertId);
        $this->insert_pending_referral($referral_total, $insertId, $description, $formattedProducts);

        if (empty($amountInCents) || $submission->payment_status == 'paid') {
            $referral = affiliate_wp()->referrals->get_by('reference', $insertId, $this->context);

            if (is_object($referral) && $referral->status != 'pending' && $referral->status != 'rejected') {
                // This referral has already been completed, or paid
                return false;
            }

            $this->complete_referral($insertId);
            $amount = affwp_currency_filter(affwp_format_amount($referral->amount));
            $name = affiliate_wp()->affiliates->get_affiliate_name($referral->affiliate_id);

            $logData = [
                'parent_source_id' => $form->id,
                'source_type'      => 'submission_item',
                'source_id'        => $insertId,
                'component'        => 'AffiliateWP',
                'status'           => 'success',
                'title'            => __('Pushed Data to AffiliateWP', 'fluentformpro'),
                // translators: %1$d is the referral ID, %2$s is the amount, %3$s is the affiliate name, %4$d is the affiliate ID
                'description'      => sprintf(
                    __('Referral #%1$d for %2$s recorded for %3$s (ID: %4$d).', 'fluentformpro'),
                    $referral->referral_id,
                    $amount,
                    $name,
                    $referral->affiliate_id
                )
            ];

            do_action('fluentform/log_data', $logData);
        }
    }

    public function markReferralComplete($newStatus, $submission, $transactionId)
    {
        if ($newStatus != 'paid') {
            return;
        }

        $referral = affiliate_wp()->referrals->get_by('reference', $submission->id, $this->context);

        if (!$referral) {
            return;
        }

        if (is_object($referral) && $referral->status != 'pending' && $referral->status != 'rejected') {
            // This referral has already been completed, or paid
            return false;
        }

        $this->complete_referral($submission->id);
        $amount = affwp_currency_filter(affwp_format_amount($referral->amount));
        $name = affiliate_wp()->affiliates->get_affiliate_name($referral->affiliate_id);

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Affiliate Wp',
            'status'           => 'success',
            'title'            => __('Pushed Data to Affiliate WP', 'fluentformpro'),
            // translators: %1$d is the referral ID, %2$s is the amount, %3$s is the affiliate name, %4$d is the affiliate ID
            'description'      => sprintf(
                __('Referral #%1$d for %2$s recorded for %3$s (ID: %4$d).', 'fluentformpro'),
                $referral->referral_id,
                $amount,
                $name,
                $referral->affiliate_id
            )
        ];

        do_action('fluentform/log_data', $logData);
    }

    public function revokeReferralOnRefund($refund, $transaction, $submission)
    {
        $this->reject_referral($submission->id);

        $referral = affiliate_wp()->referrals->get_by('reference', $submission->id, $this->context);
        $amount = affwp_currency_filter(affwp_format_amount($referral->amount));
        $name = affiliate_wp()->affiliates->get_affiliate_name($referral->affiliate_id);
        // translators: %1$d is the referral ID, %2$s is the amount, %3$s is the affiliate name
        $note = sprintf(
            __('Referral #%1$d for %2$s for %3$s rejected', 'fluentformpro'),
            $referral->referral_id,
            $amount,
            $name
        );

        $logData = [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Affiliate Wp',
            'status'           => 'success',
            'title'            => __('Pushed Data to Affiliate WP', 'fluentformpro'),
            'description'      => $note
        ];

        do_action('fluentform/log_data', $logData);
    }

    public function referenceLink($reference, $referral)
    {
        if (empty($referral->context) || 'fluentforms' != $referral->context) {
            return $reference;
        }

        $submission = $this->getSubmission($reference);
        if (!$submission) {
            return;
        }

        $url = admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . $submission->form_id . '#/entries/' . $submission->id);

        return '<a href="' . esc_url($url) . '">' . $reference . '</a>';
    }

    public function getSubmission($submissionId)
    {
        $submission = wpFluent()->table('fluentform_submissions')
            ->where('id', $submissionId)
            ->first();

        if (!$submission) {
            return false;
        }

        $submission->response = json_decode($submission->response, true);

        return $submission;
    }

    public function getAffiliateWpSettings($formId)
    {
        $defaults = [
            'types'         => affiliate_wp()->referrals->types_registry->get_types(),
            'selected_type' => 'sale',
            'status'        => 'no',
        ];
        $settings = Helper::getFormMeta($formId, 'affiliate_wp');
        return wp_parse_args($settings, $defaults);
    }

    public static function isEnabledInForm($formId)
    {
        $settings = Helper::getFormMeta($formId, 'affiliate_wp');
        return Arr::get($settings, 'status') == 'yes';
    }

    public function isModuleEnabled()
    {
        $globalModules = get_option('fluentform_global_modules_status');

        $affiliateWp = Arr::get($globalModules, 'affiliateWp');

        if ($affiliateWp == 'yes') {
            return true;
        }

        return false;
    }

    public function getEmails($formData, $form)
    {
        $emailInput = FormFieldsParser::getInputsByElementTypes($form, ['input_email']);
        $formData = FormDataParser::parseData((object)$formData, $emailInput, $form->id);
        if (!empty($formData)) {
            array_values($formData);
        }
        return false;
    }

    public function getLastTransaction($submissionId)
    {
        return wpFluent()->table('fluentform_transactions')
            ->where('submission_id', $submissionId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function get_customer($entry_id = 0)
    {
        $lastTransaction = $this->getLastTransaction($entry_id);

        if (!$lastTransaction) {
            $fullName = '';
            $email = '';
        } else {
            $fullName = $lastTransaction->payer_name;
            $email = $lastTransaction->payer_email;
        }

        if (empty($fullName)) {
            $firstname = '';
            $lastname = '';
        } else {
            $nameArray = explode(' ', $fullName);
            $lastname = end($nameArray);
            $firstname = str_replace($lastname, '', $fullName);
        }

        return [
            'first_name' => $firstname,
            'last_name'  => $lastname,
            'email'      => $email,
            'ip'         => affiliate_wp()->tracking->get_ip(),
        ];
    }

    public function isAffiliateEmail($formData, $form)
    {
        $emails = $this->getEmails($formData, $form);
        if (!$emails) {
            return false;
        }

        foreach ($emails as $customer_email) {
            if ($this->is_affiliate_email($customer_email, $this->affiliate_id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get Formatted Products
     *
     * @param $insertId
     * @return array
     */
    public function getFormattedProducts($insertId)
    {
        $submission = $this->getSubmission($insertId);
        $products = OrderData::getOrderItems($submission);
        $formattedProducts = [];
        foreach ($products as $product) {
            $formattedProducts[] = $product->item_name . ' - ' . $product->formatted_item_price . ' x ' . $product->quantity;
        }
        return $formattedProducts;
    }

    /**
     * Handle affiliate settings of the form
     */
    public function initFormSettings()
    {
        add_action('fluentform/after_save_form_settings', function ($formId, $allSettings) {
            $settings = Arr::get($allSettings, 'affiliate_wp', []);
            if ($settings) {
                Helper::setFormMeta($formId, 'affiliate_wp', $settings);
            }
        }, 10, 2);

        add_filter('fluentform/form_settings_ajax', function ($settings, $formId) {
            if ($affiliateWPSettings = $this->getAffiliateWpSettings($formId)) {
                $settings['affiliate_wp'] = $affiliateWPSettings;
            }
            
            return $settings;
        }, 10, 2);
    }

    public function plugin_is_active()
    {
        return true;
    }
}
