# Plan 006: `Content_Filter`, the public filter API, and `Plugin` wiring

> **Executor instructions**: Build test-first with Brain Monkey for the targeting / `should_run` / filter-application logic. Honour "STOP conditions". Update `plans/README.md` when done.
>
> **Drift check (run first)**: confirm Plans 001–005 committed and green. Open `classes/Linker.php` and confirm `link( string $html, array $keywords, Ruleset $rules, ?callable $attribute_filter = null ): string`. Open `classes/Settings_Repository.php` (`get_ruleset()`, `get_post_types()`, `get_terms()`) and `classes/Keyword_Repository.php` (`all()`). If signatures differ, STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (this is where the engine meets `the_content`; wrong priority or targeting breaks rendering)
- **Depends on**: 003, 004, 005
- **Category**: correctness / integration
- **Planned at**: greenfield, written 2026-06-25

## Why this matters

`Content_Filter` is the bridge between WordPress and the pure engine: it hooks `the_content`, decides whether to run (targeting + `should_run`), assembles the `Ruleset` (applying the per-context filters), fetches keywords (applying the keywords filter), calls the `Linker`, and exposes the `kntnt_autolink_link_attributes` filter through the engine's callback seam. This plan also finally wires `Plugin` so the plugin actually does something. The five-filter public API (design §7) is realised here — it is the "never hit the no-hooks wall" promise that motivated the whole project (design §1).

## Current state

After Plan 005 every component exists but nothing is hooked into WordPress: `Plugin::__construct()` is still empty (Plan 001). The pure `Linker`, the repositories, and `Capabilities` are ready.

**The five public filters** (design §7), prefix `kntnt_autolink_`:

| Filter | Signature | Purpose |
|---|---|---|
| `kntnt_autolink_keywords` | `( array $keywords ) → array` | Filter the keyword set (array of `Keyword`) before matching. |
| `kntnt_autolink_deny` | `( array $deny, WP_Post $post ) → array` | Adjust deny rules per context. `$deny = ['tags' => list<string>, 'xpath' => ?string]`. |
| `kntnt_autolink_allow_only` | `( string $xpath, WP_Post $post ) → string` | Set/override the include-only XPath per context (empty string = none). |
| `kntnt_autolink_should_run` | `( bool $run, WP_Post $post ) → bool` | Short-circuit per request/post. |
| `kntnt_autolink_link_attributes` | `( array $attrs, array $context ) → array` | Filter generated `<a>` attributes per match. |

Plus `kntnt_autolink_content_priority` `( int ) → int` — the `the_content` hook priority (default after `wpautop`/shortcodes; use `20`).

**Targeting** (design §4): before the engine runs, check the current post's type is in `get_post_types()` and (if `terms` targeting is set) the post has a matching term; if out of scope, return content untouched.

**Conventions**: `declare(strict_types=1)`; tabs; padded parens; PHPDoc `@since 1.0.0`; user-facing strings (none expected here) English + text domain.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| This suite | `vendor/bin/pest tests/Unit/Content_Filter_Test.php` | pass |
| All unit tests | `composer test` | all pass |
| Static analysis | `composer analyse` | "No errors" |

## Scope

**In scope**:
- `classes/Content_Filter.php` (create)
- `classes/Plugin.php` (edit — wire the components)
- `tests/Unit/Content_Filter_Test.php` (create)

**Out of scope**:
- The `Linker`, repositories, `Ruleset`, `Keyword`, `Capabilities` — consume them; do not modify.
- The admin page (Plan 007).
- Do NOT register the `the_content` filter anywhere except via `Content_Filter::register_hooks()` called from `Plugin`.

## Public contract

```php
// Content_Filter
public function __construct( Settings_Repository $settings, Keyword_Repository $keywords, Linker $linker );
public function register_hooks(): void;                 // add_filter('the_content', ..., <priority>)
public function filter_content( string $content ): string;   // the the_content callback
```

## Git workflow

- Branch `advisor/006-content-filter-and-wiring`. Commit `Content_Filter` then `Plugin`. Do not push.

## Steps

### Step 1: `Content_Filter` — tests first

