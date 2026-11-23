<?php
/**
 * Posts list: column, row action, bulk action.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Index Pages column and the send actions to the enabled post types.
 */
class IndexPages_Posts_List {

	/**
	 * Column and bulk action id.
	 */
	const ID = 'indexpages';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_indexpages_send_post', array( __CLASS__, 'handle_row_action' ) );
	}

	/**
	 * Hooks into the list screens of the enabled post types.
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( IndexPages_Settings::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$type}", array( __CLASS__, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		}
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
	}

	/**
	 * Whether the current user may send a post.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function user_can_send( WP_Post $post ) {
		$type = get_post_type_object( $post->post_type );
		$can  = $type && current_user_can( 'edit_post', $post->ID ) && current_user_can( $type->cap->publish_posts );

		/**
		 * Filters whether the current user may send a post to Index Pages from
		 * the posts list. Default: the user can edit the post and publish posts
		 * of its type.
		 *
		 * @param bool    $can  Whether the user may send.
		 * @param WP_Post $post The post.
		 */
		return (bool) apply_filters( 'indexpages_user_can_send', $can, $post );
	}

	/**
	 * Adds the column (hideable in Screen Options like any other).
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns[ self::ID ] = __( 'Index Pages', 'indexpages' );
		return $columns;
	}

	/**
	 * Prints the column cell.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::ID !== $column ) {
			return;
		}
		$status = (string) get_post_meta( $post_id, IndexPages_State::META_STATUS, true );
		if ( '' === $status ) {
			echo '<span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'Not sent', 'indexpages' ) . '</span>';
			return;
		}
		echo '<span class="indexpages-status indexpages-status-' . esc_attr( $status ) . '">' . esc_html( IndexPages_Admin::post_status_label( $status ) ) . '</span>';

		$sent = (int) get_post_meta( $post_id, IndexPages_State::META_SENT, true );
		if ( $sent > 0 ) {
			echo '<br><span class="indexpages-muted">' . esc_html( IndexPages_Admin::date( $sent ) ) . '</span>';
		}
		$order = (string) get_post_meta( $post_id, IndexPages_State::META_ORDER, true );
		if ( '' !== $order && current_user_can( 'manage_options' ) ) {
			echo '<br><a href="' . esc_url(
				IndexPages_Admin::tools_url(
					array(
						'view'  => 'urls',
						'order' => $order,
					)
				)
			) . '"><code>' . esc_html( $order ) . '</code></a>';
		}
		$message = (string) get_post_meta( $post_id, IndexPages_State::META_MESSAGE, true );
		if ( '' !== $message ) {
			echo '<br><span class="indexpages-muted">' . esc_html( $message ) . '</span>';
		}
	}

	/**
	 * Adds the bulk action.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function add_bulk_action( $actions ) {
		$actions[ self::ID ] = __( 'Send to Index Pages', 'indexpages' );
		return $actions;
	}

	/**
	 * Handles the bulk action. WordPress has already checked the bulk-posts nonce.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action.
	 * @param int[]  $post_ids    Selected posts.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( self::ID !== $action ) {
			return $redirect_to;
		}
		self::send( array_map( 'absint', (array) $post_ids ) );
		return $redirect_to;
	}

	/**
	 * Adds the row action.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function add_row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return $actions;
		}
		if ( ! in_array( $post->post_type, IndexPages_Settings::post_types(), true ) || ! self::user_can_send( $post ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'indexpages_send_post',
					'post'   => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'indexpages_send_post_' . $post->ID
		);
		$actions[ self::ID ] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Send to Index Pages', 'indexpages' ) . '</a>';
		return $actions;
	}

	/**
	 * Handles the row action link.
	 *
	 * @return void
	 */
	public static function handle_row_action() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'indexpages_send_post_' . $post_id );
		$post = get_post( $post_id );
		if ( ! $post || ! self::user_can_send( $post ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'indexpages' ), 403 );
		}
		self::send( array( $post_id ) );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'edit.php?post_type=' . $post->post_type ) );
		exit;
	}

	/**
	 * Queues the posts, sends the queue right away and leaves a message.
	 *
	 * The request is a deliberate click, so it waits for the API; saving a post
	 * never does.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return void
	 */
	private static function send( array $post_ids ) {
		$queued  = 0;
		$skipped = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			if ( ! self::user_can_send( $post ) ) {
				$skipped[] = get_the_title( $post ) . ': ' . __( 'you are not allowed to send it', 'indexpages' );
				continue;
			}
			$reason = IndexPages_Queue::offer( $post, 'manual' );
			if ( '' === $reason ) {
				++$queued;
			} else {
				$skipped[] = get_the_title( $post ) . ': ' . rtrim( $reason, '.' );
			}
		}

		$parts = array();
		if ( $queued > 0 ) {
			/* translators: %d: number of posts. */
			$parts[] = sprintf( _n( '%d post queued.', '%d posts queued.', $queued, 'indexpages' ), $queued );
		}
		if ( $skipped ) {
			/* translators: %d: number of posts. */
			$parts[] = sprintf( _n( '%d post skipped:', '%d posts skipped:', count( $skipped ), 'indexpages' ), count( $skipped ) );
			$parts[] = implode( '; ', array_slice( $skipped, 0, 5 ) ) . ( count( $skipped ) > 5 ? '; …' : '.' );
		}

		$level = $skipped ? 'warning' : 'success';
		if ( $queued > 0 ) {
			$result  = IndexPages_Sender::flush( 'manual' );
			$parts[] = $result['message'];
			$level   = 'ok' === $result['status'] && ! $skipped ? 'success' : IndexPages_Admin::level_for( $result );
			if ( 'ok' === $result['status'] && $skipped ) {
				$level = 'warning';
			}
		}
		IndexPages_Admin::flash( $level, implode( ' ', $parts ) );
	}
}
