<?php

namespace FluentFormPro\Integrations\Mailster;

defined('ABSPATH') or die;

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManagerController
{
    public $disableGlobalSettings = 'yes';

    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Mailster',
            'mailster',
            '_fluentform_mailster_settings',
            'mailster_feed',
            36
        );

        $globalModules = get_option('fluentform_global_modules_status');

        $isActive = isset($globalModules['mailster']) ? $globalModules['mailster'] : false;

        if (!function_exists('mailster') && $isActive == 'yes') {
            // add_action('admin_notices', array($this, 'requiredPlugin'));
        }

//        add_filter('fluentform/notifying_async_mailster', '__return_false');

        $this->logo = fluentFormMix('img/integrations/mailster.png');

        $this->description = "Send Beautiful Email Newsletters in WordPress. Join more than 26,000 people worldwide and use Mailster to grow your business.";
	    add_filter('fluentform/save_integration_value_' . $this->integrationKey, [$this, 'validate'], 10, 3);

        $this->registerAdminHooks();
    }

	public function validate($settings, $integrationId, $formId)
	{
		$error = false;
		$errors = array();
		foreach ($this->getFields() as $field){
			if ($field['required'] && empty($settings[$field['key']])) {
				$error = true;
				// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
				$errors[$field['key']] = [__($field['label'].' is required', 'fluentformpro')];
			}
		}
		if ($error){
			wp_send_json_error([
				'message' => __('Validation Failed', 'fluentformpro'),
				'errors'  => $errors
			], 423);
		}
		return $settings;
	}

    public function requiredPlugin()
    {
        $pluginInfo = $this->getMailsterInstallationDetails();

        $class = 'notice notice-error';

        $install_url_text = 'Click Here to Install the Plugin';

        if ($pluginInfo->action == 'activate') {
            $install_url_text = 'Click Here to Activate the Plugin';
        }

        $message = __('Fluent Forms Mailster Integration Requires Mailster Plugin', 'fluentformpro') . '<b><a href="' . $pluginInfo->url
            . '">' . $install_url_text . '</a></b>';

        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), __($message, 'fluentformpro'));
    }

    protected function getMailsterInstallationDetails()
    {
        $activation = (object)[
            'action' => 'install',
            'url' => ''
        ];

        $allPlugins = get_plugins();

        if (isset($allPlugins['mailster/mailster.php'])) {
            $url = wp_nonce_url(
                self_admin_url('plugins.php?action=activate&plugin=mailster/mailster.php'),
                'activate-plugin_mailster/mailster.php'
            );

            $activation->action = 'activate';
        } else {
            $api = (object)[
                'slug' => 'mailster'
            ];

            $url = wp_nonce_url(
                self_admin_url('update.php?action=install-plugin&plugin=' . $api->slug),
                'install-plugin_' . $api->slug
            );
        }

        $activation->url = $url;

        return $activation;
    }

    public function addGlobalMenu($setting)
    {
        return $setting;
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'category' => '',
            'disable_global_settings' => 'yes',
            'logo' => $this->logo,
            'title' => $this->title . ' Integration',
            'is_active' => $this->isConfigured()
        ];

        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name' => '',
            'list_id' => '',
            'other_fields' => [
                [
                    'item_value' => '',
                    'label' => ''
                ]
            ],
            'conditionals' => [
                'conditions' => [],
                'status' => false,
                'type' => 'all'
            ],
            'enabled' => true
        ];
    }

    public function getSettingsFields($settings, $formId = null)
    {
        if (!function_exists('mailster')) {
            return [];
        }

        $allFieldSettings = [
            'fields' => [
                [
                    'key' => 'name',
                    'label' => __('Feed Name', 'fluentformpro'),
                    'required' => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component' => 'text'
                ],
                [
                    'key' => 'list_id',
                    'label' => __('Mailster Lists', 'fluentformpro'),
                    'placeholder' => __('Select Mailster List', 'fluentformpro'),
                    'required' => true,
                    'component' => 'select',
                    'tips' => __('Select Mailster List', 'fluentformpro'),
                    'options' => $this->getLists()
                ],
            ],
            'integration_title' => $this->title
        ];

        $getFields = $this->getFields();

        $allFieldSettings['fields'] = array_merge($allFieldSettings['fields'], $getFields, [
            [
                'require_list' => false,
                'key' => 'conditionals',
                'label' => __('Conditional Logics', 'fluentformpro'),
                'tips' => __('Allow this integration conditionally based on your submission values', 'fluentformpro'),
                'component' => 'conditional_block'
            ],
            [
                'require_list' => false,
                'key' => 'enabled',
                'label' => __('Status', 'fluentformpro'),
                'component' => 'checkbox-single',
                'checkbox_label' => __('Enable this feed', 'fluentformpro')
            ]
        ]);

        return $allFieldSettings;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
    }

    protected function getLists()
    {
        $modifiedList = [];

        $lists = mailster('lists')->get();

        foreach ($lists as $field) {
            $modifiedList[$field->ID] = $field->name;
        }

        return $modifiedList;
    }

    protected function getFields()
    {
        return [
            [
                'key' => 'email',
                'label' => __('Enter Email', 'fluentformpro'),
                'required' => true,
                'tips' => __('Enter Email', 'fluentformpro'),
                'component' => 'value_text'
            ],
            [
                'key' => 'firstname',
                'label' => __('Enter First Name', 'fluentformpro'),
                'required' => false,
                'tips' => __('Enter First Name', 'fluentformpro'),
                'component' => 'value_text'
            ],
            [
                'key' => 'lastname',
                'label' => __('Enter Last Name', 'fluentformpro'),
                'required' => false,
                'tips' => __('Enter Last Name', 'fluentformpro'),
                'component' => 'value_text'
            ],
            [
                'key' => 'mailster_status',
                'label' => __('Select Status', 'fluentformpro'),
                'required' => false,
                'tips' => __('Select Status', 'fluentformpro'),
                'component' => 'select',
                'options' => [
                    "value_0" => __('Pending', 'fluentformpro'),
                    "value_1" => __('Subscribed', 'fluentformpro'),
                    "value_2" => __('Unsubscribed', 'fluentformpro'),
                    "value_3" => __('Hardbounced', 'fluentformpro')
                ]
            ],
            [
                'key' => 'referer',
                'label' => __('Enter Referer Name', 'fluentformpro'),
                'required' => false,
                'tips' => __('Enter Referer name', 'fluentformpro'),
                'component' => 'value_text'
            ],
            [
                'key' => 'other_fields',
                'require_list' => false,
                'required' => false,
                'label' => __('Custom Fields', 'fluentformpro'),
                'tips' => __('Map Custom fields according with Fluent Forms fields.', 'fluentformpro'),
                'component' => 'dropdown_label_repeater',
                'field_label' => __('Custom Field Key', 'fluentformpro'),
                'value_label' => __('Custom Field Value', 'fluentformpro')
            ]
        ];
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $subscriber = [
            'list_id' => $feedData['list_id']
        ];

        $getFields = $this->getFields();
        foreach ($getFields as $fieldValue) {
            if (!empty($feedData[$fieldValue['key']])) {
                if ($fieldValue['key'] == 'other_fields') {
                    $otherFields = ArrayHelper::get($feedData, 'other_fields');

                    foreach ($otherFields as $other) {
                        if (!empty($other['item_value'])) {
                            $subscriber[$other['label']] = $other['item_value'];
                        }
                    }
                } else {
                    if (strpos($fieldValue['key'], 'mailster') !== false) {
                        $arr = explode('_', $fieldValue['key']);
                        $key = $arr[1];
                        $subscriber[$key] = ArrayHelper::get($feedData, $fieldValue['key']);
						if (strpos($subscriber[$key], 'value_') !== false) {
							$subscriber[$key] = explode('_', $subscriber[$key])[1];
						}
                    } else {
                        $subscriber[$fieldValue['key']] = ArrayHelper::get($feedData, $fieldValue['key']);
                    }
                }
            }
        }

        if (function_exists('mailster') && function_exists('mailster_subscribe')) {
            $list_id = $subscriber['list_id'];
            $email = $subscriber['email'];
            unset($subscriber['list_id']);
            unset($subscriber['email']);
            
            // Use mailster_subscribe function which handles confirmation properly
            $result = mailster_subscribe(
                $email,
                $subscriber,
                $list_id,
                false,
                true,
                false
            );
            
            if ($result) {
                do_action('fluentform/integration_action_result', $feed, 'success',
                    __('Mailster feed has been successfully initialed and pushed data', 'fluentformpro'));
            } else {
                do_action('fluentform/integration_action_result', $feed, 'failed', 
                    __('Failed to add subscriber to Mailster', 'fluentformpro'));
            }
        } else {
            do_action('fluentform/integration_action_result', $feed, 'failed', __('Please install or active Mailster plugin.', 'fluentformpro'));
        }
    }

    public function isConfigured()
    {
        return true;
    }
}
