# Plan 002: The `Keyword` and `Ruleset` value objects (test-first)

> **Executor instructions**: Follow this plan step by step, test-first (write the failing test, see it fail, then write the code that passes it). Run every verification command and confirm the expected result before moving on. If anything in "STOP conditions" occurs, stop and report. When done, update the status row for this plan in `plans/README.md`.
>
> **Drift check (run first)**: `git rev-parse --short HEAD` and confirm Plan 001 is committed (`composer test` exists and passes). If `classes/Keyword.php` or `classes/Ruleset.php` already exist, compare them against the contracts below; on any mismatch, STOP and report.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (the XPath compilation is subtle — word-boundary and class-token correctness live here and in Plan 003)
- **Depends on**: 001
- **Category**: tech-debt (foundation)
- **Planned at**: greenfield, written 2026-06-25 against the design `kntnt-autolink-design.md`

## Why this matters

These two value objects are the WordPress-free core the whole design hinges on. `Keyword` is the unit of "what to link"; `Ruleset` is the unit of "where linking is allowed", and its single job — compiling a friendly deny-tag list + skip-class + raw XPath escape hatches into one XPath that selects exactly the eligible text nodes — is the design's central engineering idea (design §4). Getting the XPath right here (ancestor-walk, class-token safety) is what makes the `Linker` (Plan 003) correct. Both are pure: no WordPress calls, fully unit-testable with Pest given inputs → outputs.

## Current state

After Plan 001 the repo has the skeleton, `composer test`, and `composer analyse`. No domain classes yet.

Relevant design sections, inlined so you need not open the design doc:

- **Keyword entry** (design §3): `{ id, base, variants[], url, max? }`. `base` is the canonical display form; `variants` are additional equal-weight literal surface forms; all point to the same `url`. `max` defaults to 1.
- **Ruleset / eligibility** (design §4): one XPath yields the eligible text nodes. Three friendly front-ends + one escape hatch compile into it:
  - **Deny tag list** → a text node is ineligible if it has an `ancestor-or-self` of any denied tag. The *ancestor walk* (not just direct parent) is essential: a keyword inside `<h2><em>…</em></h2>` must still be skipped. Default deny tags: `h1,h2,h3,h4,h5,h6,a,code,pre,script,style`.
  - **`.no-autolink` class** → ineligible if any `ancestor-or-self` carries the class. Use the class-token-safe test `contains( concat( ' ', normalize-space( @class ), ' ' ), ' no-autolink ' )` so that `no-autolink-foo` does NOT match. Default class name: `no-autolink`.
  - **Raw deny-XPath** (optional) → an additional exclusion: a text node is ineligible if it has an `ancestor-or-self` that is in the node-set the deny-XPath selects.
  - **Allow-only raw XPath** (optional, include-only) → when set, the candidate set is `(<allow_only_xpath>)//text()` instead of `//text()`; the deny predicate is then applied on top.
- **Link attributes** (design §5/§9): generated `<a>` carries `class` = `link_class` (default `kntnt-autolink`); `rel` built from `nofollow`/`new_tab` (`nofollow` when nofollow on, `noopener` when opening a new tab); `target="_blank"` when `new_tab`. `href` is per-keyword and added by the `Linker`, not by `Ruleset`.

**Conventions** (from `agents.d/coding-standard/`): `declare( strict_types = 1 )`; tabs; padded parens; `Pascal_Snake_Case` class names; `[ ... ]` arrays; trailing commas; PHPDoc with `@since 1.0.0`; `readonly` value objects with constructor promotion. Modern PHP 8.4.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Unit tests | `composer test` | all pass |
| One file | `vendor/bin/pest tests/Unit/Ruleset_Test.php` | that suite passes |
| Static analysis | `composer analyse` | "No errors" |
| Syntax lint | `php -l classes/Ruleset.php` | "No syntax errors detected" |

## Scope

**In scope** (create):
- `classes/Keyword.php`
- `classes/Ruleset.php`
- `tests/Unit/Keyword_Test.php`
- `tests/Unit/Ruleset_Test.php`

