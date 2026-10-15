<?php
/**
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Keys double as switches in the `modules` config entry. */
	private const MODULES = [
		'slugs'        => Slugs::class,
		'bases'        => Bases::class,
		'default_term' => Default_Term::class,
		'options'      => Options::class,
		'duplicate'    => Duplicate::class,
		'translations' => Translations::class,
		'menus'        => Menus::class,
		'ids'          => Ids::class,
		'media'        => Media::class,
		'strings'      => Strings::class,
		'users'        => Users::class,
		'frontend'     => Frontend::class,
	];

	/** @var array<string,string> Module => why it was switched off. */
	private static $conflicts = [];

	public static function boot(): void {
		Config::init();

		// The theme hooks its config while functions.php loads, so modules start right after that.
		add_action( 'after_setup_theme', [ self::class, 'init_modules' ], 20 );
		add_action( 'admin_notices', [ self::class, 'render_missing_polylang_notice' ] );
		add_action( 'admin_notices', [ self::class, 'render_conflicts_notice' ] );
	}

	public static function init_modules(): void {
		if ( ! Config::active() ) {
			return;
		}

		self::$conflicts = array_diff_key( self::detect_conflicts(), (array) Config::get( 'modules' ) );

		Copier::init();

		foreach ( self::MODULES as $key => $class ) {
			if ( Config::module_enabled( $key, ! isset( self::$conflicts[ $key ] ) ) ) {
				$class::init();
			}
		}
	}

	public static function enqueue_admin_assets(): void {
		wp_enqueue_style( 'cosmo-mlk-admin', plugins_url( 'assets/admin.css', FILE ), [], VERSION );
		wp_enqueue_script( 'cosmo-mlk-admin', plugins_url( 'assets/admin.js', FILE ), [], VERSION, true );
	}

	/**
	 * Modules that would fight Polylang Pro, another plugin or a Polylang setting
	 * doing the same job. An explicit `modules` entry in the config still wins.
	 *
	 * @return array<string,string>
	 */
	private static function detect_conflicts(): array {
		$conflicts = [];

		if ( defined( 'POLYLANG_PRO' ) ) {
			$reason = __( 'Polylang Pro already does this.', 'cosmo-multilingual-kit-for-polylang' );

			$conflicts['slugs']     = $reason;
			$conflicts['bases']     = $reason;
			$conflicts['duplicate'] = $reason;
		} elseif ( ! Config::language_in_url() ) {
			$conflicts['slugs'] = __( 'The language is set from the content, so nothing in the URL tells shared slugs apart.', 'cosmo-multilingual-kit-for-polylang' );
		}

		if ( defined( 'BEA_ACF_OPTIONS_FOR_POLYLANG_VERSION' ) ) {
			$conflicts['options'] = __( 'ACF Options For Polylang already stores options per language.', 'cosmo-multilingual-kit-for-polylang' );
		}

		if ( ! empty( PLL()->options['media_support'] ) ) {
			$conflicts['media'] = __( 'Polylang media translation is on, so every language has its own attachment and alt.', 'cosmo-multilingual-kit-for-polylang' );
		}

		return $conflicts;
	}

	public static function render_missing_polylang_notice(): void {
		if ( Config::polylang_supported() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: %s: Polylang version. */
				__( 'Cosmo Multilingual Kit for Polylang needs Polylang %s or later to be installed and active.', 'cosmo-multilingual-kit-for-polylang' ),
				Config::MIN_POLYLANG
			) )
		);
	}

	public static function render_conflicts_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! self::$conflicts || ! $screen || $screen->id !== 'plugins' || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>' . esc_html__( 'Cosmo Multilingual Kit for Polylang switched off some modules:', 'cosmo-multilingual-kit-for-polylang' ) . '</p><ul>';

		foreach ( self::$conflicts as $module => $reason ) {
			printf( '<li><code>%1$s</code> — %2$s</li>', esc_html( $module ), esc_html( $reason ) );
		}

		echo '</ul></div>';
	}
}
