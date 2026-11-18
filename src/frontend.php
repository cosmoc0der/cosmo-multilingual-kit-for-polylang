<?php
/**
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	/** @var bool The request was turned into a 404 by not_found_untranslated(). */
	private static $forced_404 = false;

	public static function init(): void {
		add_filter( 'pll_rel_hreflang_attributes', [ self::class, 'drop_unavailable_hreflangs' ], 5 );
		add_filter( 'pll_rel_hreflang_attributes', [ self::class, 'add_x_default' ] );
		add_filter( 'pll_translation_url', [ self::class, 'keep_page_number' ], 10, 2 );
		add_action( 'wp_head', [ self::class, 'paged_hreflangs' ], 1 );
		// Before Polylang's canonical redirect at 4.
		add_action( 'template_redirect', [ self::class, 'not_found_untranslated' ], 3 );
		add_filter( 'redirect_canonical', [ self::class, 'keep_not_found' ] );
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

		return $translation && self::readable( $translation ) ? $translation : 0;
	}

	public static function readable( int $post_id ): bool {
		return get_post_status( $post_id ) === 'publish' || current_user_can( 'read_post', $post_id );
	}

	/**
	 * Not readable_translation(): an admin can read drafts, but visitors
	 * following a link to one would get a 404.
	 */
	public static function published_translation( int $post_id, string $language ): int {
		$translation = $post_id && Config::active() ? (int) pll_get_post( $post_id, $language ) : 0;

		return $translation && get_post_status( $translation ) === 'publish' ? $translation : 0;
	}

	/**
	 * 0 for external urls and pages of untranslatable types.
	 */
	public static function post_from_url( string $url ): int {
		if ( strpos( $url, home_url() ) !== 0 ) {
			return 0;
		}

		$post_id = url_to_postid( $url ) ?: self::page_from_url( $url );

		return $post_id && Config::is_translatable_post_type( get_post_type( $post_id ) ) ? $post_id : 0;
	}

	/**
	 * url_to_postid() misses the posts page, and private pages unless the current
	 * user can read them, so a menu sync from WP-CLI would see other links than one
	 * from the admin.
	 */
	private static function page_from_url( string $url ): int {
		$path = (string) wp_parse_url( PLL()->links_model->remove_language_from_link( $url ), PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = trim( (string) substr( $path, strlen( $home ) ), '/' );
		$page = $path !== '' ? get_page_by_path( $path ) : null;

		return $page ? (int) $page->ID : 0;
	}

	/**
	 * A link to a page of the site goes to that page's published translation,
	 * empty when it has none. Other urls are returned as they are.
	 */
	public static function translate_url( string $url, string $language ): string {
		if ( ( PLL()->links_model->get_language_from_url( $url ) ?: Config::default_language() ) === $language ) {
			return $url;
		}

		$post_id = self::post_from_url( $url );

		if ( ! $post_id ) {
			return $url;
		}

		// Shared slugs: the url of one language may resolve to the post of another.
		$translation = self::published_translation( $post_id, $language );

		return $translation ? (string) get_permalink( $translation ) : '';
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
	 * Polylang links every paged archive to the first page of its translation.
	 * Page N leads to page N instead, and a language that has fewer pages is
	 * dropped from the switcher and the hreflangs alike.
	 *
	 * @param string|null $url
	 * @param string      $language
	 */
	public static function keep_page_number( $url, $language ) {
		$page = (int) get_query_var( 'paged' );

		if ( ! $url || $page < 2 ) {
			return $url;
		}

		if ( self::last_page( (string) $language ) < $page ) {
			return null;
		}

		$links = PLL()->links_model;

		return $links->add_paged_to_link( $links->remove_paged_from_link( $url ), $page );
	}

	/**
	 * Polylang prints no hreflangs on paged archives, yet each page carries a
	 * self canonical, so search engines expect the alternates of that same page.
	 * Its own output is reused with is_paged lowered for the call.
	 */
	public static function paged_hreflangs(): void {
		global $wp_query;

		if ( ! is_paged() || empty( PLL()->filters_links ) ) {
			return;
		}

		$wp_query->is_paged = false;
		PLL()->filters_links->wp_head();
		$wp_query->is_paged = true;
	}

	/**
	 * 0 when the view is not one whose pages can be counted in another language.
	 */
	private static function last_page( string $language ): int {
		global $wp_query;

		if ( $language === Config::current_language() ) {
			return (int) $wp_query->max_num_pages;
		}

		$per_page = (int) $wp_query->get( 'posts_per_page' );
		$object   = PLL()->model->get_language( $language );

		if ( $per_page < 1 || ! $object ) {
			return 0;
		}

		if ( is_home() || is_post_type_archive() ) {
			$post_type = is_home() ? 'post' : get_query_var( 'post_type' );
			$count     = PLL()->model->count_posts( $object, [ 'post_type' => is_array( $post_type ) ? reset( $post_type ) : $post_type ] );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$translation = (int) pll_get_term( get_queried_object_id(), $language );
			$term        = $translation ? get_term( $translation ) : null;
			$count       = $term instanceof \WP_Term ? (int) $term->count : 0;
		} else {
			return 0;
		}

		return (int) ceil( $count / $per_page );
	}

	/**
	 * Without a translated front page Polylang serves the latest posts on the
	 * language's home, which is an empty blog until that language has posts.
	 */
	public static function redirect_untranslated_front(): void {
		if ( ! self::is_untranslated_front( Config::current_language() ) ) {
			return;
		}

		wp_safe_redirect( pll_home_url( Config::default_language() ) );
		exit;
	}

	/**
	 * Polylang sends a URL in a language the content lacks to the content's own
	 * address, and the front page above goes to the default language home. With
	 * `untranslated => '404'` both are not found instead.
	 */
	public static function not_found_untranslated(): void {
		if ( Config::get( 'untranslated' ) !== '404' || ! Config::language_in_url() || is_404() ) {
			return;
		}

		$language = Config::current_language();

		if ( ! self::is_untranslated_front( $language ) && ! self::queried_untranslated( $language ) ) {
			return;
		}

		$GLOBALS['wp_query']->set_404();
		status_header( 404 );
		nocache_headers();

		self::$forced_404 = true;
	}

	/**
	 * On a 404 WordPress guesses the post by its slug and redirects to it, which
	 * lands in another language, or on a draft's ?page_id= link.
	 *
	 * @param string|false $redirect_url
	 * @return string|false
	 */
	public static function keep_not_found( $redirect_url ) {
		if ( Config::get( 'untranslated' ) !== '404' || ! is_404() ) {
			return $redirect_url;
		}

		return self::$forced_404 || Config::current_language() !== Config::default_language() ? false : $redirect_url;
	}

	private static function is_untranslated_front( string $language ): bool {
		// Paths only: with subdomains or domains home_url() keeps the main host.
		return is_home() && ! is_paged() && self::front_untranslated( $language )
			&& self::url_path( home_url( $GLOBALS['wp']->request ) ) === self::url_path( pll_home_url( $language ) );
	}

	private static function queried_untranslated( string $language ): bool {
		$object = get_queried_object();

		if ( $object instanceof \WP_Post ) {
			return Config::is_translatable_post_type( $object->post_type )
				&& Config::post_language( $object->ID ) !== $language
				&& ! self::readable_translation( $object->ID, $language );
		}

		if ( $object instanceof \WP_Term ) {
			return Config::is_translatable_taxonomy( $object->taxonomy )
				&& Config::term_language( $object->term_id ) !== $language
				&& ! pll_get_term( $object->term_id, $language );
		}

		return false;
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
