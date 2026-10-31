=== Cosmo Multilingual Kit for Polylang ===
Contributors: bakhods
Tags: polylang, multilingual, translation, slugs, acf
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fills the gaps of Polylang free: one slug in every language, translated URL bases, per-language ACF options, duplicate-as-translation and more.

== Description ==

Polylang free covers the basics. Building a real multilingual theme on it, you quickly run into things that only Polylang PRO or WPML handle. This plugin adds them, configured from your theme in code so the setup travels with the theme rather than the database.

* **The same slug in every language.** `/about-us/` and `/es/about-us/` instead of `/es/about-us-2/`, for posts, pages, custom post types and terms. Requests resolve to the post or term of the requested language.
* **Translated URL bases.** `/success-stories/` in English, `/es/casos-de-exito/` in Spanish, for post types, taxonomies and the blog base.
* **Per-language ACF / SCF options pages.** Language tabs above the options page, a "copy from the default language" button, and a fallback to the default language for empty values. `get_field( 'name', 'options' )` keeps working.
* **Duplicate as translation.** A "Duplicate → Español" row action copies blocks, custom fields, featured image, template and terms, and links the posts as translations.
* **Copy from another language.** A "Translation" box in the editor replaces a translation's title, content, custom fields and terms with those of any other language, like WPML's "Copy content from". New translations created with Polylang's "+" start pre-filled from the source post.
* **Outdated translations.** A translation remembers what it was copied from. Once the source changes, the editor box and the posts list flag it as outdated until it is marked up to date or copied again.
* **Menu sync.** One button on the Menus screen copies the default language menus to every other language and assigns them to the same locations. Items point to the translated posts and terms, labels already translated are kept, and items removed from the source are removed from the copies.
* **Translated ids on output.** Reusable blocks and ACF post object, relationship and taxonomy values, options pages included, show the current language's translation when there is one.
* **Default category in every language.** Repairs the default category translation Polylang leaves without a language when languages are created programmatically, and creates missing ones.
* **Image alt per language**, with a fallback to the library alt, for images output with `wp_get_attachment_image()`. The block editor's Image block saves its alt into the post content and is translated with the post.
* **Theme strings** in Languages → Translations.
* **Author profile fields per language**, including the biography fallback Polylang free lacks.
* **Language switcher data** that hides untranslated, hidden or not-yet-translated copies, or keeps them greyed out, plus hreflang `x-default`.
* **Paged archives.** The switcher and hreflang of `/page/2/` point to page 2 of each translation, and a language with fewer pages is left out. Polylang prints no hreflang on paged archives at all.
* **No foreign slugs.** A URL reached through another language's slug, like `/pt/recursos/` for `/pt/blog-e-noticias/`, redirects to the translation's own address instead of serving a duplicate.
* **One Yoast sitemap per language**, if you prefer it. With languages in directories Yoast's per-type maps mix every language, as they do with WPML; opted in, the sitemap index lists `en-sitemap.xml`, `es-sitemap.xml`… instead. Yoast's own rules for noindex, exclusions and canonicals still apply.
* **Untranslated front page.** Until the static front page is translated, a language's home redirects to the default language one instead of showing its empty blog, home links follow, and hreflang leaves that language out. Hidden languages are left out of hreflang too.

Requires Polylang 3.7 or later.

Cosmo Multilingual Kit for Polylang is an independent, unofficial plugin. "Polylang" is a trademark of WP SYNTEX; this plugin is not affiliated with or endorsed by WP SYNTEX, and the name refers to that plugin solely to describe what this one works with.

== Installation ==

1. Install and activate Polylang 3.7 or later, and add your languages.
2. Upload the plugin through **Plugins - Add New - Upload Plugin**, or install it from the plugin directory.
3. Activate **Cosmo Multilingual Kit for Polylang**.
4. Add the `cosmo/multilingual_kit/config` filter to your theme (see Configuration in the description) and flush permalinks under **Settings - Permalinks**.

== Configuration ==

Everything is configured through a single filter, typically in the theme's `functions.php`:

`
add_filter( 'cosmo/multilingual_kit/config', function ( $config ) {
	return array_merge( $config, [
		'post_types'       => [ 'post', 'page', 'story' ],
		'taxonomies'       => [ 'category', 'post_tag', 'story_category' ],
		'bases'            => [ 'blog', 'story', 'story_category' ],
		'base_map'         => [ 'story' => [ 'es' => 'casos-de-exito' ] ],
		'options_pages'    => [ 'site-options' ],
		'strings'          => [ 'read_more' => 'Read more' ],
		'strings_group'    => 'My theme',
		'user_fields'      => [ 'position' => 'Position' ],
		'pending_meta'     => 'translation_pending',
		'hidden_languages' => [ 'uz' ],
		'sitemaps'         => 'language',
	] );
} );
`

