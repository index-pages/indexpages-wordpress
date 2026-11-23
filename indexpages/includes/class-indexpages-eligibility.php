<?php
/**
 * Decides whether a post's URL may be sent: public, published, indexable.
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eligibility checks, including noindex flags set by SEO plugins.
 *
 * Yoast SEO and Rank Math are detected through their post meta and options
 * only; neither plugin needs to be active or loaded.
 */
class IndexPages_Eligibility {

	/**
	 * Why a post must not be sent, or an empty string when it may be.
	 *
	 * @param WP_Post|int|null $post Post.
	 * @return string
	 */
	public static function reason( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return __( 'The post does not exist.', 'indexpages' );
		}

		$reason = self::core_reason( $post );

		/**
		 * Filters why a post is not sent to Index Pages.
		 *
		 * Return a non-empty string to skip the post (the text is shown to the
		 * user), or an empty string to allow it.
		 *
		 * @param string  $reason Empty when the post is eligible.
		 * @param WP_Post $post   The post.
		 */
		$reason = apply_filters( 'indexpages_skip_reason', $reason, $post );

		return is_string( $reason ) ? $reason : '';
	}

	/**
	 * The plugin's own checks.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function core_reason( WP_Post $post ) {
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			return __( 'The site asks search engines not to index it (Settings → Reading).', 'indexpages' );
		}
		if ( ! in_array( $post->post_type, IndexPages_Settings::post_types(), true ) ) {
			return __( 'This post type is not enabled in the Index Pages settings.', 'indexpages' );
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return __( 'This post type is not public.', 'indexpages' );
		}
		if ( 'publish' !== $post->post_status ) {
			return __( 'Only published posts are sent.', 'indexpages' );
		}
		if ( '' !== $post->post_password ) {
			return __( 'The post is password protected.', 'indexpages' );
		}
		if ( self::yoast_noindex( $post ) ) {
			return __( 'Yoast SEO marks this post as noindex.', 'indexpages' );
		}
		if ( self::rank_math_noindex( $post ) ) {
			return __( 'Rank Math marks this post as noindex.', 'indexpages' );
		}
		$url = self::url( $post );
		if ( '' === $url ) {
			return __( 'The post has no public http or https address.', 'indexpages' );
		}
		return '';
	}

	/**
	 * Yoast SEO: per-post flag, then the post type default.
	 *
	 * Post meta `_yoast_wpseo_meta-robots-noindex`: 1 = noindex, 2 = index,
	 * empty or 0 = follow the post type setting `noindex-<type>` in the
	 * `wpseo_titles` option.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function yoast_noindex( WP_Post $post ) {
		$flag = (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === $flag ) {
			return true;
		}
		if ( '2' === $flag ) {
			return false;
		}
		$titles = get_option( 'wpseo_titles' );
		return is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post->post_type ] );
	}

	/**
	 * Rank Math: per-post robots, then the post type or global default.
	 *
	 * Post meta `rank_math_robots` is an array such as ['noindex', 'nofollow'].
	 * Without it, the `rank-math-options-titles` option holds
	 * `pt_<type>_robots` (used when `pt_<type>_custom_robots` is on) or the
	 * global `robots_global`.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function rank_math_noindex( WP_Post $post ) {
		$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
		if ( is_array( $robots ) && ! empty( $robots ) ) {
			return in_array( 'noindex', $robots, true );
		}
		$titles = get_option( 'rank-math-options-titles' );
		if ( ! is_array( $titles ) ) {
			return false;
		}
		$type = $post->post_type;
		if ( isset( $titles[ 'pt_' . $type . '_custom_robots' ] ) && 'on' === $titles[ 'pt_' . $type . '_custom_robots' ] ) {
			$defaults = isset( $titles[ 'pt_' . $type . '_robots' ] ) ? $titles[ 'pt_' . $type . '_robots' ] : array();
		} else {
			$defaults = isset( $titles['robots_global'] ) ? $titles['robots_global'] : array();
		}
		return is_array( $defaults ) && in_array( 'noindex', $defaults, true );
	}

	/**
	 * The public URL sent for a post, or an empty string.
	 *
	 * @param WP_Post|int $post Post.
	 * @return string
	 */
	public static function url( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$url = get_permalink( $post );

		/**
		 * Filters the URL sent to Index Pages for a post.
		 *
		 * @param string  $url  The permalink.
		 * @param WP_Post $post The post.
		 */
		$url = apply_filters( 'indexpages_post_url', $url, $post );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}
}
