<?php

namespace FluentFormPro\Payments;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\Classes\PaymentAction;
use FluentFormPro\Payments\Classes\PaymentEntries;
use FluentFormPro\Payments\Classes\PaymentReceipt;
use FluentFormPro\Payments\Components\Coupon;
use FluentFormPro\Payments\Components\CustomPaymentComponent;
use FluentFormPro\Payments\Components\ItemQuantity;
use FluentFormPro\Payments\Components\MultiPaymentComponent;
use FluentFormPro\Payments\Components\PaymentMethods;
use FluentFormPro\Payments\Components\PaymentSummaryComponent;
use FluentFormPro\Payments\Components\Subscription;
use FluentFormPro\Payments\Orders\OrderData;
use FluentFormPro\Payments\PaymentMethods\Mollie\MollieHandler;
use FluentFormPro\Payments\PaymentMethods\Offline\OfflineHandler;
use FluentFormPro\Payments\PaymentMethods\PayPal\PayPalHandler;
use FluentFormPro\Payments\PaymentMethods\Paystack\PaystackHandler;
use FluentFormPro\Payments\PaymentMethods\RazorPay\RazorPayHandler;
use FluentFormPro\Payments\PaymentMethods\Square\SquareHandler;
use FluentFormPro\Payments\PaymentMethods\Stripe\Components\StripeInline;
use FluentFormPro\Payments\PaymentMethods\Stripe\ConnectConfig;
use FluentFormPro\Payments\PaymentMethods\Stripe\StripeHandler;
use FluentFormPro\Payments\PaymentMethods\Stripe\StripeSettings;
use FluentFormPro\Payments\PaymentMethods\Square\Components\SquareInline;
use FluentFormPro\Payments\PaymentMethods\Paddle\PaddleHandler;
use FluentFormPro\Payments\PaymentMethods\AuthorizeNet\AuthorizeNetHandler;

class PaymentHandler
{
    public function init()
    {
        if (PaymentHelper::isPaymentCompatible()) {
            $this->initNew();
        } else {
            $this->initOld();
        }
    }

    public function initNew()
    {
        add_filter('fluentform/global_settings_payment_sub_menu_items', [$this, 'pushGlobalSettingsNew'], 1);

        add_filter('fluentform/payment_config', [$this, 'pushPaymentConfig'], 1, 2);

        add_action('fluentform/global_settings_component_settings', [$this, 'renderPaymentSettingsNew'], 9);

        add_action('fluentform/handle_payment_ajax_endpoint', [$this, 'handleAjaxEndpointsNew']);

        add_action('fluentform/render_order_claim_result', [$this, 'renderOrderClaimResult'], 10, 2);

        if (!$this->isEnabled()) {
            return;
        }

        (new PayPalHandler())->init();
        (new OfflineHandler())->init();
        (new MollieHandler())->init();
        (new RazorPayHandler())->init();
        (new PaystackHandler())->init();
        (new SquareHandler())->init();
        (new PaddleHandler())->init();
        (new AuthorizeNetHandler())->init();

        new Coupon();
        new SquareInline();

        add_action('fluentform/rendering_payment_form', function ($form) {
            if (PaymentHelper::isPaymentScriptLoadFromFree()) {
                return;
            }
            wp_enqueue_script('fluentformpro-payment-handler', FLUENTFORMPRO_DIR_URL . 'public/js/payment_handler_pro.js', array('jquery'), FLUENTFORMPRO_VERSION, true);
        });
    }

    /**
     * Render the guest-order claim result (success/invalid) on a branded FluentForm
     * landing page, matching the Double Opt-in confirmation experience. Fired by free's
     * handleOrderClaimRequest(); loadPublicView() echoes the page and exits.
     */
    public function renderOrderClaimResult($ok, $message)
    {
        $sharePage = new \FluentFormPro\classes\SharePage\SharePage();
        $settings = $sharePage->getSettings(0);

        $heading = $ok
            ? __('You\'re all set', 'fluentformpro')
            : __('This link can\'t be used', 'fluentformpro');
        $title = $ok
            ? __('Orders linked', 'fluentformpro')
            : __('Link not valid', 'fluentformpro');

        $content = '<div style="text-align:center;max-width:520px;margin:0 auto;">'
            . '<h1 style="font-size:24px;margin:0 0 12px;">' . esc_html($heading) . '</h1>'
            . '<p style="font-size:16px;line-height:1.6;opacity:.85;">' . esc_html($message) . '</p>'
            . '</div>';

        $landingVars = [
            'settings'        => $settings,
            'title'           => $title,
            'form_id'         => 0,
            'entry'           => null,
            'form'            => null,
            'bg_color'        => $settings['custom_color'],
            'landing_content' => $content,
            'has_header'      => false,
            'isEmbeded'       => false,
        ];
        $landingVars = apply_filters('fluentform/submission_vars', $landingVars, 0);

        $sharePage->loadPublicView($landingVars);
    }