**Out of scope** (do NOT touch):
- `classes/Linker.php` — Plan 003 consumes these objects; do not write it here.
- Any repository, WordPress glue, or admin code.
- Do NOT add WordPress function calls to either class. They must be testable with zero WordPress bootstrap. (If you find yourself wanting `sanitize_*`/`esc_*` here, stop — sanitisation belongs in the repositories/admin, Plans 004/007.)

## Git workflow

- Branch `advisor/002-value-objects` off the Plan 001 commit.
- Commit test-first: one commit for `Keyword`, one for `Ruleset`, or per logical unit. Do not push.

## Steps

### Step 1: `Keyword` — write `tests/Unit/Keyword_Test.php` first

Cases to assert:
- Constructs with `id, base, variants, url, max` and exposes them as readonly public properties.
- `max` defaults to `1` when omitted.
- `forms()` returns base + variants as a de-duplicated list, base first. Given `base = 'cat'`, `variants = ['cats', 'cat']` → `forms()` === `['cat', 'cats']` (the duplicate `cat` collapses, order preserved).

Run it; see it fail (class does not exist).

**Verify**: `vendor/bin/pest tests/Unit/Keyword_Test.php` → fails because `Kntnt\Autolink\Keyword` is undefined.

### Step 2: `Keyword` — write `classes/Keyword.php` to pass

Target shape:

```php
<?php
/**
 * A keyword group: one canonical base form plus equal-weight variant surface
 * forms, all linking to the same URL.
 *
 * Pure value object — no WordPress calls. Sanitisation of url/forms happens at
 * the repository/admin boundary (Plans 004/007), never here.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final readonly class Keyword {

	/**
	 * @since 1.0.0
	 *
	 * @param string        $id       Stable identifier.
	 * @param string        $base     Canonical surface form (display/management).
	 * @param list<string>  $variants Additional literal surface forms.
	 * @param string        $url      Destination all forms link to.
	 * @param int           $max      Per-keyword link cap. Default 1.
	 */
	public function __construct(
		public string $id,
		public string $base,
		public array $variants,
		public string $url,
		public int $max = 1,
	) {}

	/**
	 * All surface forms, base first, de-duplicated, preserving order.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function forms(): array {
		return array_values( array_unique( [ $this->base, ...$this->variants ] ) );
	}

}
```

**Verify**: `vendor/bin/pest tests/Unit/Keyword_Test.php` → passes; `composer analyse` → no new errors.

### Step 3: `Ruleset` — write `tests/Unit/Ruleset_Test.php` first

`Ruleset` has two compiled outputs to test: `eligible_text_nodes_query(): string` and `link_attributes(): array`. Assert the produced strings/arrays **exactly** (these are the contract Plan 003 depends on).

Construct a `Ruleset` with the design defaults:

```php
$rules = new \Kntnt\Autolink\Ruleset(
	deny_tags: [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'code', 'pre', 'script', 'style' ],
	skip_class: 'no-autolink',
	deny_xpath: null,
	allow_only_xpath: null,
	link_class: 'kntnt-autolink',
	nofollow: false,
	new_tab: false,
	max_links_per_post: 10,
);
```

**XPath cases** — assert `eligible_text_nodes_query()` returns exactly the strings below. (Build the expected string in the test the same way the class does, OR hard-code it; hard-coding is preferred so the test pins the contract.)

1. **Defaults, no allow-only, no deny-xpath** → candidate is the whole document; deny predicate excludes denied-tag ancestors and the skip class:

   ```
   //text()[not(ancestor-or-self::h1 or ancestor-or-self::h2 or ancestor-or-self::h3 or ancestor-or-self::h4 or ancestor-or-self::h5 or ancestor-or-self::h6 or ancestor-or-self::a or ancestor-or-self::code or ancestor-or-self::pre or ancestor-or-self::script or ancestor-or-self::style) and not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' no-autolink ')])]
   ```

