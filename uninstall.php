<?php
/**
 * Translations stored by the plugin (options_<lang>, alt and profile meta) are
 * content, so only the internal flag goes.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cosmo_mlk_default_term_checked' );
