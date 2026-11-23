<?php
/**
 * Runtime state: notices, the batch in flight, the spending counter, the activity log.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small persistent store for everything that is not a setting.
 */
class IndexPages_State {

	/**
	 * Option with the state array (autoload off).
	 */
	const OPTION = 'indexpages_state';

	/**
	 * Option with the activity log (autoload off).
	 */
	const LOG_OPTION = 'indexpages_log';

	/**
	 * Option used as a mutex for the sender.
	 */
	const LOCK_OPTION = 'indexpages_lock';

	/**
	 * Transient with the cached GET /account response.
	 */
	const ACCOUNT_TRANSIENT = 'indexpages_account';

	/**
	 * Transient with the cached GET /orders response.
	 */
	const ORDERS_TRANSIENT = 'indexpages_orders';

	/**
	 * Post meta: submission status (queued, draft, submitted, rejected, skipped).
	 */
	const META_STATUS = '_indexpages_status';

	/**
	 * Post meta: Unix time the URL last went into an order.
	 */
	const META_SENT = '_indexpages_last_sent';

	/**
	 * Post meta: the URL that was sent.
	 */
	const META_URL = '_indexpages_last_url';

	/**
	 * Post meta: id of the order the URL went into.
	 */
	const META_ORDER = '_indexpages_order';

	/**
	 * Post meta: human-readable reason for rejected or skipped.
	 */
	const META_MESSAGE = '_indexpages_message';

	/**
	 * Maximum number of log entries kept.
	 */
	const LOG_SIZE = 50;

	/**
	 * All post meta keys the plugin writes.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array( self::META_STATUS, self::META_SENT, self::META_URL, self::META_ORDER, self::META_MESSAGE );
	}

	/**
	 * The whole state array, read fresh from the database.
	 *
	 * @return array
	 */
	private static function all() {
		wp_cache_delete( self::OPTION, 'options' );
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * One state value.
	 *
	 * @param string $name    Name.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $name, $fallback = null ) {
		$state = self::all();
		return array_key_exists( $name, $state ) ? $state[ $name ] : $fallback;
	}

	/**
	 * Sets one state value; null removes it.
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function set( $name, $value ) {
		$state = self::all();
		if ( null === $value ) {
			unset( $state[ $name ] );
		} else {
			$state[ $name ] = $value;
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Stores the notice shown to administrators.
	 *
	 * @param string $level   error, warning or info.
	 * @param string $code    Machine code.
	 * @param string $message What happened.
	 * @param string $hint    What to do.
	 * @return void
	 */
	public static function set_notice( $level, $code, $message, $hint = '' ) {
		self::set(
			'notice',
			array(
				'level'   => $level,
				'code'    => $code,
				'message' => $message,
				'hint'    => $hint,
				'time'    => time(),
			)
		);
	}

	/**
	 * Clears the notice.
	 *
	 * @return void
	 */
	public static function clear_notice() {
		self::set( 'notice', null );
	}

	/**
	 * Adds an entry to the activity log.
	 *
	 * @param string $type    order_created, order_run, rejected, skipped, error.
	 * @param string $message Text.
	 * @param array  $extra   Extra fields (url, order).
	 * @return void
	 */
	public static function log( $type, $message, array $extra = array() ) {
		wp_cache_delete( self::LOG_OPTION, 'options' );
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array_merge(
				$extra,
				array(
					'type'    => $type,
					'message' => $message,
					'time'    => time(),
				)
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	/**
	 * The activity log, newest first.
	 *
	 * @return array
	 */
	public static function get_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Today's date in the site's time zone, the key of the spending counter.
	 *
	 * @return string
	 */
	private static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * URLs run automatically today.
	 *
	 * @return int
	 */
	public static function spent_today() {
		$spend = self::get( 'spend', array() );
		if ( ! is_array( $spend ) || ! isset( $spend['date'], $spend['urls'] ) || self::today() !== $spend['date'] ) {
			return 0;
		}
		return (int) $spend['urls'];
	}

	/**
	 * Adds URLs to today's automatic spending.
	 *
	 * @param int $urls Number of URLs run.
	 * @return void
	 */
	public static function add_spent( $urls ) {
		self::set(
			'spend',
			array(
				'date' => self::today(),
				'urls' => self::spent_today() + (int) $urls,
			)
		);
	}

	/**
	 * Takes the sender lock.
	 *
	 * @return bool False when another run holds it.
	 */
	public static function lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$since = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $since > 0 && $since < time() - 5 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, time(), '', false );
		}
		return false;
	}

	/**
	 * Releases the sender lock.
	 *
	 * @return void
	 */
	public static function unlock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Updates the submission post meta of one post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $status  Status.
	 * @param array  $fields  Optional: url, order, message, sent (bool – set the sent time).
	 * @return void
	 */
	public static function mark_post( $post_id, $status, array $fields = array() ) {
		update_post_meta( $post_id, self::META_STATUS, $status );
		if ( ! empty( $fields['sent'] ) ) {
			update_post_meta( $post_id, self::META_SENT, time() );
		}
		if ( isset( $fields['url'] ) ) {
			update_post_meta( $post_id, self::META_URL, $fields['url'] );
		}
		if ( isset( $fields['order'] ) ) {
			update_post_meta( $post_id, self::META_ORDER, $fields['order'] );
		}
		if ( isset( $fields['message'] ) ) {
			update_post_meta( $post_id, self::META_MESSAGE, $fields['message'] );
		} else {
			delete_post_meta( $post_id, self::META_MESSAGE );
		}
	}

	/**
	 * Drops the cached account and orders.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		delete_transient( self::ACCOUNT_TRANSIENT );
		delete_transient( self::ORDERS_TRANSIENT );
	}
}