2. **`allow_only_xpath = "//main"`** (others default) → candidate becomes `(//main)//text()`:

   ```
   (//main)//text()[not(ancestor-or-self::h1 or … or ancestor-or-self::style) and not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' no-autolink ')])]
   ```
   (the predicate is identical to case 1; only the candidate prefix changes.)

3. **`deny_xpath = "//figure"`** (allow-only null) → a third `and` clause is appended to the predicate, using the XPath-1.0 set-membership idiom so any ancestor that is one of the deny-xpath nodes makes the text node ineligible:

   ```
   //text()[not(ancestor-or-self::h1 or … or ancestor-or-self::style) and not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' no-autolink ')]) and not(ancestor-or-self::*[count(. | (//figure)) = count((//figure))])]
   ```

4. **`skip_class = 'noindex'`** → the class token in the predicate is `' noindex '`, not `' no-autolink '`.

5. **Empty `deny_tags = []`** → the first `not( … )` tag clause is omitted entirely; the predicate begins with the skip-class clause: `//text()[not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' noindex ')])]` (with whatever skip class). I.e. do not emit an empty `not()`.

**Link-attribute cases** — assert `link_attributes()` returns exactly:

6. Defaults (`nofollow: false, new_tab: false`) → `[ 'class' => 'kntnt-autolink' ]` (no `rel`, no `target` keys).
7. `nofollow: true, new_tab: false` → `[ 'class' => 'kntnt-autolink', 'rel' => 'nofollow' ]`.
8. `nofollow: false, new_tab: true` → `[ 'class' => 'kntnt-autolink', 'rel' => 'noopener', 'target' => '_blank' ]`.
9. `nofollow: true, new_tab: true` → `[ 'class' => 'kntnt-autolink', 'rel' => 'nofollow noopener', 'target' => '_blank' ]`.

Run the suite; see it fail (class undefined).

**Verify**: `vendor/bin/pest tests/Unit/Ruleset_Test.php` → fails (undefined `Kntnt\Autolink\Ruleset`).

### Step 4: `Ruleset` — write `classes/Ruleset.php` to pass

Target shape (the two methods are the contract; match the strings in Step 3 exactly):

```php
<?php
/**
 * Where links may go. Compiles a friendly deny-tag list, a skip class, and two
 * raw-XPath escape hatches (deny and allow-only) into a single XPath selecting
 * the eligible text nodes, and builds the static <a> attributes.
 *
 * Pure value object — no WordPress calls.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final readonly class Ruleset {

	/**
	 * @since 1.0.0
	 *
	 * @param list<string> $deny_tags          Tags whose subtree is never linked.
	 * @param string       $skip_class         Class that silences an element and its descendants.
	 * @param string|null  $deny_xpath         Optional raw XPath; ancestors in its node-set are excluded.
	 * @param string|null  $allow_only_xpath   Optional raw XPath; when set, only its subtree is eligible.
	 * @param string       $link_class         Class applied to generated links.
	 * @param bool         $nofollow           Add rel="nofollow".
	 * @param bool         $new_tab            Open in a new tab (target=_blank, rel adds noopener).
	 * @param int          $max_links_per_post Global cap on total links per post.
	 */
	public function __construct(
		public array $deny_tags,
		public string $skip_class,
		public ?string $deny_xpath,
		public ?string $allow_only_xpath,
		public string $link_class,
		public bool $nofollow,
		public bool $new_tab,
		public int $max_links_per_post,
	) {}

	/**
	 * The XPath selecting eligible text nodes (design §4).
	 *
	 * Candidate set is the whole document, or the allow-only subtree when set;
	 * the deny predicate then removes text nodes whose ancestor-or-self is a
	 * denied tag, carries the skip class, or is in the deny-XPath node-set.
	 *
	 * @since 1.0.0
	 */
	public function eligible_text_nodes_query(): string {

		// Candidate text nodes: whole document, or restricted to the allow-only subtree.
		$candidate = $this->allow_only_xpath !== null
			? '(' . $this->allow_only_xpath . ')//text()'
			: '//text()';

		// Build the exclusion clauses; only non-empty ones join with " and ".
		$clauses = [];

		if ( $this->deny_tags !== [] ) {
			$tags = implode( ' or ', array_map(
				static fn ( string $tag ): string => 'ancestor-or-self::' . $tag,
				$this->deny_tags,
			) );
			$clauses[] = 'not(' . $tags . ')';
		}

		$clauses[] = "not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' " . $this->skip_class . " ')])";

		if ( $this->deny_xpath !== null ) {
			$clauses[] = 'not(ancestor-or-self::*[count(. | (' . $this->deny_xpath . ')) = count((' . $this->deny_xpath . '))])';
		}

		return $candidate . '[' . implode( ' and ', $clauses ) . ']';

	}

	/**
	 * The static attributes for a generated <a>, before href and the
	 * per-match attribute filter are applied (Plan 003 / Plan 006).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public function link_attributes(): array {

		// Class is always present; rel/target depend on the link policy.
		$attributes = [ 'class' => $this->link_class ];

		$rel = [];
		if ( $this->nofollow ) {
			$rel[] = 'nofollow';
		}
		if ( $this->new_tab ) {
			$rel[] = 'noopener';
		}
		if ( $rel !== [] ) {
			$attributes['rel'] = implode( ' ', $rel );
		}
		if ( $this->new_tab ) {
			$attributes['target'] = '_blank';
		}

		return $attributes;

	}

}
```

