<?php
/**
 * Turns API errors into messages an administrator can act on.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error descriptions.
 */
class IndexPages_Errors {

	/**
	 * What went wrong and what to do about it.
	 *
	 * @param WP_Error $error API error.
	 * @return array { message: string, hint: string }
	 */
	public static function describe( WP_Error $error ) {
		$code    = $error->get_error_code();
		$problem = IndexPages_Api::error_problem( $error );
		$detail  = $error->get_error_message();
		$status  = IndexPages_Api::error_status( $error );

		switch ( $code ) {
			case 'unauthorized':
				return array(
					'message' => __( 'Index Pages rejected the API key: it is unknown, revoked or expired.', 'indexpages' ),
					'hint'    => __( 'Create a new key in the Index Pages dashboard under Settings → API keys and paste it on the plugin settings page. Automatic sending is paused until the key changes.', 'indexpages' ),
				);
			case 'forbidden_scope':
				$scope = isset( $problem['required_scope'] ) && is_string( $problem['required_scope'] ) ? $problem['required_scope'] : '';
				return array(
					/* translators: %s: API scope name, for example orders:run. */
					'message' => '' !== $scope ? sprintf( __( 'The API key lacks the "%s" permission.', 'indexpages' ), $scope ) : __( 'The API key lacks a permission this action needs.', 'indexpages' ),
					'hint'    => __( 'Create a key with the permissions you need in the Index Pages dashboard, or switch the plugin to a mode that does not need it.', 'indexpages' ),
				);
			case 'account_frozen':
				return array(
					'message' => __( 'Your Index Pages account is frozen.', 'indexpages' ),
					'hint'    => __( 'Contact support@index-pages.com.', 'indexpages' ),
				);
			case 'insufficient_balance':
				$required = isset( $problem['required'] ) ? (string) $problem['required'] : '';
				$avail    = isset( $problem['available'] ) ? (string) $problem['available'] : '';
				$currency = isset( $problem['currency'] ) ? (string) $problem['currency'] : 'USD';
				return array(
					'message' => '' !== $required
						/* translators: 1: amount required, 2: balance, 3: currency code. */
						? sprintf( __( 'Not enough balance: the order costs %1$s %3$s, the balance is %2$s %3$s.', 'indexpages' ), $required, $avail, $currency )
						: __( 'Not enough balance to run the order.', 'indexpages' ),
					'hint'    => __( 'Top up the balance in the Index Pages dashboard. The order stays a free draft; run it from Tools → Index Pages when the balance allows.', 'indexpages' ),
				);
			case 'draft_limit_reached':
				$limit = isset( $problem['limit'] ) ? (int) $problem['limit'] : 0;
				return array(
					'message' => $limit > 0
						/* translators: %d: maximum number of draft orders. */
						? sprintf( __( 'The account already holds the maximum of %d draft orders, so no new one could be created.', 'indexpages' ), $limit )
						: __( 'The account already holds the maximum number of draft orders.', 'indexpages' ),
					'hint'    => __( 'Run or delete some drafts in Tools → Index Pages or in the dashboard. The URLs stay in the queue and go out on the next run.', 'indexpages' ),
				);
			case 'rate_limited':
				return array(
					'message' => __( 'Too many requests to Index Pages in a short time.', 'indexpages' ),
					'hint'    => __( 'Nothing to do: the plugin waits and tries again automatically.', 'indexpages' ),
				);
			case 'validation_failed':
				return array(
					/* translators: %s: the service's explanation. */
					'message' => sprintf( __( 'Index Pages did not accept the request: %s', 'indexpages' ), self::validation_summary( $problem, $detail ) ),
					'hint'    => __( 'Check the URLs listed in the activity log on Tools → Index Pages.', 'indexpages' ),
				);
			case 'order_not_executable':
				return array(
					'message' => __( 'This order is not a draft any more, so it cannot be run again.', 'indexpages' ),
					'hint'    => __( 'Refresh the list of orders.', 'indexpages' ),
				);
			case 'order_not_deletable':
				return array(
					'message' => __( 'Only draft orders can be deleted.', 'indexpages' ),
					'hint'    => __( 'Refresh the list of orders.', 'indexpages' ),
				);
			case 'not_found':
				return array(
					'message' => __( 'Index Pages does not know this order.', 'indexpages' ),
					'hint'    => __( 'It may have been deleted in the dashboard. Refresh the list of orders.', 'indexpages' ),
				);
			case 'idempotency_key_in_flight':
				return array(
					'message' => __( 'The previous attempt is still being processed by Index Pages.', 'indexpages' ),
					'hint'    => __( 'Nothing to do: the plugin checks again on the next run.', 'indexpages' ),
				);
			case 'indexpages_network':
				return array(
					/* translators: %s: error from the HTTP library. */
					'message' => sprintf( __( 'Could not reach Index Pages: %s', 'indexpages' ), $detail ),
					'hint'    => __( 'This is usually temporary; the plugin tries again on the next run. If it keeps happening, ask your host whether outgoing HTTPS requests to api.index-pages.com are allowed.', 'indexpages' ),
				);
		}

		if ( $status >= 500 ) {
			return array(
				/* translators: %d: HTTP status code. */
				'message' => sprintf( __( 'Index Pages had an internal problem (HTTP %d).', 'indexpages' ), $status ),
				'hint'    => __( 'The plugin tries again on the next run. If it persists, contact support@index-pages.com.', 'indexpages' ),
			);
		}

		return array(
			'message' => '' !== $detail
				/* translators: 1: HTTP status code, 2: the service's explanation. */
				? sprintf( __( 'Index Pages answered with HTTP %1$d: %2$s', 'indexpages' ), $status, $detail )
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Index Pages answered with HTTP %d.', 'indexpages' ), $status ),
			'hint'    => __( 'If this keeps happening, contact support@index-pages.com.', 'indexpages' ),
		);
	}

	/**
	 * One-line summary of a validation problem.
	 *
	 * @param array  $problem Problem body.
	 * @param string $detail  Fallback text.
	 * @return string
	 */
	private static function validation_summary( array $problem, $detail ) {
		if ( empty( $problem['errors'] ) || ! is_array( $problem['errors'] ) ) {
			return $detail;
		}
		$parts = array();
		foreach ( array_slice( $problem['errors'], 0, 3 ) as $item ) {
			if ( is_array( $item ) && isset( $item['field'], $item['message'] ) ) {
				$parts[] = $item['field'] . ': ' . $item['message'];
			}
		}
		return $parts ? implode( '; ', $parts ) : $detail;
	}

	/**
	 * Readable text for a per-URL rejection code.
	 *
	 * @param string $code    errors[].code.
	 * @param string $message errors[].message from the service.
	 * @return string
	 */
	public static function url_reason( $code, $message ) {
		$known = array(
			'invalid_url'        => __( 'Not a valid URL.', 'indexpages' ),
			'unsupported_scheme' => __( 'Only http and https URLs are accepted.', 'indexpages' ),
			'private_host'       => __( 'The URL points to a private or internal address.', 'indexpages' ),
			'too_long'           => __( 'The URL is too long.', 'indexpages' ),
			'invalid_characters' => __( 'The URL contains characters that are not allowed.', 'indexpages' ),
		);
		if ( isset( $known[ $code ] ) ) {
			return $known[ $code ];
		}
		return '' !== $message ? $message : $code;
	}
}
