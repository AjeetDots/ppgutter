<?php
defined('ABSPATH') or die('No script kiddies please!');
class TrustindexWidgetEmbedder_google
{
private static $builders = [
'_elementor_edit_mode' => 'Elementor',
'_et_pb_use_builder' => 'Divi',
'_fl_builder_enabled' => 'Beaver Builder',
'_wpb_vc_js_status' => 'WPBakery Page Builder',
'ct_builder_shortcodes' => 'Oxygen',
'_bricks_page_content_2' => 'Bricks',
'_breakdance_data' => 'Breakdance',
'panels_data' => 'SiteOrigin Page Builder',
'brizy_post_uid' => 'Brizy',
'tcb_editor_enabled' => 'Thrive Architect',
];
private static $inactiveBuilderValues = ['', '0', 'off', 'false', 'no'];
private static $excludedPostTypes = ['revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache'];
private static $postStatuses = ['publish', 'future', 'draft', 'pending', 'private'];
private static $liveStatuses = ['publish', 'future', 'private'];
private static $widgetAreaOptions = ['widget_block', 'widget_text', 'widget_custom_html'];
private static $blockName = 'trustindex/block-selector';
private static $maxLocationCount = 20;
private static $maxSearchedPostCount = 200;
private $plugin;
public function __construct($plugin)
{
$this->plugin = $plugin;
}
public function embed($widget, $input)
{
$input = $this->normalizeInput($input);
$targetCount = count(array_filter([0 < $input['post_id'], '' !== $input['post_url'], $input['front_page'], '' !== $input['new_page_title']]));
if (1 !== $targetCount) {
return new WP_Error('trustindex_invalid_target', 'Give exactly one of post_id, post_url, front_page and new_page_title.', ['status' => 400]);
}
if ('' !== $input['new_page_title']) {
return $this->embedIntoNewPage($widget, $input);
}
$post = $this->getEditablePost($this->findTargetPost($input));
return is_wp_error($post) ? $post : $this->embedIntoPost($post, $widget, $input);
}
public function findLocations($widget)
{
$locations = [];
foreach (array_merge($this->findContentLocations($widget), $this->findElementorLocations($widget)) as $location) {
$locations[$location['id']] = $location;
}
return array_slice(array_merge(array_values($locations), $this->findWidgetAreaLocations($widget)), 0, self::$maxLocationCount);
}
public function hasWidget($content, $widget)
{
return is_string($content) && ($this->hasWidgetShortcode($content, $widget) || $this->hasWidgetBlock($content, $widget));
}
private function hasWidgetShortcode($content, $widget)
{
$tag = $this->plugin->get_shortcode_name();
if (false === strpos($content, '['.$tag)) {
return false;
}
preg_match_all('/'.get_shortcode_regex([$tag]).'/', $content, $matches, PREG_SET_ORDER);
foreach ($matches as $match) {
$attributes = shortcode_parse_atts($match[3]);
if (is_array($attributes) && isset($attributes[$widget['attribute']]) && 0 === strcasecmp($attributes[$widget['attribute']], $widget['value'])) {
return true;
}
}
return false;
}
private function hasWidgetBlock($content, $widget)
{
if (false === strpos($content, '<!-- wp:'.self::$blockName)) {
return false;
}
return $this->findWidgetBlock(parse_blocks($content), $widget);
}
private function findWidgetBlock($blocks, $widget)
{
foreach ($blocks as $block) {
if (self::$blockName === $block['blockName'] && $this->isWidgetBlockMatching($block['attrs'], $widget)) {
return true;
}
if ([] !== $block['innerBlocks'] && $this->findWidgetBlock($block['innerBlocks'], $widget)) {
return true;
}
}
return false;
}
private function isWidgetBlockMatching($attributes, $widget)
{
$widgetId = isset($attributes['widget_id']) ? (string)$attributes['widget_id'] : '';
$attribute = isset($attributes['free_widgets'][$widgetId]) ? 'no-registration' : 'data-widget-id';
return '' !== $widgetId && $widget['attribute'] === $attribute && 0 === strcasecmp($widgetId, $widget['value']);
}
public function getPostSummary($post)
{
$document = $this->getElementorDocument($post);
return [
'id' => (int)$post->ID,
'title' => (string)$post->post_title,
'post_type' => (string)$post->post_type,
'status' => (string)$post->post_status,
'url' => (string)get_permalink($post),
'edit_url' => null === $document ? (string)get_edit_post_link($post->ID, 'raw') : (string)$document->get_edit_url(),
'preview_url' => 'publish' === $post->post_status ? null : (string)get_preview_post_link($post),
];
}


private function normalizeInput($input)
{
return [
'post_id' => isset($input['post_id']) ? (int)$input['post_id'] : 0,
'post_url' => isset($input['post_url']) ? trim((string)$input['post_url']) : '',
'front_page' => isset($input['front_page']) && (bool)$input['front_page'],
'new_page_title' => isset($input['new_page_title']) ? sanitize_text_field((string)$input['new_page_title']) : '',
'new_page_status' => isset($input['new_page_status']) && 'publish' === $input['new_page_status'] ? 'publish' : 'draft',
'position' => isset($input['position']) && 'start' === $input['position'] ? 'start' : 'end',
'confirm' => isset($input['confirm']) && (bool)$input['confirm'],
];
}
private function findTargetPost($input)
{
if (0 < $input['post_id']) {
return get_post($input['post_id']);
}
if ('' !== $input['post_url']) {
$postId = url_to_postid($input['post_url']);
return 0 < $postId ? get_post($postId) : null;
}
if ('page' !== get_option('show_on_front') || 0 >= (int)get_option('page_on_front')) {
$message = 'The front page of the site shows the latest posts, not a page. Embed the widget into a new page (new_page_title) or into an existing page, or ask the site owner to set a static front page in Settings > Reading.';
return new WP_Error('trustindex_no_static_front_page', $message, ['status' => 409]);
}
return get_post((int)get_option('page_on_front'));
}
private function getEditablePost($post)
{
if (is_wp_error($post)) {
return $post;
}
if (!($post instanceof WP_Post) || in_array($post->post_type, self::$excludedPostTypes, true) || !in_array($post->post_status, self::$postStatuses, true)) {
return new WP_Error('trustindex_post_not_found', 'The post or page was not found.', ['status' => 404]);
}
if (!current_user_can('edit_post', $post->ID)) {
return new WP_Error('trustindex_forbidden', 'You are not allowed to edit this post or page.', ['status' => 403]);
}
return $post;
}


private function embedIntoPost($post, $widget, $input)
{
if ($this->isEmbeddedInPost($post, $widget)) {
return $this->getResult('already_embedded', 'The widget is already embedded in this post or page.', $post);
}
$document = $this->getElementorDocument($post);
if (null !== $document) {
return $this->embedIntoElementorPage($document, $widget, $input);
}
$builder = $this->getBuilder($post);
if (null !== $builder) {
return $this->getBuilderResult($post, $builder, sprintf('This page is built with %s, so its content cannot be changed here.', $builder));
}
$confirmation = $this->getConfirmationResult($post, $input);
if (null !== $confirmation) {
return $confirmation;
}
$result = wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => $this->insertShortcode($post->post_content, $widget['shortcode'], $input['position'])]), true);
return is_wp_error($result) ? $result : $this->getResult('embedded', 'The widget was embedded.', get_post($post->ID));
}
private function embedIntoNewPage($widget, $input)
{
$isPublished = 'publish' === $input['new_page_status'];
if ($isPublished && !current_user_can('publish_pages')) {
return new WP_Error('trustindex_forbidden', 'You are not allowed to publish pages, create the page as a draft.', ['status' => 403]);
}
if ($isPublished && !$input['confirm']) {
$message = sprintf('A new published page "%s" will be created with the widget, visible to the visitors. Ask the site owner to confirm it, then call this ability again with "confirm": true.', $input['new_page_title']);
return $this->getResult('needs_confirmation', $message, null);
}
$postId = wp_insert_post(wp_slash([
'post_type' => 'page',
'post_title' => $input['new_page_title'],
'post_status' => $input['new_page_status'],
'post_content' => $this->getShortcodeBlock($widget['shortcode']),
]), true);
return is_wp_error($postId) ? $postId : $this->getResult('embedded', 'A new page was created with the widget.', get_post($postId));
}
private function insertShortcode($content, $shortcode, $position)
{
if ('' === $content) {
return $this->getShortcodeBlock($shortcode);
}
$insertion = has_blocks($content) ? $this->getShortcodeBlock($shortcode) : $shortcode;
return 'start' === $position ? $insertion."\n\n".$content : $content."\n\n".$insertion;
}
private function getShortcodeBlock($shortcode)
{
return "<!-- wp:shortcode -->\n".$shortcode."\n<!-- /wp:shortcode -->";
}
private function getConfirmationResult($post, $input)
{
if (!in_array($post->post_status, self::$liveStatuses, true) || $input['confirm']) {
return null;
}
$message = sprintf('The widget will be added to the %1$s of the published %2$s "%3$s", visible to the visitors. Ask the site owner to confirm it, then call this ability again with "confirm": true.', $this->getPositionName($input['position']), $post->post_type, $post->post_title);
return $this->getResult('needs_confirmation', $message, $post);
}
private function getPositionName($position)
{
return 'start' === $position ? 'beginning' : 'end';
}
private function getResult($status, $message, $post)
{
return [
'status' => $status,
'message' => $message,
'post' => null === $post ? null : $this->getPostSummary($post),
'builder' => null === $post ? null : $this->getBuilder($post),
];
}
private function getBuilderResult($post, $builder, $reason)
{
$message = $reason.sprintf(' Ask the site owner to open the page in the %s editor and add the shortcode below with a shortcode element (or with the Trustindex widget of the builder, if there is one).', $builder);
return $this->getResult('manual_step_required', $message, $post);
}