**Verify**: `vendor/bin/pest tests/Unit/Ruleset_Test.php` → all 9 cases pass; `composer analyse` → no errors.

### Step 5: Full suite + static analysis green

**Verify**: `composer test` → all pass (smoke + Keyword + Ruleset); `composer analyse` → "No errors".

## Test plan

- `tests/Unit/Keyword_Test.php`: construction, `max` default, `forms()` de-dup/order (3 cases).
- `tests/Unit/Ruleset_Test.php`: the 5 XPath cases (defaults, allow-only, deny-xpath, custom skip class, empty deny-tags) and the 4 link-attribute cases — model the file structure after `tests/Unit/Smoke_Test.php` (Pest `it(...)` style).
- The XPath strings are asserted **verbatim** so Plan 003 can rely on the exact shape. Do not assert "contains" — assert equality.
- Verification: `composer test` → 14 passing tests total (2 smoke + 3 + 9).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer test` exits 0; `Keyword_Test` and `Ruleset_Test` exist and pass
- [ ] `composer analyse` exits 0, "No errors"
- [ ] `grep -rn "function\|sanitize_\|esc_\|apply_filters\|get_option" classes/Keyword.php classes/Ruleset.php` shows NO WordPress function calls (only the classes' own methods)
- [ ] `git status` shows only the four in-scope files changed
- [ ] `plans/README.md` status row for 002 updated

## STOP conditions

Stop and report back (do not improvise) if:

- A produced XPath string cannot be made to match the Step 3 expected string AND you believe the expected string is wrong — report the discrepancy with your reasoning rather than "fixing" the contract, because Plan 003 and its tests are written against these exact strings.
- PHPStan flags a type problem you cannot resolve without adding a WordPress call or weakening a type (do not weaken `list<string>` to `array`).
- The XPath-1.0 set-membership idiom in case 3 fails its assertion — report; that clause is the riskiest part.

## Maintenance notes

- The exact XPath produced here is a contract consumed by `Linker` (Plan 003) and exercised end-to-end in Plan 008. If you ever change the predicate shape, re-run Plans 003 and 008's suites.
- The skip-class test is deliberately the class-token-safe `concat`/`contains` form — a reviewer must reject any "simplification" to `contains(@class, 'no-autolink')`, which would wrongly match `no-autolink-foo`.
- Raw `deny_xpath` / `allow_only_xpath` are admin-only power features (Plan 007 gates them to `manage_options`). They are interpolated into the query verbatim by design (they are XPath, authored by an admin). The admin form (Plan 007) is responsible for not exposing them to lower-privileged users; this class trusts them.
- `link_attributes()` intentionally omits `href` — the per-keyword URL is added by the `Linker`. Keep it that way; a `Ruleset` does not know any single keyword's URL.
