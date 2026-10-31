<?php
/**
 * The same slug in every language.
 *
 * WordPress checks slug uniqueness without regard for language, so the second
 * "about-us" page becomes "about-us-2" and Polylang free adds "-es" to a term
 * slug taken by another language. Polylang PRO and WPML let translations share
 * a slug; this module does the same and then makes the request resolve to the
 * post or term of the requested language, because the core lookups by slug
 * return whichever language they find first.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Slugs {

	private const LOOKUP_STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

	/** @var array{taxonomy:string,language:string}|null Language the term being saved is checked against. */
	private static $term_scope = null;

	/** @var int Translation that owns the requested URL under its own slug. */
	private static $redirect_to = 0;

	public static function init(): void {
		add_filter( 'wp_unique_post_slug', [ self::class, 'allow_post_slug_per_language' ], 10, 6 );

		add_filter( 'pre_term_slug', [ self::class, 'drop_polylang_term_suffix' ], 9 );
		add_filter( 'pre_insert_term', [ self::class, 'scope_inserted_term' ], 10, 3 );
		add_filter( 'wp_update_term_parent', [ self::class, 'scope_updated_term' ], 10, 3 );
		add_filter( 'get_terms_args', [ self::class, 'limit_terms_to_scope' ], 20 );
		add_filter( 'wp_insert_term_duplicate_term_check', [ self::class, 'allow_duplicate_in_other_language' ] );
		add_filter( 'wp_update_term_data', [ self::class, 'end_term_scope' ] );

		add_action( 'parse_query', [ self::class, 'resolve_post_language' ], 5 );
		add_action( 'parse_query', [ self::class, 'resolve_term_language' ], 5 );
		add_action( 'template_redirect', [ self::class, 'redirect_to_own_slug' ], 1 );
	}

	public static function allow_post_slug_per_language( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( $slug === $original_slug || ! $original_slug || ! Config::is_translatable_post_type( $post_type ) ) {
			return $slug;
		}

		$language = pll_get_post_language( (int) $post_id, 'slug' );

		if ( ! $language ) {
			return $slug;
		}

		$args = [
			'post_type'        => $post_type,
			'post_status'      => self::LOOKUP_STATUSES,
			'name'             => $original_slug,
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'lang'           => '',
		];

		// Core gives pages and attachments one namespace.
		if ( is_post_type_hierarchical( $post_type ) ) {
			$args['post_type']   = [ $post_type, 'attachment' ];
			$args['post_status'] = array_merge( self::LOOKUP_STATUSES, [ 'inherit' ] );
			$args['post_parent'] = (int) $post_parent;
		}

		$conflicts = array_diff( get_posts( $args ), [ (int) $post_id ] );

		// Core suffixed the slug for another reason: a feed name, a date archive, a reserved word.
		if ( ! $conflicts ) {
			return $slug;
		}

		foreach ( $conflicts as $conflict_id ) {
			$conflict_language = pll_get_post_language( (int) $conflict_id, 'slug' );

			// A post without a language, like an attachment, is there in every language.
			if ( ! $conflict_language || $conflict_language === $language ) {
				return $slug;
			}
		}

		return $original_slug;
	}

	/**
	 * get_page_by_path() and the `name` lookup know nothing about languages, so
	 * /es/about-us/ served the English page and the canonical redirect then bounced
	 * the visitor to /about-us/.
	 */
	public static function resolve_post_language( $query ): void {
		$language = self::query_language( $query );

		if ( $language === '' ) {
			return;
		}

		$pagename = (string) $query->get( 'pagename' );

		if ( $pagename !== '' ) {
			self::resolve_path( $query, 'page', 'pagename', $pagename, $language );

			return;
		}

		[ $post_type, $query_var, $slug ] = self::single_request( $query );

		if ( $slug === '' || ! Config::is_translatable_post_type( $post_type ) ) {
			return;
		}

		if ( is_post_type_hierarchical( $post_type ) ) {
			self::resolve_path( $query, $post_type, $query_var, $slug, $language );
		} else {
			self::resolve_single_language( $query, $post_type, $query_var, $slug, $language );
		}
	}

	private static function resolve_path( \WP_Query $query, string $post_type, string $query_var, string $path, string $language ): void {
		$target = 0;
		$post   = get_page_by_path( $path, OBJECT, $post_type );

		if ( $post instanceof \WP_Post ) {
			$post_language = pll_get_post_language( $post->ID, 'slug' );

			if ( ! $post_language || $post_language === $language ) {
				return;
			}

			$target = (int) pll_get_post( $post->ID, $language );
		}

		// Not linked as a translation, but a post with this slug may still exist in the language.
		if ( ! $target ) {
			$target = self::find_post( $post_type, wp_basename( $path ), $language );
		}

		if ( ! $target ) {
			return;
		}

		self::point_query_to_slug( $query, $query_var, $target, urldecode( get_page_uri( $target ) ) !== urldecode( $path ) );
	}

	private static function resolve_single_language( \WP_Query $query, string $post_type, string $query_var, string $slug, string $language ): void {
		$found = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'name'           => $slug,
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'lang'           => '',
		] );

		$target = 0;

		if ( count( $found ) > 1 ) {
			foreach ( $found as $post_id ) {
				if ( pll_get_post_language( (int) $post_id, 'slug' ) === $language ) {
					$target = (int) $post_id;
					break;
				}
			}
		} elseif ( $found ) {
			$post_language = pll_get_post_language( (int) $found[0], 'slug' );

			if ( $post_language && $post_language !== $language ) {
				$target = (int) pll_get_post( (int) $found[0], $language );
			}
		}

		if ( ! $target ) {
			return;
		}

		self::point_query_to_slug( $query, $query_var, $target, urldecode( get_post_field( 'post_name', $target ) ) !== urldecode( $slug ) );
	}

	/**
	 * A translation reached through another language's slug, like /pt/recursos/
	 * for the page that is /pt/blog-e-noticias/, would be a duplicate with a
	 * foreign URL, so it is sent to its own address instead of being served.
	 */
	private static function point_query_to_slug( \WP_Query $query, string $query_var, int $target, bool $foreign_slug ): void {
		if ( $foreign_slug ) {
			self::$redirect_to = $target;

			return;
		}

		self::point_query_to( $query, $query_var, $target );
	}

	public static function redirect_to_own_slug(): void {
		if ( ! self::$redirect_to ) {
			return;
		}

		$url  = get_permalink( self::$redirect_to );
		$page = (int) get_query_var( 'paged' );

		if ( $page > 1 ) {
			$url = PLL()->links_model->add_paged_to_link( $url, $page );
		}

		wp_safe_redirect( $url, 301 );
		exit;
	}

	private static function point_query_to( \WP_Query $query, string $query_var, int $target ): void {
		$query->set( 'name', '' );

		if ( $query_var ) {
			$query->set( $query_var, '' );
		}

		if ( self::is_posts_page( $target ) ) {
			// Core leaves the ID out of the query for the posts page, which lists posts instead.
			$query->set( 'page_id', $target );
			$query->is_page       = false;
			$query->is_singular   = false;
			$query->is_home       = true;
			$query->is_posts_page = true;
		} elseif ( get_post_type( $target ) === 'page' ) {
			$query->set( 'page_id', $target );
			$query->is_page     = true;
			$query->is_singular = true;
		} else {
			$query->set( 'p', $target );
			$query->is_single   = true;
			$query->is_singular = true;
		}

		self::set_queried_object( $query, get_post( $target ) );
	}

	/**
	 * With the posts page sharing its slug across languages, the request reaches
	 * the other language's page first, and core would then show the translation
	 * as an empty page.
	 */
	private static function is_posts_page( int $page_id ): bool {
		$posts_page = (int) get_option( 'page_for_posts' );

		if ( ! $posts_page || get_option( 'show_on_front' ) !== 'page' ) {
			return false;
		}

		return (int) pll_get_post( $posts_page, (string) pll_get_post_language( $page_id, 'slug' ) ) === $page_id;
	}

	/**
	 * @return array{0:string,1:string,2:string}
	 */
	private static function single_request( \WP_Query $query ): array {
		$post_type = $query->get( 'post_type' );

		if ( is_array( $post_type ) ) {
			return [ '', '', '' ];
		}

		$name = (string) $query->get( 'name' );

		// WP_Query copies the post type query var back into `name`, so it has to be cleared too.
		if ( $name !== '' ) {
			$post_type = (string) $post_type ?: 'post';
			$object    = get_post_type_object( $post_type );

			return [ $post_type, $object && $object->query_var ? (string) $object->query_var : '', $name ];
		}

		foreach ( Config::translatable_post_types() as $type ) {
			$object = get_post_type_object( $type );

			if ( ! $object || ! $object->query_var || in_array( $type, [ 'post', 'page' ], true ) ) {
				continue;
			}

			$slug = (string) $query->get( $object->query_var );

			if ( $slug !== '' ) {
				return [ $type, $object->query_var, $slug ];
			}
		}

		return [ '', '', '' ];
	}

	private static function find_post( string $post_type, string $slug, string $language ): int {
		$found = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => self::LOOKUP_STATUSES,
			'name'           => $slug,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'lang'           => $language,
		] );

		return (int) ( $found[0] ?? 0 );
	}

	/**
	 * Polylang free appends "-<lang>" to a term slug used by any other language.
	 * Uniqueness is checked per language below instead.
	 */
	public static function drop_polylang_term_suffix( $slug ) {
		if ( isset( PLL()->terms ) ) {
			remove_filter( 'pre_term_slug', [ PLL()->terms, 'set_pre_term_slug' ] );
		}

		return $slug;
	}

	public static function scope_inserted_term( $term, $taxonomy, $args ) {
		self::start_term_scope( (string) $taxonomy, 0, (string) ( $args['slug'] ?? '' ) );

		return $term;
	}

	public static function scope_updated_term( $parent, $term_id, $taxonomy ) {
		self::start_term_scope( (string) $taxonomy, (int) $term_id, '' );

		return $parent;
	}

	private static function start_term_scope( string $taxonomy, int $term_id, string $slug ): void {
		self::$term_scope = null;

		if ( ! Config::is_translatable_taxonomy( $taxonomy ) ) {
			return;
		}

		// Polylang answers this in the term screens and inside PLL()->model->term->insert().
		$language = apply_filters( 'pll_inserted_term_language', null, $taxonomy, $slug ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang's own filter.
		$language = $language instanceof \PLL_Language ? $language->slug : '';

		if ( ! $language && $term_id ) {
			$language = (string) pll_get_term_language( $term_id, 'slug' );
		}

		if ( $language ) {
			self::$term_scope = [ 'taxonomy' => $taxonomy, 'language' => $language ];
		}
	}

	/**
	 * Core name and slug duplicate checks in wp_insert_term() and wp_update_term()
	 * would otherwise see the terms of every language.
	 */
	public static function limit_terms_to_scope( $args ) {
		if ( ! self::$term_scope || isset( $args['lang'] ) || ! empty( $args['object_ids'] ) ) {
			return $args;
		}

		if ( ! in_array( self::$term_scope['taxonomy'], (array) ( $args['taxonomy'] ?? [] ), true ) ) {
			return $args;
		}

		$args['lang'] = self::$term_scope['language'];

		return $args;
	}

	public static function end_term_scope( $data ) {
		self::$term_scope = null;

		return $data;
	}

	/**
	 * The last raw SQL check in wp_insert_term() ignores languages and would hand
	 * back the other language's term instead of creating a new one.
	 */
	public static function allow_duplicate_in_other_language( $duplicate ) {
		$scope            = self::$term_scope;
		self::$term_scope = null;

		if ( $duplicate && $scope && pll_get_term_language( (int) $duplicate->term_id, 'slug' ) !== $scope['language'] ) {
			return null;
		}

		return $duplicate;
	}

	/**
	 * Unlike wp_insert_term() it may reuse a slug taken by translations.
	 *
	 * @param array $args wp_insert_term() arguments, plus `translations` (language => term id).
	 * @return array|\WP_Error
	 */
	public static function insert_term( string $name, string $taxonomy, string $language, array $args = [] ) {
		$language = Config::active() ? PLL()->model->get_language( $language ) : false;

		if ( ! $language ) {
			return new \WP_Error( 'cosmo_mlk_language', __( 'Unknown language.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		return PLL()->model->term->insert( $name, $taxonomy, $language, $args );
	}

	public static function term_by_slug( string $slug, string $taxonomy, string $language = '' ): ?\WP_Term {
		if ( ! Config::active() ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );

			return $term instanceof \WP_Term ? $term : null;
		}

		$language = Config::language_exists( $language ) ? $language : Config::default_language();

		foreach ( self::terms_by_slug( $slug, $taxonomy ) as $term ) {
			if ( pll_get_term_language( (int) $term->term_id, 'slug' ) === $language ) {
				return $term;
			}
		}

		return null;
	}

	public static function resolve_term_language( $query ): void {
		$language = self::query_language( $query );

		if ( $language === '' ) {
			return;
		}

		foreach ( Config::translatable_taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( ! $object || ! $object->query_var ) {
				continue;
			}

			$slug = (string) $query->get( $object->query_var );

			// A comma or a plus asks for several terms at once.
			if ( $slug === '' || strpbrk( $slug, ',+' ) !== false ) {
				continue;
			}

			if ( count( self::terms_by_slug( $slug, $taxonomy ) ) < 2 ) {
				continue;
			}

			$term = self::term_by_slug( $slug, $taxonomy, $language );

			if ( ! $term ) {
				continue;
			}

			if ( $taxonomy === 'category' ) {
				$query->set( 'category_name', '' );
				$query->set( 'cat', (int) $term->term_id );
			} else {
				$query->set( 'tax_query', [ [
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => [ (int) $term->term_id ],
				] ] );
			}

			self::set_queried_object( $query, $term );
		}
	}

	/**
	 * @return \WP_Term[]
	 */
	private static function terms_by_slug( string $slug, string $taxonomy ): array {
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'slug'       => $slug,
			'hide_empty' => false,
			'lang'       => '',
		] );

		return is_wp_error( $terms ) ? [] : $terms;
	}

	/**
	 * Hidden default language prefixes, subdomains and domains leave no `lang` var,
	 * but Polylang has read the language from the URL by now.
	 */
	private static function query_language( $query ): string {
		if ( is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return '';
		}

		$language = (string) $query->get( 'lang' ) ?: ( (string) pll_current_language( 'slug' ) ?: Config::default_language() );

		return Config::language_exists( $language ) ? $language : '';
	}

	/**
	 * @param \WP_Post|\WP_Term|null $object
	 */
	private static function set_queried_object( \WP_Query $query, $object ): void {
		if ( $object instanceof \WP_Post ) {
			$query->queried_object    = $object;
			$query->queried_object_id = (int) $object->ID;
		} elseif ( $object instanceof \WP_Term ) {
			$query->queried_object    = $object;
			$query->queried_object_id = (int) $object->term_id;
		}
	}
}
