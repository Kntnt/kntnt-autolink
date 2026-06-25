# Plan 003: The pure `Linker` engine (test-first)

> **Executor instructions**: Build this test-first. For every behaviour, write the failing Pest test, run it, watch it fail, then write the minimum code to pass. Run every verification command. If anything in "STOP conditions" occurs, stop and report. When done, update the status row in `plans/README.md`.
>
> **Drift check (run first)**: confirm Plans 001 and 002 are committed and green (`composer test` passes; `classes/Keyword.php` and `classes/Ruleset.php` exist). Open `classes/Ruleset.php` and confirm `eligible_text_nodes_query()` and `link_attributes()` match the signatures in Plan 002 §Step 4. If they differ, STOP — this plan is written against those exact signatures.

## Status

- **Priority**: P1
- **Effort**: L
- **Risk**: HIGH (this is the correctness core: word boundaries, UTF-8, DOM splitting, idempotence)
- **Depends on**: 002
- **Category**: correctness (foundation)
- **Planned at**: greenfield, written 2026-06-25 against `kntnt-autolink-design.md`

## Why this matters

`Linker` is the deep module the whole plugin exists to provide. Its one public method takes HTML + keywords + a `Ruleset` and returns linked HTML, making **zero WordPress calls** — so it is fully unit-testable and is where every matching bug the survey found (Interlinko's missing word boundaries that linked "page" inside "homepage") is prevented. Everything else in the plugin is plumbing around this. Correctness here is non-negotiable; it is also why the design insists on building it first and test-first (design §5, §13).

## Current state

After Plan 002 the repo has `Keyword` and `Ruleset` value objects and a green test suite. `Ruleset::eligible_text_nodes_query()` returns the composed XPath; `Ruleset::link_attributes()` returns `['class' => …, 'rel' => …?, 'target' => …?]` (no `href`). `Keyword::forms()` returns base + variants, de-duplicated.

**The algorithm to implement** (design §5, made fully explicit below — this is the authoritative spec for this plan):

1. **Pre-check.** Collect every surface form from every keyword (`Keyword::forms()`). If none appears in the raw `$html` via case-insensitive substring search (`stripos`), return `$html` unchanged. This keeps cost near zero on posts with no matches — no DOM parse.
2. **Parse.** Wrap the content in a single container element and load it:
   ```php
   $wrapped = '<div>' . $html . '</div>';
   $dom = new \DOMDocument();
   $previous = libxml_use_internal_errors( true );   // suppress HTML5-tag warnings
   $dom->loadHTML( '<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
   libxml_clear_errors();
   libxml_use_internal_errors( $previous );
   ```
   The `<?xml encoding="UTF-8">` prefix preserves UTF-8; `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD` stop libxml adding `<html>/<body>/<!DOCTYPE>`. **Do NOT** use `mb_convert_encoding(…, 'HTML-ENTITIES')` (deprecated since PHP 8.2). The container is the document's first element node (`$dom->documentElement` — but note the XML PI may be a sibling; locate the `div` reliably: it is the first child of `$dom` that is a `DOMElement`).
3. **Select** eligible text nodes via `$rules->eligible_text_nodes_query()` evaluated with `DOMXPath` against `$dom` (document scope; the query is absolute). Collect the matched nodes into a **plain PHP array** before mutating (a live `DOMNodeList` must not be iterated while you change the tree).
4. **Order keywords** longest-first: order the keyword groups by their longest surface form descending; within a group, try forms longest-first. This makes multi-word phrases and longer forms win over their parts.
5. **Match & insert**, walking the collected text nodes in document order. Track a per-keyword remaining count (starts at `Keyword::max`) and a running total; stop entirely when total reaches `$rules->max_links_per_post`. For each text node, find and link matches within it (details in Step 4's algorithm), splitting the node and inserting `<a …>matched text</a>`. New text fragments created by a split are processed inline within the same node (so multiple links per node work) but are NOT re-added to the candidate array (so the engine never links inside a link it just made — idempotence).
6. **Serialise** by concatenating `$dom->saveHTML( $child )` over the container's child nodes; return that string.

**The literal match pattern** for a form: `'/(?<!\p{L})' . preg_quote( $form, '/' ) . '(?!\p{L})/iu'`. The `u` flag enables Unicode; `\p{L}` lookarounds are the word boundaries; `i` is case-insensitive. With real word boundaries, a variant can never match inside a longer word, so variants are purely additive.

**Conventions**: `declare( strict_types = 1 )`; tabs; padded parens; `Pascal_Snake_Case`; `[ ... ]`; PHPDoc `@since 1.0.0`; no WordPress calls anywhere in this file.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| This suite | `vendor/bin/pest tests/Unit/Linker_Test.php` | passes |
| All unit tests | `composer test` | all pass |
| Static analysis | `composer analyse` | "No errors" |
| Syntax lint | `php -l classes/Linker.php` | "No syntax errors detected" |

## Scope

**In scope** (create):
- `classes/Linker.php`
- `tests/Unit/Linker_Test.php`

**Out of scope** (do NOT touch):
- `classes/Ruleset.php`, `classes/Keyword.php` — consume them; do not modify. If you believe a value object needs a new method, STOP and report rather than editing it (it would invalidate Plan 002's contract tests).
- Any WordPress glue. The `kntnt_autolink_link_attributes` filter is applied by the **caller** (Plan 006) via the optional callback parameter defined below — do NOT call `apply_filters` here.

## Public contract (the deep interface — design as if unchangeable)

```php
public function link(
	string $html,
	array $keywords,                 // list<Keyword>
	Ruleset $rules,
	?callable $attribute_filter = null   // fn( array $attrs, array $context ): array
): string
```

- Returns the input unchanged when no keyword form is present (pre-check) or `$keywords` is empty.
- `$attribute_filter`, when provided, is called once per generated link with `( array $attributes, array $context )` and must return the (possibly modified) attributes. `$context` keys: `'url'`, `'keyword_id'`, `'base'`, `'matched_text'`. This is the seam the `kntnt_autolink_link_attributes` filter (and a future hovercard) hook into, kept WordPress-free here.
- The generated anchor's attributes are `$rules->link_attributes()` plus `'href' => $keyword->url`, then passed through `$attribute_filter` if set.

## Git workflow

- Branch `advisor/003-linker-engine`. Commit per behaviour group (boundaries, skip rules, caps, UTF-8, idempotence). Do not push.

## Steps

### Step 1: Write `tests/Unit/Linker_Test.php` first — the behaviour matrix

Each row is one `it(...)` test: build a `Ruleset` (use the design defaults from Plan 002 §Step 3, overriding per case), a `list<Keyword>`, call `(new Linker())->link( $html, $keywords, $rules )`, assert on the returned HTML. Prefer asserting with `toContain(...)` / `not->toContain(...)` on the anchor and on the absence of wrong links, plus exact-equality assertions where the whole output is small.

Helper for terse keywords in the test file:

```php
function kw( string $base, array $variants = [], string $url = 'https://example.com/', int $max = 1 ): \Kntnt\Autolink\Keyword {
	return new \Kntnt\Autolink\Keyword( id: $base, base: $base, variants: $variants, url: $url, max: $max );
}
```

Behaviours to cover (write them as failing tests first):

1. **Happy path**: `link( '<p>I love cats.</p>', [ kw('cats') ], $defaults )` → contains `<a class="kntnt-autolink" href="https://example.com/">cats</a>`, and the surrounding `<p>…</p>` survives.
2. **Word boundary — no partial match**: keyword `page`, html `<p>Visit the homepage today.</p>` → output **equals** input (no `<a>` inserted); `page` must NOT link inside `homepage`. This is the headline correctness case.
3. **Case-insensitive**: keyword `Cat`, html `<p>A CAT and a cat.</p>` → first occurrence (`CAT`) is linked, anchor text preserves the matched casing `CAT`; the second `cat` is NOT linked (default `max` = 1).
4. **Skip in headings via ancestor walk**: keyword `cat`, html `<h2>A <em>cat</em></h2><p>a cat</p>` → the `<em>cat</em>` inside `<h2>` is NOT linked (h2 is a deny tag, ancestor walk catches it through `<em>`); the `<p>` `cat` IS linked.
5. **`.no-autolink` class**: keyword `cat`, html `<p class="no-autolink">cat</p><p>cat</p>` → first `<p>` is skipped, second `<p>` linked. Also assert `no-autolink-foo` does NOT skip: html `<p class="no-autolink-foo">cat</p>` → linked (class-token safety).
6. **Allow-only XPath (include-only)**: `Ruleset` with `allow_only_xpath: '//main'`, keyword `cat`, html `<aside>cat</aside><main>cat</main>` → only the `<main>` occurrence is linked.
7. **Longest-first**: keywords `kw('machine learning')` and `kw('learning')` (note: pre-check and ordering by longest form). html `<p>machine learning rocks</p>` → `machine learning` is linked as one anchor; `learning` is NOT separately linked inside it.
8. **First occurrence + per-keyword max**: keyword `cat` with default `max` = 1, html `<p>cat cat cat</p>` → exactly one `<a>`; with `kw('cat', max: 2)` → exactly two `<a>`.
9. **Global cap**: `Ruleset` with `max_links_per_post: 2`, keywords `kw('a', max:5)`, `kw('b', max:5)`, `kw('c', max:5)`, html `<p>a b c</p>` → exactly 2 anchors total (a and b linked, c not), honouring document order.
10. **UTF-8 integrity**: keyword `kaffé`, html `<p>Ett kafé och en kaffé.</p>` → the `kaffé` occurrence linked, multibyte intact in output (assert output contains `kaffé` and `kafé` unbroken, no mojibake/entities for the accented chars). Also a case with an emoji or `å/ä/ö` in surrounding text to confirm no corruption.
11. **Idempotence / no nested links**: running `link()` on output that already contains `<a class="kntnt-autolink" href="…">cat</a>` does NOT wrap a new link inside it (the existing `<a>` is a deny tag). Assert running twice equals running once.
12. **Pre-check short-circuit**: keyword `zebra`, html `<p>no match here</p>` → returns input unchanged (and, since this is hard to assert directly, at least assert output **equals** input byte-for-byte).
13. **Attribute filter callback**: pass `$attribute_filter = fn( $attrs, $ctx ) => [ ...$attrs, 'data-id' => $ctx['keyword_id'] ]`; assert the anchor gains `data-id="cat"` and `$ctx['matched_text']`/`$ctx['url']` are correct (assert via the produced attribute).
14. **Empty keywords**: `link( '<p>cat</p>', [], $defaults )` → returns input unchanged.

Run the suite; all should fail (no `Linker` class).

**Verify**: `vendor/bin/pest tests/Unit/Linker_Test.php` → fails, `Kntnt\Autolink\Linker` undefined.

### Step 2: Implement `classes/Linker.php` — parsing, selection, serialisation

Write the skeleton with the pre-check, parse, XPath selection, and serialise (no insertion yet) and get the pre-check / empty-keyword / no-match tests (12, 14, 2 when no match) green first. Reference shape:

```php
<?php
/**
 * The pure autolinking engine. Given HTML, a keyword set, and a Ruleset, returns
 * the HTML with the first eligible occurrences linked. Makes no WordPress calls.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Linker {

	/**
	 * Links eligible keyword occurrences in the given HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $html             Content HTML (a fragment, not a full document).
	 * @param list<Keyword> $keywords         Keyword groups to link.
	 * @param Ruleset       $rules            Eligibility rules and link policy.
	 * @param callable|null $attribute_filter fn( array $attrs, array $context ): array, applied per link.
	 * @return string The linked HTML, or the input unchanged when nothing matches.
	 */
	public function link( string $html, array $keywords, Ruleset $rules, ?callable $attribute_filter = null ): string {

		// Cheap pre-check: bail before any DOM work when no surface form is present.
		$forms = [];
		foreach ( $keywords as $keyword ) {
			$forms = [ ...$forms, ...$keyword->forms() ];
		}
		if ( $forms === [] || ! $this->contains_any( $html, $forms ) ) {
			return $html;
		}

		// Parse the fragment into a DOM without libxml injecting html/body/doctype.
		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . '<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		// Locate the wrapper div (first element child; an XML PI may precede it).
		$container = null;
		foreach ( $dom->childNodes as $node ) {
			if ( $node instanceof \DOMElement ) {
				$container = $node;
				break;
			}
		}
		if ( $container === null ) {
			return $html;   // parse produced no element; leave content untouched
		}

		// Collect eligible text nodes into an array before mutating the tree.
		$xpath = new \DOMXPath( $dom );
		$found = $xpath->query( $rules->eligible_text_nodes_query() );
		$candidates = $found === false ? [] : iterator_to_array( $found );

		// Insert links (Step 3 fills this in).
		$this->insert_links( $dom, $candidates, $keywords, $rules, $attribute_filter );

		// Serialise the container's children back to an HTML fragment.
		$out = '';
		foreach ( $container->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		return $out;

	}

	/**
	 * True when any of the forms appears in the haystack, case-insensitively.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $forms
	 */
	private function contains_any( string $haystack, array $forms ): bool {
		foreach ( $forms as $form ) {
			if ( $form !== '' && stripos( $haystack, $form ) !== false ) {
				return true;
			}
		}
		return false;
	}

	// insert_links(): see Step 3.

}
```

A caution to verify by experiment: with `LIBXML_HTML_NOIMPLIED`, libxml on some builds still requires the content to have a single root — that is why we wrap in `<div>`. Confirm the serialise step returns the inner HTML *without* the wrapping `<div>` (it iterates the container's children, not the container). If your build emits the `<?xml … ?>` PI into output, strip it: it should be a child of `$dom`, not of `$container`, so iterating `$container->childNodes` already excludes it — verify with test 1.

**Verify**: tests 12, 14, and the no-`<a>` part of 2 pass; others still fail (no insertion yet).

### Step 3: Implement `insert_links()` — the node-outer matching algorithm

This is the heart. Implement exactly this algorithm (it is correct for longest-first, first-occurrence, caps, and idempotence, in a single pass over the candidate array):

```
precompute groups: for each Keyword, build [ id, base, url, max, forms (longest-first), remaining = max ]
sort groups by their longest form length, descending
total = 0

for each $textNode in $candidates (document order):
    if total >= rules->max_links_per_post: break
    if $textNode is no longer in the tree (parentNode === null): continue   // defensive: a prior op detached it
    process_text_node($textNode):
        work on the node's text from left to right:
        loop:
            among all groups with remaining > 0, find the earliest regex match
            (lowest byte offset) in the current text; on a tie at the same offset,
            prefer the longer matched length (longest-first). Use the pattern
            '/(?<!\p{L})' . preg_quote($form,'/') . '(?!\p{L})/iu' and try each
            group's forms longest-first, taking that group's first match position.
            if no match, or total >= max_links_per_post: stop processing this node.
            else:
                split: replace the matched run with [before-text-node, <a>, after-text-node-as-the-new-current-text]
                build <a>: attributes = rules->link_attributes() + ['href' => group.url];
                          if $attribute_filter: attributes = $attribute_filter(attributes,
                              ['url'=>group.url,'keyword_id'=>group.id,'base'=>group.base,'matched_text'=>matched]);
                          create element 'a', setAttribute each, appendChild a text node with the matched text.
                insert before-text (if non-empty), then <a>, then continue the loop on the after-text.
                group.remaining--; total++
```

Concrete DOM mechanics for one match inside a `DOMText $node` whose value is `$before . $matched . $after`:

```php
$parent = $node->parentNode;

// Anchor element with the matched text as its only child.
$anchor = $dom->createElement( 'a' );
foreach ( $attributes as $name => $value ) {
	$anchor->setAttribute( $name, $value );
}
$anchor->appendChild( $dom->createTextNode( $matched ) );

// Replace the original node with: before-text, anchor, after-text — in order.
if ( $before !== '' ) {
	$parent->insertBefore( $dom->createTextNode( $before ), $node );
}
$parent->insertBefore( $anchor, $node );
$afterNode = $dom->createTextNode( $after );
$parent->insertBefore( $afterNode, $node );
$parent->removeChild( $node );

// Continue scanning $after for more matches by repeating on $afterNode.
```

Important correctness notes — encode these as you implement, and they are why the matrix tests must pass:

- **Offsets**: compute match offsets on the *current remaining text* (the `$after` portion) each iteration, not on the original whole node, so positions stay valid after each split.
- **`preg_match` with `/u`** returns byte offsets via `PREG_OFFSET_CAPTURE`; `substr` on byte offsets is correct for splitting because UTF-8 is byte-safe for `substr` at match boundaries (matches never split a codepoint). The matched substring is taken with `substr( $text, $offset, $length )` where `$length = strlen( $matchedBytes )`.
- **Anchor text casing**: insert the *matched* text (`$matched`), preserving the document's original casing, NOT the keyword's `base`.
- **No re-scan of new fragments across nodes**: the `$after` text node you keep scanning is the only new node you revisit, and only within this node's loop. You never add it to `$candidates`. The `<a>` you created is a deny tag (`a`) so even if it were re-queried it would be excluded — this is the idempotence guarantee. Do not requery the XPath after mutation.
- **`remaining` and `total`** are shared across all text nodes (per-post first-occurrence and the global cap span the whole document).

Now make the rest of the matrix pass.

**Verify**: `vendor/bin/pest tests/Unit/Linker_Test.php` → all 14 behaviours pass.

### Step 4: Full suite + static analysis

**Verify**: `composer test` → all pass; `composer analyse` → "No errors". `php -l classes/Linker.php` → clean.

## Test plan

- `tests/Unit/Linker_Test.php` — the 14-row behaviour matrix in Step 1. Model the file after `tests/Unit/Ruleset_Test.php` (Pest `it(...)`), with the `kw()` helper.
- The matrix deliberately includes the two highest-risk correctness cases — word boundaries (test 2) and idempotence (test 11) — and the UTF-8 integrity case (test 10). Do not delete or weaken these.
- Verification: `composer test` → all unit tests pass (smoke + Keyword + Ruleset + 14 Linker).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer test` exits 0; `Linker_Test` exists with all 14 behaviours passing
- [ ] `composer analyse` exits 0, "No errors"
- [ ] `grep -nE "get_option|apply_filters|esc_|sanitize_|wp_|__\(" classes/Linker.php` returns nothing (no WordPress calls)
- [ ] `git status` shows only `classes/Linker.php` and `tests/Unit/Linker_Test.php` changed
- [ ] `plans/README.md` status row for 003 updated

## STOP conditions

Stop and report back (do not improvise) if:

- `DOMDocument::loadHTML` on this PHP build does NOT honour `LIBXML_HTML_NOIMPLIED` (output gains `<html>`/`<body>` wrappers you cannot strip by iterating the container's children) — report; the parse strategy may need the documented fallback of locating `//body` or the wrapper differently. Do not switch to the deprecated `mb_convert_encoding(…, 'HTML-ENTITIES')`.
- A UTF-8 test shows mojibake or HTML entities in place of accented characters (test 10) — the encoding prefix or serialisation is wrong; report rather than papering over with `html_entity_decode`.
- The longest-first guarantee (test 7) cannot be satisfied with the node-outer algorithm as written — report with the failing input; do not silently switch to a per-keyword re-query loop (it would break the "single pass / no write on read path" performance intent and idempotence reasoning).
- You find you must modify `Ruleset` or `Keyword` to make a test pass.

## Maintenance notes

- This engine is performance-critical (it runs on every render — design §1, §5). The `stripos` pre-check is the guard that keeps no-match posts cheap; never move DOM parsing before it.
- If a future change adds nested-link use cases (e.g. the hovercard), the idempotence invariant — "never link inside an `<a>`" — must hold; `a` being a default deny tag is what enforces it. A reviewer should reject any change that lets the engine re-scan freshly inserted anchors.
- The byte-offset splitting relies on `preg` `/u` matches never bisecting a codepoint; keep the `u` flag on every pattern.
- The `$attribute_filter` callback is the only extension seam; the WordPress `apply_filters` wrapper lives in `Content_Filter` (Plan 006), which keeps this class pure and unit-testable. Do not inline WordPress here.
