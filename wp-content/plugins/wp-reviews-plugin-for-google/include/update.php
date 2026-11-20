<?php
defined('ABSPATH') or die('No script kiddies please!');
require_once(ABSPATH . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'upgrade.php');
global $wpdb;
$previousVersion = $this->getVersion('update-version-check');
$isNewInstall = '1.0' === $previousVersion;
$updateRetryTransient = 'ti-update-retry-'.$this->getShortName();
if (0 !== version_compare($this->getVersion(), $previousVersion) && false === get_transient($updateRetryTransient)) {
$tableName = $this->get_tablename('reviews');
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$results = $wpdb->get_results($wpdb->prepare('SHOW FULL COLUMNS FROM %i', $tableName), ARRAY_A);
$columns = array_column($results, 'Field');

if (!in_array('highlight', $columns)) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD highlight VARCHAR(11) NULL AFTER rating', $tableName));
}

if (!in_array('reply', $columns)) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD reply TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL AFTER date', $tableName));
}
if (in_array('replied', $columns)) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP replied', $tableName));
}
if (!in_array('reviewId', $columns)) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD reviewId TEXT NULL AFTER date', $tableName));
}

if (!in_array('hidden', $columns)) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER id', $tableName));
}

$columnCollations = array_column($results, 'Collation', 'Field');
if (isset($columnCollations['user']) && 0 !== strpos((string)$columnCollations['user'], 'utf8mb4')) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query($wpdb->prepare('ALTER TABLE %i MODIFY `user` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci', $tableName));
}

if (!in_array('photos', $columns)) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query($wpdb->prepare('ALTER TABLE %i ADD photos TEXT NULL AFTER reply', $tableName));
}

if (!$isNewInstall && version_compare($previousVersion, '14.3', '<')) {
$this->setNotificationParam('gdpr-mode', 'active', true);
$this->setNotificationParam('gdpr-mode', 'dashboard-active', true);
}


if (!$isNewInstall && version_compare($previousVersion, '14.4', '<')) {
$this->setNotificationParam('ai-setup-review-photos', 'active', true);
$this->setNotificationParam('ai-setup-review-photos', 'dashboard-active', true);
}

if (version_compare($previousVersion, '14.4', '<')) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query($wpdb->prepare(
'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
$wpdb->options,
$wpdb->esc_like('ti-online-users-').'%',
$wpdb->esc_like('_transient_ti_uid_').'%',
$wpdb->esc_like('_transient_timeout_ti_uid_').'%'
));
}

if (version_compare($previousVersion, '14.4', '<')) {
$this->getAssetProxy()->restoreUrls($this->getReviewImageUrls());
}
$oldRateUs = get_option('trustindex-'. $this->getShortName() .'-rate-us');
if ($oldRateUs) {
if ($oldRateUs === 'hide') {
$this->setNotificationParam('rate-us', 'hidden', true);
}
else {
$this->setNotificationParam('rate-us', 'active', true);
$this->setNotificationParam('rate-us', 'timestamp', $oldRateUs);
}
}
$oldNotificationEmail = get_option('trustindex-'. $this->getShortName() .'-review-download-notification-email');
if ($oldNotificationEmail) {
$this->setNotificationParam('review-download-finished', 'email', $oldNotificationEmail);
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$results = $wpdb->get_results($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, 'trustindex-'.$this->getShortName().'-%'), ARRAY_A);
$optionNamesInDb = array_column($results, 'option_name');
$usedOptionNames = [];
foreach ($this->get_option_names() as $optName) {
$usedOptionNames []= $this->get_option_name($optName);
}
foreach ($optionNamesInDb as $optName) {
if (!in_array($optName, $usedOptionNames)) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->delete($wpdb->options, ['option_name' => $optName]);
}
}
if (get_option($this->get_option_name('css-content'))) {
$cssCdnVersion = $this->getCdnVersion('widget-css');
if ($cssCdnVersion && version_compare($cssCdnVersion, $this->getVersion('widget-css'))) {
if (is_wp_error($this->saveWidgetCss(true))) {
set_transient($updateRetryTransient, 1, 10 * MINUTE_IN_SECONDS);
return;
}
$this->updateVersion('widget-css', $cssCdnVersion);
}
$this->handleCssFile();
}
if (get_option($this->get_option_name('review-content'))) {
$htmlCdnVersion = $this->getCdnVersion('widget-html');
if ($htmlCdnVersion && version_compare($htmlCdnVersion, $this->getVersion('widget-html'))) {
delete_option($this->get_option_name('review-content'));
$this->updateVersion('widget-html', $htmlCdnVersion);
}
}
if (!$this->is_table_exists('views')) {
$tiReviewsTableName = $this->get_tablename('reviews');
$tiViewsTableName = $this->get_tablename('views');
include $this->get_plugin_dir() . 'include' . DIRECTORY_SEPARATOR . 'schema.php';
try {
dbDelta(trim($ti_db_schema['views']));
} catch (Exception $e) { }
}
$this->updateVersion('update-version-check', $this->getVersion());
}
?>
