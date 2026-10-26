<?php
/**
 * The email log: storage, queries, counts and pruning.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log storage and queries.
 */
final class SMTP_Email_Log_Log {

	/**
	 * Row statuses. "sending" is written before the email goes out, so a
	 * crash or timeout mid-send still leaves the email in the log; it becomes
	 * "unknown" if WordPress never reports back.
	 */
	const SENDING = 'sending';
	const SENT    = 'sent';
	const FAILED  = 'failed';
	const UNKNOWN = 'unknown';

	/**
	 * Size limits in bytes. Longer values are cut, and the row says so.
	 */
	const MAX_MESSAGE    = 524288;
	const MAX_TRANSCRIPT = 65536;
	const MAX_TEXT       = 16000;
	const MAX_SUBJECT    = 2000;

	/**
	 * Minutes after which an email still marked "sending" is shown as unknown.
	 */
	const STALE_MINUTES = 10;

	/**
	 * Option with daily sent/failed counts, kept apart from the rows so the
	 * totals stay right when only failed emails are logged or rows are deleted.
	 */
	const COUNTS_OPTION = 'smtp_email_log_counts';

	/**
	 * Columns the list view needs (not the message or SMTP conversation).
	 */
	const LIST_COLUMNS = 'id, created_at, status, to_addr, cc_addr, bcc_addr, from_addr, subject, error, source, resent_from, attachments, meta';

	/**
	 * Column formats for $wpdb.
	 */
	const FORMATS = array(
		'created_at'   => '%s',
		'status'       => '%s',
		'to_addr'      => '%s',
		'cc_addr'      => '%s',
		'bcc_addr'     => '%s',
		'reply_to'     => '%s',
		'from_addr'    => '%s',
		'subject'      => '%s',
		'message'      => '%s',
		'content_type' => '%s',
		'headers'      => '%s',
		'attachments'  => '%s',
		'error'        => '%s',
		'transport'    => '%s',
		'server_reply' => '%s',
		'transcript'   => '%s',
		'source'       => '%s',
		'context'      => '%s',
		'resent_from'  => '%d',
		'meta'         => '%s',
	);

	/**
	 * Byte limits per column.
	 */
	const LIMITS = array(
		'status'       => 10,
		'to_addr'      => self::MAX_TEXT,
		'cc_addr'      => self::MAX_TEXT,
		'bcc_addr'     => self::MAX_TEXT,
		'reply_to'     => self::MAX_TEXT,
		'from_addr'    => 255,
		'subject'      => self::MAX_SUBJECT,
		'message'      => self::MAX_MESSAGE,
		'content_type' => 60,
		'headers'      => self::MAX_TEXT,
		'attachments'  => self::MAX_TEXT,
		'error'        => self::MAX_TEXT,
		'transport'    => self::MAX_TEXT,
		'server_reply' => 255,
		'transcript'   => self::MAX_TRANSCRIPT,
		'source'       => 191,
		'context'      => self::MAX_TEXT,
		'meta'         => self::MAX_TEXT,
	);

	/**
	 * Columns stored as JSON.
	 */
	const JSON_COLUMNS = array( 'attachments', 'transport', 'context', 'meta' );

	/**
	 * Whether the table can't hold 4-byte characters (an old utf8 table).
	 *
	 * @var bool|null
	 */
	private static $three_byte = null;

