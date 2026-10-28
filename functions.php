<?php
/**
 * Template API. Every function keeps working with Polylang switched off, falling
 * back to a single default language.
 *
 * Each function is pluggable: a theme may declare single-language fallbacks while
 * the plugin is off, and activation includes this file after the theme has loaded.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

use cosmo\Multilingual_Kit_For_Polylang\Bases;
use cosmo\Multilingual_Kit_For_Polylang\Config;
use cosmo\Multilingual_Kit_For_Polylang\Duplicate;
use cosmo\Multilingual_Kit_For_Polylang\Frontend;
use cosmo\Multilingual_Kit_For_Polylang\Media;
use cosmo\Multilingual_Kit_For_Polylang\Menus;
use cosmo\Multilingual_Kit_For_Polylang\Options;
use cosmo\Multilingual_Kit_For_Polylang\Slugs;
use cosmo\Multilingual_Kit_For_Polylang\Strings;
use cosmo\Multilingual_Kit_For_Polylang\Translations;
use cosmo\Multilingual_Kit_For_Polylang\Users;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'cosmo_mlk_language' ) ) {
	function cosmo_mlk_language(): string {
		return Config::current_language();
	}
}

if ( ! function_exists( 'cosmo_mlk_default_language' ) ) {
	function cosmo_mlk_default_language(): string {
		return Config::default_language();
	}
}

if ( ! function_exists( 'cosmo_mlk_locale' ) ) {
	function cosmo_mlk_locale( string $language = '' ): string {
		return Config::locale( $language );
	}
}

if ( ! function_exists( 'cosmo_mlk_languages' ) ) {
	function cosmo_mlk_languages(): array {
		return Config::languages();
	}
}

if ( ! function_exists( 'cosmo_mlk_url_prefix' ) ) {
	function cosmo_mlk_url_prefix( string $language ): string {
		return Config::url_prefix( $language );
	}
}

if ( ! function_exists( 'cosmo_mlk_home_url' ) ) {
	function cosmo_mlk_home_url( string $path = '', string $language = '' ): string {
		if ( ! Config::active() ) {
			return home_url( $path );
		}

		$url = pll_home_url( $language ?: Config::current_language() );

		return $path ? trailingslashit( $url ) . ltrim( $path, '/' ) : $url;
	}
}

if ( ! function_exists( 'cosmo_mlk_switcher' ) ) {
	/**
	 * @param array $args Extra pll_the_languages() arguments.
	 */
	function cosmo_mlk_switcher( array $args = [] ): array {
		return Frontend::switcher( $args );
	}
}

if ( ! function_exists( 'cosmo_mlk_pending_translations' ) ) {
	/**
	 * Languages whose translation of the current post is still flagged with `pending_meta`.
	 *
	 * @return array<string,true>
	 */
	function cosmo_mlk_pending_translations(): array {
		return Config::active() ? Frontend::pending_translations() : [];
	}
}

if ( ! function_exists( 'cosmo_mlk_t' ) ) {
	function cosmo_mlk_t( string $key, string $fallback = '' ): string {
		return Strings::get( $key, $fallback );
	}
}

if ( ! function_exists( 'cosmo_mlk_user_field' ) ) {
	/**
	 * @param string $key A key from `user_fields`, or 'description'.
	 */
	function cosmo_mlk_user_field( int $user_id, string $key, string $language = '' ): string {
		return Users::get( $user_id, $key, $language );
	}
}

if ( ! function_exists( 'cosmo_mlk_image_alt' ) ) {
	function cosmo_mlk_image_alt( int $attachment_id, string $language = '' ): string {
		return Media::alt( $attachment_id, $language );
	}
}

if ( ! function_exists( 'cosmo_mlk_url_base' ) ) {
	function cosmo_mlk_url_base( string $key, string $language = '' ): string {
		return Bases::base( $key, $language );
	}
}

if ( ! function_exists( 'cosmo_mlk_translated_post' ) ) {
	function cosmo_mlk_translated_post( int $post_id, string $language ): int {
		return Config::active() ? (int) pll_get_post( $post_id, $language ) : $post_id;
	}
}

