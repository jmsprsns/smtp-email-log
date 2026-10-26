<?php
/**
 * Records every email WordPress sends, from the moment wp_mail() is called.
 *
 * The flow for one email:
 *
 * 1. `wp_mail` filter: the email is written to the log as "sending", with
 *    its recipients, subject, message and attachments, before anything is
 *    sent. If the send then crashes or hangs until the request is killed,
 *    the email (and the form submission in it) is still in the log.
 * 2. `phpmailer_init`: the final From and recipients, and how it's being
 *    sent (SMTP server, port and encryption, or PHP mail()). For SMTP, the
 *    conversation with the server starts being recorded.
 * 3. `wp_mail_succeeded` or `wp_mail_failed`: the result and error.
 *
 * Emails are kept on a stack, so an email sent while another is being sent
 * (say, an alert sent from a wp_mail_failed handler) gets its own row.
 * Anything WordPress never reports back on is settled at shutdown: as failed
 * if PHP crashed, as sent if PHPMailer confirmed the server accepted it,
 * otherwise as unknown.
 *
 * Nothing here may break sending: every hook catches its own errors and
 * returns what it was given.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mail hooks.
 */
final class SMTP_Email_Log_Recorder {

	/**
	 * Result hooks run first, so the result is written before other plugins'
	 * handlers (which might exit, or send another email).
	 */
	const RESULT_PRIORITY = -1000;

	/**
	 * Plugin instance.
	 *
	 * @var SMTP_Email_Log_Plugin
	 */
	private $plugin;

	/**
	 * Emails in progress, innermost last.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $stack = array();

	/**
	 * SMTP conversation recorder.
	 *
	 * @var SMTP_Email_Log_Transcript
	 */
	private $transcript;

	/**
	 * The PHPMailer instance changed for the current send, and the values
	 * it had before.
	 *
	 * @var object|null
	 */
	private $mailer = null;

	/**
	 * Property => original value.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * The PHPMailer SMTP object whose Timelimit was changed, and its old value.
	 *
	 * @var array{0:object,1:int}|null
	 */
	private $smtp_limit = null;

	/**
	 * Details for the next email (a test or resend from this plugin).
	 *
	 * @var array<string,mixed>
	 */
	private $next = array();

	/**
	 * Row ID of the last email logged.
	 *
	 * @var int
	 */
	private $last_id = 0;

