<?php

namespace FluentFormPro\Components\Post\Components;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Services\FormBuilder\Components\Select;
use FluentForm\App\Services\FormBuilder\Components\Checkable;
use FluentForm\Framework\Helpers\ArrayHelper;

class HierarchicalTaxonomy
{
    public function compile($data, $form)
    {
        $data = $this->populateOptions($data, $form);

        $data['settings']['dynamic_default_value'] = ArrayHelper::get($data, 'attributes.value');

        $fieldType = $data['settings']['field_type'];

        if ($fieldType == 'select_single' || $fieldType == 'select_multiple') {
            
            $data['attributes']['type'] = 'select';

            if ($fieldType == 'select_multiple') {
                $data['attributes']['multiple'] = true;
            }
            
            return (new Select)->compile($data, $form);
        } else {
            if ($fieldType == 'radio') {
                $data['attributes']['type'] = 'radio';
            } else {
                $data['attributes']['type'] = 'checkbox';
            }

            return (new Checkable)->compile($data, $form);   
        }
    }

    protected function populateOptions($data, $form)
    {
        if (isset($data['taxonomy_settings'])) {

            if ($data['taxonomy_settings']['hierarchical']) {

                $postTermsArgs = apply_filters_deprecated(
                    'fluentform_post_integrations_terms_args',
                    [
                        [
                            'order' => 'ASC',
                            'hide_empty' => false,
                            'taxonomy' => $data['taxonomy_settings']['name']
                        ],
                        $data,
                        $form
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/post_integrations_terms_args',
                    'Use fluentform/post_integrations_terms_args instead of fluentform_post_integrations_terms_args.'
                );

                $termsArgs = apply_filters('fluentform/post_integrations_terms_args', $postTermsArgs, $data, $form);

                $terms = get_terms($termsArgs);
                $data['settings']['advanced_options'] = TaxonomyOptionFormatter::formatOptions($terms);
            }
        }

        return $data;
    }
}
