<?php
/**
 * Removes everything the plugin stored: options, transients, post meta, cron events.
 *
 * @package IndexPages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Cleans up the current site.
 *
 * @return void
 */
function indexpages_uninstall_site() {
	global $wpdb;

	$options = array(
		'indexpages_settings',
		'indexpages_api_key',
		'indexpages_queue',
		'indexpages_state',
		'indexpages_log',
		'indexpages_lock',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'indexpages_send_queue' );

	$meta_keys = array(
		'_indexpages_status',
		'_indexpages_last_sent',
		'_indexpages_last_url',
		'_indexpages_order',
		'_indexpages_message',
	);
	foreach ( $meta_keys as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	delete_transient( 'indexpages_account' );
	delete_transient( 'indexpages_orders' );

	// Per-user flash messages: indexpages_flash_<user id>.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_indexpages_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_indexpages_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$indexpages_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $indexpages_sites as $indexpages_site_id ) {
		switch_to_blog( $indexpages_site_id );
		indexpages_uninstall_site();
		restore_current_blog();
	}
} else {
	indexpages_uninstall_site();
}
