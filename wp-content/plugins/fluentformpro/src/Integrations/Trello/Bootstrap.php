<?php

namespace FluentFormPro\Integrations\Trello;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentFormPro\Components\RepeaterField;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Trello',
            'trello',
            '_fluentform_Trello_settings',
            'fluentform_Trello_feed',
            16
        );

        $this->logo = fluentFormMix('img/integrations/trello.png');

        $this->description = 'Fluent Forms Trello Module allows you to create Trello card from submitting forms.';

        $this->registerAdminHooks();

        add_action('wp_ajax_fluentform_pro_trello_board_config', array($this, 'getBoardConfigOptions'));
        // add_filter('fluentform/notifying_async_trello', '__return_false');

    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('Trello API Settings', 'fluentformpro'),
            'menu_description'   => __('Trello is an integrated email marketing, marketing automation, and small business CRM. Save time while growing your business with sales automation. Use Fluent Forms to collect customer information and automatically add it to your Trello list. If you don\'t have an Trello account, you can <a href="https://www.trello.com/" target="_blank">sign up for one here.</a>', 'fluentformpro'),
            'valid_message'      => __('Your Trello configuration is valid', 'fluentformpro'),
            'invalid_message'    => __('Your Trello configuration is invalid', 'fluentformpro'),
            'save_button_text'   => __('Verify Trello ', 'fluentformpro'),
            'config_instruction' => $this->getConfigInstractions(),
            'fields'             => [
                'accessToken' => [
                    'type'        => 'text',
                    'placeholder' => 'access token Key',
                    'label_tips'  => __("Enter your Trello access token Key, if you do not have <br>Please click here to get yours", 'fluentformpro'),
                    'label'       => __('Trello access Key', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'      => true,
            'discard_settings'   => [
                'section_description' => __('Your Trello API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect Trello', 'fluentformpro'),
                'data'                => [
                    'accessToken' => ''
                ],
                'show_verify'         => true
            ]
        ];
    }

    public function getGlobalSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);
        if (!$globalSettings) {
            $globalSettings = [];
        }
        $defaults = [
            'accessToken' => '',
            'status'      => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function getBoardConfigOptions()
    {
        Acl::verify('fluentform_forms_manager');

        $requestInfo = $this->app->request->get('settings');
        $boardConfig = ArrayHelper::get($requestInfo, 'board_config');

        $boardId = ArrayHelper::get($boardConfig, 'board_id');

        $data = [
            'board_id'       => $this->getBoards(),
            'board_list_id'  => [],
            'board_label_id' => [],
            'member_ids'     => []
        ];

        if ($boardId) {
            $data['board_list_id'] = $this->getBoardLists($boardId);
            $data['board_label_id'] = $this->getBoardLabels($boardId);
            $data['member_ids'] = $this->getBoardMembers($boardId);
        }

        wp_send_json_success([
            'fields_options' => $data
        ], 200);
    }

    /*
    * Saving The Global Settings
    *
    */
    public function saveGlobalSettings($settings)
    {
        if (!$settings['accessToken']) {
            $integrationSettings = [
                'accessToken' => '',
                'status'      => false
            ];

            // Update the reCaptcha details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, 'no');

            wp_send_json_success([
                'message' => __('Your settings has been updated and discarded', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        try {
            $settings['status'] = false;
            update_option($this->optionKey, $settings, 'no');
            $api = new TrelloApi($settings['accessToken'], null);
            $auth = $api->auth_test();
            if (isset($auth['id'])) {
                $settings['status'] = true;
                update_option($this->optionKey, $settings, 'no');
                wp_send_json_success([
                    'status'  => true,
                    'message' => __('Your settings has been updated!', 'fluentformpro')
                ], 200);
            }
            throw new \Exception(__('Invalid Credentials', 'fluentformpro'), 400);

        } catch (\Exception $e) {
            wp_send_json_error([
                'status'  => false,
                'message' => $e->getMessage()
            ], $e->getCode());
        }
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-trello-settings'),
            'configure_message'     => __('Trello is not configured yet! Please configure your Trello API first', 'fluentformpro'),
            'configure_button_text' => __('Set Trello API', 'fluentformpro')
        ];
        return $integrations;
    }

    protected function getConfigInstractions()
    {
        ob_start();
        ?>
        <div><h4>To Authenticate Trello you need an access token.</h4>
            <ol>
                <li>Click here to <a
                        href="https://trello.com/1/authorize?expiration=never&name=FluentForm%20Pro&scope=read,write,account&response_type=token&key=f79dfb43d0becc887dc488e99bed0687"
                        target="_blank">Get Access Token</a>.
                </li>
                <li>Then login and allow with your trello account.</li>
                <li>Copy your access token and paste bellow field then click Verify Trello.</li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'         => '',
            'list_id'      => '', // This is the borad id
            'board_config' => [
                'board_id'       => '',
                'board_list_id'  => '',
                'board_label_id' => '',
                'member_ids'     => []
            ],
            'card_name'    => '',
            'card_description'    => '',
            'card_pos'    => 'bottom',
            'conditionals' => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'      => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields'              => [
                [
                    'key'         => 'name',
                    'label'       => __('Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'            => 'board_config',
                    'label'          => __('Trello Configuration', 'fluentformpro'),
                    'required'       => true,
                    'component'      => 'chained_select',
                    'primary_key'    => 'board_id',
                    'fields_options' => [
                        'board_id'       => [],
                        'board_list_id'  => [],
                        'board_label_id' => [],
                        'member_ids'     => []
                    ],
                    'options_labels' => [
                        'board_id'       => [
                            'label'       => __('Select Board', 'fluentformpro'),
                            'type'        => 'select',
                            'placeholder' => __('Select Board', 'fluentformpro')
                        ],
                        'board_list_id'  => [
                            'label'       => __('Select List', 'fluentformpro'),
                            'type'        => 'select',
                            'placeholder' => __('Select Board List', 'fluentformpro')
                        ],
                        'board_label_id' => [
                            'label'       => __('Select Card Label', 'fluentformpro'),
                            'type'        => 'multi-select',
                            'placeholder' => __('Select Card Label', 'fluentformpro')
                        ],
                        'member_ids'     => [
                            'label'       => __('Select Members', 'fluentformpro'),
                            'type'        => 'multi-select',
                            'placeholder' => __('Select Members', 'fluentformpro')
                        ]
                    ],
                    'remote_url'     => admin_url('admin-ajax.php?action=fluentform_pro_trello_board_config')
                ],
                [
                    'key' => 'card_name',
                    'label' => __('Card Title', 'fluentformpro'),
                    'required' => true,
                    'placeholder' => __('Trello Card Title', 'fluentformpro'),
                    'component' => 'value_text'
                ],
                [
                    'key' => 'card_description',
                    'label' => __('Card Content', 'fluentformpro'),
                    'placeholder' => __('Trello Card Content', 'fluentformpro'),
                    'component' => 'value_textarea'
                ],
                [
                    'key' => 'card_pos',
                    'label' => __('Card Position', 'fluentformpro'),
                    'required' => true,
                    'placeholder' => __('Position', 'fluentformpro'),
                    'component' => 'radio_choice',
                    'options' => [
                        'bottom' => __('Bottom', 'fluentformpro'),
                        'top' => __('Top', 'fluentformpro')
                    ]
                ],
                [
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluentformpro'),
                    'tips'         => __('Allow Gist integration conditionally based on your submission values', 'fluentformpro'),
                    'component'    => 'conditional_block'
                ],
                [
                    'key'             => 'enabled',
                    'label'           => __('Status', 'fluentformpro'),
                    'component'       => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'fluentformpro')
                ]
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];
    }

    protected function getBoards()
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }
        $boards = $api->getBoards();

        $formattedBoards = [];
        foreach ($boards as $board) {
            if (is_array($board)) {
                $formattedBoards[$board['id']] = $board['name'];
            }
        }

        return $formattedBoards;
    }

    protected function getBoardLists($boardId)
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }

        $lists = $api->getLists($boardId);

        if(is_wp_error($lists)) {
            return [];
        }

        $formattedLists = [];
        foreach ($lists as $list) {
            if (is_array($list)) {
                $formattedLists[$list['id']] = $list['name'];
            }
        }

        return $formattedLists;
    }

    protected function getBoardLabels($boardId)
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }

        $labels = $api->getLabels($boardId);

        if(is_wp_error($labels)) {
            return [];
        }

        $formattedLabels = [];
        foreach ($labels as $label) {
            if (is_array($label)) {
                $formattedLabels[$label['id']] = $label['name'] ?: $label['color'];
            }
        }

        return $formattedLabels;
    }

    protected function getBoardMembers($boardId)
    {
        $api = $this->getApiClient();
        if (!$api) {
            return [];
        }

        $members = $api->getMembers($boardId);

        if(is_wp_error($members)) {
            return [];
        }

        $formattedMembers = [];
        foreach ($members as $member) {
            if (is_array($member)) {
                $formattedMembers[$member['id']] = $member['fullName'] . ' (@' . $member['username'] . ')';
            }
        }

        return $formattedMembers;
    }


    /**
     * Prepare Trello forms for feed field.
     *
     * @return array
     */

    /*
     * Submission Broadcast Handler
     */

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        $listId = ArrayHelper::get($feedData, 'board_config.board_list_id');

        if(!$listId) {
            return;
        }

        $cardDescription = ArrayHelper::get($feedData, 'card_description');
        $cardDescShortcode = ArrayHelper::get($feed, 'settings.card_description');
        if ($cardDescShortcode) {
            $inputs = FormFieldsParser::getEntryInputs($form, ['raw']);
            $cardDescInputName = Helper::getInputNameFromShortCode($cardDescShortcode);
            if ($cardDescInputName && $element = ArrayHelper::get($inputs, $cardDescInputName)) {
                if (
                    'tabular_grid' == ArrayHelper::get($element, 'element') &&
                    $value = Helper::getTabularGridFormatValue($formData[$cardDescInputName], $element, "\n", ",  ")
                ) {
                    $cardDescription = $value;
                } elseif (
                    'repeater_field' == ArrayHelper::get($element, 'element') &&
                    $value = RepeaterField::getResponseAsText(ArrayHelper::get($formData, $cardDescInputName), ArrayHelper::get($element, 'raw.fields'))
                ) {
                    $cardDescription = $value;
                }
            }
        }

        $data = [
            'name'   => ArrayHelper::get($feedData, 'card_name'),
            'desc'   => str_replace("<br />", '', $cardDescription),
            'pos'    => ArrayHelper::get($feedData, 'card_pos'),
            'idList' => $listId
        ];

        if($members = ArrayHelper::get($feedData, 'board_config.member_ids')) {
            $data['idMembers'] = implode(',', $members);
        }

        $labels = ArrayHelper::get($feedData, 'board_config.board_label_id');

        if($labels) {
            if(is_array($labels)) {
                $data['idLabels'] = implode(',', $labels);
            } else {
                $data['idLabels'] = $labels;
            }
        }
    
        $data = apply_filters_deprecated(
            'fluentform_integration_data_' . $this->integrationKey,
            [
                $data,
                $feed,
                $entry
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/integration_data_' . $this->integrationKey,
            'Use fluentform/integration_data_' . $this->integrationKey . ' instead of fluentform_integration_data_' . $this->integrationKey
        );
        $data = apply_filters('fluentform/integration_data_' . $this->integrationKey, $data, $feed, $entry);


        // Now let's prepare the data and push to Trello
        $api = $this->getApiClient();

        $response = $api->addCard($data);

        if (!is_wp_error($response) && isset($response['id'])) {
            do_action('fluentform/integration_action_result', $feed, 'success', __('Trello feed has been successfully initialed and pushed data', 'fluentformpro'));
        } else {
            $error = is_wp_error($response) ? $response->get_error_messages() : __('API Error when submitting Data in trello server', 'fluentformpro');
            do_action('fluentform/integration_action_result', $feed, 'failed', $error);
        }
    }

    protected function getApiClient()
    {
        $settings = get_option($this->optionKey);
        return new TrelloApi(
            $settings['accessToken']
        );
    }

    function getMergeFields($list, $listId, $formId)
    {
        return $list;
    }
}
