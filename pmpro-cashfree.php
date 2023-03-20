<?php
/*
Plugin Name: Paid Memberships Pro - Cashfree Gateway
Plugin URI: http://www.wpstriker.com/
Description: Adds Cashfree as a gateway option for Paid Memberships Pro.
Version: 1.0.0
Author: WPStriker
Author URI: http://www.wpstriker.com
*/

define( 'PMPRO_CASHFREE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMPRO_CASHFREE_URL', plugin_dir_url( __FILE__ ) );

// load payment gateway class after all plugins are loaded to make sure PMPro stuff is available
function pmpro_cashfree_plugins_loaded() {

	// make sure PMPro is loaded
	if ( ! defined( 'PMPRO_DIR' ) ) {
		return;
	}

	require_once( PMPRO_CASHFREE_DIR . '/classes/class.pmprogateway_cashfree.php' );
}
add_action( 'plugins_loaded', 'pmpro_cashfree_plugins_loaded' );

// Register activation hook.
register_activation_hook( __FILE__, 'pmpro_cashfree_admin_notice_activation_hook' );

/**
 * Runs only when the plugin is activated.
 *
 * @since 0.1.0
 */
function pmpro_cashfree_admin_notice_activation_hook() {
	// Create transient data.
	set_transient( 'pmpro-cashfree-admin-notice', true, 5 );
}

/**
 * Admin Notice on Activation.
 *
 * @since 0.1.0
 */
function pmpro_cashfree_admin_notice() {
	// Check transient, if available display notice.
	if ( get_transient( 'pmpro-cashfree-admin-notice' ) ) { ?>
		<div class="updated notice is-dismissible">
			<p><?php printf( __( 'Thank you for activating. <a href="%s">Visit the payment settings page</a> to configure the Cashfree Gateway.' ), esc_url( get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) ) ); ?></p>
		</div>
		<?php
		// Delete transient, only display this notice once.
		delete_transient( 'pmpro-cashfree-admin-notice' );
	}
}
add_action( 'admin_notices', 'pmpro_cashfree_admin_notice' );

/**
 * Function to add links to the plugin action links
 *
 * @param array $links Array of links to be shown in plugin action links.
 */
function pmpro_cashfree_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		$new_links = array(
			'<a href="' . get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) . '">' . __( 'Configure Cashfree' ) . '</a>',
		);
	}
	return array_merge( $new_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pmpro_cashfree_plugin_action_links' );
