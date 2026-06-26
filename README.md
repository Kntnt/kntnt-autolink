# Kntnt Autolink

![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)
![Requires PHP](https://img.shields.io/badge/PHP-8.4%2B-blue)
![Requires WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue)
![Latest release](https://img.shields.io/github/v/release/Kntnt/kntnt-autolink)

Kntnt Autolink turns chosen phrases into links to URLs you specify, directly in your published content, so your internal linking stays consistent without manual effort.

## Description

Kntnt Autolink links the first eligible occurrences of the phrases you define to a destination URL of your choice. Phrases are organised into link groups: a group's phrases are interchangeable, share one URL and one link budget, and carry their own link behaviour. It is a small, fully owned replacement for the Autolink module of Slim SEO Pro, built around a WordPress-free matching engine and a small, documented filter API.

The links it creates are real and followable by default, because the point of internal linking is to let search engines follow it. Matching is exact and case-insensitive, respects Unicode word boundaries so a phrase never links inside a longer word, and can be confined to any region of the page you can name with an XPath expression – an include-only targeting feature no comparable free plugin offers.

### Key features

- Exact, literal phrase matching with Unicode word boundaries, so `page` never links inside `homepage`.
- Include-only targeting: restrict linking to within any region you can name with an XPath expression.
- A deny-tag list and a `.no-autolink` class that keep links out of headings, code and any block you choose.
- First-occurrence linking with a per-group cap and a global per-post cap; the longest matching phrase wins a tie.
- Per-group link behaviour: each link group decides on its own whether its links are nofollowed and whether they open in a new tab.
- Followable links by default, each carrying a `kntnt-autolink` class so the theme can restyle them.
- A small filter API – five behaviour filters plus a priority filter – so you never hit the no-hooks wall.
- A split of authority: editors manage link groups under Tools, while administrators alone control the structural rules under Settings.
- A matching engine that makes no WordPress calls and is unit-tested in isolation.

### The problem

Slim SEO Pro's Autolink module hard-codes which tags it skips and exposes no filter or action, so there is no way to stop it linking inside a heading, to exclude a single paragraph or to restrict linking to a chosen region. The free alternatives are no better: most are option-heavy or freemium, and the most promising of them links a phrase inside longer words – linking `page` inside `homepage`, for instance – because its matching ignores word boundaries.

### How this plugin helps

Kntnt Autolink parses the rendered content into a DOM, walks the ancestor chain of each candidate text node so a denied tag is honoured even through nested elements, and matches with Unicode word boundaries so partial-word matches cannot happen. Where to link is yours to decide: a friendly deny-tag list and skip class for everyday cases, and raw deny and include-only XPath for everything else. Every decision the engine makes is exposed through a filter, so a theme or a companion plugin can adjust it without patching the source.

### Limitations

The plugin is deliberately small, and some things are out of scope by design:

- No inflection or plural engine. Irregular forms are entered by hand as extra phrases in a link group.
- No CSS-to-XPath converter. The deny-tag list and skip class cover the common cases; raw XPath is the escape hatch for the rest.
- No minimum distance between links, no per-group rule level and no statistics or live preview.
- Linking is computed on every render rather than stored. A `stripos` pre-check keeps posts with no matching phrase close to free, and nothing is ever written on the read path.

## Requirements

WordPress 6.5 or later, and PHP 8.4 or later. On an older PHP version the plugin refuses to load and shows an admin notice rather than causing a fatal error mid-request.

## Installation

1. Download the latest release from [the releases page](https://github.com/Kntnt/kntnt-autolink/releases/latest/download/kntnt-autolink.zip), or clone this repository into `wp-content/plugins/kntnt-autolink`.
2. Activate **Kntnt Autolink** from **Plugins** in the WordPress admin. Activation grants the link-group-management capability to editors and administrators.
3. If you run Slim SEO Pro, disable its Autolink module so that two linkers do not both process `the_content`. Keep the rest of Slim SEO Pro; only the Autolink module is replaced.

## Usage

Manage link groups under **Tools → Autolink**; administrators configure the structural rules under **Settings → Autolink**.

### Managing link groups

Editors and administrators manage link groups in a native list, each shown as its phrases, URL and group cap. A **link group** is a set of equal, interchangeable **phrases** that all link to one URL, share one **group cap** (how many links the group may make in a post), and carry their own **nofollow** and **new-tab** behaviour. There is no canonical or "main" phrase — every phrase is a peer. Add New opens a modal; clicking a group's phrases (or its Edit row action) opens the same modal to edit it, and Delete removes it. Saving and deleting happen over REST and refresh the list in place.

Matching is exact and literal: there is no regular expression for you to write and no inflection engine. It is case-insensitive and respects word boundaries, and the longest matching phrase wins a tie, so `machine learning` links as one phrase rather than linking `learning` inside it. Extra phrases are entered by hand, one per line, which is how you cover irregular plurals and alternative spellings within a single group.

### Structural rules (administrators only)

Administrators alone reach a **Settings → Autolink** page with the structural rules editors never see: the deny-tag list, the skip class, the raw deny and include-only XPath expressions, the link class, the global per-post cap and the post-type and term targeting. These are reserved for administrators because a mistaken XPath could break matching across the whole site. Whether links are nofollowed or open in a new tab is no longer global — it is set per link group.

### Styling

Every generated link carries `class="kntnt-autolink"`, so the theme can restyle auto-links with no plugin code – a dotted underline, for example:

```css
a.kntnt-autolink {
	text-decoration-style: dotted;
}
```

### Silencing an element with `.no-autolink`

Add the class `no-autolink` to any element to stop linking inside it and all of its descendants. The test is class-token-safe, so an unrelated class such as `no-autolink-foo` is unaffected. This is how an editor silences a single paragraph or block without touching the rules.

## Frequently asked questions (FAQ)

#### Why are the links followable rather than `nofollow` by default?

Internal links exist to be crawled, which is the whole point of internal linking for search. You can turn `nofollow` on per link group, or per request through the filters.

#### Does the plugin rewrite my stored content?

No. Linking happens while the page renders and never when content is saved. The database is never written on the read path.

#### A phrase linked inside a longer word once. Can that happen here?

No. Matching respects Unicode word boundaries, so a phrase never links inside a longer word – `page` is never linked inside `homepage`.

## Questions, bugs, and feature requests

Have a usage question or something to discuss? Please use [Discussions](https://github.com/Kntnt/kntnt-autolink/discussions).

Found a bug or want to request a feature? Please [open an issue](https://github.com/Kntnt/kntnt-autolink/issues). Search the existing issues first to avoid duplicates.

## Extending

Every decision the engine makes is exposed through a filter, all prefixed `kntnt_autolink_`. There are five behaviour filters plus one that sets the `the_content` priority. Each has its own subsection below.

### `kntnt_autolink_link_groups`

`apply_filters( 'kntnt_autolink_link_groups', Kntnt\Autolink\Link_Group[] $groups ): Kntnt\Autolink\Link_Group[]`

Filters the link-group set before matching. Inject, override or remove groups – useful for config-as-code.

```php
add_filter( 'kntnt_autolink_link_groups', function ( array $groups ): array {
	$groups[] = new Kntnt\Autolink\Link_Group(
		id: 'wp',
		phrases: [ 'WordPress' ],
		url: 'https://wordpress.org/',
		cap: 1,
	);
	return $groups;
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

Sets or overrides the include-only XPath per context. An empty string means ‘no restriction’.

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

Filters the generated `<a>` attributes per match. `$context` carries `url`, `group_id` and `matched_text`. This is also the seam a future hovercard hooks into.

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

## Development

### Build from source

Clone the repository and install the development dependencies with Composer:

```bash
git clone https://github.com/Kntnt/kntnt-autolink.git
cd kntnt-autolink
composer install
```

### Run tests

```bash
composer test       # Pest unit suite
composer analyse    # PHPStan at level max, with the WordPress stubs
```

The end-to-end suite boots a real WordPress on [WordPress Playground](https://wordpress.github.io/wordpress-playground/) and asserts the linking and capability behaviour over HTTP. It needs Node.js with `npx`:

```bash
bash tests/Integration/run.sh
```

### Technical documentation

The matching engine (`Kntnt\Autolink\Linker`) makes no WordPress calls: it takes HTML, a set of link groups and a `Ruleset`, and returns the linked HTML. That purity is what keeps every matching decision in one place and makes the engine unit-testable in isolation. The WordPress glue – the option repositories, the `the_content` bridge, the capabilities and the admin page – surrounds it. The source lives under `classes/`, the design rationale in `kntnt-autolink-design.md`, the build history in `plans/`, and the coding standard in `AGENTS.md` and `agents.d/`.

## How you can contribute

Contributions are welcome, large or small: opening an issue to report a bug or request a feature, submitting a pull request, translating the plugin or improving the documentation. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before you start, follow the [Code of Conduct](CODE_OF_CONDUCT.md), and report security issues privately as described in [SECURITY.md](SECURITY.md).

## Acknowledgements

The one good idea worth keeping from the surveyed alternatives – parse the DOM, skip the disallowed tags and walk the ancestor chain – is reused here; everything else is rebuilt. The test and analysis toolchain rests on [Pest](https://pestphp.com/), [PHPStan](https://phpstan.org/), [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) and [WordPress Playground](https://wordpress.github.io/wordpress-playground/).

## License

This plugin is licensed under the GPL-2.0-or-later licence. See [LICENSE](LICENSE) for the full text.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

The project follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).
