<?php

namespace FluentFormPro\Payments;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentFormPro\Payments\PaymentMethods\Square\SquareSettings;

class PaymentHelper
{
    public static function isPaymentCompatible()
    {
        return version_compare(FLUENTFORM_VERSION, FLUENTFORM_MINIMUM_CORE_VERSION, '>=');
    }

    /**
     * maybe_unserialize() with objects disabled. Preferring the free Helper, but
     * falling back inline so an older free plugin without it still blocks object
     * instantiation. Preserves maybe_unserialize semantics: non-serialized data
     * passes through unchanged.
     */
    public static function safeUnserialize($data)
    {
        if (method_exists(Helper::class, 'safeUnserialize')) {
            return Helper::safeUnserialize($data);
        }
        if (is_serialized($data)) {
            return @unserialize(trim($data), ['allowed_classes' => false]);
        }

        return $data;
    }

    // TODO: Remove the fallback when the minimum Free version is 6.2.14.
    public static function isReversedPaymentStatus($status)
    {
        $ffHelper = '\FluentForm\App\Modules\Payments\PaymentHelper';

        if (method_exists($ffHelper, 'isReversedPaymentStatus')) {
            return $ffHelper::isReversedPaymentStatus($status);
        }

        $status = is_null($status) ? '' : (string) $status;

        if ('' === $status) {
            return false;
        }

        $reversedStatuses = apply_filters('fluentform/reversed_payment_statuses', [
            'refunded', 'partially-refunded', 'cancelled',
        ]);

        return in_array($status, $reversedStatuses, true);
    }

    public static function isPaymentScriptLoadFromFree()
    {
        return version_compare(FLUENTFORM_VERSION, '6.0.4', '>=');
    }

    public static function getFormCurrency($formId)
    {
        $settings = self::getFormSettings($formId, 'public');
        return $settings['currency'];
    }

    public static function formatMoney($amountInCents, $currency)
    {
        $currencySettings = self::getCurrencyConfig(false, $currency);
        $symbol = \html_entity_decode($currencySettings['currency_sign']);
        $position = $currencySettings['currency_sign_position'];
        $decimalSeparator = '.';
        $thousandSeparator = ',';
        if ($currencySettings['currency_separator'] != 'dot_comma') {
            $decimalSeparator = ',';
            $thousandSeparator = '.';
        }
        $decimalPoints = 2;
        if ((int) round($amountInCents) % 100 == 0 && $currencySettings['decimal_points'] == 0) {
            $decimalPoints = 0;
        }

        $amount = number_format($amountInCents / 100, $decimalPoints, $decimalSeparator, $thousandSeparator);

        if ('left' === $position) {
            return $symbol . $amount;
        } elseif ('left_space' === $position) {
            return $symbol . ' ' . $amount;
        } elseif ('right' === $position) {
            return $amount . $symbol;
        } elseif ('right_space' === $position) {
            return $amount . ' ' . $symbol;
        }
        return $amount;
    }

    public static function getFormSettings($formId, $scope = 'public')
    {
        static $cachedSettings = [];

        if (isset($cachedSettings[$scope . '_' . $formId])) {
            return $cachedSettings[$scope . '_' . $formId];
        }
    
    
        $defaults = [
            'currency'                       => '',
            'push_meta_to_stripe'            => 'no',
            'receipt_email'                  => self::getFormInput($formId,'input_email'),
            'customer_name'                  => self::getFormInput($formId,'input_name'),
            'customer_address'               => self::getFormInput($formId,'address'),
            'transaction_type'               => 'product',
            'stripe_checkout_methods'        => ['card'],
            'stripe_meta_data'               => [
                [
                    'item_value' => '',
                    'label'      => ''
                ]
            ],
            'stripe_account_type'            => 'global',
            'disable_stripe_payment_receipt' => 'no',
            'stripe_custom_config'           => [
                'payment_mode'    => 'live',
                'publishable_key' => '',
                'secret_key'      => ''
            ],
            'custom_paypal_id'               => '',
            'custom_paypal_mode'             => 'live',
            'paypal_account_type'            => 'global'
        ];
        $settings = Helper::getFormMeta($formId, '_payment_settings', []);
        $settings = wp_parse_args($settings, $defaults);
        if (empty($settings['receipt_email'])) {
            $settings['receipt_email'] = self::getFormInput($formId, 'input_email');
        }
        if (empty($settings['customer_name'])) {
            $name = self::getFormInput($formId, 'input_name');
            if (!empty($name)) {
                $settings['customer_name'] = sprintf('{inputs.%s}', $name);
            }
        }
        if (empty($settings['customer_address'])) {
            $settings['customer_address'] = self::getFormInput($formId, 'address');
        }
        
        $globalSettings = self::getPaymentSettings();

        if (!$settings['currency']) {
            $settings['currency'] = $globalSettings['currency'];
        }

        if ($scope == 'public') {
            $settings = wp_parse_args($settings, $globalSettings);
        }


        $cachedSettings[$scope . '_' . $formId] = $settings;

        return $settings;

    }

    public static function getCurrencyConfig($formId = false, $currency = false)
    {
        if ($formId) {
            $settings = self::getFormSettings($formId, 'public');
        } else {
            $settings = self::getPaymentSettings();
        }

        if ($currency) {
            $settings['currency'] = $currency;
        }

        $settings = ArrayHelper::only($settings, ['currency', 'currency_sign_position', 'currency_separator', 'decimal_points']);

        $settings['currency_sign'] = self::getCurrencySymbol($settings['currency']);
        return $settings;
    }

    public static function getPaymentSettings()
    {
        static $paymentSettings;
        if ($paymentSettings) {
            return $paymentSettings;
        }

        $paymentSettings = get_option('__fluentform_payment_module_settings');
        $defaults = [
            'status'                       => 'no',
            'currency'                     => 'USD',
            'currency_sign_position'       => 'left',
            'currency_separator'           => 'dot_comma',
            'decimal_points'               => "2",
            'business_name'                => '',
            'business_logo'                => '',
            'business_address'             => '',
            'debug_log'                    => 'no',
            'all_payments_page_id'         => '',
            'receipt_page_id'              => '',
            'user_can_manage_subscription' => 'yes'
        ];

        $paymentSettings = wp_parse_args($paymentSettings, $defaults);

        return $paymentSettings;
    }

