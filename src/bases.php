<?php
/**
 * Polylang free only adds the language prefix, so /es/success-stories/ cannot
 * become /es/casos-de-exito/ without "Translate slugs" from Polylang PRO.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Bases {

	/** @var array<string,string>|null */
	private static $defaults = null;

	/**
	 * Core rules of each section, captured before Polylang prefixes them. Copying
	 * them keeps every URL shape: nested terms, feeds, paging, embeds, attachments.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $rules = [];

	/** @var array<string,array<string,string>> */
	private static $archive_rules = [];

	public static function init(): void {
		if ( ! Config::get( 'bases' ) ) {
			return;
		}

		foreach ( self::keys() as $key ) {
			add_filter(
				( $key === 'blog' ? 'post' : $key ) . '_rewrite_rules',
				static function ( $rules ) use ( $key ) {
					self::$rules[ $key ] = (array) $rules;

					return $rules;
				},
				1
			);
		}

		// Post type archives are extra rules without a filter of their own.
		add_filter( 'rewrite_rules_array', [ self::class, 'capture_archive_rules' ], 1 );
		// After Polylang, so it does not stack its prefixes on top of ours.
		add_filter( 'rewrite_rules_array', [ self::class, 'add_rewrite_rules' ], 20 );

		add_filter( 'post_type_link', [ self::class, 'filter_post_link' ], 20, 2 );
		add_filter( 'post_link', [ self::class, 'filter_post_link' ], 20, 2 );
		add_filter( 'post_type_archive_link', [ self::class, 'filter_archive_link' ], 20, 2 );
		add_filter( 'term_link', [ self::class, 'filter_term_link' ], 20, 3 );
		add_filter( 'pll_get_archive_url', [ self::class, 'filter_switcher_archive_url' ], 10, 2 );
	}

	/**
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		if ( self::$defaults !== null ) {
			return self::$defaults;
		}

		$defaults = [];

		foreach ( (array) Config::get( 'bases' ) as $key => $base ) {
			if ( is_int( $key ) ) {
				$key  = (string) $base;
				$base = '';
			}

			$base = trim( (string) $base, '/' ) ?: self::registered_base( $key );

			if ( $base !== '' ) {
				$defaults[ $key ] = $base;
			}
		}

		if ( did_action( 'init' ) ) {
			self::$defaults = $defaults;
		}

		return $defaults;
	}

	public static function base( string $key, string $language = '' ): string {
		$language = $language ?: Config::current_language();
		$map      = (array) Config::get( 'base_map' );

		return trim( (string) ( $map[ $key ][ $language ] ?? '' ), '/' ) ?: ( self::defaults()[ $key ] ?? '' );
	}

	/**
	 * @param array<string,string> $rules
	 * @return array<string,string>
	 */
	public static function capture_archive_rules( $rules ) {
		foreach ( self::defaults() as $key => $default ) {
			$post_type = get_post_type_object( $key );

			if ( ! $post_type || ! $post_type->has_archive ) {
				continue;
			}

			foreach ( (array) $rules as $regex => $query ) {
				if ( preg_match( '#[?&]post_type=' . preg_quote( $key, '#' ) . '(&|$)#', (string) $query ) && self::relative_rule( (string) $regex, $default ) !== null ) {
					self::$archive_rules[ $key ][ $regex ] = $query;
				}
			}
		}

		return $rules;
	}

	/**
	 * The language is pinned in the query, so the rules work with and without a visible prefix.
	 *
	 * @param array<string,string> $rules
	 * @return array<string,string>
	 */
	public static function add_rewrite_rules( $rules ) {
		$root       = (string) $GLOBALS['wp_rewrite']->root;
		$translated = [];

		foreach ( self::defaults() as $key => $default ) {
			// Archive rules go first, or the single post rule takes /base/page/2/ for a post named "page".
			$section = array_merge( self::$archive_rules[ $key ] ?? [], self::$rules[ $key ] ?? [] );

			foreach ( array_keys( Config::languages() ) as $language ) {
				$base = self::base( $key, $language );

				if ( $base === $default ) {
					continue;
				}

				$prefix = $root . Config::url_prefix( $language ) . preg_quote( $base, '#' );

				foreach ( $section as $regex => $query ) {
					$rest = self::relative_rule( (string) $regex, $default );

					if ( $rest !== null ) {
						$translated[ $prefix . $rest ] = $query . '&lang=' . $language;
					}
				}
			}
		}

		return array_merge( $translated, (array) $rules );
	}

	public static function filter_post_link( $permalink, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $permalink;
		}

		$key = $post->post_type === 'post' ? 'blog' : $post->post_type;

		return self::swap_base( $permalink, $key, Config::post_language( $post->ID ) );
	}

	public static function filter_archive_link( $link, $post_type ) {
		return self::swap_base( $link, (string) $post_type, Config::current_language() );
	}

	public static function filter_term_link( $link, $term, $taxonomy ) {
		return self::swap_base( $link, (string) $taxonomy, Config::term_language( (int) $term->term_id ) );
	}

	/**
	 * Polylang builds the switcher link of an archive by swapping the language in
	 * the current URL, which keeps the current language's base.
	 *
	 * @param string              $url
	 * @param \PLL_Language|mixed $language
	 */
	public static function filter_switcher_archive_url( $url, $language ) {
		if ( ! is_post_type_archive() || ! $language instanceof \PLL_Language ) {
			return $url;
		}

		$post_type = get_query_var( 'post_type' );
		$post_type = (string) ( is_array( $post_type ) ? reset( $post_type ) : $post_type );

		return self::replace_base( (string) $url, self::base( $post_type, Config::current_language() ), self::base( $post_type, $language->slug ) );
	}

	/**
	 * @return string[]
	 */
	private static function keys(): array {
		$keys = [];

		foreach ( (array) Config::get( 'bases' ) as $key => $base ) {
			$keys[] = is_int( $key ) ? (string) $base : (string) $key;
		}

		return $keys;
	}

	private static function registered_base( string $key ): string {
		if ( $key === 'blog' ) {
			return trim( (string) ( $GLOBALS['wp_rewrite']->front ?? '' ), '/' );
		}

		$object = get_post_type_object( $key ) ?: get_taxonomy( $key );

		return $object && ! empty( $object->rewrite['slug'] ) ? trim( $object->rewrite['slug'], '/' ) : '';
	}

	/**
	 * The part of a rule after the base, null when the rule does not start with it.
	 */
	private static function relative_rule( string $regex, string $base ): ?string {
		$root  = (string) $GLOBALS['wp_rewrite']->root;
		$regex = ltrim( $regex, '^' );

		if ( $root !== '' && strpos( $regex, $root ) === 0 ) {
			$regex = substr( $regex, strlen( $root ) );
		}

		return strpos( $regex, $base . '/' ) === 0 ? substr( $regex, strlen( $base ) ) : null;
	}

	private static function swap_base( string $url, string $key, string $language ): string {
		return self::replace_base( $url, self::defaults()[ $key ] ?? '', self::base( $key, $language ) );
	}

	/**
	 * Anchored after the home path and the optional language prefix, so the same
	 * segment inside a post slug is left alone.
	 */
	private static function replace_base( string $url, string $from, string $to ): string {
		if ( $from === '' || $to === '' || $from === $to ) {
			return $url;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( $path === '' ) {
			return $url;
		}

		$home      = untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );
		$languages = implode(
			'|',
			array_map(
				static function ( $slug ) {
					return preg_quote( (string) $slug, '#' );
				},
				array_keys( Config::languages() )
			)
		);

		$swapped = preg_replace(
			'#^(' . preg_quote( $home . '/' . $GLOBALS['wp_rewrite']->root, '#' ) . '(?:(?:language/)?(?:' . $languages . ')/)?)' . preg_quote( $from, '#' ) . '(/|$)#',
			'${1}' . addcslashes( $to, '\\$' ) . '${2}',
			$path,
			1
		);

		if ( $swapped === null || $swapped === $path ) {
			return $url;
		}

		return substr_replace( $url, $swapped, (int) strpos( $url, $path ), strlen( $path ) );
	}
}
