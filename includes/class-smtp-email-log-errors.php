<?php
/**
 * Plain-English explanations of common sending errors, shown beside the
 * raw error so a site owner knows what to fix.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Error explanations.
 */
final class SMTP_Email_Log_Errors {

	/**
	 * Explain an error.
	 *
	 * @param string              $error Error message.
	 * @param array<string,mixed> $row   Log row, for its transcript and meta.
	 * @return array{icon:string,title:string,text:string}
	 */
	public static function explain( $error, array $row = array() ) {
		$error = (string) $error;
		$meta  = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array();
		$text  = strtolower( $error . "\n" . self::problem_lines( isset( $row['transcript'] ) ? (string) $row['transcript'] : '' ) );

		if ( ! empty( $meta['fatal'] ) || false !== strpos( $text, 'maximum execution time' ) ) {
			return array(
				'icon'  => 'timer-off',
				'title' => __( 'The page crashed or ran out of time while sending', 'smtp-email-log' ),
				'text'  => __( 'PHP stopped before the email was handed over. If the error mentions the maximum execution time, the mail server was too slow to answer: set a shorter SMTP timeout on the Advanced tab so the form fails quickly instead, and check the server address and port. Otherwise, the error above names the plugin or theme file that crashed.', 'smtp-email-log' ),
			);
		}

		if ( ! empty( $meta['short_circuit'] ) ) {
			return array(
				'icon'  => 'plug-zap',
				'title' => __( 'Another plugin stopped the email', 'smtp-email-log' ),
				'text'  => __( 'A plugin on this site took over sending through the pre_wp_mail filter and reported that it didn\'t send this email. Check that plugin\'s own log or settings.', 'smtp-email-log' ),
			);
		}

		$checks = array(
			array(
				'match' => array( 'could not authenticate', 'authentication unsuccessful', 'authentication failed', 'username and password not accepted', 'application-specific password', 'invalid credentials', '535', '534', '5.7.8', '5.7.9', 'smtpclientauthentication is disabled', 'basic authentication is disabled' ),
				'icon'  => 'key-round',
				'title' => __( 'The mail server didn\'t accept the login', 'smtp-email-log' ),
				'text'  => __( 'The SMTP username or password is wrong, or the account isn\'t allowed to log in this way. Check them in your SMTP plugin. Gmail and Google Workspace need an app password (with 2-step verification on) or a "Sign in with Google" connection; Microsoft 365 mailboxes need SMTP AUTH switched on by an admin; SendGrid uses "apikey" as the username and the API key as the password.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'could not instantiate mail function', 'mail() returned', 'could not execute' ),
				'icon'  => 'server-off',
				'title' => __( 'This server can\'t send email by itself', 'smtp-email-log' ),
				'text'  => __( 'WordPress tried PHP\'s built-in mail() function, which many hosts switch off or don\'t set up. Install an SMTP plugin (WP Mail SMTP, FluentSMTP, Post SMTP and others are free) and connect it to your email provider or a sending service. Email sent that way is also far less likely to land in spam.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'certificate verify failed', 'ssl operation failed', 'stream_socket_enable_crypto', 'ssl routines', 'wrong version number', 'tls negotiation', 'handshake', 'starttls failed', 'unable to enable crypto' ),
				'icon'  => 'lock-keyhole-open',
				'title' => __( 'The secure connection to the mail server failed', 'smtp-email-log' ),
				'text'  => __( 'The encryption setting has to match the port: TLS (STARTTLS) on 587, SSL on 465. If it does, the mail server\'s certificate doesn\'t match the host name you entered (use the provider\'s own host name, not an IP address or alias), or your server\'s certificate list is out of date, which your host can fix.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'smtp connect() failed', 'failed to connect', 'connection refused', 'connection timed out', 'timed out', 'network is unreachable', 'no route to host', 'php_network_getaddresses', 'getaddrinfo', 'name or service not known', 'could not connect', 'unable to connect' ),
				'icon'  => 'unplug',
				'title' => __( 'Your server couldn\'t reach the mail server', 'smtp-email-log' ),
				'text'  => __( 'Check the SMTP host and port: usually 587 with TLS, or 465 with SSL. If they\'re right, your host is probably blocking outgoing mail ports (many do, to stop spam). Ask them to open the port, or use your SMTP plugin\'s API connection to a sending service instead, which goes over the normal web port.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'message body empty' ),
				'icon'  => 'file-x',
				'title' => __( 'The email had no message', 'smtp-email-log' ),
				'text'  => __( 'The plugin that sent it passed an empty message, which mail servers won\'t send. Check that plugin\'s email template or notification settings.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'recipient address rejected', 'user unknown', 'mailbox unavailable', 'no such user', 'does not exist', 'recipients failed', 'refused every recipient', '5.1.1', 'invalid recipient' ),
				'icon'  => 'mail-x',
				'title' => __( 'A recipient address was refused', 'smtp-email-log' ),
				'text'  => __( 'The mail server said an address doesn\'t exist or won\'t take mail. Check it for typos. If it\'s your own address, check that the mailbox exists and isn\'t full.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'too many', 'rate limit', 'limit exceeded', 'quota', 'try again later', 'temporarily', '421', '450', '451', '452', '4.7.0' ),
				'icon'  => 'gauge',
				'title' => __( 'The mail server is limiting how much you send', 'smtp-email-log' ),
				'text'  => __( 'You\'ve hit a sending limit, or the server is busy. Resend this email in a while. If it keeps happening, your plan\'s daily limit may be too low for this site: free Gmail accounts allow about 500 emails a day, for example.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'data not accepted', 'message rejected', 'spam', 'blocklist', 'blacklist', 'policy', 'content rejected', 'message size', 'too large', '554', '552' ),
				'icon'  => 'shield-alert',
				'title' => __( 'The mail server refused the message itself', 'smtp-email-log' ),
				'text'  => __( 'It was turned away for its content or size, or because the sending server or domain is on a block list. Look at the server\'s reply in the SMTP conversation for the reason, and check your domain\'s SPF, DKIM and DMARC records with your email provider.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'sender address rejected', 'not owned by', 'not allowed to send', 'not authorized to send', 'not authorised to send', 'sender verify', 'unverified', 'relay access denied', 'relaying denied', 'relay not permitted', 'send as', '553' ),
				'icon'  => 'user-x',
				'title' => __( 'The mail server won\'t send from this address', 'smtp-email-log' ),
				'text'  => __( 'Most providers only send from addresses on the account you log in with, or on a domain you\'ve verified with them. Set the From address in your SMTP plugin (and in the form plugin\'s notification settings) to one of those, and put the visitor\'s address in Reply-To instead.', 'smtp-email-log' ),
			),
			array(
				'match' => array( 'invalid address', 'you must provide at least one recipient' ),
				'icon'  => 'at-sign',
				'title' => __( 'An email address was missing or invalid', 'smtp-email-log' ),
				'text'  => __( 'The plugin that sent this gave WordPress a To or From address that isn\'t a valid email address, often an empty notification setting in a form or shop plugin. Check its notification settings.', 'smtp-email-log' ),
			),
		);

		foreach ( $checks as $check ) {
			foreach ( $check['match'] as $needle ) {
				if ( self::contains( $text, $needle ) ) {
					return array(
						'icon'  => $check['icon'],
						'title' => $check['title'],
						'text'  => $check['text'],
					);
				}
			}
		}

		return array(
			'icon'  => 'circle-question-mark',
			'title' => __( 'The email couldn\'t be sent', 'smtp-email-log' ),
			'text'  => __( 'The mail server\'s own words are in the error above and in the SMTP conversation, when there is one. Send a test email from the Test Email tab after any change to see if it\'s fixed.', 'smtp-email-log' ),
		);
	}

	/**
	 * Whether the text has a phrase, or a reply code as a whole number
	 * (so "535" doesn't match inside a server's message ID).
	 *
	 * @param string $text   Lowercase text.
	 * @param string $needle Phrase or code.
	 * @return bool
	 */
	private static function contains( $text, $needle ) {
		if ( preg_match( '/^[0-9.]+$/', $needle ) ) {
			return (bool) preg_match( '/(?<![0-9.])' . preg_quote( $needle, '/' ) . '(?![0-9])/', $text );
		}

		return false !== strpos( $text, $needle );
	}

	/**
	 * The lines of an SMTP conversation that describe a problem: the
	 * server's 4xx and 5xx replies, and connection errors. The rest (the
	 * server listing STARTTLS and AUTH, say) would only cause false matches.
	 *
	 * @param string $transcript SMTP conversation.
	 * @return string
	 */
	private static function problem_lines( $transcript ) {
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $transcript ) as $line ) {
			if ( preg_match( '/SERVER -> CLIENT: [45][0-9]{2}/', $line ) || preg_match( '/\b(error|failed|warning)\b/i', $line ) ) {
				$out[] = preg_replace( '/^\[\s*[0-9.]+s\]\s*/', '', $line );
			}
		}

		return implode( "\n", array_slice( $out, -10 ) );
	}
}
