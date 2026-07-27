<?php
/**
 * Removes plugin data on deletion — but only when the site owner asked for it.
 *
 * @package GoogleReviews
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$gbrw_settings = get_option( 'gbrw_settings', array() );

if ( ! is_array( $gbrw_settings ) || empty( $gbrw_settings['delete_data_on_uninstall'] ) ) {
	// Default path: leave imported reviews and the Google connection in place so
	// reinstalling restores everything.
	return;
}

global $wpdb;

$gbrw_tables = array(
	$wpdb->prefix . 'gbrw_sync_log',
	$wpdb->prefix . 'gbrw_widget_reviews',
	$wpdb->prefix . 'gbrw_widgets',
	$wpdb->prefix . 'gbrw_reviews',
	$wpdb->prefix . 'gbrw_locations',
);

foreach ( $gbrw_tables as $gbrw_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $gbrw_table ) . '`' );
}

$gbrw_options = array(
	'gbrw_settings',
	'gbrw_google_connection',
	'gbrw_license',
	'gbrw_db_version',
	'gbrw_installed_at',
	'gbrw_sync_offset',
	'gbrw_log_suffix',
);

foreach ( $gbrw_options as $gbrw_option ) {
	delete_option( $gbrw_option );
}

// Remove the log directory, including any rotated files.
$gbrw_uploads = wp_upload_dir( null, false );

if ( empty( $gbrw_uploads['error'] ) && ! empty( $gbrw_uploads['basedir'] ) ) {
	$gbrw_log_dir = trailingslashit( $gbrw_uploads['basedir'] ) . 'gbrw-logs';

	require_once ABSPATH . 'wp-admin/includes/file.php';

	global $wp_filesystem;

	if ( WP_Filesystem() && $wp_filesystem instanceof WP_Filesystem_Base ) {
		$wp_filesystem->delete( $gbrw_log_dir, true );
	}
}

// Any queued background work belongs to a plugin that no longer exists.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'gbrw' );
}
