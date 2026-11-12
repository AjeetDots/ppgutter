<?php
defined('ABSPATH') or die('No script kiddies please!');
class TrustindexAssetProxy_google
{
private static $enabledOption = 'trustindex-asset-proxy';
private static $secretOption = 'trustindex-asset-proxy-secret';
private static $revokedOption = 'trustindex-asset-proxy-revoked';
private static $maxRevokedCount = 1000;
private static $garbageCollectionTransient = 'trustindex-asset-proxy-gc';
private static $cdnUrl = 'https://cdn.trustindex.io/';
private static $maxSize = 5242880;
private static $failureLifetime = 600;
private static $garbageLifetime = 2592000;
private static $publicCdnPaths = [
'loader' => 'loader\\.js',
'view' => 'widgets\\/[a-z0-9]{2}\\/[a-z0-9]+\\/content\\.html',
'html' => 'widgets\\/[a-z0-9]{2}\\/[a-z0-9]+\\/_subcontent-\\d+\\.html',
'sprite' => 'widgets\\/[a-z0-9]{2}\\/[a-z0-9]+\\/sprite\\.(?:jpg|webp)',
'css' => 'assets\\/widget-presetted-css\\/(?:v\\d+\\/)?[a-z0-9_-]+\\.css',
'file' => 'assets\\/(?:platform\\/[a-z0-9_\\/.-]+|default-avatar\\/noprofile-\\d+|img\\/ai-profile-image)\\.svg|companies\\/default_avatar\\.jpg',
];
private static $lifetimes = [ 'loader' => 3600, 'view' => 0, 'html' => 3600, 'sprite' => 3600, 'css' => 3600, 'file' => 604800 ];
private static $allowedTypes = [
'loader' => [ 'text/javascript', 'application/javascript' ],
'view' => [ 'text/html' ],
'html' => [ 'text/html' ],
'sprite' => [ 'image/jpeg', 'image/webp' ],
'css' => [ 'text/css' ],
'file' => [
'image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif',
'font/woff2', 'font/woff', 'application/font-woff2', 'application/font-woff',
],
];
private static $jsonUrlKeys = [ 'src', 'thumbnail', 'images' ];
private static $googleImageSizes = [ 'src' => 1200, 'thumbnail' => 240 ];
private $plugin;
private $filesystem = null;
public function __construct($plugin)
{
$this->plugin = $plugin;
}
public function isEnabled()
{
return 1 === (int)get_option(self::$enabledOption, 0);
}
public function setEnabled($isEnabled)
{
return update_option(self::$enabledOption, (int)$isEnabled, true);
}
public function getLoaderUrl()
{
return $this->getAssetUrl(self::$cdnUrl.'loader.js');
}
public function getAssetUrl($url)
{
return $this->isEnabled() && !is_admin() ? $this->getProxyUrl($url) : $url;
}
public function rewriteWidgetHtml($html)
{
if (!$this->isEnabled()) {
return $html;
}
$html = $this->rewriteHtml($html);
$html = $this->rewriteTagContents($html, 'style', [ $this, 'rewriteCss' ]);
return $this->rewriteTagContents($html, 'script', [ $this, 'rewriteJson' ]);
}
public function rewriteHtml($html)
{
if (!$this->isEnabled()) {
return $html;
}
$result = preg_replace_callback('/(\s(src|srcset)=")([^"]+)"/i', function ($match) {
$value = html_entity_decode($match[3], ENT_QUOTES);
$value = 'srcset' === strtolower($match[2]) ? $this->getProxySrcset($value) : $this->getProxyUrl($value);
return $match[1].esc_attr($value).'"';
}, $html);
return null === $result ? $html : $result;
}
public function rewriteCss($css)
{
if (!$this->isEnabled()) {
return $css;
}
$css = str_replace('../../../assets', self::$cdnUrl.'assets', $css);
$result = preg_replace_callback('/url\((["\']?)((?:https?:)?\/\/[^"\')]+)\1\)/i', function ($match) {
return 'url('.$match[1].preg_replace('/^https?:/i', '', $this->getProxyUrl($match[2])).$match[1].')';
}, $css);
return null === $result ? $css : $result;
}
public function getProxyUrl($url)
{
$asset = $this->getAssetName($url);
if (null === $asset) {
return $url;
}
$query = 'trustindex-asset='.str_replace('%2F', '/', rawurlencode($asset));
if (!$this->isPublicAsset($asset)) {
$query = 's='.$this->sign($asset).'&'.$query;
}
return $this->getSiteUrl().'/?'.$query;
}
public function handleRequest()
{
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public file endpoint, the asset name is validated in isRequestedAssetValid()
$asset = isset($_GET['trustindex-asset']) ? wp_unslash($_GET['trustindex-asset']) : "";
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public file endpoint, the signature is the verification
$signature = isset($_GET['s']) ? sanitize_key(wp_unslash($_GET['s'])) : "";
if (!$this->isEnabled() || !is_string($asset) || !$this->isRequestedAssetValid($asset, $signature)) {
status_header(404);
exit;
}
$this->collectGarbage();
$this->sendAsset($asset);
}
public function isCacheWritable()
{
$filesystem = $this->getFilesystem();
return null !== $filesystem && $this->prepareCacheDir($filesystem);
}
public function clearCache()
{
$filesystem = $this->getFilesystem();
return null !== $filesystem && $filesystem->rmdir($this->getCacheDir(), true);
}
public function delete()
{
$this->clearCache();
delete_option(self::$enabledOption);
delete_option(self::$secretOption);
delete_option(self::$revokedOption);
delete_transient(self::$garbageCollectionTransient);
}
public function revokeUrls($urls)
{
$keys = $this->getRevocationKeys($urls);
if ([] === $keys) {
return;
}
$revokedKeys = array_values(array_unique(array_merge($this->getRevokedKeys(), $keys)));
update_option(self::$revokedOption, array_slice($revokedKeys, -self::$maxRevokedCount), false);
$this->clearCache();
}
public function restoreUrls($urls)
{
$revokedKeys = $this->getRevokedKeys();
$remainingKeys = array_values(array_diff($revokedKeys, $this->getRevocationKeys($urls)));
if (count($remainingKeys) !== count($revokedKeys)) {
update_option(self::$revokedOption, $remainingKeys, false);
}
}
private function getRevocationKeys($urls)
{
$keys = [];
foreach ($urls as $url) {
$asset = is_string($url) ? $this->getAssetName($url) : null;
if (null !== $asset) {
$keys []= $this->getRevocationKey($asset);
}
}
return $keys;
}
private function getRevocationKey($asset)
{
$isGoogleImage = 1 === preg_match('/^[^\/]*googleusercontent\.com\//i', $asset);
return hash('sha256', $isGoogleImage ? preg_replace('/=[^\/]*$/', '', $asset) : $asset);
}
private function getRevokedKeys()
{
$keys = get_option(self::$revokedOption, []);
return is_array($keys) ? $keys : [];
}
private function isRevoked($asset)
{
return in_array($this->getRevocationKey($asset), $this->getRevokedKeys(), true);
}
private function rewriteTagContents($html, $tag, $callback)
{
$result = preg_replace_callback('/(<'.$tag.'\\b[^>]*>)(.*?)(<\\/'.$tag.'>)/is', function ($match) use ($callback) {
return $match[1].call_user_func($callback, $match[2]).$match[3];
}, $html);
return null === $result ? $html : $result;
}
private function rewriteJson($json)
{
$data = json_decode($json, true);
if (!is_array($data)) {
return $json;
}
$rewrittenData = $this->rewriteJsonUrls($data, null);
return $rewrittenData === $data ? $json : wp_json_encode($rewrittenData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}
private function rewriteJsonUrls($value, $key)
{
if (is_array($value)) {
foreach ($value as $childKey => $child) {
$value[$childKey] = $this->rewriteJsonUrls($child, is_int($childKey) ? $key : $childKey);
}
return $value;
}
if (!is_string($value) || !in_array($key, self::$jsonUrlKeys, true)) {
return $value;
}
return $this->getProxyUrl($this->getSizedGoogleImageUrl($value, $key));
}
private function getSizedGoogleImageUrl($url, $key)
{
if (!isset(self::$googleImageSizes[$key]) || !preg_match('/^(?:https?:)?\\/\\/[^\\/]*googleusercontent\\.com\\//i', $url)) {
return $url;
}
return preg_replace('/=[^\\/]*$/', '', $url).'=s'.self::$googleImageSizes[$key];
}
private function getSiteUrl()
{
return set_url_scheme(untrailingslashit(get_option('home')));
}
private function getProxySrcset($srcset)
{
return implode(', ', array_map(function ($candidate) {
$parts = preg_split('/\s+/', trim($candidate), 2);
return trim($this->getProxyUrl($parts[0]).' '.(isset($parts[1]) ? $parts[1] : ""));
}, preg_split('/,\s+/', trim($srcset))));
}
private function getAssetName($url)
{
if (!preg_match('/^(?:https?:)?\/\/([^\/?#]+)(\/[^#]*)/i', $url, $match) || $this->isSiteHost($match[1])) {
return null;
}
return strtolower($match[1]).$match[2];
}
private function isSiteHost($host)
{
$siteHost = (string)wp_parse_url(get_option('home'), PHP_URL_HOST);
return strtolower(preg_replace('/:\d+$/', '', $host)) === strtolower($siteHost);
}
private function isPublicAsset($asset)
{
return null !== $this->getPublicAssetKind($asset);
}
private function getPublicAssetKind($asset)
{
$cdnPrefix = preg_quote($this->getCdnAssetPrefix(), '/');
foreach (self::$publicCdnPaths as $kind => $path) {
if (preg_match('/^'.$cdnPrefix.'(?:'.$path.')$/i', $asset)) {
return $kind;
}
}
return null;
}
private function getAssetKind($asset)
{
$kind = $this->getPublicAssetKind($asset);
return null === $kind ? 'file' : $kind;
}
private function isRequestedAssetValid($asset, $signature)
{
if (!preg_match('/^[a-z0-9.-]+(?::\d+)?\/[^\s\\\\]*$/i', $asset) || false !== strpos($asset, '..')) {
return false;
}
return $this->isPublicAsset($asset) || (hash_equals($this->sign($asset), $signature) && !$this->isRevoked($asset));
}
private function getCdnAssetPrefix()
{
return preg_replace('/^https?:\/\//i', '', self::$cdnUrl);
}
private function sign($asset)
{
return substr(hash_hmac('sha256', $asset, $this->getSecret()), 0, 16);
}
private function getSecret()
{
$secret = get_option(self::$secretOption);
if (!is_string($secret) || '' === $secret) {
$secret = wp_generate_password(64, false);
update_option(self::$secretOption, $secret, true);
}
return $secret;
}
private function sendAsset($asset)
{
$file = $this->loadAsset($asset);
if (null === $file['type']) {
status_header(404);
exit;
}
$kind = $this->getAssetKind($asset);
$file['body'] = $this->rewriteBody($kind, $file['body']);
$this->output($file, self::$lifetimes[$kind]);
}
private function output($file, $lifetime)
{
header_remove('Set-Cookie');
status_header(200);
header('Content-Type: '.$file['type']);
header('Cache-Control: '.(0 < $lifetime ? 'public, max-age='.$lifetime : 'no-cache'));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('X-Robots-Tag: noindex');
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file content
echo $file['body'];
exit;
}
private function loadAsset($asset)
{
$isCacheable = 0 < self::$lifetimes[$this->getAssetKind($asset)];
$cacheFile = $this->getCacheDir().hash_hmac('sha256', $asset, $this->getSecret());
$file = $isCacheable ? $this->readCache($cacheFile) : null;
if (null === $file) {
$file = $this->downloadAsset($asset);
if ($isCacheable && (null !== $file['type'] || !$this->isPublicAsset($asset))) {
$this->writeCache($cacheFile, $file);
}
}
return $file;
}
private function downloadAsset($asset)
{
$kind = $this->getAssetKind($asset);
$response = wp_safe_remote_get('https://'.$asset, [
'timeout' => 5,
'redirection' => 3,
'limit_response_size' => self::$maxSize,
'headers' => 'view' === $kind ? [ 'Referer' => $this->getSiteUrl().'/' ] : [],
]);
$body = wp_remote_retrieve_body($response);
$type = $this->getContentType($response, $asset);
$isDownloaded = 200 === (int)wp_remote_retrieve_response_code($response) && strlen($body) < self::$maxSize;
if (!$isDownloaded || !in_array($type, self::$allowedTypes[$kind], true)) {
return [ 'type' => null, 'body' => "", 'expires' => time() + self::$failureLifetime ];
}
return [ 'type' => $type, 'body' => $body, 'expires' => time() + self::$lifetimes[$kind] ];
}
private function rewriteBody($kind, $body)
{
if ('css' === $kind) {
return $this->rewriteCss($body);
}
return 'view' === $kind || 'html' === $kind ? $this->rewriteWidgetHtml($body) : $body;
}
private function getContentType($response, $asset)
{
$header = wp_remote_retrieve_header($response, 'content-type');
if (is_array($header)) {
$header = end($header);
}
$type = strtolower(trim(explode(';', (string)$header)[0]));
if ('' !== $type && 'application/octet-stream' !== $type) {
return $type;
}
$fileType = wp_check_filetype((string)strtok($asset, '?'));
return is_string($fileType['type']) ? $fileType['type'] : $type;
}
private function readCache($cacheFile)
{
$filesystem = $this->getFilesystem();
$meta = null === $filesystem ? null : json_decode((string)$filesystem->get_contents($cacheFile.'.json'), true);
if (!is_array($meta) || !array_key_exists('type', $meta) || (int)$meta['expires'] < time()) {
return null;
}
$body = null === $meta['type'] ? "" : $filesystem->get_contents($cacheFile.'.bin');
return false === $body ? null : [ 'type' => $meta['type'], 'body' => $body, 'expires' => (int)$meta['expires'] ];
}
private function writeCache($cacheFile, $file)
{
$filesystem = $this->getFilesystem();
if (null === $filesystem || !$this->prepareCacheDir($filesystem)) {
return false;
}
$tmpFile = $cacheFile.'.'.wp_generate_password(8, false).'.tmp';
if (null !== $file['type'] && (!$filesystem->put_contents($tmpFile, $file['body'], FS_CHMOD_FILE) || !$filesystem->move($tmpFile, $cacheFile.'.bin', true))) {
return false;
}
return $filesystem->put_contents($cacheFile.'.json', wp_json_encode([ 'type' => $file['type'], 'expires' => $file['expires'] ]), FS_CHMOD_FILE);
}
private function getCacheDir()
{
return trailingslashit(wp_upload_dir(null, false)['basedir']).'trustindex-asset-cache/';
}
private function prepareCacheDir($filesystem)
{
$dir = $this->getCacheDir();
if ($filesystem->is_dir($dir)) {
return $filesystem->is_writable($dir);
}
$htaccess = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
return wp_mkdir_p($dir) && $filesystem->put_contents($dir.'.htaccess', $htaccess, FS_CHMOD_FILE);
}
private function getFilesystem()
{
global $wp_filesystem;
if (null !== $this->filesystem) {
return $this->filesystem ?: null;
}
require_once ABSPATH.'wp-admin'.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'file.php';
add_filter('filesystem_method', [ $this->plugin, 'filter_filesystem_method' ]);
$isLoaded = WP_Filesystem();
remove_filter('filesystem_method', [ $this->plugin, 'filter_filesystem_method' ]);
$this->filesystem = $isLoaded && 'direct' === $wp_filesystem->method ? $wp_filesystem : false;
return $this->filesystem ?: null;
}
private function collectGarbage()
{
if (get_transient(self::$garbageCollectionTransient)) {
return;
}
set_transient(self::$garbageCollectionTransient, 1, DAY_IN_SECONDS);
$filesystem = $this->getFilesystem();
$files = null === $filesystem ? false : $filesystem->dirlist($this->getCacheDir());
foreach (is_array($files) ? $files : [] as $name => $file) {
if ('.htaccess' !== $name && $file['lastmodunix'] < time() - self::$garbageLifetime) {
$filesystem->delete($this->getCacheDir().$name);
}
}
}
}
?>