* `post_types`, `taxonomies` — made translatable in addition to Polylang's settings.
* `bases` — sections whose URL base can be translated: post type names, taxonomy names, or `blog`. A default can be given explicitly (`'blog' => 'news'`), otherwise the registered rewrite slug is used. Every URL of the section follows the translated base: nested terms, feeds, paging, archives.
* `base_map` — translated bases per language. Flush permalinks after changing it, and let your SEO plugin rebuild its URL cache.
* `options_pages` — ACF options page slugs stored per language. Storage goes by the page's post id, so every options page that keeps the default `options` post id becomes per-language too; give pages that should stay shared their own `post_id`. A field left empty in a language shows the default language value, except for cells inside repeaters and flexible content.
* `strings`, `strings_group` — template strings registered with Polylang.
* `user_fields` — profile fields translated per language.
* `pending_meta` — meta key set on duplicated copies; while set, the language switcher hides that language.
* `hidden_languages` — languages left out of the switcher and hreflang, e.g. while their content is being prepared.
* `x_default` — add hreflang `x-default` (default `true`).
* `prefill_translations` — pre-fill translations created with Polylang's "+" from the source post (default `true`, off with Polylang PRO, which does it itself).
* `sitemaps` — Yoast sitemaps with languages in directories: `mixed` keeps Yoast's per-type maps with every language in them (default), `language` gives one map per language. Subdomains and domains get a sitemap per host either way.
* `modules` — switch modules off: `[ 'media' => false ]`. Keys: `slugs`, `bases`, `default_term`, `options`, `duplicate`, `translations`, `menus`, `ids`, `media`, `strings`, `users`, `frontend`, `sitemaps`.

== Template functions ==

* `cosmo_mlk_language()`, `cosmo_mlk_default_language()`, `cosmo_mlk_locale( $language )`, `cosmo_mlk_languages()`
* `cosmo_mlk_home_url( $path, $language )`, `cosmo_mlk_url_prefix( $language )`, `cosmo_mlk_url_base( $key, $language )`
* `cosmo_mlk_switcher( $args )` — languages for a switcher: slug, name, locale, url, flag, current, available. Pass `keep_unavailable => true` to keep untranslated languages with an empty url.
* `cosmo_mlk_t( $key, $fallback )` — translated template string
* `cosmo_mlk_user_field( $user_id, $key, $language )`, `cosmo_mlk_image_alt( $attachment_id, $language )`
* `cosmo_mlk_translated_post( $post_id, $language )`, `cosmo_mlk_readable_translation( $post_id, $language )`, `cosmo_mlk_pending_translations()`
* `cosmo_mlk_term_by_slug( $slug, $taxonomy, $language )`, `cosmo_mlk_insert_term( $name, $taxonomy, $language, $args )`
* `cosmo_mlk_duplicate_post( $post_id, $language, $args )`, `cosmo_mlk_link_translations( $map )`, `cosmo_mlk_link_term_translations( $map )`
* `cosmo_mlk_copy_post( $from, $to )`, `cosmo_mlk_translation_outdated( $post_id )`
* `cosmo_mlk_sync_menus()`
* `cosmo_mlk_copy_options( $from, $to, $pages )`, `cosmo_mlk_migrate_legacy_options( $language )`

Every function keeps working with Polylang switched off, falling back to a single default language.

== Hooks ==

* `cosmo/multilingual_kit/config` — the configuration above.
* `cosmo/multilingual_kit/switcher` — filters the switcher language list.
* `cosmo/multilingual_kit/per_page` — posts per page of a post type archive, for the paged URLs in the language sitemaps: `( $per_page, $post_type )`. Defaults to the Reading setting.
* `cosmo/multilingual_kit/duplicated` — fires after a translation is created by copying: `( $new_id, $source_id, $language )`.
* `cosmo/multilingual_kit/copied` — fires after an existing translation is overwritten from another language: `( $post_id, $source_id, $language )`.

== Frequently Asked Questions ==

= I switched an existing site to per-language options and the settings look empty =

Values saved before were stored without a language. Run `cosmo_mlk_migrate_legacy_options()` once to move them to the default language.

= Does it work with Polylang PRO? =

It targets Polylang free. With PRO active, the `slugs`, `bases` and `duplicate` modules switch themselves off, since PRO covers them. An explicit `modules` entry in the config overrides that.

= Which modules switch themselves off? =

The Plugins screen lists them with the reason:

* `slugs` and `bases` and `duplicate` with Polylang PRO.
* `slugs` when Polylang sets the language from the content: with no language in the URL, shared slugs cannot be told apart.
* `options` with ACF Options For Polylang, which stores options per language on its own.
* `media` when Polylang media translation is on, as every language then has its own attachment.

= Which URL modes are supported? =

Language as a directory (with or without `/language/`), as a subdomain, or as a domain. Sites installed in a subdirectory work too.

= Can I duplicate WooCommerce products? =

No. WooCommerce keeps product data outside post meta, so a plain copy would give a broken product.

== Changelog ==

= 1.2.2 =
* A posts page sharing its slug with its translations shows each language's blog instead of an empty page.

= 1.2.1 =
* Yoast sitemaps stay mixed across languages by default, as in 1.1 and WPML. One sitemap per language is opted into with the new `sitemaps => 'language'` config key.

= 1.2.0 =
* Paged archives keep the page number in the switcher and get hreflang, which Polylang leaves out.
* URLs reached through another language's slug redirect to the translation's own address.
* New `sitemaps` module: one Yoast sitemap per language, paged archives included.

= 1.1.1 =
* Template functions are pluggable, so the plugin activates on a theme that declares its own fallbacks for them.

= 1.1.0 =
* Copy from another language, pre-filled new translations and outdated translation tracking.
* Menu sync across languages.
* Reusable blocks and ACF relational fields follow the current language.
* Switcher can keep untranslated languages greyed out.
* Untranslated front page no longer exposes an empty blog in other languages.
* Hidden languages are left out of hreflang.
* Options pages: get_field() by name works in a language that was never saved.

= 1.0.0 =
* First release.
