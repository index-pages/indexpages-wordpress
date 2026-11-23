<?php
/**
 * Shared admin pieces: notices, flash messages, dashboard widget, assets.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin helpers used by the settings, tools and posts list screens.
 */
class IndexPages_Admin {

	/**
	 * Settings page slug.
	 */
	const SETTINGS_SLUG = 'indexpages-settings';

	/**
	 * Tools page slug.
	 */
	const TOOLS_SLUG = 'indexpages-tools';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_indexpages_dismiss', array( __CLASS__, 'handle_dismiss' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( INDEXPAGES_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * URL of the settings page.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function settings_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SETTINGS_SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * URL of the tools page.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function tools_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::TOOLS_SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Adds Settings and Tools links on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'indexpages' ) . '</a>',
			'<a href="' . esc_url( self::tools_url() ) . '">' . esc_html__( 'Queue and orders', 'indexpages' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Loads the small stylesheet and script on the plugin's screens.
	 *
	 * @param string $hook_suffix Screen hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		$screens = array( 'settings_page_' . self::SETTINGS_SLUG, 'tools_page_' . self::TOOLS_SLUG, 'edit.php', 'index.php' );
		if ( ! in_array( $hook_suffix, $screens, true ) ) {
			return;
		}
		wp_enqueue_style( 'indexpages-admin', INDEXPAGES_PLUGIN_URL . 'assets/admin.css', array(), INDEXPAGES_VERSION );
		wp_enqueue_script( 'indexpages-admin', INDEXPAGES_PLUGIN_URL . 'assets/admin.js', array(), INDEXPAGES_VERSION, true );
	}

	/**
	 * Stores a one-time message for the current user, shown after a redirect.
	 *
	 * @param string $level   success, error, warning or info.
	 * @param string $message Text.
	 * @return void
	 */
	public static function flash( $level, $message ) {
		set_transient( 'indexpages_flash_' . get_current_user_id(), array( $level, $message ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Maps a sender result status to a notice level.
	 *
	 * @param array $result Sender result.
	 * @return string
	 */
	public static function level_for( array $result ) {
		$map = array(
			'ok'      => 'success',
			'idle'    => 'info',
			'waiting' => 'warning',
			'error'   => 'error',
		);
		return isset( $map[ $result['status'] ] ) ? $map[ $result['status'] ] : 'info';
	}

	/**
	 * Prints one notice box.
	 *
	 * @param string $level   success, error, warning or info.
	 * @param string $html    Already escaped inner HTML.
	 * @param bool   $dismiss Whether WordPress may hide it on click.
	 * @return void
	 */
	public static function notice_box( $level, $html, $dismiss = true ) {
		printf(
			'<div class="notice notice-%1$s%2$s indexpages-notice">%3$s</div>',
			esc_attr( $level ),
			$dismiss ? ' is-dismissible' : '',
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the callers.
		);
	}

	/**
	 * Prints the flash message and the stored failure notices.
	 *
	 * @return void
	 */
	public static function render_notices() {
		$flash_key = 'indexpages_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_array( $flash ) && 2 === count( $flash ) ) {
			delete_transient( $flash_key );
			self::notice_box( $flash[0], '<p>' . esc_html( $flash[1] ) . '</p>' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = IndexPages_State::get( 'notice' );
		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			$html  = '<p><strong>' . esc_html__( 'Index Pages:', 'indexpages' ) . '</strong> ' . esc_html( $notice['message'] ) . '</p>';
			$html .= ! empty( $notice['hint'] ) ? '<p>' . esc_html( $notice['hint'] ) . '</p>' : '';
			$html .= '<p><a href="' . esc_url( self::tools_url() ) . '">' . esc_html__( 'Open the queue and orders', 'indexpages' ) . '</a> | ';
			$html .= '<a href="' . esc_url( self::dismiss_url( 'notice' ) ) . '">' . esc_html__( 'Dismiss', 'indexpages' ) . '</a></p>';
			self::notice_box( 'error' === $notice['level'] ? 'error' : 'warning', $html, false );
		}

		$rejected = IndexPages_State::get( 'rejected', array() );
		if ( is_array( $rejected ) && ! empty( $rejected ) ) {
			$html  = '<p><strong>' . esc_html__( 'Index Pages:', 'indexpages' ) . '</strong> ';
			$html .= esc_html(
				sprintf(
					/* translators: %d: number of URLs. */
					_n( '%d URL was rejected and removed from the queue. The other URLs were sent.', '%d URLs were rejected and removed from the queue. The other URLs were sent.', count( $rejected ), 'indexpages' ),
					count( $rejected )
				)
			) . '</p><ul class="indexpages-list">';
			foreach ( $rejected as $item ) {
				$html .= '<li><code>' . esc_html( $item['url'] ) . '</code> – ' . esc_html( $item['reason'] ) . '</li>';
			}
			$html .= '</ul><p>' . esc_html__( 'Fix the address (for example the site URL in Settings → General) and send the post again.', 'indexpages' ) . ' ';
			$html .= '<a href="' . esc_url( self::dismiss_url( 'rejected' ) ) . '">' . esc_html__( 'Dismiss', 'indexpages' ) . '</a></p>';
			self::notice_box( 'warning', $html, false );
		}
	}

	/**
	 * Nonce-protected link that clears a stored notice.
	 *
	 * @param string $what notice or rejected.
	 * @return string
	 */
	private static function dismiss_url( $what ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'indexpages_dismiss',
					'what'   => $what,
				),
				admin_url( 'admin-post.php' )
			),
			'indexpages_dismiss'
		);
	}

