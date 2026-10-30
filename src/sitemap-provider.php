<?php
/**
 * Loaded only once Yoast's sitemaps are running, so its interface exists.
 *
 * @package Cosmo_Multilingual_Kit_For_Polylang
 */

namespace cosmo\Multilingual_Kit_For_Polylang;

defined( 'ABSPATH' ) || exit;

final class Sitemap_Provider implements \WPSEO_Sitemap_Provider {

	public function handles_type( $type ) {
		return in_array( $type, Sitemaps::languages(), true );
	}

	public function get_index_links( $max_entries ) {
		$links = [];

		foreach ( Sitemaps::languages() as $language ) {
			$links[] = [
				'loc'     => \WPSEO_Sitemaps_Router::get_base_url( $language . '-sitemap.xml' ),
				'lastmod' => Sitemaps::last_modified( $language ),
			];
		}

		return $links;
	}

	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		if ( (int) $current_page > 1 ) {
			throw new \OutOfBoundsException( 'Invalid sitemap page requested' );
		}

		return Sitemaps::links( (string) $type );
	}
}
