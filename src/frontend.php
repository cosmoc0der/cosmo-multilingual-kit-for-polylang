<?php
/**
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public static function init(): void {
		add_filter( 'pll_rel_hreflang_attributes', [ self::class, 'drop_unavailable_hreflangs' ], 5 );
		add_filter( 'pll_rel_hreflang_attributes', [ self::class, 'add_x_default' ] );
		add_action( 'template_redirect', [ self::class, 'redirect_untranslated_front' ] );
		add_filter( 'home_url', [ self::class, 'untranslated_front_home' ], 20, 2 );
		add_action( 'admin_bar_menu', [ self::class, 'fix_admin_bar_edit_link' ], 90 );
	}

	/**
	 * Polylang's hide_if_no_translation is not enough: it still offers pending
	 * copies, untranslated views, and front or posts pages without a readable
	 * translation, which it links to another language's home.
	 *
	 * With `keep_unavailable` those languages stay in the list with `available`
	 * false and no url, for a switcher that shows them greyed out.
	 *
	 * @param array $args Extra pll_the_languages() arguments, plus `keep_unavailable`.
	 * @return array<int,array{slug:string,name:string,locale:string,url:string,flag:string,current:bool,available:bool}>
	 */
	public static function switcher( array $args = [] ): array {
		if ( ! Config::active() || ! function_exists( 'pll_the_languages' ) ) {
			return [];
		}

		$keep_unavailable = ! empty( $args['keep_unavailable'] );
		unset( $args['keep_unavailable'] );

		// hide_if_empty would hide the whole switcher while a language has no posts yet.
		$raw = pll_the_languages( array_merge( [
			'raw'                    => 1,
			'hide_if_empty'          => 0,
			'hide_if_no_translation' => $keep_unavailable ? 0 : 1,
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

			if ( ! $current && in_array( $slug, $hidden, true ) ) {
				continue;
			}

			$available = $current || ! (
				$only_current
				|| ! empty( $language['no_translation'] )
				|| isset( $pending[ $slug ] )
				|| ( $required_page && ! self::readable_translation( $required_page, $slug ) )
			);

			if ( ! $available && ! $keep_unavailable ) {
				continue;
			}

			$items[] = [
				'slug'      => $slug,
				'name'      => (string) $language['name'],
				'locale'    => (string) ( $language['locale'] ?? Config::locale( $slug ) ),
				'url'       => $available ? (string) $language['url'] : '',
				'flag'      => (string) ( $language['flag'] ?? '' ),
				'current'   => $current,
				'available' => $available,
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

	public static function add_x_default( $hreflangs ) {
		if ( ! Config::get( 'x_default' ) || isset( $hreflangs['x-default'] ) ) {
			return $hreflangs;
		}

		$key = self::hreflang_key( $hreflangs, Config::default_language() );

		return $key === '' ? $hreflangs : array_merge( [ 'x-default' => $hreflangs[ $key ] ], $hreflangs );
	}

	/**
	 * Polylang lists every language's home, translated front page or not, and
	 * knows nothing of hidden languages.
	 */
	public static function drop_unavailable_hreflangs( $hreflangs ) {
		if ( ! is_array( $hreflangs ) ) {
			return $hreflangs;
		}

		$front  = self::static_front_page();
		$hidden = (array) Config::get( 'hidden_languages' );

		foreach ( pll_languages_list() as $slug ) {
			$key = self::hreflang_key( $hreflangs, $slug );

			if ( $key !== '' && ( in_array( $slug, $hidden, true ) || ( $front && ! self::readable_translation( $front, $slug ) ) ) ) {
				unset( $hreflangs[ $key ] );
			}
		}

		return $hreflangs;
	}

	/**
	 * Without a translated front page Polylang serves the latest posts on the
	 * language's home, which is an empty blog until that language has posts.
	 */
	public static function redirect_untranslated_front(): void {
		$language = Config::current_language();

		// Paths only: with subdomains or domains home_url() keeps the main host.
		if (
			! is_home() || is_paged() || ! self::front_untranslated( $language )
			|| self::url_path( home_url( $GLOBALS['wp']->request ) ) !== self::url_path( pll_home_url( $language ) )
		) {
			return;
		}

		wp_safe_redirect( pll_home_url( Config::default_language() ) );
		exit;
	}

	/** Logo and other home links would otherwise bounce off the redirect above. */
	public static function untranslated_front_home( $url, $path ) {
		if ( is_admin() || ( $path !== '' && $path !== '/' ) || ! did_action( 'pll_language_defined' ) ) {
			return $url;
		}

		$language = Config::current_language();

		if ( untrailingslashit( $url ) !== untrailingslashit( pll_home_url( $language ) ) || ! self::front_untranslated( $language ) ) {
			return $url;
		}

		return pll_home_url( Config::default_language() );
	}

	private static function front_untranslated( string $language ): bool {
		$front = (int) get_option( 'page_on_front' );

		return get_option( 'show_on_front' ) === 'page' && $front
			&& $language !== Config::default_language() && ! self::readable_translation( $front, $language );
	}

	private static function url_path( string $url ): string {
		return trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/**
	 * Polylang keys hreflangs by locale, cut to the language code when no other
	 * language shares it, so the language slug may match neither.
	 */
	private static function hreflang_key( array $hreflangs, string $slug ): string {
		$language = PLL()->model->get_language( $slug );

		if ( ! $language ) {
			return '';
		}

		$locale = $language->get_locale( 'display' );

		foreach ( [ $locale, explode( '-', $locale )[0] ] as $key ) {
			if ( isset( $hreflangs[ $key ] ) ) {
				return $key;
			}
		}

		return '';
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
