<?php
/**
 * Sending a logged email again: to its original recipients, or forwarded
 * to another address (to recover a form submission that never arrived).
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resend and forward.
 */
final class SMTP_Email_Log_Resend {

	/**
	 * Most emails resent in one bulk action, so the request can't time out.
	 */
	const MAX_BULK = 20;

	/**
	 * Send a logged email again.
	 *
	 * @param int    $id           Log row.
	 * @param string $to           Forward to this address instead of the original recipients.
	 * @param bool   $default_from Leave out the original From header (use the site's default sender).
	 * @return array{ok:bool,id:int,error:string,code:string}
	 */
	public static function send( $id, $to = '', $default_from = false ) {
		$row = SMTP_Email_Log_Log::get( $id );

		if ( ! $row ) {
			return self::result( false, 0, 'missing' );
		}

		$meta = $row['meta'];
		if ( '' === (string) $row['message'] && ( ! empty( $meta['no_body'] ) || ! empty( $meta['body_dropped'] ) ) ) {
			return self::result( false, 0, 'no_body' );
		}

		$recipients = '' !== $to ? array( $to ) : self::split( (string) $row['to_addr'] );
		if ( ! $recipients ) {
			return self::result( false, 0, 'no_to' );
		}

		$copies   = SMTP_Email_Log_Attachments::temp_copies( $row['attachments'] );
		$recorder = SMTP_Email_Log_Plugin::instance()->recorder();
		$before   = $recorder ? $recorder->last_id() : 0;
		$error    = '';
		$catch    = function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};

		if ( $recorder ) {
			$recorder->expect(
				array(
					'kind'        => 'resend',
					'resent_from' => (int) $row['id'],
				)
			);
		}

		add_action( 'wp_mail_failed', $catch );
		$ok = wp_mail( $recipients, (string) $row['subject'], (string) $row['message'], self::headers( $row, '' !== $to, $default_from ), $copies['paths'] );
		remove_action( 'wp_mail_failed', $catch );

		if ( $recorder ) {
			$recorder->expect( array() );
			$recorder->settle();
		}

		SMTP_Email_Log_Attachments::remove_temp( $copies['dir'] );

		$new_id = $recorder && $recorder->last_id() !== $before ? $recorder->last_id() : 0;

		// Note it on the original, so its page shows when and where it went.
		$resends   = isset( $meta['resends'] ) && is_array( $meta['resends'] ) ? $meta['resends'] : array();
		$resends[] = array(
			'id'  => $new_id,
			'at'  => time(),
			'ok'  => $ok ? 1 : 0,
			'to'  => '' !== $to ? $to : '',
			'who' => get_current_user_id(),
		);
		SMTP_Email_Log_Log::update_meta( (int) $row['id'], array( 'resends' => array_slice( $resends, -10 ) ) );

		return array(
			'ok'    => (bool) $ok,
			'id'    => (int) $new_id,
			'error' => (string) $error,
			'code'  => $ok ? 'sent' : 'failed',
		);
	}

	/**
	 * The original headers, adjusted for resending.
	 *
	 * Cc and Bcc are dropped when forwarding to someone else. The content
	 * type and Reply-To are added back when the original sender set them
	 * some other way than a header (a filter, say), so the resend looks the
	 * same and replies still reach the person who filled in the form.
	 *
	 * @param array<string,mixed> $row          Log row.
	 * @param bool                $forward      Sending to another address.
	 * @param bool                $default_from Leave out the From header.
	 * @return string[]
	 */
	public static function headers( array $row, $forward, $default_from ) {
		$out       = array();
		$has_type  = false;
		$has_reply = false;

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $row['headers'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			$name = strtolower( trim( strtok( $line, ':' ) ) );

			if ( ( $forward && in_array( $name, array( 'cc', 'bcc' ), true ) ) || ( $default_from && 'from' === $name ) || 'to' === $name ) {
				continue;
			}

			$has_type  = $has_type || 'content-type' === $name;
			$has_reply = $has_reply || 'reply-to' === $name;
			$out[]     = $line;
		}

		if ( ! $has_type && false !== strpos( (string) $row['content_type'], 'html' ) ) {
			$out[] = 'Content-Type: text/html; charset=UTF-8';
		}

		if ( ! $has_reply && '' !== trim( (string) $row['reply_to'] ) ) {
			$out[] = 'Reply-To: ' . trim( (string) $row['reply_to'] );
		}

		return $out;
	}

	/**
	 * A stored address list back into addresses.
	 *
	 * @param string $list "Name <address>, address".
	 * @return string[]
	 */
	public static function split( $list ) {
		$out = array();
		foreach ( explode( ',', $list ) as $address ) {
			$address = trim( $address );
			if ( '' !== $address ) {
				$out[] = $address;
			}
		}

		return $out;
	}

	/**
	 * A result that never reached wp_mail().
	 *
	 * @param bool   $ok   Sent.
	 * @param int    $id   New row.
	 * @param string $code Reason.
	 * @return array{ok:bool,id:int,error:string,code:string}
	 */
	private static function result( $ok, $id, $code ) {
		return array(
			'ok'    => $ok,
			'id'    => $id,
			'error' => '',
			'code'  => $code,
		);
	}

	/**
	 * Why an email can't be resent, as a sentence.
	 *
	 * @param string $code Reason from {@see send()}.
	 * @return string
	 */
	public static function reason( $code ) {
		$reasons = array(
			'missing' => __( 'That email isn\'t in the log anymore.', 'smtp-email-log' ),
			'no_body' => __( 'The message wasn\'t saved (see "Message content" in the settings), so it can\'t be resent.', 'smtp-email-log' ),
			'no_to'   => __( 'The email has no recipient to send it to.', 'smtp-email-log' ),
			'invalid' => __( 'That isn\'t a valid email address.', 'smtp-email-log' ),
		);

		return isset( $reasons[ $code ] ) ? $reasons[ $code ] : '';
	}
}
