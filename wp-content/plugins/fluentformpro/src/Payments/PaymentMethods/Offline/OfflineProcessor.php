<?php

namespace FluentFormPro\Payments\PaymentMethods\Offline;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentFormPro\Payments\PaymentMethods\BaseProcessor;

class OfflineProcessor extends BaseProcessor
{
    protected $form;

    protected $method = 'test';

    public function handlePaymentAction($submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable)
    {
        $this->form = $form;
        $this->setSubmissionId($submissionId);

        $amountTotal = $this->getAmountTotal();

        if($amountTotal || $hasSubscriptions) {
            $this->createInitialPendingTransaction($this->getSubmission(), $hasSubscriptions);
        }
    }
    
    public function getPaymentMode()
    {
        return $this->method;
    }
}
