<?php
/**
 * Plugin Name: Statistics-Light
 * Plugin URI:  https://www.it-sbs.de/Wordpress-Plugin-Statistics-Light/
 * Description: Statistics-Light - A lightweight statistic tool for Wordpress
 * Version:     0.6
 * Author:      it-sbs.de
 * Author URI:  https://www.it-sbs.de/
 * Text Domain: statistics-light
 * Domain Path: /languages
 */

define("ITSBSDE_SL_prefix", "itsbsde_sl_");
define("ITSBSDE_SL_URL", "https://www.it-sbs.de/Wordpress-Plugin-Statistics-Light/");
define("ITSBSDE_SL_URL_EN", "https://www.tinkering-sascha.com/wordpress-plugin-statistics-light/");
define("ITSBSDE_SL_VERSION", "0.6");

function itsbsde_sl_run()
{
    if (itsbsde_sl_is_bot())
    {
        return;
    }
    
    global $wpdb;
    
    $ip = esc_html($_SERVER['REMOTE_ADDR']);
    $uri = esc_html(explode("?", $_SERVER['REQUEST_URI'])[0]);
    $date = date("Y-m-d", time());
    
    $count = $wpdb->get_var("SELECT COUNT(*) FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."visitor WHERE entry_url='".$uri."' AND ip='".$ip."'");
    if ($count == 0)
    {
        $wpdb->query("INSERT INTO ".$wpdb->prefix.ITSBSDE_SL_prefix."visitor (ip, entry_url, datum) VALUES ('".$ip."', '".$uri."', '".$date."')");
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."visitor WHERE ip='".$ip."'");
        if ($count <= 1)
        {
            $count = $wpdb->query("UPDATE ".$wpdb->prefix.ITSBSDE_SL_prefix."visits SET hits=hits+1 WHERE datum='".$date."'");
            if ($count <= 0)
            {
                $wpdb->query("INSERT INTO ".$wpdb->prefix.ITSBSDE_SL_prefix."visits (datum, hits) VALUES ('".$date."', '1')");
            }
            
            if (isset($_SERVER['HTTP_REFERER']))
            {
                $ref = esc_html(explode("?", $_SERVER['HTTP_REFERER'])[0]);
                $myblog_url = get_home_url();
                
                $replace = array("http://", "https://", "www.");
                foreach ($replace as $r)
                {
                    $ref = str_replace($r, "", $ref);
                    $myblog_url = str_replace($r, "", $myblog_url);
                }
                
                if (strlen($ref)>0 && $myblog_url!=$ref)
                {
                    $count = $wpdb->query("UPDATE ".$wpdb->prefix.ITSBSDE_SL_prefix."referer SET hits=hits+1 WHERE datum='".$date."' AND referer='".$ref."'");
                    if ($count <= 0)
                    {
                        $wpdb->query("INSERT INTO ".$wpdb->prefix.ITSBSDE_SL_prefix."referer (referer, datum, hits, domain) VALUES ('".$ref."', '".$date."', '1', '".explode("/", $ref)[0]."')");
                    }
                }
            }
        }
        
        if (is_home() || is_category() || is_single() || is_page())
        {
            $count = $wpdb->query("UPDATE ".$wpdb->prefix.ITSBSDE_SL_prefix."pages SET hits=hits+1 WHERE datum='".$date."' AND url='".$uri."'");
            if ($count <= 0)
            {
                if (is_single() || is_page())
                {
                    $post_id = get_the_id();
                    $post_type = get_post_type();
                }
                else if (is_category())
                {
                    $categories = get_the_category();
                    $post_id = (!empty($categories)) ? $categories[0]->term_id : 0; 
                    $post_type = "category";
                }
                else
                {
                    $post_id = 0;
                    $post_type = "";
                }
                
                $wpdb->query("INSERT INTO ".$wpdb->prefix.ITSBSDE_SL_prefix."pages (url, page_type, page_id, datum, hits"
                            .") VALUES ("
                            ."'".$uri."', '".$post_type."', '".$post_id."', '".$date."', '1'"
                            .")");
            }
        }
    }
    
    //delete old visits and ip-adresses
    $wpdb->query("DELETE FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."visitor WHERE datum<'".$date."'");
    
    //cron - refresh data export for app
    $api_url = get_option('itsbsde_sl_option_api');
    if (strlen($api_url)>0)
    {
        $dt1 = new DateTime(date("Y-m-d H:i:s", time()));
        $dt2 = new DateTime(get_option('itsbsde_sl_option_last_update'));
        
        $diff = $dt1->diff($dt2);
        $hours = $diff->h + ($diff->days*24);
        
        if ($hours>1)
        {
            itsbsde_sl_update_stat($api_url);
        }
    }
}
add_action('wp', 'itsbsde_sl_run');

function itsbsde_sl_getStatsForApp()
{
    global $wpdb;
    
    $stat_data = "";
    $delim = ";";
    $delim_new = "\n";
    
    $one_day = 60*60*24;
    $time_now = time();
    for ($i=0; $i<30; $i++)
    {
        $day = date("Y-m-d", $time_now - ($one_day*$i));
        $count = $wpdb->get_var("SELECT hits FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."visits WHERE datum='".$day."'");
        
        if (!is_numeric($count))
            $count = 0;
            
        $stat_data .= $count.$delim;
    }
    
    $last_30_days = date("Y-m-d", $time_now - ($one_day*30));
    $q = "SELECT url, SUM(hits) AS clicks FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."pages WHERE datum>='".$last_30_days."'"
        ." GROUP BY url ORDER BY SUM(hits) DESC LIMIT 0,15";
    $res = $wpdb->get_results($q, OBJECT);
    foreach ($res as $r)
    {
        $stat_data .= $delim_new.$r->url.$delim.$r->clicks;
    }
    
    $stat_data .= $delim_new."#REFS";
    
    $q = "SELECT domain, SUM(hits) AS clicks FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."referer WHERE datum>='".$last_30_days."'"
        ." GROUP BY domain ORDER BY SUM(hits) DESC LIMIT 0,15";
    $res = $wpdb->get_results($q, OBJECT);
    foreach ($res as $r)
    {
        $stat_data .= $delim_new.$r->domain.$delim.$r->clicks;
    }
    
    $content = "<!--STAT_DATA:".base64_encode($stat_data)."-->";
    
    return $content;
}

function itsbsde_sl_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    global $wpdb;
    
    $one_day = 60*60*24;
    
    $today = date("Y-m-d", time());
    $yesterday = date("Y-m-d", time() - (1*$one_day));
    $last_7_days = date("Y-m-d", time() - (7*$one_day));
    $last_14_days = date("Y-m-d", time() - (14*$one_day));
    $last_30_days = date("Y-m-d", time() - (30*$one_day));
    
    $arr_load = array("Last 7 days" => $last_7_days
                    , "Last 14 days" => $last_14_days
                    , "Last 30 days" => $last_30_days
    );
    
    $api_path = plugin_dir_url(__FILE__)."tmp/";
    ?>
    <div class="wrap">
    	<h1>Statistics-Light - <a href="<?php echo ITSBSDE_SL_URL; ?>" target="_blank">www.it-sbs.de</a></h1>
    	
    	<h2>Visitors</h2>
    	<?php 
    	   echo "Today: ".$wpdb->get_var("SELECT hits FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."visits WHERE datum='".$today."'")."<br>";
    	   echo "Yesterday: ".$wpdb->get_var("SELECT hits FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."visits WHERE datum='".$yesterday."'")."<br>";
    	   
    	   foreach ($arr_load as $k => $v)
    	   {
    	       $count = $wpdb->get_var("SELECT SUM(hits) FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."visits WHERE datum>='".$v."'");
    	       echo $k.": ".$count."<br>";
    	   }
    	?>
    	
    	<table style="width:100%">
    	<tr>
    		<td style="width:50%; vertical-align: top;">
            	<h2>Most clicked - Last 30 days</h2>
            	<?php 
            	   $q = "SELECT url, SUM(hits) AS clicks FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."pages WHERE datum>='".$last_30_days."'"
                        ." GROUP BY url ORDER BY SUM(hits) DESC LIMIT 0,30";
            	   $res = $wpdb->get_results($q, OBJECT);
            	   foreach ($res as $r)
            	   {
            	       echo $r->url." | Hits: ".$r->clicks."<br>";
            	   }
            	?>
			</td>
			<td style="width:50%; vertical-align: top;">
            	<h2>Top Referrers - Last 30 days</h2>
            	<?php 
            	   $q = "SELECT domain, SUM(hits) AS clicks FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."referer WHERE datum>='".$last_30_days."'"
                        ." GROUP BY domain ORDER BY SUM(hits) DESC LIMIT 0,30";
            	   $res = $wpdb->get_results($q, OBJECT);
            	   foreach ($res as $r)
            	   {
            	       echo $r->domain." | Hits: ".$r->clicks."<br>";
            	   }
            	?>
            </td>
		</tr>
		</table>
    	
    	<br><HR><br>
    	
    	<h2>Statistics-Light-App - API</h2>
    	<form method="post" action="options.php">
  			<?php 
  			   settings_fields( 'itsbsde_sl_options_group' ); 
  			   $api_url = get_option('itsbsde_sl_option_api');
  			   $api_pass = get_option('itsbsde_sl_option_pass');
  		    ?>
  			DATA-URL (for example: ajkjbuwnczwelgfd) enables the Statistics-Light-App (<a href="<?php echo ITSBSDE_SL_URL; ?>" target="_blank">German/Deutsch</a> / <a href="<?php echo ITSBSDE_SL_URL_EN; ?>" target="_blank">English</a>) to download and visualize the statistics data on your mobile device:<br>
  			<?php echo $api_path."ajkjbuwnczwelgfd"; ?>
  			<br><br><em>If DATA-URL is empty, Statistics-API is deactivated!</em>
  			<br><br><em>Note: Also make sure that you have set up an SSL certificate for your blog or website to enable encrypted communication!</em>
  			
          	<table style="width: 100%;">
                  <tr valign="top">
                  <th scope="row"><label for="itsbsde_sl_option_api">DATA-URL:</label></th>
                  <td><input style="width: 100%;" type="text" id="itsbsde_sl_option_api" name="itsbsde_sl_option_api" value="<?php echo $api_url; ?>" /></td>
                  </tr>
                  <tr valign="top">
                  <th scope="row"><label for="itsbsde_sl_option_pass">Password:</label></th>
                  <td><input style="width: 100%;" type="password" id="itsbsde_sl_option_pass" name="itsbsde_sl_option_pass" value="<?php echo $api_pass; ?>" /></td>
                  </tr>
          	</table>
          	<?php  submit_button(); ?>
    	</form>
  		
  		<?php 
  		    if (strlen($api_url)>0)
  		    {
  		        $deeplink_api = $api_path.$api_url;
  		        echo "<h2>Statistics-API is active!</h2>";
  		        echo "<h3>Your DATA-URL:<br>".$deeplink_api."</h3>";
  		        echo "<em>Last Data-Update: ".get_option('itsbsde_sl_option_last_update')."</em>";
  		    }
  		?>
  		
    </div><!-- end div wrap -->
    <?php
}

function itsbsde_sl_admin() {
    add_menu_page(
        'Statistics-Light',
        'Statistics-Light',
        'manage_options',
        'statistics-light',
        'itsbsde_sl_admin_page',
        'dashicons-chart-pie', //plugin_dir_url(__FILE__) . 'images/pie.png',
        20
        );
}
add_action('admin_menu', 'itsbsde_sl_admin' );

function itsbsde_sl_update_field_option_api( $new_value, $old_value ) {
    $api_url = strtolower($new_value);
    
    $data_path = plugin_dir_path( __FILE__ )."tmp/";
    
    if (file_exists($data_path.$old_value))
        unlink($data_path.$old_value);
    
    $filter = array(" ", "/", "\\", ".", ",");
    foreach ($filter as $f)
    {
        $api_url = trim(str_replace($f, "", $api_url));
    }
    
    itsbsde_sl_update_stat($api_url);
    
    return $api_url;
}

function itsbsde_sl_update_stat($file)
{
    if (strlen($file)<=0)
        return;
    
    $data_path = plugin_dir_path( __FILE__ )."tmp/";
    file_put_contents($data_path.$file, itsbsde_sl_getStatsForApp());
    
    update_option(ITSBSDE_SL_prefix.'option_last_update', date("Y-m-d H:i:s", time()));
}

function itsbsde_sl_update_field_option_pass( $new_value, $old_value )
{
    $nl = "\r\n";
    $data_path = plugin_dir_path( __FILE__ )."tmp/";
    
    $new_value = trim($new_value);
    if (strlen($new_value)<=0)
        $new_value = md5(time());
    
    $htaccess = "AuthType Basic".$nl
                ."AuthName \"Access denied\"".$nl
                ."AuthUserFile ".$data_path.".htusers".$nl
                ."Require valid-user".$nl;
    file_put_contents($data_path.".htaccess", $htaccess);
    
    $htusers = "stat_api:".crypt($new_value).$nl;
    file_put_contents($data_path.".htusers", $htusers);
    
    return $new_value;
}

function itsbsde_sl_register_settings() {
    add_option( ITSBSDE_SL_prefix.'option_api', '');
    register_setting( ITSBSDE_SL_prefix.'options_group', ITSBSDE_SL_prefix.'option_api', ITSBSDE_SL_prefix.'_callback' );
    
    add_option( ITSBSDE_SL_prefix.'option_pass', '');
    register_setting( ITSBSDE_SL_prefix.'options_group', ITSBSDE_SL_prefix.'option_pass', ITSBSDE_SL_prefix.'_callback' );
    
    add_option( ITSBSDE_SL_prefix.'option_last_update', date("Y-m-d H:i:s", time()));
    
    add_filter( 'pre_update_option_itsbsde_sl_option_api', ITSBSDE_SL_prefix.'update_field_option_api', 10, 2 );
    add_filter( 'pre_update_option_itsbsde_sl_option_pass', ITSBSDE_SL_prefix.'update_field_option_pass', 10, 2 );
    
    $cur_version = get_option(ITSBSDE_SL_prefix.'option_version');
    if ($cur_version !== ITSBSDE_SL_VERSION)
    {
        add_option(ITSBSDE_SL_prefix.'option_version', ITSBSDE_SL_VERSION);
        itsbsde_sl_install();
    }
    update_option(ITSBSDE_SL_prefix.'option_version', ITSBSDE_SL_VERSION);
}
add_action( 'admin_init', 'itsbsde_sl_register_settings' );

function itsbsde_sl_is_bot() {
    return (
        isset($_SERVER['HTTP_USER_AGENT'])
        && preg_match('/bot|crawl|slurp|spider|mediapartners/i', $_SERVER['HTTP_USER_AGENT'])
        );
}

function itsbsde_sl_install()
{
    global $wpdb;
    
    $q = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}".ITSBSDE_SL_prefix."visitor"
                ." ("
                    ."Id bigint(20) NOT NULL AUTO_INCREMENT"
                    .", ip varchar(50) NOT NULL"
                    .", entry_url text NOT NULL"
                    .", datum date"
                    .", PRIMARY KEY (Id)"
                .") CHARSET=utf8";
    $wpdb->query($q);
    
    $q = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}".ITSBSDE_SL_prefix."visits"
                ." ("
                    ."Id bigint(20) NOT NULL AUTO_INCREMENT"
                    .", datum date"
                    .", hits int(11)"
                    .", PRIMARY KEY (Id)"
                    .", UNIQUE KEY unique_date (datum)"
                .") CHARSET=utf8";
    $wpdb->query($q);
    
    $q = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}".ITSBSDE_SL_prefix."pages"
                ." ("
                    ."Id bigint(20) NOT NULL AUTO_INCREMENT"
                    .", url varchar(255)"
                    .", page_type varchar(32)"
                    .", page_id bigint(20)"
                    .", datum date"
                    .", hits int(11)"
                    .", PRIMARY KEY (Id)"
                    .", KEY idx_date (datum)"
                    .", KEY idx_url (url)"
                    .", KEY idx_pageid (page_id)"
                .") CHARSET=utf8";
    $wpdb->query($q);
    
    $q = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}".ITSBSDE_SL_prefix."referer"
            ." ("
                ."Id bigint(20) NOT NULL AUTO_INCREMENT"
                .", referer varchar(255)"
                .", domain varchar(255)"
                .", datum date"
                .", hits int(11)"
                .", PRIMARY KEY (Id)"
                .", KEY idx_date (datum)"
                .", KEY idx_ref (referer)"
                .", KEY idx_domain (domain)"
            .") CHARSET=utf8";
    $wpdb->query($q);
    
    /*
     * cleanup
     */
    //delete own referers (fixed by v0.6)
    $myblog_url = get_home_url();
    $replace = array("http://", "https://", "www.");
    foreach ($replace as $r)
    {
        $myblog_url = str_replace($r, "", $myblog_url);
    }
    $wpdb->query("DELETE FROM ".$wpdb->prefix.ITSBSDE_SL_prefix."referer WHERE domain='".$myblog_url."'");
}
register_activation_hook( __FILE__, 'itsbsde_sl_install' );


function itsbsde_sl_deactivation()
{
    
}
register_deactivation_hook( __FILE__, 'itsbsde_sl_deactivation' );


function itsbsde_sl_uninstall()
{
    
}
register_uninstall_hook(__FILE__, 'itsbsde_sl_uninstall');


function itsbsde_sl_hide_stat_page_from_rss($content) {
    global $post;
    
    if ($post->post_title == ITSBSDE_SL_prefix)
    {
        $content = "";
    }
    return $content;
}
add_filter( 'the_content_feed', 'itsbsde_sl_hide_stat_page_from_rss' );

?>