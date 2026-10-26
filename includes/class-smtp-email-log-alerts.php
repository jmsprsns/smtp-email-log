<?php
/**
 * Telling someone that email is failing: a count beside the menu item, a
 * notice on the Dashboard, and an optional webhook (Slack, Discord, Teams,
 * Zapier and so on).
 *
 * A webhook rather than an email alert, because when the mail server is
 * the problem, an alert sent by email would fail too.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Failure alerts.
 */
final class SMTP_Email_Log_Alerts {

	/**
	 * Option holding the unseen count, the latest failure and the webhook state.
	 * Autoloaded: the menu count reads it on every admin page.
	 */
	const STATE_OPTION = 'smtp_email_log_state';

	/**
	 * Single event that sends the webhook.
	 */
	const CRON_ALERT = 'smtp_email_log_alert';

	/**
	 * admin-post.php action (and nonce action) for dismissing the notice.
	 */
	const DISMISS = 'smtp_email_log_dismiss';

	/**
	 * Plugin instance.
	 *
	 * @var SMTP_Email_Log_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param SMTP_Email_Log_Plugin $plugin Plugin instance.
	 */
	public function __construct( SMTP_Email_Log_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( self::CRON_ALERT, array( $this, 'send_webhook' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'badge_top_menu' ), 1000 );
			add_action( 'admin_notices', array( $this, 'dashboard_notice' ) );
			add_action( 'admin_post_' . self::DISMISS, array( $this, 'dismiss' ) );
		}
	}

	/**
	 * The stored state, with defaults.
	 *
	 * @return array{unseen:int,last_id:int,last_error:string,last_at:int,pending:int,pending_id:int,webhook_at:int}
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'unseen'     => 0,
				'last_id'    => 0,
				'last_error' => '',
				'last_at'    => 0,
				'pending'    => 0,
				'pending_id' => 0,
				'webhook_at' => 0,
			)
		);
	}

	/**
	 * Save the state.
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	private static function save( array $state ) {
		update_option( self::STATE_OPTION, $state, true );
	}

	/**
	 * Count a failure, and queue the webhook if there is one.
	 *
	 * @param int    $id    Log row.
	 * @param string $error Error message.
	 * @return void
	 */
	public static function record_failure( $id, $error ) {
		$state    = self::state();
		$settings = SMTP_Email_Log_Plugin::instance()->get_settings();

		$state['unseen']     = (int) $state['unseen'] + 1;
		$state['last_id']    = absint( $id );
		$state['last_error'] = SMTP_Email_Log_Log::clip( SMTP_Email_Log_Log::utf8( (string) $error ), 300 );
		$state['last_at']    = time();

		if ( '' !== (string) $settings['webhook_url'] ) {
			$state['pending']    = (int) $state['pending'] + 1;
			$state['pending_id'] = absint( $id );

			// One alert per cooldown period; failures in between are added up.
			if ( ! wp_next_scheduled( self::CRON_ALERT ) ) {
				$cooldown = max( 5, (int) $settings['webhook_cooldown'] ) * MINUTE_IN_SECONDS;
				wp_schedule_single_event( max( time(), (int) $state['webhook_at'] + $cooldown ), self::CRON_ALERT );
			}
		}

		self::save( $state );
	}

	/**
	 * Failures since an administrator last opened the log.
	 *
	 * @return int
	 */
	public static function unseen() {
		$state = self::state();

		return max( 0, (int) $state['unseen'] );
	}

	/**
	 * The log has been looked at.
	 *
	 * @return void
	 */
	public static function mark_seen() {
		$state = self::state();

		if ( (int) $state['unseen'] > 0 ) {
			$state['unseen'] = 0;
			self::save( $state );
		}
	}

	/**
	 * Count bubble for a menu item, like the one beside Plugins.
	 *
	 * @param int $count Unseen failures.
	 * @return string
	 */
	public static function badge( $count ) {
		return sprintf(
			'<span class="awaiting-mod count-%1$d smtpel-badge"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			(int) $count,
			esc_html( number_format_i18n( $count ) ),
			/* translators: %s: number of emails. */
			esc_html( sprintf( _n( '%s failed email', '%s failed emails', $count, 'smtp-email-log' ), number_format_i18n( $count ) ) )
		);
	}

	/**
	 * Show the unseen count on the top-level Content Powered menu too, so
	 * it's visible while that menu is closed.
	 *
	 * @return void
	 */
	public function badge_top_menu() {
		global $menu;

		if ( ! $this->plugin->get_setting( 'badge' ) || ! current_user_can( 'manage_options' ) || ! is_array( $menu ) ) {
			return;
		}

		$count = self::unseen();
		if ( $count < 1 ) {
			return;
		}

		foreach ( $menu as $position => $item ) {
			if ( isset( $item[0], $item[2] ) && SMTP_Email_Log_Menu::SLUG === $item[2] && false === strpos( (string) $item[0], 'smtpel-badge' ) ) {
				$menu[ $position ][0] .= ' ' . self::badge( $count ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Adding a count bubble, as core does.
			}
		}
	}

	/**
	 * Dashboard notice while there are unseen failures.
	 *
	 * @return void
	 */
	public function dashboard_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'dashboard' !== $screen->id || ! $this->plugin->get_setting( 'notice' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = self::state();
		$count = (int) $state['unseen'];
		if ( $count < 1 ) {
			return;
		}

		$view    = SMTP_Email_Log_Admin::url( array( 'status' => 'failed' ) );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS ), self::DISMISS );
		?>
		<div class="notice notice-error smtpel-notice">
			<p>
				<strong><?php esc_html_e( 'SMTP Email Log:', 'smtp-email-log' ); ?></strong>
				<?php
				/* translators: %s: number of emails. */
				echo esc_html( sprintf( _n( '%s email failed to send since you last checked.', '%s emails failed to send since you last checked.', $count, 'smtp-email-log' ), number_format_i18n( $count ) ) );
				?>
				<?php if ( '' !== $state['last_error'] ) : ?>
					<?php
					/* translators: %s: error message. */
					echo esc_html( sprintf( __( 'Latest error: %s', 'smtp-email-log' ), $state['last_error'] ) );
					?>
				<?php endif; ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $view ); ?>"><?php esc_html_e( 'View failed emails', 'smtp-email-log' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'smtp-email-log' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * admin-post.php: dismiss the notice (and the menu count).
	 *
	 * @return void
	 */
	public function dismiss() {
		check_admin_referer( self::DISMISS );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smtp-email-log' ), 403 );
		}

		self::mark_seen();

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * WP-Cron: send the webhook for the failures counted since the last one.
	 *
	 * @return void
	 */
	public function send_webhook() {
		$state = self::state();
		$url   = (string) $this->plugin->get_setting( 'webhook_url' );

		if ( '' === $url || (int) $state['pending'] < 1 ) {
			return;
		}

		$count = (int) $state['pending'];
		$row   = SMTP_Email_Log_Log::get( (int) $state['pending_id'], 'id, created_at, to_addr, subject, error' );

		// Saved before the request, so a slow or failing webhook is never sent twice.
		$state['pending']    = 0;
		$state['pending_id'] = 0;
		$state['webhook_at'] = time();
		self::save( $state );

		self::post( $url, self::payload( $count, $row ) );
	}

	/**
	 * The alert.
	 *
	 * @param int        $count Failures.
	 * @param array|null $row   Latest failure.
	 * @param bool       $test  A test alert.
	 * @return array<string,mixed>
	 */
	public static function payload( $count, $row, $test = false ) {
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$link = SMTP_Email_Log_Admin::url( array( 'status' => 'failed' ) );

		if ( $test ) {
			/* translators: %s: site domain. */
			$text = sprintf( __( 'Test alert from SMTP Email Log on %s. Failure alerts will arrive here.', 'smtp-email-log' ), $site );
		} else {
			/* translators: 1: site domain, 2: number of emails. */
			$text = sprintf( _n( '%1$s: %2$s email failed to send.', '%1$s: %2$s emails failed to send.', $count, 'smtp-email-log' ), $site, number_format_i18n( $count ) );

			if ( is_array( $row ) ) {
				/* translators: 1: email subject, 2: recipients, 3: error message. */
				$text .= ' ' . sprintf( __( 'Latest: "%1$s" to %2$s. %3$s', 'smtp-email-log' ), self::short( $row['subject'], 120 ), self::short( $row['to_addr'], 120 ), self::short( $row['error'], 300 ) );
			}
		}

		$payload = array(
			'text'    => $text . ' ' . $link,
			'event'   => $test ? 'smtp_email_log.test' : 'smtp_email_log.failed',
			'site'    => home_url( '/' ),
			'failed'  => $test ? 0 : (int) $count,
			'log_url' => $link,
		);

		if ( ! $test && is_array( $row ) ) {
			$payload['latest'] = array(
				'id'      => (int) $row['id'],
				'date'    => gmdate( 'c', (int) strtotime( $row['created_at'] . ' UTC' ) ),
				'to'      => (string) $row['to_addr'],
				'subject' => (string) $row['subject'],
				'error'   => (string) $row['error'],
			);
		}

		return $payload;
	}

	/**
	 * POST an alert to a webhook. Chat services get the message in the
	 * field they read; anything else gets the full JSON.
	 *
	 * @param string              $url     Webhook URL (https, validated on save).
	 * @param array<string,mixed> $payload From {@see payload()}.
	 * @return array|WP_Error Response.
	 */
	public static function post( $url, array $payload ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( preg_match( '/(^|\.)(discord\.com|discordapp\.com)$/', $host ) ) {
			$body = array( 'content' => self::short( $payload['text'], 1900 ) );
		} elseif ( preg_match( '/(^|\.)(hooks\.slack\.com|chat\.googleapis\.com|webhook\.office\.com|office\.com)$/', $host ) ) {
			$body = array( 'text' => $payload['text'] );
		} else {
			$body = $payload;
		}

		/**
		 * Change the JSON sent to the webhook.
		 *
		 * @param array  $body    JSON body.
		 * @param array  $payload Every detail of the alert.
		 * @param string $url     Webhook URL.
		 */
		$body = apply_filters( 'smtp_email_log_webhook_payload', $body, $payload, $url );

		return wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
			)
		);
	}

	/**
	 * Whether a URL is acceptable as a webhook: https, and a public host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function valid_url( $url ) {
		return '' !== $url && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && false !== wp_http_validate_url( $url );
	}

	/**
	 * Shorten text for an alert.
	 *
	 * @param mixed $text  Text.
	 * @param int   $chars Most characters.
	 * @return string
	 */
	private static function short( $text, $chars ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, $chars, '…', 'UTF-8' ) : substr( $text, 0, $chars );
	}
}