	/**
	 * The table name with the site's prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'smtp_email_log';
	}

	/**
	 * Add a row.
	 *
	 * Database errors are never printed: this runs in the middle of other
	 * plugins' requests (form submissions, REST and AJAX calls), where stray
	 * output would break their responses. If the table has gone missing,
	 * it's recreated once and the insert retried.
	 *
	 * @param array<string,mixed> $row Column => value. JSON columns take arrays.
	 * @return int Row ID, or 0 if it couldn't be written.
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$data = self::prepare( $row );
		if ( ! $data ) {
			return 0;
		}

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( self::table(), $data, self::formats( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table.

		if ( false === $ok ) {
			SMTP_Email_Log_Install::install();
			$ok = $wpdb->insert( self::table(), $data, self::formats( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table.
		}

		$wpdb->suppress_errors( $suppress );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Change a row.
	 *
	 * @param int                 $id  Row ID.
	 * @param array<string,mixed> $row Column => value.
	 * @return bool
	 */
	public static function update( $id, array $row ) {
		global $wpdb;

		$id   = absint( $id );
		$data = self::prepare( $row );
		if ( ! $id || ! $data ) {
			return false;
		}

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->update( self::table(), $data, array( 'id' => $id ), self::formats( $data ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		$wpdb->suppress_errors( $suppress );

		return false !== $ok;
	}

	/**
	 * Merge keys into a row's meta.
	 *
	 * @param int                 $id   Row ID.
	 * @param array<string,mixed> $meta Keys to set.
	 * @return void
	 */
	public static function update_meta( $id, array $meta ) {
		$row = self::get( $id, 'id, meta' );
		if ( $row ) {
			self::update( $id, array( 'meta' => array_merge( $row['meta'], $meta ) ) );
		}
	}

	/**
	 * One row, with its JSON columns decoded.
	 *
	 * @param int    $id      Row ID.
	 * @param string $columns Columns to read; a fixed list, never user input.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id, $columns = '*' ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; $columns is a fixed list from this plugin.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE id = %d", absint( $id ) ), ARRAY_A );

		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Rows for the list view, newest first.
	 *
	 * @param array<string,mixed> $args status, search, limit, offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $args ) {
		global $wpdb;

		$table = self::table();
		$where = self::where( $args );
		$limit = isset( $args['limit'] ) ? max( 1, min( 500, absint( $args['limit'] ) ) ) : 30;
		$skip  = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$sql = "SELECT " . self::LIST_COLUMNS . " FROM {$table} WHERE {$where['sql']} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; every value goes through prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where['params'], array( $limit, $skip ) ) ), ARRAY_A );

		return array_map( array( __CLASS__, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * How many rows {@see query()} can page through.
	 *
	 * @param array<string,mixed> $args status, search.
	 * @return int
	 */
	public static function count( array $args ) {
		global $wpdb;

		$table = self::table();
		$where = self::where( $args );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}";

		if ( $where['params'] ) {
			$sql = $wpdb->prepare( $sql, $where['params'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders only.
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Rows per status, for the filter links.
	 *
	 * @return array{all:int,sent:int,failed:int,unknown:int,sending:int}
	 */
	public static function status_counts() {
		global $wpdb;

		$table = self::table();
		$out   = array(
			'all'     => 0,
			'sent'    => 0,
			'failed'  => 0,
			'unknown' => 0,
			'sending' => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] = (int) $row['n'];
			}
			$out['all'] += (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Count a finished email towards today's totals.
	 *
	 * @param string $status SENT, FAILED or UNKNOWN.
	 * @param int    $number How many.
	 * @return void
	 */
	public static function count_result( $status, $number = 1 ) {
		if ( $number < 1 || ! in_array( $status, array( self::SENT, self::FAILED, self::UNKNOWN ), true ) ) {
			return;
		}

		$counts = get_option( self::COUNTS_OPTION, array() );
		$counts = is_array( $counts ) ? $counts : array();
		$today  = wp_date( 'Y-m-d' );

		if ( ! isset( $counts[ $today ] ) || ! is_array( $counts[ $today ] ) ) {
			$counts[ $today ] = array();
		}
		$counts[ $today ][ $status ] = ( isset( $counts[ $today ][ $status ] ) ? (int) $counts[ $today ][ $status ] : 0 ) + (int) $number;

		// Keep five weeks.
		krsort( $counts );
		$counts = array_slice( $counts, 0, 35, true );

		update_option( self::COUNTS_OPTION, $counts, false );
	}

	/**
	 * Totals for the stat tiles.
	 *
	 * @return array{sent_today:int,failed_today:int,sent_month:int,failed_month:int,unknown_month:int}
	 */
	public static function totals() {
		$counts = get_option( self::COUNTS_OPTION, array() );
		$counts = is_array( $counts ) ? $counts : array();
		$today  = wp_date( 'Y-m-d' );
		$since  = wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$out    = array(
			'sent_today'    => 0,
			'failed_today'  => 0,
			'sent_month'    => 0,
			'failed_month'  => 0,
			'unknown_month' => 0,
		);

		foreach ( $counts as $day => $day_counts ) {
			if ( ! is_array( $day_counts ) || ! is_string( $day ) || $day < $since ) {
				continue;
			}
			$sent    = isset( $day_counts['sent'] ) ? (int) $day_counts['sent'] : 0;
			$failed  = isset( $day_counts['failed'] ) ? (int) $day_counts['failed'] : 0;
			$unknown = isset( $day_counts['unknown'] ) ? (int) $day_counts['unknown'] : 0;

			$out['sent_month']    += $sent;
			$out['failed_month']  += $failed;
			$out['unknown_month'] += $unknown;

			if ( $day === $today ) {
				$out['sent_today']   = $sent;
				$out['failed_today'] = $failed;
			}
		}

		return $out;
	}

	/**
	 * The most recent failed email.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest_failure() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$row = $wpdb->get_row( "SELECT id, created_at, to_addr, subject, error FROM {$table} WHERE status = 'failed' ORDER BY id DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Number of rows and the table's size on disk.
	 *
	 * @return array{rows:int,bytes:int}
	 */
	public static function size() {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
		// phpcs:enable

		$bytes = is_array( $status ) ? (int) $status['Data_length'] + (int) $status['Index_length'] : 0;

		return array(
			'rows'  => $rows,
			'bytes' => $bytes,
		);
	}

	/**
	 * Delete rows and any attachment copies they hold.
	 *
	 * @param int[] $ids Row IDs.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$table   = self::table();
		$deleted = 0;

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Our own table; $in is one %d placeholder per ID.
			$files = $wpdb->get_col( $wpdb->prepare( "SELECT attachments FROM {$table} WHERE id IN ({$in}) AND attachments <> ''", $chunk ) );
			$count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$in})", $chunk ) );
			// phpcs:enable

			foreach ( (array) $files as $json ) {
				SMTP_Email_Log_Attachments::delete_files( self::json( $json ) );
			}

			$deleted += (int) $count;
		}

		return $deleted;
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function delete_all() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		SMTP_Email_Log_Attachments::delete_all();
	}

	/**
	 * Mark emails that have been "sending" for too long as unknown: the
	 * request that sent them ended without WordPress reporting back.
	 *
	 * @return int Rows changed.
	 */
	public static function mark_stale() {
		global $wpdb;

		$table   = self::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::STALE_MINUTES * MINUTE_IN_SECONDS );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'unknown' WHERE status = 'sending' AND created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.

		self::count_result( self::UNKNOWN, (int) $changed );

		return (int) $changed;
	}

	/**
	 * Delete rows past their retention period, then cap the table.
	 *
	 * @param int $sent_days   Days to keep sent emails (0 = forever).
	 * @param int $failed_days Days to keep failed and unknown emails (0 = forever).
	 * @param int $max_rows    Most rows kept.
	 * @return void
	 */
	public static function prune( $sent_days, $failed_days, $max_rows ) {
		if ( $sent_days > 0 ) {
			self::delete_where( "status = 'sent' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $sent_days * DAY_IN_SECONDS ) );
		}

		if ( $failed_days > 0 ) {
			self::delete_where( "status IN ('failed','unknown') AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $failed_days * DAY_IN_SECONDS ) );
		}

		self::cap( $max_rows );
	}

	/**
	 * Drop the oldest rows past the limit.
	 *
	 * @param int $max_rows Most rows kept.
	 * @return void
	 */
	public static function cap( $max_rows ) {
		global $wpdb;

		$max_rows = max( 100, absint( $max_rows ) );
		$table    = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$edge = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max_rows ) );

		if ( $edge > 0 ) {
			self::delete_where( 'id <= %d', $edge );
		}
	}

	/**
	 * Delete matching rows in batches, attachments included.
	 *
	 * @param string     $condition SQL condition with one placeholder; fixed strings from this class.
	 * @param string|int $value     Value for the placeholder.
	 * @return void
	 */
	private static function delete_where( $condition, $value ) {
		global $wpdb;

		$table = self::table();

		// At most 50,000 rows per run; the next daily run carries on.
		for ( $i = 0; $i < 50; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Our own table; $condition is a fixed string from this class with one placeholder.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$condition} ORDER BY id ASC LIMIT 1000", $value ) );

			if ( ! $ids ) {
				return;
			}

			self::delete( $ids );
		}
	}

	/**
	 * Rows that involve an email address, for the personal data exporter.
	 *
	 * @param string $email  Email address.
	 * @param int    $limit  Rows.
	 * @param int    $offset Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_email( $email, $limit, $offset ) {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array();
		}

		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $email ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, status, to_addr, cc_addr, bcc_addr, reply_to, from_addr, subject, message, content_type FROM {$table} WHERE to_addr LIKE %s OR cc_addr LIKE %s OR bcc_addr LIKE %s OR reply_to LIKE %s OR from_addr LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d", $like, $like, $like, $like, $like, absint( $limit ), absint( $offset ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete every row that involves an email address, including ones that
	 * only mention it in the message (a contact form's "Email" field).
	 *
	 * @param string $email Email address.
	 * @return int Rows deleted.
	 */
	public static function erase_email( $email ) {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return 0;
		}

		$table   = self::table();
		$like    = '%' . $wpdb->esc_like( $email ) . '%';
		$deleted = 0;

		for ( $i = 0; $i < 100; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE to_addr LIKE %s OR cc_addr LIKE %s OR bcc_addr LIKE %s OR reply_to LIKE %s OR from_addr LIKE %s OR headers LIKE %s OR message LIKE %s LIMIT 500", $like, $like, $like, $like, $like, $like, $like ) );

			if ( ! $ids ) {
				break;
			}

			$deleted += self::delete( $ids );
		}

		return $deleted;
	}

	/**
	 * Walk every matching row, newest first, in batches of 50 (for the CSV
	 * export: a message can be 512 KB, so bigger batches risk the memory limit).
	 *
	 * @param array<string,mixed> $args     status, search.
	 * @param callable            $callback Called with each full row.
	 * @return void
	 */
	public static function each( array $args, $callback ) {
		global $wpdb;

		$table = self::table();
		$where = self::where( $args );
		$below = PHP_INT_MAX;

		for ( $i = 0; $i < 2000; $i++ ) {
			$sql = "SELECT * FROM {$table} WHERE {$where['sql']} AND id < %d ORDER BY id DESC LIMIT 50";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; every value goes through prepare().
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where['params'], array( $below ) ) ), ARRAY_A );

			if ( ! $rows ) {
				return;
			}

			foreach ( $rows as $row ) {
				$below = (int) $row['id'];
				call_user_func( $callback, self::decode( $row ) );
			}
		}
	}

