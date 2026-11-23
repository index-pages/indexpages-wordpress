<?php
/**
 * The queue of posts waiting to be sent.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue stored in a non-autoloaded option: post id => { time, reason }.
 *
 * The URL is resolved when the queue is sent, so a slug changed in between is
 * picked up.
 */
class IndexPages_Queue {

	/**
	 * Option holding the queue.
	 */
	const OPTION = 'indexpages_queue';

	/**
	 * Upper bound on queued posts, to keep the option small.
	 */
	const MAX_SIZE = 5000;

	/**
	 * The queue, read fresh from the database, oldest first.
	 *
	 * @return array
	 */
	public static function all() {
		wp_cache_delete( self::OPTION, 'options' );
		$queue = get_option( self::OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Number of queued posts.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::all() );
	}

	/**
	 * Whether a post is queued.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function has( $post_id ) {
		$queue = self::all();
		return isset( $queue[ (int) $post_id ] );
	}

	/**
	 * Saves the queue.
	 *
	 * @param array $queue Queue.
	 * @return void
	 */
	private static function save( array $queue ) {
		if ( empty( $queue ) ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $queue, false );
	}

	/**
	 * Tries to queue a post.
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $reason publish, update or manual.
	 * @return string Empty when queued or already queued, otherwise why it was not.
	 */
	public static function offer( WP_Post $post, $reason ) {
		$skip = IndexPages_Eligibility::reason( $post );
		if ( '' !== $skip ) {
			return $skip;
		}

		$queue = self::all();
		if ( isset( $queue[ $post->ID ] ) ) {
			return '';
		}

		$wait = self::interval_reason( $post );
		if ( '' !== $wait ) {
			return $wait;
		}

		if ( count( $queue ) >= self::MAX_SIZE ) {
			return __( 'The queue is full. Send or clear it first.', 'indexpages' );
		}

		$queue[ $post->ID ] = array(
			'time'   => time(),
			'reason' => $reason,
		);
		self::save( $queue );
		IndexPages_State::mark_post( $post->ID, 'queued' );
		return '';
	}

	/**
	 * Why the post is still within its minimum interval, or an empty string.
	 *
	 * The interval applies per URL: a post whose address changed may be sent again.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function interval_reason( WP_Post $post ) {
		$sent = (int) get_post_meta( $post->ID, IndexPages_State::META_SENT, true );
		if ( $sent <= 0 ) {
			return '';
		}
		$last_url = (string) get_post_meta( $post->ID, IndexPages_State::META_URL, true );
		if ( '' !== $last_url && IndexPages_Eligibility::url( $post ) !== $last_url ) {
			return '';
		}
		$days = max( 1, (int) IndexPages_Settings::get( 'update_interval' ) );
		$next = $sent + $days * DAY_IN_SECONDS;
		if ( time() >= $next ) {
			return '';
		}
		return sprintf(
			/* translators: 1: date the URL was last sent, 2: date it may be sent again. */
			__( 'Already sent on %1$s; it can be sent again after %2$s.', 'indexpages' ),
			wp_date( get_option( 'date_format' ), $sent ),
			wp_date( get_option( 'date_format' ), $next )
		);
	}

	/**
	 * Removes posts from the queue.
	 *
	 * @param int[] $post_ids     Post ids.
	 * @param bool  $reset_status Also drop the "queued" status from the posts.
	 * @return void
	 */
	public static function remove( array $post_ids, $reset_status = false ) {
		$queue   = self::all();
		$changed = false;
		foreach ( $post_ids as $post_id ) {
			if ( isset( $queue[ (int) $post_id ] ) ) {
				unset( $queue[ (int) $post_id ] );
				$changed = true;
				if ( $reset_status && 'queued' === get_post_meta( (int) $post_id, IndexPages_State::META_STATUS, true ) ) {
					delete_post_meta( (int) $post_id, IndexPages_State::META_STATUS );
				}
			}
		}
		if ( $changed ) {
			self::save( $queue );
		}
	}

	/**
	 * Empties the queue.
	 *
	 * @return void
	 */
	public static function clear() {
		self::remove( array_keys( self::all() ), true );
	}
}
