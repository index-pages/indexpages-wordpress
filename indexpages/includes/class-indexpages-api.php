<?php
/**
 * Client for the Index Pages API v1.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin HTTP client over wp_remote_request().
 *
 * Every method returns the decoded response array, or a WP_Error whose code is
 * the API's problem `code` (for example `insufficient_balance`), or
 * `indexpages_network` / `indexpages_http_<status>` when there is none. The
 * error data holds `status`, `problem` (the decoded problem+json body) and,
 * for 429, `retry_after` in seconds.
 */
class IndexPages_Api {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Constructor.
	 *
	 * @param string $key  API key.
	 * @param string $base Base URL.
	 */
	public function __construct( $key, $base ) {
		$this->key  = $key;
		$this->base = $base;
	}

	/**
	 * A client built from the plugin settings, or null when no key is set.
	 *
	 * @return IndexPages_Api|null
	 */
	public static function from_settings() {
		if ( ! IndexPages_Settings::has_key() ) {
			return null;
		}
		return new self( IndexPages_Settings::api_key(), IndexPages_Settings::api_base() );
	}

	/**
	 * GET /account.
	 *
	 * @return array|WP_Error
	 */
	public function get_account() {
		return $this->request( 'GET', '/account' );
	}

	/**
	 * GET /orders.
	 *
	 * @param array $query limit, cursor, status.
	 * @return array|WP_Error
	 */
	public function list_orders( array $query = array() ) {
		return $this->request( 'GET', '/orders', array( 'query' => $query ) );
	}

	/**
	 * POST /orders with a pre-encoded body.
	 *
	 * The body is passed as a string so a retry sends exactly the same bytes and
	 * the server recognises it under the same Idempotency-Key.
	 *
	 * @param string $json_body       Encoded {"urls": [...], "title": ..., "comment": ...}.
	 * @param string $idempotency_key Idempotency-Key header.
	 * @return array|WP_Error
	 */
	public function create_order( $json_body, $idempotency_key ) {
		return $this->request(
			'POST',
			'/orders',
			array(
				'body'            => $json_body,
				'idempotency_key' => $idempotency_key,
			)
		);
	}

	/**
	 * GET /orders/{id}.
	 *
	 * @param string $id Order id.
	 * @return array|WP_Error
	 */
	public function get_order( $id ) {
		return $this->request( 'GET', '/orders/' . rawurlencode( $id ) );
	}

	/**
	 * DELETE /orders/{id} (drafts only).
	 *
	 * @param string $id Order id.
	 * @return array|WP_Error
	 */
	public function delete_order( $id ) {
		return $this->request( 'DELETE', '/orders/' . rawurlencode( $id ) );
	}

	/**
	 * POST /orders/{id}/run – charges the balance.
	 *
	 * @param string $id              Order id.
	 * @param string $idempotency_key Idempotency-Key header.
	 * @return array|WP_Error
	 */
	public function run_order( $id, $idempotency_key ) {
		return $this->request(
			'POST',
			'/orders/' . rawurlencode( $id ) . '/run',
			array( 'idempotency_key' => $idempotency_key )
		);
	}

	/**
	 * GET /orders/{id}/urls.
	 *
	 * @param string $id    Order id.
	 * @param array  $query limit, cursor.
	 * @return array|WP_Error
	 */
	public function list_order_urls( $id, array $query = array() ) {
		return $this->request( 'GET', '/orders/' . rawurlencode( $id ) . '/urls', array( 'query' => $query ) );
	}

	/**
	 * Sends one request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path below the base URL.
	 * @param array  $args   query (array), body (string), idempotency_key (string).
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $args = array() ) {
		$url = $this->base . $path;
		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args['query'] ) ), $url );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->key,
			'Accept'        => 'application/json',
		);
		if ( isset( $args['body'] ) ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( ! empty( $args['idempotency_key'] ) ) {
			$headers['Idempotency-Key'] = $args['idempotency_key'];
		}

		/**
		 * Filters the timeout of API requests, in seconds.
		 *
		 * @param int $timeout Timeout. Default 15.
		 */
		$timeout = (int) apply_filters( 'indexpages_http_timeout', 15 );

		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => isset( $args['body'] ) ? $args['body'] : null,
				'timeout'     => max( 1, $timeout ),
				'redirection' => 0,
				'user-agent'  => 'IndexPages-WordPress/' . INDEXPAGES_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'indexpages_network',
				$response->get_error_message(),
				array(
					'status'  => 0,
					'problem' => array(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = '' === $raw ? array() : json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $data ) ) {
				return new WP_Error(
					'indexpages_bad_response',
					__( 'The service sent a response that could not be read.', 'indexpages' ),
					array(
						'status'  => $status,
						'problem' => array(),
					)
				);
			}
			return $data;
		}

		$problem = is_array( $data ) ? $data : array();
		$code    = isset( $problem['code'] ) && is_string( $problem['code'] ) ? $problem['code'] : 'indexpages_http_' . $status;
		$detail  = '';
		if ( isset( $problem['detail'] ) && is_string( $problem['detail'] ) ) {
			$detail = $problem['detail'];
		} elseif ( isset( $problem['title'] ) && is_string( $problem['title'] ) ) {
			$detail = $problem['title'];
		}

		$error_data = array(
			'status'  => $status,
			'problem' => $problem,
		);
		if ( 429 === $status ) {
			$retry                     = wp_remote_retrieve_header( $response, 'retry-after' );
			$error_data['retry_after'] = is_numeric( $retry ) ? max( 1, (int) $retry ) : 60;
		}

		return new WP_Error( $code, $detail, $error_data );
	}

	/**
	 * A random UUID v4 for an Idempotency-Key.
	 *
	 * @return string
	 */
	public static function new_idempotency_key() {
		return wp_generate_uuid4();
	}

	/**
	 * HTTP status of an API error (0 for a network failure).
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	public static function error_status( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Decoded problem body of an API error.
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	public static function error_problem( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['problem'] ) && is_array( $data['problem'] ) ? $data['problem'] : array();
	}

	/**
	 * Whether a failed request may be retried with the same Idempotency-Key.
	 *
	 * Network failures and 5xx may not have been processed or stored; 409
	 * idempotency_key_in_flight means the first attempt is still running. Any
	 * other 4xx was answered and is replayed for 24 hours, so a retry needs a
	 * new key.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public static function is_transient_error( WP_Error $error ) {
		$status = self::error_status( $error );
		return 0 === $status || $status >= 500 || 'idempotency_key_in_flight' === $error->get_error_code();
	}
}
