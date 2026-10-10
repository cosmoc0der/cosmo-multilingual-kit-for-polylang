<?php
/**
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Strings {

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ], 20 );
	}

	public static function register(): void {
		$group = (string) Config::get( 'strings_group' ) ?: wp_get_theme()->get( 'Name' );

		foreach ( self::all() as $name => $value ) {
			pll_register_string( (string) $name, $value, $group, strlen( $value ) > 80 );
		}
	}

	/**
	 * @return array<string,string> Key => text in the default language.
	 */
	public static function all(): array {
		return array_map( 'strval', (array) Config::get( 'strings' ) );
	}

	/**
	 * Unregistered keys are translated as text, with $fallback taking the key's place.
	 */
	public static function get( string $key, string $fallback = '' ): string {
		$value = self::all()[ $key ] ?? ( $fallback ?: $key );

		return function_exists( 'pll__' ) ? pll__( $value ) : $value;
	}
}