    // Deprecated in 6.2.14; remove in 6.2.15 with the legacy Pro payment stack.
    public function initOld()
    {
    
        $message = '<div style="padding: 15px 5px;" ><b>' . __('Heads UP: ',
                'fluentformpro') . '</b>' . __('Fluent Forms Core Plugin needs to be updated to the latest version.',
                'fluentformpro') . __(' Legacy payment compatibility will be removed in Fluent Forms Pro 6.2.15.',
                'fluentformpro') . '<a href="' . admin_url('plugins.php?s=fluent+forms&plugin_status=all&force-check=1') . '">' . __(' Please update Fluent Forms to latest version.',
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

        add_filter('fluentform/global_settings_components', [$this, 'pushGlobalSettings'], 1, 1);

        add_action('fluentform/global_settings_component_payment_settings', [$this, 'renderPaymentSettings']);

        add_action('wp_ajax_fluentform_handle_payment_ajax_endpoint', [$this, 'handleAjaxEndpoints']);

        if (!$this->isEnabled()) {
            return;
        }

        add_filter('fluentform/show_payment_entries', '__return_true');

        add_filter('fluentform/form_settings_menu', array($this, 'maybeAddPaymentSettings'), 10, 2);
        // Let's load Payment Methods here
        (new StripeHandler())->init();
        (new PayPalHandler())->init();
        (new OfflineHandler())->init();
        (new MollieHandler())->init();
        (new RazorPayHandler())->init();
        (new PaystackHandler())->init();
        (new SquareHandler())->init();
        (new PaddleHandler())->init();
        (new AuthorizeNetHandler())->init();

        // Let's load the payment method component here
        new MultiPaymentComponent();
        new Subscription();
        new CustomPaymentComponent();
        new ItemQuantity();
        new PaymentMethods();
        new PaymentSummaryComponent();
        new Coupon();
        new StripeInline();
        new SquareInline();

        add_action('fluentform/before_insert_payment_form', array($this, 'maybeHandlePayment'), 10, 3);

        add_filter('fluentform/submission_order_data', function ($data, $submission) {
            return OrderData::getSummary($submission, $submission->form);
        }, 10, 2);

        add_filter('fluentform/entries_vars', function ($vars, $form) {
            if ($form->has_payment) {
                $vars['has_payment'] = $form->has_payment;
                $vars['currency_config'] = PaymentHelper::getCurrencyConfig($form->id);
                $vars['currency_symbols'] = PaymentHelper::getCurrencySymbols();
                $vars['payment_statuses'] = PaymentHelper::getPaymentStatuses();
            }
            return $vars;
        }, 10, 2);

        add_filter(
            'fluentform/submission_labels',
            [$this, 'modifySingleEntryLabels'],
            10,
            3
        );


        add_filter('fluentform/all_entry_labels_with_payment', array($this, 'modifySingleEntryLabels'), 10, 3);

        add_action('fluentform/rendering_payment_form', function ($form) {
            wp_enqueue_script('fluentform-payment-handler', FLUENTFORMPRO_DIR_URL . 'public/js/payment_handler.js', array('jquery'), FLUENTFORM_VERSION, true);
            
            wp_enqueue_style(
                'fluentform-payment-skin',
                FLUENTFORMPRO_DIR_URL . 'public/css/payment_skin.css',
                array(),
                FLUENTFORM_VERSION
            );

            wp_localize_script('fluentform-payment-handler', 'fluentform_payment_config', [
                'i18n' => [
                    'item'                     => __('Item', 'fluentformpro'),
                    'price'                    => __('Price', 'fluentformpro'),
                    'qty'                      => __('Qty', 'fluentformpro'),
                    'line_total'               => __('Line Total', 'fluentformpro'),
                    'total'                    => __('Total', 'fluentformpro'),
                    'not_found'                => __('No payment item selected yet', 'fluentformpro'),
                    'discount:'                => __('Discount:', 'fluentformpro'),
                    'processing_text'          => __('Processing payment. Please wait...', 'fluentformpro'),
                    'confirming_text'          => __('Confirming payment. Please wait...', 'fluentformpro'),
                    // translators: %s is the subscription plan name
                    'Signup Fee for %s'        => __('Signup Fee for %s', 'fluentformpro'),
                    // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- JS sprintf uses %1s/%2s format, not PHP %1$s/%2$s
                    'Signup Fee for %1s - %2s' => __('Signup Fee for %1s - %2s', 'fluentformpro'),
                ]
            ]);

            $secretKey = apply_filters_deprecated(
                'fluentform-payment_stripe_publishable_key',
                [
                    StripeSettings::getPublishableKey($form->id),
                    $form->id
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/payment_stripe_publishable_key',
                'Use fluentform/payment_stripe_publishable_key instead of fluentform-payment_stripe_publishable_key.'
            );

            $publishableKey = apply_filters('fluentform/payment_stripe_publishable_key', $secretKey, $form->id);

            $stripeCustomCss = [
                'styles' => [
                    'base' => [
                        'backgroundColor' => 'white',
                        'color'           => '#32325d',
                        'fontFamily'      => "-apple-system, \"system-ui\", \"Segoe UI\", Roboto, Oxygen-Sans, Ubuntu, Cantarell, \"Helvetica Neue\", sans-serif",
                        'fontSize'        => '14px',
                        'fontSmoothing'   => 'antialiased',
                        'iconColor'       => '#32325d',
                        'textDecoration'  => 'none',
                        '::placeholder'   => [
                            'color'=> "#aab7c4"
                        ],
                        ":focus" => [
                            'backgroundColor' => 'white',
                            'color'           => '#32325d',
                            'fontFamily'      => "-apple-system, \"system-ui\", \"Segoe UI\", Roboto, Oxygen-Sans, Ubuntu, Cantarell, \"Helvetica Neue\", sans-serif",
                            'fontSize'        => '14px',
                            'fontSmoothing'   => 'antialiased',
                            'iconColor'       => '#32325d',
                            'textDecoration'  => 'none',
                        ],
                    ],
                    'invalid' => [
                        'color'     => "#fa755a",
                        'iconColor' => "#fa755a"
                    ]
                ]
            ];

            wp_localize_script('fluentform-payment-handler', 'fluentform_payment_config_' . $form->id, [
                'currency_settings' => PaymentHelper::getCurrencyConfig($form->id),
                'stripe'            => [
                    'publishable_key' => $publishableKey,
                    'inlineConfig'    => PaymentHelper::getStripeInlineConfig($form->id),
                    'custom_style'    => apply_filters('fluentform/stripe_inline_custom_css', $stripeCustomCss, $form->id),
                    'locale'          => 'en'
                ],
                'square' => [
                    'inline_config'    => PaymentHelper::getSquareInlineConfig($form->id),
                ],
                'stripe_app_info'   => array(
                    'name'       => 'Fluent Forms',
                    'version'    => FLUENTFORMPRO_VERSION,
                    'url'        => site_url(),
                    'partner_id' => 'pp_partner_FN62GfRLM2Kx5d'
                )
            ]);

        });

        if (isset($_GET['fluentform_payment']) && isset($_GET['payment_method'])) {
            add_action('wp', function () {
                $data = fluentFormSanitizer($_GET, null, 'sanitize_text_field');

                $type = sanitize_text_field($_GET['fluentform_payment']);

                if ($type == 'view' && $route = ArrayHelper::get($data, 'route')) {
                    do_action_deprecated(
                        'fluent_payment_view_' . $route,
                        [
                            $data
                        ],
                        FLUENTFORM_FRAMEWORK_UPGRADE,
                        'fluentform/payment_view_' . $route,
                        'Use fluentform/payment_view_' . $route . ' instead of fluent_payment_view_' . $route
                    );
                    do_action('fluentform/payment_view_' . $route, $data);
                }

                $this->validateFrameLessPage($data);
                $paymentMethod = sanitize_text_field($_GET['payment_method']);
                do_action_deprecated(
                    'fluent_payment_frameless_' . $paymentMethod,
                    [
                        $data
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/payment_frameless_' . $paymentMethod,
                    'Use fluentform/payment_frameless_' . $paymentMethod . ' instead of fluent_payment_frameless_' . $paymentMethod
                );
                do_action('fluentform/payment_frameless_' . $paymentMethod, $data);
            });
        }

        if (isset($_REQUEST['fluentform_payment_api_notify'])) {
            add_action('wp', function () {
                $paymentMethod = sanitize_text_field($_REQUEST['payment_method']);
                do_action_deprecated(
                    'fluentform_ipn_endpoint_' . $paymentMethod,
                    [
                    ],
                    FLUENTFORM_FRAMEWORK_UPGRADE,
                    'fluentform/ipn_endpoint_' . $paymentMethod,
                    'Use fluentform/ipn_endpoint_' . $paymentMethod . ' instead of fluentform_ipn_endpoint_' . $paymentMethod
                );
                do_action('fluentform/ipn_endpoint_' . $paymentMethod);
            });
        }

        add_filter('fluentform/editor_vars', function ($vars) {
            $settings = PaymentHelper::getCurrencyConfig($vars['form_id']);
            $vars['payment_settings'] = $settings;
            $vars['has_payment_features'] = !!$settings;
            return $vars;
        });

        add_filter('fluentform/admin_i18n', array($this, 'paymentTranslations'), 10, 1);

        add_filter('fluentform/payment_smartcode', array($this, 'paymentReceiptView'), 10, 3);

        (new PaymentEntries())->init();

        /*
         * Transactions and subscriptions Shortcode
         */
        (new TransactionShortcodes())->init();

        add_filter(
            'fluentform/validate_input_item_subscription_payment_component',
            [$this, 'validateSubscriptionInputs'],
            10,
            3
        );

        add_filter(
            'fluentform/validate_input_item_multi_payment_component',
            [$this, 'validatePaymentInputs'],
            10,
            3
        );
        
        add_filter(
            'fluentform/validate_input_item_payment_method',
            [$this, 'validatePaymentMethod'],
            10,
            5
        );
    }

    public function pushPaymentConfig($paymentConfig, $formId)
    {
        $paymentConfig['square'] = [
            'inline_config' => PaymentHelper::getSquareInlineConfig($formId),
        ];

        return $paymentConfig;
    }

    public function pushGlobalSettingsNew($menus)
    {
        $menus[] = [
            'title'     => 'Coupons',
            'hash'      => 'payments/coupons',
        ];
        return $menus;
    }

    public function pushGlobalSettings($components)
    {
        $components['payment_settings'] = [
            'hash'  => '',
            'title' => __('Payment Settings', 'fluentformpro'),
            'query' => [
                'component' => 'payment_settings'
            ],
            'sub_menu'=>[
               [
                   'name' => __('Settings', 'fluentformpro'),
                   'path' => '#/',
                   'query' => [
                       'component' => 'payment_settings/'
                   ]
               ],
               [
                   'name' => __('Payment Methods', 'fluentformpro'),
                   'path' => '#/payment_methods',
                   'query' => [
                       'component' => 'payment_settings/'
                   ]
               ],
               [
                   'name' => __('Coupons', 'fluentformpro'),
                   'path' => '#/coupons',
                   'query' => [
                       'component' => 'payment_settings/'
                   ]
               ],
            ]
        ];
        return $components;
    }

    public function renderPaymentSettingsNew()
    {
        wp_enqueue_script('ff-payment-settings-pro', FLUENTFORMPRO_DIR_URL . 'public/js/payment-settings-pro.js', ['jquery'], FLUENTFORMPRO_VERSION, true);
        wp_enqueue_media();
    }

    public function renderPaymentSettings()
    {

        if (isset($_GET['ff_stripe_connect'])) {
            $data = ArrayHelper::only($_GET, ['ff_stripe_connect', 'mode', 'state', 'code']);
            ConnectConfig::verifyAuthorizeSuccess($data);
        }

        $paymentSettings = PaymentHelper::getPaymentSettings();
        $isSettingsAvailable = !!get_option('__fluentform_payment_module_settings');

        $nav = 'general';

        if (isset($_REQUEST['nav'])) {
            $nav = sanitize_text_field($_REQUEST['nav']);
        }

        $paymentMethods = apply_filters_deprecated(
            'fluentformpro_available_payment_methods',
            [
                []
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/available_payment_methods',
            'Use fluentform/available_payment_methods instead of fluentformpro_available_payment_methods.'
        );

        $globalSettings = apply_filters_deprecated(
            'fluentformpro_payment_methods_global_settings',
            [
                []
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_methods_global_settings',
            'Use fluentform/payment_methods_global_settings instead of fluentformpro_payment_methods_global_settings.'
        );

        $data = [
            'is_setup'                  => $isSettingsAvailable,
            'general'                   => $paymentSettings,
            'payment_methods'           => apply_filters('fluentform/available_payment_methods', $paymentMethods),
            'available_payment_methods' => apply_filters('fluentform/payment_methods_global_settings', $globalSettings),
            'currencies'                => PaymentHelper::getCurrencies(),
            'active_nav'                => $nav,
            'stripe_webhook_url'        => add_query_arg([
                'fluentform_payment_api_notify' => '1',
                'payment_method'                => 'stripe'
            ], site_url('index.php')),
            'paypal_webhook_url'        => add_query_arg([
                'fluentform_payment_api_notify' => '1',
                'payment_method'                => 'paypal'
            ], site_url('index.php'))
        ];

        wp_enqueue_script('ff-payment-settings', FLUENTFORMPRO_DIR_URL . 'public/js/payment-settings.js', ['jquery'], FLUENTFORMPRO_VERSION, true);
        wp_enqueue_style('ff-payment-settings', FLUENTFORMPRO_DIR_URL . 'public/css/payment_settings.css', [], FLUENTFORMPRO_VERSION);

        wp_enqueue_media();

        wp_localize_script('ff-payment-settings', 'ff_payment_settings', $data);

        echo '<div id="ff-payment-settings"></div>';
    }

    public function handleAjaxEndpointsNew($route)
    {
        (new AjaxEndpoints())->handleEndpoint($route);
    }

    public function handleAjaxEndpoints()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified by Acl::verify()
        $route = isset($_REQUEST['route']) ? sanitize_text_field(wp_unslash($_REQUEST['route'])) : '';
        $paymentMutationRoutes = [
            'update_transaction',
            'cancel_subscription'
        ];
        $formSettingRoutes = [
            'get_form_settings',
            'save_form_settings'
        ];

        if (in_array($route, $paymentMutationRoutes, true)) {
            Acl::verify('fluentform_manage_payments', $this->resolveRouteFormId($route));
        } elseif (in_array($route, $formSettingRoutes, true)) {
            Acl::verify('fluentform_forms_manager', $this->resolveRouteFormId($route));
        } else {
            Acl::verify('fluentform_settings_manager');
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended -- End of AJAX endpoint nonce verification by Acl::verify()

        (new AjaxEndpoints())->handleEndpoint($route);
    }

    private function resolveRouteFormId($route)
    {
        switch ($route) {
            case 'get_form_settings':
            case 'save_form_settings':
                $formId = isset($_REQUEST['form_id']) ? absint(wp_unslash($_REQUEST['form_id'])) : 0;
                if (!$formId) {
                    wp_send_json_error([
                        'message' => __('Invalid form id.', 'fluentformpro')
                    ], 422);
                }
                return $formId;
            case 'update_transaction':
                return $this->resolveTransactionFormId();
            case 'cancel_subscription':
                return $this->resolveSubscriptionFormId();
            default:
                return null;
        }
    }

    private function resolveTransactionFormId()
    {
        $transactionData = isset($_REQUEST['transaction']) ? wp_unslash($_REQUEST['transaction']) : [];
        $transactionId = is_array($transactionData) ? absint(ArrayHelper::get($transactionData, 'id')) : 0;

        if (!$transactionId) {
            return -1;
        }

        $transaction = wpFluent()->table('fluentform_transactions')
            ->select(['form_id'])
            ->find($transactionId);

        return $transaction ? (int) $transaction->form_id : -1;
    }

    private function resolveSubscriptionFormId()
    {
        $subscriptionId = isset($_REQUEST['subscription_id']) ? absint(wp_unslash($_REQUEST['subscription_id'])) : 0;

        if (!$subscriptionId) {
            return -1;
        }

        $subscription = wpFluent()->table('fluentform_subscriptions')
            ->select(['form_id'])
            ->find($subscriptionId);

        return $subscription ? (int) $subscription->form_id : -1;
    }

    public function maybeHandlePayment($insertData, $data, $form)
    {
        // Let's get selected Payment Method
        if (!FormFieldsParser::hasPaymentFields($form)) {
            return;
        }

        $paymentAction = new PaymentAction($form, $insertData, $data);

        if (!$paymentAction->getSubscriptionItems() && !$paymentAction->getCalculatedAmount()) {
            return;
        }

        /*
         * We have to check if
         * 1. has payment method
         * 2. if user selected payment method
         * 3. or maybe has a conditional logic on it
         */
        if ($paymentAction->isConditionPass()) {
            if (FormFieldsParser::hasElement($form, 'payment_method') &&
                !$paymentAction->selectedPaymentMethod
            ) {
                wp_send_json([
                    'errors' => [__('Sorry! No selected payment method found. Please select a valid payment method', 'fluentformpro')]
                ], 423);
            }
        }

        /*
         * Some Payment Gateway like Razorpay, Square not supported $subscriptionItems.
         * So we are providing filter hook to validate payment fields.
         */
        $errors = apply_filters(
            'fluentform/validate_payment_items_' . $paymentAction->selectedPaymentMethod,
            [],
            $paymentAction->getOrderItems(),
            $paymentAction->getSubscriptionItems(),
            $form
        );

        if ($errors) {
            wp_send_json([
                'errors' => $errors
            ], 423);
        }

        $paymentAction->draftFormEntry();
    }

    public function isEnabled()
    {
        $paymentSettings = PaymentHelper::getPaymentSettings();
        return $paymentSettings['status'] == 'yes';
    }

    public function modifySingleEntryLabels($labels, $submission, $form)
    {
        $formFields = FormFieldsParser::getPaymentFields($form);
        if ($formFields && is_array($formFields)) {
            $labels = ArrayHelper::except($labels, array_keys($formFields));
        }
        return $labels;
    }

    public function maybeAddPaymentSettings($menus, $formId)
    {
        $form = wpFluent()->table('fluentform_forms')->find($formId);
        if ($form->has_payment) {
            $menus = array_merge(array_slice($menus, 0, 1), array(
                'payment_settings' => [
                    'title' => __('Payment Settings', 'fluentformpro'),
                    'slug'  => 'form_settings',
                    'hash'  => 'payment_settings',
                    'route' => '/payment-settings',
                ]
            ), array_slice($menus, 1));
        }
        return $menus;
    }


    /**
     * @param $html     string
     * @param $property string
     * @param $instance ShortCodeParser
     * @return false|string
     */
    public function paymentReceiptView($html, $property, $instance)
    {
        $entry = $instance::getEntry();
        $receiptClass = new PaymentReceipt($entry);
        return $receiptClass->getItem($property);
    }

    private function validateFrameLessPage($data)
    {
        // We should verify the transaction hash from the URL
        $transactionHash = sanitize_text_field(ArrayHelper::get($data, 'transaction_hash'));
        $submissionId = intval(ArrayHelper::get($data, 'fluentform_payment'));
        if (!$submissionId) {
            die('Validation Failed');
        }

        if ($transactionHash) {
            $transaction = wpFluent()->table('fluentform_transactions')
                ->where('submission_id', $submissionId)
                ->where('transaction_hash', $transactionHash)
                ->first();
            if ($transaction) {
                return true;
            }

            die('Transaction hash is invalid');
        }

        $uid = sanitize_text_field(ArrayHelper::get($data, 'entry_uid'));
        if (!$uid) {
            die('Validation Failed');
        }

        $originalUid = Helper::getSubmissionMeta($submissionId, '_entry_uid_hash');

        if ($originalUid != $uid) {
            die(__('Transaction UID is invalid', 'fluentformpro')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return true;
    }

    public function paymentTranslations($i18n)
    {
        $paymentI18n = array(
            'Order Details' => __('Order Details', 'fluentformpro'),
            'Product' => __('Product', 'fluentformpro'),
            'Qty' => __('Qty', 'fluentformpro'),
            'Unit Price' => __('Unit Price', 'fluentformpro'),
            'Total' => __('Total', 'fluentformpro'),
            'Sub-Total' => __('Sub-Total', 'fluentformpro'),
            'Discount' => __('Discount', 'fluentformpro'),
            'Price' => __('Price', 'fluentformpro'),
            'Payment Details' => __('Payment Details', 'fluentformpro'),
            'From Subscriptions' => __('From Subscriptions', 'fluentformpro'),
            'Card Last 4' => __('Card Last 4', 'fluentformpro'),
            'Payment Total' => __('Payment Total', 'fluentformpro'),
            'Payment Status' => __('Payment Status', 'fluentformpro'),
            'Transaction ID' => __('Transaction ID', 'fluentformpro'),
            'Payment Method' => __('Payment Method', 'fluentformpro'),
            'Transaction' => __('Transaction', 'fluentformpro'),
            'Refunds' => __('Refunds', 'fluentformpro'),
            'Refund' => __('Refund', 'fluentformpro'),
            'at' => __('at', 'fluentformpro'),
            'View' => __('View', 'fluentformpro'),
            'has been refunded via' => __('has been refunded via', 'fluentformpro'),
            'Note' => __('Note', 'fluentformpro'),
            'Edit Transaction' => __('Edit Transaction', 'fluentformpro'),
            'Billing Name' => __('Billing Name', 'fluentformpro'),
            'Billing Email' => __('Billing Email', 'fluentformpro'),
            'Billing Address' => __('Billing Address', 'fluentformpro'),
            'Shipping Address' => __('Shipping Address', 'fluentformpro'),
            'Reference ID' => __('Reference ID', 'fluentformpro'),
            'refunds-to-be-handled-from-provider-text' => __('Please note that, Actual Refund needs to be handled in your Payment Service Provider.', 'fluentformpro'),
            'Please Provide new refund amount only.' => __('Please Provide new refund amount only.', 'fluentformpro'),
            'Refund Note' => __('Refund Note', 'fluentformpro'),
            'Cancel' => __('Cancel', 'fluentformpro'),
            'Confirm' => __('Confirm', 'fluentformpro'),
        );
        return array_merge($i18n,$paymentI18n);
    }

    public function validateSubscriptionInputs($error, $field, $formData)
    {
        if (isset($formData[$field['name']])) {
            $subscriptionOptions = ArrayHelper::get($field, 'raw.settings.subscription_options', []);
            $selectedPlanIndex = $formData[$field['name']];
            $acceptedSubscriptionPlan = is_numeric($selectedPlanIndex) && in_array($selectedPlanIndex, array_keys($subscriptionOptions));
            if (!$acceptedSubscriptionPlan) {
                $error = __('This subscription plan is invalid', 'fluentformpro');
            }
            $selectedPlan = ArrayHelper::get($subscriptionOptions, $selectedPlanIndex, []);
            if ('yes' === ArrayHelper::get($selectedPlan, 'user_input')) {
                $userGivenValue = ArrayHelper::get($formData, "{$field['name']}_custom_$selectedPlanIndex");
                $userGivenValue = $userGivenValue ?: 0;
                $planMinValue = ArrayHelper::get($selectedPlan, 'user_input_min_value');
                if (!is_numeric($userGivenValue) || $userGivenValue < 0 || ($planMinValue && $userGivenValue < $planMinValue)) {
                    $error = __('This subscription plan value is invalid', 'fluentformpro');
                }
            }
        }

        return $error;
    }

    public function validatePaymentInputs($error, $field, $formData)
    {
        if (ArrayHelper::get($formData, $field['name'])) {
            $fieldType = ArrayHelper::get($field, 'raw.attributes.type');

            if (in_array($fieldType, ['radio', 'select', 'checkbox'])) {
                $pricingOptions = array_column(
                    ArrayHelper::get($field, 'raw.settings.pricing_options', []),
                    'label'
                );

                $pricingOptions = array_map('sanitize_text_field', $pricingOptions);

                if (in_array($fieldType, ['radio', 'select'])) {
                    $acceptedPaymentPlan = in_array($formData[$field['name']], $pricingOptions);
                } else {
                    $acceptedPaymentPlan = array_diff($formData[$field['name']], $pricingOptions);

                    $acceptedPaymentPlan = empty($acceptedPaymentPlan);
                }
    
                if (!$acceptedPaymentPlan) {
                    $error = __('This payment item is invalid', 'fluentformpro');
                }
            }
        }

        return $error;
    }
    
    public function validatePaymentMethod($error, $field, $formData, $fields, $form)
    {
        if ($selectedMethod = ArrayHelper::get($formData, $field['name'])) {
            $activeMethods = array_keys(PaymentHelper::getFormPaymentMethods($form->id));
            if (!in_array($selectedMethod, $activeMethods)) {
                $error = __('This payment method is invalid', 'fluentformpro');
            }
        }
        return $error;
    }
}
