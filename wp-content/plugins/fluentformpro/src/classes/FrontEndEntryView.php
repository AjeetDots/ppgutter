<?php

namespace FluentFormPro\classes;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Models\FormMeta;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\App\Services\Submission\SubmissionService;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\classes\SharePage\SharePage;

if ( ! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class FrontEndEntryView
{
    
    protected $metaKey = 'front_end_entry_view';
    protected $submissionService;
    
    
    /**
     * Default settings for front-end view
     */
    protected $defaultViewSettings = [
        'status'             => 'no',
        'color_schema'       => '#4286c4',
        'custom_color'       => '#4286c4',
        'design_style'       => 'modern',
        'layout'             => 'front_end_entry',
        'no_index'           => 'no',
        'for_logged_in_user' => 'no',
        'content'            => '{all_data}',
        'logo'               => '',
        'title'              => '',
        'description'        => '',
        'featured_image'     => '',
        'background_image'   => '',
        'media'              => '',
        'alt_text'           => '',
        'form_shadow'        => [],
    ];
    
    public function init()
    {
        $this->submissionService = new SubmissionService();
        $this->initHooks();
    }
    
    protected function initHooks()
    {
        add_action('fluentform/after_save_form_settings', [$this, 'saveFormSettings'], 10, 2);
        
        add_filter('fluentform/form_settings_ajax', [$this, 'injectInFormSettings'], 10, 2);
        
        add_filter('fluentform/submission_shortcodes', [$this, 'addEntryUidLinkShortcode'], 10, 2);
        
        if ($this->canHandleFrontendView()) {
            add_action('wp', [$this, 'processFrontendView']);
        }
    }
    
    
    /**
     * Frontend View Methods
     */
    protected function canHandleFrontendView()
    {
        if (is_admin() ||
            !ArrayHelper::get($_GET, 'ff_entry') ||
            !ArrayHelper::get($_GET, 'hash')) {
            return false;
        }
        return $this->isViewEnabled();
    }
    
    public function isViewEnabled()
    {
        static $status = null;
        
        if ($status !== null) {
            return $status;
        }
        
        $status = $this->getActiveViewForms()->count() > 0;
        return apply_filters('fluentform/front_end_entry_view_status', $status);
    }
    
    protected function getActiveViewForms()
    {
        return FormMeta::where('meta_key', $this->metaKey)
                       ->get()
                       ->filter(function ($meta) {
                           $value = json_decode($meta->value, true);
                           
                           return ($value['status'] ?? '') === 'yes';
                       });
    }
    
    
    public function processFrontendView()
    {
        $submission = $this->getSubmissionByHash();
        if ( ! $submission) {
            $this->handleRedirect();
            return;
        }
        
        $this->renderSubmissionView($submission);
    }
    
    protected function getSubmissionByHash()
    {
        $hash    = ArrayHelper::get($_GET, 'hash');
        $entryId = ArrayHelper::get($_GET, 'ff_entry');
        
        if ( ! $hash || ! $entryId) {
            return null;
        }
        
        try {
            // The link only shows the entry: don't mark it read, and don't show a trashed or spam one
            add_filter('fluentform/auto_read_submission', '__return_false');
            $submission = $this->submissionService->findByParams(['uidHash' => $hash]);

            return in_array($submission->status, ['trashed', 'spam'], true) ? null : $submission;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function handleRedirect()
    {
        wp_redirect(home_url());
        exit;
    }
    
    protected function handleLoggedInUserRequired()
    {
        wp_redirect(wp_login_url(get_permalink()));
        exit;
    }
    
    /**
     * Check if the current user can view the submission
     */
    protected function canUserViewSubmission($submission)
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        
        if ( ! $submission->user_id) {
            return true;
        }
        
        return get_current_user_id() == (int)$submission->user_id;
    }
    
    protected function renderSubmissionView($submission)
    {
        $settings = $this->getViewSettings($submission->form_id);
        
        if (ArrayHelper::get($settings, 'status') !== 'yes') {
            $this->handleRedirect();
            
            return;
        }
        
        if (ArrayHelper::get($settings, 'for_logged_in_user') === 'yes') {
            if ( ! is_user_logged_in()) {
                $this->handleRedirect();
                
                return;
            } elseif ( ! $this->canUserViewSubmission($submission)) {
                $this->handleRedirect();
                
                return;
            }
        }
        
        $formData = json_decode($submission->response, true);
        
        $userContent = $settings['content'] ?: '{all_data}';
        $content     = $this->parseSubmissionContent($submission, $formData, $userContent);
        
        // Calculate background color like SharePage does
        $backgroundColor = ArrayHelper::get($settings, 'bg_color');
        if ($backgroundColor == '') {
            $backgroundColor = ArrayHelper::get($settings, 'color_schema');
        }
        
        // Calculate page title
        $pageTitle = $submission->form->title;
        if ($settings['title']) {
            $pageTitle = $settings['title'];
        }
        
        $viewData = [
            'settings'        => $settings,
            'title'           => $pageTitle,
            'form_id'         => $submission->form_id,
            'form'            => $submission->form,
            'bg_color'        => $backgroundColor,
            'landing_content' => $content,
            'has_header'      => $settings['logo'] || $settings['title'] || $settings['description'],
            'isEmbeded'       => ! ! ArrayHelper::get($_GET, 'embedded'),
            'front_end_entry' => true,
        ];
        
        $this->addNoIndexMetaTags($settings);
        (new SharePage())->loadPublicView($viewData);
    }
    
    protected function getViewSettings($formId)
    {
        $settings = Helper::getFormMeta($formId, $this->metaKey, []);
        
        return wp_parse_args($settings, $this->defaultViewSettings);
    }
    
    protected function parseSubmissionContent($submission, $formData, $userContent)
    {
        // Expand trusted shortcodes first; escaped brackets keep submitter values literal.
        $userContent = do_shortcode($userContent);
        $userContent = ShortCodeParser::parse($userContent, $submission->id, $formData);
        $userContent = str_replace(['[', ']'], ['&#91;', '&#93;'], $userContent);

        $template    = '<div class="ff_frontend_entry_view_wrapper">' . $userContent . '</div>';

        return apply_filters('fluentform/landing_content_wrapper', $template, $submission);
    }
    
    protected function addNoIndexMetaTags($settings)
    {
        if (ArrayHelper::get($settings, 'no_index') !== 'yes') {
            return;
        }
        
        add_action('wp_head', function () {
            echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
        });
    }
    
    public function injectInFormSettings($settings, $formId)
    {
        $frontEndSettings = $this->getViewSettings($formId);
        if ($frontEndSettings) {
            $settings['front_end_entry_view'] = $frontEndSettings;
        }
        
        return $settings;
    }
    
    /**
     * Add entry_uid_link shortcode only when front-end view is enabled
     */
    public function addEntryUidLinkShortcode($submissionShortcodes, $form)
    {
        if ( ! $form) {
            return $submissionShortcodes;
        }
        $frontEndSettings = $this->getViewSettings($form->id);
        $isEnabled        = ArrayHelper::get($frontEndSettings, 'status') === 'yes';
        if ($isEnabled) {
            $submissionShortcodes['shortcodes']['{submission.entry_uid_link}'] = __('Entry Frontend View Link',
                'fluentformpro');
        }
        
        return $submissionShortcodes;
    }
    
    public function saveFormSettings($formId, $settings)
    {
        if (isset($settings['front_end_entry_view']) && $settings['front_end_entry_view'] != false) {
            $frontEndSettings = $settings['front_end_entry_view'];
            if (Helper::isJson($frontEndSettings)) {
                $frontEndSettings = json_decode($frontEndSettings, true);
            }
            $frontEndSettings['status']             = in_array($frontEndSettings['status'],
                ['yes', 'no']) ? $frontEndSettings['status'] : 'no';
            $frontEndSettings['no_index']           = in_array($frontEndSettings['no_index'],
                ['yes', 'no']) ? $frontEndSettings['no_index'] : 'no';
            $frontEndSettings['for_logged_in_user'] = in_array($frontEndSettings['for_logged_in_user'],
                ['yes', 'no']) ? $frontEndSettings['for_logged_in_user'] : 'no';
            $frontEndSettings['content']            = fluentform_sanitize_html($frontEndSettings['content']);
            
            Helper::setFormMeta($formId, $this->metaKey, $frontEndSettings);
        }
    }
    
}
