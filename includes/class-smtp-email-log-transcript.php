<?php
/**
 * The SMTP conversation: PHPMailer's debug output for one send, cleaned up
 * for storing.
 *
 * Passwords and tokens are never kept. PHPMailer already hides the replies
 * to AUTH LOGIN and AUTH PLAIN prompts below its lowest debug level, but not
 * an XOAUTH2 token, so every client line after a 334 prompt and every AUTH
 * payload is hidden here as well, whatever PHPMailer did. The message itself
 * (everything between DATA and the server's reply) is left out: it's in the
 * log entry already, and it would double the size of the row.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * SMTP transcript collector.
 */
final class SMTP_Email_Log_Transcript {

	/**
	 * Most lines kept.
	 */
	const MAX_LINES = 600;

	/**
	 * Whether a send is being recorded.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Lines so far.
	 *
	 * @var string[]
	 */
	private $lines = array();

	/**
	 * Bytes so far.
	 *
	 * @var int
	 */
	private $bytes = 0;

	/**
	 * Whether lines were dropped for length.
	 *
	 * @var bool
	 */
	private $cut = false;

	/**
	 * When recording started.
	 *
	 * @var float
	 */
	private $started = 0.0;

	/**
	 * The client sent DATA and the server hasn't answered yet.
	 *
	 * @var bool
	 */
	private $data_pending = false;

	/**
	 * Inside the message, between the 354 reply and the server's next reply.
	 *
	 * @var bool
	 */
	private $in_data = false;

	/**
	 * Message lines skipped.
	 *
	 * @var int
	 */
	private $data_lines = 0;

	/**
	 * The server just asked for credentials (334).
	 *
	 * @var bool
	 */
	private $secret_next = false;

	/**
	 * Start recording a send.
	 *
	 * @return void
	 */
	public function start() {
		$this->active       = true;
		$this->lines        = array();
		$this->bytes        = 0;
		$this->cut          = false;
		$this->started      = microtime( true );
		$this->data_pending = false;
		$this->in_data      = false;
		$this->data_lines   = 0;
		$this->secret_next  = false;
	}

	/**
	 * Stop recording and return what was said.
	 *
	 * @return string Empty when nothing was recorded.
	 */
	public function stop() {
		if ( ! $this->active ) {
			return '';
		}

		$this->active = false;

		if ( $this->in_data ) {
			$this->push( sprintf( 'CLIENT -> SERVER: [message: %d lines, not saved]', $this->data_lines ) );
		}

		$text = implode( "\n", $this->lines );
		if ( $this->cut ) {
			$text .= "\n[conversation cut short]";
		}

		$this->lines = array();

		return $text;
	}

	/**
	 * Whether a send is being recorded.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->active;
	}

	/**
	 * PHPMailer's Debugoutput callback.
	 *
	 * @param mixed $str   Debug text (may hold several lines).
	 * @param mixed $level Debug level (unused).
	 * @return void
	 */
	public function collect( $str, $level = 0 ) {
		if ( ! $this->active ) {
			return;
		}

		try {
			$prefix = '';
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $str ) as $i => $line ) {
				// A multi-line reply arrives as one string: label every line of it.
				if ( 0 === $i && 0 === strpos( $line, 'SERVER -> CLIENT: ' ) ) {
					$prefix = 'SERVER -> CLIENT: ';
				} elseif ( '' !== $prefix && '' !== trim( $line ) && 0 !== strpos( $line, $prefix ) ) {
					$line = $prefix . $line;
				}
				$this->add( (string) $line );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Add one line of debug output.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private function add( $line ) {
		$line = rtrim( $line );
		if ( '' === trim( $line ) ) {
			return;
		}

		if ( preg_match( '/^CLIENT -> SERVER: ?(.*)$/', $line, $match ) ) {
			$command = $match[1];

			if ( $this->in_data ) {
				++$this->data_lines;
				return;
			}

			if ( $this->secret_next ) {
				$line = 'CLIENT -> SERVER: [credentials hidden]';
			} elseif ( preg_match( '/^AUTH\s+([A-Za-z0-9_-]+)\s+\S/', $command, $auth ) ) {
				$line = 'CLIENT -> SERVER: AUTH ' . strtoupper( $auth[1] ) . ' [credentials hidden]';
			}

			$this->secret_next  = false;
			$this->data_pending = (bool) preg_match( '/^DATA\s*$/i', $command );
		} elseif ( preg_match( '/^SERVER -> CLIENT: ?(\d{3})/', $line, $match ) ) {
			if ( $this->in_data ) {
				$this->push( sprintf( 'CLIENT -> SERVER: [message: %d lines, not saved]', $this->data_lines ) );
				$this->in_data = false;
			}

			if ( $this->data_pending && '354' === $match[1] ) {
				$this->in_data    = true;
				$this->data_lines = 0;
			}

			$this->data_pending = false;
			$this->secret_next  = '334' === $match[1];
		} else {
			// Connection notes and errors. SMTP options can carry a certificate passphrase.
			$line = (string) preg_replace( '/([\'"]?(?:pass(?:word|phrase)?|secret|token)[\'"]?\s*=>\s*)([\'"])[^\'"]*\2/i', '$1[hidden]', $line );
		}

		$this->push( $line );
	}

	/**
	 * Store a line with the time since the send started.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private function push( $line ) {
		if ( count( $this->lines ) >= self::MAX_LINES || $this->bytes >= SMTP_Email_Log_Log::MAX_TRANSCRIPT - 1000 ) {
			$this->cut = true;
			return;
		}

		$line = sprintf( '[%6.2fs] %s', microtime( true ) - $this->started, substr( $line, 0, 1000 ) );

		$this->lines[] = $line;
		$this->bytes  += strlen( $line ) + 1;
	}
}
