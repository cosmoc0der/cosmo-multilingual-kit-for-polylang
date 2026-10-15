<?php
/**
 * Post types and taxonomies are made translatable in code, so the setup travels
 * with the theme instead of living in Polylang's checkboxes.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Config {

	/** PLL()->model->term->insert() appeared in 3.7. */
	public const MIN_POLYLANG = '3.7';

	private const DEFAULTS = [
		'post_types'           => [],
		'taxonomies'           => [],
		'bases'                => [],
		'base_map'             => [],
		'options_pages'        => [],
		'strings'              => [],
		'strings_group'        => '',
		'user_fields'          => [],
		'pending_meta'         => '',
		'hidden_languages'     => [],
		'x_default'            => true,
		'fallback_language'    => 'en',
		'prefill_translations' => true,
		'modules'              => [],
	];

	/** @var array<string,mixed>|null */
	private static $config = null;

	public static function init(): void {
		add_filter( 'pll_get_post_types', [ self::class, 'filter_post_types' ], 10, 2 );
		add_filter( 'pll_get_taxonomies', [ self::class, 'filter_taxonomies' ], 10, 2 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( self::$config !== null ) {
			return self::$config;
		}

		$config = wp_parse_args( (array) apply_filters( 'cosmo/multilingual_kit/config', [] ), self::DEFAULTS );

		// Caching before the theme is loaded would freeze a config without the theme's filter.
		if ( did_action( 'after_setup_theme' ) ) {
			self::$config = $config;
		}

		return $config;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function module_enabled( string $module, bool $default = true ): bool {
		$modules = (array) self::get( 'modules' );

		return isset( $modules[ $module ] ) ? (bool) $modules[ $module ] : $default;
	}

	public static function polylang_supported(): bool {
		return function_exists( 'pll_current_language' ) && defined( 'POLYLANG_VERSION' ) && version_compare( POLYLANG_VERSION, self::MIN_POLYLANG, '>=' );
	}

	/**
	 * Polylang leaves its global unset on the front end until the first language
	 * is added, and PLL() then raises a warning.
	 */
	public static function active(): bool {
		return self::polylang_supported()
			&& isset( $GLOBALS['polylang'] )
			&& $GLOBALS['polylang'] instanceof \PLL_Base
			&& (bool) PLL()->model->get_languages_list();
	}

	/**
	 * Polylang `force_lang`: 0 from the content, 1 directory, 2 subdomain, 3 domain.
	 */
	public static function language_in_url(): bool {
		return self::active() && (int) PLL()->options['force_lang'] > 0;
	}

	/**
	 * Polylang expects a `name => name` map and intersects it with get_post_types().
	 *
	 * @param string[] $types
	 * @return string[]
	 */
	public static function filter_post_types( $types, $is_settings = false ) {
		$ours = array_values( (array) self::get( 'post_types' ) );

		return $ours ? array_merge( (array) $types, array_combine( $ours, $ours ) ) : $types;
	}

	/**
	 * @param string[] $taxonomies
	 * @return string[]
	 */
	public static function filter_taxonomies( $taxonomies, $is_settings = false ) {
		$ours = array_values( (array) self::get( 'taxonomies' ) );

		return $ours ? array_merge( (array) $taxonomies, array_combine( $ours, $ours ) ) : $taxonomies;
	}

	/**
	 * @return string[]
	 */
	public static function translatable_post_types(): array {
		if ( self::active() ) {
			return array_values( PLL()->model->post->get_translated_object_types() );
		}

		return array_values( (array) self::get( 'post_types' ) );
	}

	/**
	 * @return string[]
	 */
	public static function translatable_taxonomies(): array {
		if ( self::active() ) {
			return array_values( PLL()->model->term->get_translated_object_types() );
		}

		return array_values( (array) self::get( 'taxonomies' ) );
	}

	public static function is_translatable_post_type( $post_type ): bool {
		return in_array( $post_type, self::translatable_post_types(), true );
	}

	public static function is_translatable_taxonomy( $taxonomy ): bool {
		return in_array( $taxonomy, self::translatable_taxonomies(), true );
	}

	public static function default_language(): string {
		if ( self::active() ) {
			$default = pll_default_language( 'slug' );

			if ( $default ) {
				return $default;
			}
		}

		return (string) self::get( 'fallback_language' );
	}

	/**
	 * `?lang=` comes from the options page tabs. Polylang's "Show all languages"
	 * admin filter gives no language at all, which is taken as the default one.
	 */
	public static function current_language(): string {
		if ( ! self::active() ) {
			return self::default_language();
		}

		if ( is_admin() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the language to show.
			$requested = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';

			if ( $requested && self::language_exists( $requested ) ) {
				return $requested;
			}
		}

		return pll_current_language( 'slug' ) ?: self::default_language();
	}

	/**
	 * @return array<string,array{slug:string,name:string,locale:string,flag:string}>
	 */
	public static function languages(): array {
		if ( ! self::active() ) {
			$slug = self::default_language();

			return [
				$slug => [
					'slug'   => $slug,
					'name'   => $slug,
					'locale' => get_locale(),
					'flag'   => '',
				],
			];
		}

		$languages = [];

		foreach ( PLL()->model->get_languages_list() as $language ) {
			$languages[ $language->slug ] = [
				'slug'   => $language->slug,
				'name'   => $language->name,
				'locale' => $language->locale,
				'flag'   => $language->flag_code,
			];
		}

		return $languages;
	}

	/**
	 * @return array<string,array{slug:string,name:string,locale:string,flag:string}>
	 */
	public static function secondary_languages(): array {
		$languages = self::languages();
		unset( $languages[ self::default_language() ] );

		return $languages;
	}

	public static function language_exists( $slug ): bool {
		return is_string( $slug ) && isset( self::languages()[ $slug ] );
	}

	public static function locale( string $slug = '' ): string {
		$slug = $slug ?: self::current_language();

		return self::languages()[ $slug ]['locale'] ?? get_locale();
	}

	/**
	 * Empty for subdomains and domains, where the language lives in the host.
	 */
	public static function url_prefix( string $language ): string {
		if ( ! self::active() || (int) PLL()->options['force_lang'] !== 1 ) {
			return '';
		}

		if ( $language === self::default_language() && ! empty( PLL()->options['hide_default'] ) ) {
			return '';
		}

		return ( empty( PLL()->options['rewrite'] ) ? 'language/' : '' ) . $language . '/';
	}

	public static function post_language( int $post_id ): string {
		return ( self::active() ? pll_get_post_language( $post_id, 'slug' ) : '' ) ?: self::current_language();
	}

	public static function term_language( int $term_id ): string {
		return ( self::active() ? pll_get_term_language( $term_id, 'slug' ) : '' ) ?: self::current_language();
	}
}
