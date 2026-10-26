<?php
/**
 * Copies of the attachments of emails that failed, so a file sent through a
 * form isn't lost along with the email.
 *
 * Copies live in wp-content/uploads/smtp-email-log/ under random names with
 * no extension, so they can't be run as code or guessed, and that folder
 * refuses direct requests where the server reads .htaccess. They're only
 * ever served to administrators, as downloads, by
 * SMTP_Email_Log_Actions::download().
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Attachment storage.
 */
final class SMTP_Email_Log_Attachments {

	/**
	 * Folder inside uploads.
	 */
	const FOLDER = 'smtp-email-log';

	/**
	 * Stored file names: row ID, dash, 32 random hex characters.
	 */
	const NAME_PATTERN = '/^[0-9]+-[a-f0-9]{32}$/';

	/**
	 * Most bytes kept across every email, so a flood of failing form
	 * submissions can't fill the disk.
	 */
	const TOTAL_LIMIT = 268435456;

	/**
	 * Option with the bytes currently stored.
	 */
	const BYTES_OPTION = 'smtp_email_log_attachment_bytes';

	/**
	 * The storage folder, with a trailing slash.
	 *
	 * @param bool $create Create and protect it if needed.
	 * @return string Empty if it doesn't exist (or couldn't be created).
	 */
	public static function dir( $create = false ) {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::FOLDER . '/';

		if ( $create ) {
			return self::protect( $dir ) ? $dir : '';
		}

		return is_dir( $dir ) ? $dir : '';
	}

	/**
	 * Create the folder with files that stop it being listed or served.
	 *
	 * @param string $dir Folder.
	 * @return bool Whether it's ready to write to.
	 */
	private static function protect( $dir ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$files = array(
			'.htaccess'  => "# Copies of email attachments, only served to administrators through wp-admin.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);

		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . $name ) ) {
				file_put_contents( $dir . $name, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small protective files in our own folder.
			}
		}

