<?php
/**
 * Everything the log's buttons do: bulk actions, resend and forward, delete,
 * empty the log, CSV export, attachment downloads, the test email and the
 * webhook test.
 *
 * Every handler checks a nonce and manage_options. Anything that changes
 * data is a POST. Results are passed back to the page as fixed codes and
 * row IDs, never as text from the URL.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request handlers.
 */
final class SMTP_Email_Log_Actions {

	/**
	 * admin-post.php and admin-ajax.php actions.
	 */
	const BULK      = 'smtp_email_log_bulk';
	const RESEND    = 'smtp_email_log_resend';
	const DELETE    = 'smtp_email_log_delete';
	const EMPTY_LOG = 'smtp_email_log_empty';
	const EXPORT    = 'smtp_email_log_export';
	const DOWNLOAD  = 'smtp_email_log_download';
	const TEST      = 'smtp_email_log_test';
	const WEBHOOK   = 'smtp_email_log_test_webhook';
	const QUICK     = 'smtp_email_log_quick_resend';

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

		add_action( 'admin_post_' . self::BULK, array( $this, 'bulk' ) );
		add_action( 'admin_post_' . self::RESEND, array( $this, 'resend' ) );
		add_action( 'admin_post_' . self::DELETE, array( $this, 'delete' ) );
		add_action( 'admin_post_' . self::EMPTY_LOG, array( $this, 'empty_log' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'export' ) );
		add_action( 'admin_post_' . self::DOWNLOAD, array( $this, 'download' ) );
		add_action( 'wp_ajax_' . self::TEST, array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_' . self::WEBHOOK, array( $this, 'ajax_webhook' ) );
		add_action( 'wp_ajax_' . self::QUICK, array( $this, 'ajax_resend' ) );
	}

