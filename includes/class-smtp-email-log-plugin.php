<?php
/**
 * Main plugin orchestrator: owns the settings and boots the recorder and the admin side.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class SMTP_Email_Log_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'smtp_email_log_settings';

	/**
	 * Daily housekeeping event.
	 */
	const CRON_DAILY = 'smtp_email_log_daily';

	/**
	 * Single shared instance.
	 *
	 * @var SMTP_Email_Log_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings, read once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * The recorder, while logging is on.
	 *
	 * @var SMTP_Email_Log_Recorder|null
	 */
	private $recorder = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return SMTP_Email_Log_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		SMTP_Email_Log_Install::maybe_upgrade();

		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_settings' ) );
		add_action( self::CRON_DAILY, array( $this, 'daily' ) );

		if ( $this->is_enabled() ) {
			$this->recorder = new SMTP_Email_Log_Recorder( $this );
		}

		new SMTP_Email_Log_Alerts( $this );
		new SMTP_Email_Log_Privacy();

		if ( is_admin() ) {
			new SMTP_Email_Log_Admin( $this );
			new SMTP_Email_Log_Actions( $this );
		}

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'          => 1,

			// What to log: "all" emails, or only "failed" ones (sent ones are
			// still written first, so a crash mid-send can't lose them).
			'log_mode'         => 'all',

			// Message body: kept for "all" emails, only "failed" ones, or "none".
			'content'          => 'all',

			// Hide the secret part of password reset and login links.
			'hide_secrets'     => 1,

			// Copy a failed email's attachments, up to this many MB per email.
			'keep_attachments' => 1,
			'attachment_mb'    => 10,

			// Emails whose recipients or subject contain one of these lines aren't logged.
			'exclude'          => '',

			// Retention in days (0 = forever), and a cap on the number of rows.
			'retention_sent'   => 30,
			'retention_failed' => 90,
			'max_rows'         => 10000,

			// Alerts.
			'notice'           => 1,
			'badge'            => 1,
			'webhook_url'      => '',
			'webhook_cooldown' => 60,

			// SMTP conversation: saved for "failed" emails, "all" or "off".
			'transcript'       => 'failed',

			// PHPMailer's SMTP timeout in seconds (0 = leave it alone).
			'smtp_timeout'     => 0,

			'keep_data'        => 0,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the cached settings after they're saved.
	 *
	 * @return void
	 */
	public function flush_settings() {
		$this->settings = null;
	}

	/**
	 * Whether emails are being logged.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( defined( 'SMTP_EMAIL_LOG_DISABLED' ) && SMTP_EMAIL_LOG_DISABLED ) {
			return false;
		}

		return (bool) $this->get_setting( 'enabled' );
	}

	/**
	 * The recorder, or null while logging is off.
	 *
	 * @return SMTP_Email_Log_Recorder|null
	 */
	public function recorder() {
		return $this->recorder;
	}

	/**
	 * Daily housekeeping. Never throws: a failure here mustn't stop other cron jobs.
	 *
	 * @return void
	 */
	public function daily() {
		try {
			SMTP_Email_Log_Log::mark_stale();
			SMTP_Email_Log_Log::prune(
				(int) $this->get_setting( 'retention_sent' ),
				(int) $this->get_setting( 'retention_failed' ),
				(int) $this->get_setting( 'max_rows' )
			);
			SMTP_Email_Log_Attachments::sweep();
		} catch ( \Throwable $e ) {
			self::log( 'daily', $e );
		}
	}

	/**
	 * Log a caught error when WP_DEBUG is on. Every hook on the mail path
	 * catches its own errors and ends up here, so a bug in this plugin can
	 * never stop an email from being sent.
	 *
	 * @param string     $context Where it happened.
	 * @param \Throwable $error   The caught error.
	 * @return void
	 */
	public static function log( $context, $error ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging.
			sprintf(
				'[SMTP Email Log] %s: %s in %s:%d',
				$context,
				$error->getMessage(),
				$error->getFile(),
				$error->getLine()
			)
		);
	}
}
