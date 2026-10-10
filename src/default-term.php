<?php
/**
 * Polylang translates the default category when a language is added, but when
 * languages are created programmatically it does so before its language cache is
 * refreshed: the new term is linked into the translation group yet never gets a
 * language, and posts of that language end up without a valid default category.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Default_Term {

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'ensure_translations' ] );
		add_action( 'pll_add_language', [ self::class, 'schedule_check' ] );
	}

	/** The new language is not in Polylang's cache yet, so check on the next admin request. */
	public static function schedule_check(): void {
		delete_option( 'cosmo_mlk_default_term_checked' );
	}

	public static function ensure_translations(): void {
		$default_id = (int) get_option( 'default_category' );
		$languages  = array_keys( Config::languages() );
		$signature  = md5( $default_id . '|' . implode( ',', $languages ) );

		if ( ! $default_id || ! Config::is_translatable_taxonomy( 'category' ) || get_option( 'cosmo_mlk_default_term_checked' ) === $signature ) {
			return;
		}

		$source = get_term( $default_id, 'category' );

		if ( ! $source instanceof \WP_Term ) {
			return;
		}

		$source_language = pll_get_term_language( $default_id, 'slug' );

		if ( ! $source_language ) {
			$source_language = Config::default_language();
			pll_set_term_language( $default_id, $source_language );
		}

		$translations = [ $source_language => $default_id ];

		foreach ( pll_get_term_translations( $default_id ) as $language => $term_id ) {
			if ( in_array( $language, $languages, true ) && get_term( (int) $term_id, 'category' ) instanceof \WP_Term ) {
				$translations[ $language ] = (int) $term_id;
			}
		}

		foreach ( $languages as $language ) {
			if ( isset( $translations[ $language ] ) ) {
				// A group member without a language is the Polylang glitch described above.
				if ( pll_get_term_language( $translations[ $language ], 'slug' ) !== $language ) {
					pll_set_term_language( $translations[ $language ], $language );
				}

				continue;
			}

			$created = Slugs::insert_term( $source->name, 'category', $language, [ 'slug' => $source->slug ] );

			if ( is_wp_error( $created ) ) {
				$signature = '';
				continue;
			}

			$translations[ $language ] = (int) $created['term_id'];
		}

		pll_save_term_translations( $translations );

		if ( $signature ) {
			update_option( 'cosmo_mlk_default_term_checked', $signature, false );
		}
	}
}
