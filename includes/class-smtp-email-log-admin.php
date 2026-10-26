<?php
/**
 * Admin side: the Content Powered > SMTP Email Log page (the log, one
 * email's details, the test email and the settings) and the Plugins-screen link.
 *
 * @package SMTP_Email_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller.
 */
final class SMTP_Email_Log_Admin {

	/**
	 * Page slug, also used as the settings group.
	 */
	const PAGE = 'smtp-email-log';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'mail-search';

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'smtp_email_log_admin';

	/**
	 * Emails per page of the log.
	 */
	const PER_PAGE = 25;

	/**
	 * Settings panels, switched in the browser: id => Lucide icon.
	 */
	const PANELS = array(
		'logging'  => 'list-checks',
		'alerts'   => 'bell-ring',
		'advanced' => 'sliders-horizontal',
	);

	/**
	 * Plugin instance.
	 *
	 * @var SMTP_Email_Log_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param SMTP_Email_Log_Plugin $plugin Plugin instance.
	 */
	public function __construct( SMTP_Email_Log_Plugin $plugin ) {
		$this->plugin = $plugin;

		SMTP_Email_Log_Menu::init();
		SMTP_Email_Log_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_args' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( SMTP_EMAIL_LOG_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	// Shared helpers ----------------------------------------------------------------

	/**
	 * URL of the plugin page.
	 *
	 * @param array<string,scalar> $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$args = array_map(
			function ( $value ) {
				return is_string( $value ) ? rawurlencode( $value ) : $value;
			},
			array_filter(
				$args,
				function ( $value ) {
					return '' !== $value && null !== $value && 0 !== $value;
				}
			)
		);

		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return SMTP_Email_Log_Icons::get( $name, $size );
	}

	/**
	 * Translated status.
	 *
	 * @param string $status Row status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'sent'    => __( 'Sent', 'smtp-email-log' ),
			'failed'  => __( 'Failed', 'smtp-email-log' ),
			'unknown' => __( 'Unknown', 'smtp-email-log' ),
			'sending' => __( 'Sending', 'smtp-email-log' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Status tag markup.
	 *
	 * @param string $status Row status.
	 * @return string
	 */
	private static function status_tag( $status ) {
		$styles = array(
			'sent'    => array( 'is-good', 'check' ),
			'failed'  => array( 'is-bad', 'x' ),
			'unknown' => array( 'is-warn', 'circle-question-mark' ),
			'sending' => array( 'is-info', 'loader' ),
		);
		$style  = isset( $styles[ $status ] ) ? $styles[ $status ] : array( 'is-none', 'circle-dot' );

		return '<span class="smtpel-tag ' . esc_attr( $style[0] ) . '">' . self::icon( $style[1], 13 ) . esc_html( self::status_label( $status ) ) . '</span>';
	}

	/**
	 * What sent an email, by name.
	 *
	 * @param string $source plugin:<folder>, theme:<folder>, core:<function> and so on.
	 * @return string
	 */
	public static function source_label( $source ) {
		$parts = explode( ':', (string) $source, 2 );
		$type  = $parts[0];
		$name  = isset( $parts[1] ) ? $parts[1] : '';

		if ( 'plugin' === $type ) {
			return 'smtp-email-log' === $name ? __( 'SMTP Email Log', 'smtp-email-log' ) : self::plugin_name( $name );
		}

		if ( 'mu-plugin' === $type ) {
			/* translators: %s: must-use plugin file or folder. */
			return sprintf( __( '%s (must-use plugin)', 'smtp-email-log' ), $name );
		}

		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $name );
			/* translators: %s: theme name. */
			return sprintf( __( '%s theme', 'smtp-email-log' ), $theme->exists() ? $theme->get( 'Name' ) : $name );
		}

		if ( 'core' === $type ) {
			$core = array(
				'retrieve_password'                => __( 'WordPress: password reset', 'smtp-email-log' ),
				'wp_new_user_notification'         => __( 'WordPress: new account', 'smtp-email-log' ),
				'wp_password_change_notification'  => __( 'WordPress: password changed', 'smtp-email-log' ),
				'wp_notify_postauthor'             => __( 'WordPress: new comment', 'smtp-email-log' ),
				'wp_notify_moderator'              => __( 'WordPress: comment to moderate', 'smtp-email-log' ),
				'wp_send_user_request'             => __( 'WordPress: privacy request', 'smtp-email-log' ),
				'wp_privacy_send_personal_data_export_email' => __( 'WordPress: personal data export', 'smtp-email-log' ),
				'_wp_privacy_send_request_confirmation_notification' => __( 'WordPress: privacy request', 'smtp-email-log' ),
				'_wp_privacy_send_erasure_fulfillment_notification' => __( 'WordPress: privacy request', 'smtp-email-log' ),
				'wp_update_user'                   => __( 'WordPress: account details changed', 'smtp-email-log' ),
				'send_confirmation_on_profile_email' => __( 'WordPress: email change', 'smtp-email-log' ),
				'update_option_new_admin_email'    => __( 'WordPress: admin email change', 'smtp-email-log' ),
				'wp_site_admin_email_change_notification' => __( 'WordPress: admin email change', 'smtp-email-log' ),
				'WP_Automatic_Updater::send_email' => __( 'WordPress: automatic updates', 'smtp-email-log' ),
				'WP_Automatic_Updater::send_debug_email' => __( 'WordPress: automatic updates', 'smtp-email-log' ),
				'WP_Automatic_Updater::send_plugin_theme_email' => __( 'WordPress: automatic updates', 'smtp-email-log' ),
				'WP_Recovery_Mode_Email_Service::send_recovery_mode_email' => __( 'WordPress: recovery mode', 'smtp-email-log' ),
			);

			return isset( $core[ $name ] ) ? $core[ $name ] : __( 'WordPress', 'smtp-email-log' );
		}

		if ( 'wp-cli' === $type ) {
			return 'WP-CLI';
		}

