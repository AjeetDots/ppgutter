<?php

namespace FluentFormPro\Components\DynamicField;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DynamicFieldHelper
{
    /**
     * The submission form- id scope for a given author: false = unrestricted,
     * [] = restricted to nothing (fail closed), [ids] = the author's forms.
     * Single source of truth for both DynamicField and FluentformSubmission.
     */
    public static function allowedFormIdsForAuthor($authorId)
    {
        $authorId = (int) $authorId;

        if (!$authorId) {
            return [];
        }

        // A specific-forms manager is already scoped by the service ([] or [ids]).
        $scope = \FluentForm\App\Services\Manager\FormManagerService::getUserAllowedFormsScope($authorId);
        if (false !== $scope) {
            return $scope;
        }

        // getUserAllowedFormsScope() fails OPEN (false) for every non-manager — a
        // subscriber or a demoted manager included — so the unrestricted verdict must
        // be re-gated on an actual forms authority, or a subscriber-owned form leaks
        // cross-form data.
        $unrestricted = user_can($authorId, 'fluentform_forms_manager')
            || user_can($authorId, 'fluentform_full_access')
            || user_can($authorId, 'manage_options');

        return $unrestricted ? false : [];
    }

    public static function getOperators()
    {
        return [
            '='             => __('Equal', 'fluentformpro'),
            '!='            => __('Not Equal', 'fluentformpro'),
            'IN'            => __('Equal In', 'fluentformpro'),
            'NOT IN'        => __('Not Equal In', 'fluentformpro'),
            '>'             => __('Greater Than', 'fluentformpro'),
            '<'             => __('Less Than', 'fluentformpro'),
            '>='            => __('Greater Than or Equal', 'fluentformpro'),
            '<='            => __('Less Than or Equal', 'fluentformpro'),
            'contains'      => __('Contains', 'fluentformpro'),
            'doNotContains' => __('Does Not Contain', 'fluentformpro'),
            'startsWith'    => __('Starts With', 'fluentformpro'),
            'endsWith'      => __('Ends With', 'fluentformpro'),
            'BETWEEN'       => __('Between', 'fluentformpro'),
            'NOT BETWEEN'   => __('Not Between', 'fluentformpro'),
        ];
    }


    public static function numericColumns()
    {
        return ['ID', 'serial_number', 'terms.term_id', 'fluentform_submissions.id', 'fluentform_submissions.user_id', 'fluentform_submissions.form_id', 'parent', 'post_author', 'post_parent'];
    }

    public static function dateColumns()
    {
        return ['created_at', 'updated_at', 'post_date'];
    }

