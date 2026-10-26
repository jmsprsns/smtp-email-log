<?php
/**
 * Plugin Name:       SMTP Email Log
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Logs every email your site sends, sent or failed, so you can debug SMTP errors and recover form messages and leads that never arrived.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smtp-email-log
 * Update URI:        https://github.com/jmsprsns/smtp-email-log
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$smtp_email_log_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'SMTP_EMAIL_LOG_VERSION', $smtp_email_log_headers['version'] );
define( 'SMTP_EMAIL_LOG_REPO', $smtp_email_log_headers['repo'] );
define( 'SMTP_EMAIL_LOG_FILE', __FILE__ );
define( 'SMTP_EMAIL_LOG_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMTP_EMAIL_LOG_URL', plugin_dir_url( __FILE__ ) );

unset( $smtp_email_log_headers );

require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-icons.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-updater.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-menu.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-feedback.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-install.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-log.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-attachments.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-transcript.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-recorder.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-errors.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-alerts.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-resend.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-privacy.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-actions.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-admin.php';
require_once SMTP_EMAIL_LOG_DIR . 'includes/class-smtp-email-log-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
SMTP_Email_Log_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * Priority 0, so the log is listening before other plugins start their own
 * plugins_loaded work, some of which sends email.
 *
 * @return SMTP_Email_Log_Plugin
 */
function smtp_email_log_plugin() {
	return SMTP_Email_Log_Plugin::instance();
}
add_action( 'plugins_loaded', 'smtp_email_log_plugin', 0 );

/**
 * Create the log table on activation.
 *
 * Network activation and new sites on a multisite are covered too: the
 * table is created on each site's first request (see
 * SMTP_Email_Log_Install::maybe_upgrade()).
 *
 * @return void
 */
function smtp_email_log_activate() {
	SMTP_Email_Log_Install::install();
}
register_activation_hook( SMTP_EMAIL_LOG_FILE, 'smtp_email_log_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * The log and settings stay put (uninstall.php removes them).
 *
 * @return void
 */
function smtp_email_log_deactivate() {
	wp_clear_scheduled_hook( SMTP_Email_Log_Plugin::CRON_DAILY );
	wp_clear_scheduled_hook( SMTP_Email_Log_Alerts::CRON_ALERT );
}
register_deactivation_hook( SMTP_EMAIL_LOG_FILE, 'smtp_email_log_deactivate' );
