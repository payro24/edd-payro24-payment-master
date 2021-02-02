<?php
/**
 * Plugin Name: payro24 for Easy Digital Downloads (EDD)
 * Author: payro24
 * Description: <a href="https://payro24.ir">payro24</a> secure payment gateway for Easy Digital Downloads (EDD)
 * Version: 2.1.2
 * Author URI: https://payro24.ir
 * Author Email: info@payro24.ir
 *
 * Text Domain: payro24-for-edd
 * Domain Path: languages
 */

if (!defined('ABSPATH')) exit;

/**
 * Load plugin textdomain.
 */
function payro24_for_edd_load_textdomain() {
	load_plugin_textdomain( 'payro24-for-edd', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'payro24_for_edd_load_textdomain' );

include_once( plugin_dir_path( __FILE__ ) . 'includes/edd-payro24-gateway.php' );
