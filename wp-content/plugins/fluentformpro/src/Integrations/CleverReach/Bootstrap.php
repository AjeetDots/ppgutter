<?php

namespace FluentFormPro\Integrations\CleverReach;

defined('ABSPATH') or die;

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'CleverReach',
            'cleverreach',
            '_fluentform_cleverreach_settings',
            'cleverreach_feed',
            36
        );

//        add_filter('fluentform/notifying_async_cleverreach', '__return_false');

        $this->logo = fluentFormMix('img/integrations/clever_reach.png');

        $this->description = 'Connect Fluent Forms with CleverReach to grow your CleverReach subscriber list.';

        $this->registerAdminHooks();

        add_action('admin_init', function () {
            if (isset($_REQUEST['ff_cleverreach_auth'])) {
                if (!current_user_can('fluentform_settings_manager')) {
                    return;
                }
                $client = $this->getRemoteClient();
                if (isset($_REQUEST['code'])) {
                    // SECURITY (PRO-17): reject callbacks not bound to the initiating admin session.
                    $state = isset($_REQUEST['state']) ? sanitize_text_field($_REQUEST['state']) : '';
                    if (!wp_verify_nonce($state, 'ff_cleverreach_oauth')) {
                        wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-cleverreach-settings'));
                        exit();
                    }
                    // Get the access token now
                    $code = sanitize_text_field($_REQUEST['code']);
                    $settings = $this->getGlobalSettings([]);
                    $settings = $client->generateAccessToken($code, $settings);
                    if (!is_wp_error($settings)) {
                        $settings['status'] = true;
                        update_option($this->optionKey, $settings, 'no');
                    }
                    wp_redirect(admin_url('admin.php?page=fluent_forms_settings#general-cleverreach-settings'));
                    exit();
                } else {
                    $client->redirectToAuthServer();
                }
                die();
            }
        });
    }

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        $settings['option_key'] = $this->optionKey;
        return new API(
            $settings
        );
    }

    public function getGlobalSettings($settings = [])
    {
        $globalSettings = get_option($this->optionKey);

        if (!$globalSettings) {
            $globalSettings = [];
        }

        $defaults = [
            'client_id'     => '',
            'client_secret' => '',
            'status'        => false,
            'access_token'  => '',
            'refresh_token' => '',
            'expire_at'     => false
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            $integrationSettings = [
                'client_id'     => '',
                'client_secret' => '',
                'status'        => false,
                'access_token'  => ''
            ];
            // Update the details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_success([
                'message' => __('Your settings has been updated', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        // Verify API key now
        try {
            $oldSettings = $this->getGlobalSettings([]);
            $oldSettings['client_id'] = sanitize_text_field($settings['client_id']);
            $oldSettings['client_secret'] = sanitize_text_field($settings['client_secret']);
            $oldSettings['status'] = false;
            update_option($this->optionKey, $oldSettings, 'no');

            $client = $this->getRemoteClient();
            $check = $client->checkForClientId();
            if (is_wp_error($check)) {
                $integrationSettings = [
                    'client_id'     => '',
                    'client_secret' => '',
                    'status'        => false,
                    'access_token'  => ''
                ];
                update_option($this->optionKey, $integrationSettings, 'no');

                wp_send_json_error([
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                    'message' => __($check->errors['invalid_client'][0], 'fluentformpro'),
                    'status'  => false
                ], 400);
            } else {
                wp_send_json_success([
                    'message'      => __('You are redirect to authenticate', 'fluentformpro'),
                    'redirect_url' => admin_url('?ff_cleverreach_auth')
                ], 200);
            }
        } catch (\Exception $exception) {
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('CleverReach Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'menu_description'   => __($this->description, 'fluentformpro'),
            'valid_message'      => __('Your CleverReach API Key is valid', 'fluentformpro'),
            'invalid_message'    => __('Your CleverReach API Key is not valid', 'fluentformpro'),
            'save_button_text'   => __('Save Settings', 'fluentformpro'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
            'config_instruction' => __($this->getConfigInstructions(), 'fluentformpro'),
            'fields'             => [
                'client_id'     => [
                    'type'        => 'text',
                    'placeholder' => __('CleverReach Client ID', 'fluentformpro'),
                    'label_tips'  => __('Enter your CleverReach Client ID', 'fluentformpro'),
                    'label'       => __('CleverReach Client ID', 'fluentformpro'),
                ],
                'client_secret' => [
                    'type'        => 'password',
                    'placeholder' => __('CleverReach App Client Secret', 'fluentformpro'),
                    'label_tips'  => __('Enter your CleverReach Client secret', 'fluentformpro'),
                    'label'       => __('CleverReach Client Secret', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'      => true,
            'discard_settings'   => [
                'section_description' => __('Your CleverReach API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect CleverReach', 'fluentformpro'),
                'data'                => [
                    'client_id'     => '',
                    'client_secret' => '',
                    'access_token'  => '',
                ],
                'show_verify'         => true
            ]
        ];
    }

    protected function getConfigInstructions()
    {
        ob_start();
        ?>
        <div>
            <h4>To Authenticate CleverReach you have to enable your API first</h4>
            <ol>
                <li>Go to Your CleverReach account dashboard, Click on the profile icon on the top right
                    corner. Click on My Account >> Interfaces >> REST Api then click on Create an OAuth App now button.
                </li>
                <li>Then give your oauth app a name >> choose REST API Version 3 >> Select the Recipients and Forms scopes >> Redirect URL should be '*' and save it.<br/>
                </li>
                <li>Paste your CleverReach account Client Id and Secret Id. Then click save settings.
                </li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-cleverreach-settings'),
            'configure_message'     => __('CleverReach is not configured yet! Please configure your CleverReach API first',
                'fluentformpro'),
            'configure_button_text' => __('Set CleverReach API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name' => '',
            'list_id' => '',
            'fieldEmailAddress' => '',
            'custom_fields'        => (object)[],
            'conditionals'         => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'resubscribe'          => false,
            'enabled'              => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields'            => [
                [
                    'key'         => 'name',
                    'label'       => __('Feed Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'         => 'list_id',
                    'label'       => __('CleverReach List', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Select CleverReach List', 'fluentformpro'),
                    'tips'        => __('Select the CleverReach list you would like to add your contacts to.',
                        'fluentformpro'),
                    'component'   => 'list_ajax_options',
                    'options'     => $this->getLists(),
                ],
                [
                    'key'                => 'custom_fields',
                    'require_list'       => true,
                    'label'              => __('Map Fields', 'fluentformpro'),
                    'tips'               => __('Associate your CleverReach merge tags to the appropriate Fluent Forms fields by selecting the appropriate form field from the list.',
                        'fluentformpro'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('CleverReach Field', 'fluentformpro'),
                    'field_label_local'  => __('Form Field', 'fluentformpro'),
                    'primary_fileds'     => [
                        [
                            'key'           => 'email',
                            'label'         => __('Email Address', 'fluentformpro'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ],
                    ]
                ],
                [
                    'require_list' => true,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow  CleverReach integration conditionally based on your submission values',
                        'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list'   => true,
                    'key'            => 'resubscribe',
                    'label'          => __('Resubscribe', 'fluentformpro'),
                    'tips'           => __('If the email address is already in this CleverReach list but has been deactivated or unsubscribed, reactivate it instead of failing. This reactivates contacts who previously unsubscribed, so only enable it where you are permitted to contact them again.',
                        'fluentformpro'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Reactivate subscriber if value is checked', 'fluentformpro'),
                ],
                [
                    'require_list'   => true,
                    'key'            => 'enabled',
                    'label'          => __('Status', 'fluentformpro'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed','fluentformpro'),
                ]
            ],
            'integration_title' => $this->title
        ];
    }

    protected function getLists()
    {
        $client = $this->getRemoteClient();

        if (!$this->isConfigured()) {
            return [];
        }

        // CleverReach documents no paging on /v3/groups, so this is additive:
        // the first request is unchanged and further pages are only probed when
        // one comes back large enough to look capped. If the params are ignored
        // the probe returns rows we already hold and the loop stops.
        //
        // Unverified: that the unpaged length equals the server page size, and
        // that `page` is 0-based. If it is 1-based, paging silently no-ops.
        //
        // $config: probe_from (rows before probing, < 1 disables), max_pages.
        $config = apply_filters('fluentform/cleverreach_groups_pagination', [
            'probe_from' => 50,
            'max_pages'  => 20,
        ]);

        $probeFrom = intval(ArrayHelper::get($config, 'probe_from', 50));
        $maxPages = max(1, intval(ArrayHelper::get($config, 'max_pages', 20)));

        $formattedLists = [];
        $pageSize = 0;
        $incomplete = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $url = 'https://rest.cleverreach.com/v3/groups';
            if ($page > 0) {
                $url .= '?pagesize=' . $pageSize . '&page=' . $page;
            }

            try {
                $lists = $client->makeRequest($url, null, 'GET');
            } catch (\Exception $exception) {
                $incomplete = $exception->getMessage();
                break;
            }

            if (is_wp_error($lists)) {
                $incomplete = $lists->get_error_message();
                break;
            }

            if (!$lists || !is_array($lists)) {
                break;
            }

            // A page of unreadable rows is not a page of duplicates.
            $parsed = 0;
            $added = 0;
            foreach ($lists as $list) {
                if (!is_array($list) || !isset($list['id']) || !isset($list['name'])) {
                    continue;
                }
                $parsed++;
                if (isset($formattedLists[$list['id']])) {
                    continue;
                }
                $formattedLists[$list['id']] = $list['name'];
                $added++;
            }

            // Rows arrived but none were usable: a degraded result on any page,
            // including the first, where it is otherwise indistinguishable from
            // an account that genuinely has no lists. An empty response already
            // broke out above, so reaching here means rows were present.
            if (!$parsed) {
                $incomplete = sprintf('page %d returned %d unreadable rows', $page, count($lists));
                break;
            }

            if (0 === $page) {
                // Only a response big enough to look truncated is worth probing.
                // CleverReach documents a 500 cap on its paged endpoints, so
                // echoing a larger unpaged count back would get the probe rejected.
                $pageSize = min(count($lists), 500);
                if ($probeFrom < 1 || count($lists) < $probeFrom) {
                    break;
                }
                continue;
            }

            if (!$added || count($lists) < $pageSize) {
                // No new ids: paging unsupported, or that was the last page.
                break;
            }
        }

        if (null === $incomplete && $page >= $maxPages) {
            // Ran out of iterations while rows were still arriving -- the one
            // case where truncation is certain rather than suspected.
            $incomplete = sprintf('stopped at the max_pages cap (%d) with rows still arriving', $maxPages);
        }

        if (null !== $incomplete) {
            // A half-filled dropdown looks exactly like a small account.
            do_action(
                'fluentform/cleverreach_groups_fetch_incomplete',
                $page,
                count($formattedLists),
                $incomplete
            );
        }

        return $formattedLists;
    }

    public function getMergeFields($list, $listId, $formId)
    {
        $client = $this->getRemoteClient();

        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $lists = $client->makeRequest('https://rest.cleverreach.com/v3/groups/' . $listId . '/attributes/', null, 'GET');

            if (!$lists || is_wp_error($lists)) {
                return [];
            }
        } catch (\Exception $exception) {
            return false;
        }

        $fields = [];
        if (is_array($lists)) {
            foreach ($lists as $merged_field) {
                if (isset($merged_field['name'])) {
                    $fields[$merged_field['name']] = $merged_field['name'];
                }
            }
        }

        return $fields;
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        // The editor stores the form input's NAME here, not an address, so the
        // usual path is the lookup below. A feed saved without an email field
        // selected has no key at all, and passing a null/array key on to
        // ArrayHelper::get returns the whole submission.
        $email = ArrayHelper::get($feedData, 'email');

        if (!is_email($email) && is_string($email) && '' !== $email) {
            $email = ArrayHelper::get($formData, $email);
        }

        if (!is_email($email)) {
            do_action('fluentform/integration_action_result', $feed, 'failed',
                __('CleverReach API call has been skipped because no valid email available', 'fluentformpro'));
            return;
        }

        $subscriber = [];
        $subscriber['list_id'] = ArrayHelper::get($feedData, 'list_id');
        $subscriber['email'] = $email;
        $subscriber['attributes'] = ArrayHelper::get($feedData, 'custom_fields');

        $client = $this->getRemoteClient();
        $response = $client->subscribe($subscriber, ArrayHelper::isTrue($feedData, 'resubscribe'));

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        } else {
            if ($client->hasResubscribed()) {
                // Duplicates fire for active receivers too, so this cannot claim
                // the contact had unsubscribed -- only that it is active now.
                $message = __('CleverReach receiver already existed in this list and has been set to active', 'fluentformpro');
            } else {
                $message = __('CleverReach has been successfully initialed and pushed data', 'fluentformpro');
            }
            do_action('fluentform/integration_action_result', $feed, 'success', $message);
        }
    }
}
