<?php
/**
 * Turns the queue into orders.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds one order per run from the queue and, in Automatic mode, runs it.
 *
 * A batch is written to the state before the first request and carries its
 * own Idempotency-Keys and the exact request body. A run that fails on the
 * network or with a 5xx leaves the batch in place, and the next run repeats
 * the same request with the same key, so the service replays the first answer
 * instead of creating or charging a second time.
 */
class IndexPages_Sender {

	/**
	 * How often one run rebuilds a batch after URLs were rejected.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Sends the queue.
	 *
	 * @param string $trigger cron (scheduled) or manual (a user clicked something).
	 * @return array { status: ok|idle|waiting|error, message: string }
	 */
	public static function flush( $trigger = 'cron' ) {
		$api = IndexPages_Api::from_settings();
		if ( null === $api ) {
			return self::result( 'idle', __( 'No API key is set.', 'indexpages' ) );
		}

		$mode = IndexPages_Settings::mode();
		if ( 'cron' === $trigger ) {
			if ( IndexPages_Settings::MODE_MANUAL === $mode ) {
				return self::result( 'idle', __( 'Manual mode: nothing is sent automatically.', 'indexpages' ) );
			}
			if ( IndexPages_State::get( 'auth_failed' ) === IndexPages_Settings::key_fingerprint() ) {
				return self::result( 'error', __( 'Paused: the API key was rejected. Enter a new key.', 'indexpages' ) );
			}
		}

		$retry_after = (int) IndexPages_State::get( 'retry_after', 0 );
		if ( $retry_after > time() ) {
			return self::result(
				'waiting',
				/* translators: %d: seconds. */
				sprintf( __( 'Rate limited by Index Pages; trying again in %d seconds.', 'indexpages' ), $retry_after - time() )
			);
		}

		if ( ! IndexPages_State::lock() ) {
			return self::result( 'waiting', __( 'Another run is in progress. Try again in a minute.', 'indexpages' ) );
		}

		try {
			$pending = IndexPages_State::get( 'pending' );
			if ( is_array( $pending ) && ! empty( $pending['items'] ) ) {
				$result = self::continue_batch( $api, $pending );
			} else {
				$result = self::new_batch( $api, $mode );
			}
		} finally {
			IndexPages_State::unlock();
		}

		return $result;
	}

