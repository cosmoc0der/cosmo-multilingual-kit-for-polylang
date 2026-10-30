# Cosmo Multilingual Kit for Polylang

Fills the gaps of Polylang free that otherwise need Polylang PRO or WPML: the
same slug in every language, translated URL bases, per-language ACF / SCF
options pages, duplicate-as-translation, copy from another language with
outdated translation tracking, menu sync, per-language image alt, theme strings,
author fields, hreflang on paged archives and one Yoast sitemap per language.

Requires **Polylang 3.7** or later. Without it every template function falls
back to a single default language.

Independent, unofficial plugin. "Polylang" is a trademark of WP SYNTEX; this
project is not affiliated with or endorsed by WP SYNTEX.

## Configuration

Everything is configured from the theme, so the setup travels with the code
rather than the database:

```php
add_filter( 'cosmo/multilingual_kit/config', function ( $config ) {
	return array_merge( $config, [
		'post_types'    => [ 'post', 'page', 'story' ],
		'taxonomies'    => [ 'category', 'post_tag', 'story_category' ],
		'bases'         => [ 'blog', 'story', 'story_category' ],
		'base_map'      => [ 'story' => [ 'es' => 'casos-de-exito' ] ],
		'options_pages' => [ 'site-options' ],
	] );
} );
```

The full list of keys, template functions (`cosmo_mlk_*`) and hooks is in
[readme.txt](readme.txt).

## Install

Upload via **Plugins → Add New → Upload Plugin**, or drop the folder into
`wp-content/plugins/`, then activate.

## Release

Bump the version in the plugin header, the `VERSION` constant and the
`Stable tag` of `readme.txt`, add a changelog entry, push `main`, then push an
annotated tag `vX.Y.Z`. The tag deploys to WordPress.org; a `readme.txt` or
`.wordpress-org/` change on `main` updates the plugin page.

## License

GPL-2.0-or-later.