    public static function getI18n()
    {
        return [
            'Value' => __('Value', 'fluentformpro'),
            'Dynamic Field' => __('Dynamic Field', 'fluentformpro'),
            'Meta Value' => __('Meta Value', 'fluentformpro'),
            'Meta Key' => __('Meta Key', 'fluentformpro'),
            'User Url' => __('User Url', 'fluentformpro'),
            'User Email' => __('User Email', 'fluentformpro'),
            'User Nicename' => __('User Nicename', 'fluentformpro'),
            'Display Name' => __('Display Name', 'fluentformpro'),
            'User Login' => __('User Login', 'fluentformpro'),
            'ID' => __('ID', 'fluentformpro'),
            'Post Author' => __('Post Author', 'fluentformpro'),
            'Post Parent' => __('Post Parent', 'fluentformpro'),
            'Post Title' => __('Post Title', 'fluentformpro'),
            'Post Name' => __('Post Name', 'fluentformpro'),
            'Post Excerpt' => __('Post Excerpt', 'fluentformpro'),
            'Post Status' => __('Post Status', 'fluentformpro'),
            'Post Type' => __('Post Type', 'fluentformpro'),
            'Term Id' => __('Term Id', 'fluentformpro'),
            'Name' => __('Name', 'fluentformpro'),
            'Slug' => __('Slug', 'fluentformpro'),
            'Taxonomy' => __('Taxonomy', 'fluentformpro'),
            'Parent' => __('Parent', 'fluentformpro'),
            'Description' => __('Description', 'fluentformpro'),
            'Suffix Label' => __('Suffix Label', 'fluentformpro'),
            'Check if you want to be used value as the default value.' => __('Check if you want to be used value as the default value.', 'fluentformpro'),
            'Options' => __('Options', 'fluentformpro'),
            'Populate Dynamically' => __('Populate Dynamically', 'fluentformpro'),
            'Label Placement' => __('Label Placement', 'fluentformpro'),
            'Admin Field Label' => __('Admin Field Label', 'fluentformpro'),
            'Element Label' => __('Element Label', 'fluentformpro'),
            'Choose the option you want to be used as the value' => __('Choose the option you want to be used as the value', 'fluentformpro'),
            'Prefix Label' => __('Prefix Label', 'fluentformpro'),
            'valid values of ' => __('valid values of ', 'fluentformpro'),
            'Valid values by template mapping' => __('Valid values by template mapping', 'fluentformpro'),
            'Results' => __('Results', 'fluentformpro'),
            'Valid options make by template mapping' => __('Valid options make by template mapping', 'fluentformpro'),
            'Result found by filters' => __('Result found by filters', 'fluentformpro'),
            'Values' => __('Values', 'fluentformpro'),
            'Select option if you want to be used value as the default value.' => __('Select option if you want to be used value as the default value.', 'fluentformpro'),
            'Type' => __('Type', 'fluentformpro'),
            'Choose the source to populate dynamically' => __('Choose the source to populate dynamically', 'fluentformpro'),
            'Filters' => __('Filters', 'fluentformpro'),
            'Refine search results by specifying database query filters. Utilize logical operators like AND/OR to group multiple filters, ensuring more precise filtering.' => __('Refine search results by specifying database query filters. Utilize logical operators like AND/OR to group multiple filters, ensuring more precise filtering.', 'fluentformpro'),
            'Add Filter Group' => __('Add Filter Group', 'fluentformpro'),
            'Only Show Unique Result' => __('Only Show Unique Result', 'fluentformpro'),
            'Toggle to display only unique results based on the ' => __('Toggle to display only unique results based on the ', 'fluentformpro'),
            'Ordering' => __('Ordering', 'fluentformpro'),
            'Template Mapping' => __('Template Mapping', 'fluentformpro'),
            'Specify the ordering of the dynamically populate' => __('Specify the ordering of the dynamically populate', 'fluentformpro'),
            'results' => __('results', 'fluentformpro'),
            'valid option of ' => __('valid option of ', 'fluentformpro'),
            'Get Result' => __('Get Result', 'fluentformpro'),
            'Define the mapping template for generate options. Use placeholders to dynamically insert values from the database records.' => __('Define the mapping template for generate options. Use placeholders to dynamically insert values from the database records.', 'fluentformpro'),
            'Empty Values' => __('Empty Values', 'fluentformpro'),
            'Empty Options' => __('Empty Options', 'fluentformpro'),
            'Dynamic Options Retrieval' => __('Dynamic Options Retrieval', 'fluentformpro'),
            'When checked, value are dynamically fetched based on filters during rendering, and the first valid value is used. Leave unchecked to use the current valid value mapping.' => __('When checked, value are dynamically fetched based on filters during rendering, and the first valid value is used. Leave unchecked to use the current valid value mapping.', 'fluentformpro'),
            'When checked, options are dynamically fetched based on filters during rendering. If unchecked, the current valid options remain unchanged.' => __('When checked, options are dynamically fetched based on filters during rendering. If unchecked, the current valid options remain unchanged.', 'fluentformpro'),
            'Values are dynamically fetched based on filters during rendering, and the first valid value will be used.' => __('Values are dynamically fetched based on filters during rendering, and the first valid value will be used.', 'fluentformpro'),
            'Basic' => __('Basic', 'fluentformpro'),
            'Advance' => __('Advance', 'fluentformpro'),
            'Form' => __('Form', 'fluentformpro'),
            'Choose a form from the list.' => __('Choose a form from the list.', 'fluentformpro'),
            'Form Field' => __('Form Field', 'fluentformpro'),
            'Select form' => __('Select form', 'fluentformpro'),
            'Select Field' => __('Select Field', 'fluentformpro'),
            'Select a form' => __('Select a form', 'fluentformpro'),
            'User Role' => __('User Role', 'fluentformpro'),
            'Select Role' => __('Select Role', 'fluentformpro'),
            'Chose a role' => __('Chose a role', 'fluentformpro'),
        ];
    }
}