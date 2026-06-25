# Plan 004: `Settings_Repository` and `Keyword_Repository` (test-first, Brain Monkey)

> **Executor instructions**: Build test-first using Brain Monkey to stub WordPress functions. Run every verification command. Honour "STOP conditions". When done, update `plans/README.md`.
>
> **Drift check (run first)**: confirm Plans 001–003 committed and green. Open `classes/Keyword.php` and `classes/Ruleset.php`; confirm their constructors match Plan 002 (`Keyword(id, base, variants, url, max)`; `Ruleset(deny_tags, skip_class, deny_xpath, allow_only_xpath, link_class, nofollow, new_tab, max_links_per_post)`). If they differ, STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (sanitisation correctness; option hydration into value objects)
- **Depends on**: 002
- **Category**: tech-debt (foundation)
- **Planned at**: greenfield, written 2026-06-25

## Why this matters

The two repositories are the only places that touch the two `wp_option`s (design §2, §3). They are the seam that lets the pure engine stay pure: they read the stored arrays and hydrate them into `Ruleset` / `Keyword[]`, and they sanitise on write so everything downstream (engine, admin render) can trust the data. Centralising option access here (read once per request, `autoload = no`) is what keeps the read path cheap and the rest of the code WordPress-option-free.

## Current state

After Plan 003 the repo has the pure engine and value objects, all green. No WordPress glue yet. Brain Monkey (`brain/monkey`) and Mockery are installed (Plan 001 `require-dev`).

**Option contracts** (design §3) — the stored shapes:

`kntnt_autolink_settings` (associative array) with keys and defaults:

| Key | Type | Default |
|---|---|---|
| `deny_tags` | `list<string>` | `['h1','h2','h3','h4','h5','h6','a','code','pre','script','style']` |
| `skip_class` | `string` | `'no-autolink'` |
| `deny_xpath` | `string` (empty = none) | `''` |
| `allow_only_xpath` | `string` (empty = none) | `''` |
| `link_class` | `string` | `'kntnt-autolink'` |
| `nofollow` | `bool` | `false` |
| `new_tab` | `bool` | `false` |
| `max_links_per_post` | `int` | `10` |
| `post_types` | `list<string>` | `['post','page']` |
| `terms` | `array` (taxonomy → list of term ids) | `[]` |

`kntnt_autolink_keywords` — a `list` of entries, each an assoc array `{ id, base, variants (list<string>), url, max (int) }`.

**Conventions**: `declare(strict_types=1)`; tabs; padded parens; `Pascal_Snake_Case`; PHPDoc `@since 1.0.0`. All superglobal/external input sanitised; this plan sanitises on **save**. Escaping at output is the admin page's job (Plan 007), not here.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| These suites | `vendor/bin/pest tests/Unit/Settings_Repository_Test.php tests/Unit/Keyword_Repository_Test.php` | pass |
| All unit tests | `composer test` | all pass |
| Static analysis | `composer analyse` | "No errors" |

## Scope

**In scope** (create):
- `classes/Settings_Repository.php`
- `classes/Keyword_Repository.php`
- `tests/Unit/Settings_Repository_Test.php`
- `tests/Unit/Keyword_Repository_Test.php`
- Edit `tests/Pest.php` to wire Brain Monkey `setUp`/`tearDown` for the `Unit` suite (see Step 1).

**Out of scope**:
- Registering the options' `autoload = no` flag and any default seeding on activation — that is `install.php` (Plan 005). Repositories only read/write; they apply defaults in-memory when the option is absent.
- The `Ruleset`/`Keyword` classes (do not modify).
- The admin form and capability checks (Plan 007).

## Public contracts

```php
// Settings_Repository
public function get_settings(): array;          // raw assoc array, defaults merged in
public function get_ruleset(): Ruleset;         // hydrated Ruleset (empty xpath → null)
public function save_settings( array $input ): void;   // sanitises, then update_option
public function get_post_types(): array;        // list<string>
public function get_terms(): array;             // taxonomy => list<int>
public function get_max_links_per_post(): int;

// Keyword_Repository
public function all(): array;                   // list<Keyword>, hydrated
public function save( Keyword $keyword ): void;  // upsert by id
public function delete( string $id ): void;
public function replace_all( array $keywords ): void;   // list<Keyword> -> overwrite the option
```

Notes:
- `get_ruleset()` converts empty-string `deny_xpath` / `allow_only_xpath` to `null` (the `Ruleset` constructor takes `?string`).
- Both repositories define their option key and defaults as class constants.