	/**
	 * Builds a batch from the queue and sends it.
	 *
	 * @param IndexPages_Api $api  Client.
	 * @param string         $mode Mode.
	 * @return array
	 */
	private static function new_batch( IndexPages_Api $api, $mode ) {
		$queue = IndexPages_Queue::all();
		if ( empty( $queue ) ) {
			return self::result( 'idle', __( 'The queue is empty.', 'indexpages' ) );
		}

		$min      = 1;
		$max      = 1000;
		$balance  = null;
		$price    = null;
		$currency = 'USD';
		$account  = $api->get_account();
		if ( is_wp_error( $account ) ) {
			// A key without balance:read can still create and run orders.
			if ( 'forbidden_scope' !== $account->get_error_code() ) {
				return self::fail( $account );
			}
		} else {
			set_transient( IndexPages_State::ACCOUNT_TRANSIENT, $account, 10 * MINUTE_IN_SECONDS );
			if ( isset( $account['limits']['min_urls_per_order'] ) ) {
				$min = max( 1, (int) $account['limits']['min_urls_per_order'] );
			}
			if ( isset( $account['limits']['max_urls_per_order'] ) ) {
				$max = max( 1, (int) $account['limits']['max_urls_per_order'] );
			}
			$balance  = isset( $account['balance'] ) ? self::cents( $account['balance'] ) : null;
			$price    = isset( $account['price_per_url'] ) ? self::cents( $account['price_per_url'] ) : null;
			$currency = isset( $account['currency'] ) ? (string) $account['currency'] : 'USD';
		}

		/**
		 * Filters the largest number of URLs put into one order.
		 *
		 * The account's own maximum always wins.
		 *
		 * @param int $max Maximum from the account.
		 */
		$max = min( $max, max( 1, (int) apply_filters( 'indexpages_max_urls_per_order', $max ) ) );

		$run = IndexPages_Settings::MODE_AUTOMATIC === $mode;
		if ( $run ) {
			$cap  = max( 1, (int) IndexPages_Settings::get( 'daily_cap' ) );
			$left = max( 0, $cap - IndexPages_State::spent_today() );
			if ( 0 === $left ) {
				$message = sprintf(
					/* translators: 1: daily cap in URLs, 2: number of queued URLs. */
					_n(
						'The daily spending cap of %1$d URLs is reached; %2$d queued URL waits until tomorrow.',
						'The daily spending cap of %1$d URLs is reached; %2$d queued URLs wait until tomorrow.',
						count( $queue ),
						'indexpages'
					),
					$cap,
					count( $queue )
				);
				IndexPages_State::set_notice( 'warning', 'cap_reached', $message, __( 'Raise the daily cap in Settings → Index Pages if you want them sent today.', 'indexpages' ) );
				return self::result( 'waiting', $message );
			}
			$max = min( $max, $left );

			if ( null !== $balance && null !== $price && $price > 0 ) {
				$affordable = (int) floor( $balance / $price );
				if ( $affordable < $min ) {
					$message = sprintf(
						/* translators: 1: balance, 2: currency, 3: price per URL. */
						__( 'Not enough balance to run an order: the balance is %1$s %2$s and one URL costs %3$s %2$s.', 'indexpages' ),
						$account['balance'],
						$currency,
						$account['price_per_url']
					);
					IndexPages_State::set_notice( 'error', 'insufficient_balance', $message, __( 'Top up the balance in the Index Pages dashboard. Queued URLs stay in the queue.', 'indexpages' ) );
					return self::result( 'error', $message );
				}
				$max = min( $max, $affordable );
			}
		}

		$items = array();
		$seen  = array();
		foreach ( array_keys( $queue ) as $post_id ) {
			if ( count( $items ) >= $max ) {
				break;
			}
			$post   = get_post( $post_id );
			$reason = IndexPages_Eligibility::reason( $post );
			if ( '' !== $reason ) {
				IndexPages_Queue::remove( array( $post_id ) );
				if ( $post ) {
					IndexPages_State::mark_post( $post_id, 'skipped', array( 'message' => $reason ) );
				}
				IndexPages_State::log( 'skipped', $reason, array( 'post' => (int) $post_id ) );
				continue;
			}
			$url = IndexPages_Eligibility::url( $post );
			if ( isset( $seen[ $url ] ) ) {
				IndexPages_Queue::remove( array( $post_id ) );
				continue;
			}
			$seen[ $url ]      = true;
			$items[ $post_id ] = $url;
		}

		if ( empty( $items ) ) {
			return self::result( 'idle', __( 'Nothing left to send: the queued posts are no longer eligible.', 'indexpages' ) );
		}
		if ( count( $items ) < $min ) {
			return self::result(
				'waiting',
				sprintf(
					/* translators: 1: number of URLs ready, 2: minimum URLs per order. */
					__( '%1$d URLs are ready; an order needs at least %2$d. They wait for more.', 'indexpages' ),
					count( $items ),
					$min
				)
			);
		}

		$batch = self::make_batch( $items, $run, $min );
		IndexPages_State::set( 'pending', $batch );
		return self::continue_batch( $api, $batch );
	}

