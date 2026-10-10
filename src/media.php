<?php
/**
 * With Polylang media translation off one attachment serves every language, so
 * the alt has nowhere else to be translated.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Media {

	public const META = '_wp_attachment_image_alt_';

	public static function init(): void {
		add_filter( 'attachment_fields_to_edit', [ self::class, 'fields' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ self::class, 'save' ], 10, 2 );
		add_filter( 'wp_get_attachment_image_attributes', [ self::class, 'image_attributes' ], 10, 2 );
	}

	public static function alt( int $attachment_id, string $language = '' ): string {
		$language = $language ?: Config::current_language();
		$alt      = Config::active() ? (string) get_post_meta( $attachment_id, self::META . $language, true ) : '';

		return $alt !== '' ? $alt : (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	}

	/**
	 * An alt passed by the template is kept, only the library one is swapped.
	 */
	public static function image_attributes( $attr, $attachment ) {
		if ( ( $attr['alt'] ?? '' ) === (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) ) {
			$attr['alt'] = self::alt( $attachment->ID );
		}

		return $attr;
	}

	public static function fields( $fields, $post ) {
		if ( ! wp_attachment_is_image( $post ) ) {
			return $fields;
		}

		foreach ( Config::secondary_languages() as $slug => $language ) {
			$fields[ 'alt_' . $slug ] = [
				/* translators: %s: language name. */
				'label' => sprintf( __( 'Alt (%s)', 'cosmo-multilingual-kit-for-polylang' ), $language['name'] ),
				'input' => 'text',
				'value' => get_post_meta( $post->ID, self::META . $slug, true ),
			];
		}

		return $fields;
	}

	public static function save( $post, $attachment ) {
		foreach ( array_keys( Config::secondary_languages() ) as $slug ) {
			if ( isset( $attachment[ 'alt_' . $slug ] ) ) {
				update_post_meta( $post['ID'], self::META . $slug, sanitize_text_field( $attachment[ 'alt_' . $slug ] ) );
			}
		}

		return $post;
	}
}
