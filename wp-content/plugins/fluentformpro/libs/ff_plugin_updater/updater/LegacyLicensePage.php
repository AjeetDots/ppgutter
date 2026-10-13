<?php

namespace FluentFormPro\FluentUpdater;

use FluentForm\Framework\Support\Arr;

defined('ABSPATH') or die;

/**
 * Compatibility UI for installations where Free predates the Vue license page.
 */
class LegacyLicensePage
{
    private const AJAX_NONCE_ACTION = 'fluentformpro_license';

    /** @var LicenseSettings */
    private $settings;

    public function __construct(LicenseSettings $settings)
    {
        $this->settings = $settings;
    }

    public function register()
    {
        add_action('fluentform/global_settings_component_license_page', [$this, 'render']);
        add_action('wp_ajax_fluentformpro_license_activate', [$this, 'activate']);
        add_action('wp_ajax_fluentformpro_license_deactivate', [$this, 'deactivate']);
        add_action('wp_ajax_fluentformpro_license_status', [$this, 'status']);
    }

    public function activate()
    {
        if (!$this->authorize()) {
            return;
        }

        $licenseKey = Arr::get(wp_unslash($_POST), 'license_key', '');
        $licenseKey = is_scalar($licenseKey) ? sanitize_text_field((string)$licenseKey) : '';

        if (!$licenseKey) {
            wp_send_json_error([
                'message' => __('Please enter a License key.', 'fluentformpro'),
            ], 422);
        }

        $result = $this->settings->activateLicense($licenseKey);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ], 422);
        }

        wp_send_json_success($result);
    }

    public function deactivate()
    {
        if (!$this->authorize()) {
            return;
        }

        $result = $this->settings->deactivateLicense();

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ], 502);
        }

        wp_send_json_success($result);
    }

    public function status()
    {
        if (!$this->authorize()) {
            return;
        }

        wp_send_json_success([
            'message'      => __('License status refreshed.', 'fluentformpro'),
            'license_data' => $this->settings->provideLicenseStatus(true),
        ]);
    }

    /**
     * The legacy AJAX transport needs its own nonce and the same permission as REST.
     */
    private function authorize()
    {
        if (!check_ajax_referer(self::AJAX_NONCE_ACTION, '_nonce', false)) {
            wp_send_json_error([
                'message' => __('Your session expired. Please refresh the page and try again.', 'fluentformpro'),
            ], 403);

            return false;
        }

        if (!\FluentForm\App\Modules\Acl\Acl::hasPermission('fluentform_full_access')) {
            wp_send_json_error([
                'message' => __('You do not have permission to manage this License.', 'fluentformpro'),
            ], 403);

            return false;
        }

        return true;
    }

    public function render()
    {
        if (!\FluentForm\App\Modules\Acl\Acl::hasPermission('fluentform_full_access')) {
            return;
        }

        $license = $this->settings->provideLicenseStatus();
        $status = Arr::get($license, 'status', 'unregistered');
        $maskedKey = Arr::get($license, 'license_key_masked', '');
        $hasLicense = $maskedKey !== '';
        $statusMap = [
            'valid'        => [__('Active', 'fluentformpro'), 'success'],
            'active'       => [__('Active', 'fluentformpro'), 'success'],
            'expired'      => [__('Expired', 'fluentformpro'), 'danger'],
            'disabled'     => [__('Disabled', 'fluentformpro'), 'danger'],
            'invalid'      => [__('Invalid', 'fluentformpro'), 'danger'],
            'error'        => [__('Error', 'fluentformpro'), 'danger'],
            'unregistered' => [__('Not Activated', 'fluentformpro'), 'neutral'],
        ];
        $statusMeta = Arr::get(
            $statusMap,
            $status,
            [ucwords(str_replace('_', ' ', $status)), 'neutral']
        );
        $scriptConfig = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::AJAX_NONCE_ACTION),
        ];
        ?>
        <div class="ff_card fluent_activation_wrapper" id="ffpro-license-panel">
            <div class="ff_card_head">
                <h5 class="title"><?php echo esc_html__('License', 'fluentformpro'); ?></h5>
                <p class="text"><?php echo esc_html__('Manage updates and support for Fluent Forms Pro.', 'fluentformpro'); ?></p>
            </div>

            <div class="ffpro-license-message" role="status" aria-live="polite"></div>

            <?php if ($hasLicense) : ?>
                <div class="ffpro-license-card">
                    <div class="ffpro-license-card-head">
                        <div class="ffpro-license-card-title">
                            <h3><?php echo esc_html(Arr::get($license, 'product_title', 'Fluent Forms Pro')); ?></h3>
                            <?php if (Arr::get($license, 'variation_title')) : ?>
                                <p><?php echo esc_html(Arr::get($license, 'variation_title')); ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="ffpro-license-pill is-<?php echo esc_attr(Arr::get($statusMeta, 1)); ?>">
                            <?php echo esc_html(Arr::get($statusMeta, 0)); ?>
                        </span>
                    </div>

                    <ul class="ffpro-license-meta">
                        <?php if (Arr::get($license, 'expires_human')) : ?>
                            <li>
                                <span class="ffpro-meta-label"><?php echo esc_html__('Expires', 'fluentformpro'); ?></span>
                                <span class="ffpro-meta-value"><?php echo esc_html(Arr::get($license, 'expires_human')); ?></span>
                            </li>
                        <?php endif; ?>
                        <li>
                            <span class="ffpro-meta-label"><?php echo esc_html__('License Key', 'fluentformpro'); ?></span>
                            <span class="ffpro-meta-value ffpro-license-key"><?php echo esc_html($maskedKey); ?></span>
                        </li>
                        <?php if (Arr::get($license, 'customer_name') || Arr::get($license, 'customer_email_masked')) : ?>
                            <li>
                                <span class="ffpro-meta-label"><?php echo esc_html__('Licensed To', 'fluentformpro'); ?></span>
                                <span class="ffpro-meta-value">
                                    <?php echo esc_html(Arr::get($license, 'customer_name') ?: Arr::get($license, 'customer_email_masked')); ?>
                                    <?php if (Arr::get($license, 'customer_name') && Arr::get($license, 'customer_email_masked')) : ?>
                                        <small><?php echo esc_html(Arr::get($license, 'customer_email_masked')); ?></small>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endif; ?>
                    </ul>

                    <div class="ffpro-license-card-footer">
                        <div class="ffpro-license-checked">
                            <?php if (Arr::get($license, 'last_checked_human')) : ?>
                                <span><?php echo esc_html__('Last checked:', 'fluentformpro'); ?> <?php echo esc_html(Arr::get($license, 'last_checked_human')); ?></span>
                            <?php endif; ?>
                            <button type="button" class="ffpro-license-link" data-license-action="status"><?php echo esc_html__('Refresh', 'fluentformpro'); ?></button>
                            <?php if (Arr::get($license, 'account_url')) : ?>
                                <a href="<?php echo esc_url(Arr::get($license, 'account_url')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Your Account', 'fluentformpro'); ?></a>
                            <?php endif; ?>
                            <?php if (Arr::get($license, 'support_url')) : ?>
                                <a href="<?php echo esc_url(Arr::get($license, 'support_url')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Get Support', 'fluentformpro'); ?></a>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="el-button el-button--primary is-plain el-button--small" data-license-action="deactivate"><?php echo esc_html__('Deactivate', 'fluentformpro'); ?></button>
                    </div>
                </div>
            <?php else : ?>
                <div class="ffpro-license-form">
                    <label for="ffpro-license-key"><strong><?php echo esc_html__('License Key', 'fluentformpro'); ?></strong></label>
                    <div class="ffpro-license-form-controls">
                        <span class="el-input el-input-gray">
                            <input id="ffpro-license-key" type="password" class="el-input__inner" autocomplete="off" spellcheck="false" />
                        </span>
                        <button type="button" class="el-button el-button--primary" data-license-action="activate"><?php echo esc_html__('Verify License', 'fluentformpro'); ?></button>
                    </div>
                    <?php if (Arr::get($license, 'purchase_url')) : ?>
                        <p><?php echo esc_html__("Don't have a License key?", 'fluentformpro'); ?> <a href="<?php echo esc_url(Arr::get($license, 'purchase_url')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Purchase one here', 'fluentformpro'); ?></a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <style>
            #ffpro-license-panel .ffpro-license-message { margin: 0 0 12px; }
            #ffpro-license-panel .ffpro-license-message.is-error { color: #ff6154; }
            #ffpro-license-panel .ffpro-license-message.is-success { color: #00b27f; }
            #ffpro-license-panel .ffpro-license-card { overflow: hidden; border: 1px solid #e4e7ed; border-radius: 6px; background: #fff; }
            #ffpro-license-panel .ffpro-license-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 20px 24px; border-bottom: 1px solid #ebeef5; }
            #ffpro-license-panel .ffpro-license-card-title h3 { margin: 0; color: #303133; font-size: 18px; line-height: 26px; }
            #ffpro-license-panel .ffpro-license-card-title p { margin: 3px 0 0; color: #606266; font-size: 14px; line-height: 20px; }
            #ffpro-license-panel .ffpro-license-pill { display: inline-flex; align-items: center; padding: 4px 12px; border: 1px solid transparent; border-radius: 999px; font-size: 13px; font-weight: 600; line-height: 20px; white-space: nowrap; }
            #ffpro-license-panel .ffpro-license-pill.is-success { border-color: #b3e8d9; background: #e6fff8; color: #008f66; }
            #ffpro-license-panel .ffpro-license-pill.is-danger { border-color: #ffc0bb; background: #fff0ef; color: #d94f44; }
            #ffpro-license-panel .ffpro-license-pill.is-neutral { border-color: #dadbdd; background: #f5f7fa; color: #606266; }
            #ffpro-license-panel .ffpro-license-meta { margin: 0; padding: 8px 24px; list-style: none; }
            #ffpro-license-panel .ffpro-license-meta li { display: flex; align-items: baseline; justify-content: space-between; gap: 20px; margin: 0; padding: 13px 0; border-bottom: 1px dashed #ebeef5; font-size: 14px; }
            #ffpro-license-panel .ffpro-license-meta li:last-child { border-bottom: 0; }
            #ffpro-license-panel .ffpro-meta-label { color: #606266; }
            #ffpro-license-panel .ffpro-meta-value { color: #303133; font-weight: 500; text-align: right; overflow-wrap: anywhere; }
            #ffpro-license-panel .ffpro-meta-value small { display: block; margin-top: 3px; color: #909399; font-size: 13px; font-weight: 400; }
            #ffpro-license-panel .ffpro-license-key { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
            #ffpro-license-panel .ffpro-license-card-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 24px; border-top: 1px solid #ebeef5; background: #f5f7fa; }
            #ffpro-license-panel .ffpro-license-checked { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; color: #606266; font-size: 13px; }
            #ffpro-license-panel .ffpro-license-checked a,
            #ffpro-license-panel .ffpro-license-link { padding: 0; border: 0; background: transparent; color: #1a7efb; font: inherit; font-weight: 500; text-decoration: none; cursor: pointer; }
            #ffpro-license-panel .ffpro-license-form-controls { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
            #ffpro-license-panel .ffpro-license-form-controls .el-input { width: min(100%, 470px); }
            #ffpro-license-panel .ffpro-license-form p { margin: 10px 0 0; color: #606266; }
            #ffpro-license-panel[aria-busy="true"] { opacity: .72; }
            @media (max-width: 600px) {
                #ffpro-license-panel .ffpro-license-card-head,
                #ffpro-license-panel .ffpro-license-meta,
                #ffpro-license-panel .ffpro-license-card-footer { padding-left: 16px; padding-right: 16px; }
                #ffpro-license-panel .ffpro-license-meta li { align-items: flex-start; flex-direction: column; gap: 3px; }
                #ffpro-license-panel .ffpro-meta-value { text-align: left; }
            }
        </style>
        <script>
            (function($) {
                'use strict';

                var config = <?php echo wp_json_encode($scriptConfig); ?>;
                var panel = $('#ffpro-license-panel');
                var message = panel.find('.ffpro-license-message');
                var requestPending = false;

                function showMessage(text, isError) {
                    message.removeClass('is-error is-success')
                        .addClass(isError ? 'is-error' : 'is-success')
                        .text(text || '');
                }

                function request(action, extra) {
                    if (requestPending) {
                        return;
                    }

                    var buttons = panel.find('[data-license-action]');
                    var payload = $.extend({
                        action: 'fluentformpro_license_' + action,
                        _nonce: config.nonce
                    }, extra || {});

                    requestPending = true;
                    buttons.prop('disabled', true);
                    panel.attr('aria-busy', 'true');
                    showMessage('', false);

                    $.post(config.ajaxUrl, payload)
                        .done(function(response) {
                            var data = response && response.data ? response.data : {};
                            showMessage(data.message || '', false);
                            window.location.reload();
                        })
                        .fail(function(xhr) {
                            var body = xhr.responseJSON || {};
                            var data = body.data || {};
                            showMessage(data.message || <?php echo wp_json_encode(__('The License request failed. Please try again.', 'fluentformpro')); ?>, true);
                            requestPending = false;
                            buttons.prop('disabled', false);
                            panel.removeAttr('aria-busy');
                        });
                }

                panel.on('click', '[data-license-action]', function() {
                    var action = $(this).data('license-action');
                    var extra = {};

                    if (action === 'activate') {
                        extra.license_key = panel.find('#ffpro-license-key').val();
                    }

                    request(action, extra);
                });
            })(jQuery);
        </script>
        <?php
    }
}