	/**
	 * Creates (and runs) the order of a stored batch.
	 *
	 * @param IndexPages_Api $api   Client.
	 * @param array          $batch Batch.
	 * @return array
	 */
	private static function continue_batch( IndexPages_Api $api, array $batch ) {
		$attempt = 0;
		while ( empty( $batch['order'] ) ) {
			++$attempt;
			$order = $api->create_order( $batch['body'], $batch['create_key'] );

			if ( ! is_wp_error( $order ) ) {
				if ( empty( $order['id'] ) ) {
					IndexPages_State::set( 'pending', null );
					return self::fail( new WP_Error( 'indexpages_bad_response', __( 'The service sent a response that could not be read.', 'indexpages' ) ) );
				}
				$batch['order'] = (string) $order['id'];
				IndexPages_State::set( 'pending', $batch );
				self::after_created( $batch, $order );
				break;
			}

			if ( IndexPages_Api::is_transient_error( $order ) || 'rate_limited' === $order->get_error_code() ) {
				// Keep the batch and its key: the next run repeats the same request.
				return self::fail( $order );
			}

			IndexPages_State::set( 'pending', null );

			if ( 'validation_failed' !== $order->get_error_code() ) {
				return self::fail( $order );
			}

			$items = self::drop_rejected( $batch['items'], IndexPages_Api::error_problem( $order ) );
			if ( null === $items ) {
				return self::fail( $order );
			}
			if ( empty( $items ) ) {
				$message = __( 'Every URL in the batch was rejected. The reasons are in the activity log.', 'indexpages' );
				IndexPages_State::set_notice( 'error', 'all_rejected', $message, __( 'Check the rejected URLs on Tools → Index Pages.', 'indexpages' ) );
				return self::result( 'error', $message );
			}
			if ( count( $items ) < (int) $batch['min'] || $attempt >= self::MAX_ATTEMPTS ) {
				return self::result( 'waiting', __( 'Some URLs were rejected; the rest stay in the queue for the next run.', 'indexpages' ) );
			}

			// The body changed, so the old key must not be reused.
			$batch = self::make_batch( $items, ! empty( $batch['run'] ), (int) $batch['min'] );
			IndexPages_State::set( 'pending', $batch );
		}

		$count = count( $batch['items'] );

		if ( empty( $batch['run'] ) ) {
			IndexPages_State::set( 'pending', null );
			IndexPages_State::clear_notice();
			return self::result(
				'ok',
				sprintf(
					/* translators: 1: order id, 2: number of URLs. */
					_n(
						'Draft order %1$s was created with %2$d URL. A draft costs nothing until it is run.',
						'Draft order %1$s was created with %2$d URLs. A draft costs nothing until it is run.',
						$count,
						'indexpages'
					),
					$batch['order'],
					$count
				)
			);
		}

		if ( IndexPages_Settings::MODE_AUTOMATIC !== IndexPages_Settings::mode() ) {
			IndexPages_State::set( 'pending', null );
			/* translators: %s: order id. */
			return self::result( 'ok', sprintf( __( 'Order %s was left as a draft because the mode is no longer Automatic.', 'indexpages' ), $batch['order'] ) );
		}

		$run = $api->run_order( $batch['order'], $batch['run_key'] );
		if ( is_wp_error( $run ) ) {
			if ( IndexPages_Api::is_transient_error( $run ) || 'rate_limited' === $run->get_error_code() ) {
				return self::fail( $run );
			}
			if ( 'order_not_executable' === $run->get_error_code() ) {
				$current = $api->get_order( $batch['order'] );
				if ( ! is_wp_error( $current ) && isset( $current['status'] ) && 'draft' !== $current['status'] ) {
					$run = $current;
				}
			}
		}
		IndexPages_State::set( 'pending', null );
		if ( is_wp_error( $run ) ) {
			return self::fail( $run );
		}

		self::after_run( $batch, $run );
		IndexPages_State::clear_notice();
		return self::result(
			'ok',
			sprintf(
				/* translators: 1: order id, 2: number of URLs, 3: amount charged, for example $3.00. */
				_n(
					'Order %1$s with %2$d URL was run; %3$s was charged.',
					'Order %1$s with %2$d URLs was run; %3$s was charged.',
					$count,
					'indexpages'
				),
				$batch['order'],
				$count,
				self::charged( $run )
			)
		);
	}

