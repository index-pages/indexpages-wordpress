<?php
/**
 * Bootstrap: hooks, cron, activation.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin together.
 */
class IndexPages_Plugin {

	/**
	 * Recurring WP-Cron hook that sends the queue.
	 */
	const CRON_HOOK = 'indexpages_send_queue';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );

		IndexPages_Collector::init();

		if ( is_admin() ) {
			IndexPages_Admin::init();
			IndexPages_Settings_Page::init();
			IndexPages_Tools_Page::init();
			IndexPages_Posts_List::init();
			add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
		}
	}

	/**
	 * Suggests a paragraph for the site's privacy policy (Settings → Privacy).
	 *
	 * @return void
	 */
	public static function privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . sprintf(
			/* translators: %s: link to the Index Pages privacy policy. */
			__( 'This site submits the public addresses (URLs) of its published pages to Index Pages, a service that asks search engines to crawl them. No personal data of visitors or users is sent. See the %s.', 'indexpages' ),
			'<a href="https://index-pages.com/privacy/">' . esc_html__( 'Index Pages privacy policy', 'indexpages' ) . '</a>'
		) . '</p>';
		wp_add_privacy_policy_content( __( 'Index Pages', 'indexpages' ), wp_kses_post( $text ) );
	}

	/**
	 * Adds the 15-minute recurrence.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['indexpages_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Index Pages)', 'indexpages' ),
		);
		return $schedules;
	}

	/**
	 * The cron callback.
	 *
	 * @return void
	 */
	public static function run_cron() {
		IndexPages_Sender::flush( 'cron' );
	}

	/**
	 * Keeps the cron event in line with the settings.
	 *
	 * Scheduled only when a key is set and the mode sends something on its own;
	 * rescheduled when the recurrence changes. Runs on every request but costs a
	 * single lookup in the already loaded cron array.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$wanted = IndexPages_Settings::has_key() && IndexPages_Settings::MODE_MANUAL !== IndexPages_Settings::mode();
		$event  = wp_get_scheduled_event( self::CRON_HOOK );

		if ( ! $wanted ) {
			if ( $event ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		$recurrence = (string) IndexPages_Settings::get( 'schedule' );
		if ( ! array_key_exists( $recurrence, IndexPages_Settings::schedules() ) ) {
			$recurrence = 'indexpages_15min';
		}
		if ( $event && $event->schedule === $recurrence ) {
			return;
		}
		if ( $event ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, self::CRON_HOOK );
	}

	/**
	 * Activation: store the defaults once; the cron event follows the settings.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( IndexPages_Settings::OPTION ) ) {
			add_option( IndexPages_Settings::OPTION, IndexPages_Settings::defaults() );
		}
	}

	/**
	 * Deactivation: stop the cron event and release a stale lock. Settings and
	 * history stay until the plugin is deleted.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( IndexPages_State::LOCK_OPTION );
	}
}
