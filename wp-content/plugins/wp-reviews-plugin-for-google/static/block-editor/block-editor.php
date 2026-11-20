<?php
defined('ABSPATH') || exit;
class TrustindexGutenbergPlugin
{

public static $instance = null;

function init()
{
add_action('enqueue_block_editor_assets', [ $this, 'gutenberg_enqueue_block_editor_assets' ]);
add_action('init', [ $this, 'register_block' ]);
add_filter('render_block_core/shortcode', [$this, 'render_shortcode_block']);
}

function gutenberg_enqueue_block_editor_assets()
{
wp_enqueue_script(
'gutenberg-ti',
plugins_url('block-editor.js', __FILE__),
[
'wp-api-fetch',
'wp-block-editor',
'wp-components',
'wp-compose',
'wp-blocks',
'wp-element',
'wp-i18n'
],
filemtime(plugin_dir_path(__FILE__) . 'block-editor.js'),
['in_footer' => false]
);
}
function register_block()
{
register_block_type('trustindex/block-selector', [
'api_version' => 3,
'render_callback' => [ $this, 'render_block' ],
'attributes' => [
'widget_id' => [ 'type' => 'string' ],
'trustindex_widgets' => [ 'type' => 'object' ],
'free_widgets' => [ 'type' => 'object' ],
'custom_id' => [ 'type' => 'boolean' ],
'setup_url' => [ 'type' => 'string' ]
]
]);
}

function render_block($attributes)
{
if ($this->is_gutenberg_page()) {
return "";
}
if (isset($attributes['free_widgets'][ $attributes['widget_id'] ])) {
$shortCodeAttribute = 'no-registration=' . $attributes['widget_id'];
}
else {
$shortCodeAttribute = 'data-widget-id="'. $attributes['widget_id'] .'"';
}
$shortCode = '[trustindex '. $shortCodeAttribute .']';
return $this->is_shortcode_run_after_blocks() ? $shortCode : do_shortcode($shortCode);
}

function render_shortcode_block($blockContent)
{
if ($this->is_shortcode_run_after_blocks() || !has_shortcode($blockContent, 'trustindex')) {
return $blockContent;
}
return preg_replace_callback('/'.get_shortcode_regex(['trustindex']).'/', function ($shortcodeMatch) {
return do_shortcode($shortcodeMatch[0]);
}, shortcode_unautop($blockContent));
}

private function is_shortcode_run_after_blocks()
{
return doing_filter('the_content') || doing_filter('widget_block_content');
}
function is_gutenberg_page()
{
if (function_exists('is_gutenberg_page') && is_gutenberg_page()) {
return true;
}
if (function_exists('get_current_screen')) {
$currentScreen = get_current_screen();
if ($currentScreen && method_exists($currentScreen, 'is_block_editor') && $currentScreen->is_block_editor()) {
return true;
}
}
return false;
}

public static function instance()
{
if (is_null(self::$instance)) {
self::$instance = new self();
self::$instance->init();
}
return self::$instance;
}

public function __clone()
{
_doing_it_wrong(__FUNCTION__, 'Cheating huh?', '1.0.0');
}

public function __wakeup()
{
_doing_it_wrong(__FUNCTION__, 'Cheating huh?', '1.0.0');
}
}
?>
