<?php

namespace FluentFormPro\Mcp;

defined('ABSPATH') || exit();

/**
 * Auto-installs FluentHub (which bundles the WP MCP Adapter) so FluentForm's
 * MCP server has a transport to speak through.
 *
 * Listens on the site-wide `fluent_toolkit/*` hook contract that FluentForm free
 * fires from its "Install FluentHub" button .The shared `fluent_toolkit/toolkit_installing`
 * guard means multiple Pro installers coexist without double-installing.
 */
class ToolkitInstaller
{
    const TOOLKIT_PLUGIN_FILE = 'fluent-toolkit/fluent-toolkit.php';

    const TOOLKIT_DOWNLOAD_URL = 'https://static.wpmanageninja.com/fluent-toolkit.zip';

    public function init()
    {
        add_filter('fluent_toolkit/can_auto_install', [$this, 'canAutoInstall']);
        add_action('fluent_toolkit/do_auto_install', [$this, 'autoInstall']);
    }

    /**
     * Tell the free plugin a one-click install is possible (it shows a button
     * instead of a manual download link) when the user may install plugins.
     */
    public function canAutoInstall($canAutoInstall)
    {
        return $this->userCanInstall() ? true : $canAutoInstall;
    }

    /**
     * Installing then activating a plugin needs BOTH caps (install_plugins is not
     * activate_plugins), and must respect a site that has locked down file mods.
     */
    private function userCanInstall()
    {
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            return false;
        }

        return current_user_can('install_plugins') && current_user_can('activate_plugins');
    }

    /**
     * Download → install → activate FluentHub, then boot its MCP adapter so
     * the endpoint can respond within the same request flow.
     */
    public function autoInstall()
    {
        if (!$this->userCanInstall()) {
            return;
        }

        // Guard against re-entrancy if the action fires more than once, and
        // against a second Fluent Pro installer racing the same shared action.
        if (did_action('fluent_toolkit/toolkit_installing')) {
            return;
        }

        do_action('fluent_toolkit/toolkit_installing');

        // Already installed — just activate + boot.
        if ($this->activateToolkit() && $this->isToolkitAdapterAvailable()) {
            return;
        }

        $installed = $this->installFromZipUrl(self::TOOLKIT_DOWNLOAD_URL);
        if (is_wp_error($installed)) {
            return;
        }

        $this->activateToolkit();
    }

    private function activateToolkit()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!file_exists(WP_PLUGIN_DIR . '/' . self::TOOLKIT_PLUGIN_FILE)) {
            return false;
        }

        if (is_plugin_active(self::TOOLKIT_PLUGIN_FILE)) {
            $this->bootToolkitAdapter();
            return true;
        }

        $result = activate_plugin(self::TOOLKIT_PLUGIN_FILE);

        if (is_wp_error($result)) {
            return false;
        }

        $this->bootToolkitAdapter();

        return true;
    }

    private function bootToolkitAdapter()
    {
        if (class_exists('\FluentToolkit\Mcp\AdapterBootstrap')) {
            \FluentToolkit\Mcp\AdapterBootstrap::boot();
        }
    }

    private function isToolkitAdapterAvailable()
    {
        if (!class_exists('\FluentToolkit\Mcp\AdapterBootstrap')) {
            return false;
        }

        if (method_exists('\FluentToolkit\Mcp\AdapterBootstrap', 'available')) {
            return (bool) \FluentToolkit\Mcp\AdapterBootstrap::available();
        }

        return class_exists('\WP\MCP\Core\McpAdapter') && function_exists('wp_register_ability');
    }

    /**
     * Download a plugin zip from a direct URL and install it. Self-contained on
     * the WP_Upgrader primitives so it carries no extra service dependency.
     *
     * @param string $zipUrl
     * @return true|\WP_Error
     */
    private function installFromZipUrl($zipUrl)
    {
        $zipUrl = esc_url_raw((string) $zipUrl);

        if (!$zipUrl || !filter_var($zipUrl, FILTER_VALIDATE_URL)) {
            return new \WP_Error('invalid_plugin_zip_url', __('Invalid plugin zip URL.', 'fluentformpro'));
        }

        // We only reach here when no valid toolkit install was found. If a
        // fluent-toolkit folder nonetheless exists, it's a stale/partial/tampered
        // install — merging fresh files into it (clear_destination=false) could
        // leave old code behind that then gets activated. Abort and let the
        // operator remove it rather than silently merging.
        $destDir = WP_PLUGIN_DIR . '/' . dirname(self::TOOLKIT_PLUGIN_FILE);
        if (is_dir($destDir)) {
            return new \WP_Error('toolkit_dir_exists', sprintf(
                /* translators: 1: plugin directory path */
                __(
                    'A %1$s folder already exists but is not a valid FluentHub install. Remove it and try again.',
                    'fluentformpro',
                ),
                'wp-content/plugins/' . dirname(self::TOOLKIT_PLUGIN_FILE),
            ));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        \WP_Filesystem();

        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \WP_Upgrader($skin);

        ob_start();

        try {
            $download = $upgrader->download_package($zipUrl);
            if (is_wp_error($download)) {
                throw new \Exception($download->get_error_message());
            }

            $workingDir = $upgrader->unpack_package($download, true);
            if (is_wp_error($workingDir)) {
                throw new \Exception($workingDir->get_error_message());
            }

            $result = $upgrader->install_package([
                'source' => $workingDir,
                'destination' => WP_PLUGIN_DIR,
                'clear_destination' => false,
                'abort_if_destination_exists' => false,
                'clear_working' => true,
                'hook_extra' => [
                    'type' => 'plugin',
                    'action' => 'install',
                ],
            ]);

            if (!$result || is_wp_error($result)) {
                $message = is_wp_error($result)
                    ? $result->get_error_message()
                    : __('Plugin package installation failed.', 'fluentformpro');
                throw new \Exception($message);
            }
        } catch (\Exception $e) {
            ob_end_clean();
            wp_clean_plugins_cache();
            return new \WP_Error('plugin_install_failed', $e->getMessage());
        }

        ob_end_clean();
        wp_clean_plugins_cache();

        return true;
    }
}