		return __( 'Unknown', 'smtp-email-log' );
	}

	/**
	 * An installed plugin's name from its folder.
	 *
	 * @param string $folder Folder, or file for a single-file plugin.
	 * @return string
	 */
	private static function plugin_name( $folder ) {
		static $names = null;

		if ( null === $names ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$names = array();
			foreach ( get_plugins() as $file => $data ) {
				$key           = false !== strpos( $file, '/' ) ? dirname( $file ) : $file;
				$names[ $key ] = $data['Name'];
			}
		}

		return isset( $names[ $folder ] ) ? $names[ $folder ] : $folder;
	}

	/**
	 * What kind of request sent an email.
	 *
	 * @param array<string,mixed> $context Row context.
	 * @return string
	 */
	public static function context_label( array $context ) {
		$labels = array(
			'front'  => __( 'A page or form on the site', 'smtp-email-log' ),
			'admin'  => __( 'A wp-admin page', 'smtp-email-log' ),
			'ajax'   => __( 'An AJAX request', 'smtp-email-log' ),
			'rest'   => __( 'A REST API request', 'smtp-email-log' ),
			'cron'   => __( 'A scheduled task (WP-Cron)', 'smtp-email-log' ),
			'cli'    => __( 'A WP-CLI command', 'smtp-email-log' ),
			'xmlrpc' => __( 'An XML-RPC request', 'smtp-email-log' ),
		);
		$type   = isset( $context['type'] ) ? (string) $context['type'] : '';

		return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
	}

	/**
	 * How an email was sent, in one line.
	 *
	 * @param mixed $transport Row transport.
	 * @return string
	 */
	public static function transport_label( $transport ) {
		$transport = is_array( $transport ) ? $transport : array();
		$mailer    = isset( $transport['mailer'] ) ? strtolower( (string) $transport['mailer'] ) : '';

		if ( 'smtp' === $mailer ) {
			$host  = isset( $transport['host'] ) ? (string) $transport['host'] : '';
			$port  = isset( $transport['port'] ) ? (int) $transport['port'] : 0;
			$parts = array( 'SMTP ' . $host . ( $port ? ':' . $port : '' ) );

			if ( ! empty( $transport['user'] ) ) {
				/* translators: %s: shortened SMTP username. */
				$parts[] = sprintf( __( 'logged in as %s', 'smtp-email-log' ), $transport['user'] );
			} elseif ( empty( $transport['auth'] ) ) {
				$parts[] = __( 'no login', 'smtp-email-log' );
			}

			$secure = isset( $transport['secure'] ) ? strtolower( (string) $transport['secure'] ) : '';
			if ( 'tls' === $secure ) {
				$parts[] = 'STARTTLS';
			} elseif ( 'ssl' === $secure ) {
				$parts[] = 'SSL';
			} elseif ( ! empty( $transport['auto'] ) ) {
				$parts[] = __( 'TLS if offered', 'smtp-email-log' );
			} else {
				$parts[] = __( 'no encryption', 'smtp-email-log' );
			}

			return implode( ' · ', $parts );
		}

		$names = array(
			'mail'     => __( 'PHP mail()', 'smtp-email-log' ),
			'sendmail' => 'Sendmail',
			'qmail'    => 'Qmail',
		);

		if ( isset( $names[ $mailer ] ) ) {
			return $names[ $mailer ];
		}

		// SMTP plugins name their API connections here (sendgrid, mailgun...).
		return '' !== $mailer ? ucfirst( $mailer ) : '';
	}

	/**
	 * "5 mins ago", and the full date for a tooltip.
	 *
	 * @param string $gmt MySQL datetime in UTC.
	 * @return array{0:string,1:string}
	 */
	private static function when( $gmt ) {
		$time = (int) strtotime( $gmt . ' UTC' );

		return array(
			/* translators: %s: time since, e.g. "5 mins". */
			sprintf( __( '%s ago', 'smtp-email-log' ), human_time_diff( $time ) ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ),
		);
	}

	/**
	 * A stored address list, shortened for a table cell.
	 *
	 * @param string $list Addresses.
	 * @param int    $show How many to show.
	 * @return string
	 */
	private static function short_list( $list, $show = 2 ) {
		$all = SMTP_Email_Log_Resend::split( (string) $list );

		if ( ! $all ) {
			return '';
		}

		$text = implode( ', ', array_slice( $all, 0, $show ) );
		if ( count( $all ) > $show ) {
			/* translators: %s: number of further recipients. */
			$text .= ' ' . sprintf( __( '+%s more', 'smtp-email-log' ), number_format_i18n( count( $all ) - $show ) );
		}

		return $text;
	}

	/**
	 * Shorten text for a table cell. Plain text: an address in angle
	 * brackets in an SMTP error isn't a tag.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Most characters.
	 * @return string
	 */
	private static function excerpt( $text, $chars ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );

		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, $chars, '…', 'UTF-8' ) : substr( $text, 0, $chars );
	}

	/**
	 * A sandboxed HTML document for the preview iframe.
	 *
	 * The iframe gets `sandbox` without allow-scripts or allow-same-origin,
	 * so nothing in an email can run script or reach wp-admin, and the
	 * Content-Security-Policy below blocks every outside request (tracking
	 * pixels included) until "Load images" is clicked. Scripts, frames,
	 * forms, event handlers and javascript: links are also stripped first,
	 * as a second layer.
	 *
	 * @param string $html Email HTML.
	 * @return string
	 */
	public static function preview_document( $html ) {
		// Linear work only (see SMTP_Email_Log_Log::plain_text()): scripts are
		// cut by string search, and tag regexes only see up to the last ">".
		$source = (string) $html;
		$parts  = SMTP_Email_Log_Log::split_at_last_tag( SMTP_Email_Log_Log::strip_blocks( $source, array( 'script' ) ) );
		$html   = preg_replace( '#</?(?:script|iframe|frame|frameset|object|embed|applet|meta|link|base|form|input|button|textarea|select)\b[^>]*>#i', '', $parts[0] );
		$html   = null === $html ? null : preg_replace_callback(
			'#<[a-z][^>]*>#i',
			function ( $tag ) {
				$tag = (string) preg_replace( '#\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $tag[0] );
				$tag = (string) preg_replace( '#(\s(?:href|src|action|xlink:href|background)\s*=\s*["\']?)\s*(?:javascript|vbscript|data:text)[^"\'\s>]*#i', '$1#', $tag );

				// Images that aren't inline wait for "Load images" (the CSP below blocks them too).
				if ( preg_match( '#^<img\b#i', $tag ) ) {
					$tag = (string) preg_replace( '#\s(src|srcset)\s*=\s*(["\']?)(?!\s*(?:data:|cid:))#i', ' data-smtpel-$1=$2', $tag );
				}

				return $tag;
			},
			$html
		);

		// A message built to defeat the cleanup is shown as its source instead.
		// The tail after the last ">" holds no complete tag; keep it as text.
		if ( null === $html ) {
			$html = '<pre style="white-space:pre-wrap">' . esc_html( $source ) . '</pre>';
		} else {
			$html .= str_replace( '<', '&lt;', $parts[1] );
		}

		return '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; font-src data:; img-src data: cid:; form-action \'none\'">'
			. '<meta name="referrer" content="no-referrer"><base target="_blank">'
			. '<style>body{margin:0;padding:16px;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327;background:#fff}img{max-width:100%;height:auto}</style>'
			. '</head><body>' . $html . '</body></html>';
	}

	/**
	 * Notice keys this page puts in its URL after an action; WordPress
	 * removes them from the address bar once the notice is shown.
	 *
	 * @param string[] $args Removable query args.
	 * @return string[]
	 */
	public function removable_args( $args ) {
		return array_merge( (array) $args, array( 'smtpel_done', 'smtpel_n', 'smtpel_failed', 'smtpel_over', 'smtpel_id' ) );
	}

	// Menu, settings and assets ----------------------------------------------------

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Email Log', 'smtp-email-log' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'tab' => 'settings' ) ) ), esc_html__( 'Settings', 'smtp-email-log' ) )
		);

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu, with a count of
	 * failures nobody has looked at yet.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = SMTP_Email_Log_Icons::get( self::ICON, 17, 'smtp-email-log-menu-icon' );
		$menu_title = $icon . '<span class="smtp-email-log-menu-label">' . esc_html__( 'SMTP Email Log', 'smtp-email-log' ) . '</span>';
		$unseen     = SMTP_Email_Log_Alerts::unseen();

		if ( $unseen > 0 && $this->plugin->get_setting( 'badge' ) ) {
			$menu_title .= SMTP_Email_Log_Alerts::badge( $unseen );
		}

		$this->hook_suffix = add_submenu_page(
			SMTP_Email_Log_Menu::SLUG,
			__( 'SMTP Email Log', 'smtp-email-log' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
			add_action( 'load-' . $this->hook_suffix, array( $this, 'on_load' ) );
		}
	}

	/**
	 * Before the page is drawn: settle stale emails, and once the log is
	 * open, count its failures as seen and take the count off the menu
	 * already built for this page load.
	 *
	 * @return void
	 */
	public function on_load() {
		global $menu, $submenu;

		if ( ! current_user_can( 'manage_options' ) || ! in_array( self::view(), array( 'log', 'entry' ), true ) ) {
			return;
		}

		SMTP_Email_Log_Log::mark_stale();
		SMTP_Email_Log_Alerts::mark_seen();

		$strip = function ( $title ) {
			return is_string( $title ) ? (string) preg_replace( '#\s*<span class="awaiting-mod count-\d+ smtpel-badge">.*?</span></span>#s', '', $title ) : $title;
		};

		foreach ( is_array( $menu ) ? $menu : array() as $position => $item ) {
			if ( isset( $item[0] ) ) {
				$menu[ $position ][0] = $strip( $item[0] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Removing our own count bubble.
			}
		}

		if ( isset( $submenu[ SMTP_Email_Log_Menu::SLUG ] ) && is_array( $submenu[ SMTP_Email_Log_Menu::SLUG ] ) ) {
			foreach ( $submenu[ SMTP_Email_Log_Menu::SLUG ] as $position => $item ) {
				if ( isset( $item[0] ) ) {
					$submenu[ SMTP_Email_Log_Menu::SLUG ][ $position ][0] = $strip( $item[0] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Removing our own count bubble.
				}
			}
		}
	}

	/**
	 * Mark the page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' smtpel-page' : $classes;
	}

	/**
	 * Register the setting. The settings panels are switched in the browser,
	 * so one form saves them all at once.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			SMTP_Email_Log_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => SMTP_Email_Log_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$defaults = SMTP_Email_Log_Plugin::defaults();
		$current  = $this->plugin->get_settings();
		$flag     = function ( $key ) use ( $in ) {
			return empty( $in[ $key ] ) ? 0 : 1;
		};
		$number   = function ( $key, $min, $max ) use ( $in, $defaults ) {
			$value = isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (int) $in[ $key ] : (int) $defaults[ $key ];
			return max( $min, min( $max, $value ) );
		};
		$choice   = function ( $key, array $allowed ) use ( $in, $defaults ) {
			return isset( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ? $in[ $key ] : $defaults[ $key ];
		};
		// options.php has already unslashed the values.
		$text = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '';
		};

		$exclude = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text( 'exclude' ) ) as $line ) {
			$line = substr( trim( sanitize_text_field( $line ) ), 0, 200 );
			if ( '' !== $line && ! in_array( $line, $exclude, true ) ) {
				$exclude[] = $line;
			}
		}

		$out = array(
			'enabled'          => $flag( 'enabled' ),
			'log_mode'         => $choice( 'log_mode', array( 'all', 'failed' ) ),
			'content'          => $choice( 'content', array( 'all', 'failed', 'none' ) ),
			'hide_secrets'     => self::secrets_locked() ? 1 : $flag( 'hide_secrets' ),
			'keep_attachments' => $flag( 'keep_attachments' ),
			'attachment_mb'    => $number( 'attachment_mb', 1, 50 ),
			'exclude'          => implode( "\n", array_slice( $exclude, 0, 50 ) ),
			'retention_sent'   => $number( 'retention_sent', 0, 3650 ),
			'retention_failed' => $number( 'retention_failed', 0, 3650 ),
			'max_rows'         => $number( 'max_rows', 100, 1000000 ),
			'notice'           => $flag( 'notice' ),
			'badge'            => $flag( 'badge' ),
			'webhook_url'      => '',
			'webhook_cooldown' => $number( 'webhook_cooldown', 5, 1440 ),
			'transcript'       => $choice( 'transcript', array( 'failed', 'all', 'off' ) ),
			'smtp_timeout'     => $number( 'smtp_timeout', 0, 300 ),
			'keep_data'        => $flag( 'keep_data' ),
		);

		// The webhook must be https on a public host; a bad entry keeps the old one.
		$url = esc_url_raw( trim( $text( 'webhook_url' ) ) );
		if ( '' === $url ) {
			$out['webhook_url'] = '';
		} elseif ( SMTP_Email_Log_Alerts::valid_url( $url ) ) {
			$out['webhook_url'] = $url;
		} else {
			$out['webhook_url'] = (string) $current['webhook_url'];
			self::warn( __( 'The webhook address has to start with https:// and point at a public server, so it wasn\'t changed.', 'smtp-email-log' ) );
		}

		return $out;
	}

	/**
	 * Whether this user may not switch off hidden login links. On a
	 * multisite network a site administrator isn't trusted with every
	 * account on the network, and password reset emails for any network
	 * user can be sent from (and logged on) their site.
	 *
	 * @return bool
	 */
	private static function secrets_locked() {
		return is_multisite() && ! current_user_can( 'manage_network_options' );
	}

	/**
	 * Add a warning to the save. WordPress only says "Settings saved." when
	 * nothing else was said, so say it too: the other settings were saved.
	 * Once per request, as WordPress can sanitize a value twice.
	 *
	 * @param string $message Warning.
	 * @return void
	 */
	private static function warn( $message ) {
		static $said = array();

		if ( isset( $said[ $message ] ) ) {
			return;
		}

		if ( ! $said ) {
			add_settings_error( self::PAGE, 'smtpel-saved', __( 'Settings saved.', 'smtp-email-log' ), 'success' );
		}

		$said[ $message ] = true;
		add_settings_error( self::PAGE, 'smtpel-warning-' . count( $said ), $message, 'warning' );
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="smtp-email-log-menu-icon-style">'
			. '#adminmenu a:has(>.smtp-email-log-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .smtp-email-log-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .smtp-email-log-menu-icon,#adminmenu a:hover .smtp-email-log-menu-icon,#adminmenu a:focus .smtp-email-log-menu-icon{opacity:1}'
			. '#adminmenu a:has(>.smtp-email-log-menu-icon) .smtpel-badge{margin-left:auto}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'smtp-email-log-admin', SMTP_EMAIL_LOG_URL . 'assets/admin.css', array(), SMTP_EMAIL_LOG_VERSION );
		wp_enqueue_script( 'smtp-email-log-admin', SMTP_EMAIL_LOG_URL . 'assets/admin.js', array(), SMTP_EMAIL_LOG_VERSION, true );

		wp_localize_script(
			'smtp-email-log-admin',
			'smtp_email_log_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'sending'       => __( 'Sending...', 'smtp-email-log' ),
					'testing'       => __( 'Testing...', 'smtp-email-log' ),
					'failed'        => __( 'Something went wrong. Reload the page and try again.', 'smtp-email-log' ),
					'resendConfirm' => __( 'Send this email again to its original recipients?', 'smtp-email-log' ),
					'bulkDelete'    => __( 'Delete the selected emails from the log? This can\'t be undone.', 'smtp-email-log' ),
					'bulkResend'    => __( 'Send the selected emails again to their original recipients? At most 20 are sent at a time.', 'smtp-email-log' ),
					'bulkNone'      => __( 'Select some emails and an action first.', 'smtp-email-log' ),
					'copied'        => __( 'Copied', 'smtp-email-log' ),
					'copy'          => __( 'Copy', 'smtp-email-log' ),
					'openLog'       => __( 'Open it in the log', 'smtp-email-log' ),
					'conversation'  => __( 'SMTP conversation', 'smtp-email-log' ),
					'via'           => __( 'Sent through', 'smtp-email-log' ),
					'reply'         => __( 'Server reply', 'smtp-email-log' ),
					'error'         => __( 'Error', 'smtp-email-log' ),
				),
			)
		);
	}

	// Page -------------------------------------------------------------------------

	/**
	 * Which view to show.
	 *
	 * @return string log, entry, test or settings.
	 */
	private static function view() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		if ( isset( $_GET['view'] ) && absint( $_GET['view'] ) > 0 ) {
			return 'entry';
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable

		return in_array( $tab, array( 'test', 'settings' ), true ) ? $tab : 'log';
	}

	/**
	 * Render the page: a header with the tabs, then the current view.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view = self::view();

		$this->render_header( $view );
		?>
		<div class="wrap smtpel-wrap">
			<hr class="wp-header-end">
			<?php
			if ( 'settings' === $view ) {
				// Pages outside the Settings menu have to show "Settings saved." themselves.
				settings_errors();
			}

			$this->render_result_notice();

			if ( ! $this->plugin->is_enabled() && 'settings' !== $view ) {
				$this->render_off_notice();
			}

			if ( 'entry' === $view ) {
				$this->render_entry( isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
			} elseif ( 'test' === $view ) {
				$this->render_test();
			} elseif ( 'settings' === $view ) {
				$this->render_settings();
			} else {
				$this->render_log();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The page header: icon, title, version and tabs.
	 *
	 * @param string $view Current view.
	 * @return void
	 */
	private function render_header( $view ) {
		$settings_url = self::url( array( 'tab' => 'settings' ) );
		$tabs         = array(
			'log'      => array( 'inbox', __( 'Email Log', 'smtp-email-log' ), self::url() ),
			'test'     => array( 'send', __( 'Test Email', 'smtp-email-log' ), self::url( array( 'tab' => 'test' ) ) ),
			'logging'  => array( self::PANELS['logging'], __( 'Settings', 'smtp-email-log' ), $settings_url . '#logging' ),
			'alerts'   => array( self::PANELS['alerts'], __( 'Alerts', 'smtp-email-log' ), $settings_url . '#alerts' ),
			'advanced' => array( self::PANELS['advanced'], __( 'Advanced', 'smtp-email-log' ), $settings_url . '#advanced' ),
		);
		$active       = 'entry' === $view ? 'log' : $view;
		$unseen       = SMTP_Email_Log_Alerts::unseen();
		?>
		<div class="smtpel-header">
			<div class="smtpel-header-title">
				<span class="smtpel-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="smtpel-version"><?php echo esc_html( 'v' . SMTP_EMAIL_LOG_VERSION ); ?></span>
			</div>
			<nav class="smtpel-tabs" aria-label="<?php esc_attr_e( 'Email log sections', 'smtp-email-log' ); ?>">
				<?php foreach ( $tabs as $id => $tab ) : ?>
					<?php $is_panel = 'settings' === $view && array_key_exists( $id, self::PANELS ); ?>
					<a href="<?php echo esc_url( $tab[2] ); ?>" class="smtpel-tab<?php echo $id === $active ? ' is-active' : ''; ?>"<?php echo $is_panel ? ' data-smtpel-tab="' . esc_attr( $id ) . '"' : ''; ?><?php echo $id === $active ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $tab[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $tab[1] ); ?>
						<?php if ( 'log' === $id && $unseen > 0 && 'log' !== $active ) : ?>
							<span class="smtpel-tab-count"><?php echo esc_html( number_format_i18n( $unseen ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Notice for the result of an action (from the URL's fixed codes).
	 *
	 * @return void
	 */
	private function render_result_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only: fixed codes and numbers.
		$done   = isset( $_GET['smtpel_done'] ) ? sanitize_key( wp_unslash( $_GET['smtpel_done'] ) ) : '';
		$count  = isset( $_GET['smtpel_n'] ) ? absint( $_GET['smtpel_n'] ) : 0;
		$failed = isset( $_GET['smtpel_failed'] ) ? absint( $_GET['smtpel_failed'] ) : 0;
		$over   = isset( $_GET['smtpel_over'] ) ? absint( $_GET['smtpel_over'] ) : 0;
		$new_id = isset( $_GET['smtpel_id'] ) ? absint( $_GET['smtpel_id'] ) : 0;
		// phpcs:enable

		if ( '' === $done ) {
			return;
		}

		$type = 'success';
		$link = '';

		switch ( $done ) {
			case 'deleted':
				/* translators: %s: number of emails. */
				$text = sprintf( _n( '%s email deleted from the log.', '%s emails deleted from the log.', $count, 'smtp-email-log' ), number_format_i18n( $count ) );
				break;
			case 'nothing':
				$type = 'warning';
				$text = __( 'Select some emails and an action first.', 'smtp-email-log' );
				break;
			case 'bulk_resent':
				$type = $failed ? 'warning' : 'success';
				/* translators: %s: number of emails. */
				$text = sprintf( _n( '%s email resent.', '%s emails resent.', $count, 'smtp-email-log' ), number_format_i18n( $count ) );
				if ( $failed ) {
					/* translators: %s: number of emails. */
					$text .= ' ' . sprintf( _n( '%s failed again; it\'s at the top of the log.', '%s failed again; they\'re at the top of the log.', $failed, 'smtp-email-log' ), number_format_i18n( $failed ) );
				}
				if ( $over ) {
					/* translators: %s: number of emails. */
					$text .= ' ' . sprintf( _n( '%s more was selected; resend it in another batch.', '%s more were selected; resend them in another batch.', $over, 'smtp-email-log' ), number_format_i18n( $over ) );
				}
				break;
			case 'resent':
				$text = __( 'Email sent again.', 'smtp-email-log' );
				$link = $new_id ? self::url( array( 'view' => $new_id ) ) : '';
				break;
			case 'resend_failed':
				$type = 'error';
				$text = __( 'The email failed again.', 'smtp-email-log' );
				$link = $new_id ? self::url( array( 'view' => $new_id ) ) : '';
				break;
			case 'emptied':
				$text = __( 'The log is empty.', 'smtp-email-log' );
				break;
			default:
				$type = 'error';
				$text = SMTP_Email_Log_Resend::reason( $done );
		}

		if ( '' === $text ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p>
				<?php echo esc_html( $text ); ?>
				<?php if ( '' !== $link ) : ?>
					<a href="<?php echo esc_url( $link ); ?>"><?php echo 'error' === $type ? esc_html__( 'See what went wrong', 'smtp-email-log' ) : esc_html__( 'View the new log entry', 'smtp-email-log' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Warning while logging is off.
	 *
	 * @return void
	 */
	private function render_off_notice() {
		$text = defined( 'SMTP_EMAIL_LOG_DISABLED' ) && SMTP_EMAIL_LOG_DISABLED
			? __( 'Logging is switched off by SMTP_EMAIL_LOG_DISABLED in wp-config.php, so new emails aren\'t being recorded.', 'smtp-email-log' )
			: __( 'Logging is switched off, so new emails aren\'t being recorded. Turn it on in the Save box on the Settings tab.', 'smtp-email-log' );

		echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * A callout line.
	 *
	 * @param string $icon    Lucide icon.
	 * @param string $text    Plain text.
	 * @param string $variant "", "is-warning", "is-error" or "is-success".
	 * @param string $link    Optional link URL.
	 * @param string $label   Link text.
	 * @return void
	 */
	private static function callout( $icon, $text, $variant = '', $link = '', $label = '' ) {
		echo '<p class="smtpel-callout ' . esc_attr( $variant ) . '">' . self::icon( $icon, 16 ) . '<span>' . esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		if ( '' !== $link ) {
			echo ' <a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</span></p>';
	}

	/**
	 * A box.
	 *
	 * @param string   $icon    Lucide icon.
	 * @param string   $title   Title.
	 * @param callable $content Prints the box's content.
	 * @param string   $class   Extra classes.
	 * @param string   $attrs   Extra attributes, already escaped.
	 * @return void
	 */
	private static function box( $icon, $title, $content, $class = '', $attrs = '' ) {
		?>
		<div class="postbox smtpel-box <?php echo esc_attr( $class ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by the callers. ?>>
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $title ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php call_user_func( $content ); ?>
			</div>
		</div>
		<?php
	}

	// The log --------------------------------------------------------------------------

	/**
	 * The log: totals, filters, search and the list of emails.
	 *
	 * @return void
	 */
	private function render_log() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filtering and paging.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$status = in_array( $status, array( 'sent', 'failed', 'unknown', 'sending' ), true ) ? $status : '';
		$search = isset( $_GET['s'] ) ? substr( trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ), 0, 200 ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$args  = array(
			'status' => $status,
			'search' => $search,
		);
		$total = SMTP_Email_Log_Log::count( $args );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = SMTP_Email_Log_Log::query( $args + array( 'limit' => self::PER_PAGE, 'offset' => ( $paged - 1 ) * self::PER_PAGE ) );

		self::box(
			'chart-column',
			__( 'Overview', 'smtp-email-log' ),
			array( $this, 'render_overview' ),
			'smtpel-overview'
		);

		self::box(
			'inbox',
			__( 'Emails', 'smtp-email-log' ),
			function () use ( $rows, $status, $search, $paged, $pages, $total ) {
				$this->render_toolbar( $status, $search );
				$this->render_rows( $rows, $status, $search, $paged );
				$this->pagination( $paged, $pages, $total, $status, $search );
			},
			'smtpel-emails'
		);
	}

	/**
	 * Log: the stat tiles and the latest problem.
	 *
	 * @return void
	 */
	public function render_overview() {
		$totals = SMTP_Email_Log_Log::totals();
		$month  = $totals['sent_month'] + $totals['failed_month'];
		$rate   = $month > 0 ? $totals['sent_month'] / $month * 100 : null;
		$tiles  = array(
			array( 'send', number_format_i18n( $totals['sent_today'] ), __( 'Sent today', 'smtp-email-log' ), 'good' ),
			array( 'mail-x', number_format_i18n( $totals['failed_today'] ), __( 'Failed today', 'smtp-email-log' ), $totals['failed_today'] ? 'bad' : '' ),
			array( 'mails', number_format_i18n( $totals['sent_month'] ), __( 'Sent, last 30 days', 'smtp-email-log' ), '' ),
			array( 'mail-warning', number_format_i18n( $totals['failed_month'] ), __( 'Failed, last 30 days', 'smtp-email-log' ), $totals['failed_month'] ? 'bad' : '' ),
			array( 'gauge', null === $rate ? '–' : number_format_i18n( $rate, $rate < 100 && $rate >= 99.95 ? 2 : ( $rate < 100 ? 1 : 0 ) ) . '%', __( 'Delivered to the mail server, 30 days', 'smtp-email-log' ), null === $rate ? '' : ( $rate >= 99 ? 'good' : 'warn' ) ),
		);
		?>
		<div class="smtpel-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="smtpel-stat<?php echo '' !== $tile[3] ? ' is-' . esc_attr( $tile[3] ) : ''; ?>">
					<span class="smtpel-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="smtpel-stat-num"><?php echo esc_html( $tile[1] ); ?></span>
					<span class="smtpel-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		$latest = SMTP_Email_Log_Log::latest_failure();

		if ( $latest && strtotime( $latest['created_at'] . ' UTC' ) > time() - 7 * DAY_IN_SECONDS ) {
			$hint = SMTP_Email_Log_Errors::explain( (string) $latest['error'] );
			$when = self::when( $latest['created_at'] );
			self::callout(
				$hint['icon'],
				/* translators: 1: time since, 2: explanation, 3: error message. */
				sprintf( __( 'Latest failure, %1$s: %2$s. "%3$s"', 'smtp-email-log' ), $when[0], $hint['title'], self::excerpt( (string) $latest['error'], 160 ) ),
				'is-error',
				self::url( array( 'view' => (int) $latest['id'] ) ),
				__( 'See what to do', 'smtp-email-log' )
			);
		} elseif ( $month > 0 && 0 === $totals['failed_month'] ) {
			self::callout( 'circle-check', __( 'Every email in the last 30 days was accepted by the mail server.', 'smtp-email-log' ), 'is-success' );
		}

		if ( 'failed' === $this->plugin->get_setting( 'log_mode' ) ) {
			self::callout( 'funnel', __( 'Only failed emails are kept in the list below (sent ones are still counted above). Change this on the Settings tab.', 'smtp-email-log' ) );
		}
	}

	/**
	 * Log: filter links, search and export.
	 *
	 * @param string $status Current status filter.
	 * @param string $search Current search.
	 * @return void
	 */
	private function render_toolbar( $status, $search ) {
		$counts  = SMTP_Email_Log_Log::status_counts();
		$filters = array(
			''        => array( __( 'All', 'smtp-email-log' ), $counts['all'] ),
			'sent'    => array( __( 'Sent', 'smtp-email-log' ), $counts['sent'] ),
			'failed'  => array( __( 'Failed', 'smtp-email-log' ), $counts['failed'] ),
			'unknown' => array( __( 'Unknown', 'smtp-email-log' ), $counts['unknown'] ),
		);

		if ( $counts['sending'] > 0 ) {
			$filters['sending'] = array( __( 'Sending', 'smtp-email-log' ), $counts['sending'] );
		}

		$export = wp_nonce_url(
			add_query_arg(
				array_map(
					'rawurlencode',
					array_filter(
						array(
							'action' => SMTP_Email_Log_Actions::EXPORT,
							'status' => $status,
							's'      => $search,
						)
					)
				),
				admin_url( 'admin-post.php' )
			),
			SMTP_Email_Log_Actions::EXPORT
		);
		?>
		<div class="smtpel-toolbar">
			<div class="smtpel-filters">
				<?php foreach ( $filters as $key => $filter ) : ?>
					<?php
					if ( 'unknown' === $key && 0 === $filter[1] && 'unknown' !== $status ) {
						continue;
					}
					?>
					<a href="<?php echo esc_url( self::url( array( 'status' => $key, 's' => $search ) ) ); ?>" class="<?php echo $key === $status ? 'is-active' : ''; ?><?php echo 'failed' === $key && $filter[1] > 0 ? ' is-bad' : ''; ?>"<?php echo $key === $status ? ' aria-current="true"' : ''; ?>>
						<?php echo esc_html( $filter[0] ); ?>
						<span class="smtpel-filter-count"><?php echo esc_html( number_format_i18n( $filter[1] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<form class="smtpel-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( '' !== $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php endif; ?>
				<label class="screen-reader-text" for="smtpel-search"><?php esc_html_e( 'Search emails', 'smtp-email-log' ); ?></label>
				<input type="search" id="smtpel-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Address, subject, message or error', 'smtp-email-log' ); ?>">
				<button type="submit" class="button"><?php echo self::icon( 'search', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Search', 'smtp-email-log' ); ?></button>
			</form>

			<a class="button smtpel-export" href="<?php echo esc_url( $export ); ?>"><?php echo self::icon( 'download', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Export CSV', 'smtp-email-log' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Log: the table of emails, with bulk actions.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows.
	 * @param string                         $status Current status filter.
	 * @param string                         $search Current search.
	 * @param int                            $paged  Current page.
	 * @return void
	 */
	private function render_rows( array $rows, $status, $search, $paged ) {
		if ( ! $rows ) {
			echo '<div class="smtpel-empty-state">';
			if ( '' !== $search ) {
				echo self::icon( 'search-x', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				echo '<p>' . esc_html__( 'No emails match that search.', 'smtp-email-log' ) . '</p>';
			} elseif ( 'failed' === $status ) {
				echo '<span class="is-good">' . self::icon( 'circle-check', 28 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				echo '<p>' . esc_html__( 'No failed emails in the log.', 'smtp-email-log' ) . '</p>';
			} elseif ( '' !== $status ) {
				echo self::icon( 'inbox', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				echo '<p>' . esc_html__( 'No emails with this status.', 'smtp-email-log' ) . '</p>';
			} else {
				echo self::icon( 'mail-plus', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				echo '<p>' . esc_html__( 'No emails logged yet. Every email your site sends from now on will appear here, whether it\'s sent or not.', 'smtp-email-log' ) . '</p>';
				echo '<p><a class="button button-primary" href="' . esc_url( self::url( array( 'tab' => 'test' ) ) ) . '">' . self::icon( 'send', 15 ) . esc_html__( 'Send a test email', 'smtp-email-log' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			}
			echo '</div>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smtpel-bulk-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( SMTP_Email_Log_Actions::BULK ); ?>">
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
			<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>">
			<?php wp_nonce_field( SMTP_Email_Log_Actions::BULK ); ?>

			<div class="smtpel-bulkbar">
				<label class="screen-reader-text" for="smtpel-bulk-action"><?php esc_html_e( 'Bulk action', 'smtp-email-log' ); ?></label>
				<select name="bulk_action" id="smtpel-bulk-action">
					<option value=""><?php esc_html_e( 'Bulk actions', 'smtp-email-log' ); ?></option>
					<option value="resend"><?php esc_html_e( 'Resend', 'smtp-email-log' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'smtp-email-log' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'smtp-email-log' ); ?></button>
			</div>

			<table class="widefat striped smtpel-table smtpel-log">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><label class="screen-reader-text" for="smtpel-select-all"><?php esc_html_e( 'Select all', 'smtp-email-log' ); ?></label><input type="checkbox" id="smtpel-select-all"></td>
						<th scope="col" class="smtpel-col-status"><?php esc_html_e( 'Status', 'smtp-email-log' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'smtp-email-log' ); ?></th>
						<th scope="col" class="smtpel-col-source"><?php esc_html_e( 'Sent by', 'smtp-email-log' ); ?></th>
						<th scope="col" class="smtpel-col-date"><?php esc_html_e( 'When', 'smtp-email-log' ); ?></th>
						<th scope="col" class="smtpel-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'smtp-email-log' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$id      = (int) $row['id'];
						$when    = self::when( $row['created_at'] );
						$link    = self::url( array( 'view' => $id ) );
						$meta    = $row['meta'];
						$files   = is_array( $row['attachments'] ) ? count( $row['attachments'] ) : 0;
						$subject = '' !== trim( (string) $row['subject'] ) ? (string) $row['subject'] : __( '(no subject)', 'smtp-email-log' );
						?>
						<tr class="is-<?php echo esc_attr( (string) $row['status'] ); ?>">
							<th scope="row" class="check-column">
								<label class="screen-reader-text" for="smtpel-row-<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $subject ); ?></label>
								<input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $id ); ?>" id="smtpel-row-<?php echo esc_attr( (string) $id ); ?>">
							</th>
							<td class="smtpel-col-status"><?php echo self::status_tag( (string) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?></td>
							<td class="smtpel-col-email">
								<span class="smtpel-mobile-status"><?php echo self::status_tag( (string) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?></span>
								<a class="smtpel-subject" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( self::excerpt( $subject, 140 ) ); ?></a>
								<span class="smtpel-muted">
									<?php
									/* translators: %s: recipients. */
									echo esc_html( sprintf( __( 'To: %s', 'smtp-email-log' ), '' !== self::short_list( $row['to_addr'] ) ? self::short_list( $row['to_addr'] ) : '–' ) );
									?>
									<?php if ( $files ) : ?>
										<span class="smtpel-chip" title="<?php esc_attr_e( 'Attachments', 'smtp-email-log' ); ?>"><?php echo self::icon( 'paperclip', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( number_format_i18n( $files ) ); ?></span>
									<?php endif; ?>
									<?php if ( isset( $meta['kind'] ) && 'test' === $meta['kind'] ) : ?>
										<span class="smtpel-chip"><?php esc_html_e( 'Test', 'smtp-email-log' ); ?></span>
									<?php elseif ( ! empty( $row['resent_from'] ) ) : ?>
										<span class="smtpel-chip"><?php echo self::icon( 'repeat', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Resend', 'smtp-email-log' ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $meta['resends'] ) ) : ?>
										<span class="smtpel-chip is-good"><?php echo self::icon( 'check', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Resent', 'smtp-email-log' ); ?></span>
									<?php endif; ?>
								</span>
								<?php if ( 'failed' === $row['status'] && '' !== (string) $row['error'] ) : ?>
									<span class="smtpel-error-line"><?php echo esc_html( self::excerpt( (string) $row['error'], 150 ) ); ?></span>
								<?php endif; ?>
							</td>
							<td class="smtpel-col-source"><?php echo esc_html( self::source_label( (string) $row['source'] ) ); ?></td>
							<td class="smtpel-col-date"><span title="<?php echo esc_attr( $when[1] ); ?>"><?php echo esc_html( $when[0] ); ?></span></td>
							<td class="smtpel-col-actions">
								<a class="button button-small" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'View', 'smtp-email-log' ); ?></a>
								<?php if ( in_array( $row['status'], array( 'failed', 'unknown' ), true ) ) : ?>
									<button type="button" class="button button-small" data-smtpel-resend="<?php echo esc_attr( (string) $id ); ?>"><?php echo self::icon( 'send', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Resend', 'smtp-email-log' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php
	}

	/**
	 * Previous and next links under the table.
	 *
	 * @param int    $paged  Current page.
	 * @param int    $pages  Pages.
	 * @param int    $total  Rows.
	 * @param string $status Status filter.
	 * @param string $search Search.
	 * @return void
	 */
	private function pagination( $paged, $pages, $total, $status, $search ) {
		if ( $total < 1 ) {
			return;
		}

		$base = array(
			'status' => $status,
			's'      => $search,
		);
		?>
		<div class="smtpel-pages">
			<span class="smtpel-muted">
				<?php
				/* translators: 1: current page, 2: number of pages, 3: number of emails. */
				echo esc_html( sprintf( _n( 'Page %1$s of %2$s (%3$s email)', 'Page %1$s of %2$s (%3$s emails)', $total, 'smtp-email-log' ), number_format_i18n( $paged ), number_format_i18n( $pages ), number_format_i18n( $total ) ) );
				?>
			</span>
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $base + array( 'paged' => $paged - 1 ) ) ); ?>"><?php echo self::icon( 'chevron-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Newer', 'smtp-email-log' ); ?></a>
			<?php endif; ?>
			<?php if ( $paged < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $base + array( 'paged' => $paged + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'smtp-email-log' ); ?><?php echo self::icon( 'chevron-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	// One email ----------------------------------------------------------------------

	/**
	 * One email: what happened, the message, and resend.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	private function render_entry( $id ) {
		$row = SMTP_Email_Log_Log::get( $id );
		$back = wp_get_referer();
		$back = $back && false !== strpos( $back, 'page=' . self::PAGE ) && false === strpos( $back, 'view=' ) ? $back : self::url();
		?>
		<p class="smtpel-back">
			<a href="<?php echo esc_url( $back ); ?>"><?php echo self::icon( 'arrow-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Back to the log', 'smtp-email-log' ); ?></a>
		</p>
		<?php
		if ( ! $row ) {
			self::callout( 'search-x', __( 'That email isn\'t in the log anymore. It may have been deleted, or removed after the retention period.', 'smtp-email-log' ), 'is-warning' );
			return;
		}

		$subject = '' !== trim( (string) $row['subject'] ) ? (string) $row['subject'] : __( '(no subject)', 'smtp-email-log' );
		?>
		<div class="smtpel-entry-title">
			<?php echo self::status_tag( (string) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?>
			<h2><?php echo esc_html( $subject ); ?></h2>
		</div>

		<?php $this->render_outcome( $row ); ?>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<?php
					self::box(
						'mail-open',
						__( 'Message', 'smtp-email-log' ),
						function () use ( $row ) {
							$this->render_message( $row );
						},
						'smtpel-message-box'
					);
					?>
				</div>
				<div id="postbox-container-1" class="postbox-container">
					<?php
					self::box(
						'info',
						__( 'Details', 'smtp-email-log' ),
						function () use ( $row ) {
							$this->render_details( $row );
						}
					);

					if ( $row['attachments'] ) {
						self::box(
							'paperclip',
							__( 'Attachments', 'smtp-email-log' ),
							function () use ( $row ) {
								$this->render_attachments( $row );
							}
						);
					}

					self::box(
						'send',
						__( 'Send again', 'smtp-email-log' ),
						function () use ( $row ) {
							$this->render_resend( $row );
						}
					);

					self::box(
						'trash-2',
						__( 'Delete', 'smtp-email-log' ),
						function () use ( $row ) {
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( SMTP_Email_Log_Actions::DELETE ); ?>">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
								<?php wp_nonce_field( SMTP_Email_Log_Actions::DELETE . '_' . (int) $row['id'] ); ?>
								<p><?php esc_html_e( 'Remove this email, and any attachment copies, from the log.', 'smtp-email-log' ); ?></p>
								<button type="submit" class="button smtpel-button-danger" data-smtpel-confirm="<?php esc_attr_e( 'Delete this email from the log? This can\'t be undone.', 'smtp-email-log' ); ?>"><?php echo self::icon( 'trash-2', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Delete this email', 'smtp-email-log' ); ?></button>
							</form>
							<?php
						}
					);
					?>
				</div>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * One email: what happened to it, and what to do.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	private function render_outcome( array $row ) {
		$meta      = $row['meta'];
		$when      = self::when( $row['created_at'] );
		$transport = self::transport_label( $row['transport'] );

		if ( 'failed' === $row['status'] ) {
			$hint = SMTP_Email_Log_Errors::explain( (string) $row['error'], $row );
			?>
			<div class="smtpel-outcome is-bad">
				<span class="smtpel-outcome-icon"><?php echo self::icon( $hint['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<div class="smtpel-outcome-text">
					<h3><?php echo esc_html( $hint['title'] ); ?></h3>
					<p class="smtpel-outcome-error">
						<code><?php echo esc_html( (string) $row['error'] ); ?></code>
						<button type="button" class="button-link smtpel-copy" data-smtpel-copy="<?php echo esc_attr( (string) $row['error'] ); ?>"><?php echo self::icon( 'copy', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Copy', 'smtp-email-log' ); ?></span></button>
					</p>
					<p><?php echo esc_html( $hint['text'] ); ?></p>
					<p class="smtpel-muted">
						<?php
						if ( '' !== $transport ) {
							/* translators: 1: date, 2: how it was sent. */
							echo esc_html( sprintf( __( 'Tried %1$s through %2$s.', 'smtp-email-log' ), $when[1], $transport ) );
						} elseif ( ! empty( $meta['before_send'] ) ) {
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Stopped %s, before it was handed to a mail server.', 'smtp-email-log' ), $when[1] ) );
						} else {
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Tried %s.', 'smtp-email-log' ), $when[1] ) );
						}
						?>
						<?php esc_html_e( 'The full message is kept below, so nothing in it is lost: resend it once the problem is fixed, or forward it to yourself.', 'smtp-email-log' ); ?>
					</p>
				</div>
			</div>
			<?php
		} elseif ( 'unknown' === $row['status'] ) {
			?>
			<div class="smtpel-outcome is-warn">
				<span class="smtpel-outcome-icon"><?php echo self::icon( 'circle-question-mark', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<div class="smtpel-outcome-text">
					<h3><?php esc_html_e( 'WordPress never said whether this was sent', 'smtp-email-log' ); ?></h3>
					<p><?php esc_html_e( 'The email was handed over for sending, but no result came back. Usually the page was cut off mid-send (the mail server didn\'t answer and the request timed out), or another plugin delivers email its own way without telling WordPress. A shorter SMTP timeout on the Advanced tab makes slow servers fail properly instead. The message is kept below: resend it if it never arrived.', 'smtp-email-log' ); ?></p>
				</div>
			</div>
			<?php
		} elseif ( 'sending' === $row['status'] ) {
			self::callout( 'loader', __( 'This email is being sent right now. Reload the page in a moment for the result.', 'smtp-email-log' ) );
		} else {
			?>
			<div class="smtpel-outcome is-good">
				<span class="smtpel-outcome-icon"><?php echo self::icon( 'circle-check', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<div class="smtpel-outcome-text">
					<h3>
						<?php
						if ( ! empty( $meta['short_circuit'] ) ) {
							esc_html_e( 'Handed to another plugin, which reported it as sent', 'smtp-email-log' );
						} else {
							esc_html_e( 'Accepted by the mail server', 'smtp-email-log' );
						}
						?>
					</h3>
					<p>
						<?php
						if ( '' !== $transport ) {
							/* translators: 1: date, 2: how it was sent. */
							echo esc_html( sprintf( __( 'Sent %1$s through %2$s.', 'smtp-email-log' ), $when[1], $transport ) );
						} else {
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Sent %s.', 'smtp-email-log' ), $when[1] ) );
						}
						?>
						<?php if ( '' !== (string) $row['server_reply'] ) : ?>
							<?php
							/* translators: %s: the mail server's queue ID or reply. */
							echo esc_html( sprintf( __( 'Server reference: %s.', 'smtp-email-log' ), $row['server_reply'] ) );
							?>
						<?php endif; ?>
					</p>
					<p class="smtpel-muted"><?php esc_html_e( 'From here, delivery is up to the receiving mail server. If it didn\'t arrive, check the spam folder, and your domain\'s SPF, DKIM and DMARC records.', 'smtp-email-log' ); ?></p>
				</div>
			</div>
			<?php
		}

		if ( ! empty( $meta['rejected'] ) && is_array( $meta['rejected'] ) ) {
			/* translators: %s: email addresses. */
			self::callout( 'user-x', sprintf( __( 'The mail server refused these recipients: %s', 'smtp-email-log' ), implode( ', ', array_map( 'strval', $meta['rejected'] ) ) ), 'is-warning' );
		}
	}

	/**
	 * One email: the message, with preview, text, source, headers and SMTP tabs.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	private function render_message( array $row ) {
		$meta    = $row['meta'];
		$message = (string) $row['message'];
		$is_html = false !== strpos( strtolower( (string) $row['content_type'] ), 'html' );

		if ( ! empty( $meta['redacted'] ) ) {
			self::callout( 'eye-off', __( 'Password reset and login links in this email were hidden before it was saved, so the log can\'t be used to get into an account. A resend sends the hidden version: ask the person to request a new link instead.', 'smtp-email-log' ) );
		}
		if ( ! empty( $meta['truncated'] ) ) {
			/* translators: %s: size, like "2 MB". */
			self::callout( 'scissors', sprintf( __( 'This message was %s, so only the first 512 KB was saved.', 'smtp-email-log' ), size_format( (int) $meta['truncated'] ) ) );
		}

		$tabs = array();
		if ( '' !== $message ) {
			$tabs['preview'] = __( 'Preview', 'smtp-email-log' );
			if ( $is_html ) {
				$tabs['text']   = __( 'Plain text', 'smtp-email-log' );
				$tabs['source'] = __( 'HTML source', 'smtp-email-log' );
			}
		}
		$tabs['headers'] = __( 'Headers', 'smtp-email-log' );
		$tabs['smtp']    = __( 'SMTP conversation', 'smtp-email-log' );
		$first           = key( $tabs );
		?>
		<?php if ( '' === $message ) : ?>
			<?php
			if ( ! empty( $meta['no_body'] ) ) {
				self::callout( 'file-x', __( 'The message wasn\'t saved, because "Message content" is set to not save it. The recipients, subject and result are below.', 'smtp-email-log' ) );
			} elseif ( ! empty( $meta['body_dropped'] ) ) {
				self::callout( 'file-x', __( 'The message was removed once it was sent, because "Message content" is set to keep it for failed emails only.', 'smtp-email-log' ) );
			} else {
				self::callout( 'file-x', __( 'This email had no message.', 'smtp-email-log' ) );
			}
			?>
		<?php endif; ?>

		<div class="smtpel-msg-tabs" role="tablist">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<button type="button" role="tab" class="smtpel-msg-tab<?php echo $key === $first ? ' is-active' : ''; ?>" data-smtpel-msgtab="<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $key === $first ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>

		<?php if ( isset( $tabs['preview'] ) ) : ?>
			<div class="smtpel-msg-panel" data-smtpel-msgpanel="preview">
				<?php if ( $is_html ) : ?>
					<div class="smtpel-preview-bar">
						<span class="smtpel-muted"><?php echo self::icon( 'shield-check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Shown safely: scripts can\'t run, and outside images are blocked.', 'smtp-email-log' ); ?></span>
						<button type="button" class="button button-small" data-smtpel-images><?php echo self::icon( 'image', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Load images', 'smtp-email-log' ); ?></button>
					</div>
					<div class="smtpel-preview-frame">
						<iframe class="smtpel-preview" title="<?php esc_attr_e( 'Email preview', 'smtp-email-log' ); ?>" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="<?php echo htmlspecialchars( self::preview_document( $message ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully encoded (double_encode on) for the srcdoc attribute; shown in a sandboxed frame. ?>"></iframe>
					</div>
				<?php else : ?>
					<pre class="smtpel-pre smtpel-plain"><?php echo esc_html( $message ); ?></pre>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( isset( $tabs['text'] ) ) : ?>
			<div class="smtpel-msg-panel" data-smtpel-msgpanel="text" hidden>
				<pre class="smtpel-pre smtpel-plain"><?php echo esc_html( SMTP_Email_Log_Log::plain_text( $message, (string) $row['content_type'] ) ); ?></pre>
			</div>
			<div class="smtpel-msg-panel" data-smtpel-msgpanel="source" hidden>
				<pre class="smtpel-pre smtpel-code"><?php echo esc_html( $message ); ?></pre>
			</div>
		<?php endif; ?>

		<div class="smtpel-msg-panel" data-smtpel-msgpanel="headers"<?php echo 'headers' === $first ? '' : ' hidden'; ?>>
			<?php if ( '' !== trim( (string) $row['headers'] ) ) : ?>
				<pre class="smtpel-pre smtpel-code"><?php echo esc_html( (string) $row['headers'] ); ?></pre>
			<?php else : ?>
				<p class="smtpel-muted"><?php esc_html_e( 'The plugin that sent this email added no headers of its own.', 'smtp-email-log' ); ?></p>
			<?php endif; ?>
			<p class="smtpel-muted">
				<?php
				/* translators: %s: content type, like text/html. */
				echo esc_html( sprintf( __( 'Content type: %s', 'smtp-email-log' ), '' !== (string) $row['content_type'] ? $row['content_type'] : 'text/plain' ) );
				?>
			</p>
		</div>

		<div class="smtpel-msg-panel" data-smtpel-msgpanel="smtp" hidden>
			<?php if ( '' !== (string) $row['transcript'] ) : ?>
				<div class="smtpel-preview-bar">
					<span class="smtpel-muted"><?php echo self::icon( 'key-round', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Passwords and the message itself are never saved here.', 'smtp-email-log' ); ?></span>
					<button type="button" class="button button-small smtpel-copy" data-smtpel-copy-target="#smtpel-transcript"><?php echo self::icon( 'copy', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Copy', 'smtp-email-log' ); ?></span></button>
				</div>
				<pre class="smtpel-pre smtpel-code smtpel-transcript" id="smtpel-transcript"><?php echo esc_html( (string) $row['transcript'] ); ?></pre>
			<?php else : ?>
				<p class="smtpel-muted">
					<?php
					$mailer = isset( $row['transport']['mailer'] ) ? strtolower( (string) $row['transport']['mailer'] ) : '';
					if ( '' !== $mailer && 'smtp' !== $mailer ) {
						esc_html_e( 'This email wasn\'t sent over SMTP, so there\'s no conversation to show.', 'smtp-email-log' );
					} elseif ( 'off' === $this->plugin->get_setting( 'transcript' ) ) {
						esc_html_e( 'Saving the SMTP conversation is switched off on the Advanced tab.', 'smtp-email-log' );
					} elseif ( 'failed' === $this->plugin->get_setting( 'transcript' ) && 'sent' === $row['status'] ) {
						esc_html_e( 'The SMTP conversation is only saved for failed emails. Change that on the Advanced tab.', 'smtp-email-log' );
					} else {
						esc_html_e( 'No SMTP conversation was recorded for this email.', 'smtp-email-log' );
					}
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One email: who, when, how and from where.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	private function render_details( array $row ) {
		$when    = self::when( $row['created_at'] );
		$context = $row['context'];
		$request = array();

		if ( ! empty( $context['method'] ) || ! empty( $context['path'] ) ) {
			$request[] = trim( ( isset( $context['method'] ) ? $context['method'] : '' ) . ' ' . ( isset( $context['path'] ) ? $context['path'] : '' ) );
		}
		if ( ! empty( $context['action'] ) ) {
			/* translators: %s: AJAX action name. */
			$request[] = sprintf( __( 'action: %s', 'smtp-email-log' ), $context['action'] );
		}
		if ( ! empty( $context['hook'] ) ) {
			/* translators: %s: cron hook name. */
			$request[] = sprintf( __( 'task: %s', 'smtp-email-log' ), $context['hook'] );
		}

		$items = array(
			__( 'When', 'smtp-email-log' )     => array( $when[1] . ' (' . $when[0] . ')', false ),
			__( 'From', 'smtp-email-log' )     => array( (string) $row['from_addr'], true ),
			__( 'To', 'smtp-email-log' )       => array( (string) $row['to_addr'], true ),
			__( 'Cc', 'smtp-email-log' )       => array( (string) $row['cc_addr'], true ),
			__( 'Bcc', 'smtp-email-log' )      => array( (string) $row['bcc_addr'], true ),
			__( 'Reply-To', 'smtp-email-log' ) => array( (string) $row['reply_to'], true ),
			__( 'Sent by', 'smtp-email-log' )  => array( self::source_label( (string) $row['source'] ), false ),
			__( 'Called from', 'smtp-email-log' ) => array( ! empty( $context['file'] ) ? $context['file'] . ( ! empty( $context['line'] ) ? ':' . (int) $context['line'] : '' ) : '', true ),
			__( 'Triggered by', 'smtp-email-log' ) => array( self::context_label( $context ), false ),
			__( 'Request', 'smtp-email-log' )  => array( implode( ' · ', $request ), true ),
			__( 'Sent through', 'smtp-email-log' ) => array( self::transport_label( $row['transport'] ), false ),
			__( 'Server reference', 'smtp-email-log' ) => array( (string) $row['server_reply'], true ),
		);
		?>
		<table class="smtpel-table-kv">
			<tbody>
				<?php foreach ( $items as $label => $item ) : ?>
					<?php
					if ( '' === trim( $item[0] ) ) {
						continue;
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><?php echo $item[1] ? '<code>' . esc_html( $item[0] ) . '</code>' : esc_html( $item[0] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! empty( $row['resent_from'] ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Resend of', 'smtp-email-log' ); ?></th>
						<td><a href="<?php echo esc_url( self::url( array( 'view' => (int) $row['resent_from'] ) ) ); ?>">#<?php echo esc_html( (string) (int) $row['resent_from'] ); ?></a></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Log ID', 'smtp-email-log' ); ?></th>
					<td>#<?php echo esc_html( (string) (int) $row['id'] ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One email: its attachments, with downloads for the copies kept.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	private function render_attachments( array $row ) {
		$reasons = array(
			'size' => __( 'not kept: over the size limit', 'smtp-email-log' ),
			'full' => __( 'not kept: attachment storage is full', 'smtp-email-log' ),
			'copy' => __( 'not kept: couldn\'t be copied', 'smtp-email-log' ),
		);
		?>
		<ul class="smtpel-files">
			<?php foreach ( $row['attachments'] as $index => $item ) : ?>
				<?php
				if ( ! is_array( $item ) ) {
					continue;
				}
				$name = SMTP_Email_Log_Attachments::clean_name( isset( $item['name'] ) ? $item['name'] : '' );
				$size = ! empty( $item['size'] ) ? size_format( (int) $item['size'] ) : '';
				$path = ! empty( $item['file'] ) ? SMTP_Email_Log_Attachments::path( $item['file'] ) : '';
				?>
				<li>
					<?php echo self::icon( 'file', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span class="smtpel-file-name">
						<?php if ( '' !== $path ) : ?>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => SMTP_Email_Log_Actions::DOWNLOAD, 'id' => (int) $row['id'], 'n' => (int) $index ), admin_url( 'admin-post.php' ) ), SMTP_Email_Log_Actions::DOWNLOAD . '_' . (int) $row['id'] ) ); ?>"><?php echo esc_html( $name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $name ); ?>
						<?php endif; ?>
						<span class="smtpel-muted">
							<?php
							$notes = array_filter( array( $size ) );
							if ( '' === $path ) {
								if ( ! empty( $item['skipped'] ) && isset( $reasons[ $item['skipped'] ] ) ) {
									$notes[] = $reasons[ $item['skipped'] ];
								} elseif ( ! empty( $item['missing'] ) ) {
									$notes[] = __( 'file was already gone when the email was sent', 'smtp-email-log' );
								} elseif ( ! empty( $item['file'] ) ) {
									$notes[] = __( 'copy deleted', 'smtp-email-log' );
								} elseif ( 'failed' === $row['status'] || 'unknown' === $row['status'] ) {
									$notes[] = __( 'name only: copies weren\'t being kept', 'smtp-email-log' );
								} else {
									$notes[] = __( 'name only: copies are kept for failed emails', 'smtp-email-log' );
								}
							}
							echo esc_html( implode( ' · ', $notes ) );
							?>
						</span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * One email: resend or forward, and its resend history.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	private function render_resend( array $row ) {
		$meta = $row['meta'];
		$id   = (int) $row['id'];

		if ( '' === (string) $row['message'] && ( ! empty( $meta['no_body'] ) || ! empty( $meta['body_dropped'] ) ) ) {
			echo '<p class="smtpel-muted">' . esc_html( SMTP_Email_Log_Resend::reason( 'no_body' ) ) . '</p>';
			return;
		}

		$user = wp_get_current_user();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smtpel-resend-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( SMTP_Email_Log_Actions::RESEND ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( SMTP_Email_Log_Actions::RESEND . '_' . $id ); ?>

			<fieldset class="smtpel-radios">
				<legend class="screen-reader-text"><?php esc_html_e( 'Send to', 'smtp-email-log' ); ?></legend>
				<label>
					<input type="radio" name="recipient" value="original" checked>
					<span>
						<?php esc_html_e( 'The original recipients', 'smtp-email-log' ); ?>
						<span class="smtpel-muted"><?php echo esc_html( self::short_list( $row['to_addr'], 3 ) ); ?></span>
					</span>
				</label>
				<label>
					<input type="radio" name="recipient" value="other">
					<span><?php esc_html_e( 'Forward to another address', 'smtp-email-log' ); ?></span>
				</label>
				<input type="email" name="forward_to" class="regular-text smtpel-forward" value="<?php echo esc_attr( $user ? $user->user_email : '' ); ?>" aria-label="<?php esc_attr_e( 'Forward to', 'smtp-email-log' ); ?>" data-smtpel-forward>
			</fieldset>

			<label class="smtpel-check">
				<input type="checkbox" name="default_from" value="1">
				<span><?php esc_html_e( 'Send from the site\'s usual address instead of the original From', 'smtp-email-log' ); ?></span>
			</label>
			<p class="description"><?php esc_html_e( 'Useful when the mail server refused the original sender. Replies still go to the Reply-To address.', 'smtp-email-log' ); ?></p>

			<button type="submit" class="button button-primary"><?php echo self::icon( 'send', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Send now', 'smtp-email-log' ); ?></button>
		</form>

		<?php if ( ! empty( $meta['resends'] ) && is_array( $meta['resends'] ) ) : ?>
			<h4 class="smtpel-subhead"><?php esc_html_e( 'Sent again', 'smtp-email-log' ); ?></h4>
			<ul class="smtpel-history">
				<?php foreach ( array_reverse( $meta['resends'] ) as $resend ) : ?>
					<?php
					if ( ! is_array( $resend ) ) {
						continue;
					}
					$who = ! empty( $resend['who'] ) ? get_userdata( (int) $resend['who'] ) : false;
					?>
					<li>
						<?php echo SMTP_Email_Log_Icons::get( ! empty( $resend['ok'] ) ? 'circle-check' : 'circle-x', 15, ! empty( $resend['ok'] ) ? 'is-ok' : 'is-bad' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<span>
							<?php
							$text = ! empty( $resend['to'] )
								/* translators: 1: time since, 2: email address. */
								? sprintf( __( '%1$s ago, forwarded to %2$s', 'smtp-email-log' ), human_time_diff( (int) $resend['at'] ), $resend['to'] )
								/* translators: %s: time since. */
								: sprintf( __( '%s ago, to the original recipients', 'smtp-email-log' ), human_time_diff( (int) $resend['at'] ) );
							if ( $who ) {
								/* translators: %s: user's display name. */
								$text .= ' ' . sprintf( __( 'by %s', 'smtp-email-log' ), $who->display_name );
							}
							echo esc_html( $text );
							?>
							<?php if ( ! empty( $resend['id'] ) ) : ?>
								<a href="<?php echo esc_url( self::url( array( 'view' => (int) $resend['id'] ) ) ); ?>">#<?php echo esc_html( (string) (int) $resend['id'] ); ?></a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	// Test email -------------------------------------------------------------------

	/**
	 * Test Email: send one, and how this site sends email.
	 *
	 * @return void
	 */
	private function render_test() {
		$user = wp_get_current_user();
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<?php
					self::box(
						'send',
						__( 'Send a test email', 'smtp-email-log' ),
						function () use ( $user ) {
							?>
							<p class="smtpel-intro"><?php esc_html_e( 'Sends a short email the same way every other email from this site goes out (through your SMTP plugin, or PHP mail() if there isn\'t one) and shows exactly what happened, including the conversation with the mail server.', 'smtp-email-log' ); ?></p>
							<form class="smtpel-test-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" data-smtpel-test>
								<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
								<input type="hidden" name="tab" value="test">
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="smtpel-test-to"><?php esc_html_e( 'Send to', 'smtp-email-log' ); ?></label></th>
										<td><input type="email" id="smtpel-test-to" class="regular-text" name="to" value="<?php echo esc_attr( $user ? $user->user_email : '' ); ?>" required></td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Format', 'smtp-email-log' ); ?></th>
										<td>
											<fieldset class="smtpel-inline">
												<label><input type="radio" name="format" value="html" checked> <?php esc_html_e( 'HTML', 'smtp-email-log' ); ?></label>
												<label><input type="radio" name="format" value="text"> <?php esc_html_e( 'Plain text', 'smtp-email-log' ); ?></label>
											</fieldset>
										</td>
									</tr>
								</table>
								<p><button type="submit" class="button button-primary button-hero smtpel-test-button"><?php echo self::icon( 'send', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Send test email', 'smtp-email-log' ); ?></span></button></p>
							</form>

							<div class="smtpel-test-result" data-smtpel-test-result hidden aria-live="polite">
								<div class="smtpel-outcome">
									<span class="smtpel-outcome-icon" data-smtpel-result-icon></span>
									<div class="smtpel-outcome-text">
										<h3 data-smtpel-result-title></h3>
										<p class="smtpel-outcome-error" data-smtpel-result-error hidden><code></code></p>
										<p data-smtpel-result-text></p>
										<p class="smtpel-muted" data-smtpel-result-meta></p>
										<p data-smtpel-result-note hidden></p>
										<p><a href="#" data-smtpel-result-link hidden></a></p>
									</div>
								</div>
								<div data-smtpel-result-smtp hidden>
									<h4 class="smtpel-subhead"><?php esc_html_e( 'SMTP conversation', 'smtp-email-log' ); ?></h4>
									<pre class="smtpel-pre smtpel-code smtpel-transcript"></pre>
								</div>
							</div>
							<template data-smtpel-icon="ok"><?php echo self::icon( 'circle-check', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></template>
							<template data-smtpel-icon="bad"><?php echo self::icon( 'circle-x', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></template>
							<?php
						}
					);

					self::box(
						'route',
						__( 'How this site sends email', 'smtp-email-log' ),
						array( $this, 'render_setup' )
					);
					?>
				</div>
				<div id="postbox-container-1" class="postbox-container">
					<?php
					self::box(
						'lightbulb',
						__( 'Common fixes', 'smtp-email-log' ),
						array( $this, 'render_tips' )
					);
					?>
				</div>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * Test Email: what this site sends through.
	 *
	 * @return void
	 */
	public function render_setup() {
		$replaced = self::wp_mail_owner();
		$plugins  = self::mail_plugins();
		$last     = get_option( 'smtp_email_log_transport', array() );
		$mail_ok  = function_exists( 'mail' ) && false === stripos( (string) ini_get( 'disable_functions' ), 'mail' );
		?>
		<table class="smtpel-table-kv">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Mail plugins', 'smtp-email-log' ); ?></th>
					<td>
						<?php if ( $plugins ) : ?>
							<?php echo esc_html( implode( ', ', $plugins ) ); ?>
						<?php else : ?>
							<?php esc_html_e( 'None found. Without one, WordPress sends through PHP\'s mail() function, which many hosts switch off and which is far more likely to land in spam.', 'smtp-email-log' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last email went out', 'smtp-email-log' ); ?></th>
					<td>
						<?php if ( is_array( $last ) && ! empty( $last['transport'] ) ) : ?>
							<code><?php echo esc_html( self::transport_label( $last['transport'] ) ); ?></code>
							<span class="smtpel-muted">
								<?php
								/* translators: %s: time since. */
								echo esc_html( sprintf( __( '%s ago', 'smtp-email-log' ), human_time_diff( (int) $last['at'] ) ) );
								?>
							</span>
						<?php else : ?>
							<?php esc_html_e( 'No email has been sent since the plugin was activated.', 'smtp-email-log' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( is_array( $last ) && ! empty( $last['from'] ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'From address', 'smtp-email-log' ); ?></th>
						<td><code><?php echo esc_html( (string) $last['from'] ); ?></code></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'wp_mail()', 'smtp-email-log' ); ?></th>
					<td>
						<?php
						if ( '' === $replaced ) {
							esc_html_e( 'WordPress\'s own. Every email goes through the hooks this log listens to.', 'smtp-email-log' );
						} else {
							/* translators: %s: plugin name or file. */
							echo esc_html( sprintf( __( 'Replaced by %s. Emails are still logged if it runs WordPress\'s mail hooks, as most do: send a test to check.', 'smtp-email-log' ), $replaced ) );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'PHP mail()', 'smtp-email-log' ); ?></th>
					<td><?php echo $mail_ok ? esc_html__( 'Available on this server', 'smtp-email-log' ) : esc_html__( 'Switched off on this server', 'smtp-email-log' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'SMTP timeout', 'smtp-email-log' ); ?></th>
					<td>
						<?php
						$timeout = (int) $this->plugin->get_setting( 'smtp_timeout' );
						echo esc_html(
							$timeout > 0
								/* translators: %s: number of seconds. */
								? sprintf( _n( '%s second (set on the Advanced tab)', '%s seconds (set on the Advanced tab)', $timeout, 'smtp-email-log' ), number_format_i18n( $timeout ) )
								: __( 'Left to your SMTP plugin (PHPMailer waits up to 5 minutes)', 'smtp-email-log' )
						);
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Test Email: short troubleshooting list.
	 *
	 * @return void
	 */
	public function render_tips() {
		$tips = array(
			array( 'key-round', __( 'Login refused', 'smtp-email-log' ), __( 'Gmail and Google Workspace need an app password; Microsoft 365 needs SMTP AUTH switched on for the mailbox.', 'smtp-email-log' ) ),
			array( 'unplug', __( 'Can\'t connect', 'smtp-email-log' ), __( 'Use port 587 with TLS or 465 with SSL. Many hosts block mail ports: ask them, or use an API connection.', 'smtp-email-log' ) ),
			array( 'user-x', __( 'Sender refused', 'smtp-email-log' ), __( 'Send from an address on your own domain or SMTP account, and put the visitor\'s address in Reply-To.', 'smtp-email-log' ) ),
			array( 'mail-question-mark', __( 'Sent, but not arriving', 'smtp-email-log' ), __( 'Check spam, then your domain\'s SPF, DKIM and DMARC records with your email provider.', 'smtp-email-log' ) ),
			array( 'timer-off', __( 'Forms hang, then fail', 'smtp-email-log' ), __( 'The mail server is slow to answer. Set a 20-second SMTP timeout on the Advanced tab.', 'smtp-email-log' ) ),
		);
		?>
		<ul class="smtpel-tips">
			<?php foreach ( $tips as $tip ) : ?>
				<li>
					<span class="smtpel-tip-icon"><?php echo self::icon( $tip[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span><strong><?php echo esc_html( $tip[1] ); ?></strong> <?php echo esc_html( $tip[2] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The plugin (or file) that replaced wp_mail(), if any.
	 *
	 * @return string Empty when it's WordPress's own.
	 */
	private static function wp_mail_owner() {
		try {
			$file = wp_normalize_path( (string) ( new ReflectionFunction( 'wp_mail' ) )->getFileName() );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' ) === $file ) {
			return '';
		}

		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		if ( 0 === strpos( $file, $plugins ) ) {
			return self::plugin_name( strtok( substr( $file, strlen( $plugins ) ), '/' ) );
		}

		return wp_basename( $file );
	}

	/**
	 * Active plugins that look like they send or route email.
	 *
	 * @return string[] Names.
	 */
	private static function mail_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! in_array( $file, $active, true ) || 0 === strpos( $file, 'smtp-email-log/' ) ) {
				continue;
			}

			$haystack = strtolower( $file . ' ' . $data['Name'] );
			if ( preg_match( '/smtp|mailer|mailgun|sendgrid|postmark|sparkpost|brevo|sendinblue|mailjet|amazon ses|\bses\b|mailersend|smtp2go|elastic email|suremail|site mailer|resend|mailtrap|sendlayer/', $haystack ) ) {
				$out[] = $data['Name'];
			}
		}

		return $out;
	}

	// Settings ------------------------------------------------------------------------

	/**
	 * The page's settings boxes, in order: the panel each one is on, its
	 * icon, title, intro and what draws it.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		return array(
			'what'      => array(
				'panel'  => 'logging',
				'icon'   => 'list-checks',
				'title'  => __( 'What to log', 'smtp-email-log' ),
				'intro'  => __( 'Every email is written to the log before it\'s sent, so even one that crashes the page or times out halfway is kept, along with the form submission inside it.', 'smtp-email-log' ),
				'render' => 'render_what',
			),
			'privacy'   => array(
				'panel'  => 'logging',
				'icon'   => 'eye-off',
				'title'  => __( 'Content and privacy', 'smtp-email-log' ),
				'intro'  => '',
				'render' => 'render_privacy',
			),
			'skip'      => array(
				'panel'  => 'logging',
				'icon'   => 'funnel-x',
				'title'  => __( 'Emails to leave out', 'smtp-email-log' ),
				'intro'  => __( 'Emails whose recipients or subject contain any of these, one per line, aren\'t logged at all. Not case-sensitive.', 'smtp-email-log' ),
				'render' => 'render_skip',
			),
			'retention' => array(
				'panel'  => 'logging',
				'icon'   => 'calendar-clock',
				'title'  => __( 'How long to keep emails', 'smtp-email-log' ),
				'intro'  => __( 'Older emails are deleted once a day, along with any attachment copies.', 'smtp-email-log' ),
				'render' => 'render_retention',
			),
			'admin'     => array(
				'panel'  => 'alerts',
				'icon'   => 'bell-ring',
				'title'  => __( 'In wp-admin', 'smtp-email-log' ),
				'intro'  => '',
				'render' => 'render_admin_alerts',
			),
			'webhook'   => array(
				'panel'  => 'alerts',
				'icon'   => 'webhook',
				'title'  => __( 'Webhook alert', 'smtp-email-log' ),
				'intro'  => __( 'Post a message to Slack, Discord, Microsoft Teams, Google Chat, Zapier or any webhook when emails fail. A webhook rather than an email alert, because when the mail server is the problem, an alert email would fail too.', 'smtp-email-log' ),
				'render' => 'render_webhook',
			),
			'smtp'      => array(
				'panel'  => 'advanced',
				'icon'   => 'terminal',
				'title'  => __( 'SMTP conversation', 'smtp-email-log' ),
				'intro'  => __( 'The commands and replies between your site and the mail server: the quickest way to see why a server refused an email. Passwords, tokens and the message itself are never saved.', 'smtp-email-log' ),
				'render' => 'render_smtp',
			),
			'data'      => array(
				'panel'  => 'advanced',
				'icon'   => 'database',
				'title'  => __( 'Data', 'smtp-email-log' ),
				'intro'  => '',
				'render' => 'render_data',
			),
			'devnotes'  => array(
				'panel'  => 'advanced',
				'icon'   => 'code',
				'title'  => __( 'For developers', 'smtp-email-log' ),
				'intro'  => '',
				'render' => 'render_developer',
			),
		);
	}

	/**
	 * Settings: one form for every panel, with Save and status on the right.
	 *
	 * @return void
	 */
	private function render_settings() {
		?>
		<form action="options.php" method="post" class="smtpel-form">
			<?php settings_fields( self::PAGE ); ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php foreach ( $this->sections() as $id => $section ) : ?>
							<?php
							self::box(
								$section['icon'],
								$section['title'],
								function () use ( $section ) {
									if ( '' !== $section['intro'] ) {
										echo '<p class="smtpel-intro">' . esc_html( $section['intro'] ) . '</p>';
									}
									call_user_func( array( $this, $section['render'] ) );
								},
								'',
								' id="smtpel-' . esc_attr( $id ) . '" data-smtpel-panel="' . esc_attr( $section['panel'] ) . '"'
							);
							?>
						<?php endforeach; ?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="smtpel-sidebar">
							<?php $this->render_sidebar(); ?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="smtpel-empty-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( SMTP_Email_Log_Actions::EMPTY_LOG ); ?>">
			<?php wp_nonce_field( SMTP_Email_Log_Actions::EMPTY_LOG ); ?>
		</form>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return SMTP_Email_Log_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="smtpel-check"><input type="checkbox" name="%1$s" value="1" %2$s> <span>%3$s</span></label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Number input.
	 *
	 * @param string $key    Setting key.
	 * @param int    $min    Minimum.
	 * @param int    $max    Maximum.
	 * @param string $suffix Text after the box.
	 * @param string $class  Input class.
	 * @return void
	 */
	private function number( $key, $min, $max, $suffix = '', $class = 'small-text' ) {
		printf(
			'<input type="number" class="%7$s" id="smtpel-%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1"> %6$s',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			(int) $this->plugin->get_setting( $key ),
			(int) $min,
			(int) $max,
			esc_html( $suffix ),
			esc_attr( $class )
		);
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @param string   $depends   Setting that has to be on for this row to apply.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '', $depends = '' ) {
		echo '<tr' . ( '' !== $depends ? ' data-smtpel-depends="' . esc_attr( $depends ) . '"' : '' ) . '><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * A choice between options, as cards with an icon and explanation.
	 *
	 * @param string                          $key     Setting key.
	 * @param array<string,array<int,string>> $choices value => [icon, title, text].
	 * @return void
	 */
	private function choices( $key, array $choices ) {
		$current = (string) $this->plugin->get_setting( $key );
		?>
		<div class="smtpel-options" role="radiogroup">
			<?php foreach ( $choices as $value => $choice ) : ?>
				<label class="smtpel-option">
					<input type="radio" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
					<span class="smtpel-option-icon"><?php echo self::icon( $choice[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="smtpel-option-text">
						<strong><?php echo esc_html( $choice[1] ); ?></strong>
						<span><?php echo esc_html( $choice[2] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Settings: every email, or only failed ones.
	 *
	 * @return void
	 */
	public function render_what() {
		$this->choices(
			'log_mode',
			array(
				'all'    => array( 'mails', __( 'Every email', 'smtp-email-log' ), __( 'Sent and failed: a full record of what your site sends, and when.', 'smtp-email-log' ) ),
				'failed' => array( 'mail-warning', __( 'Only emails that fail', 'smtp-email-log' ), __( 'Sent emails are removed from the log the moment they\'re accepted, and only counted. The smallest log.', 'smtp-email-log' ) ),
			)
		);
	}

	/**
	 * Settings: message content, hidden links and attachments.
	 *
	 * @return void
	 */
	public function render_privacy() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Message content', 'smtp-email-log' ),
			function () {
				$current = (string) $this->plugin->get_setting( 'content' );
				$options = array(
					'all'    => __( 'Save it for every email', 'smtp-email-log' ),
					'failed' => __( 'Save it only for emails that fail', 'smtp-email-log' ),
					'none'   => __( 'Don\'t save it (recipients, subject and errors only)', 'smtp-email-log' ),
				);
				echo '<fieldset class="smtpel-radios">';
				foreach ( $options as $value => $label ) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s> <span>%4$s</span></label>',
						esc_attr( self::name( 'content' ) ),
						esc_attr( $value ),
						checked( $current, $value, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset>';
				echo '<p class="description">' . esc_html__( 'Without the content, a failed form submission can\'t be read or resent.', 'smtp-email-log' ) . '</p>';
			}
		);

		$this->row(
			__( 'Login links', 'smtp-email-log' ),
			function () {
				if ( self::secrets_locked() ) {
					echo '<label class="smtpel-check"><input type="checkbox" checked disabled> <span>' . esc_html__( 'Hide password reset and login links', 'smtp-email-log' ) . '</span></label>';
					echo '<p class="description">' . esc_html__( 'Always on for site administrators on a multisite network: otherwise a site admin could read the password reset link of any user on the network. Only a super admin can switch it off.', 'smtp-email-log' ) . '</p>';
					return;
				}
				$this->checkbox( 'hide_secrets', __( 'Hide password reset and login links', 'smtp-email-log' ), __( 'Removes the secret key or token from links in password reset, new account, activation and magic login emails before they\'re saved, so nobody can use the log to get into an account.', 'smtp-email-log' ) );
			}
		);

		$this->row(
			__( 'Attachments', 'smtp-email-log' ),
			function () {
				$this->checkbox( 'keep_attachments', __( 'Keep a copy of the attachments when an email fails', 'smtp-email-log' ) );
				echo '<p class="smtpel-inline smtpel-indent">';
				$this->number( 'attachment_mb', 1, 50, __( 'MB per email at most', 'smtp-email-log' ) );
				echo '</p>';
				/* translators: %s: size, like "256 MB". */
				echo '<p class="description">' . esc_html( sprintf( __( 'So a file sent through a form isn\'t lost with the email. Copies are stored where the web can\'t reach them, only administrators can download them, and they\'re deleted with the email (%s in total at most).', 'smtp-email-log' ), size_format( SMTP_Email_Log_Attachments::TOTAL_LIMIT ) ) ) . '</p>';
			},
			'',
			'keep_attachments'
		);

		echo '</table>';
	}

	/**
	 * Settings: the "don't log" list.
	 *
	 * @return void
	 */
	public function render_skip() {
		printf(
			'<label class="screen-reader-text" for="smtpel-exclude">%1$s</label><textarea class="large-text code" rows="4" id="smtpel-exclude" name="%2$s" placeholder="%3$s">%4$s</textarea>',
			esc_html__( 'Emails to leave out', 'smtp-email-log' ),
			esc_attr( self::name( 'exclude' ) ),
			esc_attr( "newsletter@example.com\nYour daily digest" ),
			esc_textarea( (string) $this->plugin->get_setting( 'exclude' ) )
		);
		echo '<p class="description">' . esc_html__( 'Test emails and resends from this page are always logged.', 'smtp-email-log' ) . '</p>';
	}

	/**
	 * Settings: retention.
	 *
	 * @return void
	 */
	public function render_retention() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Sent emails', 'smtp-email-log' ),
			function () {
				$this->number( 'retention_sent', 0, 3650, __( 'days (0 = keep forever)', 'smtp-email-log' ) );
			},
			'smtpel-retention_sent'
		);

		$this->row(
			__( 'Failed emails', 'smtp-email-log' ),
			function () {
				$this->number( 'retention_failed', 0, 3650, __( 'days (0 = keep forever)', 'smtp-email-log' ) );
				echo '<p class="description">' . esc_html__( 'Kept longer by default: they may hold messages that never reached anyone.', 'smtp-email-log' ) . '</p>';
			},
			'smtpel-retention_failed'
		);

		$this->row(
			__( 'At most', 'smtp-email-log' ),
			function () {
				$this->number( 'max_rows', 100, 1000000, __( 'emails (the oldest go first)', 'smtp-email-log' ), 'smtpel-wide-number' );
				$size = SMTP_Email_Log_Log::size();
				/* translators: 1: number of emails, 2: size, like "4 MB". */
				echo '<p class="description">' . esc_html( sprintf( _n( 'The log holds %1$s email, using %2$s.', 'The log holds %1$s emails, using %2$s.', $size['rows'], 'smtp-email-log' ), number_format_i18n( $size['rows'] ), size_format( $size['bytes'] ? $size['bytes'] : 0, 1 ) ) ) . '</p>';
			},
			'smtpel-max_rows'
		);

		echo '</table>';
	}

	/**
	 * Alerts: the notice and menu count.
	 *
	 * @return void
	 */
	public function render_admin_alerts() {
		echo '<div class="smtpel-stack">';
		$this->checkbox( 'notice', __( 'Show a notice on the Dashboard when emails fail', 'smtp-email-log' ), __( 'With the latest error and a link to the failed emails. It goes away once you\'ve looked at the log, or dismiss it.', 'smtp-email-log' ) );
		$this->checkbox( 'badge', __( 'Count new failures beside the menu item', 'smtp-email-log' ), __( 'A count on Content Powered and SMTP Email Log, like the one for plugin updates.', 'smtp-email-log' ) );
		echo '</div>';
	}

	/**
	 * Alerts: the webhook.
	 *
	 * @return void
	 */
	public function render_webhook() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Webhook URL', 'smtp-email-log' ),
			function () {
				echo '<div class="smtpel-inline">';
				printf(
					'<input type="url" class="large-text code" id="smtpel-webhook_url" name="%1$s" value="%2$s" placeholder="https://hooks.slack.com/services/..." autocomplete="off" spellcheck="false">',
					esc_attr( self::name( 'webhook_url' ) ),
					esc_attr( (string) $this->plugin->get_setting( 'webhook_url' ) )
				);
				echo '<button type="button" class="button" data-smtpel-webhook-test>' . self::icon( 'send', 15 ) . esc_html__( 'Send test alert', 'smtp-email-log' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				echo '</div>';
				echo '<p class="smtpel-test-line" data-smtpel-webhook-result aria-live="polite"></p>';
				echo '<p class="description">' . esc_html__( 'An https:// address. Leave it empty for no webhook. Chat services get a short message; anything else gets JSON with the count, latest error, subject, recipients and a link to the log.', 'smtp-email-log' ) . '</p>';
			},
			'smtpel-webhook_url'
		);

		$this->row(
			__( 'At most', 'smtp-email-log' ),
			function () {
				$this->number( 'webhook_cooldown', 5, 1440, __( 'minutes between alerts', 'smtp-email-log' ) );
				echo '<p class="description">' . esc_html__( 'Failures in between are added up into the next alert, so a broken form can\'t flood the channel. Alerts go out from WP-Cron, never while a visitor waits.', 'smtp-email-log' ) . '</p>';
			},
			'smtpel-webhook_cooldown'
		);

		echo '</table>';
	}

	/**
	 * Advanced: the SMTP conversation and timeout.
	 *
	 * @return void
	 */
	public function render_smtp() {
		$this->choices(
			'transcript',
			array(
				'failed' => array( 'mail-x', __( 'Save it for failed emails', 'smtp-email-log' ), __( 'Recommended: there when you need it, without filling the log.', 'smtp-email-log' ) ),
				'all'    => array( 'mails', __( 'Save it for every email', 'smtp-email-log' ), __( 'Shows which server accepted each email and what it said. A few KB per email.', 'smtp-email-log' ) ),
				'off'    => array( 'circle-off', __( 'Don\'t save it', 'smtp-email-log' ), __( 'Leaves PHPMailer\'s debug settings alone entirely.', 'smtp-email-log' ) ),
			)
		);

		echo '<table class="form-table" role="presentation">';
		$this->row(
			__( 'SMTP timeout', 'smtp-email-log' ),
			function () {
				$this->number( 'smtp_timeout', 0, 300, __( 'seconds (0 = leave it to your SMTP plugin)', 'smtp-email-log' ) );
				echo '<p class="description">' . esc_html__( 'PHPMailer waits up to 5 minutes for a mail server that doesn\'t answer, far longer than most servers let a page run, so the visitor gets an error page and the email is never marked as failed. 15 to 30 seconds is plenty for any working server.', 'smtp-email-log' ) . '</p>';
			},
			'smtpel-smtp_timeout'
		);
		echo '</table>';
	}

	/**
	 * Advanced: keep data, and empty the log.
	 *
	 * @return void
	 */
	public function render_data() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Empty the log', 'smtp-email-log' ),
			function () {
				echo '<button type="submit" form="smtpel-empty-form" class="button smtpel-button-danger" data-smtpel-confirm="' . esc_attr__( 'Delete every email in the log, and every attachment copy? This can\'t be undone.', 'smtp-email-log' ) . '">' . self::icon( 'trash-2', 15 ) . esc_html__( 'Delete every logged email', 'smtp-email-log' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				echo '<p class="description">' . esc_html__( 'Settings stay as they are. The totals on the log page are kept too.', 'smtp-email-log' ) . '</p>';
			}
		);

		$this->row(
			__( 'When deleting the plugin', 'smtp-email-log' ),
			function () {
				$this->checkbox( 'keep_data', __( 'Keep the log and settings', 'smtp-email-log' ), __( 'Normally, deleting the plugin from the Plugins screen removes the log table, attachment copies and settings.', 'smtp-email-log' ) );
			}
		);

		echo '</table>';
	}

	/**
	 * Advanced: hooks reference.
	 *
	 * @return void
	 */
	public function render_developer() {
		$hooks = array(
			'smtp_email_log_skip'            => __( 'Filter: return true to leave an email out of the log (gets the wp_mail() arguments).', 'smtp-email-log' ),
			'smtp_email_log_row'             => __( 'Filter: change what\'s stored for an email, or return false to skip it.', 'smtp-email-log' ),
			'smtp_email_log_failed'          => __( 'Action: an email failed (log ID, error message).', 'smtp-email-log' ),
			'smtp_email_log_webhook_payload' => __( 'Filter: change the JSON sent to the webhook.', 'smtp-email-log' ),
		);
		?>
		<table class="widefat striped smtpel-table smtpel-hooks">
			<tbody>
				<?php foreach ( $hooks as $hook => $what ) : ?>
					<tr><td><code><?php echo esc_html( $hook ); ?></code></td><td><?php echo esc_html( $what ); ?></td></tr>
				<?php endforeach; ?>
				<tr><td><code>SMTP_EMAIL_LOG_DISABLED</code></td><td><?php esc_html_e( 'Define as true in wp-config.php to stop logging, whatever the settings say.', 'smtp-email-log' ); ?></td></tr>
			</tbody>
		</table>
		<p class="description">
			<?php
			/* translators: %s: database table name. */
			echo esc_html( sprintf( __( 'The log is the %s table. Times are stored in UTC.', 'smtp-email-log' ), SMTP_Email_Log_Log::table() ) );
			?>
		</p>
		<?php
	}

	/**
	 * Sidebar: Save, status, and where updates come from.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$plugin  = $this->plugin;
		$last    = get_option( 'smtp_email_log_transport', array() );
		$size    = SMTP_Email_Log_Log::size();
		$sent    = (int) $plugin->get_setting( 'retention_sent' );
		$failed  = (int) $plugin->get_setting( 'retention_failed' );
		$content = (string) $plugin->get_setting( 'content' );
		$status  = array(
			array( $plugin->is_enabled(), $plugin->is_enabled() ? ( 'failed' === $plugin->get_setting( 'log_mode' ) ? __( 'Logging failed emails', 'smtp-email-log' ) : __( 'Logging every email', 'smtp-email-log' ) ) : __( 'Logging is off', 'smtp-email-log' ) ),
			array( 'none' !== $content, 'all' === $content ? __( 'Saving message content', 'smtp-email-log' ) : ( 'failed' === $content ? __( 'Saving content of failed emails', 'smtp-email-log' ) : __( 'Not saving message content', 'smtp-email-log' ) ) ),
			array(
				$sent > 0 || $failed > 0,
				$sent > 0 || $failed > 0
					/* translators: 1: days for sent emails, 2: days for failed emails. */
					? sprintf( __( 'Keeping sent %1$s days, failed %2$s days', 'smtp-email-log' ), $sent > 0 ? number_format_i18n( $sent ) : '∞', $failed > 0 ? number_format_i18n( $failed ) : '∞' )
					: __( 'Keeping emails forever', 'smtp-email-log' ),
			),
			array( '' !== (string) $plugin->get_setting( 'webhook_url' ), '' !== (string) $plugin->get_setting( 'webhook_url' ) ? __( 'Webhook alerts on', 'smtp-email-log' ) : __( 'No webhook alerts', 'smtp-email-log' ) ),
			array(
				is_array( $last ) && ! empty( $last['transport'] ),
				is_array( $last ) && ! empty( $last['transport'] )
					/* translators: %s: how the last email was sent. */
					? sprintf( __( 'Last email: %s', 'smtp-email-log' ), self::transport_label( $last['transport'] ) )
					: __( 'No email sent yet', 'smtp-email-log' ),
			),
			/* translators: 1: number of emails, 2: size. */
			array( true, sprintf( _n( '%1$s email in the log (%2$s)', '%1$s emails in the log (%2$s)', $size['rows'], 'smtp-email-log' ), number_format_i18n( $size['rows'] ), size_format( $size['bytes'] ? $size['bytes'] : 0, 1 ) ) ),
		);
		?>
		<div class="postbox smtpel-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'smtp-email-log' ); ?>
				</h2>
			</div>
			<div class="inside">
				<label class="smtpel-master">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'enabled' ) ); ?>" value="1" <?php checked( 1, (int) $plugin->get_setting( 'enabled' ) ); ?>>
					<span><?php esc_html_e( 'Logging on', 'smtp-email-log' ); ?></span>
				</label>
				<?php if ( defined( 'SMTP_EMAIL_LOG_DISABLED' ) && SMTP_EMAIL_LOG_DISABLED ) : ?>
					<p class="smtpel-callout is-warning"><?php esc_html_e( 'Switched off by SMTP_EMAIL_LOG_DISABLED in wp-config.php.', 'smtp-email-log' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Settings on every tab are saved together.', 'smtp-email-log' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'smtp-email-log' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'smtp-email-log' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="smtpel-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( SMTP_EMAIL_LOG_REPO ) );
		$check_url  = SMTP_Email_Log_Updater::check_now_url();
		?>
		<div class="postbox smtpel-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'smtp-email-log' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'smtp-email-log' ), '<strong>' . esc_html( SMTP_EMAIL_LOG_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! SMTP_Email_Log_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'smtp-email-log' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( SMTP_EMAIL_LOG_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( SMTP_Email_Log_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'smtp-email-log' ),
							'<a href="' . esc_url( SMTP_EMAIL_LOG_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>SMTP_EMAIL_LOG_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'smtp-email-log' ),
							'<a href="' . esc_url( SMTP_EMAIL_LOG_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'smtp-email-log' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