		return wp_is_writable( $dir );
	}

	/**
	 * Describe an email's attachments: name and size of each.
	 *
	 * @param mixed $attachments Paths, as passed to wp_mail() (array or newline-separated string).
	 * @return array<int,array<string,mixed>>
	 */
	public static function describe( $attachments ) {
		$out = array();

		foreach ( self::paths( $attachments ) as $name => $path ) {
			$item = array(
				'name' => self::clean_name( $name ),
				'size' => 0,
			);

			if ( self::readable( $path ) ) {
				$item['size'] = (int) filesize( $path );
			} else {
				$item['missing'] = 1;
			}

			$out[] = $item;
		}

		return array_slice( $out, 0, 50 );
	}

	/**
	 * Copy an email's attachments into storage.
	 *
	 * @param int   $row_id      Log row.
	 * @param mixed $attachments Paths, as passed to wp_mail().
	 * @param int   $limit       Most bytes for this email.
	 * @return array<int,array<string,mixed>> The row's attachment list, with
	 *                                        "file" set on each one copied.
	 */
	public static function store( $row_id, $attachments, $limit ) {
		$out   = array();
		$dir   = '';
		$used  = 0;
		$total = max( 0, (int) get_option( self::BYTES_OPTION, 0 ) );

		foreach ( self::paths( $attachments ) as $name => $path ) {
			$item = array(
				'name' => self::clean_name( $name ),
				'size' => 0,
			);

			if ( ! self::readable( $path ) ) {
				$item['missing'] = 1;
				$out[]           = $item;
				continue;
			}

			$size         = (int) filesize( $path );
			$item['size'] = $size;

			if ( $used + $size > $limit ) {
				$item['skipped'] = 'size';
			} elseif ( $total + $used + $size > self::TOTAL_LIMIT ) {
				$item['skipped'] = 'full';
			} else {
				$dir  = '' !== $dir ? $dir : self::dir( true );
				$file = absint( $row_id ) . '-' . self::random();

				if ( '' !== $dir && @copy( $path, $dir . $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed copy is recorded below.
					$item['file'] = $file;
					$used        += $size;
				} else {
					$item['skipped'] = 'copy';
				}
			}

			$out[] = $item;
		}

		if ( $used > 0 ) {
			update_option( self::BYTES_OPTION, $total + $used, false );
		}

		return array_slice( $out, 0, 50 );
	}

	/**
	 * Full path of a stored copy.
	 *
	 * @param string $file Stored name.
	 * @return string Empty if the name isn't one of ours or the file is gone.
	 */
	public static function path( $file ) {
		$file = (string) $file;
		$dir  = self::dir();

		if ( '' === $dir || ! preg_match( self::NAME_PATTERN, $file ) ) {
			return '';
		}

		$path = realpath( $dir . $file );
		$root = realpath( $dir );

		if ( ! $path || ! $root || 0 !== strpos( $path, trailingslashit( $root ) ) || ! is_file( $path ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Delete the stored copies listed in a row's attachments.
	 *
	 * @param array $attachments Row attachment list.
	 * @return void
	 */
	public static function delete_files( array $attachments ) {
		$freed = 0;

		foreach ( $attachments as $item ) {
			if ( ! is_array( $item ) || empty( $item['file'] ) ) {
				continue;
			}

			$path = self::path( $item['file'] );
			if ( '' !== $path && wp_delete_file_from_directory( $path, self::dir() ) ) {
				$freed += isset( $item['size'] ) ? (int) $item['size'] : 0;
			}
		}

		if ( $freed > 0 ) {
			update_option( self::BYTES_OPTION, max( 0, (int) get_option( self::BYTES_OPTION, 0 ) - $freed ), false );
		}
	}

	/**
	 * Delete every stored copy (the folder and its protective files stay).
	 *
	 * @return void
	 */
	public static function delete_all() {
		foreach ( self::stored() as $file => $path ) {
			wp_delete_file( $path );
		}

		update_option( self::BYTES_OPTION, 0, false );
	}

	/**
	 * Delete copies whose log row is gone, and recount the bytes stored.
	 *
	 * @return void
	 */
	public static function sweep() {
		global $wpdb;

		$files = self::stored();
		$ids   = array();

		foreach ( array_keys( $files ) as $file ) {
			$ids[ (int) strtok( $file, '-' ) ] = true;
		}

		$live  = array();
		$table = SMTP_Email_Log_Log::table();

		foreach ( array_chunk( array_keys( $ids ), 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Our own table; $in is one %d placeholder per ID.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$in})", $chunk ) ) as $id ) {
				$live[ (int) $id ] = true;
			}
		}

		$bytes = 0;
		foreach ( $files as $file => $path ) {
			if ( isset( $live[ (int) strtok( $file, '-' ) ] ) ) {
				$bytes += (int) filesize( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		update_option( self::BYTES_OPTION, $bytes, false );
	}

	/**
	 * Copy stored attachments to a temporary folder under their real names,
	 * for resending.
	 *
	 * @param array $attachments Row attachment list.
	 * @return array{dir:string,paths:string[]}
	 */
	public static function temp_copies( array $attachments ) {
		$dir   = trailingslashit( get_temp_dir() ) . 'smtp-email-log-' . self::random() . '/';
		$paths = array();

		foreach ( $attachments as $item ) {
			$source = is_array( $item ) && ! empty( $item['file'] ) ? self::path( $item['file'] ) : '';
			if ( '' === $source ) {
				continue;
			}

			if ( ! $paths && ! wp_mkdir_p( $dir ) ) {
				break;
			}

			$name   = self::clean_name( isset( $item['name'] ) ? $item['name'] : '' );
			$target = file_exists( $dir . $name ) ? $dir . count( $paths ) . '-' . $name : $dir . $name;

			if ( @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Skipped if it fails.
				$paths[] = $target;
			}
		}

		return array(
			'dir'   => $paths ? $dir : '',
			'paths' => $paths,
		);
	}

	/**
	 * Remove a folder made by {@see temp_copies()}.
	 *
	 * @param string $dir Folder.
	 * @return void
	 */
	public static function remove_temp( $dir ) {
		$dir = (string) $dir;
		if ( '' === $dir || false === strpos( basename( untrailingslashit( $dir ) ), 'smtp-email-log-' ) ) {
			return;
		}

		foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own empty temp folder.
	}

	/**
	 * A safe display and download name.
	 *
	 * @param mixed $name Original name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = sanitize_file_name( wp_basename( (string) $name ) );

		return '' !== $name ? substr( $name, 0, 180 ) : 'attachment';
	}

	/**
	 * Name => path for each attachment wp_mail() was given.
	 *
	 * @param mixed $attachments Array, or a newline-separated string.
	 * @return array<string,string>
	 */
	private static function paths( $attachments ) {
		if ( is_string( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		}

		$out = array();
		foreach ( is_array( $attachments ) ? $attachments : array() as $key => $path ) {
			if ( ! is_string( $path ) || '' === trim( $path ) ) {
				continue;
			}

			$path = trim( $path );
			$name = is_string( $key ) && '' !== $key ? $key : wp_basename( $path );

			// Keep two attachments with the same name apart.
			while ( isset( $out[ $name ] ) ) {
				$name = '_' . $name;
			}

			$out[ $name ] = $path;
		}

		return $out;
	}

	/**
	 * Whether a path is a file this process can read. Quiet under open_basedir.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function readable( $path ) {
		return @is_file( $path ) && @is_readable( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings.
	}

	/**
	 * Stored copies in the folder: name => path.
	 *
	 * @return array<string,string>
	 */
	private static function stored() {
		$dir = self::dir();
		$out = array();

		if ( '' === $dir ) {
			return $out;
		}

		foreach ( (array) glob( $dir . '*-*' ) as $path ) {
			$file = is_string( $path ) ? basename( $path ) : '';
			if ( preg_match( self::NAME_PATTERN, $file ) ) {
				$out[ $file ] = $path;
			}
		}

		return $out;
	}

	/**
	 * 32 random hex characters.
	 *
	 * @return string
	 */
	private static function random() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( wp_generate_password( 64, true, true ) . microtime() );
		}
	}
}
