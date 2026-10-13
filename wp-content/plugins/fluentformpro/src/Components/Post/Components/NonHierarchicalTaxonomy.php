<?php

namespace FluentFormPro\Components\Post\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Services\FormBuilder\Components\Text;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;

class NonHierarchicalTaxonomy extends Text
{
    public function compile($data, $form)
    {
        $data['attributes']['type'] = 'text';
        $data['attributes']['placeholder'] = Arr::get($data, 'settings.placeholder');

        return parent::compile($data, $form);
    }
}