    public static function updatePaymentSettings($data)
    {
        $existingSettings = self::getPaymentSettings();
        $settings = wp_parse_args($data, $existingSettings);
        update_option('__fluentform_payment_module_settings', $settings, 'yes');

        return self::getPaymentSettings();
    }

    /**
     * https://support.stripe.com/questions/which-currencies-does-stripe-support
     */
    public static function getCurrencies()
    {
        $currencies = [
            'AED' => __('United Arab Emirates Dirham', 'fluentformpro'),
            'AFN' => __('Afghan Afghani', 'fluentformpro'),
            'ALL' => __('Albanian Lek', 'fluentformpro'),
            'AMD' => __('Armenian Dram', 'fluentformpro'),
            'ANG' => __('Netherlands Antillean Gulden', 'fluentformpro'),
            'AOA' => __('Angolan Kwanza', 'fluentformpro'),
            'ARS' => __('Argentine Peso','fluentformpro'), // non amex
            'AUD' => __('Australian Dollar', 'fluentformpro'),
            'AWG' => __('Aruban Florin', 'fluentformpro'),
            'AZN' => __('Azerbaijani Manat', 'fluentformpro'),
            'BAM' => __('Bosnia & Herzegovina Convertible Mark', 'fluentformpro'),
            'BBD' => __('Barbadian Dollar', 'fluentformpro'),
            'BDT' => __('Bangladeshi Taka', 'fluentformpro'),
            'BIF' => __('Burundian Franc', 'fluentformpro'),
            'BGN' => __('Bulgarian Lev', 'fluentformpro'),
            'BMD' => __('Bermudian Dollar', 'fluentformpro'),
            'BND' => __('Brunei Dollar', 'fluentformpro'),
            'BOB' => __('Bolivian Boliviano', 'fluentformpro'),
            'BRL' => __('Brazilian Real', 'fluentformpro'),
            'BSD' => __('Bahamian Dollar', 'fluentformpro'),
            'BWP' => __('Botswana Pula', 'fluentformpro'),
            'BZD' => __('Belize Dollar', 'fluentformpro'),
            'CAD' => __('Canadian Dollar', 'fluentformpro'),
            'CDF' => __('Congolese Franc', 'fluentformpro'),
            'CHF' => __('Swiss Franc', 'fluentformpro'),
            'CLP' => __('Chilean Peso', 'fluentformpro'),
            'CNY' => __('Chinese Renminbi Yuan', 'fluentformpro'),
            'COP' => __('Colombian Peso', 'fluentformpro'),
            'CRC' => __('Costa Rican Colón', 'fluentformpro'),
            'CVE' => __('Cape Verdean Escudo', 'fluentformpro'),
            'CZK' => __('Czech Koruna', 'fluentformpro'),
            'DJF' => __('Djiboutian Franc', 'fluentformpro'),
            'DKK' => __('Danish Krone', 'fluentformpro'),
            'DOP' => __('Dominican Peso', 'fluentformpro'),
            'DZD' => __('Algerian Dinar', 'fluentformpro'),
            'EGP' => __('Egyptian Pound', 'fluentformpro'),
            'ETB' => __('Ethiopian Birr', 'fluentformpro'),
            'EUR' => __('Euro', 'fluentformpro'),
            'FJD' => __('Fijian Dollar', 'fluentformpro'),
            'FKP' => __('Falkland Islands Pound', 'fluentformpro'),
            'GBP' => __('British Pound', 'fluentformpro'),
            'GEL' => __('Georgian Lari', 'fluentformpro'),
            'GHS' => __('Ghanaian Cedi', 'fluentformpro'),
            'GIP' => __('Gibraltar Pound', 'fluentformpro'),
            'GMD' => __('Gambian Dalasi', 'fluentformpro'),
            'GNF' => __('Guinean Franc', 'fluentformpro'),
            'GTQ' => __('Guatemalan Quetzal', 'fluentformpro'),
            'GYD' => __('Guyanese Dollar', 'fluentformpro'),
            'HKD' => __('Hong Kong Dollar', 'fluentformpro'),
            'HNL' => __('Honduran Lempira', 'fluentformpro'),
            'HRK' => __('Croatian Kuna', 'fluentformpro'),
            'HTG' => __('Haitian Gourde', 'fluentformpro'),
            'HUF' => __('Hungarian Forint', 'fluentformpro'),
            'IDR' => __('Indonesian Rupiah', 'fluentformpro'),
            'ILS' => __('Israeli New Sheqel', 'fluentformpro'),
            'INR' => __('Indian Rupee', 'fluentformpro'),
            'ISK' => __('Icelandic Króna', 'fluentformpro'),
            'JMD' => __('Jamaican Dollar', 'fluentformpro'),
            'JPY' => __('Japanese Yen', 'fluentformpro'),
            'KES' => __('Kenyan Shilling', 'fluentformpro'),
            'KGS' => __('Kyrgyzstani Som', 'fluentformpro'),
            'KHR' => __('Cambodian Riel', 'fluentformpro'),
            'KMF' => __('Comorian Franc', 'fluentformpro'),
            'KRW' => __('South Korean Won', 'fluentformpro'),
            'KYD' => __('Cayman Islands Dollar', 'fluentformpro'),
            'KZT' => __('Kazakhstani Tenge', 'fluentformpro'),
            'LAK' => __('Lao Kip', 'fluentformpro'),
            'LBP' => __('Lebanese Pound', 'fluentformpro'),
            'LKR' => __('Sri Lankan Rupee', 'fluentformpro'),
            'LRD' => __('Liberian Dollar', 'fluentformpro'),
            'LSL' => __('Lesotho Loti', 'fluentformpro'),
            'MAD' => __('Moroccan Dirham', 'fluentformpro'),
            'MDL' => __('Moldovan Leu', 'fluentformpro'),
            'MGA' => __('Malagasy Ariary', 'fluentformpro'),
            'MKD' => __('Macedonian Denar', 'fluentformpro'),
            'MNT' => __('Mongolian Tögrög', 'fluentformpro'),
            'MOP' => __('Macanese Pataca', 'fluentformpro'),
            'MRO' => __('Mauritanian Ouguiya', 'fluentformpro'),
            'MUR' => __('Mauritian Rupee', 'fluentformpro'),
            'MVR' => __('Maldivian Rufiyaa', 'fluentformpro'),
            'MWK' => __('Malawian Kwacha', 'fluentformpro'),
            'MXN' => __('Mexican Peso', 'fluentformpro'),
            'MYR' => __('Malaysian Ringgit', 'fluentformpro'),
            'MZN' => __('Mozambican Metical', 'fluentformpro'),
            'NAD' => __('Namibian Dollar', 'fluentformpro'),
            'NGN' => __('Nigerian Naira', 'fluentformpro'),
            'NIO' => __('Nicaraguan Córdoba', 'fluentformpro'),
            'NOK' => __('Norwegian Krone', 'fluentformpro'),
            'NPR' => __('Nepalese Rupee', 'fluentformpro'),
            'NZD' => __('New Zealand Dollar', 'fluentformpro'),
            'PAB' => __('Panamanian Balboa', 'fluentformpro'),
            'PEN' => __('Peruvian Nuevo Sol', 'fluentformpro'),
            'PGK' => __('Papua New Guinean Kina', 'fluentformpro'),
            'PHP' => __('Philippine Peso', 'fluentformpro'),
            'PKR' => __('Pakistani Rupee', 'fluentformpro'),
            'PLN' => __('Polish Złoty', 'fluentformpro'),
            'PYG' => __('Paraguayan Guaraní', 'fluentformpro'),
            'QAR' => __('Qatari Riyal', 'fluentformpro'),
            'RON' => __('Romanian Leu', 'fluentformpro'),
            'RSD' => __('Serbian Dinar', 'fluentformpro'),
            'RUB' => __('Russian Ruble', 'fluentformpro'),
            'RWF' => __('Rwandan Franc', 'fluentformpro'),
            'SAR' => __('Saudi Riyal', 'fluentformpro'),
            'SBD' => __('Solomon Islands Dollar', 'fluentformpro'),
            'SCR' => __('Seychellois Rupee', 'fluentformpro'),
            'SEK' => __('Swedish Krona', 'fluentformpro'),
            'SGD' => __('Singapore Dollar', 'fluentformpro'),
            'SHP' => __('Saint Helenian Pound', 'fluentformpro'),
            'SLL' => __('Sierra Leonean Leone', 'fluentformpro'),
            'SOS' => __('Somali Shilling', 'fluentformpro'),
            'SRD' => __('Surinamese Dollar', 'fluentformpro'),
            'STD' => __('São Tomé and Príncipe Dobra', 'fluentformpro'),
            'SVC' => __('Salvadoran Colón', 'fluentformpro'),
            'SZL' => __('Swazi Lilangeni', 'fluentformpro'),
            'THB' => __('Thai Baht', 'fluentformpro'),
            'TJS' => __('Tajikistani Somoni', 'fluentformpro'),
            'TOP' => __('Tongan Paʻanga', 'fluentformpro'),
            'TRY' => __('Turkish Lira', 'fluentformpro'),
            'TTD' => __('Trinidad and Tobago Dollar', 'fluentformpro'),
            'TWD' => __('New Taiwan Dollar', 'fluentformpro'),
            'TZS' => __('Tanzanian Shilling', 'fluentformpro'),
            'UAH' => __('Ukrainian Hryvnia', 'fluentformpro'),
            'UGX' => __('Ugandan Shilling', 'fluentformpro'),
            'USD' => __('United States Dollar', 'fluentformpro'),
            'UYU' => __('Uruguayan Peso', 'fluentformpro'),
            'UZS' => __('Uzbekistani Som', 'fluentformpro'),
            'VND' => __('Vietnamese Đồng', 'fluentformpro'),
            'VUV' => __('Vanuatu Vatu', 'fluentformpro'),
            'WST' => __('Samoan Tala', 'fluentformpro'),
            'XAF' => __('Central African Cfa Franc', 'fluentformpro'),
            'XCD' => __('East Caribbean Dollar', 'fluentformpro'),
            'XOF' => __('West African Cfa Franc', 'fluentformpro'),
            'XPF' => __('Cfp Franc', 'fluentformpro'),
            'YER' => __('Yemeni Rial', 'fluentformpro'),
            'ZAR' => __('South African Rand', 'fluentformpro'),
            'ZMW' => __('Zambian Kwacha', 'fluentformpro')
        ];
    
        $currencies = apply_filters_deprecated(
            'fluentform_accepted_currencies',
            [
                $currencies
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/accepted_currencies',
            'Use fluentform/accepted_currencies instead of fluentform_accepted_currencies.'
        );

        return apply_filters('fluentform/accepted_currencies', $currencies);
    }

    /**
     * Get a specific currency symbol
     *
     * https://support.stripe.com/questions/which-currencies-does-stripe-support
     */
    public static function getCurrencySymbol($currency = '')
    {
        if (!$currency) {
            // If no currency is passed then default it to USD
            $currency = 'USD';
        }
        $currency = strtoupper($currency);

        $symbols = self::getCurrencySymbols();
        $currency_symbol = isset($symbols[$currency]) ? $symbols[$currency] : '';
    
        $currency_symbol = apply_filters_deprecated(
            'fluentform_currency_symbol',
            [
                $currency_symbol,
                $currency
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/currency_symbol',
            'Use fluentform/currency_symbol instead of fluentform_currency_symbol.'
        );

        return apply_filters('fluentform/currency_symbol', $currency_symbol, $currency);
    }

    public static function getCurrencySymbols()
    {
        $symbols = [
            'AED' => '&#x62f;.&#x625;',
            'AFN' => '&#x60b;',
            'ALL' => 'L',
            'AMD' => 'AMD',
            'ANG' => '&fnof;',
            'AOA' => 'Kz',
            'ARS' => '&#36;',
            'AUD' => '&#36;',
            'AWG' => '&fnof;',
            'AZN' => 'AZN',
            'BAM' => 'KM',
            'BBD' => '&#36;',
            'BDT' => '&#2547;&nbsp;',
            'BGN' => '&#1083;&#1074;.',
            'BHD' => '.&#x62f;.&#x628;',
            'BIF' => 'Fr',
            'BMD' => '&#36;',
            'BND' => '&#36;',
            'BOB' => 'Bs.',
            'BRL' => '&#82;&#36;',
            'BSD' => '&#36;',
            'BTC' => '&#3647;',
            'BTN' => 'Nu.',
            'BWP' => 'P',
            'BYR' => 'Br',
            'BZD' => '&#36;',
            'CAD' => '&#36;',
            'CDF' => 'Fr',
            'CHF' => '&#67;&#72;&#70;',
            'CLP' => '&#36;',
            'CNY' => '&yen;',
            'COP' => '&#36;',
            'CRC' => '&#x20a1;',
            'CUC' => '&#36;',
            'CUP' => '&#36;',
            'CVE' => '&#36;',
            'CZK' => '&#75;&#269;',
            'DJF' => 'Fr',
            'DKK' => 'DKK',
            'DOP' => 'RD&#36;',
            'DZD' => '&#x62f;.&#x62c;',
            'EGP' => 'EGP',
            'ERN' => 'Nfk',
            'ETB' => 'Br',
            'EUR' => '&euro;',
            'FJD' => '&#36;',
            'FKP' => '&pound;',
            'GBP' => '&pound;',
            'GEL' => '&#x10da;',
            'GGP' => '&pound;',
            'GHS' => '&#x20b5;',
            'GIP' => '&pound;',
            'GMD' => 'D',
            'GNF' => 'Fr',
            'GTQ' => 'Q',
            'GYD' => '&#36;',
            'HKD' => '&#36;',
            'HNL' => 'L',
            'HRK' => 'Kn',
            'HTG' => 'G',
            'HUF' => '&#70;&#116;',
            'IDR' => 'Rp',
            'ILS' => '&#8362;',
            'IMP' => '&pound;',
            'INR' => '&#8377;',
            'IQD' => '&#x639;.&#x62f;',
            'IRR' => '&#xfdfc;',
            'ISK' => 'Kr.',
            'JEP' => '&pound;',
            'JMD' => '&#36;',
            'JOD' => '&#x62f;.&#x627;',
            'JPY' => '&yen;',
            'KES' => 'KSh',
            'KGS' => '&#x43b;&#x432;',
            'KHR' => '&#x17db;',
            'KMF' => 'Fr',
            'KPW' => '&#x20a9;',
            'KRW' => '&#8361;',
            'KWD' => '&#x62f;.&#x643;',
            'KYD' => '&#36;',
            'KZT' => 'KZT',
            'LAK' => '&#8365;',
            'LBP' => '&#x644;.&#x644;',
            'LKR' => '&#xdbb;&#xdd4;',
            'LRD' => '&#36;',
            'LSL' => 'L',
            'LYD' => '&#x644;.&#x62f;',
            'MAD' => '&#x62f;. &#x645;.',
            'MDL' => 'L',
            'MGA' => 'Ar',
            'MKD' => '&#x434;&#x435;&#x43d;',
            'MMK' => 'Ks',
            'MNT' => '&#x20ae;',
            'MOP' => 'P',
            'MRO' => 'UM',
            'MUR' => '&#x20a8;',
            'MVR' => '.&#x783;',
            'MWK' => 'MK',
            'MXN' => '&#36;',
            'MYR' => '&#82;&#77;',
            'MZN' => 'MT',
            'NAD' => '&#36;',
            'NGN' => '&#8358;',
            'NIO' => 'C&#36;',
            'NOK' => '&#107;&#114;',
            'NPR' => '&#8360;',
            'NZD' => '&#36;',
            'OMR' => '&#x631;.&#x639;.',
            'PAB' => 'B/.',
            'PEN' => 'S/.',
            'PGK' => 'K',
            'PHP' => '&#8369;',
            'PKR' => '&#8360;',
            'PLN' => '&#122;&#322;',
            'PRB' => '&#x440;.',
            'PYG' => '&#8370;',
            'QAR' => '&#x631;.&#x642;',
            'RMB' => '&yen;',
            'RON' => 'lei',
            'RSD' => '&#x434;&#x438;&#x43d;.',
            'RUB' => '&#8381;',
            'RWF' => 'Fr',
            'SAR' => '&#x631;.&#x633;',
            'SBD' => '&#36;',
            'SCR' => '&#x20a8;',
            'SDG' => '&#x62c;.&#x633;.',
            'SEK' => '&#107;&#114;',
            'SGD' => '&#36;',
            'SHP' => '&pound;',
            'SLL' => 'Le',
            'SOS' => 'Sh',
            'SRD' => '&#36;',
            'SSP' => '&pound;',
            'STD' => 'Db',
            'SYP' => '&#x644;.&#x633;',
            'SZL' => 'L',
            'THB' => '&#3647;',
            'TJS' => '&#x405;&#x41c;',
            'TMT' => 'm',
            'TND' => '&#x62f;.&#x62a;',
            'TOP' => 'T&#36;',
            'TRY' => '&#8378;',
            'TTD' => '&#36;',
            'TWD' => '&#78;&#84;&#36;',
            'TZS' => 'Sh',
            'UAH' => '&#8372;',
            'UGX' => 'UGX',
            'USD' => '&#36;',
            'UYU' => '&#36;',
            'UZS' => 'UZS',
            'VEF' => 'Bs F',
            'VND' => '&#8363;',
            'VUV' => 'Vt',
            'WST' => 'T',
            'XAF' => 'Fr',
            'XCD' => '&#36;',
            'XOF' => 'Fr',
            'XPF' => 'Fr',
            'YER' => '&#xfdfc;',
            'ZAR' => '&#82;',
            'ZMW' => 'ZK',
        ];

        $symbols = apply_filters_deprecated(
            'fluentform_currency_symbols',
            [
                $symbols
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/currencies_symbols',
            'Use fluentform/currencies_symbols instead of fluentform_currency_symbols.'
        );

        return apply_filters('fluentform/currencies_symbols', $symbols);
    }

    public static function zeroDecimalCurrencies()
    {
        $zeroDecimalCurrencies = [
            'BIF' => esc_html__('Burundian Franc', 'fluentformpro'),
            'CLP' => esc_html__('Chilean Peso', 'fluentformpro'),
            'DJF' => esc_html__('Djiboutian Franc', 'fluentformpro'),
            'GNF' => esc_html__('Guinean Franc', 'fluentformpro'),
            'JPY' => esc_html__('Japanese Yen', 'fluentformpro'),
            'KMF' => esc_html__('Comorian Franc', 'fluentformpro'),
            'KRW' => esc_html__('South Korean Won', 'fluentformpro'),
            'MGA' => esc_html__('Malagasy Ariary', 'fluentformpro'),
            'PYG' => esc_html__('Paraguayan Guaraní', 'fluentformpro'),
            'RWF' => esc_html__('Rwandan Franc', 'fluentformpro'),
            'VND' => esc_html__('Vietnamese Dong', 'fluentformpro'),
            'VUV' => esc_html__('Vanuatu Vatu', 'fluentformpro'),
            'XAF' => esc_html__('Central African Cfa Franc', 'fluentformpro'),
            'XOF' => esc_html__('West African Cfa Franc', 'fluentformpro'),
            'XPF' => esc_html__('Cfp Franc', 'fluentformpro'),
        ];

        $zeroDecimalCurrencies = apply_filters_deprecated(
            'fluentform_zero_decimal_currencies',
            [
                $zeroDecimalCurrencies
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/zero_decimal_currencies',
            'Use fluentform/zero_decimal_currencies instead of fluentform_zero_decimal_currencies.'
        );

        return apply_filters('fluentform/zero_decimal_currencies', $zeroDecimalCurrencies);
    }

    public static function isZeroDecimal($currencyCode)
    {
        $currencyCode = strtoupper($currencyCode);
        $zeroDecimals = self::zeroDecimalCurrencies();
        return isset($zeroDecimals[$currencyCode]);
    }

    public static function getPaymentStatuses()
    {
        $paymentStatuses = [
            'paid'               => __('Paid', 'fluentformpro'),
            'processing'         => __('Processing', 'fluentformpro'),
            'pending'            => __('Pending', 'fluentformpro'),
            'requires_review'    => __('Requires Review', 'fluentformpro'),
            'failed'             => __('Failed', 'fluentformpro'),
            'refunded'           => __('Refunded', 'fluentformpro'),
            'partially-refunded' => __('Partial Refunded', 'fluentformpro'),
            'cancelled'          => __('Cancelled', 'fluentformpro')
        ];

        $paymentStatuses = apply_filters_deprecated(
            'fluentform_available_payment_statuses',
            [
                $paymentStatuses
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/available_payment_statuses',
            'Use fluentform/available_payment_statuses instead of fluentform_available_payment_statuses.'
        );

        return apply_filters('fluentform/available_payment_statuses', $paymentStatuses);
    }

    /**
     * The only payment status that may fire the submission-action pipeline
     * (integrations, user registration, webhooks). Everything else -- processing,
     * requires_review, pending, failed -- records the order without fulfilling it,
     * so a buyer is never handed the product on money the merchant has not settled.
     * Allow-list, never a deny-list, and filterable for a gateway with its own
     * settled state.
     *
     * @param  string|null $status
     * @return bool
     */
    public static function isFulfillablePaymentStatus($status)
    {
        $status = is_null($status) ? '' : (string) $status;

        $fulfillable = apply_filters('fluentform/fulfillable_payment_statuses', ['paid']);

        return in_array($status, $fulfillable, true);
    }

    public static function getFormPaymentMethods($formId)
    {
        $inputs = FormFieldsParser::getInputs($formId, ['element', 'settings']);
        foreach ($inputs as $field) {
            if ($field['element'] == 'payment_method') {
                $methods = ArrayHelper::get($field, 'settings.payment_methods');
                if (is_array($methods)) {
                    return array_filter($methods, function ($method) {
                        return $method['enabled'] == 'yes';
                    });
                }
            }
        }
        return [];
    }

    public static function getCustomerEmail($submission, $form = false)
    {

        $formSettings = PaymentHelper::getFormSettings($submission->form_id, 'admin');
        $customerEmailField = ArrayHelper::get($formSettings, 'receipt_email');

        if ($customerEmailField) {
            $email = ArrayHelper::get($submission->response, $customerEmailField);
            if ($email) {
                return $email;
            }
        }

        $user = get_user_by('ID', get_current_user_id());

        if ($user) {
            return $user->user_email;
        }

        if (!$form) {
            return '';
        }

        $emailFields = FormFieldsParser::getInputsByElementTypes($form, ['input_email'], ['attributes']);

        foreach ($emailFields as $field) {
            $fieldName = $field['attributes']['name'];
            if (!empty($submission->response[$fieldName])) {
                return $submission->response[$fieldName];
            }
        }

        return '';

    }

    static function getCustomerPhoneNumber($submission, $form) {
        $phoneFields = FormFieldsParser::getInputsByElementTypes($form, ['phone'], ['attributes']);

        foreach ($phoneFields as $field) {
            $fieldName = $field['attributes']['name'];
            if (!empty($submission->response[$fieldName])) {
                return $submission->response[$fieldName];
            }
        }
        return '';
    }

    /**
     * Trim a string and append a suffix.
     *
     * @param string $string String to trim.
     * @param integer $chars Amount of characters.
     *                         Defaults to 200.
     * @param string $suffix Suffix.
     *                         Defaults to '...'.
     * @return string
     */
    public static function formatPaymentItemString($string, $chars = 200, $suffix = '...')
    {
        $string = wp_strip_all_tags($string);
        if (strlen($string) > $chars) {
            if (function_exists('mb_substr')) {
                $string = mb_substr($string, 0, ($chars - mb_strlen($suffix))) . $suffix;
            } else {
                $string = substr($string, 0, ($chars - strlen($suffix))) . $suffix;
            }
        }

        return html_entity_decode($string, ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * Limit length of an arg.
     *
     * @param string $string Argument to limit.
     * @param integer $limit Limit size in characters.
     * @return string
     */
    public static function limitLength($string, $limit = 127)
    {
        $str_limit = $limit - 3;
        if (function_exists('mb_strimwidth')) {
            if (mb_strlen($string) > $limit) {
                $string = mb_strimwidth($string, 0, $str_limit) . '...';
            }
        } else {
            if (strlen($string) > $limit) {
                $string = substr($string, 0, $str_limit) . '...';
            }
        }
        return $string;
    }

    public static function floatToString($float)
    {
        if (!is_float($float)) {
            return $float;
        }

        $locale = localeconv();
        $string = strval($float);
        $string = str_replace($locale['decimal_point'], '.', $string);

        return $string;
    }

    public static function convertToCents($amount)
    {
        if (!$amount) {
            return 0;
        }

        $amount = floatval($amount);

        return intval(round($amount * 100));
    }

    public static function getCustomerName($submission, $form = false)
    {
        $formSettings = PaymentHelper::getFormSettings($submission->form_id, 'admin');
        $customerNameCode = ArrayHelper::get($formSettings, 'customer_name');
        if ($customerNameCode) {
            $customerName = ShortCodeParser::parse($customerNameCode, $submission->id, $submission->response);
            if ($customerName) {
                return $customerName;
            }
        }

        $user = get_user_by('ID', get_current_user_id());

        if ($user) {
            $customerName = trim($user->first_name . ' ' . $user->last_name);
            if (!$customerName) {
                $customerName = $user->display_name;
            }
            if ($customerName) {
                return $customerName;
            }
        }

        if (!$form) {
            return '';
        }

        $nameFields = FormFieldsParser::getInputsByElementTypes($form, ['input_name'], ['attributes']);

        $fieldName = false;
        foreach ($nameFields as $field) {
            if ($field['element'] === 'input_name') {
                $fieldName = $field['attributes']['name'];
                break;
            }
        }

        $name = '';
        if ($fieldName) {
            if (!empty($submission->response[$fieldName])) {
                $names = array_filter($submission->response[$fieldName]);
                return trim(implode(' ', $names));
            }
        }

        return $name;
    }

    public static function getStripeInlineConfig($formId)
    {
        $methods = static::getFormPaymentMethods($formId);

        $stripe = ArrayHelper::get($methods, 'stripe');
        $stripeInlineStyles = ArrayHelper::get(Helper::getFormMeta($formId, '_ff_form_styles', []), 'stripe_inline_element_style', false);
        if ($stripe) {
            return apply_filters(
                'fluentform/stripe_inline_config',
                [
                    'is_inline'     => ArrayHelper::get($stripe, 'settings.embedded_checkout.value') == 'yes',
                    'inline_styles' => $stripeInlineStyles,
                    'verifyZip'     => ArrayHelper::get($methods['stripe'], 'settings.verify_zip_code.value') === 'yes',
                    'disable_link'  => false
                ],
                $formId
            );
        }

        return [];
    }

    public static function getSquareInlineConfig($formId)
    {
        $methods = static::getFormPaymentMethods($formId);

        $square = ArrayHelper::get($methods, 'square');
        $squareSettings = SquareSettings::getApiKeys();
        // SECURITY (PRO-01): the Square access token is a server-side secret and must never
        // be localized to the public form page. The front-end payment handler only needs the
        // public application_id / location_id, so strip the access token before it reaches JS.
        unset($squareSettings['access_key']);
        $squareInlineStyles = ArrayHelper::get(Helper::getFormMeta($formId, '_ff_form_styles', []), 'square_inline_element_style', false);
        if ($square) {
            return apply_filters(
                'fluentform/square_inline_config',
                [
                    'is_inline'     => ArrayHelper::get($square, 'settings.embedded_checkout.value') == 'yes',
                    'inline_styles' => $squareInlineStyles,
                    'settings'      => $squareSettings,
                    'locale'        => 'en-US'
                ],
                $formId
            );
        }

        return [];
    }

    public static function log($data, $submission = false, $forceInsert = false)
    {
        if (!$forceInsert) {
            static $paymentSettings;
            if (!$paymentSettings) {
                $paymentSettings = self::getPaymentSettings();
            }

            if (!isset($paymentSettings['debug_log']) || $paymentSettings['debug_log'] != 'yes') {
                return false;
            }
        }

        $defaults = [
            'component'  => 'Payment',
            'status'     => 'info',
            'created_at' => current_time('mysql')
        ];

        if ($submission) {
            $defaults['parent_source_id'] = $submission->form_id;
            $defaults['source_type'] = 'submission_item';
            $defaults['source_id'] = $submission->id;
        } else {
            $defaults['source_type'] = 'system_log';
        }

        $data = wp_parse_args($data, $defaults);

        return wpFluent()->table('fluentform_logs')
            ->insertGetId($data);

    }

    public static function maybeFireSubmissionActionHok($submission)
    {
        if (Helper::getSubmissionMeta($submission->id, 'is_form_action_fired') == 'yes') {
            return false;
        }

        $form = wpFluent()->table('fluentform_forms')->where('id', $submission->form_id)->first();

        if (!apply_filters('fluentform/should_process_submission_actions', true, $submission, $form)) {
            return false;
        }

        $formData = $submission->response;
        if (!is_array($formData)) {
            $formData = json_decode($formData, true);
        }

        (new SubmissionHandlerService())->processSubmissionData(
            $submission->id, $formData, $form
        );
        Helper::setSubmissionMeta($submission->id, 'is_form_action_fired', 'yes');
        return true;
    }

    public static function loadView($fileName, $data)
    {
        // normalize the filename
        $fileName = str_replace(array('../', './'), '', $fileName);
    
        $basePath = FLUENTFORMPRO_DIR_PATH . 'src/views/receipt/';
        $basePath = apply_filters_deprecated(
            'fluentform_payment_receipt_template_base_path',
            [
                $basePath,
                $fileName,
                $data
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/payment_receipt_template_base_path',
            'Use fluentform/payment_receipt_template_base_path instead of fluentform_payment_receipt_template_base_path.'
        );

        $basePath = apply_filters('fluentform/payment_receipt_template_base_path', $basePath, $fileName, $data);

        $filePath = $basePath . $fileName . '.php';
        extract($data);
        ob_start();
        include $filePath;
        return ob_get_clean();
    }

    public static function recordSubscriptionCancelled($subscription, $vendorData, $logData = [])
    {
        wpFluent()->table('fluentform_subscriptions')
            ->where('id', $subscription->id)
            ->update([
                'status'     => 'cancelled',
                'updated_at' => current_time('mysql')
            ]);

        $subscription = wpFluent()->table('fluentform_subscriptions')
            ->where('id', $subscription->id)
            ->first();

        $submission = wpFluent()->table('fluentform_submissions')
            ->where('id', $subscription->submission_id)
            ->first();

        $logDefaults = [
            'parent_source_id' => $subscription->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $subscription->submission_id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => __('Subscription has been cancelled', 'fluentformpro'),
            'description'      => __('Subscription has been cancelled from ', 'fluentformpro') . $submission->payment_method
        ];

        $logs = wp_parse_args($logData, $logDefaults);

        do_action('fluentform/log_data', $logs);

        do_action_deprecated(
            'fluentform_subscription_payment_canceled',
            [
                $subscription,
                $submission,
                $vendorData
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/subscription_payment_canceled',
            'Use fluentform/subscription_payment_canceled instead of fluentform_subscription_payment_canceled.'
        );
        // New Payment Made so we have to fire some events here
        do_action('fluentform/subscription_payment_canceled', $subscription, $submission, $vendorData);

        do_action_deprecated(
            'fluentform_subscription_payment_canceled_' . $submission->payment_method,
            [
                $subscription,
                $submission,
                $vendorData
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/subscription_payment_canceled_' . $submission->payment_method,
            'Use fluentform/subscription_payment_canceled_' . $submission->payment_method . ' instead of fluentform_subscription_payment_canceled_' . $submission->payment_method
        );
        do_action('fluentform/subscription_payment_canceled_' . $submission->payment_method, $subscription, $submission, $vendorData);
    }

    public static function getPaymentSummaryText($plan, $formId, $currency, $withMarkup = true)
    {
        $paymentSummaryText = [
            'has_signup_fee' => __('{first_interval_total} for first {billing_interval} then {subscription_amount} for each {billing_interval}', 'fluentformpro'),
            'has_trial'      => __('Free for {trial_days} days then {subscription_amount} for each {billing_interval}', 'fluentformpro'),
            'onetime_only'   => __('One time payment of {first_interval_total}', 'fluentformpro'),
            'normal'         => __('{subscription_amount} for each {billing_interval}', 'fluentformpro'),
            'bill_times'     => __(', for {bill_times} installments', 'fluentformpro'),
            'single_trial'   => __('Free for {trial_days} days then {subscription_amount} one time', 'fluentformpro')
        ];

        $paymentSummaryText = apply_filters_deprecated(
            'fluentform_recurring_payment_summary_texts',
            [
                $paymentSummaryText,
                $plan,
                $formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/recurring_payment_summary_texts',
            'Use fluentform/recurring_payment_summary_texts instead of fluentform_recurring_payment_summary_texts.'
        );

        $cases = apply_filters('fluentform/recurring_payment_summary_texts', $paymentSummaryText, $plan, $formId);

        // if is trial
        $hasTrial = ArrayHelper::get($plan, 'has_trial_days') == 'yes' && ArrayHelper::get($plan, 'trial_days');
        if ($hasTrial) {
            $plan['signup_fee'] = 0;
        }

        $signupFee = 0;
        $hasSignupFee = ArrayHelper::get($plan, 'has_signup_fee') == 'yes' && ArrayHelper::get($plan, 'signup_fee');
        if ($hasSignupFee) {
            $plan['trial_days'] = 0;
            $signupFee = ArrayHelper::get($plan, 'signup_fee');
        }

        $firstIntervalTotal = PaymentHelper::formatMoney(
            PaymentHelper::convertToCents($signupFee + ArrayHelper::get($plan, 'subscription_amount')),
            $currency
        );

        if ($signupFee) {
            $signupFee = PaymentHelper::formatMoney(
                PaymentHelper::convertToCents($signupFee),
                $currency
            );
        }

        $subscriptionAmount = PaymentHelper::formatMoney(
            PaymentHelper::convertToCents(ArrayHelper::get($plan, 'subscription_amount')),
            $currency
        );

        $billingInterval = $plan['billing_interval'];
        $billingInterval = ArrayHelper::get(self::getBillingIntervals(), $billingInterval, $billingInterval);
        $billingInterval = esc_html($billingInterval);
        $trialDays = esc_html(ArrayHelper::get($plan, 'trial_days'));
        $billTimes = esc_html(ArrayHelper::get($plan, 'bill_times'));
        $replaces = array(
            '{signup_fee}'           => '<span class="ff_bs ffbs_signup_fee">' . $signupFee . '</span>',
            '{first_interval_total}' => '<span class="ff_bs ffbs_first_interval_total">' . $firstIntervalTotal . '</span>',
            '{subscription_amount}'  => '<span class="ff_bs ffbs_subscription_amount">' . $subscriptionAmount . '</span>',
            '{billing_interval}'     => '<span class="ff_bs ffbs_billing_interval">' . $billingInterval . '</span>',
            '{trial_days}'           => '<span class="ff_bs ffbs_trial_days">' . $trialDays . '</span>',
            '{bill_times}'           => '<span class="ff_bs ffbs_bill_times">' . $billTimes . '</span>',
        );

        if (ArrayHelper::get($plan, 'user_input') == 'yes') {
            $cases['{subscription_amount}'] = '<span class="ff_dynamic_input_amount">' . $subscriptionAmount . '</span>';
        }

        foreach ($cases as $textKey => $text) {
            $cases[$textKey] = str_replace(array_keys($replaces), array_values($replaces), $text);
        }

        $customText = '';
        if ($hasSignupFee && isset($plan['bill_times']) && $plan['bill_times'] == 1) {
            $customText = $cases['onetime_only'];
        } else if ($hasSignupFee) {
            $customText = $cases['has_signup_fee'];
        } else if ($hasTrial) {
            if (ArrayHelper::get($plan, 'bill_times') == 1) {
                $customText = $cases['single_trial'];
            } else {
                $customText = $cases['has_trial'];
            }
        } else if (isset($plan['bill_times']) && $plan['bill_times'] == 1) {
            $customText = $cases['onetime_only'];
        } else {
            $customText = $cases['normal'];
        }

        if (isset($plan['bill_times']) && $plan['bill_times'] > 1) {
            $customText .= $cases['bill_times'];
        }
        if($withMarkup) {
            $class = $plan['is_default'] === 'yes' ? '' : 'hidden_field';
            return '<div class="ff_summary_container ff_summary_container_' . esc_attr($plan['index']) . ' ' . $class . '">' . $customText . '</div>';
        }
        return $customText;
    }

	public static function getCustomerAddress($submission)
	{
		$formSettings = PaymentHelper::getFormSettings($submission->form_id, 'admin');
		$customerAddressField = ArrayHelper::get($formSettings, 'customer_address');

		if ($customerAddressField) {
            return ArrayHelper::get($submission->response, $customerAddressField);
		}

		return null;
	}

    public static function getBillingIntervals()
    {
        return [
            'day'   => __('day', 'fluentformpro'),
            'week'  => __('week', 'fluentformpro'),
            'month' => __('month', 'fluentformpro'),
            'year'  => __('year', 'fluentformpro')
        ];
    }

    public static function getSubscriptionStatuses()
    {
        return [
            'active'    => __('active', 'fluentformpro'),
            'trialling' => __('trialling', 'fluentformpro'),
            'failing'   => __('failing', 'fluentformpro'),
            'cancelled' => __('cancelled', 'fluentformpro')
        ];
    }
    
    public static function getFormInput($formId,$inputType)
    {
        $form = fluentFormApi()->form($formId);
        $fields = FormFieldsParser::getInputsByElementTypes($form, [$inputType], ['attributes']);
        if (!empty($fields)) {
            $field = array_shift($fields);
            return ArrayHelper::get($field, 'attributes.name');
        }
        return '';
    }

    public static function encryptKey($value)
    {
        if(!$value) {
            return $value;
        }

        // SECURITY (PRO-39): fail closed instead of storing the secret in plaintext when OpenSSL is
        // unavailable — the caller must handle a false return rather than persist a cleartext key.
        if ( ! extension_loaded( 'openssl' ) ) {
            return false;
        }

        // SECURITY (PRO-39): never encrypt with the public in-source fallback key (which ships in
        // the plugin and makes the ciphertext reversible by anyone). Require the site's real
        // LOGGED_IN_SALT / LOGGED_IN_KEY for new writes; fail closed if they are absent/empty.
        // (decryptKey still accepts the legacy fallback so pre-existing data keeps working.)
        $salt = (defined( 'LOGGED_IN_SALT' ) && '' !== LOGGED_IN_SALT) ? LOGGED_IN_SALT : '';
        $key = ( defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ) ? LOGGED_IN_KEY : '';

        if (!$salt || !$key) {
            return false;
        }

        $method = 'aes-256-ctr';
        $ivlen  = openssl_cipher_iv_length( $method );
        $iv     = openssl_random_pseudo_bytes( $ivlen );

        $raw_value = openssl_encrypt( $value . $salt, $method, $key, 0, $iv );
        if ( ! $raw_value ) {
            return false;
        }

        return base64_encode( $iv . $raw_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    public static function decryptKey( $raw_value ) {

        if(!$raw_value) {
            return $raw_value;
        }

        if ( ! extension_loaded( 'openssl' ) ) {
            return $raw_value;
        }

        $decoded = base64_decode( $raw_value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        $method = 'aes-256-ctr';
        $ivlen  = openssl_cipher_iv_length( $method );
        $iv     = substr( $decoded, 0, $ivlen );

        $ciphertext = substr( $decoded, $ivlen );

        $fallbackStr = 'this-is-a-fallback-key-but-not-secure';

        $salt = (defined( 'LOGGED_IN_SALT' ) && '' !== LOGGED_IN_SALT) ? LOGGED_IN_SALT : $fallbackStr;
        $key = (defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ) ? LOGGED_IN_KEY : $fallbackStr;

        if (!$salt || !$key) {
            return false;
        }

        $value = openssl_decrypt( $ciphertext, $method, $key, 0, $iv );
        if ( $value && substr( $value, - strlen( $salt ) ) === $salt ) {
            return substr( $value, 0, - strlen( $salt ) );
        }

        // Primary decryption failed. If we're using real WordPress keys,
        // retry with the fallback key for data encrypted before proper
        // LOGGED_IN_SALT / LOGGED_IN_KEY were configured.
        if ($salt !== $fallbackStr || $key !== $fallbackStr) {
            $value = openssl_decrypt( $ciphertext, $method, $fallbackStr, 0, $iv );
            if ( $value && substr( $value, - strlen( $fallbackStr ) ) === $fallbackStr ) {
                return substr( $value, 0, - strlen( $fallbackStr ) );
            }
        }

        return false;
    }
}
