<?php

namespace FluentFormPro\Integrations\UserRegistration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper as Arr;
use FluentFormPro\Components\Post\AcfHelper;
use FluentFormPro\Components\Post\JetEngineHelper;
use FluentFormPro\Components\Post\MetaboxHelper;

class Bootstrap extends IntegrationManagerController
{
    use Getter;

    public $category = 'wp_core';
    public $disableGlobalSettings = 'yes';
    protected $updateApi;
    protected $regApi;

    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'User Registration or Update',
            'UserRegistration',
            '_fluentform_user_registration_settings',
            'user_registration_feeds',
            1
        );

        $this->regApi = new UserRegistrationApi();
        $this->updateApi = new UserUpdateFormHandler();

        $this->logo = fluentFormMix('img/integrations/user_registration.png');

        $this->description = 'Create WordPress user or update user when a form is submitted.';

        $this->registerAdminHooks();

        $this->registerHooks();

        // Both integration_name variants that can persist a processable feed are gated, so a
        // snake-case save can't skip the cap; registered here to run regardless of isEnabled.
        add_filter('fluentform/save_integration_value_UserRegistration', [$this, 'capGrantableUserRole'], 11, 1);
        add_filter('fluentform/save_integration_value_user_registration', [$this, 'capGrantableUserRole'], 11, 1);
    }

    public function capGrantableUserRole($metaValue)
    {
        if (!is_array($metaValue) || empty($metaValue['userRole'])) {
            return $metaValue;
        }

        if ('user_update' === Arr::get($metaValue, 'list_id')) {
            return $metaValue; // user_update changes profile fields, not the role
        }

        if (current_user_can('manage_options') || current_user_can('fluentform_full_access')) {
            return $metaValue;
        }

        if (!$this->currentUserCanGrantRole($metaValue['userRole'])) {
            $metaValue['userRole'] = 'subscriber';
        }

        return $metaValue;
    }

    private function currentUserCanGrantRole($roleKey)
    {
        if (!is_string($roleKey) || '' === $roleKey) {
            return false;
        }

        $role = get_role($roleKey);
        if (!$role || empty($role->capabilities)) {
            return false;
        }

        // Compare against the saver's own capability set, not current_user_can(): a cap stripped
        // at runtime (e.g. unfiltered_html on multisite) must not falsely downgrade a real grant.
        $userCaps = wp_get_current_user()->allcaps;
        foreach ($role->capabilities as $capability => $granted) {
            if ($granted && empty($userCaps[$capability])) {
                return false;
            }
        }

        return true;
    }

    protected function registerHooks()
    {
        $isEnabled =  $this->isEnabled();
        if(!$isEnabled){
            return;
        }

        add_filter('fluentform/notifying_async_UserRegistration', '__return_false');

        // Capture the submitter in their own request, at submit, before any deferral
        // (payment webhook / approval / opt-in) runs the feeds where the submitter is
        // no longer the current user.
        add_action('fluentform/notify_on_form_submit', [$this->updateApi, 'captureUpdateTarget'], 9, 3);
        add_filter('fluentform/nonce_verify', [$this->updateApi, 'requireNonceForSelfUpdate'], 10, 2);

        add_filter('fluentform/save_integration_value_' . $this->integrationKey, [$this, 'handleValidate'], 10, 3);
        add_filter('fluentform/validation_user_registration_errors', [$this->regApi, 'validateSubmittedForm'], 10, 3);
        
        add_filter('fluentform/validation_user_update_errors', [$this->updateApi, 'validateSubmittedForm'], 10, 3);
        add_filter('fluentform/rendering_form', [
            $this->updateApi, 'maybePopulateUserUpdateForm'
        ], 10, 3);

        add_action('fluentform/after_form_render', [
            $this->updateApi, 'maybeEnqueueUserUpdateScript'
        ]);

        add_filter('fluentform/white_listed_fields', [$this->updateApi, 'addWhiteListedFields'], 10, 2);

        add_filter(
            'fluentform/get_integration_values_' . $this->integrationKey,
            [$this, 'resolveIntegrationSettings'],
            100,
            3
        );
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'category'                => 'wp_core',
            'disable_global_settings' => 'yes',
            'logo'                    => $this->logo,
            'title'                   => $this->title . ' Integration',
            'is_active'               => $this->isConfigured()
        ];

        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId = null)
    {
        $listId = $this->app->request->get('serviceId', 'user_registration');

        if ($listId == 'user_registration') {
            $name = $this->app->request->get('serviceName', 'User Registration');
            $this->title = 'User Registration';
        } else {
            $name = $this->app->request->get('serviceName', 'User Update');
            $this->title = 'User Update';
        }

        $fields = [
            'name'                 => $name,
            'list_id'              => $listId,
            'Email'                => '',
            'username'             => '',
            'CustomFields'         => (object)[],
            'userRole'             => 'subscriber',
            'userMeta'             => [
                [
                    'label' => '',
                    'item_value' => ''
                ]
            ],
            'enableAutoLogin'      => false,
            'sendEmailToNewUser'   => false,
            'validateForUserEmail' => true,
            'conditionals'         => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'              => true
        ];

        if (AcfHelper::hasAcf()) {
            $fields['acf_mappings']['general'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
            $fields['acf_mappings']['advanced'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
        }

        if (MetaboxHelper::hasMetabox()) {
            $fields['metabox_mappings']['general'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
            $fields['metabox_mappings']['advanced'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
        }

        if (JetEngineHelper::hasJetEngine()) {
            $fields['jetengine_mappings']['general'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
            $fields['jetengine_mappings']['advanced'] = [
                [
                    'field_key' => '',
                    'field_value' => ''
                ]
            ];
        }
    
        $fields = apply_filters_deprecated(
            'fluentform_user_registration_field_defaults',
            [
                $fields,
                $formId
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/user_registration_field_defaults',
            'Use fluentform/user_registration_field_defaults instead of fluentform_user_registration_field_defaults.'
        );

        return apply_filters('fluentform/user_registration_field_defaults', $fields, $formId);
    }

    public function getSettingsFields($settings, $formId = null)
    {
        $fieldSettings = [
            'fields' => [
                [
                    'key'         => 'name',
                    'label'       => __('Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key' => 'list_id',
                    'label' => __('Services', 'fluentformpro'),
                    'placeholder' => __('Choose Service', 'fluentformpro'),
                    'required' => true,
                    'component' => 'refresh',
                    'options' => [
                        'user_registration' => __('User Registration', 'fluentformpro'),
                        'user_update' => __('User Update', 'fluentformpro')
                    ]
                ],
            ],
            'button_require_list' => false,
            'integration_title' => $this->title
        ];

        $listId = $this->app->request->get('serviceId', Arr::get($settings, 'list_id'));
        $inline_msg ='';
        if ($listId) {
            $fields = $this->getFields($listId);
            $fields = array_merge($fieldSettings['fields'], $fields);
            $fieldSettings['fields'] = $fields;

            if ($listId == 'user_update') {
                $this->title = __('User Update', 'fluentformpro');
                $inline_msg = __('Please note that, This action will only run if the user is logged in.', 'fluentformpro');
            } else {
                $this->title = __('User Registration', 'fluentformpro');
            }
        }
        $fieldSettings['fields'] = array_merge($fieldSettings['fields'], [
            [
                'require_list' => false,
                'key'          => 'conditionals',
                'label'        => __('Conditional Logics', 'fluentformpro'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'tips'         => __('Allow '. $this->title . ' integration conditionally based on your submission values', 'fluentformpro'),
                'component'    => 'conditional_block'
            ],
            [
                'require_list'   => false,
                'key'            => 'enabled',
                'label'          => __('Status', 'fluentformpro'),
                'component'      => 'checkbox-single',
                'checkbox_label' => __('Enable This feed', 'fluentformpro'),
                'inline_tip'     => $inline_msg ?: __('Please note that, This action will only run if the visitor is logged out state and the email is not registered yet', 'fluentformpro'),
            ]
        ]);

        if ($listId && $listId == 'user_registration') {
            $fieldSettings['fields'] = apply_filters_deprecated(
                'fluentform_user_registration_feed_fields',
                [
                    $fieldSettings['fields'],
                    $formId
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/user_registration_feed_fields',
                'Use fluentform/user_registration_feed_fields instead of fluentform_user_registration_feed_fields.'
            );
            $fieldSettings['fields'] = apply_filters('fluentform/user_registration_feed_fields', $fieldSettings['fields'], $formId);
        }
        if ($listId && $listId == 'user_update') {
            $fieldSettings['fields'] = apply_filters_deprecated(
                'fluentform_user_update_feed_fields',
                [
                    $fieldSettings['fields'],
                    $formId
                ],
                FLUENTFORM_FRAMEWORK_UPGRADE,
                'fluentform/user_update_feed_fields',
                'Use fluentform/user_update_feed_fields instead of fluentform_user_update_feed_fields.'
            );
            $fieldSettings['fields'] = apply_filters('fluentform/user_update_feed_fields', $fieldSettings['fields'], $formId);
        }

        return $fieldSettings;
    }

    public function handleValidate($settings, $integrationId, $formId)
    {
        $parseSettings = $this->validate(
            $settings, $this->getSettingsFields($settings)
        );

        if (Arr::get($parseSettings, 'list_id') == 'user_update') {
            Helper::setFormMeta($formId, '_has_user_update', 'yes');
        } else {
            Helper::setFormMeta($formId, '_has_user_registration', 'yes');
        }

        return $parseSettings;
    }

    

    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedValue = Arr::get($feed,'processedValues');
        $this->resolveCustomMetaValue($feed, $formData, $form);

        if (
            $feedValue &&
            Arr::get($feedValue, 'list_id') &&
            Arr::get($feedValue, 'list_id') === 'user_update'
        ) {
            $this->updateApi->handleUpdateUser($feed, $formData, $entry, $form, $this->integrationKey);
        } else {
            $this->regApi->registerUser(
                $feed, $formData, $entry, $form, $this->integrationKey
            );
        }
    }

    // There is no global settings, so we need
    // to return true to make this module work.
    public function isConfigured()
    {
        return true;
    }

    // This is an absttract method, so it's required.
    public function getMergeFields($list, $listId, $formId)
    {
        // ...
    }

    // This method should return global settings. It's not required for
    // this class. So we should return the default settings otherwise
    // there will be an empty global settings page for this module.
    public function addGlobalMenu($setting)
    {
        return $setting;
    }



    protected function getFields($listId)
    {
        if ($listId == 'user_update') {
            $map_primary_field = $this->updateApi->userUpdateMapFields();
        } else {
            $map_primary_field = $this->regApi->userRegistrationMapFields();
        }

        $customFieldMsg = $listId == 'user_update' ? 'update' : 'registration';
        $fields = [
            [
                'key'                => 'CustomFields',
                'require_list'       => false,
                'label'              => __('Map Fields', 'fluentformpro'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'tips'               => __('Associate your user '. $customFieldMsg .' fields to the appropriate Fluent Forms fields by selecting the appropriate form field from the list.', 'fluentformpro'),
                'component'          => 'map_fields',
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                'field_label_remote' => __('User ' . ucfirst($customFieldMsg) . ' Field', 'fluentformpro'),
                'field_label_local'  => __('Form Field', 'fluentformpro'),
                'primary_fileds'     => $map_primary_field
            ],
        ];
        if ($listId == 'user_registration') {
            $fields[] = [
                'require_list' => false,
                'required' => true,
                'key' => 'userRole',
                'label' => __('Default User Role', 'fluentformpro'),
                'tips' => __('Set default user role when registering a new user.', 'fluentformpro'),
                'component' => 'radio_choice',
                'options' => $this->regApi->getUserRoles()
            ];
            $fields[] = [
                'require_list' => false,
                'key' => 'userMeta',
                'label' => __('User Meta', 'fluentformpro'),
                'tips' => __('Add user meta.', 'fluentformpro'),
                'component' => 'dropdown_label_repeater',
                'field_label' => __('User Meta Key', 'fluentformpro'),
                'value_label' => __('User Meta Value', 'fluentformpro')
            ];
            $this->addMetaPluginsMappingFields($fields);
            $fields[] = [
                'require_list' => false,
                'key' => 'enableAutoLogin',
                'label' => __('Auto Login', 'fluentformpro'),
                'checkbox_label' => __('Allow the user login automatically after registration', 'fluentformpro'),
                'component' => 'checkbox-single',
            ];
            $fields[] = [
                'require_list' => false,
                'key' => 'sendEmailToNewUser',
                'label' => __('Email Notification', 'fluentformpro'),
                'checkbox_label' => __('Send default WordPress welcome email to user after registration', 'fluentformpro'),
                'component' => 'checkbox-single',
            ];
            $fields[] = [
                'require_list' => false,
                'key' => 'validateForUserEmail',
                'label' => __('Form Validation', 'fluentformpro'),
                'checkbox_label' => __('Do not submit the form if user already exist in Database', 'fluentformpro'),
                'component' => 'checkbox-single',
            ];
        } else {
            $fields[] = [
                'require_list' => false,
                'key' => 'userMeta',
                'label' => __('User Meta', 'fluentformpro'),
                'tips' => __('Add user meta.', 'fluentformpro'),
                'component' => 'dropdown_label_repeater',
                'field_label' => __('User Meta Key', 'fluentformpro'),
                'value_label' => __('User Meta Value', 'fluentformpro')
            ];
            $this->addMetaPluginsMappingFields($fields);
        }
        return $fields;
    }

    private function addMetaPluginsMappingFields(&$fields)
    {
        if (AcfHelper::hasAcf()) {
            $fields[] = [
                'require_list' => false,
                'key' => 'acf_mappings',
                'labels' => [
                    'section_title' => __('ACF Plugin Mapping', 'fluentformpro'),
                    'remote_label' => __('ACF Field', 'fluentformpro'),
                    'local_label' => __('Form Field (Value)', 'fluentformpro'),
                ],
                'component' => 'meta_plugin_mapping',
                'fields' => AcfHelper::getAcfUserFields()
            ];
        }

        if (MetaboxHelper::hasMetabox()) {
            $fields[] = [
                'require_list' => false,
                'key' => 'metabox_mappings',
                'labels' => [
                    'section_title' => __('Metabox Meta Mapping', 'fluentformpro'),
                    'remote_label' => __('Metabox Field', 'fluentformpro'),
                    'local_label' => __('Form Field (Value)', 'fluentformpro'),
                ],
                'component' => 'meta_plugin_mapping',
                'fields' => MetaboxHelper::getUserFields()
            ];
        }

        if (JetEngineHelper::hasJetEngine()) {
            $fields[] = [
                'require_list' => false,
                'key' => 'jetengine_mappings',
                'labels' => [
                    'section_title' => __('Jetengine Meta Mapping', 'fluentformpro'),
                    'remote_label' => __('Jetengine Field', 'fluentformpro'),
                    'local_label' => __('Form Field (Value)', 'fluentformpro'),
                ],
                'component' => 'meta_plugin_mapping',
                'fields' => JetEngineHelper::getUserFields()
            ];
        }
    }

    private function resolveCustomMetaValue(&$feed, $formData, $form)
    {
        if (!Arr::get($feed, 'processedValues.userMeta', [])) {
          return;
        }
        $userMetaMapping = Arr::get($feed, 'settings.userMeta', []);
        $inputs = FormFieldsParser::getInputs($form, ['element', 'attributes']);
        $resolvableElement = ['input_checkbox', 'multi_select'];
        foreach ($feed['processedValues']['userMeta'] as $index => &$userMeta) {
            if (
                $userMeta['label'] === Arr::get($userMetaMapping, $index . '.label', '') &&
                $inputName = Helper::getInputNameFromShortCode(Arr::get($userMetaMapping, $index . '.item_value', ''))
            ) {
                $elementType = Arr::get($inputs, $inputName . '.element');
                if ($elementType == 'select' && Arr::get($inputs, $inputName . '.attributes.multiple', false)) {
                    $elementType = 'multi_select';
                }
                if (in_array($elementType, $resolvableElement) && isset($formData[$inputName])) {
                    $value = $formData[$inputName];
                    // Hardening only — the deny-list is what stops a dangerous key.
                    // A list control posts a list; an associative payload is not legitimate.
                    $userMeta['item_value'] = is_array($value) ? array_values($value) : $value;
                }
            }

        }
    }

    public function resolveIntegrationSettings($settings, $feed, $formId)
    {

        $serviceId = $this->app->request->get('serviceId', '');
        $serviceName = $this->app->request->get('serviceName', '');

        if ($serviceName) {
            $settings['name'] = $serviceName;
        }

        if ($serviceId) {
            $settings['list_id'] = $serviceId;
        }

        if ( $serviceId = Arr::get($settings,'list_id')) {
            if ($serviceId == 'user_update') {
                $this->title = __('User Update', 'fluentformpro');
            } else {
                $this->title = __('User Registration', 'fluentformpro');
            }
        } else {
            $this->title = __('User Registration', 'fluentformpro');
        }

        return $settings;
    }

}
