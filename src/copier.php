<?php
/**
 * Copies a post's meta and terms into another language, shared by every way
 * of creating or refreshing a translation from another one.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Copier {

	/** Source post and the hash of its text the translation was made from. */
	public const SOURCE_META = '_cosmo_mlk_source';
	public const HASH_META   = '_cosmo_mlk_source_hash';

	private const SKIP_META = [
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_pingme',
		'_encloseme',
		self::SOURCE_META,
		self::HASH_META,
		// Elementor caches are tied to the post id and get rebuilt for the copy.
		'_elementor_css',
		'_elementor_page_assets',
		'_elementor_element_cache',
	];

	/** SEO plugins store the primary term of a taxonomy as a term id. */
	private const PRIMARY_TERM_META = [ '_yoast_wpseo_primary_', 'rank_math_primary_' ];

	public static function init(): void {
		add_filter( 'pll_copy_post_metas', [ self::class, 'keep_meta_local' ] );
	}

	/**
	 * With custom fields sync on, Polylang would spread these to the source, and
	 * a pending flag there would hide the finished translation from the switcher.
	 *
	 * @param string[] $keys
	 * @return string[]
	 */
	public static function keep_meta_local( $keys ) {
		return array_values( array_diff( (array) $keys, [ self::SOURCE_META, self::HASH_META, (string) Config::get( 'pending_meta' ) ] ) );
	}

	/**
	 * Title, content and excerpt; replaces what the target had.
	 */
	public static function copy_fields( int $from, int $to ): void {
		$source = get_post( $from );

		if ( ! $source ) {
			return;
		}

		wp_update_post( wp_slash( [
			'ID'           => $to,
			'post_title'   => $source->post_title,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
		] ) );
	}

	public static function copy_meta( int $from, int $to ): void {
		foreach ( (array) get_post_meta( $from ) as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}

			delete_post_meta( $to, $key );

			foreach ( (array) $values as $value ) {
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	public static function copy_terms( int $from, int $to, string $language ): void {
		foreach ( get_object_taxonomies( get_post_type( $from ) ) as $taxonomy ) {
			if ( $taxonomy === 'language' || $taxonomy === 'post_translations' ) {
				continue;
			}

			$terms = wp_get_object_terms( $from, $taxonomy, [ 'fields' => 'ids' ] );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$translatable = Config::is_translatable_taxonomy( $taxonomy );

			if ( $translatable ) {
				$terms = self::translate_terms( $terms, $taxonomy, $language );
			}

			wp_set_object_terms( $to, array_map( 'intval', $terms ), $taxonomy );

			if ( $translatable ) {
				self::translate_primary_terms( $to, $taxonomy, $language );
			}
		}
	}

	/**
	 * Remembers which version of the source the translation now matches.
	 */
	public static function remember_source( int $from, int $to ): void {
		update_post_meta( $to, self::SOURCE_META, $from );
		update_post_meta( $to, self::HASH_META, self::hash( $from ) );
	}

	public static function hash( int $post_id ): string {
		$post = get_post( $post_id );

		return $post ? md5( $post->post_title . "\n" . $post->post_content . "\n" . $post->post_excerpt ) : '';
	}

	public static function flag_pending( int $post_id ): void {
		$pending_meta = (string) Config::get( 'pending_meta' );

		if ( $pending_meta !== '' ) {
			update_post_meta( $post_id, $pending_meta, 1 );
		}
	}

	/**
	 * A term without a translation is dropped rather than leaking the source language.
	 *
	 * @param int[] $term_ids
	 * @return int[]
	 */
	private static function translate_terms( array $term_ids, string $taxonomy, string $language ): array {
		$translated = array_filter( array_map(
			static function ( $term_id ) use ( $language ) {
				return (int) pll_get_term( (int) $term_id, $language );
			},
			$term_ids
		) );

		// A post needs a category, and the one wp_insert_post() picked may be of another language.
		if ( ! $translated && $taxonomy === 'category' ) {
			$translated = array_filter( [ (int) pll_get_term( (int) get_option( 'default_category' ), $language ) ] );
		}

		return array_values( $translated );
	}

	private static function translate_primary_terms( int $post_id, string $taxonomy, string $language ): void {
		foreach ( self::PRIMARY_TERM_META as $prefix ) {
			$key     = $prefix . $taxonomy;
			$term_id = (int) get_post_meta( $post_id, $key, true );

			if ( ! $term_id ) {
				continue;
			}

			$translated = (int) pll_get_term( $term_id, $language );

			if ( $translated ) {
				update_post_meta( $post_id, $key, $translated );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}
}
