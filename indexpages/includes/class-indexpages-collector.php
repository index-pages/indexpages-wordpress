<?php
/**
 * Collects URLs when posts are published or updated.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts published posts into the queue. Never makes an HTTP request: the save
 * request only writes to the database, the sending happens in WP-Cron.
 */
class IndexPages_Collector {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Handles a status change.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( 'publish' !== $new_status ) {
			if ( 'publish' === $old_status ) {
				IndexPages_Queue::remove( array( $post->ID ), true );
			}
			return;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( IndexPages_Settings::MODE_MANUAL === IndexPages_Settings::mode() || ! IndexPages_Settings::has_key() ) {
			return;
		}
		if ( ! in_array( $post->post_type, IndexPages_Settings::post_types(), true ) ) {
			return;
		}

		if ( 'publish' === $old_status ) {
			if ( ! IndexPages_Settings::get( 'send_updates' ) ) {
				return;
			}
			$reason = 'update';
		} else {
			$reason = 'publish';
		}

		IndexPages_Queue::offer( $post, $reason );
	}

	/**
	 * Drops a deleted post from the queue.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function on_delete( $post_id ) {
		IndexPages_Queue::remove( array( (int) $post_id ) );
	}
}
