<?php

/**
 * index.php should contain:
 *     include "index-wp-redis.php";
 *     exit;
 */

include "wp-config-redis.php";

// Start the timer so we can track the page load time
$start = microtime();

function getMicroTime($time) {
  list($usec, $sec) = explode(" ", $time);
  return ((float) $usec + (float) $sec);
}

function refreshHasSecret($secret) {
  return isset($_GET['refresh']) && $_GET['refresh'] == $secret;
}

function requestHasSecret($secret) {
  return strpos($_SERVER['REQUEST_URI'],"refresh=${secret}")!==false;
}

function isRemotePageLoad($currentUrl, $websiteIp) {
  return (isset($_SERVER['HTTP_REFERER'])
    && $_SERVER['HTTP_REFERER']== $currentUrl
    && $_SERVER['REQUEST_URI'] != '/'
    && $_SERVER['REMOTE_ADDR'] != $websiteIp);
}

function handleCDNRemoteAddressing() {
  // so we don't confuse the cloudflare server
  if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
  }
}

handleCDNRemoteAddressing();

if(!defined('WP_USE_THEMES')) {
  define('WP_USE_THEMES', true);
}

function compressOutput($html = "") {
  global $redis_key;
  header("X-Page-Cached: $redis_key");
  header("Content-Type: text/html; charset=utf-8");
  header("Cache-control: must-revalidate");
  header("Expires: ".gmdate("D, d M Y H:i:s", time() + 3600)." GMT");
  header("Vary: Accept-Encoding");

  $HTTP_ACCEPT_ENCODING = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : false;
  $encoding             = false;

  if(strpos($HTTP_ACCEPT_ENCODING, "x-gzip")    !== false) $encoding = "x-gzip";
  else if(strpos($HTTP_ACCEPT_ENCODING, "gzip") !== false) $encoding = "gzip";

  // Test if stored page is already gzipped
  $alreadyGZIP = @gzdecode($html);
  if($alreadyGZIP !== false) {
    if($encoding !== false) {
      // client accepts gzip, send gzipped contents
      header("Content-Encoding: " . $encoding);
      echo $html;
    } else {
      // request doesn't support gzip, send plain
      echo $alreadyGZIP;
    }
    exit;
  }

  // encode only if encoding exists and gzcompress function exists
  if($encoding && function_exists("gzcompress")) {
    // set gzip, compress, and send
    header("Content-Encoding: ".$encoding);
    echo "\x1f\x8b\x08\x00\x00\x00\x00\x00";
    echo substr(gzcompress($html), 0, -4);
    echo pack('V', crc32($html)); // pack the crc32
    echo pack('V', strlen($html)); // pack the size
  } else {
    // no gzip, send plain
    echo $html;
  }

  return;
}

try {
  // check if PECL Extension is available
  /** Force use of PREDIS
  if (class_exists('Redis')) {
  if ($debug) {
  echo "<!-- Redis PECL module found -->\n";
  }
  $redis = new Redis();

  // Sockets can be used as well. Documentation @ https://github.com/nicolasff/phpredis/#connection
  $redis->connect($reddis_server);

  } else { // Fallback to predis5.2.php
   **/
  if ($debug) {
    echo "<!-- using predis as a backup -->\n";
  }
  include_once("wp-content/plugins/wp-redis-cache/predis5.2.php"); //we need this to use Redis inside of PHP
  $redis = new Predis_Client(array(
    "scheme" => "tcp",
    "host"   => $reddis_server,
    "port"   => 6379,
    "timeout" => 5,
    "password"   => $secret_string
  ));
  /**
  }
   **/

  $loggedIn = preg_match("/wordpress_logged_in/", var_export($_COOKIE, true));
  $isPOST = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 1 : 0;
  //Either manual refresh cache by adding ?refresh=secret_string after the URL or somebody posting a comment
  if (refreshHasSecret($secret_string) || requestHasSecret($secret_string)/* || isRemotePageLoad($current_url, $websiteIp)*/) {
    if ($debug) {
      echo "<!-- manual refresh was required -->\n";
    }
    $redis->del($redis_key);
    require('./wp-blog-header.php');

    $unlimited = get_option('wp-redis-cache-debug',false);
    $seconds_cache_redis = get_option('wp-redis-cache-seconds',43200);
    // This page is cached, lets display it
  } else if ($redis->exists($redis_key) && !$loggedIn && !$isPOST) {
    if ($debug) {
      echo "<!-- serving page from cache: key: $redis_key -->\n";
    }
    $cache  = true;
    $html_of_page = $redis->get($redis_key);

    compressOutput($html_of_page);
    exit;

    // If the cache does not exist lets display the user the normal page without cache, and then fetch a new cache page
  } else if ($_SERVER['REMOTE_ADDR'] != $websiteIp && strstr($current_url, 'preview=true') == false) {
    if ($debug) {
      echo "<!-- displaying page without cache -->\n";
    }

    if (!$isPOST && !$loggedIn) {
      ob_start();
      $level = ob_get_level();
      require('./wp-blog-header.php');
      while(ob_get_level() > $level) ob_end_flush();
      $html_of_page = ob_get_clean();
      compressOutput($html_of_page);

      $unlimited = get_option('wp-redis-cache-debug',false);
      $seconds_cache_redis = get_option('wp-redis-cache-seconds',43200);

      if (!is_numeric($seconds_cache_redis)) {
        $seconds_cache_redis = 43200;
      }

      // When the search was used, do not cache
      //if ((!is_404()) and (!is_search()))  {
      if(!is_search()) {
        if ($unlimited) {
          $redis->set($redis_key, $html_of_page);
        } else {
          $redis->setex($redis_key, $seconds_cache_redis, $html_of_page);
        }
      }
    } else { //either the user is logged in, or is posting a comment, show them uncached
      require('./wp-blog-header.php');
    }
  } else if ($_SERVER['REMOTE_ADDR'] != $websiteIp && strstr($current_url, 'preview=true') == true) {
    require('./wp-blog-header.php');
  } else { // something else..
    require('./wp-blog-header.php');
  }
} catch (Exception $e) {
  require('./wp-blog-header.php');
  /*echo "something went wrong";
  echo $e->getMessage();
  echo "<br><pre>";
  echo $e->getTraceAsString();*/
}

$end  = microtime();
$time = (@getMicroTime($end) - @getMicroTime($start));
if ($debug) {
  echo "\n<!-- Page ($redis_key) generated in " . round($time, 5) . " seconds. -->\n";
  echo "<!-- Site was cached  = " . $cache . " -->\n";
  if (isset($seconds_cache_redis)) {
    echo "<!-- wp-redis-cache-seconds  = " . $seconds_cache_redis . " -->\n";
  }
  echo "<!-- wp-redis-cache-secret  = " . $secret_string . "-->\n";
  echo "<!-- wp-redis-cache-ip  = " . $websiteIp . "-->\n";
  if (isset($unlimited)) {
    echo "<!-- wp-redis-cache-unlimited = " . $unlimited . "-->\n";
  }
  echo "<!-- wp-redis-cache-debug  = " . $debug . "-->\n";
}
