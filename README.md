# Kntnt Autolink

Rule-based keyword→URL autolinking for WordPress. It links the first eligible occurrence of each keyword you define to a URL of your choice, directly in post content, with an include-only targeting feature no comparable free plugin offers: restrict linking to within any region you can name with an XPath expression. Links are real and followable by default, because the whole point is to let search engines crawl your internal links.

It is a small, deliberately-scoped replacement for Slim SEO Pro's Autolink module, built around a WordPress-free matching engine that is unit-tested in isolation and a tiny, documented filter API so you never hit the "no hooks" wall.

## Requirements

WordPress 6.5 or later, and PHP 8.4 or later. The plugin refuses to load on older PHP rather than fatalling mid-request.

## Installation and deployment

Copy the plugin to `wp-content/plugins/kntnt-autolink` and activate it. Activation grants the keyword-management capability to editors and administrators.

If you run Slim SEO Pro, **disable its Autolink module** so two linkers don't both process `the_content`. Keep the rest of Slim SEO Pro (Schema and so on) — only the Autolink module is replaced.

## Usage

Manage everything under **Tools → Autolink**.

Editors and administrators manage the keyword list: each keyword has a base form (the canonical surface form), optional variants (additional equal-weight surface forms, one per line or comma-separated), a destination URL, and an optional per-keyword maximum number of links per post.

Administrators additionally see a **Structural rules** section that editors never see: the deny-tag list, the skip class, the raw deny and allow-only XPath expressions, the link defaults (`nofollow`, open in a new tab, link class), the global per-post cap, and the post-type/term targeting. These are administrator-only because a bad XPath could break matching site-wide.

Matching is exact and literal — there is no regex for you to author and no inflection engine. Matching is case-insensitive and respects Unicode word boundaries, so `page` never links inside `homepage`, and longer phrases win over their parts. Variants are entered manually (for example, irregular plurals).

## Styling

Every generated link carries `class="kntnt-autolink"`. Restyle auto-links from your theme with zero plugin code — for example a dotted underline:

```css
a.kntnt-autolink {
	text-decoration-style: dotted;
}
```

## The `.no-autolink` class

Add the class `no-autolink` to any element to stop linking inside it and all of its descendants. The test is class-token-safe, so `no-autolink-foo` is unaffected. This is how an editor silences a single paragraph or block without touching the rules.

## Filter reference

All hooks are prefixed `kntnt_autolink_`. There are five behaviour filters plus one that sets the `the_content` priority.

### `kntnt_autolink_keywords`

`apply_filters( 'kntnt_autolink_keywords', Kntnt\Autolink\Keyword[] $keywords ): Kntnt\Autolink\Keyword[]`

Filters the keyword set before matching. Inject, override, or remove entries — useful for config-as-code.

```php
add_filter( 'kntnt_autolink_keywords', function ( array $keywords ): array {
	$keywords[] = new Kntnt\Autolink\Keyword(
		id: 'wp',
		base: 'WordPress',
		variants: [],
		url: 'https://wordpress.org/',
		max: 1,
	);
	return $keywords;
} );
```

### `kntnt_autolink_deny`

`apply_filters( 'kntnt_autolink_deny', array $deny, WP_Post $post ): array`

Adjusts the deny rules per context. `$deny` is `[ 'tags' => string[], 'xpath' => ?string ]`.

```php
// Never link inside <blockquote> on this site.
add_filter( 'kntnt_autolink_deny', function ( array $deny, WP_Post $post ): array {
	$deny['tags'][] = 'blockquote';
	return $deny;
}, 10, 2 );
```

### `kntnt_autolink_allow_only`

`apply_filters( 'kntnt_autolink_allow_only', string $xpath, WP_Post $post ): string`

Sets or overrides the include-only XPath per context. An empty string means "no restriction".

```php
// Only link inside the main content area.
add_filter( 'kntnt_autolink_allow_only', fn ( string $xpath ): string => '//main' );
```

### `kntnt_autolink_should_run`

`apply_filters( 'kntnt_autolink_should_run', bool $run, WP_Post $post ): bool`

Short-circuits per request or post. Return `false` to skip linking entirely.

```php
// Disable autolinking on a specific page template.
add_filter( 'kntnt_autolink_should_run', function ( bool $run, WP_Post $post ): bool {
	return is_page_template( 'no-links.php' ) ? false : $run;
}, 10, 2 );
```

### `kntnt_autolink_link_attributes`

`apply_filters( 'kntnt_autolink_link_attributes', array $attributes, array $context ): array`

Filters the generated `<a>` attributes per match. `$context` carries `url`, `keyword_id`, `base`, and `matched_text`. This is also the seam a future hovercard hooks into.

```php
// Tag every generated link with its destination, for a hovercard or analytics.
add_filter( 'kntnt_autolink_link_attributes', function ( array $attributes, array $context ): array {
	$attributes['data-url'] = $context['url'];
	return $attributes;
}, 10, 2 );
```

### `kntnt_autolink_content_priority`

`apply_filters( 'kntnt_autolink_content_priority', int $priority ): int`

Sets the `the_content` hook priority. The default is `20`, after `wpautop` (10) and `do_shortcode` (11), so the engine sees fully-rendered HTML. Override it only if a theme or plugin reorders `the_content`.

```php
add_filter( 'kntnt_autolink_content_priority', fn (): int => 99 );
```

## Roadmap

Future: optional hovercard (hover preview) as a separate companion plugin, consuming the `kntnt_autolink_link_attributes` filter + `kntnt-autolink` class + a REST preview endpoint.

## Architecture

The matching engine (`Kntnt\Autolink\Linker`) makes no WordPress calls: it takes HTML, a keyword set, and a `Ruleset`, and returns the linked HTML. That purity is what makes it unit-testable in isolation and keeps every matching decision in one place. The WordPress glue — repositories, the `the_content` bridge, capabilities, and the admin page — surrounds it. See `classes/` and the Pest suite under `tests/`.

## License

GPL-2.0-or-later.
