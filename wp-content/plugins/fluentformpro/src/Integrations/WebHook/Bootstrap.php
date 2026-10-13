<?php

namespace FluentFormPro\Integrations\WebHook;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Modules\Acl\Acl;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap
{
    protected $app = null;
    protected $notifier = null;
    protected $title = 'WebHook';

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->registerHooks();
    }

    public function registerHooks()
    {
        $isEnabled = $this->isEnabled();
        add_filter('fluentform/global_addons', function ($addons) use ($isEnabled) {
            $addons['webhook'] = [
                'title' => 'Webhooks',
                'category' => 'crm',
                'description' => __('Broadcast your Fluent Forms Submission to any web api endpoint with the powerful webhook module.', 'fluentformpro'),
                'logo' => fluentFormMix('img/integrations/webhook.png'),
                'enabled' => ($isEnabled) ? 'yes' : 'no'
            ];
            return $addons;
        });

        if (!$isEnabled) {
            return;
        }

//        add_filter('fluentform/notifying_async_webhook', '__return_false');

        add_filter('fluentform/global_notification_active_types', function ($types) {
            $types['fluentform_webhook_feed'] = 'webhook';
            return $types;
        }, 20, 1);
        add_action('fluentform/integration_notify_fluentform_webhook_feed', array($this, 'notify'), 20, 4);

        add_filter('fluentform/form_settings_menu', array($this, 'addFormMenu'), 99, 1);
        add_action('wp_ajax_fluentform-get-webhooks', function () {
            Acl::verify('fluentform_forms_manager', absint($this->app->request->get('form_id')));
            $this->getApiClient()->getWebHooks();
        });
        add_action('wp_ajax_fluentform-save-webhook', function () {
            Acl::verify('fluentform_forms_manager', absint($this->app->request->get('form_id')));
            $this->getApiClient()->saveWebHook();
        });
        add_action('wp_ajax_fluentform-delete-webhook', function () {
            $formId = absint($this->app->request->get('form_id'));
            Acl::verify('fluentform_forms_manager', $formId);
            $this->getApiClient()->deleteWebHook();
        });
    }

    public function addFormMenu($settingsMenus)
    {
        $settingsMenus['webhook'] = array(
            'slug' => 'form_settings',
            'hash' => 'webhook',
            'route' => '/webhook',
            'title' => $this->title,
        );
        return $settingsMenus;
    }

    /*
     * For Handling Notifications broadcast
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $api = $this->getApiClient();
        $response = $api->notify($feed, $formData, $entry, $form);

        if(is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
            return;
        }

        $code = ArrayHelper::get($response, 'response.code');
        if($code < 300) {
            // it's a success
            do_action('fluentform/integration_action_result', $feed, 'success',
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                __('Webhook payload has been fired. Status Code: ' . $code, 'fluentformpro'));
            return;
        }

        do_action('fluentform/integration_action_result', $feed, 'failed',
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            __('FAILED. Status Code: ' . $code, 'fluentformpro'));
    }

    protected function getApiClient()
    {
        return new Client($this->app);
    }

    public function isEnabled()
    {
        $globalModules = get_option('fluentform_global_modules_status');
        return $globalModules && isset($globalModules['webhook']) && $globalModules['webhook'] == 'yes';
    }

}