private function getBuilder($post)
{
foreach (self::$builders as $metaKey => $name) {
if ($this->isBuilderMetaActive(get_post_meta($post->ID, $metaKey, true))) {
return $name;
}
}
return null;
}
private function isBuilderMetaActive($value)
{
if (is_array($value)) {
return [] !== $value;
}
return is_scalar($value) && !in_array(strtolower(trim((string)$value)), self::$inactiveBuilderValues, true);
}
private function isEmbeddedInPost($post, $widget)
{
return $this->hasWidget($post->post_content, $widget) || $this->hasElementorWidget(get_post_meta($post->ID, '_elementor_data', true), $widget);
}
private function hasElementorWidget($json, $widget)
{
$elements = is_string($json) && '' !== $json ? json_decode($json, true) : null;
return is_array($elements) && $this->findElementorWidget($elements, $widget);
}
private function findElementorWidget($elements, $widget)
{
foreach ($elements as $element) {
if (!is_array($element)) {
continue;
}
if ($this->isElementorWidgetMatching($element, $widget)) {
return true;
}
if (isset($element['elements']) && is_array($element['elements']) && $this->findElementorWidget($element['elements'], $widget)) {
return true;
}
}
return false;
}
private function isElementorWidgetMatching($element, $widget)
{
$settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];
foreach ($settings as $value) {
if (is_string($value) && $this->hasWidget($value, $widget)) {
return true;
}
}
$isTrustindexWidget = null !== $widget['elementor-widget'] && isset($element['widgetType']) && $widget['elementor-widget'] === $element['widgetType'];
return $isTrustindexWidget && (!isset($settings['type']) || 'pro' !== $settings['type']);
}