	/**
	 * Stop unless the user may manage the log.
	 *
	 * @return void
	 */
	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smtp-email-log' ), 403 );
		}
	}

	/**
	 * Stop an AJAX request unless the user may manage the log. Each AJAX
	 * handler checks its nonce first.
	 *
	 * @return void
	 */
	private static function require_ajax_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'smtp-email-log' ) ), 403 );
		}
	}

	/**
	 * Back to the plugin page with a result code.
	 *
	 * @param array<string,scalar> $args Query args (fixed codes and IDs only).
	 * @return void
	 */
	private static function back( array $args ) {
		wp_safe_redirect( SMTP_Email_Log_Admin::url( $args ) );
		exit;
	}

	/**
	 * The log filters to return to, from the submitted form.
	 *
	 * @return array<string,scalar>
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called after the handlers' nonce checks.
		$out = array();
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( in_array( $status, array( 'sent', 'failed', 'unknown', 'sending' ), true ) ) {
			$out['status'] = $status;
		}
		$search = isset( $_POST['s'] ) && is_string( $_POST['s'] ) ? substr( trim( sanitize_text_field( wp_unslash( $_POST['s'] ) ) ), 0, 200 ) : '';
		if ( '' !== $search ) {
			$out['s'] = $search;
		}
		if ( isset( $_POST['paged'] ) && absint( $_POST['paged'] ) > 1 ) {
			$out['paged'] = absint( $_POST['paged'] );
		}
		// phpcs:enable

		return $out;
	}

	/**
	 * Bulk delete or resend.
	 *
	 * @return void
	 */
	public function bulk() {
		check_admin_referer( self::BULK );
		self::require_cap();

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['ids'] ) ) ) ) ) : array();
		$back   = self::filters();

		if ( ! $ids || ! in_array( $action, array( 'delete', 'resend' ), true ) ) {
			self::back( $back + array( 'smtpel_done' => 'nothing' ) );
		}

		if ( 'delete' === $action ) {
			self::back(
				$back + array(
					'smtpel_done' => 'deleted',
					'smtpel_n'    => SMTP_Email_Log_Log::delete( $ids ),
				)
			);
		}

		$sent   = 0;
		$failed = 0;
		foreach ( array_slice( $ids, 0, SMTP_Email_Log_Resend::MAX_BULK ) as $id ) {
			$result = SMTP_Email_Log_Resend::send( $id );
			if ( $result['ok'] ) {
				++$sent;
			} else {
				++$failed;
			}
		}

		self::back(
			$back + array(
				'smtpel_done'   => 'bulk_resent',
				'smtpel_n'      => $sent,
				'smtpel_failed' => $failed,
				'smtpel_over'   => max( 0, count( $ids ) - SMTP_Email_Log_Resend::MAX_BULK ),
			)
		);
	}

	/**
	 * Resend or forward one email, from its page.
	 *
	 * @return void
	 */
	public function resend() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is per row, checked next.

		check_admin_referer( self::RESEND . '_' . $id );
		self::require_cap();

		$to = '';
		if ( isset( $_POST['recipient'] ) && 'other' === $_POST['recipient'] ) {
			$to = isset( $_POST['forward_to'] ) ? sanitize_email( wp_unslash( $_POST['forward_to'] ) ) : '';
			if ( ! is_email( $to ) ) {
				self::back(
					array(
						'view'        => $id,
						'smtpel_done' => 'invalid',
					)
				);
			}
		}

		$result = SMTP_Email_Log_Resend::send( $id, $to, ! empty( $_POST['default_from'] ) );

		if ( in_array( $result['code'], array( 'missing', 'no_body', 'no_to' ), true ) ) {
			self::back(
				array(
					'view'        => $id,
					'smtpel_done' => $result['code'],
				)
			);
		}

		self::back(
			array(
				'view'        => $id,
				'smtpel_done' => $result['ok'] ? 'resent' : 'resend_failed',
				'smtpel_id'   => $result['id'],
			)
		);
	}

	/**
	 * AJAX: resend one email from the list.
	 *
	 * @return void
	 */
	public function ajax_resend() {
		check_ajax_referer( SMTP_Email_Log_Admin::NONCE, 'nonce' );
		self::require_ajax_cap();

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = SMTP_Email_Log_Resend::send( $id );
		$reason = SMTP_Email_Log_Resend::reason( $result['code'] );

		if ( '' !== $reason ) {
			wp_send_json_error( array( 'message' => $reason ) );
		}

		$data = array(
			'message' => $result['ok'] ? __( 'Resent', 'smtp-email-log' ) : __( 'Failed again', 'smtp-email-log' ),
			'url'     => $result['id'] ? SMTP_Email_Log_Admin::url( array( 'view' => $result['id'] ) ) : '',
		);

		if ( $result['ok'] ) {
			wp_send_json_success( $data );
		}

		wp_send_json_error( $data );
	}

	/**
	 * Delete one email, from its page.
	 *
	 * @return void
	 */
	public function delete() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is per row, checked next.

		check_admin_referer( self::DELETE . '_' . $id );
		self::require_cap();

		self::back(
			array(
				'smtpel_done' => 'deleted',
				'smtpel_n'    => SMTP_Email_Log_Log::delete( array( $id ) ),
			)
		);
	}

	/**
	 * Empty the whole log.
	 *
	 * @return void
	 */
	public function empty_log() {
		check_admin_referer( self::EMPTY_LOG );
		self::require_cap();

		SMTP_Email_Log_Log::delete_all();
		SMTP_Email_Log_Alerts::mark_seen();

		self::back( array( 'smtpel_done' => 'emptied' ) );
	}

	/**
	 * Download the log (with the current filters) as CSV.
	 *
	 * @return void
	 */
	public function export() {
		check_admin_referer( self::EXPORT );
		self::require_cap();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked above.
		$args = array(
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'search' => isset( $_GET['s'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['s'] ) ), 0, 200 ) : '',
		);
		// phpcs:enable

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not allowed on some hosts.
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="smtp-email-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the download.

		// A byte order mark, so Excel reads the file as UTF-8.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		self::csv_row(
			$out,
			array(
				__( 'ID', 'smtp-email-log' ),
				__( 'Date', 'smtp-email-log' ),
				__( 'Status', 'smtp-email-log' ),
				__( 'From', 'smtp-email-log' ),
				__( 'To', 'smtp-email-log' ),
				__( 'Cc', 'smtp-email-log' ),
				__( 'Bcc', 'smtp-email-log' ),
				__( 'Reply-To', 'smtp-email-log' ),
				__( 'Subject', 'smtp-email-log' ),
				__( 'Error', 'smtp-email-log' ),
				__( 'Sent by', 'smtp-email-log' ),
				__( 'Message', 'smtp-email-log' ),
			)
		);

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		SMTP_Email_Log_Log::each(
			$args,
			function ( $row ) use ( $out, $format ) {
				self::csv_row(
					$out,
					array(
						(int) $row['id'],
						wp_date( $format, (int) strtotime( $row['created_at'] . ' UTC' ) ),
						SMTP_Email_Log_Admin::status_label( (string) $row['status'] ),
						(string) $row['from_addr'],
						(string) $row['to_addr'],
						(string) $row['cc_addr'],
						(string) $row['bcc_addr'],
						(string) $row['reply_to'],
						(string) $row['subject'],
						(string) $row['error'],
						SMTP_Email_Log_Admin::source_label( (string) $row['source'] ),
						SMTP_Email_Log_Log::clip( SMTP_Email_Log_Log::plain_text( (string) $row['message'], (string) $row['content_type'] ), 32000 ),
					)
				);
			}
		);

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * One CSV row. Cells that a spreadsheet would run as a formula are
	 * prefixed with a quote: email content comes from strangers.
	 *
	 * @param resource $out    Output stream.
	 * @param array    $fields Cells.
	 * @return void
	 */
	private static function csv_row( $out, array $fields ) {
		foreach ( $fields as $i => $field ) {
			$field = (string) $field;
			if ( '' !== $field && in_array( $field[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$field = "'" . $field;
			}
			$fields[ $i ] = $field;
		}

		fputcsv( $out, $fields, ',', '"', '\\' );
	}

	/**
	 * Download a stored attachment copy.
	 *
	 * @return void
	 */
	public function download() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is per row, checked next.
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$index = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		// phpcs:enable

		check_admin_referer( self::DOWNLOAD . '_' . $id );
		self::require_cap();

		$row  = SMTP_Email_Log_Log::get( $id, 'id, attachments' );
		$item = $row && isset( $row['attachments'][ $index ] ) && is_array( $row['attachments'][ $index ] ) ? $row['attachments'][ $index ] : array();
		$path = ! empty( $item['file'] ) ? SMTP_Email_Log_Attachments::path( $item['file'] ) : '';

		if ( '' === $path ) {
			wp_die( esc_html__( 'That attachment isn\'t stored anymore.', 'smtp-email-log' ), 404 );
		}

		$name = SMTP_Email_Log_Attachments::clean_name( isset( $item['name'] ) ? $item['name'] : '' );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; sandbox' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming the download.
		exit;
	}

	/**
	 * AJAX: send a test email and report exactly what happened.
	 *
	 * @return void
	 */
	public function ajax_test() {
		check_ajax_referer( SMTP_Email_Log_Admin::NONCE, 'nonce' );
		self::require_ajax_cap();

		$to   = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$html = isset( $_POST['format'] ) && 'html' === sanitize_key( wp_unslash( $_POST['format'] ) );

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email address.', 'smtp-email-log' ) ) );
		}

		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site     = '' !== $site ? $site : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$when     = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$recorder = $this->plugin->recorder();
		$before   = $recorder ? $recorder->last_id() : 0;
		$error    = '';
		$catch    = function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Test email from %s', 'smtp-email-log' ), $site );

		if ( $html ) {
			$message = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f6f7f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">'
				. '<div style="max-width:520px;margin:0 auto;padding:28px;background:#ffffff;border:1px solid #dcdcde;border-radius:8px;">'
				. '<h1 style="margin:0 0 12px;font-size:20px;">' . esc_html__( 'Your site can send email', 'smtp-email-log' ) . '</h1>'
				/* translators: %s: site name. */
				. '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;">' . esc_html( sprintf( __( 'This is a test from SMTP Email Log on %s. If you can read it, email from your site is getting through.', 'smtp-email-log' ), $site ) ) . '</p>'
				/* translators: %s: date and time. */
				. '<p style="margin:0;font-size:13px;color:#646970;">' . esc_html( sprintf( __( 'Sent %s', 'smtp-email-log' ), $when ) ) . '</p>'
				. '</div></body></html>';
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		} else {
			/* translators: %s: site name. */
			$message = sprintf( __( 'This is a test from SMTP Email Log on %s. If you can read it, email from your site is getting through.', 'smtp-email-log' ), $site )
				/* translators: %s: date and time. */
				. "\n\n" . sprintf( __( 'Sent %s', 'smtp-email-log' ), $when );
			$headers = array();
		}

		if ( $recorder ) {
			$recorder->expect( array( 'kind' => 'test' ) );
		}

		add_action( 'wp_mail_failed', $catch );
		$started = microtime( true );
		$ok      = wp_mail( $to, $subject, $message, $headers );
		$seconds = microtime( true ) - $started;
		remove_action( 'wp_mail_failed', $catch );

		if ( $recorder ) {
			$recorder->expect( array() );
			$recorder->settle();
		}

		$id  = $recorder && $recorder->last_id() !== $before ? $recorder->last_id() : 0;
		$row = $id ? SMTP_Email_Log_Log::get( $id ) : null;

		$data = array(
			'ok'         => (bool) $ok,
			'seconds'    => round( $seconds, 1 ),
			'error'      => $error,
			'transport'  => $row ? SMTP_Email_Log_Admin::transport_label( $row['transport'] ) : '',
			'reply'      => $row ? (string) $row['server_reply'] : '',
			'transcript' => $row ? (string) $row['transcript'] : '',
			'url'        => $id ? SMTP_Email_Log_Admin::url( array( 'view' => $id ) ) : '',
			'logged'     => (bool) $id,
		);

		if ( $ok ) {
			/* translators: 1: email address, 2: seconds. */
			$data['message'] = sprintf( __( 'Sent to %1$s in %2$s seconds.', 'smtp-email-log' ), $to, number_format_i18n( $seconds, 1 ) );
			$data['hint']    = __( 'The mail server accepted it. Check that inbox, and its spam folder, to be sure it arrived.', 'smtp-email-log' );
		} else {
			$hint            = SMTP_Email_Log_Errors::explain( $error, $row ? $row : array() );
			$data['message'] = __( 'The test email couldn\'t be sent.', 'smtp-email-log' );
			$data['title']   = $hint['title'];
			$data['hint']    = $hint['text'];
		}

		if ( ! $id && $this->plugin->is_enabled() ) {
			$data['note'] = __( 'This email didn\'t reach the log. Another plugin may be sending email without going through WordPress\'s mail hooks; see "How this site sends email".', 'smtp-email-log' );
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: send a test alert to the webhook in the form (saved or not).
	 *
	 * @return void
	 */
	public function ajax_webhook() {
		check_ajax_referer( SMTP_Email_Log_Admin::NONCE, 'nonce' );
		self::require_ajax_cap();

		$url = isset( $_POST['url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes a URL for use; valid_url() then checks it.

		if ( ! SMTP_Email_Log_Alerts::valid_url( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter an https:// webhook address on a public host.', 'smtp-email-log' ) ) );
		}

		$response = SMTP_Email_Log_Alerts::post( $url, SMTP_Email_Log_Alerts::payload( 0, null, true ) );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Couldn\'t reach the webhook: %s', 'smtp-email-log' ), $response->get_error_message() ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code. */
			wp_send_json_error( array( 'message' => sprintf( __( 'The webhook answered with HTTP %d. Check the address.', 'smtp-email-log' ), $code ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Sent. Check the channel for the test alert.', 'smtp-email-log' ) ) );
	}
}
