<?php
/**
 * Settings → Index Pages.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The settings screen and its form handlers.
 */
class IndexPages_Settings_Page {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_indexpages_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_indexpages_verify', array( __CLASS__, 'handle_verify' ) );
	}

	/**
	 * Adds the page under Settings.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'Index Pages', 'indexpages' ),
			__( 'Index Pages', 'indexpages' ),
			'manage_options',
			IndexPages_Admin::SETTINGS_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Post types offered on the page: the public ones except attachments.
	 *
	 * @return WP_Post_Type[]
	 */
	private static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
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
		$settings = IndexPages_Settings::all();
		$account  = IndexPages_Admin::account( false );
		?>
		<div class="wrap indexpages">
			<h1><?php esc_html_e( 'Index Pages', 'indexpages' ); ?></h1>
			<p>
				<?php esc_html_e( 'Index Pages is a paid service that speeds up the indexing of your URLs. Running an order charges your prepaid balance; draft orders are free.', 'indexpages' ); ?>
				<a href="<?php echo esc_url( IndexPages_Admin::tools_url() ); ?>"><?php esc_html_e( 'Queue and orders', 'indexpages' ); ?></a>
			</p>

			<?php if ( '0' === (string) get_option( 'blog_public', '1' ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'This site asks search engines not to index it (Settings → Reading → Search engine visibility). Nothing is sent while that is on.', 'indexpages' ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'API key', 'indexpages' ); ?></h2>
			<?php self::render_account( $account ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="indexpages_save_settings">
				<?php wp_nonce_field( 'indexpages_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="indexpages-api-key"><?php esc_html_e( 'API key', 'indexpages' ); ?></label></th>
						<td>
							<?php if ( IndexPages_Settings::key_from_constant() ) : ?>
								<p>
									<?php
									printf(
										/* translators: 1: masked key, 2: constant name. */
										esc_html__( 'The key %1$s is set by the %2$s constant in wp-config.php.', 'indexpages' ),
										'<code>' . esc_html( IndexPages_Settings::mask_key( IndexPages_Settings::api_key() ) ) . '</code>',
										'<code>INDEXPAGES_API_KEY</code>'
									);
									?>
								</p>
							<?php else : ?>
								<?php if ( IndexPages_Settings::has_key() ) : ?>
									<p>
										<?php esc_html_e( 'Saved key:', 'indexpages' ); ?>
										<code><?php echo esc_html( IndexPages_Settings::mask_key( IndexPages_Settings::api_key() ) ); ?></code>
									</p>
								<?php endif; ?>
								<input type="password" id="indexpages-api-key" name="indexpages_api_key" class="regular-text" value="" autocomplete="off" spellcheck="false" placeholder="ipk_live_…">
								<p class="description">
									<?php
									if ( IndexPages_Settings::has_key() ) {
										esc_html_e( 'Leave empty to keep the saved key. The key is never shown again.', 'indexpages' );
									} else {
										esc_html_e( 'Create a key in the Index Pages dashboard under Settings → API keys. A key without the "orders:run" permission can never spend money.', 'indexpages' );
									}
									?>
								</p>
								<?php if ( IndexPages_Settings::has_key() ) : ?>
									<p><label><input type="checkbox" name="indexpages_remove_key" value="1"> <?php esc_html_e( 'Remove the saved key', 'indexpages' ); ?></label></p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'indexpages' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Mode', 'indexpages' ); ?></legend>
								<?php
								$modes = array(
									IndexPages_Settings::MODE_MANUAL    => array(
										__( 'Manual', 'indexpages' ),
										__( 'Nothing is sent automatically and nothing is spent. Use "Send to Index Pages" in the posts list; that creates a free draft order, which you run yourself.', 'indexpages' ),
									),
									IndexPages_Settings::MODE_DRAFTS    => array(
										__( 'Draft orders', 'indexpages' ),
										__( 'Newly published URLs are collected into draft orders. Drafts cost nothing; you run them in the Index Pages dashboard or under Tools → Index Pages, and only then is the balance charged.', 'indexpages' ),
									),
									IndexPages_Settings::MODE_AUTOMATIC => array(
										__( 'Automatic', 'indexpages' ),
										__( 'Orders are created and run automatically, and every URL is charged to your balance at the current price, up to the daily cap below.', 'indexpages' ),
									),
								);
								foreach ( $modes as $value => $mode ) :
									?>
									<div class="indexpages-mode">
										<label>
											<input type="radio" name="indexpages[mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['mode'], $value ); ?>>
											<strong><?php echo esc_html( $mode[0] ); ?></strong>
										</label>
										<p class="description"><?php echo esc_html( $mode[1] ); ?></p>
									</div>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="indexpages-daily-cap"><?php esc_html_e( 'Daily spending cap', 'indexpages' ); ?></label></th>
						<td>
							<input type="number" id="indexpages-daily-cap" name="indexpages[daily_cap]" min="1" max="100000" step="1" required class="small-text" value="<?php echo esc_attr( (string) $settings['daily_cap'] ); ?>">
							<?php esc_html_e( 'URLs per day', 'indexpages' ); ?>
							<p class="description">
								<?php
								esc_html_e( 'Automatic mode only. The plugin never runs more URLs than this in one day (site time zone); the rest wait in the queue until the next day.', 'indexpages' );
								if ( is_array( $account ) && isset( $account['price_per_url'] ) ) {
									echo ' ';
									echo esc_html(
										sprintf(
											/* translators: 1: price per URL, 2: daily maximum spend. */
											__( 'At %1$s per URL that is at most %2$s a day.', 'indexpages' ),
											IndexPages_Admin::money( $account['price_per_url'], $account['currency'] ),
											IndexPages_Admin::money( number_format( (float) $account['price_per_url'] * (int) $settings['daily_cap'], 2, '.', '' ), $account['currency'] )
										)
									);
								}
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'indexpages' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'indexpages' ); ?></legend>
								<?php foreach ( self::available_post_types() as $type ) : ?>
									<label class="indexpages-block">
										<input type="checkbox" name="indexpages[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $settings['post_types'], true ) ); ?>>
										<?php echo esc_html( $type->labels->name ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Password-protected posts and posts marked noindex in Yoast SEO or Rank Math are always skipped.', 'indexpages' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Updated posts', 'indexpages' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="indexpages[send_updates]" value="1" <?php checked( ! empty( $settings['send_updates'] ) ); ?>>
								<?php esc_html_e( 'Also send a URL again when an already published post is updated', 'indexpages' ); ?>
							</label>
							<p>
								<label for="indexpages-interval"><?php esc_html_e( 'Minimum interval per URL:', 'indexpages' ); ?></label>
								<input type="number" id="indexpages-interval" name="indexpages[update_interval]" min="1" max="365" step="1" class="small-text" value="<?php echo esc_attr( (string) $settings['update_interval'] ); ?>">
								<?php esc_html_e( 'days', 'indexpages' ); ?>
							</p>
							<p class="description"><?php esc_html_e( 'A URL that went into an order is not queued again until this many days have passed, so editing a post does not pay for it twice. Applies to the posts list actions too.', 'indexpages' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="indexpages-schedule"><?php esc_html_e( 'Send the queue', 'indexpages' ); ?></label></th>
						<td>
							<select id="indexpages-schedule" name="indexpages[schedule]">
								<?php foreach ( IndexPages_Settings::schedules() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['schedule'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Each run turns the queue into one order. Uses WP-Cron, which runs when the site gets visits.', 'indexpages' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints the verified account details and the Verify button.
	 *
	 * @param array|WP_Error|null $account Cached account.
	 * @return void
	 */
	private static function render_account( $account ) {
		if ( ! IndexPages_Settings::has_key() ) {
			return;
		}
		if ( is_array( $account ) && isset( $account['balance'] ) ) {
			$scopes = isset( $account['key']['scopes'] ) && is_array( $account['key']['scopes'] ) ? $account['key']['scopes'] : array();
			?>
			<table class="widefat striped indexpages-account">
				<tbody>
					<tr><th><?php esc_html_e( 'Balance', 'indexpages' ); ?></th><td><?php echo esc_html( IndexPages_Admin::money( $account['balance'], $account['currency'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Price per URL', 'indexpages' ); ?></th><td><?php echo esc_html( IndexPages_Admin::money( $account['price_per_url'], $account['currency'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Key', 'indexpages' ); ?></th><td>
						<?php
						echo esc_html( isset( $account['key']['name'] ) ? $account['key']['name'] : '' );
						if ( isset( $account['key']['prefix'] ) ) {
							echo ' <code>' . esc_html( $account['key']['prefix'] ) . '…</code>';
						}
						?>
					</td></tr>
					<tr><th><?php esc_html_e( 'Permissions', 'indexpages' ); ?></th><td><code><?php echo esc_html( implode( ', ', $scopes ) ); ?></code></td></tr>
					<?php if ( isset( $account['limits'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Limits', 'indexpages' ); ?></th><td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: minimum URLs, 2: maximum URLs, 3: maximum drafts. */
									__( '%1$d–%2$d URLs per order, up to %3$d draft orders', 'indexpages' ),
									$account['limits']['min_urls_per_order'],
									$account['limits']['max_urls_per_order'],
									$account['limits']['max_draft_orders']
								)
							);
							?>
						</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php
			$mode = IndexPages_Settings::mode();
			if ( IndexPages_Settings::MODE_MANUAL !== $mode && ! in_array( 'orders:write', $scopes, true ) ) {
				IndexPages_Admin::notice_box( 'warning', '<p>' . esc_html__( 'This key lacks "orders:write", so the plugin cannot create orders.', 'indexpages' ) . '</p>', false );
			}
			if ( IndexPages_Settings::MODE_AUTOMATIC === $mode && ! in_array( 'orders:run', $scopes, true ) ) {
				IndexPages_Admin::notice_box( 'warning', '<p>' . esc_html__( 'This key lacks "orders:run": in Automatic mode orders will be created but stay drafts.', 'indexpages' ) . '</p>', false );
			}
		} elseif ( is_wp_error( $account ) ) {
			IndexPages_Admin::notice_box( 'error', '<p>' . esc_html( $account->get_error_message() ) . '</p>', false );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="indexpages-inline-form">
			<input type="hidden" name="action" value="indexpages_verify">
			<?php wp_nonce_field( 'indexpages_verify' ); ?>
			<?php submit_button( __( 'Verify key and show balance', 'indexpages' ), 'secondary', 'indexpages-verify', false ); ?>
		</form>
		<?php
	}

	/**
	 * Saves the form.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'indexpages' ), 403 );
		}
		check_admin_referer( 'indexpages_save_settings' );

		$messages = array();
		$level    = 'success';

		if ( ! IndexPages_Settings::key_from_constant() ) {
			$new_key = isset( $_POST['indexpages_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['indexpages_api_key'] ) ) ) : '';
			if ( ! empty( $_POST['indexpages_remove_key'] ) ) {
				IndexPages_Settings::delete_api_key();
				self::key_changed();
				$messages[] = __( 'The API key was removed.', 'indexpages' );
			} elseif ( '' !== $new_key ) {
				if ( IndexPages_Settings::is_valid_key_format( $new_key ) ) {
					IndexPages_Settings::save_api_key( $new_key );
					self::key_changed();
					$messages[] = __( 'The API key was saved. Use "Verify" to check it.', 'indexpages' );
				} else {
					$level      = 'error';
					$messages[] = __( 'That does not look like an Index Pages API key (it starts with "ipk_live_"). The key was not changed.', 'indexpages' );
				}
			}
		}

		$input = isset( $_POST['indexpages'] ) && is_array( $_POST['indexpages'] ) ? wp_unslash( $_POST['indexpages'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitised below.
		$old   = IndexPages_Settings::all();
		$new   = $old;

		$types              = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_map( 'sanitize_key', $input['post_types'] ) : array();
		$new['post_types']  = array_values( array_intersect( $types, array_keys( self::available_post_types() ) ) );
		$new['send_updates'] = ! empty( $input['send_updates'] );

		$interval               = isset( $input['update_interval'] ) ? absint( $input['update_interval'] ) : 7;
		$new['update_interval'] = min( 365, max( 1, $interval ) );

		$schedule        = isset( $input['schedule'] ) ? sanitize_key( $input['schedule'] ) : '';
		$new['schedule'] = array_key_exists( $schedule, IndexPages_Settings::schedules() ) ? $schedule : 'indexpages_15min';

		$cap = isset( $input['daily_cap'] ) ? absint( $input['daily_cap'] ) : 0;
		if ( $cap >= 1 && $cap <= 100000 ) {
			$new['daily_cap'] = $cap;
		}

		$mode    = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : '';
		$allowed = array( IndexPages_Settings::MODE_MANUAL, IndexPages_Settings::MODE_DRAFTS, IndexPages_Settings::MODE_AUTOMATIC );
		if ( ! in_array( $mode, $allowed, true ) ) {
			$mode = IndexPages_Settings::MODE_MANUAL;
		}
		if ( IndexPages_Settings::MODE_AUTOMATIC === $mode && ( $cap < 1 || $cap > 100000 ) ) {
			$mode       = $old['mode'];
			$level      = 'error';
			$messages[] = __( 'Automatic mode needs a daily spending cap between 1 and 100000 URLs. The mode was not changed.', 'indexpages' );
		}
		$new['mode'] = $mode;

		IndexPages_Settings::save( $new );
		IndexPages_Plugin::maybe_schedule();

		if ( 'success' === $level ) {
			$messages[] = __( 'Settings saved.', 'indexpages' );
		}
		IndexPages_Admin::flash( $level, implode( ' ', $messages ) );
		wp_safe_redirect( IndexPages_Admin::settings_url() );
		exit;
	}

	/**
	 * Resets everything tied to the previous key.
	 *
	 * @return void
	 */
	private static function key_changed() {
		IndexPages_State::set( 'auth_failed', null );
		IndexPages_State::flush_cache();
		$notice = IndexPages_State::get( 'notice' );
		if ( is_array( $notice ) && isset( $notice['code'] ) && in_array( $notice['code'], array( 'unauthorized', 'forbidden_scope' ), true ) ) {
			IndexPages_State::clear_notice();
		}
	}

	/**
	 * Calls GET /account and shows the result.
	 *
	 * @return void
	 */
	public static function handle_verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'indexpages' ), 403 );
		}
		check_admin_referer( 'indexpages_verify' );

		delete_transient( IndexPages_State::ACCOUNT_TRANSIENT );
		$account = IndexPages_Admin::account();
		if ( is_wp_error( $account ) ) {
			IndexPages_Admin::flash( 'error', $account->get_error_message() );
		} elseif ( is_array( $account ) ) {
			IndexPages_State::set( 'auth_failed', null );
			IndexPages_Admin::flash(
				'success',
				sprintf(
					/* translators: 1: balance, 2: price per URL. */
					__( 'The key works. Balance: %1$s, price per URL: %2$s.', 'indexpages' ),
					IndexPages_Admin::money( $account['balance'], $account['currency'] ),
					IndexPages_Admin::money( $account['price_per_url'], $account['currency'] )
				)
			);
		} else {
			IndexPages_Admin::flash( 'error', __( 'No API key is set.', 'indexpages' ) );
		}
		wp_safe_redirect( IndexPages_Admin::settings_url() );
		exit;
	}
}