	/**
	 * Clears a stored notice.
	 *
	 * @return void
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'indexpages' ), 403 );
		}
		check_admin_referer( 'indexpages_dismiss' );
		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '';
		if ( 'rejected' === $what ) {
			IndexPages_State::set( 'rejected', null );
		} else {
			IndexPages_State::clear_notice();
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * GET /account, cached for ten minutes (failures for five).
	 *
	 * @param bool $fetch Whether to call the API when nothing is cached.
	 * @return array|WP_Error|null Null when nothing is cached and $fetch is false.
	 */
	public static function account( $fetch = true ) {
		$cached = get_transient( IndexPages_State::ACCOUNT_TRANSIENT );
		if ( is_array( $cached ) ) {
			if ( isset( $cached['indexpages_error'] ) ) {
				return new WP_Error( (string) $cached['indexpages_error'], (string) $cached['message'] );
			}
			return $cached;
		}
		if ( ! $fetch ) {
			return null;
		}
		$api = IndexPages_Api::from_settings();
		if ( null === $api ) {
			return null;
		}
		$account = $api->get_account();
		if ( is_wp_error( $account ) ) {
			$text = IndexPages_Errors::describe( $account );
			set_transient(
				IndexPages_State::ACCOUNT_TRANSIENT,
				array(
					'indexpages_error' => $account->get_error_code(),
					'message'          => $text['message'],
				),
				5 * MINUTE_IN_SECONDS
			);
			return new WP_Error( $account->get_error_code(), $text['message'] );
		}
		set_transient( IndexPages_State::ACCOUNT_TRANSIENT, $account, 10 * MINUTE_IN_SECONDS );
		return $account;
	}

	/**
	 * Amount with currency, for display.
	 *
	 * @param string $amount   "12.50".
	 * @param string $currency "USD".
	 * @return string
	 */
	public static function money( $amount, $currency = 'USD' ) {
		return 'USD' === $currency ? '$' . $amount : $amount . ' ' . $currency;
	}

	/**
	 * Readable label of a post submission status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function post_status_label( $status ) {
		$labels = array(
			'queued'    => __( 'Queued', 'indexpages' ),
			'draft'     => __( 'In a draft order', 'indexpages' ),
			'submitted' => __( 'Submitted', 'indexpages' ),
			'rejected'  => __( 'Rejected', 'indexpages' ),
			'skipped'   => __( 'Skipped', 'indexpages' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Readable label of an order status.
	 *
	 * @param string $status draft, in_progress, done, error.
	 * @return string
	 */
	public static function order_status_label( $status ) {
		$labels = array(
			'draft'       => __( 'Draft', 'indexpages' ),
			'in_progress' => __( 'In progress', 'indexpages' ),
			'done'        => __( 'Done', 'indexpages' ),
			'error'       => __( 'Error', 'indexpages' ),
			'pending'     => __( 'Pending', 'indexpages' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Formats a Unix time or RFC 3339 string in the site's format and time zone.
	 *
	 * @param int|string $time Time.
	 * @return string
	 */
	public static function date( $time ) {
		if ( ! is_numeric( $time ) ) {
			$time = strtotime( (string) $time );
		}
		if ( ! $time ) {
			return '–';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $time );
	}

	/**
	 * Registers the dashboard widget.
	 *
	 * @return void
	 */
	public static function register_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'indexpages_widget', __( 'Index Pages', 'indexpages' ), array( __CLASS__, 'render_widget' ) );
	}

	/**
	 * Prints the dashboard widget.
	 *
	 * @return void
	 */
	public static function render_widget() {
		if ( ! IndexPages_Settings::has_key() ) {
			echo '<p>' . esc_html__( 'Add your API key to start sending URLs.', 'indexpages' ) . ' <a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'indexpages' ) . '</a></p>';
			return;
		}

		$modes   = array(
			IndexPages_Settings::MODE_MANUAL    => __( 'Manual', 'indexpages' ),
			IndexPages_Settings::MODE_DRAFTS    => __( 'Draft orders', 'indexpages' ),
			IndexPages_Settings::MODE_AUTOMATIC => __( 'Automatic', 'indexpages' ),
		);
		$account = self::account();
		$last    = IndexPages_State::get( 'last_order' );

		echo '<ul class="indexpages-stats">';
		echo '<li><span>' . esc_html__( 'Balance', 'indexpages' ) . '</span> <strong>';
		if ( is_array( $account ) && isset( $account['balance'] ) ) {
			echo esc_html( self::money( $account['balance'], $account['currency'] ) );
		} else {
			echo esc_html__( 'unavailable', 'indexpages' );
		}
		echo '</strong></li>';
		echo '<li><span>' . esc_html__( 'Queue', 'indexpages' ) . '</span> <strong>' . esc_html( number_format_i18n( IndexPages_Queue::count() ) ) . '</strong></li>';
		echo '<li><span>' . esc_html__( 'Mode', 'indexpages' ) . '</span> <strong>' . esc_html( $modes[ IndexPages_Settings::mode() ] ) . '</strong></li>';
		echo '</ul>';

		if ( is_wp_error( $account ) ) {
			echo '<p class="indexpages-muted">' . esc_html( $account->get_error_message() ) . '</p>';
		}

		if ( is_array( $last ) && ! empty( $last['id'] ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: order id, 2: order status, 3: number of URLs, 4: date. */
					_n( 'Last order: %1$s, %2$s, %3$d URL, %4$s.', 'Last order: %1$s, %2$s, %3$d URLs, %4$s.', (int) $last['urls_total'], 'indexpages' ),
					$last['id'],
					self::order_status_label( $last['status'] ),
					$last['urls_total'],
					self::date( $last['time'] )
				)
			) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No orders sent from this site yet.', 'indexpages' ) . '</p>';
		}

		echo '<p><a class="button" href="' . esc_url( self::tools_url() ) . '">' . esc_html__( 'Queue and orders', 'indexpages' ) . '</a></p>';
	}
}
