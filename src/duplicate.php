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

	/** WooCommerce keeps product data in its own tables, so a meta copy gives a broken product. */
	private const UNSUPPORTED_TYPES = [ 'product', 'product_variation' ];

	public static function init(): void {
		add_filter( 'post_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_filter( 'page_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_request' ] );
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

		Copier::copy_meta( $post_id, $new_id );
		Copier::copy_terms( $post_id, $new_id, $language );
		Copier::remember_source( $post_id, $new_id );

		pll_set_post_language( $new_id, $language );
		pll_save_post_translations( array_merge( $translations, [
			$source_language => $post_id,
			$language        => $new_id,
		] ) );

		// A published insert checks slug uniqueness before the language is set, so ask again now.
		if ( $source->post_name && get_post_field( 'post_name', $new_id ) !== $source->post_name ) {
			wp_update_post( [ 'ID' => $new_id, 'post_name' => $source->post_name ] );
		}

		Copier::flag_pending( $new_id );

		do_action( 'cosmo/multilingual_kit/duplicated', $new_id, $post_id, $language );

		return (int) $new_id;
	}

	/**
	 * @return string Empty when the post cannot be duplicated as a translation.
	 */
	public static function source_language( \WP_Post $post ): string {
		if ( in_array( $post->post_type, self::UNSUPPORTED_TYPES, true ) || ! Config::is_translatable_post_type( $post->post_type ) ) {
			return '';
		}

		return (string) pll_get_post_language( $post->ID, 'slug' );
	}
}
