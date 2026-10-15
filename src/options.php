<?php
/**
 * Polylang free stores options page values once for all languages. The page's
 * post id ('options' by default) is swapped for '<post id>_<lang>', which ACF
 * understands natively, so templates keep calling get_field( 'name', 'options' ).
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Options {

	private const COPY_ACTION = 'cosmo_mlk_copy_options';

	private static $loading_fallback = false;

	public static function init(): void {
		if ( ! Config::get( 'options_pages' ) ) {
			return;
		}

		add_filter( 'acf/validate_post_id', [ self::class, 'validate_post_id' ], 10, 2 );
		add_filter( 'acf/load_value', [ self::class, 'fallback_to_default_language' ], 20, 3 );
		add_filter( 'acf/load_reference', [ self::class, 'fallback_reference' ], 20, 3 );

		add_action( 'admin_notices', [ self::class, 'render_language_tabs' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_' . self::COPY_ACTION, [ self::class, 'handle_copy' ] );
	}

	public static function validate_post_id( $post_id, $original ) {
		if ( ! is_string( $post_id ) || ! in_array( $post_id, self::stores(), true ) ) {
			return $post_id;
		}

		return self::store( $post_id, Config::current_language() );
	}

	public static function fallback_to_default_language( $value, $post_id, $field ) {
		if ( self::$loading_fallback || ! is_string( $post_id ) || ! is_array( $field ) || ! self::is_empty( $value, $field ) ) {
			return $value;
		}

		$default = self::default_store( $post_id );

		if ( $default === '' ) {
			return $value;
		}

		self::$loading_fallback = true;
		$value                  = acf_get_value( $default, $field );
		self::$loading_fallback = false;

		return $value;
	}

	/** Without it get_field() by name cannot tell which field a never saved language means. */
	public static function fallback_reference( $reference, $field_name, $post_id ) {
		if ( $reference || ! is_string( $post_id ) ) {
			return $reference;
		}

		$default = self::default_store( $post_id );

		return $default === '' ? $reference : acf_get_reference( $field_name, $default );
	}

	public static function enqueue_assets(): void {
		if ( self::current_page() === '' ) {
			return;
		}

		Plugin::enqueue_admin_assets();
	}

	public static function render_language_tabs(): void {
		$page      = self::current_page();
		$languages = Config::languages();

		if ( $page === '' || count( $languages ) < 2 ) {
			return;
		}

		$current = Config::current_language();
		$default = Config::default_language();

		echo '<div class="cosmo-mlk-lang-bar">';
		echo '<span class="cosmo-mlk-lang-bar__title">' . esc_html__( 'Language', 'cosmo-multilingual-kit-for-polylang' ) . '</span>';
		echo '<div class="cosmo-mlk-lang-bar__list">';

		foreach ( $languages as $slug => $language ) {
			printf(
				'<a href="%1$s" class="cosmo-mlk-lang-bar__lang%2$s"%3$s>%4$s</a>',
				esc_url( self::page_url( $page, $slug ) ),
				$slug === $current ? ' is-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $language['name'] )
			);
		}

		echo '</div>';

		if ( $current !== $default ) {
			$copy_url = wp_nonce_url(
				add_query_arg(
					[
						'action' => self::COPY_ACTION,
						'page'   => $page,
						'from'   => $default,
						'to'     => $current,
					],
					admin_url( 'admin-post.php' )
				),
				self::COPY_ACTION
			);

			printf(
				'<a href="%1$s" class="button cosmo-mlk-lang-bar__copy" data-cosmo-mlk-confirm="%2$s">%3$s</a>',
				esc_url( $copy_url ),
				esc_attr__( 'Overwrite the settings of this language with values from the default language?', 'cosmo-multilingual-kit-for-polylang' ),
				esc_html( sprintf(
					/* translators: %s: language name. */
					__( 'Copy values from %s', 'cosmo-multilingual-kit-for-polylang' ),
					$languages[ $default ]['name'] ?? $default
				) )
			);
		}

		echo '</div>';

		// The copy bounces through admin-post.php; without a notice nothing seems to happen.
		if ( ! empty( $_GET['cosmo_mlk_copied'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings have been copied from the default language.', 'cosmo-multilingual-kit-for-polylang' )
			);
		}
	}

	public static function handle_copy(): void {
		check_admin_referer( self::COPY_ACTION );

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$from = isset( $_GET['from'] ) ? sanitize_key( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';

		$settings = self::is_per_language_page( $page ) ? acf_get_options_page( $page ) : false;

		if ( ! $settings ) {
			wp_die( esc_html__( 'Unknown options page.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		if ( ! current_user_can( $settings['capability'] ?? 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		if ( Config::language_exists( $from ) && Config::language_exists( $to ) && $from !== $to ) {
			self::copy( $from, $to, [ $page ] );
		}

		wp_safe_redirect( add_query_arg( 'cosmo_mlk_copied', 1, self::page_url( $page, $to ) ) );
		exit;
	}

	/**
	 * @param string[] $pages Options page slugs, all configured pages when empty.
	 * @return int Number of fields copied.
	 */
	public static function copy( string $from, string $to, array $pages = [] ): int {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return 0;
		}

		$copied = 0;

		foreach ( $pages ?: (array) Config::get( 'options_pages' ) as $page ) {
			$base = self::page_store( (string) $page );

			if ( $base === '' ) {
				continue;
			}

			foreach ( acf_get_field_groups( [ 'options_page' => $page ] ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( empty( $field['key'] ) ) {
						continue;
					}

					$value = get_field( $field['key'], self::store( $base, $from ), false );

					if ( $value === null ) {
						continue;
					}

					update_field( $field['key'], $value, self::store( $base, $to ) );
					$copied++;
				}
			}
		}

		return $copied;
	}

	/**
	 * For values saved before the per-language stores existed. Safe to run twice.
	 *
	 * @return int Number of rows moved.
	 */
	public static function migrate_legacy( string $language ): int {
		global $wpdb;

		if ( ! Config::language_exists( $language ) ) {
			return 0;
		}

		$known = array_keys( Config::languages() );
		$moved = 0;

		foreach ( self::stores() as $base ) {
			// The underscored rows map values to field keys; without them ACF guesses the field by name.
			foreach ( [ $base . '_', '_' . $base . '_' ] as $prefix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration over option rows.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );

				foreach ( (array) $rows as $row ) {
					$rest = substr( $row['option_name'], strlen( $prefix ) );

					foreach ( $known as $slug ) {
						if ( strpos( $rest, $slug . '_' ) === 0 ) {
							continue 2;
						}
					}

					$target = $prefix . $language . '_' . $rest;

					// get_option() cannot tell a missing row from one holding an empty string.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $target ) ) ) {
						continue;
					}

					add_option( $target, maybe_unserialize( $row['option_value'] ), '', false );
					$moved++;
				}
			}
		}

		return $moved;
	}

	/**
	 * Pages share whatever is saved under the same post id, so a page left out of
	 * `options_pages` but keeping the default 'options' goes per language too.
	 *
	 * @return string[]
	 */
	private static function stores(): array {
		$stores = [];

		foreach ( (array) Config::get( 'options_pages' ) as $page ) {
			$stores[] = self::page_store( (string) $page );
		}

		return array_values( array_filter( array_unique( $stores ) ) );
	}

	private static function page_store( string $page ): string {
		$settings = function_exists( 'acf_get_options_page' ) ? acf_get_options_page( $page ) : false;
		$post_id  = is_array( $settings ) && isset( $settings['post_id'] ) ? $settings['post_id'] : 'options';

		// A numeric id ties the page to a post, which Polylang already translates.
		return is_string( $post_id ) && $post_id !== '' && ! is_numeric( $post_id ) ? $post_id : '';
	}

	private static function store( string $base, string $language ): string {
		return $base . '_' . $language;
	}

	/** The default language's store for another language's one, empty otherwise. */
	private static function default_store( string $post_id ): string {
		[ $base, $language ] = self::split_store( $post_id );
		$default             = Config::default_language();

		return $base === '' || $language === $default ? '' : self::store( $base, $default );
	}

	/**
	 * @return array{0:string,1:string} Store post id and language, empty when not one of ours.
	 */
	private static function split_store( string $post_id ): array {
		foreach ( self::stores() as $base ) {
			if ( strpos( $post_id, $base . '_' ) !== 0 ) {
				continue;
			}

			$language = substr( $post_id, strlen( $base ) + 1 );

			if ( Config::language_exists( $language ) ) {
				return [ $base, $language ];
			}
		}

		return [ '', '' ];
	}

	/**
	 * A cleared top-level field falls back like a never saved one, but a cleared
	 * cell of a translated repeater does not, or the rows of two languages would mix.
	 */
	private static function is_empty( $value, array $field ): bool {
		if ( $value === null ) {
			return true;
		}

		if ( $value !== '' && $value !== [] ) {
			return false;
		}

		return ! empty( $field['parent'] ) && function_exists( 'acf_get_field_group' ) && acf_get_field_group( $field['parent'] );
	}

	private static function is_per_language_page( string $page ): bool {
		if ( $page === '' || ! function_exists( 'acf_get_options_page' ) || ! acf_get_options_page( $page ) ) {
			return false;
		}

		return in_array( self::page_store( $page ), self::stores(), true );
	}

	private static function current_page(): string {
		if ( ! is_admin() || ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return self::is_per_language_page( $page ) ? $page : '';
	}

	private static function page_url( string $page, string $language ): string {
		return add_query_arg( [ 'page' => $page, 'lang' => $language ], admin_url( 'admin.php' ) );
	}
}