With Brain Monkey, stub `get_post`, `get_the_ID`/`get_post_type`, `apply_filters`, `is_singular`/post-type checks, and `has_term`. Cases:

1. **Out-of-scope post type** → `filter_content( $content )` returns `$content` unchanged (no keywords fetched, `Linker` not called). Verify by stubbing `get_post()->post_type` to something not in `['post','page']`.
2. **`should_run` filter false** → even an in-scope post returns `$content` unchanged when `apply_filters('kntnt_autolink_should_run', true, $post)` returns false.
3. **In scope, runs** → `Linker::link()` is called once with: the keywords from `Keyword_Repository::all()` after passing through `apply_filters('kntnt_autolink_keywords', …)`, the `Ruleset` assembled from settings + the `deny`/`allow_only` filters, and a non-null `$attribute_filter` callable. Use a Mockery mock of `Linker` to assert the call and return a sentinel string; assert `filter_content` returns the sentinel.
4. **Deny filter applied** → `apply_filters('kntnt_autolink_deny', ['tags'=>…,'xpath'=>…], $post)` result is reflected in the `Ruleset` passed to `Linker` (assert the mock received a `Ruleset` whose `deny_tags`/`deny_xpath` match the filtered values).
5. **Allow-only filter applied** → `apply_filters('kntnt_autolink_allow_only', '', $post)` returning `'//main'` makes the `Ruleset->allow_only_xpath === '//main'`.
6. **Attribute-filter seam** → the callable passed to `Linker` calls `apply_filters('kntnt_autolink_link_attributes', $attrs, $context)` and returns its result. Assert by invoking the captured callable with sample `$attrs`/`$context` and checking `apply_filters` was called with the right hook name.
7. **Empty keywords** → when `Keyword_Repository::all()` (post-filter) is empty, return `$content` unchanged without calling `Linker` (optional optimisation; assert `Linker` not called).

**Verify**: `vendor/bin/pest tests/Unit/Content_Filter_Test.php` → fails first.

### Step 2: `Content_Filter` — implement

Target shape:

```php
<?php
/**
 * Bridges the_content to the pure Linker: targeting, should_run, Ruleset
 * assembly via the public filters, and the per-match attribute filter seam.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Content_Filter {

	/**
	 * @since 1.0.0
	 */
	public function __construct(
		private readonly Settings_Repository $settings,
		private readonly Keyword_Repository $keywords,
		private readonly Linker $linker,
	) {}

	/**
	 * Register the the_content filter at the configurable priority.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		$priority = (int) apply_filters( 'kntnt_autolink_content_priority', 20 );
		add_filter( 'the_content', $this->filter_content( ... ), $priority );
	}

	/**
	 * Link eligible keyword occurrences in post content, or pass it through
	 * untouched when out of scope or short-circuited.
	 *
	 * @since 1.0.0
	 */
	public function filter_content( string $content ): string {

		// Resolve the current post; bail when there is none (e.g. a non-post context).
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		// Targeting: only run on configured post types (and terms, when set).
		if ( ! $this->is_in_scope( $post ) ) {
			return $content;
		}

		// Per-request short-circuit.
		if ( ! apply_filters( 'kntnt_autolink_should_run', true, $post ) ) {
			return $content;
		}

		// Keyword set, filterable for config-as-code / overrides.
		$keywords = apply_filters( 'kntnt_autolink_keywords', $this->keywords->all() );
		if ( $keywords === [] ) {
			return $content;
		}

		// Assemble the Ruleset from settings, then apply the per-context filters.
		$rules = $this->build_ruleset( $post );

		// Hand off to the pure engine, exposing the attribute filter through its callback.
		return $this->linker->link(
			$content,
			$keywords,
			$rules,
			static fn ( array $attrs, array $context ): array =>
				apply_filters( 'kntnt_autolink_link_attributes', $attrs, $context ),
		);

	}

	// is_in_scope( WP_Post ): post_type in settings->get_post_types(); if settings->get_terms()
	//   non-empty, require has_term match. private.
	// build_ruleset( WP_Post ): start from settings->get_ruleset(); apply kntnt_autolink_deny
	//   (tags + xpath) and kntnt_autolink_allow_only; return a new Ruleset with those overrides.
	//   Since Ruleset is readonly, construct a fresh one from the filtered values + the
	//   settings' link policy. private.

}
```