	/**
	 * Constructor.
	 *
	 * @param SMTP_Email_Log_Plugin $plugin Plugin instance.
	 */
	public function __construct( SMTP_Email_Log_Plugin $plugin ) {
		$this->plugin     = $plugin;
		$this->transcript = new SMTP_Email_Log_Transcript();

		add_filter( 'wp_mail', array( $this, 'capture' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( $this, 'short_circuit' ), PHP_INT_MAX, 2 );
		add_action( 'phpmailer_init', array( $this, 'phpmailer_init' ), PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', array( $this, 'succeeded' ), self::RESULT_PRIORITY );
		add_action( 'wp_mail_failed', array( $this, 'failed' ), self::RESULT_PRIORITY );

		// WordPress's fatal error handler ends with wp_die(), before the
		// shutdown hook runs; this filter is the last thing it calls first.
		add_filter( 'wp_php_error_message', array( $this, 'on_fatal' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'shutdown' ), 0 );
	}

	/**
	 * Mark the next email as coming from this plugin (a test or a resend).
	 *
	 * @param array<string,mixed> $details kind ("test" or "resend") and resent_from.
	 * @return void
	 */
	public function expect( array $details ) {
		$this->next = $details;
	}

	/**
	 * Row ID of the last email logged in this request.
	 *
	 * @return int
	 */
	public function last_id() {
		return $this->last_id;
	}

	/**
	 * Settle the email this plugin just sent itself (a test or a resend),
	 * so its row is final before the page reads it back. Only needed before
	 * WordPress 5.9, which has no success hook; a no-op after.
	 *
	 * @return void
	 */
	public function settle() {
		try {
			$this->settle_all( self::last_fatal() );
		} catch ( \Throwable $e ) {
			SMTP_Email_Log_Plugin::log( 'settle', $e );
		}
	}

	// Hooks --------------------------------------------------------------------

	/**
	 * `wp_mail` filter: log the email before it's sent.
	 *
	 * @param mixed $atts wp_mail() arguments.
	 * @return mixed Unchanged.
	 */
	public function capture( $atts ) {
		$entry = self::blank_entry();

		try {
			$this->settle_orphans();
			$entry = $this->open( is_array( $atts ) ? $atts : array() );
		} catch ( \Throwable $e ) {
			SMTP_Email_Log_Plugin::log( 'capture', $e );
		}

		$this->stack[] = $entry;

		return $atts;
	}

	/**
	 * `pre_wp_mail` filter: another plugin took over delivery.
	 *
	 * @param mixed $result Null to send normally, otherwise the result to return.
	 * @param mixed $atts   wp_mail() arguments.
	 * @return mixed Unchanged.
	 */
	public function short_circuit( $result, $atts = array() ) {
		if ( null === $result ) {
			return $result;
		}

		try {
			$entry = array_pop( $this->stack );
			if ( is_array( $entry ) ) {
				$entry['meta']['short_circuit'] = 1;
				if ( false === $result ) {
					$this->finish( $entry, SMTP_Email_Log_Log::FAILED, 'Another plugin stopped this email before WordPress sent it (pre_wp_mail).' );
				} else {
					$this->finish( $entry, SMTP_Email_Log_Log::SENT );
				}
			}
		} catch ( \Throwable $e ) {
			SMTP_Email_Log_Plugin::log( 'short_circuit', $e );
		}

		return $result;
	}

	/**
	 * `phpmailer_init`: how the email is being sent, the final addresses,
	 * and the start of the SMTP conversation.
	 *
	 * @param object $phpmailer PHPMailer instance.
	 * @return void
	 */
	public function phpmailer_init( $phpmailer ) {
		try {
			if ( ! is_object( $phpmailer ) ) {
				return;
			}

			// Some plugins replace wp_mail() and skip the wp_mail filter, but
			// still set PHPMailer up through this hook.
			if ( ! $this->stack ) {
				$this->stack[] = $this->open( self::atts_from_mailer( $phpmailer ), true );
			}

			$top = count( $this->stack ) - 1;

			$this->stack[ $top ]['init'] = true;
			$this->restore_mailer();

			if ( ! $this->stack[ $top ]['id'] ) {
				return;
			}

			$is_smtp   = 'smtp' === strtolower( (string) $phpmailer->Mailer );
			$transport = array( 'mailer' => substr( (string) $phpmailer->Mailer, 0, 40 ) );

			if ( $is_smtp ) {
				$transport['host']   = substr( (string) $phpmailer->Host, 0, 191 );
				$transport['port']   = (int) $phpmailer->Port;
				$transport['secure'] = substr( (string) $phpmailer->SMTPSecure, 0, 10 );
				$transport['auto']   = (bool) $phpmailer->SMTPAutoTLS;
				$transport['auth']   = (bool) $phpmailer->SMTPAuth;
				$transport['user']   = $phpmailer->SMTPAuth ? self::mask( (string) $phpmailer->Username ) : '';
			}

			$from = self::address( (string) $phpmailer->From, (string) $phpmailer->FromName );

			SMTP_Email_Log_Log::update(
				$this->stack[ $top ]['id'],
				array(
					'transport'    => $transport,
					'from_addr'    => $from,
					'to_addr'      => self::list_from_mailer( $phpmailer, 'getToAddresses' ),
					'cc_addr'      => self::list_from_mailer( $phpmailer, 'getCcAddresses' ),
					'bcc_addr'     => self::list_from_mailer( $phpmailer, 'getBccAddresses' ),
					'reply_to'     => self::list_from_mailer( $phpmailer, 'getReplyToAddresses' ),
					'content_type' => strtolower( (string) $phpmailer->ContentType ),
				)
			);

			self::remember_transport( $transport, $from );

			$this->prepare_mailer( $phpmailer, $is_smtp );
		} catch ( \Throwable $e ) {
			SMTP_Email_Log_Plugin::log( 'phpmailer_init', $e );
		}
	}

	/**
	 * PHPMailer's action_function: called once per recipient with whether the
	 * server accepted it, plus the server's queue ID for SMTP.
	 *
	 * @param mixed $is_sent Accepted.
	 * @param mixed $to      Recipient(s), as [[address, name]].
	 * @param mixed $cc      Unused.
	 * @param mixed $bcc     Unused.
	 * @param mixed $subject Unused.
	 * @param mixed $body    Unused.
	 * @param mixed $from    Unused.
	 * @param mixed $extra   smtp_transaction_id for SMTP.
	 * @return void
	 */
	public function mailer_callback( $is_sent, $to = array(), $cc = array(), $bcc = array(), $subject = '', $body = '', $from = '', $extra = array() ) {
		try {
			$top = count( $this->stack ) - 1;
			if ( $top < 0 ) {
				return;
			}

			$entry = &$this->stack[ $top ];

			if ( $is_sent ) {
				$entry['accepted'] = true;
			} else {
				$entry['refused'] = true;
				if ( is_array( $to ) && isset( $to[0][0] ) && is_string( $to[0][0] ) && count( $entry['rejected'] ) < 20 ) {
					$entry['rejected'][] = $to[0][0];
				}
			}

			if ( is_array( $extra ) && ! empty( $extra['smtp_transaction_id'] ) && is_string( $extra['smtp_transaction_id'] ) ) {
				$entry['server_reply'] = substr( $extra['smtp_transaction_id'], 0, 200 );
			}
		} catch ( \Throwable $e ) {
			SMTP_Email_Log_Plugin::log( 'mailer_callback', $e );
		}
	}

	/**
	 * `wp_mail_succeeded` (WordPress 5.9+).
	 *
	 * @param mixed $mail_data Email details.
	 * @return void
	 */
	public function succeeded( $mail_data = array() ) {
		try {
			$entry = array_pop( $this->stack );

			// Fired without the wp_mail filter: a plugin that replaces wp_mail().
			if ( ! is_array( $entry ) && is_array( $mail_data ) && ! empty( $mail_data['to'] ) ) {
				$entry = $this->open( $mail_data, true );
			}

			if ( is_array( $entry ) ) {
				$this->finish( $entry, SMTP_Email_Log_Log::SENT );
			}
		} catch ( \Throwable $e ) {
			SMTP_Email_Log_Plugin::log( 'succeeded', $e );
		}
	}

	/**
	 * `wp_mail_failed`.
	 *
	 * @param mixed $error WP_Error with the PHPMailer message and the email's details.
	 * @return void
	 */
	public function failed( $error ) {
		try {
			$message = is_wp_error( $error ) ? (string) $error->get_error_message() : '';
			$data    = is_wp_error( $error ) ? $error->get_error_data() : array();
			$data    = is_array( $data ) ? $data : array();
			$entry   = array_pop( $this->stack );

			if ( ! is_array( $entry ) && ! empty( $data['to'] ) ) {
				$entry = $this->open( $data, true );
			}

			if ( is_array( $entry ) ) {
				if ( isset( $data['attachments'] ) && ( is_array( $data['attachments'] ) || is_string( $data['attachments'] ) ) ) {
					$entry['attachments'] = $data['attachments'];
				}

				$this->finish( $entry, SMTP_Email_Log_Log::FAILED, '' !== $message ? $message : 'WordPress reported that the email failed, without a reason.' );
			}
		} catch ( \Throwable $e ) {
			SMTP_Email_Log_Plugin::log( 'failed', $e );
		}
	}

	/**
	 * `wp_php_error_message`: PHP crashed. Settle emails still in progress
	 * as failed before WordPress shows its error page and exits.
	 *
	 * @param mixed $message Error page message.
	 * @param mixed $error   error_get_last() details.
	 * @return mixed Unchanged.
	 */
	public function on_fatal( $message, $error = array() ) {
		try {
			$this->settle_all( is_array( $error ) ? self::fatal_text( $error ) : 'PHP fatal error.' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $message;
	}

	/**
	 * `shutdown`: settle anything WordPress never reported back on.
	 *
	 * @return void
	 */
	public function shutdown() {
		try {
			if ( $this->stack ) {
				$this->settle_all( self::last_fatal() );
			}
		} catch ( \Throwable $e ) {
			SMTP_Email_Log_Plugin::log( 'shutdown', $e );
		}
	}

	// Recording ------------------------------------------------------------------

	/**
	 * Write a new row for an email about to be sent.
	 *
	 * @param array<string,mixed> $atts wp_mail() arguments.
	 * @param bool                $late Captured after the wp_mail filter (by a later hook).
	 * @return array<string,mixed> Stack entry.
	 */
	private function open( array $atts, $late = false ) {
		$next       = $this->next;
		$this->next = array();

		$to          = self::addresses( isset( $atts['to'] ) ? $atts['to'] : '' );
		$subject     = isset( $atts['subject'] ) && is_scalar( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$message     = isset( $atts['message'] ) && is_scalar( $atts['message'] ) ? (string) $atts['message'] : '';
		$headers     = self::header_lines( isset( $atts['headers'] ) ? $atts['headers'] : '' );
		$parsed      = self::parse_headers( $headers );
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();
		$kind        = isset( $next['kind'] ) && in_array( $next['kind'], array( 'test', 'resend' ), true ) ? $next['kind'] : '';
		$entry       = self::blank_entry();

		$entry['attachments'] = $attachments;

		if ( '' === $kind && $this->excluded( $to, $parsed, $subject, $atts ) ) {
			return $entry;
		}

		$settings = $this->plugin->get_settings();
		$meta     = array();

		if ( '' !== $kind ) {
			$meta['kind'] = $kind;
		}
		if ( $late ) {
			$meta['late'] = 1;
		}

		if ( 'none' === $settings['content'] ) {
			$stored          = '';
			$meta['no_body'] = 1;
		} else {
			$stored = $message;

			if ( ! empty( $settings['hide_secrets'] ) ) {
				$hidden = self::hide_secrets( $stored );
				if ( $hidden !== $stored ) {
					$stored           = $hidden;
					$meta['redacted'] = 1;
				}
			}

			if ( strlen( $stored ) > SMTP_Email_Log_Log::MAX_MESSAGE ) {
				$meta['truncated'] = strlen( $stored );
			}
		}

		$origin = self::origin();
		$row    = array(
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'status'       => SMTP_Email_Log_Log::SENDING,
			'to_addr'      => implode( ', ', $to ),
			'cc_addr'      => implode( ', ', $parsed['cc'] ),
			'bcc_addr'     => implode( ', ', $parsed['bcc'] ),
			'reply_to'     => implode( ', ', $parsed['reply_to'] ),
			'from_addr'    => $parsed['from'],
			'subject'      => $subject,
			'message'      => $stored,
			'content_type' => $parsed['content_type'],
			'headers'      => implode( "\n", $headers ),
			'attachments'  => SMTP_Email_Log_Attachments::describe( $attachments ),
			'source'       => $origin['source'],
			'context'      => self::context( $origin ),
			'resent_from'  => isset( $next['resent_from'] ) ? absint( $next['resent_from'] ) : 0,
			'meta'         => $meta,
		);

		/**
		 * Change what's stored for an email before it's written, or return
		 * false to leave it out of the log.
		 *
		 * @param array|false $row  Column => value.
		 * @param array       $atts wp_mail() arguments.
		 */
		$row = apply_filters( 'smtp_email_log_row', $row, $atts );

		if ( ! is_array( $row ) ) {
			return $entry;
		}

		$entry['id']   = SMTP_Email_Log_Log::insert( $row );
		$entry['meta'] = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : $meta;

		$this->last_id = $entry['id'];

		return $entry;
	}

	/**
	 * Write the result of an email.
	 *
	 * @param array<string,mixed> $entry  Stack entry.
	 * @param string              $status SENT, FAILED or UNKNOWN.
	 * @param string              $error  Error message for a failure.
	 * @return void
	 */
	private function finish( array $entry, $status, $error = '' ) {
		$transcript = $this->transcript->stop();
		$this->restore_mailer();

		$id = (int) $entry['id'];
		if ( ! $id ) {
			return;
		}

		$settings = $this->plugin->get_settings();
		$meta     = $entry['meta'];
		$policy   = (string) $settings['transcript'];

		if ( ! empty( $entry['rejected'] ) ) {
			$meta['rejected'] = $entry['rejected'];
		}
		if ( SMTP_Email_Log_Log::FAILED === $status && ! $entry['init'] ) {
			$meta['before_send'] = 1;
		}

		$update = array(
			'status'       => $status,
			'server_reply' => $entry['server_reply'],
			'meta'         => $meta,
		);

		if ( '' !== $transcript && ( 'all' === $policy || ( 'failed' === $policy && SMTP_Email_Log_Log::SENT !== $status ) ) ) {
			$update['transcript'] = $transcript;
		}

		if ( SMTP_Email_Log_Log::FAILED === $status ) {
			$update['error'] = $error;

			if ( ! empty( $settings['keep_attachments'] ) && ! empty( $entry['attachments'] ) ) {
				$update['attachments'] = SMTP_Email_Log_Attachments::store( $id, $entry['attachments'], max( 1, (int) $settings['attachment_mb'] ) * MB_IN_BYTES );
			}
		} elseif ( SMTP_Email_Log_Log::SENT === $status ) {
			// Only failed emails are kept (tests and resends always are).
			if ( 'failed' === $settings['log_mode'] && empty( $meta['kind'] ) ) {
				SMTP_Email_Log_Log::delete( array( $id ) );
				SMTP_Email_Log_Log::count_result( $status );
				return;
			}

			if ( 'failed' === $settings['content'] ) {
				$update['message']       = '';
				$meta['body_dropped']    = 1;
				$update['meta']          = $meta;
			}
		}

		SMTP_Email_Log_Log::update( $id, $update );
		SMTP_Email_Log_Log::count_result( $status );

		if ( SMTP_Email_Log_Log::FAILED === $status ) {
			SMTP_Email_Log_Alerts::record_failure( $id, $error );

			/**
			 * Fires after a failed email is recorded.
			 *
			 * @param int    $id    Log row ID.
			 * @param string $error Error message.
			 */
			do_action( 'smtp_email_log_failed', $id, $error );
		}
	}

	/**
	 * Settle an email WordPress never reported back on.
	 *
	 * @param array<string,mixed> $entry Stack entry.
	 * @param string|null         $fatal PHP fatal error text, if PHP crashed.
	 * @return void
	 */
	private function finish_unreported( array $entry, $fatal ) {
		if ( null !== $fatal ) {
			$entry['meta']['fatal'] = 1;
			$this->finish( $entry, SMTP_Email_Log_Log::FAILED, $fatal );
		} elseif ( ! empty( $entry['accepted'] ) ) {
			// PHPMailer saw the server accept it (WordPress before 5.9 has no success hook).
			$entry['meta']['confirmed'] = 'phpmailer';
			$this->finish( $entry, SMTP_Email_Log_Log::SENT );
		} elseif ( ! empty( $entry['refused'] ) ) {
			$this->finish( $entry, SMTP_Email_Log_Log::FAILED, 'The mail server refused every recipient.' );
		} else {
			$entry['meta']['no_result'] = 1;
			$this->finish( $entry, SMTP_Email_Log_Log::UNKNOWN );
		}
	}

	/**
	 * Settle every email still in progress.
	 *
	 * @param string|null $fatal PHP fatal error text, if PHP crashed.
	 * @return void
	 */
	private function settle_all( $fatal ) {
		while ( $this->stack ) {
			$this->finish_unreported( array_pop( $this->stack ), $fatal );
		}
	}

	/**
	 * A new email is starting with older ones still open, and it isn't being
	 * sent from inside one of them (from a result hook, say). The older ones
	 * will never be reported back on: settle them now.
	 *
	 * @return void
	 */
	private function settle_orphans() {
		global $wp_current_filter;

		if ( ! $this->stack ) {
			return;
		}

		$running = array_count_values( array_filter( is_array( $wp_current_filter ) ? $wp_current_filter : array(), 'is_string' ) );

		if ( ( isset( $running['wp_mail'] ) && $running['wp_mail'] > 1 ) || isset( $running['wp_mail_succeeded'] ) || isset( $running['wp_mail_failed'] ) || isset( $running['phpmailer_init'] ) || isset( $running['pre_wp_mail'] ) ) {
			return;
		}

		$this->settle_all( self::last_fatal() );
	}

	/**
	 * Whether an email matches the "don't log" list or the skip filter.
	 *
	 * @param string[]            $to      Recipients.
	 * @param array<string,mixed> $parsed  Parsed headers.
	 * @param string              $subject Subject.
	 * @param array<string,mixed> $atts    wp_mail() arguments.
	 * @return bool
	 */
	private function excluded( array $to, array $parsed, $subject, array $atts ) {
		$haystack = self::lower( implode( ' ', array_merge( $to, $parsed['cc'], $parsed['bcc'] ) ) . "\n" . $subject );

		foreach ( self::exclusions( (string) $this->plugin->get_setting( 'exclude' ) ) as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}

		/**
		 * Leave an email out of the log.
		 *
		 * @param bool  $skip Whether to skip it.
		 * @param array $atts wp_mail() arguments.
		 */
		return (bool) apply_filters( 'smtp_email_log_skip', false, $atts );
	}

	// PHPMailer --------------------------------------------------------------------

	/**
	 * Set PHPMailer up for this send: the per-recipient callback, the SMTP
	 * timeout, and SMTP debug output into the transcript. Every property
	 * changed is put back when the send ends.
	 *
	 * @param object $phpmailer PHPMailer instance.
	 * @param bool   $is_smtp   Sending over SMTP.
	 * @return void
	 */
	private function prepare_mailer( $phpmailer, $is_smtp ) {
		$this->mailer = $phpmailer;
		$this->saved  = array();
		$callback     = array( $this, 'mailer_callback' );

		if ( empty( $phpmailer->action_function ) || $callback === $phpmailer->action_function ) {
			$this->saved['action_function'] = $phpmailer->action_function;
			$phpmailer->action_function     = $callback;
		}

		if ( ! $is_smtp ) {
			return;
		}

		$timeout = (int) $this->plugin->get_setting( 'smtp_timeout' );
		if ( $timeout > 0 ) {
			// Timeout only covers opening the connection. Each wait for a
			// reply is capped by the SMTP object's Timelimit (300 seconds by
			// default), which is what keeps a form hanging on a server that
			// connects but never answers.
			$this->saved['Timeout'] = $phpmailer->Timeout;
			$phpmailer->Timeout     = $timeout;

			$smtp = method_exists( $phpmailer, 'getSMTPInstance' ) ? $phpmailer->getSMTPInstance() : null;
			if ( is_object( $smtp ) && property_exists( $smtp, 'Timelimit' ) ) {
				$this->smtp_limit = array( $smtp, $smtp->Timelimit );
				$smtp->Timelimit  = $timeout;
			}
		}

		if ( 'off' === $this->plugin->get_setting( 'transcript' ) ) {
			return;
		}

		$level  = (int) $phpmailer->SMTPDebug;
		$theirs = $phpmailer->Debugoutput;

		if ( 0 === $level ) {
			// Level 3: commands, replies and connection status. Level 4 would show passwords.
			$this->saved['SMTPDebug']   = $phpmailer->SMTPDebug;
			$this->saved['Debugoutput'] = $theirs;
			$phpmailer->SMTPDebug       = 3;
			$phpmailer->Debugoutput     = array( $this->transcript, 'collect' );
		} elseif ( $level < 4 && is_callable( $theirs ) ) {
			// Someone else is already debugging: listen in, and pass everything on.
			$collector                  = $this->transcript;
			$this->saved['Debugoutput'] = $theirs;
			$phpmailer->Debugoutput     = function ( $str, $debug_level ) use ( $collector, $theirs ) {
				$collector->collect( $str, $debug_level );
				call_user_func( $theirs, $str, $debug_level );
			};
		} else {
			return;
		}

		$this->transcript->start();
	}

	/**
	 * Put back every PHPMailer property changed for the last send. WordPress
	 * reuses one PHPMailer for every email in a request.
	 *
	 * @return void
	 */
	private function restore_mailer() {
		if ( is_object( $this->mailer ) ) {
			foreach ( $this->saved as $property => $value ) {
				$this->mailer->$property = $value;
			}
		}

		if ( $this->smtp_limit ) {
			$this->smtp_limit[0]->Timelimit = $this->smtp_limit[1];
		}

		$this->mailer     = null;
		$this->saved      = array();
		$this->smtp_limit = null;
	}

	// Helpers --------------------------------------------------------------------

	/**
	 * A fresh stack entry.
	 *
	 * @return array<string,mixed>
	 */
	private static function blank_entry() {
		return array(
			'id'           => 0,
			'meta'         => array(),
			'attachments'  => array(),
			'init'         => false,
			'accepted'     => false,
			'refused'      => false,
			'rejected'     => array(),
			'server_reply' => '',
		);
	}

	/**
	 * Recipients as wp_mail() reads them: an array, or a comma-separated string.
	 *
	 * @param mixed $to Recipients.
	 * @return string[]
	 */
	private static function addresses( $to ) {
		if ( is_string( $to ) ) {
			$to = explode( ',', $to );
		}

		$out = array();
		foreach ( is_array( $to ) ? $to : array() as $address ) {
			if ( is_scalar( $address ) ) {
				$address = trim( str_replace( array( "\r", "\n" ), '', (string) $address ) );
				if ( '' !== $address ) {
					$out[] = $address;
				}
			}
		}

		return array_slice( $out, 0, 200 );
	}

	/**
	 * Header lines as wp_mail() reads them: an array of "Name: value"
	 * strings, or one newline-separated string.
	 *
	 * @param mixed $headers Headers.
	 * @return string[]
	 */
	private static function header_lines( $headers ) {
		if ( is_string( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		$out = array();
		foreach ( is_array( $headers ) ? $headers : array() as $line ) {
			if ( is_scalar( $line ) ) {
				$line = trim( (string) $line );
				if ( '' !== $line ) {
					$out[] = $line;
				}
			}
		}

		return array_slice( $out, 0, 100 );
	}

	/**
	 * Pull Cc, Bcc, Reply-To, From and Content-Type out of the header lines.
	 *
	 * @param string[] $lines Header lines.
	 * @return array{cc:string[],bcc:string[],reply_to:string[],from:string,content_type:string}
	 */
	private static function parse_headers( array $lines ) {
		$out = array(
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'from'         => '',
			'content_type' => '',
		);

		foreach ( $lines as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', $line, 2 );
			$name                 = strtolower( trim( $name ) );
			$value                = trim( $value );

			if ( 'cc' === $name || 'bcc' === $name ) {
				$out[ $name ] = array_merge( $out[ $name ], self::addresses( $value ) );
			} elseif ( 'reply-to' === $name ) {
				$out['reply_to'] = array_merge( $out['reply_to'], self::addresses( $value ) );
			} elseif ( 'from' === $name ) {
				$out['from'] = $value;
			} elseif ( 'content-type' === $name ) {
				$out['content_type'] = strtolower( trim( strtok( $value, ';' ) ) );
			}
		}

		return $out;
	}

	/**
	 * wp_mail()-style arguments from a PHPMailer that was set up some other way.
	 *
	 * @param object $phpmailer PHPMailer instance.
	 * @return array<string,mixed>
	 */
	private static function atts_from_mailer( $phpmailer ) {
		$headers = array();
		foreach ( array( 'Cc' => 'getCcAddresses', 'Bcc' => 'getBccAddresses', 'Reply-To' => 'getReplyToAddresses' ) as $name => $method ) {
			$list = self::list_from_mailer( $phpmailer, $method );
			if ( '' !== $list ) {
				$headers[] = $name . ': ' . $list;
			}
		}

		$attachments = array();
		if ( method_exists( $phpmailer, 'getAttachments' ) ) {
			foreach ( (array) $phpmailer->getAttachments() as $attachment ) {
				if ( is_array( $attachment ) && isset( $attachment[0] ) && is_string( $attachment[0] ) && empty( $attachment[5] ) ) {
					$attachments[] = $attachment[0];
				}
			}
		}

		return array(
			'to'          => self::list_from_mailer( $phpmailer, 'getToAddresses' ),
			'subject'     => (string) $phpmailer->Subject,
			'message'     => (string) $phpmailer->Body,
			'headers'     => $headers,
			'attachments' => $attachments,
		);
	}

	/**
	 * A PHPMailer address list as "Name <address>, address".
	 *
	 * @param object $phpmailer PHPMailer instance.
	 * @param string $method    getToAddresses, getCcAddresses, getBccAddresses or getReplyToAddresses.
	 * @return string
	 */
	private static function list_from_mailer( $phpmailer, $method ) {
		if ( ! method_exists( $phpmailer, $method ) ) {
			return '';
		}

		$out = array();
		foreach ( (array) $phpmailer->$method() as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0] ) ) {
				$out[] = self::address( (string) $pair[0], isset( $pair[1] ) ? (string) $pair[1] : '' );
			}
		}

		return implode( ', ', array_slice( $out, 0, 200 ) );
	}

	/**
	 * "Name <address>", or just the address.
	 *
	 * @param string $email Address.
	 * @param string $name  Name.
	 * @return string
	 */
	private static function address( $email, $name ) {
		$email = trim( str_replace( array( "\r", "\n", ',' ), '', $email ) );

		// No commas in names: stored lists are split on them for resending.
		$name = trim( str_replace( array( "\r", "\n", '<', '>', ',', '"' ), array( '', '', '', '', ' ', '' ), $name ) );

		return '' !== $name && '' !== $email ? $name . ' <' . $email . '>' : $email;
	}

	/**
	 * A username shortened so it can't be read off the log in full: enough
	 * to tell which account is in use.
	 *
	 * @param string $user Username.
	 * @return string
	 */
	public static function mask( $user ) {
		$user = trim( $user );

		if ( '' === $user ) {
			return '';
		}

		if ( false !== strpos( $user, '@' ) ) {
			list( $local, $domain ) = explode( '@', $user, 2 );
			return substr( $local, 0, min( 3, max( 1, strlen( $local ) - 2 ) ) ) . '…@' . $domain;
		}

		return strlen( $user ) > 6 ? substr( $user, 0, 4 ) . '…' : substr( $user, 0, 1 ) . '…';
	}

	/**
	 * Hide the secret part of password reset, account activation and magic
	 * login links: the value of any key or token in a link's query string.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	public static function hide_secrets( $text ) {
		$pattern = '/((?:[?&]|&amp;|%3F|%26)(?:key|token|otp|activation|activate|[a-z0-9_-]*?(?:_key|-key|_token|-token))(?:=|%3D))([^&\s"\'<>#\\\\]{6,})/i';
		$hidden  = preg_replace( $pattern, '$1[hidden]', $text );

		if ( is_string( $hidden ) ) {
			return $hidden;
		}

		// The regex gave up on something huge. Try line by line, and fail
		// closed: a line that still can't be checked isn't stored.
		$lines = preg_split( '/(\r\n|\r|\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( is_array( $lines ) ? $lines : array( $text ) as $i => $line ) {
			$checked     = preg_replace( $pattern, '$1[hidden]', $line );
			$lines[ $i ] = is_string( $checked ) ? $checked : '[line removed: it couldn\'t be checked for login links]';
		}

		return implode( '', $lines );
	}

	/**
	 * The "don't log" list: one lowercase entry per line.
	 *
	 * @param string $text Setting.
	 * @return string[]
	 */
	public static function exclusions( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = self::lower( trim( $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return array_slice( array_unique( $out ), 0, 50 );
	}

	/**
	 * Lowercase, multibyte-aware where possible.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Where wp_mail() was called from: the plugin, theme or WordPress
	 * function, and the file and line.
	 *
	 * @return array{source:string,file:string,line:int,function:string}
	 */
	private static function origin() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Finds which plugin sent the email.
		$caller = null;
		$skip   = array(
			wp_normalize_path( SMTP_EMAIL_LOG_DIR ),
			wp_normalize_path( ABSPATH . WPINC . '/class-wp-hook.php' ),
			wp_normalize_path( ABSPATH . WPINC . '/plugin.php' ),
			wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' ),
			wp_normalize_path( ABSPATH . WPINC . '/PHPMailer/' ),
		);

		foreach ( $frames as $i => $frame ) {
			if ( isset( $frame['function'] ) && 'wp_mail' === $frame['function'] && ! isset( $frame['class'] ) ) {
				$caller = array( $frame, isset( $frames[ $i + 1 ] ) ? $frames[ $i + 1 ] : array() );
				break;
			}
		}

		// No wp_mail() call (a plugin that replaced it): the first frame outside WordPress's hook code.
		if ( null === $caller ) {
			foreach ( $frames as $i => $frame ) {
				$file = isset( $frame['file'] ) ? wp_normalize_path( $frame['file'] ) : '';
				if ( '' === $file ) {
					continue;
				}
				foreach ( $skip as $prefix ) {
					if ( 0 === strpos( $file, $prefix ) ) {
						continue 2;
					}
				}
				$caller = array( $frame, isset( $frames[ $i + 1 ] ) ? $frames[ $i + 1 ] : array() );
				break;
			}
		}

		$file     = $caller && isset( $caller[0]['file'] ) ? wp_normalize_path( $caller[0]['file'] ) : '';
		$line     = $caller && isset( $caller[0]['line'] ) ? (int) $caller[0]['line'] : 0;
		$function = '';
		if ( $caller && isset( $caller[1]['function'] ) ) {
			$function = ( isset( $caller[1]['class'] ) ? $caller[1]['class'] . '::' : '' ) . $caller[1]['function'];
		}

		return array(
			'source'   => self::classify( $file, $function ),
			'file'     => self::relative( $file ),
			'line'     => $line,
			'function' => substr( $function, 0, 120 ),
		);
	}

	/**
	 * Name what sent an email from the file that called wp_mail().
	 *
	 * @param string $file     Normalized path.
	 * @param string $function Calling function.
	 * @return string plugin:<folder>, mu-plugin:<name>, theme:<folder>, core:<function>, wp-cli or other.
	 */
	private static function classify( $file, $function ) {
		$roots = array(
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme'     => wp_normalize_path( get_theme_root() ) . '/',
		);

		foreach ( $roots as $type => $root ) {
			if ( '' !== $file && 0 === strpos( $file, $root ) ) {
				$part = strtok( substr( $file, strlen( $root ) ), '/' );
				return $type . ':' . substr( (string) $part, 0, 120 );
			}
		}

		$core = wp_normalize_path( ABSPATH );
		if ( '' !== $file && ( 0 === strpos( $file, $core . WPINC . '/' ) || 0 === strpos( $file, $core . 'wp-admin/' ) || 0 === strpos( $file, $core . 'wp-login.php' ) ) ) {
			return 'core:' . substr( preg_replace( '/[^A-Za-z0-9_:]/', '', $function ), 0, 120 );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}

		return 'other';
	}

	/**
	 * A path relative to the WordPress folder (or wp-content's parent).
	 *
	 * @param string $file Normalized path.
	 * @return string
	 */
	private static function relative( $file ) {
		foreach ( array( wp_normalize_path( ABSPATH ), wp_normalize_path( dirname( WP_CONTENT_DIR ) ) . '/' ) as $root ) {
			if ( '' !== $file && 0 === strpos( $file, $root ) ) {
				return substr( $file, strlen( $root ), 300 );
			}
		}

		// Somewhere else (eval'd code, a phar): just the file name.
		return substr( wp_basename( $file ), 0, 120 );
	}

	/**
	 * What kind of request sent the email, and where.
	 *
	 * @param array<string,mixed> $origin From {@see origin()}.
	 * @return array<string,mixed>
	 */
	private static function context( array $origin ) {
		global $wp_current_filter;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$type = 'cli';
		} elseif ( wp_doing_cron() ) {
			$type = 'cron';
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$type = 'rest';
		} elseif ( wp_doing_ajax() ) {
			$type = 'ajax';
		} elseif ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			$type = 'xmlrpc';
		} elseif ( is_admin() ) {
			$type = 'admin';
		} else {
			$type = 'front';
		}

		$out = array( 'type' => $type );

		if ( 'cli' !== $type && isset( $_SERVER['REQUEST_URI'] ) ) {
			// The path only: query strings can carry tokens.
			$path        = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned on the next line; escaped on output.
			$out['path'] = substr( (string) preg_replace( '/[^\x21-\x7E]/', '', $path ), 0, 300 );
		}

		$method = 'cli' !== $type && isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			$out['method'] = $method;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which AJAX action sent the email.
		if ( 'ajax' === $type && isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) {
			$out['action'] = substr( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 0, 100 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( 'cron' === $type && isset( $wp_current_filter[0] ) && is_string( $wp_current_filter[0] ) ) {
			$out['hook'] = substr( (string) preg_replace( '/[^A-Za-z0-9_\-\/.:]/', '', $wp_current_filter[0] ), 0, 120 );
		}

		if ( '' !== $origin['file'] ) {
			$out['file'] = $origin['file'];
			$out['line'] = $origin['line'];
		}
		if ( '' !== $origin['function'] ) {
			$out['function'] = $origin['function'];
		}

		return $out;
	}

	/**
	 * Remember how the last email went out, for the Test Email tab. Written
	 * only when it changes (or hourly), not on every email.
	 *
	 * @param array<string,mixed> $transport Transport details.
	 * @param string              $from      From address.
	 * @return void
	 */
	private static function remember_transport( array $transport, $from ) {
		$saved = get_option( 'smtp_email_log_transport', array() );
		$saved = is_array( $saved ) ? $saved : array();
		$same  = isset( $saved['transport'], $saved['from'], $saved['at'] ) && $saved['transport'] === $transport && $saved['from'] === $from && time() - (int) $saved['at'] < HOUR_IN_SECONDS;

		if ( ! $same ) {
			update_option(
				'smtp_email_log_transport',
				array(
					'transport' => $transport,
					'from'      => $from,
					'at'        => time(),
				),
				false
			);
		}
	}

	/**
	 * The last PHP error, if it was fatal.
	 *
	 * @return string|null
	 */
	private static function last_fatal() {
		$error = error_get_last();

		if ( ! is_array( $error ) || ! isset( $error['type'] ) || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return null;
		}

		return self::fatal_text( $error );
	}

	/**
	 * A short description of a PHP fatal error.
	 *
	 * @param array<string,mixed> $error error_get_last() details.
	 * @return string
	 */
	private static function fatal_text( array $error ) {
		$message = isset( $error['message'] ) ? strtok( (string) $error['message'], "\n" ) : '';
		$file    = isset( $error['file'] ) ? self::relative( wp_normalize_path( (string) $error['file'] ) ) : '';
		$line    = isset( $error['line'] ) ? (int) $error['line'] : 0;

		return substr( 'PHP fatal error while sending: ' . $message . ( '' !== $file ? ' (' . $file . ':' . $line . ')' : '' ), 0, 1000 );
	}
}
