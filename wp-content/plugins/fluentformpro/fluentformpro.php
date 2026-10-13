<?php
/**
Plugin Name: Fluent Forms Pro Add On Pack
Description: The Pro version of FluentForm, the most advanced, drag and drop form builder plugin for WordPress.
Version: 6.2.15
Author: Fluent Forms
Author URI: https://fluentforms.com
Plugin URI: https://fluentforms.com/
License: GPLv2 or later
Text Domain: fluentformpro
Domain Path: /resources/languages
*/

defined('ABSPATH') or die;

define('FLUENTFORMPRO', true);
define('FLUENTFORMPRO_VERSION', '6.2.15');
defined('FLUENTFORM_MINIMUM_CORE_VERSION') or define('FLUENTFORM_MINIMUM_CORE_VERSION', '6.0.0');
define('FLUENTFORM_UPLOAD_DIR', '/fluentform');
define('FLUENTFORMPRO_DIR_URL', plugin_dir_url(__FILE__));
define('FLUENTFORMPRO_DIR_PATH', plugin_dir_path(__FILE__));
define('FLUENTFORMPRO_DIR_FILE', __FILE__);

include FLUENTFORMPRO_DIR_PATH . 'autoload.php';

if (!class_exists('FluentFormPro')) {

    class FluentFormPro
    {
        /**
         * Add paths/directory names in $addOns to any add addons where a Bootstrap.php file
         * is available to initialize the addon. Following entries are addon directory names.
         *
         * @var $addOns array
         */
        protected $addOns = array(
            'FluentFormPro\Integrations\ActiveCampaign',
            'FluentFormPro\Integrations\CampaignMonitor',
            'FluentFormPro\Integrations\ConstantContact',
            'FluentFormPro\Integrations\ConvertKit',
            'FluentFormPro\Integrations\GetResponse',
            'FluentFormPro\Integrations\Hubspot',
            'FluentFormPro\Integrations\IContact',
            'FluentFormPro\Integrations\MooSend',
            'FluentFormPro\Integrations\Platformly',
            'FluentFormPro\Integrations\WebHook',
            'FluentFormPro\Integrations\Zapier',
            'FluentFormPro\Integrations\SendFox',
            'FluentFormPro\Integrations\MailerLite',
            'FluentFormPro\Integrations\SMSNotification',
            'FluentFormPro\Integrations\GetGist',
            'FluentFormPro\Integrations\GoogleSheet',
            'FluentFormPro\Integrations\Trello',
            'FluentFormPro\Integrations\Drip',
            // 'FluentFormPro\Integrations\Aweber',
            'FluentFormPro\Integrations\Sendinblue',
            'FluentFormPro\Integrations\UserRegistration',
            'FluentFormPro\Integrations\Automizy',
            'FluentFormPro\Integrations\Telegram',
            'FluentFormPro\Integrations\Salesflare',
            'FluentFormPro\Integrations\Discord',
            'FluentFormPro\Integrations\CleverReach',
            'FluentFormPro\Integrations\ClickSend',
            'FluentFormPro\Integrations\ZohoCRM',
            'FluentFormPro\Integrations\Pipedrive',
            'FluentFormPro\Integrations\Salesforce',
            'FluentFormPro\Integrations\Amocrm',
            'FluentFormPro\Integrations\OnePageCrm',
            'FluentFormPro\Integrations\Airtable',
            'FluentFormPro\Integrations\Mailjet',
            'FluentFormPro\Integrations\Insightly',
            'FluentFormPro\Integrations\Mailster',
            'FluentFormPro\Integrations\NewAirtable',
            'FluentFormPro\Integrations\Notion',
            'FluentFormPro\Integrations\ConstantContactV3',
        );

        /**
         * Extra elements in Pro version
         * @var $proElements array
         */
        protected $proElements = array(
            'shortcode',
            'form_step',
            'input_file',
            'input_image',
            'input_repeat',
            'action_hook',
        );

        /**
         * Bootstrap the FluentFormPro Plugin
         * @param FluentForm\Framework\Foundation\Application $app
         * @return void
         */
        public function boot()
        {
            if (!defined('FLUENTFORM')) {
                $this->injectDependency();
                return;
            }

            if (!defined('FLUENTFORM_FRAMEWORK_UPGRADE')) {
                $message = '<div><h3>' . __('Fluent Forms Pro is not working. Update required.</h3><p>Fluent Forms Pro requires the latest version of the Fluent Forms Core Plugin.', 'fluentformpro') . '<a href="' . admin_url('plugins.php?s=fluentform&plugin_status=all') . '">' . __('Please update Fluent Forms to latest version', 'fluentformpro') . '</a>.</p></div>';
                $actions = [
                    'fluentform/global_menu',
                    'fluentform/after_form_menu',
                    'admin_notices'
                ];
                foreach ($actions as $action) {
                    add_action($action, function () use ($message) {
                        printf('<div class="fluentform-admin-notice notice notice-error">%1$s</div>', wp_kses_post($message));
                    });
                }
                return;
            }

            if (function_exists('wpFluentForm')) {
                $this->registerHooks(wpFluentForm());
            }

        }

        protected function registerHooks($fluentForm)
        {
            $this->ajaxHooks($fluentForm);
            $this->adminHooks($fluentForm);
            $this->publicHooks($fluentForm);
            $this->commonHooks($fluentForm);
            $this->registerAddOns($fluentForm);

            add_action('wp_enqueue_scripts', array($this, 'registerScripts'), 999);

            new \FluentFormPro\Components\PhoneField();
            new \FluentFormPro\Components\RankingField();
            new \FluentFormPro\Components\RangeSliderField();
            new \FluentFormPro\Components\NetPromoterScore();
            new \FluentFormPro\Components\DynamicField\DynamicField();
            new \FluentFormPro\Components\ChainedSelect\ChainedSelect();
            new \FluentFormPro\Components\ColorPicker();
            new \FluentFormPro\Components\RepeaterField();
            new \FluentFormPro\Components\PostSelectionField();
            new \FluentFormPro\Components\RepeaterContainer();
            new \FluentFormPro\Components\Post\Components\PostContent('rich_text_input', 'Rich Text Input', ['content', 'rich', 'html'], 'advanced');
            new \FluentFormPro\Components\Accordion();

            // SECURITY (PRO-36): server-authoritatively approve Action Hook field hook names on save.
            add_filter('fluentform/form_fields_update', ['\FluentFormPro\Components\ActionHook', 'gateHookApproval'], 10, 2);

            new \FluentFormPro\Components\SaveProgressButton();

            /*
             * Complience Settings here
             */
            add_action('fluentform/global_notify_completed', function ($entryId, $form) {
                if (\FluentForm\App\Helpers\Helper::isEntryAutoDeleteEnabled($form->id)) {
                    $entriesService = new \FluentForm\App\Services\Submission\SubmissionService();
                    $entriesService->deleteEntries($entryId, $form->id);
                }
            }, 10, 2);

            /*
             * Form Styler
             */
            (new \FluentFormPro\classes\FormStyler)->boot();

            /*
             * Form Landing Pages
             */
            (new \FluentFormPro\classes\SharePage\SharePage)->boot();

            /*
             * Boot Payment Module
             */
            (new \FluentFormPro\Payments\PaymentHandler)->init();

            /*
            * Address AutoComplete
            */

            (new \FluentFormPro\classes\AddressAutoComplete())->init();

            if (defined('BP_VERSION')) {
                (new \FluentFormPro\Integrations\BuddyIntegration\FluentFormBuddyBoss())->init();
            }
            /*
             * Quiz Module
             */
            (new \FluentFormPro\classes\Quiz\QuizController())->init($fluentForm);
            /*
             * Inventory Settings Module
             */
            (new \FluentFormPro\classes\Inventory\InventoryController())->boot();
            /*
             * Chat Field Manager Module
             * Need more improvement
             */
            (new \FluentFormPro\classes\Chat\ChatFieldController($fluentForm));

            /*
             * Entries Import Module
             */
             (new \FluentFormPro\classes\EntriesImport\EntriesImportController())->boot();

             /*
             * Entries Advanced search
             */
             (new \FluentFormPro\classes\AdvancedEntriesSearch())->init();
        }

        /**
         * Notify the user about the FluentForm dependency and instructs to install it.
         */
        protected function injectDependency()
        {
            add_action('admin_notices', function () {
                $pluginInfo = $this->getFluentFormInstallationDetails();

                $class = 'notice notice-error';

                $install_url_text = __('Click Here to Install the Plugin', 'fluentformpro');

                if ($pluginInfo->action == 'activate') {
                    $install_url_text = __('Click Here to Activate the Plugin', 'fluentformpro');
                }

                $message = __('FluentForm PRO Add-On Requires FluentForm Base Plugin, ', 'fluentformpro') . '<b><a href="' . $pluginInfo->url
                    . '">' . $install_url_text . '</a></b>';

                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses_post($message));
            });
        }

        /**
         * Get the FluentForm plugin installation information e.g. the URL to install.
         *
         * @return \stdClass $activation
         */
        protected function getFluentFormInstallationDetails()
        {
            $activation = (object)[
                'action' => 'install',
                'url'    => ''
            ];

            $allPlugins = get_plugins();

            if (isset($allPlugins['fluentform/fluentform.php'])) {
                $url = wp_nonce_url(
                    self_admin_url('plugins.php?action=activate&plugin=fluentform/fluentform.php'),
                    'activate-plugin_fluentform/fluentform.php'
                );

                $activation->action = 'activate';
            } else {
                $api = (object)[
                    'slug' => 'fluentform'
                ];

                $url = wp_nonce_url(
                    self_admin_url('update.php?action=install-plugin&plugin=' . $api->slug),
                    'install-plugin_' . $api->slug
                );
            }

            $activation->url = $url;

            return $activation;
        }

        /**
         * Register admin/backend hooks
         * @return void
         */
        public function adminHooks($app)
        {
            add_filter('fluentform/disabled_components', [$this, 'filterDisabledComponents']);

            add_action('wp_ajax_fluentform_update_entry', function () {
                // Authorization (nonce + capability on the entry's STORED form) is
                // performed inside editEntry(), where entry_id is resolved to its
                // form. Verifying the request form_id here would authorize a
                // different form than the one actually mutated (confused deputy).
                \FluentFormPro\classes\EntryEditor::editEntry();
            });

            (new \FluentFormPro\classes\ResendNotificationHandler())->init();
            (new \FluentFormPro\classes\FailedIntegrationNotification())->init();
            (new \FluentFormPro\Uploader($app))->processFiles();

            \FluentFormPro\classes\StepFormEntries::boot($app);

            if (defined('AFFILIATEWP_VERSION')){
                (new \FluentFormPro\Integrations\AffiliateWP\AffiliateWPFF())->boot();
            }
            /**
             * Field selection for slack
             */
            add_filter('fluentform/slack_field_label_selection', function ($labels, $settings) {
                if (FluentForm\Framework\Helpers\ArrayHelper::exists($settings, 'fields')) {
                    $selectedFields = FluentForm\Framework\Helpers\ArrayHelper::get($settings, 'fields');
                    $labels = array_intersect($labels, $selectedFields);
                }

                return $labels;
            }, 10, 2);


            // Show update notice for Fluent Forms FREE version for major compatibility
            if (defined('FLUENTFORM_VERSION') && version_compare(FLUENTFORM_VERSION, '6.2.0', '<')) {
                $message = '<div style="padding: 15px 5px;" ><b>' . __('Heads UP: ',
                        'fluentformpro') . '</b>' . __('Fluent Forms FREE version needs to be updated to the latest version. Some features will not function correctly until you update.',
                        'fluentformpro') . '<a href="' . admin_url('plugins.php?s=fluent+forms&plugin_status=all&force-check=1') . '">' . __(' Please update Fluent Forms FREE to the latest version now.',
                        'fluentformpro') . '</a></div>';
                $actions = [
                    'fluentform/global_menu',
                    'fluentform/after_form_menu',
                ];
                foreach ($actions as $action) {
                    add_action($action, function () use ($message) {
                        printf('<div class="fluentform-admin-notice notice notice-warning">%1$s</div>', wp_kses_post($message));
                    });
                }
            }
        }

        /**
         * Register public/frontend hooks
         * @return void
         */
        public function publicHooks($app)
        {
            $this->registerProElementsRenderingHook($app);
        }

        /**
         * Registers common hooks.
         */
        public function commonHooks($app)
        {
            $app->addfilter('fluentform/validations', function ($validations, $form) use ($app) {
                return (new \FluentFormPro\Uploader($app))->prepareValidations($validations, $form);
            }, 10, 2);

            // Filter other confirmations for form submission response
            $app->addFilter('fluentform/form_submission_confirmation', function ($confirmation, $data, $form) {

                $confirmations = wpFluent()->table('fluentform_form_meta')
                    ->where('form_id', $form->id)
                    ->where('meta_key', 'confirmations')
                    ->get();


                foreach ($confirmations as $item) {
                    $item->value = json_decode($item->value, true);
                    if (isset($item->value['active']) && $item->value['active']) {
                        if (\FluentForm\App\Services\ConditionAssesor::evaluate($item->value, $data)) {
                            return $item->value;
                        }
                    }
                }

                return $confirmation;

            }, 10, 3);

            add_filter('fluentform/email_template_footer_credit', '__return_empty_string');

            add_shortcode('fluentform_modal', function ($atts) {
                $shortcodeDefaults = apply_filters_deprecated(
                    'fluentform_popup_shortcode_defaults',
                    [
                        [
                            'form_id'    => null,
                            'btn_text'   => 'Contact Me',
                            'css_class'  => 'btn btn-success',
                            'bg_color'   => 'white',
                            'form_class' => ''
                        ],
                        $atts
                    ],
                    'fluentform/popup_shortcode_defaults',
                    'Use fluentform/popup_shortcode_defaults instead of fluentform_popup_shortcode_defaults.'
                );
                $shortcodeDefaults = apply_filters('fluentform/popup_shortcode_defaults', $shortcodeDefaults, $atts);
                $atts = shortcode_atts($shortcodeDefaults, $atts);

                if (empty($atts['form_id'])) {
                    return;
                }

                // Apply modal button text filter
                $form = wpFluent()->table('fluentform_forms')->find($atts['form_id']);
                if ($form) {
                    $atts['btn_text'] = apply_filters('fluentform/modal_button_text', $atts['btn_text'], $form);
                }

                return \FluentFormPro\classes\FormModal::renderModal($atts);
            });

            add_shortcode('fluentform_survey', function ($atts) {
                $shortcodeDefaults = apply_filters_deprecated(
                    'fluentform_survey_shortcode_defaults',
                    [
                        [
                            'form_id'    => null,
                            'field_name' => '',
                            'label'      => __('yes', 'fluentformpro'),
                            'counts'     => __('yes', 'fluentformpro')
                        ],
                        $atts
                    ],
                    'fluentform/survey_shortcode_defaults',
                    'Use fluentform/survey_shortcode_defaults instead of fluentform_survey_shortcode_defaults.'
                );
                $shortcodeDefaults = apply_filters('fluentform/survey_shortcode_defaults', $shortcodeDefaults, $atts);

                $atts = shortcode_atts($shortcodeDefaults, $atts);

                // Apply survey labels filter
                if (!empty($atts['form_id'])) {
                    $form = wpFluent()->table('fluentform_forms')->find($atts['form_id']);
                    if ($form) {
                        $surveyLabels = ['label' => $atts['label'], 'counts' => $atts['counts']];
                        $surveyLabels = apply_filters('fluentform/survey_labels', $surveyLabels, $form);
                        $atts['label'] = $surveyLabels['label'];
                        $atts['counts'] = $surveyLabels['counts'];
                    }
                }

                return (new \FluentFormPro\classes\SurveyResultProcessor())->getSurveyResultHtml($atts);
            });

            add_filter('fluentform/submission_message_parse', array('FluentFormPro\classes\ConditionalContent', 'initiate'), 10, 4);

            add_shortcode('ff_if', array('FluentFormPro\classes\ConditionalContent', 'handle'));
            add_shortcode('ff_sub_if', array('FluentFormPro\classes\ConditionalContent', 'handle'));

            add_action('fluentform/rendering_calculation_form', function ($form, $element) {
                $calculation = new \FluentFormPro\classes\Calculation();
                $calculation->enqueueScripts();
                $calculation->localizeCalculationMessages($form);
            }, 10, 2);

            // Hook into the `fluentform_submission_confirmation` before returning the data
            // so that if append survey result is enabled we could display survey result.
            add_filter('fluentform/submission_confirmation', function ($data, $form) {
                if (isset($form->settings['appendSurveyResult']) && $form->settings['appendSurveyResult']['enabled']) {
                    $data['message'] .= (new \FluentFormPro\classes\SurveyResultProcessor())->getSurveyResultHtml([
                        'form_id'    => $form->id,
                        'field_name' => '',
                        'label'      => $form->settings['appendSurveyResult']['showLabel'] ? 'yes' : false,
                        'counts'     => $form->settings['appendSurveyResult']['showCount'] ? 'yes' : false,
                    ]);
                }

                return $data;
            }, 10, 2);

            \FluentFormPro\classes\DraftSubmissionsManager::boot($app);

            add_filter('fluentform/validation_errors', function ($errors, $data, $form, $fields) {
                return (new \FluentFormPro\classes\AdvancedFormValidation())->validateAdvancedConditions($errors, $data, $form, $fields);
            }, 10, 4);

            (new \FluentFormPro\classes\ProSmartCodes())->register();

            (new \FluentFormPro\classes\DoubleOptin())->init();

            (new \FluentFormPro\classes\AdminApproval\AdminApproval())->init();

            (new \FluentFormPro\classes\FrontEndEntryView())->init();

            // Reports module is available after 6.0.4
            if (version_compare(FLUENTFORM_VERSION, '6.0.4', '>')) {
                (new \FluentFormPro\Reports\ReportsResponseExtender())->init();
                (new \FluentFormPro\Reports\RankingReportAggregator())->init();
            }

            // On init, before the abilities registry lazily fires wp_abilities_api_init.
            add_filter('fluentform/mcp_tool_definitions', ['\FluentFormPro\Reports\McpReportTools', 'defineTools']);

            // Answer the shared fluent_toolkit/* install contract so the MCP
            // settings page shows a one-click "Install FluentHub" button.
            (new \FluentFormPro\Mcp\ToolkitInstaller())->init();

            \FluentFormPro\Components\Post\Bootstrap::boot();
            \FluentFormPro\Components\CheckableOtherOption::boot();

            /**
             * Post Type Selections for Post Form
             */
            add_filter('fluentform/all_forms_vars', function ($vars) {
                $postSettings = new \FluentFormPro\Components\Post\PostFeedSettings;
                if (!isset($vars['post_types'])) {
                    $vars['post_types'] = $postSettings->getPostTypes();
                }
                return $vars;
            }, 10, 1);
        }

        /**
         * Registers ajax hooks.
         */
        public function ajaxHooks($app)
        {
            $app->addAdminAjaxAction('fluentform_file_upload', function () use ($app) {
                (new \FluentFormPro\Uploader($app))->upload();
            });

            $app->addPublicAjaxAction('fluentform_file_upload', function () use ($app) {
                (new \FluentFormPro\Uploader($app))->upload();
            });

            $app->addAdminAjaxAction('fluentform_delete_uploaded_file', function () use ($app) {
                (new \FluentFormPro\Uploader($app))->deleteFile();
            });

            $app->addPublicAjaxAction('fluentform_delete_uploaded_file', function () use ($app) {
                (new \FluentFormPro\Uploader($app))->deleteFile();
            });

            // Post Form Creation Ajax Hook On Backend
            $app->addAdminAjaxAction('fluentform_get_post_settings', function () {
                \FluentForm\App\Modules\Acl\Acl::verify('fluentform_settings_manager');

                $postSettings = new \FluentFormPro\Components\Post\PostFeedSettings;

                wp_send_json_success($postSettings->getPostSettings());
            });

            // Chained Select Ajax Hook On Backend
            $app->addAdminAjaxAction('fluentform_chained_select_file_upload', function () use ($app) {
                $formId = intval($app->request->get('form_id'));
                \FluentForm\App\Modules\Acl\Acl::verify('fluentform_forms_manager', $formId);
                require_once(__DIR__ . '/libs/CSVParser/CSVParser.php');
                $cs = new \FluentFormPro\Components\ChainedSelect\ChainedSelectDataSourceManager(
                    $app
                );
                $cs->saveDataSource(new CSVParser);
            });

            // Chained Select Ajax Hook On Backend
            $app->addAdminAjaxAction('fluentform_chained_select_remove_ds', function () use ($app) {
                $formId = intval($app->request->get('form_id'));
                \FluentForm\App\Modules\Acl\Acl::verify('fluentform_forms_manager', $formId);
                $cs = new \FluentFormPro\Components\ChainedSelect\ChainedSelectDataSourceManager(
                    $app
                );
                $cs->deleteDataSource();
            });

            // Chained Select Ajax Hook On Frontend
            $getChainedSelectOptions = function () use ($app) {
                require_once(__DIR__ . '/libs/CSVParser/CSVParser.php');
                $cs = new \FluentFormPro\Components\ChainedSelect\ChainedSelectDataSourceManager(
                    $app
                );
                $cs->getOptionsForNextField(new CSVParser);
            };

            $app->addAdminAjaxAction('fluentform_get_chained_select_options', $getChainedSelectOptions);
            $app->addPublicAjaxAction('fluentform_get_chained_select_options', $getChainedSelectOptions);

            $app->addPublicAjaxAction('fluentform_get_chained_select_options', $getChainedSelectOptions);

            $app->addAdminAjaxAction('fluentform_apply_coupon', function () {
                (new \FluentFormPro\Payments\Classes\CouponController())->validateCoupon();
            });

            $app->addPublicAjaxAction('fluentform_apply_coupon', function () {
                (new \FluentFormPro\Payments\Classes\CouponController())->validateCoupon();
            });
            $app->addAdminAjaxAction('fluentform_paypal_delayed_check', function () use ($app) {
                (new \FluentFormPro\Payments\PaymentMethods\PayPal\PayPalProcessor())->isPaid();
            });
            $app->addPublicAjaxAction('fluentform_paypal_delayed_check', function () use ($app) {
                (new \FluentFormPro\Payments\PaymentMethods\PayPal\PayPalProcessor())->isPaid();
            });

        }

        /**
         * Register pro element's rendering hooks
         * @return void
         */
        protected function registerProElementsRenderingHook($app)
        {
            (new \FluentFormPro\Components\UploaderSettings())->boot();

            add_action('fluentform/render_item_input_file', function () {
                $class = new \FluentFormPro\Components\Uploader;
                call_user_func_array(array($class, 'compile'), func_get_args());
            }, 10, 2);

            add_action('fluentform/render_item_input_image', function () {
                $class = new \FluentFormPro\Components\Uploader;
                call_user_func_array(array($class, 'compile'), func_get_args());
            }, 10, 2);

            add_action('fluentform/render_item_input_repeat', function () {
                $class = new \FluentFormPro\Components\Repeater;
                call_user_func_array(array($class, 'compile'), func_get_args());
            }, 10, 2);

            add_action('fluentform/render_item_step_start', function () {
                $class = new \FluentFormPro\Components\FormStep;
                call_user_func_array(array($class, 'stepStart'), func_get_args());
            }, 10, 2);

            add_action('fluentform/render_item_form_step', function () {
                $class = new \FluentFormPro\Components\FormStep;
                call_user_func_array(array($class, 'compile'), func_get_args());
            }, 10, 2);

            add_action('fluentform/render_item_step_end', function ($data, $form) {
                $class = new \FluentFormPro\Components\FormStep;
                call_user_func_array(array($class, 'stepEnd'), [$data, $form]);
            }, 10, 2);

            add_action('fluentform/render_item_shortcode', function () {
                $class = new \FluentFormPro\Components\ShortCode;
                call_user_func_array(array($class, 'compile'), func_get_args());
            }, 10, 2);

            add_action('fluentform/render_item_action_hook', function () {
                $class = new \FluentFormPro\Components\ActionHook;
                call_user_func_array(array($class, 'compile'), func_get_args());
            }, 10, 2);
        }

        /**
         * Activate disabled components
         *
         * @param array $disabled
         *
         * @return array
         */
        public function filterDisabledComponents($disabled)
        {
            foreach ($disabled as $key => &$value) {
                if (in_array($key, $this->proElements)) {
                    $value['disabled'] = false;
                }
            }
            return $disabled;
        }

        /**
         * Register 3rd party Integrations on Form Submit
         * @return void
         */
        public function registerAddOns($app)
        {
            foreach ($this->addOns as $addOn) {
                $class = "{$addOn}\Bootstrap";
                new $class($app);
            }
        }


        public function registerScripts()
        {
            $cssSource = FLUENTFORMPRO_DIR_URL . 'public/libs/intl-tel-input/css/intlTelInput.min.css';
            if (is_rtl()) {
                $cssSource = FLUENTFORMPRO_DIR_URL . 'public/libs/intl-tel-input/css/intlTelInput-rtl.min.css';
            }

            wp_register_style('intlTelInput', $cssSource, [], '24.2.0');
            wp_register_style('pickr', FLUENTFORMPRO_DIR_URL . 'public/libs/pickr/themes/monolith.min.css', [], '1.5.1');
            wp_register_script('intlTelInput', FLUENTFORMPRO_DIR_URL . 'public/libs/intl-tel-input/js/intlTelInputWithUtils.min.js', [], '25.5.2', true);
            wp_register_style('lity', FLUENTFORMPRO_DIR_URL . 'public/libs/lity/lity.min.css', [], '2.3.1');
            wp_register_script('lity', FLUENTFORMPRO_DIR_URL . 'public/libs/lity/lity.min.js', ['jquery'], '2.3.1', true);

            wp_register_script(
                'fluentform-uploader-jquery-ui-widget',
                FLUENTFORMPRO_DIR_URL . 'public/libs/jQuery-File-Upload-10.32.0/js/vendor/jquery.ui.widget.js',
                array('jquery'),
                true,
                true
            );

            wp_register_script(
                'fluentform-uploader-iframe-transport',
                FLUENTFORMPRO_DIR_URL . 'public/libs/jQuery-File-Upload-10.32.0/js/jquery.iframe-transport.js',
                array('fluentform-uploader-jquery-ui-widget'),
                true,
                true
            );

            wp_register_script(
                'fluentform-uploader',
                FLUENTFORMPRO_DIR_URL . 'public/libs/jQuery-File-Upload-10.32.0/js/jquery.fileupload.js',
                array('fluentform-uploader-iframe-transport'),
                true,
                true
            );

            wp_register_style(
                'fluentform-cropperjs-style',
                FLUENTFORMPRO_DIR_URL . 'public/libs/cropperjs/cropper.min.css',
                array(),
                '1.6.2'
            );

            wp_register_script(
                'fluentform-cropperjs',
                FLUENTFORMPRO_DIR_URL . 'public/libs/cropperjs/cropper.min.js',
                array(),
                '1.6.2',
                true
            );

            wp_register_script(
                'pickr',
                FLUENTFORMPRO_DIR_URL . 'public/libs/pickr/pickr.min.js',
                array(),
                true,
                true
            );
            //beaver builder editor page tiny mce editor conflict fix
            if (class_exists('FLBuilderModel') && FLBuilderModel::is_builder_active()) {
                wp_deregister_script('fluentform-uploader-jquery-ui-widget');
            }
        }
    }

    /**
     * Plugin init hook
     * @return void
     */
    add_action('init', function () {

        load_plugin_textdomain(
            'fluentformpro', false, dirname(plugin_basename(__FILE__)) . '/resources/languages'
        );

        (new FluentFormPro)->boot();
    });

    register_activation_hook(__FILE__, function ($siteWide) {
        \FluentFormPro\Payments\Migrations\Migration::run($siteWide);
        \FluentFormPro\classes\DraftSubmissionsManager::migrate();
        \FluentFormPro\classes\SharePage\FormPrettyUrlService::registerRewriteRules();
        flush_rewrite_rules(false);
    });

    add_action('plugins_loaded', function () {
        if (defined('FLUENTFORM')) {
            include plugin_dir_path(__FILE__) . 'libs/ff_plugin_updater/ff-fluentform-pro-update.php';
        }
    });
}

/**
* Registers a custom integration in AffiliateWP
**/
add_filter('affwp_extended_integrations', function ($integrations) {
    // Register the custom integration
    $integrations['fluentforms'] = [
        'name'     => 'Fluent Forms',
        'class'    => '\FluentFormPro\Integrations\AffiliateWP\AffiliateWPFF',
        'file'     => FLUENTFORMPRO_DIR_PATH . 'src/Integrations/AffiliateWP/AffiliateWPFF.php'
    ];

    return $integrations;
});

add_filter('weglot_ajax_no_translate', function ($ajaxAction) {
    $ajaxAction[] = 'fluentform_file_upload';
    return $ajaxAction;
}, 10, 1);

add_action('fluentform_do_email_report_scheduled_tasks', function() {
    \FluentFormPro\Uploader::removeOldTempFiles();
});

