<?php
/**
 * The log table: created on activation, upgraded when the schema changes.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema management.
 */
final class SMTP_Email_Log_Install {

	/**
	 * Bump when the table changes; dbDelta then runs on the next request.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'smtp_email_log_db_version';

	/**
	 * Create or update the table when the stored version is behind. Cheap:
	 * one autoloaded option read on most requests.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update the table.
	 *
	 * Text columns allow NULL, so a row can never be refused for a column it
	 * didn't set. Addresses and the subject are `text`, the message `longtext`
	 * and the SMTP conversation `mediumtext`; the log class trims every value
	 * to fit before it's written.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = SMTP_Email_Log_Log::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				status varchar(10) NOT NULL DEFAULT 'sending',
				to_addr text NULL,
				cc_addr text NULL,
				bcc_addr text NULL,
				reply_to text NULL,
				from_addr varchar(255) NOT NULL DEFAULT '',
				subject text NULL,
				message longtext NULL,
				content_type varchar(60) NOT NULL DEFAULT '',
				headers text NULL,
				attachments text NULL,
				error text NULL,
				transport text NULL,
				server_reply varchar(255) NOT NULL DEFAULT '',
				transcript mediumtext NULL,
				source varchar(191) NOT NULL DEFAULT '',
				context text NULL,
				resent_from bigint(20) unsigned NOT NULL DEFAULT 0,
				meta text NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY status_created (status,created_at)
			) {$collate};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION );
	}
}