private function getElementorDocument($post)
{
if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->documents)) {
return null;
}
$document = \Elementor\Plugin::$instance->documents->get($post->ID);
return $document instanceof \Elementor\Core\Base\Document && $document->is_built_with_elementor() ? $document : null;
}
private function embedIntoElementorPage($document, $widget, $input)
{
$post = $document->get_post();
if (!$document->is_editable_by_current_user()) {
return new WP_Error('trustindex_forbidden', 'You are not allowed to edit this page with Elementor.', ['status' => 403]);
}
$elements = $this->getElementorElements($post);
if (null === $elements || $this->hasUnknownElementorElement($elements)) {
return $this->getBuilderResult($post, 'Elementor', 'This page contains Elementor elements that are not available now (e.g. of an inactive plugin), they would be lost if the page was saved here.');
}
$confirmation = $this->getConfirmationResult($post, $input);
if (null !== $confirmation) {
return $confirmation;
}
if (false === $document->save(['elements' => $this->insertElementorSection($elements, $widget, $input['position'])])) {
return new WP_Error('trustindex_elementor_error', 'Elementor could not save the page.', ['status' => 500]);
}
$message = sprintf('The widget was embedded in a new Elementor section at the %s of the page, the site owner can move it in the Elementor editor (edit_url).', $this->getPositionName($input['position']));
return $this->getResult('embedded', $message, get_post($post->ID));
}
private function getElementorElements($post)
{
$data = get_post_meta($post->ID, '_elementor_data', true);
if ('' === $data) {
return [];
}
$elements = is_string($data) ? json_decode($data, true) : $data;
return is_array($elements) ? $elements : null;
}
private function hasUnknownElementorElement($elements)
{
foreach ($elements as $element) {
if (!is_array($element) || !$this->isElementorElementKnown($element)) {
return true;
}
if (isset($element['elements']) && is_array($element['elements']) && $this->hasUnknownElementorElement($element['elements'])) {
return true;
}
}
return false;
}
private function isElementorElementKnown($element)
{
$elementor = \Elementor\Plugin::$instance;
$type = isset($element['elType']) && is_string($element['elType']) ? $element['elType'] : '';
if ('widget' === $type) {
return isset($element['widgetType']) && is_string($element['widgetType']) && null !== $elementor->widgets_manager->get_widget_types($element['widgetType']);
}
return '' !== $type && null !== $elementor->elements_manager->get_element_types($type);
}
private function insertElementorSection($elements, $widget, $position)
{
$section = $this->getElementorSection($widget);
return 'start' === $position ? array_merge([$section], $elements) : array_merge($elements, [$section]);
}
private function getElementorSection($widget)
{
$widgetElement = $this->getElementorWidget($widget);
if (null !== \Elementor\Plugin::$instance->elements_manager->get_element_types('container')) {
return $this->getElementorElement('container', [], [$widgetElement]);
}
$column = $this->getElementorElement('column', ['_column_size' => 100, '_inline_size' => null], [$widgetElement]);
return $this->getElementorElement('section', [], [$column]);
}
private function getElementorWidget($widget)
{
$isTrustindexWidget = null !== $widget['elementor-widget'];
$element = $this->getElementorElement('widget', $isTrustindexWidget ? ['type' => 'free'] : ['shortcode' => $widget['shortcode']], []);
$element['widgetType'] = $isTrustindexWidget ? $widget['elementor-widget'] : 'shortcode';
return $element;
}
private function getElementorElement($type, $settings, $children)
{
return [
'id' => \Elementor\Utils::generate_random_string(),
'elType' => $type,
'settings' => $settings,
'elements' => $children,
'isInner' => false,
];
}