if ( ! function_exists( 'cosmo_mlk_readable_translation' ) ) {
	function cosmo_mlk_readable_translation( int $post_id, string $language ): int {
		return Frontend::readable_translation( $post_id, $language );
	}
}

if ( ! function_exists( 'cosmo_mlk_term_by_slug' ) ) {
	function cosmo_mlk_term_by_slug( string $slug, string $taxonomy, string $language = '' ): ?WP_Term {
		return Slugs::term_by_slug( $slug, $taxonomy, $language );
	}
}

if ( ! function_exists( 'cosmo_mlk_insert_term' ) ) {
	/**
	 * @param array $args wp_insert_term() arguments plus `translations` (language => term id).
	 * @return array|WP_Error
	 */
	function cosmo_mlk_insert_term( string $name, string $taxonomy, string $language, array $args = [] ) {
		return Slugs::insert_term( $name, $taxonomy, $language, $args );
	}
}

if ( ! function_exists( 'cosmo_mlk_duplicate_post' ) ) {
	/**
	 * @return int|WP_Error
	 */
	function cosmo_mlk_duplicate_post( int $post_id, string $language, array $args = [] ) {
		return Duplicate::duplicate( $post_id, $language, $args );
	}
}

if ( ! function_exists( 'cosmo_mlk_copy_post' ) ) {
	/**
	 * Replaces a translation's title, content, custom fields and terms with those of another language.
	 *
	 * @return true|WP_Error
	 */
	function cosmo_mlk_copy_post( int $from, int $to ) {
		return Translations::copy( $from, $to );
	}
}

if ( ! function_exists( 'cosmo_mlk_translation_outdated' ) ) {
	/**
	 * Whether the post it was copied from has changed since.
	 */
	function cosmo_mlk_translation_outdated( int $post_id ): bool {
		$status = Translations::status( $post_id );

		return $status ? $status['outdated'] : false;
	}
}

if ( ! function_exists( 'cosmo_mlk_sync_menus' ) ) {
	/**
	 * Copies the default language menus to the other languages.
	 *
	 * @return int Number of menus written.
	 */
	function cosmo_mlk_sync_menus(): int {
		return Menus::sync();
	}
}

if ( ! function_exists( 'cosmo_mlk_link_translations' ) ) {
	/**
	 * @param array<string,int> $map Language => post id.
	 */
	function cosmo_mlk_link_translations( array $map ): void {
		cosmo_mlk_link( $map, 'pll_set_post_language', 'pll_save_post_translations' );
	}
}

if ( ! function_exists( 'cosmo_mlk_link_term_translations' ) ) {
	/**
	 * @param array<string,int> $map Language => term id.
	 */
	function cosmo_mlk_link_term_translations( array $map ): void {
		cosmo_mlk_link( $map, 'pll_set_term_language', 'pll_save_term_translations' );
	}
}

if ( ! function_exists( 'cosmo_mlk_link' ) ) {
	/**
	 * @internal
	 */
	function cosmo_mlk_link( array $map, string $set_language, string $save ): void {
		if ( ! Config::active() ) {
			return;
		}

		$clean = [];

		foreach ( $map as $language => $id ) {
			if ( (int) $id && Config::language_exists( $language ) ) {
				$set_language( (int) $id, $language );
				$clean[ $language ] = (int) $id;
			}
		}

		if ( count( $clean ) > 1 ) {
			$save( $clean );
		}
	}
}

if ( ! function_exists( 'cosmo_mlk_copy_options' ) ) {
	/**
	 * @param string[] $pages Options page slugs, all configured pages when empty.
	 */
	function cosmo_mlk_copy_options( string $from, string $to, array $pages = [] ): int {
		return Options::copy( $from, $to, $pages );
	}
}

if ( ! function_exists( 'cosmo_mlk_migrate_legacy_options' ) ) {
	/**
	 * For options saved before the plugin was enabled.
	 */
	function cosmo_mlk_migrate_legacy_options( string $language = '' ): int {
		return Options::migrate_legacy( $language ?: Config::default_language() );
	}
}
