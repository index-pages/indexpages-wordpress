<?php
/**
 * Plugin settings: storage, defaults, the API key and the API base URL.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin settings.
 */
class IndexPages_Settings {

	/**
	 * Option that holds the settings array.
	 */
	const OPTION = 'indexpages_settings';

	/**
	 * Option that holds the API key (autoload off).
	 */
	const KEY_OPTION = 'indexpages_api_key';

	/**
	 * Default API base URL.
	 */
	const DEFAULT_API_BASE = 'https://api.index-pages.com/v1';

	/**
	 * Mode: nothing is sent automatically.
	 */
	const MODE_MANUAL = 'manual';

	/**
	 * Mode: new URLs are collected into free draft orders.
	 */
	const MODE_DRAFTS = 'drafts';

	/**
	 * Mode: orders are created and run automatically.
	 */
	const MODE_AUTOMATIC = 'automatic';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types'      => array( 'post', 'page' ),
			'mode'            => self::MODE_MANUAL,
			'daily_cap'       => 10,
			'send_updates'    => false,
			'update_interval' => 7,
			'schedule'        => 'indexpages_15min',
		);
	}

	/**
	 * All settings merged with the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $name Setting name.
	 * @return mixed
	 */
	public static function get( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Saves the settings array (already sanitised).
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	public static function save( array $settings ) {
		update_option( self::OPTION, array_merge( self::defaults(), $settings ) );
	}

	/**
	 * The current mode, always one of the known values.
	 *
	 * @return string
	 */
	public static function mode() {
		$mode = self::get( 'mode' );
		$all  = array( self::MODE_MANUAL, self::MODE_DRAFTS, self::MODE_AUTOMATIC );
		return in_array( $mode, $all, true ) ? $mode : self::MODE_MANUAL;
	}

	/**
	 * Post types handled by the plugin.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = self::get( 'post_types' );
		return is_array( $types ) ? array_values( array_filter( $types, 'is_string' ) ) : array();
	}

	/**
	 * Cron recurrences offered on the settings page.
	 *
	 * @return array Recurrence name => label.
	 */
	public static function schedules() {
		return array(
			'indexpages_15min' => __( 'Every 15 minutes', 'indexpages' ),
			'hourly'           => __( 'Hourly', 'indexpages' ),
			'twicedaily'       => __( 'Twice a day', 'indexpages' ),
			'daily'            => __( 'Daily', 'indexpages' ),
		);
	}

	/**
	 * Whether the key comes from the INDEXPAGES_API_KEY constant.
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'INDEXPAGES_API_KEY' ) && is_string( INDEXPAGES_API_KEY ) && '' !== INDEXPAGES_API_KEY;
	}

	/**
	 * The API key in use, or an empty string.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( self::key_from_constant() ) {
			return trim( (string) INDEXPAGES_API_KEY );
		}
		$key = get_option( self::KEY_OPTION, '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Whether a key is configured.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== self::api_key();
	}

	/**
	 * Stores a new key with autoload off.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public static function save_api_key( $key ) {
		update_option( self::KEY_OPTION, $key, false );
	}

	/**
	 * Removes the stored key.
	 *
	 * @return void
	 */
	public static function delete_api_key() {
		delete_option( self::KEY_OPTION );
	}

	/**
	 * Whether a string looks like an Index Pages API key.
	 *
	 * @param string $key Candidate.
	 * @return bool
	 */
	public static function is_valid_key_format( $key ) {
		return (bool) preg_match( '/^ipk_[a-z]+_[A-Za-z0-9]{16,120}$/', $key );
	}

	/**
	 * The key shortened for display: the prefix and the last four characters.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function mask_key( $key ) {
		if ( strlen( $key ) < 20 ) {
			return str_repeat( '•', 8 );
		}
		return substr( $key, 0, 13 ) . '…' . substr( $key, -4 );
	}

	/**
	 * A short fingerprint of the current key, to notice when it changes.
	 *
	 * @return string
	 */
	public static function key_fingerprint() {
		return substr( hash( 'sha256', self::api_key() ), 0, 16 );
	}

	/**
	 * The API base URL without a trailing slash.
	 *
	 * Only the INDEXPAGES_API_BASE constant changes it; it exists for testing.
	 *
	 * @return string
	 */
	public static function api_base() {
		if ( defined( 'INDEXPAGES_API_BASE' ) && is_string( INDEXPAGES_API_BASE ) && '' !== INDEXPAGES_API_BASE ) {
			return untrailingslashit( INDEXPAGES_API_BASE );
		}
		return self::DEFAULT_API_BASE;
	}
}
