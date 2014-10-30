<?php
//add_action('transition_post_status', 'refresh_wp_redis_cache',10,3);
add_action('wp_ajax_clear_wp_redis_cache', 'clear_wp_redis_cache');
add_action( 'admin_footer', 'clear_wp_redis_cache_javascript' );

//clears the cache after you update a post
function refresh_wp_redis_cache( $new, $old, $post )
{
	if($new == "publish")
	{
		$permalink = get_permalink( $post->ID );

		// aaronstpierre: this needs to be include_once so as not to cauase a redeclaration error
    include_once(__DIR__."/../../../wp-config-redis.php");
		include_once("predis5.2.php");  //we need this to use Redis inside of PHP
		$redis = new Predis_Client(array(
          "scheme" => "tcp",
          "host"   => $reddis_server,
          "port"   => 6379,
          "timeout" => 5,
          "password"   => $secret_string
        ));

		$redis_key = md5($permalink);
    $redis->del($redis_key);

		//refresh the front page
		$frontPage = get_home_url() . "/";
		$redis_key = md5($frontPage);
		$redis->del($redis_key);
	}
}

// clears the whole cache
function clear_wp_redis_cache()
{
  include_once(__DIR__."/../../../wp-config-redis.php");
	include_once("predis5.2.php"); //we need this to use Redis inside of PHP
	$args = array( 'post_type' => 'any', 'posts_per_page' => -1);
	$wp_query = new WP_Query( $args); // to get all Posts
	$redis = new Predis_Client(array(
          "scheme" => "tcp",
          "host"   => $reddis_server,
          "port"   => 6379,
          "timeout" => 5,
          "password"   => $secret_string
        ));

  // warning: this flushes your entire redis cache... if you're not using redis for anything but your WP instance, then this should be fine.
  // otherwise, comment this out and use the loop below.
  $redis->flushdb();
  echo "Flushed redis DB";
  die();

	// Loop all posts and clear the cache
  // ISSUE: This doesn't clear everything. taxonomies, etc don't get cleared (possible bug)
  // TODO: Fix
	$i = 0;
	while ( $wp_query->have_posts() ) : $wp_query->the_post();
		$permalink = get_permalink();

		$redis_key = md5($permalink);
		if (($redis->exists($redis_key)) == true ) {
			$redis->del($redis_key);
			$i++;
		}


	endwhile;

	echo $i++." of " . $wp_query  -> found_posts . " posts was cleared in cache";
	die();
}

function clear_wp_redis_cache_javascript() {
?>
<script type="text/javascript" >
jQuery(document).ready(function($) {

	jQuery('#WPRedisClearCache').click(function(){
		var data = {
			action: 'clear_wp_redis_cache'
		};

		// since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
		$.post(ajaxurl, data, function(response) {
			alert(response);
		});
	});
});
</script>
<?php
}
?>