<?php

/**
* index.php should contain:
*     include "index-wp-redis.php";
*     exit;
*/

function getCleanUrl($secret) {
    $replaceKeys = array("?refresh=${secret}","&refresh=${secret}");
    $url = "http://${_SERVER['HTTP_HOST']}${_SERVER['REQUEST_URI']}";
    $current_url = str_replace($replaceKeys, '', $url);
    return $current_url;
}

$debug          = false; // see debug note below
$cache          = true;
$websiteIp      = 'this_site_ip';
$reddis_server  = 'host_or_ip';
$secret_string  = 'changeme';
$current_url    = getCleanUrl($secret_string);
$redis_key      = md5($current_url);
$redis_key_headers = $redis_key."_headers";

/**
* A note about debugging
*
* Use with caution. Any file type that wordpress would typically generate, such as an xml file,
* can become broken due to the debug output.
*/