	/**
	 * A new batch with fresh Idempotency-Keys and a frozen request body.
	 *
	 * @param array $items Post id => URL.
	 * @param bool  $run   Whether to run the order after creating it.
	 * @param int   $min   Minimum URLs per order.
	 * @return array
	 */
	private static function make_batch( array $items, $run, $min ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		/**
		 * Filters the title of an order created by the plugin.
		 *
		 * @param string $title Default: the site host and the date.
		 * @param array  $urls  The URLs in the order.
		 */
		$title = apply_filters(
			'indexpages_order_title',
			sprintf( '%1$s – %2$s', $host, wp_date( 'Y-m-d H:i' ) ),
			array_values( $items )
		);

		$body = wp_json_encode(
			array(
				'urls'    => array_values( $items ),
				'title'   => mb_substr( (string) $title, 0, 255 ),
				'comment' => 'WordPress plugin',
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return array(
			'items'      => $items,
			'body'       => $body,
			'create_key' => IndexPages_Api::new_idempotency_key(),
			'run'        => (bool) $run,
			'run_key'    => IndexPages_Api::new_idempotency_key(),
			'order'      => null,
			'min'        => (int) $min,
			'time'       => time(),
		);
	}

	/**
	 * Removes the URLs the service rejected and records why.
	 *
	 * @param array $items   Post id => URL, in request order.
	 * @param array $problem Problem body of the 422.
	 * @return array|null The remaining items, or null when the problem is not about single URLs.
	 */
	private static function drop_rejected( array $items, array $problem ) {
		if ( empty( $problem['errors'] ) || ! is_array( $problem['errors'] ) ) {
			return null;
		}
		$ids      = array_keys( $items );
		$rejected = array();
		foreach ( $problem['errors'] as $error ) {
			if ( ! is_array( $error ) || ! isset( $error['field'] ) || ! preg_match( '/^urls\[(\d+)\]$/', (string) $error['field'], $m ) ) {
				return null;
			}
			$index = (int) $m[1];
			if ( ! isset( $ids[ $index ] ) ) {
				return null;
			}
			$rejected[ $ids[ $index ] ] = array(
				'code'    => isset( $error['code'] ) ? (string) $error['code'] : '',
				'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
			);
		}

		$notice = IndexPages_State::get( 'rejected', array() );
		if ( ! is_array( $notice ) ) {
			$notice = array();
		}
		foreach ( $rejected as $post_id => $error ) {
			$url    = $items[ $post_id ];
			$reason = IndexPages_Errors::url_reason( $error['code'], $error['message'] );
			IndexPages_Queue::remove( array( $post_id ) );
			IndexPages_State::mark_post(
				$post_id,
				'rejected',
				array(
					'url'     => $url,
					'message' => $reason,
				)
			);
			/* translators: 1: URL, 2: reason. */
			IndexPages_State::log( 'rejected', sprintf( __( '%1$s was rejected: %2$s', 'indexpages' ), $url, $reason ), array( 'post' => (int) $post_id ) );
			array_unshift(
				$notice,
				array(
					'url'    => $url,
					'reason' => $reason,
				)
			);

			/**
			 * Fires when Index Pages rejects a URL.
			 *
			 * @param string $url     The URL.
			 * @param string $code    Rejection code, for example private_host.
			 * @param string $reason  Readable reason.
			 * @param int    $post_id Post id.
			 */
			do_action( 'indexpages_url_rejected', $url, $error['code'], $reason, (int) $post_id );
			unset( $items[ $post_id ] );
		}
		IndexPages_State::set( 'rejected', array_slice( $notice, 0, 10 ) );

		return $items;
	}

	/**
	 * Bookkeeping after an order was created.
	 *
	 * @param array $batch Batch.
	 * @param array $order Order from the API.
	 * @return void
	 */
	private static function after_created( array $batch, array $order ) {
		IndexPages_Queue::remove( array_keys( $batch['items'] ) );
		foreach ( $batch['items'] as $post_id => $url ) {
			IndexPages_State::mark_post(
				$post_id,
				'draft',
				array(
					'url'   => $url,
					'order' => $batch['order'],
					'sent'  => true,
				)
			);
		}
		self::remember_order( $order );
		IndexPages_State::log(
			'order_created',
			sprintf(
				/* translators: 1: order id, 2: number of URLs. */
				_n( 'Draft order %1$s created with %2$d URL.', 'Draft order %1$s created with %2$d URLs.', count( $batch['items'] ), 'indexpages' ),
				$batch['order'],
				count( $batch['items'] )
			),
			array( 'order' => $batch['order'] )
		);

		/**
		 * Fires after the plugin created a draft order.
		 *
		 * @param array $order The order as returned by the API.
		 * @param array $items Post id => URL.
		 */
		do_action( 'indexpages_order_created', $order, $batch['items'] );
	}

	/**
	 * Bookkeeping after an automatic run.
	 *
	 * @param array $batch Batch.
	 * @param array $order Order from the API.
	 * @return void
	 */
	private static function after_run( array $batch, array $order ) {
		IndexPages_State::add_spent( isset( $order['urls_total'] ) ? (int) $order['urls_total'] : count( $batch['items'] ) );
		foreach ( array_keys( $batch['items'] ) as $post_id ) {
			IndexPages_State::mark_post( $post_id, 'submitted', array( 'order' => $batch['order'] ) );
		}
		self::remember_order( $order );
		IndexPages_State::log(
			'order_run',
			/* translators: 1: order id, 2: amount charged, for example $3.00. */
			sprintf( __( 'Order %1$s was run automatically; %2$s charged.', 'indexpages' ), $batch['order'], self::charged( $order ) ),
			array( 'order' => $batch['order'] )
		);

		/**
		 * Fires after an order was run (the balance was charged).
		 *
		 * @param array $order The order as returned by the API.
		 */
		do_action( 'indexpages_order_run', $order );
	}

	/**
	 * Runs a draft on a user's explicit request (Tools page).
	 *
	 * Not counted against the daily cap, which limits automatic spending only.
	 *
	 * @param string $order_id        Order id.
	 * @param string $idempotency_key Key from the confirmation form.
	 * @return array|WP_Error The order, or the error.
	 */
	public static function run_draft( $order_id, $idempotency_key ) {
		$api = IndexPages_Api::from_settings();
		if ( null === $api ) {
			return new WP_Error( 'indexpages_no_key', __( 'No API key is set.', 'indexpages' ) );
		}
		$order = $api->run_order( $order_id, $idempotency_key );
		IndexPages_State::flush_cache();
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1000,
				'no_found_rows'  => true,
				'meta_key'       => IndexPages_State::META_ORDER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $posts as $post_id ) {
			IndexPages_State::mark_post( $post_id, 'submitted', array( 'order' => $order_id ) );
		}
		self::remember_order( $order );
		IndexPages_State::log(
			'order_run',
			/* translators: 1: order id, 2: amount charged, for example $3.00. */
			sprintf( __( 'Order %1$s was run from the Tools page; %2$s charged.', 'indexpages' ), $order_id, self::charged( $order ) ),
			array( 'order' => $order_id )
		);

		/** This action is documented in includes/class-indexpages-sender.php */
		do_action( 'indexpages_order_run', $order );
		return $order;
	}

	/**
	 * The amount an order cost, formatted like everywhere else in the admin.
	 *
	 * @param array $order Order.
	 * @return string
	 */
	private static function charged( array $order ) {
		if ( ! isset( $order['cost'] ) ) {
			return '?';
		}
		return IndexPages_Admin::money( (string) $order['cost'], isset( $order['currency'] ) ? (string) $order['currency'] : 'USD' );
	}

	/**
	 * Stores a short summary of the latest order for the dashboard widget.
	 *
	 * @param array $order Order.
	 * @return void
	 */
	private static function remember_order( array $order ) {
		IndexPages_State::set(
			'last_order',
			array(
				'id'         => isset( $order['id'] ) ? (string) $order['id'] : '',
				'status'     => isset( $order['status'] ) ? (string) $order['status'] : '',
				'urls_total' => isset( $order['urls_total'] ) ? (int) $order['urls_total'] : 0,
				'cost'       => isset( $order['cost'] ) ? (string) $order['cost'] : '',
				'currency'   => isset( $order['currency'] ) ? (string) $order['currency'] : 'USD',
				'time'       => time(),
			)
		);
		IndexPages_State::set( 'auth_failed', null );
		delete_transient( IndexPages_State::ORDERS_TRANSIENT );
		delete_transient( IndexPages_State::ACCOUNT_TRANSIENT );
	}

	/**
	 * Records an API failure for the admin notice and returns a result.
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	private static function fail( WP_Error $error ) {
		$text = IndexPages_Errors::describe( $error );
		$code = $error->get_error_code();

		if ( 'unauthorized' === $code ) {
			IndexPages_State::set( 'auth_failed', IndexPages_Settings::key_fingerprint() );
		}
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['retry_after'] ) ) {
			IndexPages_State::set( 'retry_after', time() + (int) $data['retry_after'] );
		}

		$level = IndexPages_Api::is_transient_error( $error ) || 'rate_limited' === $code ? 'warning' : 'error';
		IndexPages_State::set_notice( $level, $code, $text['message'], $text['hint'] );
		IndexPages_State::log( 'error', $text['message'] );

		return self::result( 'error', $text['message'] . ' ' . $text['hint'] );
	}

	/**
	 * Money string to integer cents.
	 *
	 * @param mixed $amount "12.50".
	 * @return int|null
	 */
	public static function cents( $amount ) {
		if ( ! is_string( $amount ) || ! preg_match( '/^(\d+)\.(\d{2})$/', $amount, $m ) ) {
			return null;
		}
		return (int) $m[1] * 100 + (int) $m[2];
	}

	/**
	 * Result array.
	 *
	 * @param string $status  ok, idle, waiting or error.
	 * @param string $message Text.
	 * @return array
	 */
	private static function result( $status, $message ) {
		return array(
			'status'  => $status,
			'message' => $message,
		);
	}
}
