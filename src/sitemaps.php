<?php
/**
 * One Yoast sitemap per language.
 *
 * With languages in directories Polylang hands Yoast every language at once, so
 * post-sitemap.xml and the rest mix seven languages. Here the index lists
 * en-sitemap.xml, ru-sitemap.xml… instead, each built by Yoast's own providers
 * narrowed to one language, so noindex, exclusions and canonicals still apply.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Sitemaps {

	/** The sitemap protocol limit; a language map is never split. */
	private const MAX_ENTRIES = 50000;

	/** @var string Language of the sitemap being built, empty otherwise. */
	private static $language = '';

	public static function init(): void {
		global $wpseo_sitemaps;

		// Subdomains and domains already get a sitemap per host from Polylang.
		if ( ! $wpseo_sitemaps instanceof \WPSEO_Sitemaps || (int) PLL()->options['force_lang'] !== 1 ) {
			return;
		}

		// Yoast collected its providers at after_setup_theme 10, before the modules start.
		$wpseo_sitemaps->providers[] = new Sitemap_Provider();

		add_filter( 'wpseo_sitemap_index_links', [ self::class, 'drop_mixed_sitemaps' ] );
		add_filter( 'wpseo_posts_where', [ self::class, 'posts_where' ], 20, 2 );
		add_filter( 'wpseo_typecount_where', [ self::class, 'posts_where' ], 20, 2 );
		add_filter( 'get_terms_args', [ self::class, 'terms_args' ], 20 );
		add_filter( 'wpseo_sitemap_post_type_first_links', [ self::class, 'add_archive_pages' ], 10, 2 );
	}

	/**
	 * @return string[]
	 */
	public static function languages(): array {
		return array_values( array_diff( pll_languages_list(), (array) Config::get( 'hidden_languages' ) ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function links( string $language ): array {
		$previous       = PLL()->curlang;
		PLL()->curlang  = PLL()->model->get_language( $language );
		self::$language = $language;

		$links   = [];
		$sources = [
			[ new \WPSEO_Post_Type_Sitemap_Provider(), Config::translatable_post_types() ],
			[ new \WPSEO_Taxonomy_Sitemap_Provider(), Config::translatable_taxonomies() ],
		];

		foreach ( $sources as [ $provider, $types ] ) {
			foreach ( $types as $type ) {
				try {
					$links = array_merge( $links, $provider->get_sitemap_links( $type, self::MAX_ENTRIES, 1 ) );
				} catch ( \OutOfBoundsException $e ) {
					// Excluded from sitemaps in Yoast, or nothing to list.
					continue;
				}
			}
		}

		self::$language = '';
		PLL()->curlang  = $previous;

		return $links;
	}

	public static function last_modified( string $language ): string {
		$posts = get_posts( [
			'post_type'      => Config::translatable_post_types(),
			'post_status'    => 'publish',
			'lang'           => $language,
			'orderby'        => 'modified',
			'posts_per_page' => 1,
		] );

		return $posts ? (string) $posts[0]->post_modified_gmt : '';
	}

	/**
	 * The per-type sitemaps stay reachable, they just leave the index.
	 */
	public static function drop_mixed_sitemaps( $links ) {
		$types = array_map(
			static function ( $type ) {
				return preg_quote( (string) $type, '#' );
			},
			array_merge( Config::translatable_post_types(), Config::translatable_taxonomies() )
		);

		if ( ! $types || ! is_array( $links ) ) {
			return $links;
		}

		$pattern = '#/(?:' . implode( '|', $types ) . ')-sitemap[0-9]*\.xml$#';

		return array_values( array_filter( $links, static function ( $link ) use ( $pattern ) {
			return ! preg_match( $pattern, (string) ( $link['loc'] ?? '' ) );
		} ) );
	}

	/**
	 * Appended to Polylang's own clause, which lets every language through.
	 */
	public static function posts_where( $sql, $post_type ) {
		if ( self::$language === '' || ! pll_is_translated_post_type( $post_type ) ) {
			return $sql;
		}

		return $sql . PLL()->model->post->where_clause( self::$language );
	}

	public static function terms_args( $args ) {
		if ( self::$language !== '' ) {
			$args['lang'] = self::$language;
		}

		return $args;
	}

	/**
	 * /tools/page/2/ has its own canonical, so it belongs in the sitemap too. The
	 * theme reports archive sizes it changes through `cosmo/multilingual_kit/per_page`.
	 */
	public static function add_archive_pages( $links, $post_type ) {
		if ( self::$language === '' || $post_type === 'page' || ! $links ) {
			return $links;
		}

		$archive  = end( $links );
		$per_page = (int) apply_filters( 'cosmo/multilingual_kit/per_page', (int) get_option( 'posts_per_page' ), $post_type );

		if ( empty( $archive['loc'] ) || $per_page < 1 ) {
			return $links;
		}

		$count = PLL()->model->count_posts( PLL()->curlang, [ 'post_type' => $post_type ] );
		$pages = (int) ceil( $count / $per_page );

		for ( $page = 2; $page <= $pages; $page++ ) {
			$links[] = array_merge( $archive, [ 'loc' => PLL()->links_model->add_paged_to_link( $archive['loc'], $page ) ] );
		}

		return $links;
	}
}
