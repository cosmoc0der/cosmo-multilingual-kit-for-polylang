<?php
/**
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public static function init(): void {
		add_filter( 'pll_rel_hreflang_attributes', [ self::class, 'add_x_default' ] );
		add_action( 'admin_bar_menu', [ self::class, 'fix_admin_bar_edit_link' ], 90 );
	}

	/**
	 * Polylang's hide_if_no_translation is not enough: it still offers pending
	 * copies, untranslated views, and front or posts pages without a readable
	 * translation, which it links to another language's home.
	 *
	 * @param array $args Extra pll_the_languages() arguments.
	 * @return array<int,array{slug:string,name:string,locale:string,url:string,flag:string,current:bool}>
	 */
	public static function switcher( array $args = [] ): array {
		if ( ! Config::active() || ! function_exists( 'pll_the_languages' ) ) {
			return [];
		}

		// hide_if_empty would hide the whole switcher while a language has no posts yet.
		$raw = pll_the_languages( array_merge( [
			'raw'                    => 1,
			'hide_if_empty'          => 0,
			'hide_if_no_translation' => 1,
			'display_names_as'       => 'name',
		], $args ) );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$hidden        = (array) Config::get( 'hidden_languages' );
		$pending       = self::pending_translations();
		$only_current  = self::is_untranslatable_view();
		$required_page = self::static_front_page();
		$items         = [];

		foreach ( $raw as $language ) {
			$current = ! empty( $language['current_lang'] );
			$slug    = (string) $language['slug'];

			$skip = ! $current && (
				$only_current
				|| ! empty( $language['no_translation'] )
				|| in_array( $slug, $hidden, true )
				|| isset( $pending[ $slug ] )
				|| ( $required_page && ! self::readable_translation( $required_page, $slug ) )
			);

			if ( $skip ) {
				continue;
			}

			$items[] = [
				'slug'    => $slug,
				'name'    => (string) $language['name'],
				'locale'  => (string) ( $language['locale'] ?? Config::locale( $slug ) ),
				'url'     => (string) $language['url'],
				'flag'    => (string) ( $language['flag'] ?? '' ),
				'current' => $current,
			];
		}

		return (array) apply_filters( 'cosmo/multilingual_kit/switcher', $items );
	}

	/**
	 * Drafts count for pll_get_post() but would 404 for visitors.
	 */
	public static function readable_translation( int $post_id, string $language ): int {
		$translation = Config::active() ? (int) pll_get_post( $post_id, $language ) : 0;

		if ( ! $translation ) {
			return 0;
		}

		return get_post_status( $translation ) === 'publish' || current_user_can( 'read_post', $translation ) ? $translation : 0;
	}

	/**
	 * Polylang keys hreflangs by locale, cut to the language code when no other
	 * language shares it, so the language slug may match neither.
	 */
	public static function add_x_default( $hreflangs ) {
		$language = PLL()->model->get_language( Config::default_language() );

		if ( ! Config::get( 'x_default' ) || ! $language || isset( $hreflangs['x-default'] ) ) {
			return $hreflangs;
		}

		$locale = $language->get_locale( 'display' );
		$url    = $hreflangs[ $locale ] ?? $hreflangs[ explode( '-', $locale )[0] ] ?? '';

		return $url === '' ? $hreflangs : array_merge( [ 'x-default' => $url ], $hreflangs );
	}

	public static function fix_admin_bar_edit_link( $wp_admin_bar ): void {
		if ( is_admin() ) {
			return;
		}

		$node       = $wp_admin_bar->get_node( 'edit' );
		$queried_id = get_queried_object_id() ?: get_the_ID();

		if ( ! $node || empty( $node->href ) || ! $queried_id || ! is_singular() ) {
			return;
		}

		$current   = Config::current_language();
		$post_lang = pll_get_post_language( $queried_id, 'slug' );

		if ( ! $post_lang || $post_lang === $current ) {
			return;
		}

		$translated = (int) pll_get_post( $queried_id, $current );

		if ( ! $translated || ! current_user_can( 'edit_post', $translated ) ) {
			return;
		}

		$edit_link = get_edit_post_link( $translated );

		if ( $edit_link ) {
			$node->href = $edit_link;
			$wp_admin_bar->add_node( (array) $node );
		}
	}

	/**
	 * Polylang treats a published copy as a full translation, even if its text is
	 * still in the source language; only the pending flag tells them apart.
	 *
	 * @return array<string,true>
	 */
	public static function pending_translations(): array {
		$meta = (string) Config::get( 'pending_meta' );

		if ( $meta === '' || ! is_singular() ) {
			return [];
		}

		$translations = pll_get_post_translations( get_queried_object_id() );

		update_meta_cache( 'post', array_map( 'intval', $translations ) );

		$pending = [];

		foreach ( $translations as $language => $post_id ) {
			if ( get_post_meta( (int) $post_id, $meta, true ) ) {
				$pending[ $language ] = true;
			}
		}

		return $pending;
	}

	/**
	 * Polylang still builds /es/… links for untranslated post types and
	 * taxonomies, but there are no rewrite rules behind them.
	 */
	private static function is_untranslatable_view(): bool {
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();

			return $term instanceof \WP_Term && ! Config::is_translatable_taxonomy( $term->taxonomy );
		}

		if ( is_singular() ) {
			return ! Config::is_translatable_post_type( get_post_type( get_queried_object_id() ) );
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;

			return ! Config::is_translatable_post_type( $post_type );
		}

		return false;
	}

	private static function static_front_page(): int {
		if ( ! is_front_page() && ! is_home() ) {
			return 0;
		}

		$queried = (int) get_queried_object_id();

		return $queried && get_post_type( $queried ) === 'page' ? $queried : 0;
	}
}
