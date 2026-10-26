<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data,
 * unless "Keep the log and settings" is ticked. On a multisite this covers
 * every site.
 *
 * @package SMTP_Email_Log
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the log table, attachment copies and settings for the current site.
 *
 * @return void
 */
function smtp_email_log_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'smtp_email_log_settings' );
	if ( is_array( $settings ) && ! empty( $settings['keep_data'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own table on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smtp_email_log" );

	// Attachment copies: only our own files, then the folder.
	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
		$dir = trailingslashit( $uploads['basedir'] ) . 'smtp-email-log/';

		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '*' ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) && preg_match( '/^(?:[0-9]+-[a-f0-9]{32}|index\.php|index\.html)$/', basename( $file ) ) ) {
					wp_delete_file( $file );
				}
			}

			if ( is_file( $dir . '.htaccess' ) ) {
				wp_delete_file( $dir . '.htaccess' );
			}

			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only removed once empty.
		}
	}

	foreach ( array(
		'smtp_email_log_settings',
		'smtp_email_log_db_version',
		'smtp_email_log_state',
		'smtp_email_log_counts',
		'smtp_email_log_transport',
		'smtp_email_log_attachment_bytes',
	) as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'smtp_email_log_daily' );
	wp_clear_scheduled_hook( 'smtp_email_log_alert' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $smtp_email_log_site ) {
		switch_to_blog( $smtp_email_log_site );
		smtp_email_log_uninstall_site();
		restore_current_blog();
	}
} else {
	smtp_email_log_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-smtp-email-log' );