`build_ruleset()` detail: read base values from `$this->settings->get_settings()`, apply:
```php
$deny = apply_filters( 'kntnt_autolink_deny', [ 'tags' => $s['deny_tags'], 'xpath' => $s['deny_xpath'] !== '' ? $s['deny_xpath'] : null ], $post );
$allow_only = (string) apply_filters( 'kntnt_autolink_allow_only', $s['allow_only_xpath'], $post );
```
then `new Ruleset( deny_tags: $deny['tags'], skip_class: $s['skip_class'], deny_xpath: $deny['xpath'] ?? null, allow_only_xpath: $allow_only === '' ? null : $allow_only, link_class: $s['link_class'], nofollow: (bool)$s['nofollow'], new_tab: (bool)$s['new_tab'], max_links_per_post: (int)$s['max_links_per_post'] )`.

**Verify**: `vendor/bin/pest tests/Unit/Content_Filter_Test.php` → passes; `composer analyse` → clean.

### Step 3: Wire `Plugin`

Replace the empty constructor with dependency-ordered wiring:

```php
private function __construct() {
	// Instantiate repositories and the pure engine, then wire the content filter.
	$settings = new Settings_Repository();
	$keywords = new Keyword_Repository();
	$linker = new Linker();

	$content_filter = new Content_Filter( $settings, $keywords, $linker );
	$content_filter->register_hooks();
}
```

(The admin page is added to this constructor in Plan 007 — leave a clear place for it.)

**Verify**: `php -l classes/Plugin.php` → clean; `composer analyse` → clean.

### Step 4: Full suite + static analysis

**Verify**: `composer test` → all pass; `composer analyse` → "No errors".

## Test plan

- `tests/Unit/Content_Filter_Test.php` — the 7 cases in Step 1, using a Mockery mock of `Linker` to assert the hand-off and Brain Monkey for the `apply_filters`/`get_post`/`has_term` stubs. Model after `tests/Unit/Settings_Repository_Test.php`.
- `Plugin` wiring is covered end-to-end by the integration suite (Plan 008); a unit test of the singleton is not required.
- Verification: `composer test` → all unit tests pass.

## Done criteria

- [ ] `composer test` exits 0; `Content_Filter_Test` passes
- [ ] `composer analyse` exits 0, "No errors"
- [ ] `grep -rn "add_filter( 'the_content'" classes/` appears exactly once (in `Content_Filter`)
- [ ] All five design filters + `kntnt_autolink_content_priority` appear by name in `classes/Content_Filter.php`
- [ ] `Plugin::__construct` instantiates `Settings_Repository`, `Keyword_Repository`, `Linker`, `Content_Filter` and calls `register_hooks()`
- [ ] `git status` shows only in-scope files
- [ ] `plans/README.md` row for 006 updated

## STOP conditions

Stop and report if:
- `Linker::link()`'s signature does not accept the `?callable` fourth argument (Plan 003 drift) — the attribute-filter seam depends on it.
- `Ruleset` cannot be constructed from the filtered values because its constructor changed (Plan 002 drift).
- You find the `the_content` priority interacts badly with `wpautop` in a way the integration test (Plan 008) later reveals — note it for Plan 008 rather than guessing a different number now; default stays `20`.

## Maintenance notes

- The `the_content` priority (`20`) runs after `wpautop` (10) and `do_shortcode` (11) so the engine sees fully-rendered HTML. If a theme/plugin reorders `the_content`, the `kntnt_autolink_content_priority` filter is the escape hatch — document it (Plan 009).
- All five filters are the public API. A reviewer must ensure none is renamed without a deprecation path; external code depends on the exact names.
- The attribute-filter closure is `static` and stateless — keep it so; it is called once per generated link and must stay cheap (it runs on the render path).
- Targeting runs before any keyword fetch or DOM work, preserving the "cheap on out-of-scope posts" property. Keep the order: post → in_scope → should_run → keywords → ruleset → link.