## Git workflow

- Branch `advisor/004-repositories`. Commit per repository. Do not push.

## Steps

### Step 1: Wire Brain Monkey into the test bootstrap

Edit `tests/Pest.php` so every Unit test sets up and tears down Brain Monkey (it stubs `get_option`, `update_option`, `sanitize_*`, etc.):

```php
<?php

declare( strict_types = 1 );

use Brain\Monkey;

pest()->extend( PHPUnit\Framework\TestCase::class )
	->beforeEach( fn () => Monkey\setUp() )
	->afterEach( fn () => Monkey\tearDown() )
	->in( 'Unit' );
```

If the existing `tests/Pest.php` already binds the Unit/Integration suites differently, adapt rather than overwrite the Integration binding. The pure-engine tests (Plans 002/003) make no WordPress calls, so wrapping them in Brain Monkey setUp/tearDown is harmless.

**Verify**: `composer test` → previously-passing tests still pass (Brain Monkey setUp/tearDown does not break pure tests).

### Step 2: `Settings_Repository` — tests first

Use Brain Monkey to stub WordPress functions. Cases:

1. `get_settings()` when `get_option('kntnt_autolink_settings')` returns `false` (absent) → returns the full defaults array (assert each default).
2. `get_settings()` merges a partial stored array over defaults (stored `['nofollow' => true]` → result has `nofollow = true` and all other defaults intact).
3. `get_ruleset()` hydrates a `Ruleset` with matching fields; empty `deny_xpath`/`allow_only_xpath` become `null`; a non-empty `allow_only_xpath` is passed through.
4. `save_settings()` sanitises and calls `update_option('kntnt_autolink_settings', <sanitised>, false)` (the `false` = no autoload). Sanitisation rules to assert:
   - `deny_tags`: each entry lowercased and reduced to `[a-z0-9]+` (stub via `sanitize_key` or a small private sanitiser — assert e.g. `' H2 '` → `'h2'`, and a junk entry like `'a<b'` → `'ab'` or is dropped).
   - `skip_class` / `link_class`: HTML class token (assert `sanitize_html_class`-style: spaces/illegal chars stripped).
   - `deny_xpath` / `allow_only_xpath`: trimmed; stored as-is (XPath is admin-only, not HTML — do NOT run it through `esc_*`). Empty after trim → `''`.
   - `nofollow` / `new_tab`: cast to bool.
   - `max_links_per_post`: cast to a non-negative int (`'abc'` → 0, `'12'` → 12).
   - `post_types`: each `sanitize_key`'d.

   Stub `sanitize_key`, `sanitize_html_class`, `absint` via Brain Monkey `when(...)->justReturn(...)` or `alias(...)` to model their behaviour.

Run; see failures.

**Verify**: `vendor/bin/pest tests/Unit/Settings_Repository_Test.php` → fails (class undefined).

### Step 3: `Settings_Repository` — implement

Target shape (abbreviated; fill all keys):

```php
<?php
/**
 * Reads and writes the plugin settings option, hydrating it into a Ruleset and
 * exposing the targeting fields. The only code that touches kntnt_autolink_settings.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Settings_Repository {

	/** @since 1.0.0 */
	private const OPTION = 'kntnt_autolink_settings';

	/** @since 1.0.0 @var array<string, mixed> */
	private const DEFAULTS = [
		'deny_tags'          => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'code', 'pre', 'script', 'style' ],
		'skip_class'         => 'no-autolink',
		'deny_xpath'         => '',
		'allow_only_xpath'   => '',
		'link_class'         => 'kntnt-autolink',
		'nofollow'           => false,
		'new_tab'            => false,
		'max_links_per_post' => 10,
		'post_types'         => [ 'post', 'page' ],
		'terms'              => [],
	];

	/**
	 * Settings with defaults merged in.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) ? [ ...self::DEFAULTS, ...$stored ] : self::DEFAULTS;
	}

	/**
	 * Hydrate a Ruleset from settings. Empty XPath strings become null.
	 *
	 * @since 1.0.0
	 */
	public function get_ruleset(): Ruleset {
		$s = $this->get_settings();
		return new Ruleset(
			deny_tags: $s['deny_tags'],
			skip_class: $s['skip_class'],
			deny_xpath: $s['deny_xpath'] === '' ? null : $s['deny_xpath'],
			allow_only_xpath: $s['allow_only_xpath'] === '' ? null : $s['allow_only_xpath'],
			link_class: $s['link_class'],
			nofollow: (bool) $s['nofollow'],
			new_tab: (bool) $s['new_tab'],
			max_links_per_post: (int) $s['max_links_per_post'],
		);
	}

	// get_post_types(), get_terms(), get_max_links_per_post(): read from get_settings().

	/**
	 * Sanitise and persist settings (non-autoloaded).
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $input Raw form input.
	 */
	public function save_settings( array $input ): void {
		// Sanitise each field per the table in this plan's Step 2, then:
		update_option( self::OPTION, $sanitised, false );
	}

}
```

