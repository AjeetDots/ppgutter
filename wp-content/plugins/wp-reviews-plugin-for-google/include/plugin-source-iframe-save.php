<?php 
defined('ABSPATH') or die('No script kiddies please!'); 
 
$platform = ucfirst($pluginManagerInstance->getShortName()); 
 
if ('Google' === $platform && $pluginManagerInstance->is_noreg_linked()) { 
$pageDetails = $pluginManagerInstance->getPageDetails(); 
 
$data = [ 
'platform' => $platform, 
'pageId' => $pageDetails['id'], 
'name' => $pageDetails['name'], 
'address' => $pageDetails['address'], 
'avatarUrl' => $pageDetails['avatar_url'], 
]; 
 
echo '<script id="ti-plugin-source-data" type="application/ld+json">{"@context":"http://schema.org","data":"'.esc_attr(wp_json_encode($data)).'"}</script>'; 
} 
?> 
