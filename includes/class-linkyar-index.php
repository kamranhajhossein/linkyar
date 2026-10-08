<?php
/**
 * Index of link targets (published posts, pages, products…) and the phrases that should point at them.
 *
 * @package Linkyar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and caches the target index; resolves internal URLs back to post IDs.
 */
class Linkyar_Index {

	const OPTION   = 'linkyar_index';
	const META_KEY = '_linkyar_keywords';

	/**
	 * Per-request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Post types that can be linked to and scanned.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		/**
		 * Post types Linkyar indexes and suggests links to.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( apply_filters( 'linkyar_post_types', $types ) );
	}

	/**
	 * The full index: [ 'targets' => [ id => target ], 'paths' => [ path => id ] ].
	 *
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && isset( $stored['v'] ) && LINKYAR_VERSION === $stored['v'] ) {
			self::$cache = $stored;
			return $stored;
		}
		return self::build();
	}

	/**
	 * Rebuild from the database and persist.
	 *
	 * @return array
	 */
	public static function build() {
		global $wpdb;
		$types = self::post_types();
		$index = array(
			'v'       => LINKYAR_VERSION,
			'targets' => array(),
			'paths'   => array(),
		);
		if ( $types ) {
			$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) ORDER BY ID", $types ) );
			// Prime post + meta caches in chunks so get_permalink() and get_post_meta() don't query per post.
			foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
				_prime_post_caches( $chunk, false, true );
				foreach ( $chunk as $id ) {
					$target = self::target_for( $id );
					if ( $target ) {
						$index['targets'][ $id ] = $target;
						$index['paths'][ $target['path'] ] = $id;
					}
				}
			}
		}
		update_option( self::OPTION, $index, false );
		self::$cache = $index;
		return $index;
	}

	/**
	 * Refresh (or remove) one post's entry without rebuilding everything.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function refresh_post( $post_id ) {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || ! isset( $stored['v'] ) || LINKYAR_VERSION !== $stored['v'] ) {
			return; // Will be built lazily on next use.
		}
		$post_id = (int) $post_id;
		if ( isset( $stored['targets'][ $post_id ] ) ) {
			unset( $stored['paths'][ $stored['targets'][ $post_id ]['path'] ], $stored['targets'][ $post_id ] );
		}
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && in_array( $post->post_type, self::post_types(), true ) ) {
			$target = self::target_for( $post_id );
			if ( $target ) {
				$stored['targets'][ $post_id ]       = $target;
				$stored['paths'][ $target['path'] ] = $post_id;
			}
		}
		update_option( self::OPTION, $stored, false );
		self::$cache = $stored;
	}

	/**
	 * Drop the stored index (e.g. after the permalink structure changes).
	 */
	public static function flush() {
		delete_option( self::OPTION );
		self::$cache = null;
	}

	/**
	 * Build one target entry.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	private static function target_for( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$url = get_permalink( $post );
		if ( ! $url ) {
			return null;
		}
		return array(
			'id'      => (int) $post->ID,
			'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'type'    => $post->post_type,
			'url'     => $url,
			'path'    => self::path( $url ),
			'phrases' => self::phrases( $post ),
		);
	}

	/**
	 * Phrases (folded) that should link to a post, with their source: custom > focus > title.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,string> folded phrase => source
	 */
	public static function phrases( $post ) {
		$out = array();
		$add = function ( $raw, $source, $min_words ) use ( &$out ) {
			$f = Linkyar_Text::fold( $raw );
			$w = Linkyar_Text::words( $f );
			if ( $w < $min_words || $w > 8 || mb_strlen( $f, 'UTF-8' ) < 3 || isset( $out[ $f ] ) ) {
				return;
			}
			$out[ $f ] = $source;
		};

		$split = '/\s*[,،;؛\n]+\s*/u';
		foreach ( preg_split( $split, (string) get_post_meta( $post->ID, self::META_KEY, true ) ) as $kw ) {
			$add( $kw, 'custom', 1 );
		}
		$focus = array(
			(string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true ),
			(string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
		);
		foreach ( $focus as $value ) {
			foreach ( preg_split( $split, $value ) as $kw ) {
				$add( $kw, 'focus', 1 );
			}
		}

		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
		$add( $title, 'title', 2 );
		// "Best men's perfume 2026 | Buying guide" → also try "Best men's perfume 2026".
		$parts = preg_split( '/\s*[|:–—+(«»"؟?!]\s*|\s+-\s+/u', $title );
		if ( $parts && count( $parts ) > 1 ) {
			$add( $parts[0], 'title', 2 );
		}

		/**
		 * Filter the phrases that link to a post.
		 *
		 * @param array<string,string> $out  Folded phrase => source ('custom', 'focus', 'title').
		 * @param WP_Post              $post Post.
		 */
		return apply_filters( 'linkyar_phrases', $out, $post );
	}

	/**
	 * Normalize a URL to a comparable path ("/blog/کتاب" style, decoded, no trailing slash).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function path( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = rawurldecode( $path );
		$path = '/' . trim( $path, '/' );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
	}

	/**
	 * Resolve an href to an indexed post ID (0 if external, unknown or not indexed).
	 *
	 * @param string $href Link target.
	 * @return int
	 */
	public static function resolve( $href ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $href || '#' === $href[0] || preg_match( '~^(mailto|tel|javascript|data):~i', $href ) ) {
			return 0;
		}
		$parts = wp_parse_url( $href );
		if ( false === $parts ) {
			return 0;
		}
		if ( ! empty( $parts['host'] ) ) {
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( strtolower( preg_replace( '/^www\./i', '', $parts['host'] ) ) !== strtolower( preg_replace( '/^www\./i', '', (string) $home ) ) ) {
				return 0;
			}
		} elseif ( isset( $parts['path'] ) && '/' !== substr( $parts['path'], 0, 1 ) ) {
			return 0; // Relative paths are ambiguous; ignore.
		}

		$index = self::get();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			foreach ( array( 'p', 'page_id', 'post' ) as $k ) {
				if ( ! empty( $q[ $k ] ) && isset( $index['targets'][ (int) $q[ $k ] ] ) ) {
					return (int) $q[ $k ];
				}
			}
		}
		$path = self::path( $href );
		return isset( $index['paths'][ $path ] ) ? (int) $index['paths'][ $path ] : 0;
	}

	/**
	 * All indexed post IDs an HTML fragment links to.
	 *
	 * @param string $html Markup.
	 * @return int[]
	 */
	public static function linked_ids( $html ) {
		$ids = array();
		if ( preg_match_all( '~<a\s[^>]*href\s*=\s*(["\'])(.*?)\1~is', (string) $html, $m ) ) {
			foreach ( $m[2] as $href ) {
				$id = self::resolve( $href );
				if ( $id ) {
					$ids[ $id ] = $id;
				}
			}
		}
		return array_values( $ids );
	}
}
