<?php
defined('ABSPATH') or die('No script kiddies please!');
class TrustindexWidgetSettings_google
{
private static $defaultStyle = 'light-background';
private static $paidLayoutId = 14;
private static $fixedStyleLayoutTypes = ['floating', 'fomo'];
private static $ratingNumbersLayoutIds = [119, 120];
private static $noTextSettingsLayoutIds = [17, 21, 52, 53, 112, 114];
private static $connectionOptions = ['review-download-inprogress', 'review-download-request-id', 'review-manual-download', 'page-details', 'review-content', 'css-content'];
private static $connectionSettingOptions = [
'style-id', 'scss-set', 'filter', 'lang', 'dateformat', 'nameformat', 'no-rating-text', 'verified-icon', 'enable-animation', 'show-arrows',
'show-header-button', 'reviews-load-more', 'show-reviewers-photo', 'show-review-images', 'widget-setted-up', 'show-review-replies', 'verified-by-trustindex',
];
private static $layoutOptions = [
'widget-setted-up', 'align', 'review-text-mode', 'verified-icon', 'enable-animation', 'no-rating-text', 'disable-font', 'show-reviewers-photo',
'show-review-images', 'show-logos', 'show-stars', 'footer-filter-text', 'top-rated-type', 'top-rated-date', 'show-arrows', 'show-header-button',
'reviews-load-more', 'dateformat', 'nameformat', 'show-review-replies', 'verified-by-trustindex', 'fomo-open', 'fomo-link', 'fomo-border',
'fomo-arrow', 'fomo-icon', 'fomo-color', 'fomo-icon-background', 'fomo-margin', 'fomo-title', 'fomo-text', 'fomo-url', 'fomo-day', 'fomo-hide-count',
];
private static $checkboxFallbacks = [
'verified-icon' => 0,
'enable-animation' => 1,
'show-arrows' => 1,
'show-header-button' => 1,
'reviews-load-more' => 1,
'show-reviewers-photo' => 1,

'show-review-images' => 0,
'no-rating-text' => 0,
'disable-font' => 0,
'show-logos' => 1,
'show-stars' => 1,
'footer-filter-text' => 0,
'show-review-replies' => 0,
'fomo-open' => 1,
'fomo-link' => 0,
'fomo-border' => 1,
'fomo-arrow' => 1,
'fomo-icon-background' => 0,
];
private static $integerOptions = ['fomo-margin', 'fomo-hide-count'];
private static $starFiltersByMinimumStars = [1 => '1,2,3,4,5', 4 => '4,5', 5 => '5'];
private static $colorPattern = '/^(?:#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\))$/i';
private $plugin;
public function __construct($plugin)
{
$this->plugin = $plugin;
}
public static function getTemplate($styleId)
{
$templates = TrustindexPlugin_google::$widget_templates['templates'];
return isset($templates[$styleId]) ? $templates[$styleId] : null;
}
public static function isActive($template)
{
return !isset($template['is-active']) || $template['is-active'];
}
public static function isStyleFixed($styleId)
{
$template = self::getTemplate($styleId);
return null !== $template && in_array($template['type'], self::$fixedStyleLayoutTypes, true);
}
public static function getDefaultStyle($styleId)
{
$template = self::getTemplate($styleId);
if (null === $template || self::isStyleFixed($styleId)) {
return self::$defaultStyle;
}
if ($template['is-top-rated-badge']) {
return isset($template['params']['top-rated-badge-border']) && (bool)$template['params']['top-rated-badge-border'] ? 'ligth-border' : 'light-minimal';
}
return in_array($template['type'], ['badge', 'button'], true) ? 'drop-shadow' : self::$defaultStyle;
}


public function disconnect()
{
$this->plugin->delete_async_request();
$this->deleteOptions(array_merge(self::$connectionOptions, self::$connectionSettingOptions));
if (is_file($this->plugin->getCssFile())) {
wp_delete_file($this->plugin->getCssFile());
}
$this->plugin->save_reviews([]);
$this->plugin->setNotificationParam('not-using-no-connection', 'active', true);
$this->plugin->setNotificationParam('not-using-no-connection', 'timestamp', time() + 86400);
$this->plugin->setNotificationParam('not-using-no-widget', 'active', false);
}


public function getCompletedStep()
{
if (!$this->plugin->is_noreg_linked()) {
return 0;
}
if (1 === (int)$this->plugin->getWidgetOption('widget-setted-up')) {
return $this->isTopRatedBadgeValid() ? 4 : 3;
}
if ('' !== (string)$this->plugin->getWidgetOption('scss-set', true)) {
return 3;
}
return 0 < (int)$this->plugin->getWidgetOption('style-id', true) ? 2 : 1;
}
public function selectLayout($styleId)
{
if (self::isStyleFixed($styleId)) {
return $this->selectLayoutAndStyle($styleId, self::$defaultStyle);
}
$error = $this->getLayoutError($styleId);
if (null !== $error) {
return $error;
}
$this->updateOption('style-id', $styleId);
$this->deleteOptions(['review-content', 'scss-set']);
$this->resetLayoutOptions();
return true;
}
public function selectLayoutAndStyle($styleId, $setId)
{
$setId = self::isStyleFixed($styleId) ? self::$defaultStyle : $setId;
$error = $this->getLayoutError($styleId);
if (null === $error && !self::isStyleFixed($styleId)) {
$error = $this->getStyleError($styleId, $setId);
}
$cssContent = null === $error ? $this->plugin->downloadWidgetCss($styleId, $setId) : $error;
if (is_wp_error($cssContent)) {
return $cssContent;
}
$this->updateOption('style-id', $styleId);
$this->updateOption('scss-set', $setId);
$this->deleteOptions(['review-content']);
$this->resetLayoutOptions();
$this->plugin->saveWidgetCssContent($cssContent);
return true;
}
public function selectStyle($setId)
{
$styleId = (int)$this->plugin->getWidgetOption('style-id', true);
$error = $this->getStyleError($styleId, $setId);
if (null !== $error) {
return $error;
}
$cssContent = $this->plugin->downloadWidgetCss($styleId, $setId);
if (is_wp_error($cssContent)) {
return $cssContent;
}
$this->updateOption('scss-set', $setId);
$this->resetLayoutOptions();
$this->plugin->saveWidgetCssContent($cssContent);
return true;
}
public function completeSetup()
{
if ($this->getCompletedStep() < 3) {
return new WP_Error('trustindex_setup_incomplete', 'Select a layout and a style before completing the widget setup.', ['status' => 409]);
}
if (!$this->isTopRatedBadgeValid()) {
return $this->getTopRatedBadgeError();
}
$this->updateOption('widget-setted-up', 1);
return true;
}
public function getLayoutError($styleId)
{
$template = self::getTemplate($styleId);
if (null === $template || !self::isActive($template) || self::$paidLayoutId === $styleId) {
return new WP_Error('trustindex_layout_not_available', sprintf('Layout %d is not available in the free widget.', $styleId), ['status' => 400]);
}
if ($template['is-top-rated-badge'] && !$this->hasTopRatedScore()) {
return $this->getTopRatedBadgeError();
}
if (in_array($styleId, self::$ratingNumbersLayoutIds, true) && !$this->hasRatingNumbers()) {
return new WP_Error('trustindex_reviews_update_needed', 'This layout can be used after downloading the reviews again.', ['status' => 409]);
}
return null;
}
public function getStyleError($styleId, $setId)
{
$styles = TrustindexPlugin_google::$widget_styles;
if (null === self::getTemplate($styleId)) {
return new WP_Error('trustindex_layout_missing', 'Select a layout before selecting a style.', ['status' => 409]);
}
if (self::isStyleFixed($styleId)) {
return new WP_Error('trustindex_style_fixed', 'The style of the floating and fomo layouts cannot be changed.', ['status' => 400]);
}
if (!isset($styles[$setId]) || !self::isActive($styles[$setId])) {
return new WP_Error('trustindex_style_not_available', sprintf('Style "%s" is not available.', $setId), ['status' => 400]);
}
return null;
}
public function isTopRatedBadgeValid()
{
$template = self::getTemplate((int)$this->plugin->getWidgetOption('style-id', true));
return null === $template || !$template['is-top-rated-badge'] || $this->hasTopRatedScore();
}
private function hasTopRatedScore()
{
$pageDetails = $this->plugin->getPageDetails();
return isset($pageDetails['rating_score']) && (float)$pageDetails['rating_score'] >= TrustindexPlugin_google::$topRatedMinimumScore;
}
private function hasRatingNumbers()
{
$pageDetails = $this->plugin->getPageDetails();
return isset($pageDetails['rating_numbers']) && isset($pageDetails['rating_numbers_last']);
}
private function getTopRatedBadgeError()
{
$message = sprintf('The "Top Rated" badges are available for a rating of %s and above.', TrustindexPlugin_google::$topRatedMinimumScore);
return new WP_Error('trustindex_top_rated_badge_not_available', $message, ['status' => 409]);
}
private function resetLayoutOptions()
{
$this->deleteOptions(self::$layoutOptions);
$this->plugin->emptyViews();
}


public function saveCheckboxOptions($values)
{
$fallbacks = self::$checkboxFallbacks;
$fallbacks['only-ratings'] = $this->getDefaultFilter()['only-ratings'];
foreach ($fallbacks as $name => $fallback) {
$this->saveOption($name, isset($values[$name]) ? $values[$name] : $fallback);
}
}
public function saveOption($name, $value)
{
$value = $this->sanitizeOption($name, $value);
if (is_wp_error($value)) {
return $value;
}
if (in_array($name, ['stars', 'only-ratings'], true)) {
return $this->saveFilter($name, $value);
}
if ('fomo-url' === $name && '' === $value) {
$this->deleteOptions([$name]);
} else {
$this->updateOption($name, $value);
}
$this->applyOptionSideEffects($name, $value);
return true;
}
public function getOptionValues($name)
{
switch ($name) {
case 'lang':
return array_map('strval', array_keys(TrustindexPlugin_google::$widget_languages));
case 'dateformat':
return TrustindexPlugin_google::$widget_dateformats;
case 'nameformat':
return array_map('strval', array_keys(TrustindexPlugin_google::$widget_nameformats));
case 'align':
return $this->plugin->isFomoWidget() ? ['left', 'right'] : ['left', 'center', 'right', 'justify'];
case 'review-text-mode':
return ['scroll', 'readmore', 'truncated'];
case 'top-rated-type':
return array_map('strval', array_keys(TrustindexPlugin_google::$widget_top_rated_titles));
case 'top-rated-date':
return ['hide', 'last-year', ''];
case 'verified-by-trustindex':
return ['0', '1', '2'];
case 'stars':
return array_values(self::$starFiltersByMinimumStars);
}
return $this->getFomoOptionValues($name);
}
public function getApplicableOptions()
{
$styleId = (int)$this->plugin->getWidgetOption('style-id', true);
$template = self::getTemplate($styleId);
if (null === $template) {
return [];
}
$conditions = array_merge($this->getReviewOptionConditions($styleId, $template), $this->getFomoOptionConditions($template));
return array_keys(array_filter($conditions));
}
public static function getStarFiltersByMinimumStars()
{
return self::$starFiltersByMinimumStars;
}
public function getMinimumStars()
{
$starFilter = implode(',', array_map('intval', $this->getFilter()['stars']));
$minimumStars = array_search($starFilter, self::$starFiltersByMinimumStars, true);
return false === $minimumStars ? 1 : $minimumStars;
}
private function getFomoOptionValues($name)
{
if (!$this->plugin->isFomoWidget()) {
return null;
}
$params = self::getTemplate((int)$this->plugin->getWidgetOption('style-id'))['params'];
switch ($name) {
case 'fomo-icon':
return array_merge(['hide'], isset($params['fomo-icon-choices']) ? $params['fomo-icon-choices'] : []);
case 'fomo-day':
return isset($params['fomo-days']) ? array_map('strval', $params['fomo-days']) : [];
case 'fomo-text':
$choices = $this->plugin->getFomoSubtitleTextChoices();
return [] === $choices || $this->plugin->isFomoCustomWidget() ? null : array_merge(['hide'], array_map('strval', array_keys($choices)));
}
return null;
}
private function sanitizeOption($name, $value)
{
if (!is_scalar($value)) {
return $this->getInvalidValueError($name);
}
if (array_key_exists($name, self::$checkboxFallbacks) || 'only-ratings' === $name) {
return (int)(bool)$value;
}
$values = $this->getOptionValues($name);
if (null !== $values) {
return in_array((string)$value, $values, true) ? $this->castEnumValue($name, $value) : $this->getInvalidValueError($name);
}
return $this->sanitizeTextOption($name, (string)$value);
}
private function castEnumValue($name, $value)
{
return 'fomo-day' === $name ? (int)$value : (string)$value;
}
private function sanitizeTextOption($name, $value)
{
if (in_array($name, self::$integerOptions, true)) {
$value = '' === trim($value) ? '0' : $value;
return is_numeric($value) && 0 <= (int)$value ? (int)$value : $this->getInvalidValueError($name);
}
switch ($name) {
case 'fomo-color':
return 1 === preg_match(self::$colorPattern, trim($value)) ? trim($value) : $this->getInvalidValueError($name);
case 'fomo-url':
return '' === trim($value) ? '' : $this->sanitizeUrl($name, $value);
case 'fomo-title':
case 'fomo-text':
return sanitize_text_field(htmlspecialchars($value, ENT_NOQUOTES));
}
return $this->getInvalidValueError($name);
}
private function sanitizeUrl($name, $value)
{
$url = esc_url_raw(trim($value), ['http', 'https']);
return '' !== $url ? $url : $this->getInvalidValueError($name);
}
private function getInvalidValueError($name)
{
return new WP_Error('trustindex_invalid_option_value', sprintf('Invalid value for the "%s" widget option.', $name), ['status' => 400]);
}
private function applyOptionSideEffects($name, $value)
{
if ('lang' === $name) {
$this->deleteOptions(['review-content']);
if ($this->plugin->isRtlLanguage()) {
$this->updateOption('align', 'right');
}
}
if ('fomo-icon' === $name && 'hide' === $value) {
$this->updateOption('fomo-open', 1);
}
}
private function saveFilter($name, $value)
{
$filter = $this->getFilter();
if ('stars' === $name) {
$filter['stars'] = explode(',', $value);
} else {
$filter['only-ratings'] = (bool)$value;
}
$this->updateOption('filter', $filter);
return true;
}
private function getFilter()
{
$filter = $this->plugin->getWidgetOption('filter', true);
return is_array($filter) ? array_merge($this->getDefaultFilter(), $filter) : $this->getDefaultFilter();
}
private function getDefaultFilter()
{
return $this->plugin->getWidgetOption('filter', false, true);
}
private function getReviewOptionConditions($styleId, $template)
{
$hasReviews = $this->plugin->isLayoutHasReviews();
$isFomo = 'fomo' === $template['type'];
$hasTextSettings = $hasReviews && !in_array($styleId, self::$noTextSettingsLayoutIds, true);
return [
'verified-by-trustindex' => $this->plugin->isVerifiedByTrustindexAvailable(),
'stars' => $hasReviews && $this->isStarFilterAvailable(),
'lang' => true,
'dateformat' => $hasReviews,
'nameformat' => $hasReviews,
'align' => $hasTextSettings || $isFomo,
'review-text-mode' => $hasTextSettings,
'top-rated-type' => $template['is-top-rated-badge'],
'top-rated-date' => $template['is-top-rated-badge'] && !$isFomo,
'only-ratings' => $hasReviews,
'no-rating-text' => in_array($styleId, [4, 6, 7, 15, 16, 19, 31, 33, 36, 37, 38, 39, 44], true),
'footer-filter-text' => $hasReviews && ('floating' !== $template['type'] || 53 === $styleId),
'show-review-replies' => $hasReviews,
'show-header-button' => in_array($styleId, [8, 10, 13], true),
'reviews-load-more' => in_array($styleId, [8, 16, 18, 31, 33], true),
'verified-icon' => $hasReviews && !in_array($styleId, [53, 54], true),
] + $this->getDisplayOptionConditions($styleId, $template, $hasReviews);
}
private function getDisplayOptionConditions($styleId, $template, $hasReviews)
{
$isAnimationAvailable = !in_array($template['type'], self::$fixedStyleLayoutTypes, true) && !$template['is-top-rated-badge'] && 54 !== $styleId;
return [
'show-arrows' => in_array($template['type'], ['slider', 'sidebar'], true) && !in_array($styleId, [8, 9, 10, 18, 19, 37, 54], true),
'show-reviewers-photo' => $hasReviews && 52 !== $styleId,
'show-review-images' => $hasReviews && 52 !== $styleId && $this->plugin->isReviewImageAvailable(),
'enable-animation' => $isAnimationAvailable && 'drop-shadow' !== $this->plugin->getWidgetOption('scss-set'),
'disable-font' => 'fomo' !== $template['type'],
'show-logos' => $hasReviews,
'show-stars' => $hasReviews && $this->isPlatformStarsOptionAvailable(),
];
}
private function getFomoOptionConditions($template)
{
$isFomo = 'fomo' === $template['type'];
$icon = $isFomo ? $this->plugin->getWidgetOption('fomo-icon') : 'hide';
$isCustomText = $isFomo && $this->plugin->isFomoCustomWidget();
$isLinked = $isFomo && 1 === (int)$this->plugin->getWidgetOption('fomo-link');
$hasCounter = $isFomo && (isset($template['params']['fomo-days']) || $this->plugin->isFomoHideCountAvailable());
return [
'fomo-title' => $isCustomText,
'fomo-text' => $isCustomText || ($isFomo && [] !== $this->plugin->getFomoSubtitleTextChoices()),
'fomo-icon' => $isFomo,
'fomo-color' => $isFomo,
'fomo-margin' => $isFomo,
'fomo-day' => $isFomo && isset($template['params']['fomo-days']),
'fomo-hide-count' => $hasCounter,
'fomo-open' => $isFomo && 'hide' !== $icon,
'fomo-border' => $isFomo,
'fomo-icon-background' => $isFomo && !in_array($icon, ['hide', 'platform-images'], true),
'fomo-link' => $isFomo,
'fomo-arrow' => $isLinked,
'fomo-url' => $isLinked && $isCustomText,
];
}
private function isStarFilterAvailable()
{

return true;
}
private function isPlatformStarsOptionAvailable()
{

return false;
}


private function updateOption($name, $value)
{
update_option($this->plugin->get_option_name($name), $value, false);
$this->plugin->resetCachedOptions();
}
private function deleteOptions($names)
{
foreach ($names as $name) {
delete_option($this->plugin->get_option_name($name));
}
$this->plugin->resetCachedOptions();
}
}
?>
