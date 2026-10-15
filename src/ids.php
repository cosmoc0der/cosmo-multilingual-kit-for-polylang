<?php
/**
 * Content keeps pointing to the posts and terms it was built with, so a
 * translated page would still show the English reusable block, and an options
 * page field would still return the English post. Like WPML's "adjust IDs",
 * these are swapped for their translation in the current language on output.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Ids {

	public static function init(): void {
		// The editors must keep showing what is actually stored.
		if ( is_admin() ) {
			return;
		}

		add_filter( 'render_block_data', [ self::class, 'reusable_block' ] );
		add_filter( 'acf/format_value/type=post_object', [ self::class, 'acf_posts' ], 20 );
		add_filter( 'acf/format_value/type=relationship', [ self::class, 'acf_posts' ], 20 );
		add_filter( 'acf/format_value/type=taxonomy', [ self::class, 'acf_terms' ], 20 );
	}

	public static function reusable_block( $block ) {
		if ( ( $block['blockName'] ?? '' ) === 'core/block' && ! empty( $block['attrs']['ref'] ) ) {
			$block['attrs']['ref'] = self::post( (int) $block['attrs']['ref'] );
		}

		return $block;
	}

	/**
	 * @param mixed $value Id, WP_Post or a list of them, depending on the return format.
	 * @return mixed
	 */
	public static function acf_posts( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'acf_posts' ], $value );
		}

		if ( $value instanceof \WP_Post ) {
			$id = self::post( $value->ID );

			return $id === $value->ID ? $value : get_post( $id );
		}

		return is_numeric( $value ) ? self::post( (int) $value ) : $value;
	}

	/**
	 * @param mixed $value Id, WP_Term or a list of them, depending on the return format.
	 * @return mixed
	 */
	public static function acf_terms( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'acf_terms' ], $value );
		}

		if ( $value instanceof \WP_Term ) {
			$id = self::term( $value->term_id, $value->taxonomy );

			return $id === $value->term_id ? $value : get_term( $id );
		}

		return is_numeric( $value ) ? self::term( (int) $value, '' ) : $value;
	}

	private static function post( int $id ): int {
		if ( self::is_rest() || ! Config::is_translatable_post_type( get_post_type( $id ) ) ) {
			return $id;
		}

		return Frontend::readable_translation( $id, Config::current_language() ) ?: $id;
	}

	private static function term( int $id, string $taxonomy ): int {
		if ( self::is_rest() ) {
			return $id;
		}

		if ( $taxonomy === '' ) {
			$field    = get_term_field( 'taxonomy', $id );
			$taxonomy = is_string( $field ) ? $field : '';
		}

		if ( ! Config::is_translatable_taxonomy( $taxonomy ) ) {
			return $id;
		}

		return (int) pll_get_term( $id, Config::current_language() ) ?: $id;
	}

	/** Known only once the request is parsed, long after the modules start. */
	private static function is_rest(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}
}