private function findContentLocations($widget)
{
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$rows = $wpdb->get_results($wpdb->prepare(
"SELECT ID, post_content FROM %i WHERE post_type NOT IN ('revision', 'nav_menu_item') AND post_status IN ('publish', 'future', 'draft', 'pending', 'private') AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID DESC LIMIT %d",
$wpdb->posts,
'%'.$wpdb->esc_like('['.$this->plugin->get_shortcode_name()).'%',
'%'.$wpdb->esc_like('<!-- wp:'.self::$blockName).'%',
self::$maxSearchedPostCount
));
$locations = [];
foreach ($rows as $row) {
if ($this->hasWidget($row->post_content, $widget)) {
$locations []= $this->getLocation('content', get_post($row->ID));
}
}
return $locations;
}
private function findElementorLocations($widget)
{
global $wpdb;
$widgetTypePattern = '"widgetType":"'.(null === $widget['elementor-widget'] ? '' : $widget['elementor-widget']).'"';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$rows = $wpdb->get_results($wpdb->prepare(
"SELECT p.ID, m.meta_value FROM %i p INNER JOIN %i m ON m.post_id = p.ID WHERE m.meta_key = '_elementor_data' AND p.post_type NOT IN ('revision', 'nav_menu_item') AND p.post_status IN ('publish', 'future', 'draft', 'pending', 'private') AND (m.meta_value LIKE %s OR m.meta_value LIKE %s) ORDER BY p.ID DESC LIMIT %d",
$wpdb->posts,
$wpdb->postmeta,
'%'.$wpdb->esc_like('['.$this->plugin->get_shortcode_name()).'%',
'%'.$wpdb->esc_like($widgetTypePattern).'%',
self::$maxSearchedPostCount
));
$locations = [];
foreach ($rows as $row) {
if ($this->hasElementorWidget($row->meta_value, $widget)) {
$locations []= $this->getLocation('elementor', get_post($row->ID));
}
}
return $locations;
}
private function findWidgetAreaLocations($widget)
{
foreach (self::$widgetAreaOptions as $optionName) {
foreach ((array)get_option($optionName, []) as $instance) {
if (is_array($instance) && $this->hasWidget(implode("\n", array_filter($instance, 'is_string')), $widget)) {
return [[
'location' => 'widget_area',
'id' => null,
'title' => 'Widget area',
'post_type' => null,
'status' => 'publish',
'url' => home_url('/'),
'edit_url' => admin_url('widgets.php'),
'preview_url' => null,
]];
}
}
}
return [];
}
private function getLocation($location, $post)
{
return array_merge(['location' => $location], $this->getPostSummary($post));
}
}
?>
