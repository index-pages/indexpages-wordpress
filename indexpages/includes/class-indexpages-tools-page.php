<?php
/**
 * Tools → Index Pages: queue, orders, per-URL statuses.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The queue and orders screen and its form handlers.
 */
class IndexPages_Tools_Page {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		$actions = array( 'send_now', 'refresh', 'queue_remove', 'queue_clear', 'run_order', 'delete_order' );
		foreach ( $actions as $action ) {
			add_action( 'admin_post_indexpages_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Adds the page under Tools.
	 *
	 * @return void
	 */
	public static function menu() {
		add_management_page(
			__( 'Index Pages', 'indexpages' ),
			__( 'Index Pages', 'indexpages' ),
			'manage_options',
			IndexPages_Admin::TOOLS_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * An order id from the request, or an empty string.
	 *
	 * @param array $source $_GET or $_POST.
	 * @return string
	 */
	private static function order_id( array $source ) {
		$id = isset( $source['order'] ) ? sanitize_text_field( wp_unslash( $source['order'] ) ) : '';
		return preg_match( '/^[A-Za-z0-9]{1,64}$/', $id ) ? $id : '';
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only views.
		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$order = self::order_id( $_GET );
		// phpcs:enable

		echo '<div class="wrap indexpages">';
		echo '<h1>' . esc_html__( 'Index Pages', 'indexpages' ) . '</h1>';

		if ( ! IndexPages_Settings::has_key() ) {
			echo '<p>' . esc_html__( 'Add your API key first.', 'indexpages' ) . ' <a href="' . esc_url( IndexPages_Admin::settings_url() ) . '">' . esc_html__( 'Settings', 'indexpages' ) . '</a></p></div>';
			return;
		}

		if ( 'run' === $view && '' !== $order ) {
			self::render_confirm_run( $order );
		} elseif ( 'urls' === $view && '' !== $order ) {
			self::render_order_urls( $order );
		} else {
			self::render_main();
		}
		echo '</div>';
	}

	/**
	 * A POST button to admin-post.php.
	 *
	 * @param string $action  Action without the indexpages_ prefix.
	 * @param string $label   Button text.
	 * @param array  $fields  Hidden fields.
	 * @param string $class   Button classes.
	 * @param string $confirm Confirmation question, or empty.
	 * @return void
	 */
	private static function button( $action, $label, array $fields = array(), $class = 'button', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="indexpages-inline-form"';
		if ( '' !== $confirm ) {
			echo ' data-indexpages-confirm="' . esc_attr( $confirm ) . '"';
		}
		echo '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'indexpages_' . $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		wp_nonce_field( 'indexpages_' . $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Queue, orders and activity.
	 *
	 * @return void
	 */
	private static function render_main() {
		$queue   = IndexPages_Queue::all();
		$pending = IndexPages_State::get( 'pending' );
		$mode    = IndexPages_Settings::mode();

		echo '<div class="indexpages-toolbar">';
		self::button( 'send_now', __( 'Send now', 'indexpages' ), array(), 'button button-primary' );
		self::button( 'refresh', __( 'Refresh', 'indexpages' ) );
		echo '<a class="button-link" href="' . esc_url( IndexPages_Admin::settings_url() ) . '">' . esc_html__( 'Settings', 'indexpages' ) . '</a>';
		echo '</div>';

		echo '<p class="description">';
		if ( IndexPages_Settings::MODE_AUTOMATIC === $mode ) {
			echo esc_html(
				sprintf(
					/* translators: 1: URLs run today, 2: daily cap. */
					__( 'Automatic mode: "Send now" creates an order from the queue and runs it, charging the balance. Run today: %1$d of %2$d URLs.', 'indexpages' ),
					IndexPages_State::spent_today(),
					(int) IndexPages_Settings::get( 'daily_cap' )
				)
			);
		} else {
			esc_html_e( '"Send now" creates a free draft order from the queue. Nothing is charged until you run the draft.', 'indexpages' );
		}
		echo '</p>';

		if ( is_array( $pending ) && ! empty( $pending['items'] ) ) {
			echo '<div class="notice notice-info inline"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: number of URLs. */
					__( 'A batch of %d URLs is waiting to be confirmed by Index Pages after a failed attempt. The next run repeats the same request safely; it cannot create or charge twice.', 'indexpages' ),
					count( $pending['items'] )
				)
			);
			echo '</p></div>';
		}

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %d: number of queued posts. */
				__( 'Queue (%d)', 'indexpages' ),
				count( $queue )
			)
		) . '</h2>';

		if ( empty( $queue ) ) {
			echo '<p>' . esc_html__( 'The queue is empty.', 'indexpages' ) . '</p>';
		} else {
			$reasons = array(
				'publish' => __( 'Published', 'indexpages' ),
				'update'  => __( 'Updated', 'indexpages' ),
				'manual'  => __( 'Added by hand', 'indexpages' ),
			);
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Post', 'indexpages' ) . '</th><th>' . esc_html__( 'URL', 'indexpages' ) . '</th><th>' . esc_html__( 'Why', 'indexpages' ) . '</th><th>' . esc_html__( 'Queued', 'indexpages' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $queue, 0, 200, true ) as $post_id => $entry ) {
				$post = get_post( $post_id );
				echo '<tr><td>';
				if ( $post ) {
					echo '<a href="' . esc_url( (string) get_edit_post_link( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
				} else {
					echo '#' . esc_html( (string) $post_id );
				}
				echo '</td><td><code>' . esc_html( $post ? IndexPages_Eligibility::url( $post ) : '' ) . '</code></td>';
				$why = isset( $entry['reason'], $reasons[ $entry['reason'] ] ) ? $reasons[ $entry['reason'] ] : '';
				echo '<td>' . esc_html( $why ) . '</td>';
				echo '<td>' . esc_html( IndexPages_Admin::date( isset( $entry['time'] ) ? $entry['time'] : 0 ) ) . '</td><td>';
				self::button( 'queue_remove', __( 'Remove', 'indexpages' ), array( 'post_id' => (string) $post_id ), 'button-link' );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			if ( count( $queue ) > 200 ) {
				/* translators: %d: number of queued posts not shown. */
				echo '<p>' . esc_html( sprintf( __( 'And %d more.', 'indexpages' ), count( $queue ) - 200 ) ) . '</p>';
			}
			echo '<p>';
			self::button( 'queue_clear', __( 'Clear the queue', 'indexpages' ), array(), 'button', __( 'Remove every post from the queue?', 'indexpages' ) );
			echo '</p>';
		}

		self::render_orders();
		self::render_log();
	}

	/**
	 * Recent orders from the API.
	 *
	 * @return void
	 */
	private static function render_orders() {
		echo '<h2>' . esc_html__( 'Recent orders', 'indexpages' ) . '</h2>';

		$orders = get_transient( IndexPages_State::ORDERS_TRANSIENT );
		if ( ! is_array( $orders ) ) {
			$api    = IndexPages_Api::from_settings();
			$orders = $api ? $api->list_orders( array( 'limit' => 20 ) ) : array();
			if ( is_wp_error( $orders ) ) {
				$text = IndexPages_Errors::describe( $orders );
				IndexPages_Admin::notice_box( 'error', '<p>' . esc_html( $text['message'] ) . '</p><p>' . esc_html( $text['hint'] ) . '</p>', false );
				return;
			}
			set_transient( IndexPages_State::ORDERS_TRANSIENT, $orders, 2 * MINUTE_IN_SECONDS );
		}

		$data = isset( $orders['data'] ) && is_array( $orders['data'] ) ? $orders['data'] : array();
		if ( empty( $data ) ) {
			echo '<p>' . esc_html__( 'No orders yet.', 'indexpages' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'indexpages' ) . '</th><th>' . esc_html__( 'Status', 'indexpages' ) . '</th><th>' . esc_html__( 'Done', 'indexpages' ) . '</th><th>' . esc_html__( 'Cost', 'indexpages' ) . '</th><th>' . esc_html__( 'Created', 'indexpages' ) . '</th><th>' . esc_html__( 'Actions', 'indexpages' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $data as $order ) {
			if ( ! is_array( $order ) || empty( $order['id'] ) ) {
				continue;
			}
			$id = (string) $order['id'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( isset( $order['title'] ) ? $order['title'] : '' ) . '</strong><br><code>' . esc_html( $id ) . '</code></td>';
			echo '<td><span class="indexpages-status indexpages-status-' . esc_attr( (string) $order['status'] ) . '">' . esc_html( IndexPages_Admin::order_status_label( (string) $order['status'] ) ) . '</span></td>';
			echo '<td>' . esc_html( sprintf( '%d / %d', (int) $order['urls_done'], (int) $order['urls_total'] ) ) . '</td>';
			echo '<td>' . esc_html( IndexPages_Admin::money( (string) $order['cost'], (string) $order['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( IndexPages_Admin::date( (string) $order['created_at'] ) ) . '</td><td>';
			echo '<a href="' . esc_url(
				IndexPages_Admin::tools_url(
					array(
						'view'  => 'urls',
						'order' => $id,
					)
				)
			) . '">' . esc_html__( 'URL statuses', 'indexpages' ) . '</a>';
			if ( 'draft' === $order['status'] ) {
				echo ' | <a href="' . esc_url(
					IndexPages_Admin::tools_url(
						array(
							'view'  => 'run',
							'order' => $id,
						)
					)
				) . '">' . esc_html__( 'Run…', 'indexpages' ) . '</a> | ';
				self::button( 'delete_order', __( 'Delete draft', 'indexpages' ), array( 'order' => $id ), 'button-link indexpages-danger', __( 'Delete this draft order? It has not been paid for.', 'indexpages' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The activity log.
	 *
	 * @return void
	 */
	private static function render_log() {
		$log = IndexPages_State::get_log();
		echo '<h2>' . esc_html__( 'Activity', 'indexpages' ) . '</h2>';
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'Nothing yet.', 'indexpages' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $log as $entry ) {
			echo '<tr class="indexpages-log-' . esc_attr( (string) $entry['type'] ) . '"><td class="indexpages-nowrap">' . esc_html( IndexPages_Admin::date( $entry['time'] ) ) . '</td><td>' . esc_html( (string) $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Confirmation before running a draft: cost against balance.
	 *
	 * @param string $order_id Order id.
	 * @return void
	 */
	private static function render_confirm_run( $order_id ) {
		$back = '<p><a href="' . esc_url( IndexPages_Admin::tools_url() ) . '">' . esc_html__( '← Back to the queue and orders', 'indexpages' ) . '</a></p>';
		$api  = IndexPages_Api::from_settings();
		if ( null === $api ) {
			return;
		}
		$order = $api->get_order( $order_id );
		if ( is_wp_error( $order ) ) {
			$text = IndexPages_Errors::describe( $order );
			IndexPages_Admin::notice_box( 'error', '<p>' . esc_html( $text['message'] ) . '</p>', false );
			echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			return;
		}

		echo '<h2>' . esc_html__( 'Run this order?', 'indexpages' ) . '</h2>';
		if ( 'draft' !== $order['status'] ) {
			echo '<p>' . esc_html__( 'This order is not a draft, so it cannot be run again.', 'indexpages' ) . '</p>';
			echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			return;
		}

		delete_transient( IndexPages_State::ACCOUNT_TRANSIENT );
		$account = IndexPages_Admin::account();
		$cost    = IndexPages_Sender::cents( $order['cost'] );
		$balance = is_array( $account ) && isset( $account['balance'] ) ? IndexPages_Sender::cents( $account['balance'] ) : null;

		echo '<table class="widefat striped indexpages-account"><tbody>';
		echo '<tr><th>' . esc_html__( 'Order', 'indexpages' ) . '</th><td>' . esc_html( $order['title'] ) . ' <code>' . esc_html( $order['id'] ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'URLs', 'indexpages' ) . '</th><td>' . esc_html( (string) (int) $order['urls_total'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Cost', 'indexpages' ) . '</th><td><strong>' . esc_html( IndexPages_Admin::money( $order['cost'], $order['currency'] ) ) . '</strong></td></tr>';
		if ( null !== $balance ) {
			echo '<tr><th>' . esc_html__( 'Balance now', 'indexpages' ) . '</th><td>' . esc_html( IndexPages_Admin::money( $account['balance'], $account['currency'] ) ) . '</td></tr>';
			if ( null !== $cost && $balance >= $cost ) {
				$after = number_format( ( $balance - $cost ) / 100, 2, '.', '' );
				echo '<tr><th>' . esc_html__( 'Balance after the run', 'indexpages' ) . '</th><td>' . esc_html( IndexPages_Admin::money( $after, $account['currency'] ) ) . '</td></tr>';
			}
		} else {
			echo '<tr><th>' . esc_html__( 'Balance now', 'indexpages' ) . '</th><td>' . esc_html__( 'unknown (the key cannot read the balance)', 'indexpages' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( null !== $balance && null !== $cost && $balance < $cost ) {
			IndexPages_Admin::notice_box( 'error', '<p>' . esc_html__( 'The balance is too low for this order. Top it up in the Index Pages dashboard first.', 'indexpages' ) . '</p>', false );
			echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			return;
		}

		echo '<p>' . esc_html__( 'Running the order charges its cost to your balance and starts the work. This cannot be undone.', 'indexpages' ) . '</p>';
		echo '<p>';
		self::button(
			'run_order',
			/* translators: %s: amount. */
			sprintf( __( 'Run and pay %s', 'indexpages' ), IndexPages_Admin::money( $order['cost'], $order['currency'] ) ),
			array(
				'order'           => $order['id'],
				'idempotency_key' => IndexPages_Api::new_idempotency_key(),
			),
			'button button-primary'
		);
		echo '</p>';
		echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	/**
	 * Per-URL statuses of one order, a page at a time.
	 *
	 * @param string $order_id Order id.
	 * @return void
	 */
	private static function render_order_urls( $order_id ) {
		echo '<p><a href="' . esc_url( IndexPages_Admin::tools_url() ) . '">' . esc_html__( '← Back to the queue and orders', 'indexpages' ) . '</a></p>';
		$api = IndexPages_Api::from_settings();
		if ( null === $api ) {
			return;
		}
		$order = $api->get_order( $order_id );
		if ( is_wp_error( $order ) ) {
			$text = IndexPages_Errors::describe( $order );
			IndexPages_Admin::notice_box( 'error', '<p>' . esc_html( $text['message'] ) . '</p>', false );
			return;
		}

		// Read-only pagination; the cursor is reduced to the characters a cursor can hold.
		$cursor = isset( $_GET['cursor'] ) && is_string( $_GET['cursor'] ) ? preg_replace( '/[^A-Za-z0-9_\-=.+\/]/', '', wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$query  = array( 'limit' => 100 );
		if ( '' !== $cursor ) {
			$query['cursor'] = $cursor;
		}
		$urls = $api->list_order_urls( $order_id, $query );

		echo '<h2>' . esc_html( $order['title'] ) . ' <code>' . esc_html( $order['id'] ) . '</code></h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: status, 2: done URLs, 3: total URLs, 4: cost. */
				__( 'Status: %1$s. Done: %2$d of %3$d. Cost: %4$s.', 'indexpages' ),
				IndexPages_Admin::order_status_label( $order['status'] ),
				(int) $order['urls_done'],
				(int) $order['urls_total'],
				IndexPages_Admin::money( $order['cost'], $order['currency'] )
			)
		) . '</p>';

		if ( is_wp_error( $urls ) ) {
			$text = IndexPages_Errors::describe( $urls );
			IndexPages_Admin::notice_box( 'error', '<p>' . esc_html( $text['message'] ) . '</p>', false );
			return;
		}
		$data = isset( $urls['data'] ) && is_array( $urls['data'] ) ? $urls['data'] : array();
		if ( empty( $data ) ) {
			echo '<p>' . esc_html__( 'No per-URL details. They are kept until three days after the order\'s campaign ends; the counters above remain.', 'indexpages' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'URL', 'indexpages' ) . '</th><th>' . esc_html__( 'Status', 'indexpages' ) . '</th></tr></thead><tbody>';
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			echo '<tr><td><code>' . esc_html( (string) $row['url'] ) . '</code></td><td>' . esc_html( IndexPages_Admin::order_status_label( (string) $row['status'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $urls['has_more'] ) && ! empty( $urls['next_cursor'] ) ) {
			echo '<p><a class="button" href="' . esc_url(
				IndexPages_Admin::tools_url(
					array(
						'view'   => 'urls',
						'order'  => $order_id,
						'cursor' => rawurlencode( (string) $urls['next_cursor'] ),
					)
				)
			) . '">' . esc_html__( 'Next page', 'indexpages' ) . '</a></p>';
		}
	}

	/**
	 * Common checks for every handler.
	 *
	 * @param string $action Action without the prefix.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'indexpages' ), 403 );
		}
		check_admin_referer( 'indexpages_' . $action );
	}

	/**
	 * Redirects back to the tools page.
	 *
	 * @return void
	 */
	private static function back() {
		wp_safe_redirect( IndexPages_Admin::tools_url() );
		exit;
	}

	/**
	 * "Send now".
	 *
	 * @return void
	 */
	public static function handle_send_now() {
		self::guard( 'send_now' );
		$result = IndexPages_Sender::flush( 'manual' );
		IndexPages_Admin::flash( IndexPages_Admin::level_for( $result ), $result['message'] );
		self::back();
	}

	/**
	 * "Refresh": drop the cached account and orders.
	 *
	 * @return void
	 */
	public static function handle_refresh() {
		self::guard( 'refresh' );
		IndexPages_State::flush_cache();
		self::back();
	}

	/**
	 * Removes one post from the queue.
	 *
	 * @return void
	 */
	public static function handle_queue_remove() {
		self::guard( 'queue_remove' );
		check_admin_referer( 'indexpages_queue_remove' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id ) {
			IndexPages_Queue::remove( array( $post_id ), true );
		}
		self::back();
	}

	/**
	 * Empties the queue.
	 *
	 * @return void
	 */
	public static function handle_queue_clear() {
		self::guard( 'queue_clear' );
		IndexPages_Queue::clear();
		IndexPages_Admin::flash( 'success', __( 'The queue was cleared.', 'indexpages' ) );
		self::back();
	}

	/**
	 * Runs a draft after the confirmation screen.
	 *
	 * @return void
	 */
	public static function handle_run_order() {
		self::guard( 'run_order' );
		check_admin_referer( 'indexpages_run_order' );
		$order_id = self::order_id( $_POST );
		$key      = isset( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '';
		if ( '' === $order_id || ! wp_is_uuid( $key ) ) {
			IndexPages_Admin::flash( 'error', __( 'The request was incomplete. Try again.', 'indexpages' ) );
			self::back();
		}
		$order = IndexPages_Sender::run_draft( $order_id, $key );
		if ( is_wp_error( $order ) ) {
			$text = IndexPages_Errors::describe( $order );
			IndexPages_Admin::flash( 'error', $text['message'] . ' ' . $text['hint'] );
		} else {
			IndexPages_Admin::flash(
				'success',
				sprintf(
					/* translators: 1: order id, 2: amount. */
					__( 'Order %1$s is running; %2$s was charged.', 'indexpages' ),
					$order_id,
					IndexPages_Admin::money( (string) $order['cost'], (string) $order['currency'] )
				)
			);
		}
		self::back();
	}

	/**
	 * Deletes a draft.
	 *
	 * @return void
	 */
	public static function handle_delete_order() {
		self::guard( 'delete_order' );
		check_admin_referer( 'indexpages_delete_order' );
		$order_id = self::order_id( $_POST );
		$api      = IndexPages_Api::from_settings();
		if ( '' === $order_id || null === $api ) {
			self::back();
		}
		$result = $api->delete_order( $order_id );
		IndexPages_State::flush_cache();
		if ( is_wp_error( $result ) ) {
			$text = IndexPages_Errors::describe( $result );
			IndexPages_Admin::flash( 'error', $text['message'] . ' ' . $text['hint'] );
		} else {
			/* translators: %s: order id. */
			$message = sprintf( __( 'Draft %s was deleted.', 'indexpages' ), $order_id );
			IndexPages_Admin::flash( 'success', $message );
			IndexPages_State::log( 'order_deleted', $message );
			$last = IndexPages_State::get( 'last_order' );
			if ( is_array( $last ) && isset( $last['id'] ) && $last['id'] === $order_id ) {
				IndexPages_State::set( 'last_order', null );
			}
		}
		self::back();
	}
}