**Verify**: `vendor/bin/pest tests/Unit/Settings_Repository_Test.php` → passes; `composer analyse` → clean.

### Step 4: `Keyword_Repository` — tests first

Cases:
1. `all()` when the option is absent → returns `[]`.
2. `all()` hydrates `list<Keyword>` from stored entries; missing `max` defaults to 1; `variants` missing → `[]`.
3. `save( $keyword )` upserts: when an entry with the same `id` exists it is replaced; otherwise appended; then `update_option('kntnt_autolink_keywords', <list>, false)`.
4. `delete( $id )` removes the matching entry and persists.
5. `replace_all( $keywords )` overwrites the whole option with the serialised list.
6. Serialisation round-trips: a `Keyword` saved and re-read via `all()` is field-equal.

Sanitisation on save: `url` via `esc_url_raw` (stub it); `base`/`variants` via `sanitize_text_field`; `max` via `absint` (min 1 — `0`/absent → 1); `id` via `sanitize_key`, generating one when empty (use a deterministic scheme in tests, e.g. stub `wp_generate_uuid4` / use a passed id).

**Verify**: `vendor/bin/pest tests/Unit/Keyword_Repository_Test.php` → fails first.

### Step 5: `Keyword_Repository` — implement

Same structural pattern as `Settings_Repository`: `OPTION = 'kntnt_autolink_keywords'`; hydrate to/from `Keyword`; sanitise on the `save`/`replace_all` boundary. The store is a plain `list` of assoc arrays.

**Verify**: `vendor/bin/pest tests/Unit/Keyword_Repository_Test.php` → passes.

### Step 6: Full suite + static analysis

**Verify**: `composer test` → all pass; `composer analyse` → "No errors".

## Test plan

- `tests/Unit/Settings_Repository_Test.php` — defaults, merge, ruleset hydration (empty xpath → null), save+sanitise per field, non-autoload flag.
- `tests/Unit/Keyword_Repository_Test.php` — empty, hydrate, upsert, delete, replace_all, sanitise on save, round-trip.
- Model both after `tests/Unit/Ruleset_Test.php` for structure, plus Brain Monkey `Functions\when('get_option')->justReturn(...)` / `Functions\expect('update_option')->once()->with(...)`.
- Verification: `composer test` → all unit tests pass.

## Done criteria

- [ ] `composer test` exits 0; both repository suites exist and pass
- [ ] `composer analyse` exits 0, "No errors"
- [ ] `update_option` is always called with the third arg `false` (no autoload) — assert in tests
- [ ] `grep -n "esc_html\|esc_attr\|esc_url\b" classes/Settings_Repository.php classes/Keyword_Repository.php` → no *output*-escaping here (only `esc_url_raw` for storage in Keyword_Repository is allowed)
- [ ] `git status` shows only in-scope files + `tests/Pest.php`
- [ ] `plans/README.md` row for 004 updated

## STOP conditions

Stop and report if:
- A `Ruleset`/`Keyword` constructor argument does not exist as expected (Plan 002 drift).
- Brain Monkey cannot stub a needed function (e.g. version mismatch) — report; do not replace it with a hand-rolled global function override that leaks across tests.
- Sanitising `deny_xpath`/`allow_only_xpath` through any `esc_*`/`sanitize_text_field` corrupts valid XPath — report; XPath must be stored verbatim (admin-only, gated in Plan 007), only trimmed.

## Maintenance notes

- These repositories are the single source of truth for the two options. If a setting is added to the design, add it to `DEFAULTS`, the sanitiser, and the hydration in one place here.
- `autoload = no` is deliberate (design §2): the front end reads the whole set once per request and object cache handles repeats; never flip it to autoload.
- Output escaping is intentionally NOT done here — it happens at render (Plan 007 admin, Plan 003 engine via DOM). Keep storage and presentation concerns separate.
- `Keyword_Repository::save` upserts by `id`; the admin page (Plan 007) supplies stable ids. If ids ever collide, last-write-wins by design.
