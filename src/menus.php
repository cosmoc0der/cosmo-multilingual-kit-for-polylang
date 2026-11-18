<?php
/**
 * Polylang free wants a separate menu built by hand for every language and
 * location. Like WPML's menu sync, this copies the default language menus to
 * the other languages, pointing items to the translations where they exist.
 * A label changed in a translated menu survives the next sync.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Menus {

	private const ACTION = 'cosmo_mlk_sync_menus';

	/** On a translated item: the item it was copied from. */
	private const ITEM_META = '_cosmo_mlk_source_item';

	/** On a translated custom link: the page of the site it points to. */
	private const POST_META = '_cosmo_mlk_source_post';

	/** On a translated menu: the menu it was copied from, and its language. */
	private const MENU_META     = '_cosmo_mlk_source_menu';
	private const LANGUAGE_META = '_cosmo_mlk_language';

	private const ITEM_FIELDS = [
		'attr_title'  => 'menu-item-attr-title',
		'description' => 'menu-item-description',
		'target'      => 'menu-item-target',
		'xfn'         => 'menu-item-xfn',
	];

	public static function init(): void {
		add_action( 'admin_notices', [ self::class, 'render_box' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_request' ] );
		add_filter( 'wp_get_nav_menu_items', [ self::class, 'drop_untranslated_items' ], 10, 2 );
	}

	/**
	 * @return int Number of menus written.
	 */
	public static function sync(): int {
		if ( ! Config::active() ) {
			return 0;
		}

		// The sync must see every item, also when run from WP-CLI where is_admin() is false.
		remove_filter( 'wp_get_nav_menu_items', [ self::class, 'drop_untranslated_items' ] );

		$theme     = get_stylesheet();
		$default   = Config::default_language();
		$nav_menus = (array) PLL()->options->get( 'nav_menus' );
		$core      = (array) get_theme_mod( 'nav_menu_locations', [] );
		$synced    = [];

		foreach ( array_keys( get_registered_nav_menus() ) as $location ) {
			$source = (int) ( $nav_menus[ $theme ][ $location ][ $default ] ?? 0 ) ?: (int) ( $core[ $location ] ?? 0 );

			if ( ! $source || ! is_nav_menu( $source ) ) {
				continue;
			}

			// Polylang empties a location that has no menu for the current language.
			$nav_menus[ $theme ][ $location ][ $default ] = $source;

			foreach ( Config::secondary_languages() as $language => $info ) {
				$target = (int) ( $nav_menus[ $theme ][ $location ][ $language ] ?? 0 );

				if ( ! $target || $target === $source || ! is_nav_menu( $target ) ) {
					$target = self::target_menu( $source, $language, $info['name'] );
				}

				if ( ! $target ) {
					continue;
				}

				// A menu shared by several locations is written once.
				if ( ! isset( $synced[ $target ] ) ) {
					self::sync_menu( $source, $target, $language );
					$synced[ $target ] = true;
				}

				$nav_menus[ $theme ][ $location ][ $language ] = $target;
			}
		}

		PLL()->options->set( 'nav_menus', $nav_menus );
		PLL()->options->save();

		return count( $synced );
	}

	public static function render_box(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || $screen->id !== 'nav-menus' || ! current_user_can( 'edit_theme_options' ) || ! Config::secondary_languages() ) {
			return;
		}

		Plugin::enqueue_admin_assets();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$synced = isset( $_GET['cosmo_mlk_synced'] ) ? absint( $_GET['cosmo_mlk_synced'] ) : null;

		if ( $synced !== null ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of menus. */
					_n( '%d translated menu is up to date.', '%d translated menus are up to date.', $synced, 'cosmo-multilingual-kit-for-polylang' ),
					$synced
				) )
			);
		}

		printf(
			'<div class="notice notice-info cosmo-mlk-menus"><p>%1$s</p><p><a class="button" href="%2$s" data-cosmo-mlk-confirm="%3$s">%4$s</a></p></div>',
			esc_html__( 'Copy the menus of the default language to the other languages. Items link to translations where they exist, and labels already changed in a translated menu are kept.', 'cosmo-multilingual-kit-for-polylang' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ) ),
			esc_attr__( 'Rebuild the translated menus from the default language? Items added only to a translated menu are kept.', 'cosmo-multilingual-kit-for-polylang' ),
			esc_html__( 'Sync menus to all languages', 'cosmo-multilingual-kit-for-polylang' )
		);
	}

	public static function handle_request(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cosmo-multilingual-kit-for-polylang' ) );
		}

		wp_safe_redirect( add_query_arg( 'cosmo_mlk_synced', self::sync(), admin_url( 'nav-menus.php' ) ) );
		exit;
	}

	private static function target_menu( int $source, string $language, string $language_name ): int {
		$found = get_terms( [
			'taxonomy'   => 'nav_menu',
			'hide_empty' => false,
			'fields'     => 'ids',
			'lang'       => '',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a handful of menus.
				[ 'key' => self::MENU_META, 'value' => $source ],
				[ 'key' => self::LANGUAGE_META, 'value' => $language ],
			],
		] );

		if ( ! is_wp_error( $found ) && $found ) {
			return (int) $found[0];
		}

		$menu = wp_get_nav_menu_object( $source );
		$id   = wp_create_nav_menu( $menu->name . ' (' . $language_name . ')' );

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_term_meta( $id, self::MENU_META, $source );
		update_term_meta( $id, self::LANGUAGE_META, $language );

		return (int) $id;
	}

	private static function sync_menu( int $source, int $target, string $language ): void {
		$existing = [];

		foreach ( (array) wp_get_nav_menu_items( $target, [ 'post_status' => 'any' ] ) as $item ) {
			$from = (int) get_post_meta( $item->ID, self::ITEM_META, true );

			if ( $from ) {
				$existing[ $from ] = $item;
			}
		}

		$items = (array) wp_get_nav_menu_items( $source );
		$map   = [];

		// Parents may come after their children, so ids are mapped before parents are set.
		foreach ( $items as $item ) {
			$map[ $item->ID ] = self::write_item( $target, $item, $existing[ $item->ID ] ?? null, $language, 0 );
		}

		foreach ( $items as $item ) {
			$parent = (int) $item->menu_item_parent;

			if ( $parent && ! empty( $map[ $parent ] ) && ! empty( $map[ $item->ID ] ) ) {
				self::write_item( $target, $item, get_post( $map[ $item->ID ] ), $language, $map[ $parent ] );
			}
		}

		foreach ( $existing as $from => $item ) {
			if ( ! isset( $map[ $from ] ) ) {
				wp_delete_post( $item->ID, true );
			}
		}
	}

	/**
	 * @param \WP_Post|null $current The translated item when it exists.
	 */
	private static function write_item( int $menu, \WP_Post $item, $current, string $language, int $parent ): int {
		$args = [
			'menu-item-type'      => $item->type,
			'menu-item-object'    => $item->object,
			'menu-item-object-id' => (int) $item->object_id,
			'menu-item-url'       => $item->type === 'custom' ? self::translate_url( (string) $item->url, $language ) : '',
			'menu-item-position'  => (int) $item->menu_order,
			'menu-item-parent-id' => $parent,
			'menu-item-classes'   => implode( ' ', array_filter( (array) $item->classes ) ),
			'menu-item-status'    => 'publish',
			// Raw label: empty means "use the object's title", which is then the translated one.
			'menu-item-title'     => $current ? $current->post_title : get_post_field( 'post_title', $item->ID, 'raw' ),
		];

		foreach ( self::ITEM_FIELDS as $property => $key ) {
			$args[ $key ] = (string) $item->$property;
		}

		if ( $item->type === 'post_type' && Config::is_translatable_post_type( $item->object ) ) {
			$args['menu-item-object-id'] = Frontend::published_translation( (int) $item->object_id, $language ) ?: (int) $item->object_id;
		} elseif ( $item->type === 'taxonomy' && Config::is_translatable_taxonomy( $item->object ) ) {
			$args['menu-item-object-id'] = (int) pll_get_term( (int) $item->object_id, $language ) ?: (int) $item->object_id;
		}

		$id = wp_update_nav_menu_item( $menu, $current ? $current->ID : 0, wp_slash( $args ) );

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_post_meta( $id, self::ITEM_META, $item->ID );

		$source_post = $item->type === 'custom' ? Frontend::post_from_url( (string) $item->url ) : 0;

		if ( $source_post ) {
			update_post_meta( $id, self::POST_META, $source_post );
		} else {
			delete_post_meta( $id, self::POST_META );
		}

		self::add_missing_meta( $item->ID, $id );

		return (int) $id;
	}

	/**
	 * Fields themes and plugins keep on items (columns, icons…). Values already
	 * set in the translated item are kept, as its label is.
	 */
	private static function add_missing_meta( int $from, int $to ): void {
		foreach ( (array) get_post_meta( $from ) as $key => $values ) {
			if ( str_starts_with( $key, '_menu_item_' ) || str_starts_with( $key, '_cosmo_mlk_' ) || metadata_exists( 'post', $to, $key ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	private static function translate_url( string $url, string $language ): string {
		return Frontend::translate_url( $url, $language ) ?: $url;
	}

	/**
	 * With `menus_untranslated => 'hide'` a translated menu leaves out items whose
	 * page has no published translation, with their sub-items, and a heading left
	 * without links. Checked on every view, so a translation published later shows
	 * up without another sync.
	 *
	 * @param \WP_Post[] $items
	 * @param \WP_Term   $menu
	 * @return \WP_Post[]
	 */
	public static function drop_untranslated_items( $items, $menu ) {
		if ( is_admin() || Config::get( 'menus_untranslated' ) !== 'hide' || ! is_array( $items ) || ! $menu instanceof \WP_Term ) {
			return $items;
		}

		$language = (string) get_term_meta( $menu->term_id, self::LANGUAGE_META, true );

		if ( $language === '' ) {
			return $items;
		}

		$parents = [];
		$dropped = [];

		foreach ( $items as $item ) {
			$parents[ $item->ID ] = (int) $item->menu_item_parent;
			$url                  = self::item_url( $item, $language );

			if ( $url === '' ) {
				$dropped[ $item->ID ] = true;
			} elseif ( $url !== null ) {
				$item->url = $url;
			}
		}

		$hidden = static function ( int $id ) use ( &$hidden, $parents, $dropped ): bool {
			return isset( $dropped[ $id ] ) || ( ! empty( $parents[ $id ] ) && $hidden( $parents[ $id ] ) );
		};

		$kept = array_filter( $items, static function ( $item ) use ( $hidden ) {
			return ! $hidden( (int) $item->ID );
		} );

		$had_children = array_flip( $parents );
		$has_children = array_flip( wp_list_pluck( $kept, 'menu_item_parent' ) );

		return array_values( array_filter( $kept, static function ( $item ) use ( $had_children, $has_children ) {
			$link = trim( (string) $item->url );

			return ! isset( $had_children[ $item->ID ] ) || isset( $has_children[ $item->ID ] ) || ( $link !== '' && $link !== '#' );
		} ) );
	}

	/**
	 * @return string|null Translation url, '' when there is none, null for items that are not a translatable page.
	 */
	private static function item_url( \WP_Post $item, string $language ): ?string {
		if ( $item->type === 'taxonomy' && Config::is_translatable_taxonomy( $item->object ) ) {
			$term = (int) pll_get_term( (int) $item->object_id, $language );
			$link = $term ? get_term_link( $term ) : '';

			return is_string( $link ) ? $link : '';
		}

		if ( $item->type === 'post_type' && Config::is_translatable_post_type( $item->object ) ) {
			$post_id = (int) $item->object_id;
		} else {
			$post_id = (int) get_post_meta( $item->ID, self::POST_META, true );
		}

		if ( ! $post_id ) {
			return null;
		}

		$translation = Frontend::published_translation( $post_id, $language );

		return $translation ? (string) get_permalink( $translation ) : '';
	}
}
