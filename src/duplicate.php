<?php
/**
 * In Polylang free the "+" next to a flag opens an empty post, so a page built
 * from a dozen blocks has to be assembled again.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Duplicate {

	private const ACTION = 'cosmo_mlk_duplicate';

	private const SKIP_META = [
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_pingme',
		'_encloseme',
		// Elementor caches are tied to the post id and get rebuilt for the copy.
		'_elementor_css',
		'_elementor_page_assets',
		'_elementor_element_cache',
	];

	/** WooCommerce keeps product data in its own tables, so a meta copy gives a broken product. */
	private const UNSUPPORTED_TYPES = [ 'product', 'product_variation' ];

	/** SEO plugins store the primary term of a taxonomy as a term id. */
	private const PRIMARY_TERM_META = [ '_yoast_wpseo_primary_', 'rank_math_primary_' ];

	public static function init(): void {
		add_filter( 'post_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_filter( 'page_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_request' ] );
		add_filter( 'pll_copy_post_metas', [ self::class, 'keep_pending_flag_local' ] );
	}

	public static function row_actions( $actions, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$source_language = self::source_language( $post );

		if ( $source_language === '' ) {
			return $actions;
		}

		$translations = pll_get_post_translations( $post->ID );

		foreach ( Config::languages() as $slug => $language ) {
			if ( $slug === $source_language || ! empty( $translations[ $slug ] ) ) {
				continue;
			}

			$url = wp_nonce_url(
				add_query_arg(
					[
						'action' => self::ACTION,
						'post'   => $post->ID,
						'lang'   => $slug,
					],
					admin_url( 'admin-post.php' )
				),
				self::ACTION . '_' . $post->ID
			);

			$actions[ 'cosmo_mlk_duplicate_' . $slug ] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html( sprintf(
					/* translators: %s: language name. */
					__( 'Duplicate → %s', 'cosmo-multilingual-kit-for-polylang' ),
					$language['name']
				) )
			);
		}

		return $actions;
	}

	public static function handle_request(): void {
		$post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$language = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';

		check_admin_referer( self::ACTION . '_' . $post_id );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		$new_id = self::duplicate( $post_id, $language );

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . $new_id . '&action=edit' ) );
		exit;
	}

	/**
	 * @param array $args Overrides for the new post's fields.
	 * @return int|\WP_Error Id of the translation, the existing one if there is one.
	 */
	public static function duplicate( int $post_id, string $language, array $args = [] ) {
		if ( ! Config::active() ) {
			return new \WP_Error( 'cosmo_mlk_inactive', __( 'Polylang is not active.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		if ( ! Config::language_exists( $language ) ) {
			return new \WP_Error( 'cosmo_mlk_language', __( 'Unknown language.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		$source = get_post( $post_id );

		if ( ! $source ) {
			return new \WP_Error( 'cosmo_mlk_source', __( 'Source post not found.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		$source_language = self::source_language( $source );

		if ( $source_language === '' ) {
			return new \WP_Error( 'cosmo_mlk_source', __( 'Only a post of a translatable type with a language can be duplicated as a translation.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		$translations = pll_get_post_translations( $post_id );

		if ( ! empty( $translations[ $language ] ) ) {
			return (int) $translations[ $language ];
		}

		$postarr = array_merge( [
			'post_type'      => $source->post_type,
			'post_title'     => $source->post_title,
			'post_content'   => $source->post_content,
			'post_excerpt'   => $source->post_excerpt,
			'post_status'    => 'draft',
			'post_author'    => get_current_user_id() ?: $source->post_author,
			'post_parent'    => $source->post_parent ? (int) pll_get_post( $source->post_parent, $language ) : 0,
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_name'      => $source->post_name,
		], $args );

		$new_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		self::copy_meta( $post_id, $new_id );
		self::copy_terms( $post_id, $new_id, $language );

		pll_set_post_language( $new_id, $language );
		pll_save_post_translations( array_merge( $translations, [
			$source_language => $post_id,
			$language        => $new_id,
		] ) );

		// A published insert checks slug uniqueness before the language is set, so ask again now.
		if ( $source->post_name && get_post_field( 'post_name', $new_id ) !== $source->post_name ) {
			wp_update_post( [ 'ID' => $new_id, 'post_name' => $source->post_name ] );
		}

		$pending_meta = (string) Config::get( 'pending_meta' );

		if ( $pending_meta !== '' ) {
			update_post_meta( $new_id, $pending_meta, 1 );
		}

		do_action( 'cosmo/multilingual_kit/duplicated', $new_id, $post_id, $language );

		return (int) $new_id;
	}

	/**
	 * With custom fields sync on, Polylang would spread the flag to the source,
	 * and the switcher would hide the finished translation too.
	 *
	 * @param string[] $keys
	 * @return string[]
	 */
	public static function keep_pending_flag_local( $keys ) {
		$meta = (string) Config::get( 'pending_meta' );

		return $meta === '' ? $keys : array_values( array_diff( (array) $keys, [ $meta ] ) );
	}

	/**
	 * @return string Empty when the post cannot be duplicated as a translation.
	 */
	private static function source_language( \WP_Post $post ): string {
		if ( in_array( $post->post_type, self::UNSUPPORTED_TYPES, true ) || ! Config::is_translatable_post_type( $post->post_type ) ) {
			return '';
		}

		return (string) pll_get_post_language( $post->ID, 'slug' );
	}

	private static function copy_meta( int $from, int $to ): void {
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

	private static function copy_terms( int $from, int $to, string $language ): void {
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
