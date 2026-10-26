<?php
/**
 * Privacy: suggested privacy-policy text, and the email log in WordPress's
 * "Export Personal Data" and "Erase Personal Data" tools.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy tools integration.
 */
final class SMTP_Email_Log_Privacy {

	/**
	 * Log rows handled per page of an export request.
	 */
	const BATCH = 50;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'policy_text' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Suggested text for Settings > Privacy > Policy Guide.
	 *
	 * @return void
	 */
	public function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . __( 'We keep a log of the emails this website sends, such as messages from our contact forms, order and account emails, and notifications. Each entry holds the email\'s recipients, sender, subject and content, whether it was delivered to our mail server, and the error if it wasn\'t. We use it to fix email problems and to make sure no message sent to us is lost.', 'smtp-email-log' ) . '</p>'
			. '<p>' . __( 'Only site administrators can see the log, and entries are deleted automatically after a set period. Links in password reset and login emails are hidden before they\'re stored.', 'smtp-email-log' ) . '</p>';

		wp_add_privacy_policy_content( __( 'SMTP Email Log', 'smtp-email-log' ), wp_kses_post( $text ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['smtp-email-log'] = array(
			'exporter_friendly_name' => __( 'Email log', 'smtp-email-log' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['smtp-email-log'] = array(
			'eraser_friendly_name' => __( 'Email log', 'smtp-email-log' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the emails sent to, from or on behalf of an address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		$page = max( 1, (int) $page );
		$rows = SMTP_Email_Log_Log::for_email( (string) $email, self::BATCH, ( $page - 1 ) * self::BATCH );
		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'smtp-email-log',
				'group_label' => __( 'Emails', 'smtp-email-log' ),
				'item_id'     => 'smtp-email-log-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date (UTC)', 'smtp-email-log' ),
						'value' => (string) $row['created_at'],
					),
					array(
						'name'  => __( 'Status', 'smtp-email-log' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'From', 'smtp-email-log' ),
						'value' => (string) $row['from_addr'],
					),
					array(
						'name'  => __( 'To', 'smtp-email-log' ),
						'value' => (string) $row['to_addr'],
					),
					array(
						'name'  => __( 'Cc', 'smtp-email-log' ),
						'value' => (string) $row['cc_addr'],
					),
					array(
						'name'  => __( 'Reply-To', 'smtp-email-log' ),
						'value' => (string) $row['reply_to'],
					),
					array(
						'name'  => __( 'Subject', 'smtp-email-log' ),
						'value' => (string) $row['subject'],
					),
					array(
						'name'  => __( 'Message', 'smtp-email-log' ),
						'value' => SMTP_Email_Log_Log::plain_text( (string) $row['message'], (string) $row['content_type'] ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::BATCH,
		);
	}

	/**
	 * Erase every email that involves an address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused: everything goes in one pass).
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( $email, $page = 1 ) {
		$removed = SMTP_Email_Log_Log::erase_email( (string) $email );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