	/**
	 * A message as plain text: HTML emails lose their markup but keep line
	 * breaks, list items and link addresses.
	 *
	 * Every step is linear in the message's length, so a message built to
	 * make a regex backtrack (thousands of unclosed tags, say) can't stall
	 * the page or the export. Blocks are cut with plain string searches, and
	 * the regexes only see the part up to the last ">", so none of them can
	 * fail and start over from every "<".
	 *
	 * @param string $message      Message.
	 * @param string $content_type Its content type.
	 * @return string
	 */
	public static function plain_text( $message, $content_type ) {
		$message = (string) $message;

		if ( false === strpos( strtolower( (string) $content_type ), 'html' ) ) {
			return $message;
		}

		$parts = self::split_at_last_tag( self::strip_blocks( $message, array( 'head', 'style', 'script', 'title' ) ) );
		$html  = $parts[0];

		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#</(?:p|div|h[1-6]|li|tr|table|blockquote|section|article|header|footer)\s*>#i', "\n", $html );
		$html = (string) preg_replace( '#<li\b[^>]*>#i', '- ', $html );
		$html = (string) preg_replace( '#</t[dh]\s*>#i', "\t", $html );
		$html = self::link_text( $html );

		// strip_tags(), not wp_strip_all_tags(): core's version runs the same
		// kind of backtracking regex this function avoids.
		$text = html_entity_decode( strip_tags( $html . $parts[1] ), ENT_QUOTES, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- See above.
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Remove whole elements (tag, content and closing tag) by string search.
	 * An element that's never closed takes the rest of the document with it.
	 *
	 * @param string   $html HTML.
	 * @param string[] $tags Element names. "script" and "style" match any tag
	 *                       starting with them (as browsers and core's
	 *                       wp_strip_all_tags() might read them); others need
	 *                       a word boundary, so "head" doesn't take <header>.
	 * @return string
	 */
	public static function strip_blocks( $html, array $tags ) {
		foreach ( $tags as $tag ) {
			$lower  = strtolower( $html );
			$open   = '<' . $tag;
			$loose  = in_array( $tag, array( 'script', 'style' ), true );
			$out    = '';
			$pos    = 0;
			$search = 0;

			while ( false !== ( $start = strpos( $lower, $open, $search ) ) ) {
				$next = substr( $lower, $start + strlen( $open ), 1 );

				if ( ! $loose && '' !== $next && false === strpos( " \t\r\n/>", $next ) ) {
					$search = $start + 1;
					continue;
				}

				$out .= substr( $html, $pos, $start - $pos );
				$end  = strpos( $lower, '</' . $tag, $start + 1 );
				$gt   = false === $end ? false : strpos( $lower, '>', $end );
				$pos  = false === $gt ? strlen( $html ) : $gt + 1;

				$search = $pos;
				if ( $pos >= strlen( $html ) ) {
					break;
				}
			}

			$html = $out . (string) substr( $html, $pos );
		}

		return $html;
	}

	/**
	 * Split HTML after its last ">": everything a tag regex can match, and
	 * a tail with no complete tag in it.
	 *
	 * @param string $html HTML.
	 * @return array{0:string,1:string}
	 */
	public static function split_at_last_tag( $html ) {
		$last = strrpos( $html, '>' );

		if ( false === $last ) {
			return array( '', $html );
		}

		return array( substr( $html, 0, $last + 1 ), (string) substr( $html, $last + 1 ) );
	}

	/**
	 * Replace links with their text and address, "the post (https://...)",
	 * in one pass over the tags.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function link_text( $html ) {
		$parts = preg_split( '#(<a\b[^>]*>|</a\s*>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return $html;
		}

		$out   = '';
		$href  = null;
		$label = '';

		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$out .= $part;
				if ( null !== $href ) {
					$label .= $part;
				}
				continue;
			}

			if ( '/' !== substr( $part, 1, 1 ) ) {
				$href  = preg_match( '#\shref\s*=\s*(["\'])(.*?)\1#is', $part, $match ) ? html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ) : '';
				$label = '';
				continue;
			}

			if ( null !== $href && '' !== $href && 0 !== strpos( $href, 'mailto:' ) && 0 !== strpos( $href, '#' ) ) {
				$clean = trim( strip_tags( $label ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Linear; see plain_text().
				if ( '' === $clean ) {
					$out .= $href;
				} elseif ( $clean !== $href ) {
					$out .= ' (' . $href . ')';
				}
			}

			$href = null;
		}

		return $out;
	}

	/**
	 * WHERE clause for the list filters.
	 *
	 * @param array<string,mixed> $args status, search.
	 * @return array{sql:string,params:array}
	 */
	private static function where( array $args ) {
		global $wpdb;

		$sql    = array( '1=1' );
		$params = array();
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		if ( in_array( $status, array( self::SENT, self::FAILED, self::UNKNOWN, self::SENDING ), true ) ) {
			$sql[]    = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$sql[] = '( to_addr LIKE %s OR cc_addr LIKE %s OR bcc_addr LIKE %s OR reply_to LIKE %s OR from_addr LIKE %s OR subject LIKE %s OR error LIKE %s OR message LIKE %s )';
			for ( $i = 0; $i < 8; $i++ ) {
				$params[] = $like;
			}
		}

		return array(
			'sql'    => implode( ' AND ', $sql ),
			'params' => $params,
		);
	}

	/**
	 * Clean values for writing: known columns only, valid UTF-8 the table
	 * accepts, trimmed to fit. $wpdb refuses a whole row if any value would
	 * be changed on the way in, so nothing is left to chance here.
	 *
	 * @param array<string,mixed> $row Column => value.
	 * @return array<string,string|int>
	 */
	private static function prepare( array $row ) {
		$data = array();

		foreach ( $row as $column => $value ) {
			if ( ! array_key_exists( $column, self::FORMATS ) ) {
				continue;
			}

			if ( '%d' === self::FORMATS[ $column ] ) {
				$data[ $column ] = absint( $value );
				continue;
			}

			if ( in_array( $column, self::JSON_COLUMNS, true ) ) {
				$value = is_array( $value ) && $value ? wp_json_encode( self::utf8_deep( $value ) ) : '';
			}

			$value = self::text( is_scalar( $value ) ? (string) $value : '' );

			$data[ $column ] = array_key_exists( $column, self::LIMITS ) ? self::clip( $value, self::LIMITS[ $column ] ) : $value;
		}

		return $data;
	}

	/**
	 * Formats in the same order as the data.
	 *
	 * @param array<string,mixed> $data Prepared data.
	 * @return string[]
	 */
	private static function formats( array $data ) {
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = self::FORMATS[ $column ];
		}

		return $formats;
	}

	/**
	 * Decode a row's JSON columns.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public static function decode( array $row ) {
		foreach ( self::JSON_COLUMNS as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = self::json( $row[ $column ] );
			}
		}

		return $row;
	}

	/**
	 * Decode a JSON column.
	 *
	 * @param mixed $json Stored value.
	 * @return array
	 */
	public static function json( $json ) {
		$value = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Valid UTF-8 the table can store.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	public static function text( $value ) {
		$value = self::utf8( $value );

		if ( null === self::$three_byte ) {
			global $wpdb;

			$charset          = $wpdb->get_col_charset( self::table(), 'subject' );
			self::$three_byte = is_string( $charset ) && 'utf8' === strtolower( $charset );
		}

		// Old utf8 (3-byte) tables refuse emoji; swap them for the replacement character.
		if ( self::$three_byte ) {
			$value = (string) preg_replace( '/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $value );
		}

		return $value;
	}

	/**
	 * Make a string valid UTF-8 without losing more than the broken bytes.
	 * Sites with a non-UTF-8 charset have their text converted from it.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	public static function utf8( $value ) {
		$value = (string) $value;

		if ( '' === $value || preg_match( '//u', $value ) ) {
			return $value;
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			$charset = strtoupper( (string) get_option( 'blog_charset' ) );
			try {
				$out = mb_convert_encoding( $value, 'UTF-8', '' !== $charset && 'UTF-8' !== $charset ? $charset : 'UTF-8' );
				if ( is_string( $out ) && preg_match( '//u', $out ) ) {
					return $out;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'UTF-8//IGNORE', $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Notices on bad input are expected.
			if ( is_string( $out ) && preg_match( '//u', $out ) ) {
				return $out;
			}
		}

		return (string) preg_replace( '/[\x80-\xFF]/', '?', $value );
	}

	/**
	 * UTF-8 clean every string in an array, so wp_json_encode() can't fail.
	 *
	 * @param array $value Array.
	 * @return array
	 */
	private static function utf8_deep( array $value ) {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::utf8_deep( $item );
			} elseif ( is_string( $item ) ) {
				$value[ $key ] = self::utf8( $item );
			}
		}

		return $value;
	}

	/**
	 * Cut a UTF-8 string to a number of bytes without splitting a character.
	 *
	 * @param string $value Valid UTF-8.
	 * @param int    $bytes Most bytes.
	 * @return string
	 */
	public static function clip( $value, $bytes ) {
		$value = (string) $value;

		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}

		$value = substr( $value, 0, $bytes );

		// A cut can only break the last character, at most 3 bytes of it.
		for ( $i = 0; $i < 4 && '' !== $value && ! preg_match( '//u', $value ); $i++ ) {
			$value = substr( $value, 0, -1 );
		}

		return $value;
	}
}
