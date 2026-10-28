<?php
/**
 * What WPML does around a translation and Polylang free does not: "Copy content
 * from English" in the editor, a new translation that starts as a copy instead
 * of an empty post, and a warning when the source changed after the copy.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Translations {

	private const COPY_ACTION   = 'cosmo_mlk_copy_from';
	private const SYNCED_ACTION = 'cosmo_mlk_mark_synced';

	public static function init(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add_meta_box' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_' . self::COPY_ACTION, [ self::class, 'handle_copy' ] );
		add_action( 'admin_post_' . self::SYNCED_ACTION, [ self::class, 'handle_synced' ] );
		add_filter( 'display_post_states', [ self::class, 'post_states' ], 10, 2 );

		// Polylang Pro fills new translations itself.
		if ( Config::get( 'prefill_translations' ) && ! defined( 'POLYLANG_PRO' ) ) {
			add_filter( 'default_title', [ self::class, 'prefill_title' ], 10, 2 );
			add_filter( 'default_content', [ self::class, 'prefill_content' ], 10, 2 );
			add_filter( 'default_excerpt', [ self::class, 'prefill_excerpt' ], 10, 2 );
			add_action( 'wp_insert_post', [ self::class, 'prefill_meta' ], 10, 3 );
		}
	}

	/**
	 * Replaces the translation's title, content, excerpt, custom fields and terms
	 * with those of another language.
	 *
	 * @return true|\WP_Error
	 */
	public static function copy( int $from, int $to ) {
		$language = Config::active() ? (string) pll_get_post_language( $to, 'slug' ) : '';

		if ( $language === '' || ! in_array( $from, self::translations( $to ), true ) || $from === $to ) {
			return new \WP_Error( 'cosmo_mlk_source', __( 'The posts are not translations of each other.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		Copier::copy_fields( $from, $to );
		Copier::copy_meta( $from, $to );
		Copier::copy_terms( $from, $to, $language );
		Copier::remember_source( $from, $to );
		Copier::flag_pending( $to );

		do_action( 'cosmo/multilingual_kit/copied', $to, $from, $language );

		return true;
	}

	/**
	 * @return array{source:int,language:string,outdated:bool}|null Null when the post was not copied from a translation.
	 */
	public static function status( int $post_id ): ?array {
		$source = (int) get_post_meta( $post_id, Copier::SOURCE_META, true );

		if ( ! $source || ! Config::active() || ! in_array( $source, self::translations( $post_id ), true ) ) {
			return null;
		}

		return [
			'source'   => $source,
			'language' => (string) pll_get_post_language( $source, 'slug' ),
			'outdated' => get_post_meta( $post_id, Copier::HASH_META, true ) !== Copier::hash( $source ),
		];
	}

	public static function add_meta_box( $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post || $post->post_status === 'auto-draft' || Duplicate::source_language( $post ) === '' ) {
			return;
		}

		if ( ! self::sources( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		add_meta_box( 'cosmo-mlk-translation', __( 'Translation', 'cosmo-multilingual-kit-for-polylang' ), [ self::class, 'render_meta_box' ], $post_type, 'side' );
	}

	public static function render_meta_box( \WP_Post $post ): void {
		$languages = Config::languages();
		$status    = self::status( $post->ID );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! empty( $_GET['cosmo_mlk_copied'] ) ) {
			echo '<p class="cosmo-mlk-box__done">' . esc_html__( 'Copied. Review the text and save to keep it.', 'cosmo-multilingual-kit-for-polylang' ) . '</p>';
		}

		if ( $status && $status['outdated'] ) {
			printf(
				'<p class="cosmo-mlk-box__outdated">%1$s</p><p><a class="button button-small" href="%2$s">%3$s</a></p>',
				esc_html( sprintf(
					/* translators: %s: language name. */
					__( 'The %s version has changed since this translation was copied from it.', 'cosmo-multilingual-kit-for-polylang' ),
					$languages[ $status['language'] ]['name'] ?? $status['language']
				) ),
				esc_url( self::action_url( self::SYNCED_ACTION, $post->ID ) ),
				esc_html__( 'Mark as up to date', 'cosmo-multilingual-kit-for-polylang' )
			);
		}

		echo '<p class="description">' . esc_html__( 'Replace the title, content and custom fields with those of another language.', 'cosmo-multilingual-kit-for-polylang' ) . '</p><p class="cosmo-mlk-box__actions">';

		foreach ( self::sources( $post->ID ) as $slug => $source_id ) {
			$name = $languages[ $slug ]['name'] ?? $slug;

			printf(
				'<a class="button" href="%1$s" data-cosmo-mlk-confirm="%2$s">%3$s</a> ',
				esc_url( self::action_url( self::COPY_ACTION, $post->ID, [ 'from' => $source_id ] ) ),
				esc_attr( sprintf(
					/* translators: %s: language name. */
					__( 'Replace this translation with the %s version? Unsaved changes will be lost.', 'cosmo-multilingual-kit-for-polylang' ),
					$name
				) ),
				esc_html( sprintf(
					/* translators: %s: language name. */
					__( 'Copy from %s', 'cosmo-multilingual-kit-for-polylang' ),
					$name
				) )
			);
		}

		echo '</p>';
	}

	public static function enqueue_assets( $hook ): void {
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			Plugin::enqueue_admin_assets();
		}
	}

	public static function handle_copy(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$from    = isset( $_GET['from'] ) ? absint( $_GET['from'] ) : 0;

		check_admin_referer( self::COPY_ACTION . '_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'read_post', $from ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		$copied = self::copy( $from, $post_id );

		if ( is_wp_error( $copied ) ) {
			wp_die( esc_html( $copied->get_error_message() ) );
		}

		wp_safe_redirect( add_query_arg( 'cosmo_mlk_copied', 1, get_edit_post_link( $post_id, 'url' ) ) );
		exit;
	}

	public static function handle_synced(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		check_admin_referer( self::SYNCED_ACTION . '_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		$status = self::status( $post_id );

		if ( $status ) {
			Copier::remember_source( $status['source'], $post_id );
		}

		wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) );
		exit;
	}

	public static function post_states( $states, $post ) {
		$status = $post instanceof \WP_Post ? self::status( $post->ID ) : null;

		if ( $status && $status['outdated'] ) {
			$states['cosmo_mlk_outdated'] = sprintf(
				/* translators: %s: language name. */
				__( 'Outdated: %s changed', 'cosmo-multilingual-kit-for-polylang' ),
				Config::languages()[ $status['language'] ]['name'] ?? $status['language']
			);
		}

		return $states;
	}

	public static function prefill_title( $title, $post ) {
		$source = self::new_translation_source();

		return $source ? $source->post_title : $title;
	}

	public static function prefill_content( $content, $post ) {
		$source = self::new_translation_source();

		return $source ? $source->post_content : $content;
	}

	public static function prefill_excerpt( $excerpt, $post ) {
		$source = self::new_translation_source();

		return $source ? $source->post_excerpt : $excerpt;
	}

	/**
	 * The auto-draft is saved before the editor opens, so custom fields go straight into it.
	 */
	public static function prefill_meta( $post_id, $post, $update ): void {
		$source = self::new_translation_source();

		if ( $update || ! $source || $post->post_status !== 'auto-draft' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in new_translation_source().
		$language = isset( $_GET['new_lang'] ) ? sanitize_key( wp_unslash( $_GET['new_lang'] ) ) : '';

		Copier::copy_meta( $source->ID, (int) $post_id );
		Copier::copy_terms( $source->ID, (int) $post_id, $language );
		Copier::remember_source( $source->ID, (int) $post_id );
		Copier::flag_pending( (int) $post_id );
	}

	/**
	 * The post behind Polylang's "+" link: post-new.php?from_post=…&new_lang=…&_wpnonce=….
	 */
	private static function new_translation_source(): ?\WP_Post {
		if ( ( $GLOBALS['pagenow'] ?? '' ) !== 'post-new.php' || empty( $_GET['from_post'] ) || empty( $_GET['new_lang'] ) || empty( $_GET['_wpnonce'] ) ) {
			return null;
		}

		// Polylang's own nonce for the "+" link.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'new-post-translation' ) ) {
			return null;
		}

		$source   = get_post( absint( $_GET['from_post'] ) );
		$language = sanitize_key( wp_unslash( $_GET['new_lang'] ) );
		$type     = $source ? get_post_type_object( $source->post_type ) : null;

		if ( ! $type || ! current_user_can( $type->cap->create_posts ) || ! Config::language_exists( $language ) || Duplicate::source_language( $source ) === '' || ! current_user_can( 'read_post', $source->ID ) ) {
			return null;
		}

		return $source;
	}

	/**
	 * Other languages of the post, the default one first.
	 *
	 * @return array<string,int>
	 */
	private static function sources( int $post_id ): array {
		$translations = array_map( 'intval', pll_get_post_translations( $post_id ) );
		$default      = Config::default_language();

		$translations = array_filter(
			$translations,
			static function ( $id ) use ( $post_id ) {
				return $id !== $post_id && current_user_can( 'read_post', $id );
			}
		);

		uksort(
			$translations,
			static function ( $a, $b ) use ( $default ) {
				return ( $b === $default ) <=> ( $a === $default );
			}
		);

		return $translations;
	}

	/**
	 * @return int[]
	 */
	private static function translations( int $post_id ): array {
		return array_values( array_map( 'intval', pll_get_post_translations( $post_id ) ) );
	}

	private static function action_url( string $action, int $post_id, array $args = [] ): string {
		return wp_nonce_url(
			add_query_arg( array_merge( [ 'action' => $action, 'post' => $post_id ], $args ), admin_url( 'admin-post.php' ) ),
			$action . '_' . $post_id
		);
	}
}
