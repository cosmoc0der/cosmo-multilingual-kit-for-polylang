<?php
/**
 * Plugin Name:       Cosmo Multilingual Kit for Polylang
 * Plugin URI:        https://github.com/cosmoc0der/cosmo-multilingual-kit-for-polylang
 * Description:       Fills the gaps of Polylang free: the same slug in every language, translated URL bases, per-language ACF options pages, duplicate-as-translation, per-language image alt, theme strings and author fields.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Bakhodir Sharipov
 * Author URI:        https://github.com/cosmoc0der
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cosmo-multilingual-kit-for-polylang
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.2.0';
const FILE    = __FILE__;

spl_autoload_register(
	static function ( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '_', '-', strtolower( substr( $class, strlen( $prefix ) ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

require __DIR__ . '/functions.php';

Plugin::boot();
