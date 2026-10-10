<?php
/**
 * Polylang free translates the biography (description_<lang> meta) but returns an
 * empty string on the front end when a translation is missing, and knows nothing
 * about custom profile fields.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Users {

	public static function init(): void {
		if ( ! self::fields() ) {
			return;
		}

		add_action( 'show_user_profile', [ self::class, 'render_fields' ], 20 );
		add_action( 'edit_user_profile', [ self::class, 'render_fields' ], 20 );
		add_action( 'personal_options_update', [ self::class, 'save_fields' ] );
		add_action( 'edit_user_profile_update', [ self::class, 'save_fields' ] );
	}

	public static function render_fields( $user ): void {
		$languages = Config::secondary_languages();

		if ( ! $languages ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Author translations', 'cosmo-multilingual-kit-for-polylang' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		foreach ( self::fields() as $key => $label ) {
			foreach ( $languages as $slug => $language ) {
				$name = $key . '_' . $slug;

				printf(
					'<tr><th><label for="%1$s">%2$s</label></th><td><input type="text" name="%1$s" id="%1$s" value="%3$s" class="regular-text" lang="%4$s"><p class="description">%5$s</p></td></tr>',
					esc_attr( $name ),
					esc_html( $label . ' — ' . $language['name'] ),
					esc_attr( self::raw_meta( $user->ID, $name ) ),
					esc_attr( str_replace( '_', '-', $language['locale'] ) ),
					esc_html__( 'Leave empty to show the default language value.', 'cosmo-multilingual-kit-for-polylang' )
				);
			}
		}

		echo '</table>';
	}

	/**
	 * Core checks the profile form nonce before firing the update hooks.
	 */
	public static function save_fields( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		foreach ( array_keys( self::fields() ) as $key ) {
			foreach ( array_keys( Config::secondary_languages() ) as $slug ) {
				$name = $key . '_' . $slug;

				// phpcs:disable WordPress.Security.NonceVerification.Missing
				if ( ! isset( $_POST[ $name ] ) ) {
					continue;
				}

				$value = sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
				// phpcs:enable

				if ( $value === '' ) {
					delete_user_meta( $user_id, $name );
				} else {
					update_user_meta( $user_id, $name, $value );
				}
			}
		}
	}

	/**
	 * @param string $key A key from `user_fields`, or 'description' for the biography.
	 */
	public static function get( int $user_id, string $key, string $language = '' ): string {
		$language = $language ?: Config::current_language();
		$default  = $key === 'description' || ! function_exists( 'get_field' )
			? self::raw_meta( $user_id, $key )
			: trim( (string) get_field( $key, 'user_' . $user_id ) );

		if ( $language === Config::default_language() ) {
			return $default;
		}

		$value = self::raw_meta( $user_id, $key . '_' . $language );

		return $value !== '' ? $value : $default;
	}

	/**
	 * @return array<string,string>
	 */
	private static function fields(): array {
		return (array) Config::get( 'user_fields' );
	}

	/**
	 * Straight from the meta cache: Polylang swaps get_user_meta( 'description' )
	 * for the current language's value, empty or not.
	 */
	private static function raw_meta( int $user_id, string $key ): string {
		update_meta_cache( 'user', [ $user_id ] );

		$meta = wp_cache_get( $user_id, 'user_meta' );

		return trim( (string) ( $meta[ $key ][0] ?? '' ) );
	}
}
