# Design & Handoff — Kntnt Autolink (working name)

A self-contained design specification for a new WordPress plugin that performs rule-based keyword→URL autolinking. This document is written to be read cold: a fresh session with no prior context should be able to read it top to bottom and start building immediately. It was produced in a design ("grilling") session on 2026-06-25 with Thomas (Kntnt).

## 0. How to use this document

You are picking this up with an empty context window. Do this in order:

1. Read this whole document — it is the complete design. Every decision below is settled unless §12 marks it open.
2. The author is Thomas (Kntnt); apply his coding standard. Invoke the `kntnt-code-skills:coding-standard` skill to materialise the standard into the new project (creates `agents.d/coding-standard/`, `AGENTS.md`, the `CLAUDE.md` bridge). The standard governs everything; §13 lists the points that bite hardest here.
3. Resolve the open items in §12 (the project name is blocking — it drives all namespacing).
4. Build test-first (`tdd` skill). Start with the pure `Linker` engine (§5), which has zero WordPress dependencies and is fully unit-testable. Then the WordPress glue, then the admin UI.

## 1. Background & motivation

The trigger: Slim SEO Pro's Autolink module hard-codes which tags it skips and exposes no filter or action, so there is no way to stop it linking inside headings, to exclude a specific paragraph, or to restrict linking to a chosen region. A survey of free alternatives (Interlinko, DAEXT Autolinks Manager, Pagup Automatic Internal Links) found none that meets the bar: no plugin offers "link only within selector X" (include-only); several are freemium or option-heavy; and Interlinko — the most promising — has a real matching bug (its keyword regex has no word boundaries, so "page" links inside "homepage") on top of a 2 300-line God-object architecture.

Decision: build a small, owned plugin instead. Goals, in priority order: high code quality (deep modules, `strict_types`, SOLID, testable), no bloat (YAGNI/KISS), free, and fully owned so it never hits the "no hooks" wall that started this. Interlinko's one good idea — DOM parse + skip-tags + ancestor-walk — is worth reusing; everything else is rebuilt.

## 2. Locked decisions

| Dimension | Decision |
|---|---|
| Role | Standalone plugin that **replaces** Slim SEO Pro's Autolink module. Disable SSP's Autolink module on deployment; keep the rest of Slim SEO Pro (Schema etc.). |
| Storage | **Two non-autoloaded `wp_option`s** — one for settings, one for the keyword list. Read once per request. No DB table, no migrations: the front-end always needs the whole keyword set, so a table would only be `SELECT *` with extra code. |
| Keyword entry | `{ base form, variants[], url, max? }`. The base form is canonical (display/management); variants are equal-weight surface forms. All point to the same URL. |
| Matching | Exact **literal** match (no regex authored by the user). Unicode word boundaries `(?<!\p{L})…(?!\p{L})` with the `u` flag. Case-insensitive. **No inflection engine** — Swedish plurals are irregular and cannot be regex'd reliably; extra forms are entered manually as variants. |
| Rule model | **Global only** (no per-keyword rule level). |
| Where links may go | Deny = a configurable **tag list** (default `h1, h2, h3, h4, h5, h6, a, code, pre, script, style`) + the **`.no-autolink` class** (skip any element bearing it and its descendants) + an optional **raw deny-XPath**. Plus an optional **allow-only raw XPath** that restricts linking to within matching elements (this is the include-only feature). Plus post-type / taxonomy-term **targeting**. No CSS→XPath converter: the tag list and class are conveniences that compile to ancestor predicates; raw XPath is the power escape hatch. |
| Link policy | Link the **first occurrence** of each keyword per post; a **global per-post cap** on total links; **longest-first** ordering so multi-word phrases win over their parts. Optional per-keyword `max` (default 1). No min-distance. |
| Compute / cache | **Compute live on every render, made cheap by design.** A `stripos` pre-check over the raw content returns immediately when no keyword is present (no DOM parse). Precompiled matchers; a single XPath pass. **Never write on the read path** (Interlinko's `update_option`-per-view was its cardinal sin). No own cache; rely on normal page/object caching. An opt-in transient may be exposed behind a filter only if profiling ever shows a need. |
| Statistics | **None.** No stored stats, and no live preview tool either. |
| Generated link | `<a class="kntnt-autolink" href="…">`. Real and followable (`nofollow` off by default). The class is the identifying seam both SSP and Interlinko lacked — it makes auto-links styleable and addressable. |
| Public API | A small, deliberate set of ~5 filters (§7). No speculative hooks. |
| UI placement | Under **Tools**. |
| Capabilities | The keyword list is managed by **editor and above** (a custom capability, granted to roles holding `edit_others_posts`; capabilities, not roles). The structural rules (deny tags, allow-only XPath, link defaults) are reserved for **admin** (`manage_options`) as an admin-only section on the same page — so a non-technical editor cannot break matching with a bad XPath. |

## 3. Data model

Two options, both registered with `autoload = no` and read once per request (object-cache friendly).

**Settings** (`kntnt_<project>_settings`):

- `deny_tags` — array of tag names to skip. Default `['h1','h2','h3','h4','h5','h6','a','code','pre','script','style']`.
- `skip_class` — class that silences an element and its descendants. Default `'no-autolink'`.
- `deny_xpath` — optional raw XPath; text nodes inside matches are excluded.
- `allow_only_xpath` — optional raw XPath; when set, only text nodes inside matches are eligible (include-only).
- `link_class` — class applied to generated links. Default `'kntnt-autolink'`.
- `nofollow` — bool, default `false`.
- `new_tab` — bool, default `false`.
- `max_links_per_post` — int, the global cap. Default a sane small number (e.g. 10).
- `post_types` — array of post types the linker runs on. Default `['post','page']`.
- `terms` — optional taxonomy-term targeting.

**Keywords** (`kntnt_<project>_keywords`): an ordered array of entries, each:

- `id` — stable id.
- `base` — canonical surface form (display).
- `variants` — array of additional literal forms.
- `url` — destination.
- `max` — optional per-keyword cap (default 1).

## 4. The rule model in detail

There is one engine — an XPath query that yields the **eligible text nodes** — and three friendly front-ends plus one escape hatch that all compile into it:

- **Deny tag list** → an ancestor predicate, e.g. a text node is ineligible if it has an `ancestor-or-self::h1` … `ancestor-or-self::h6`, or `a`, `code`, `pre`, `script`, `style`. The ancestor walk (not just the direct parent) is essential — a keyword inside `<h2><em>…</em></h2>` must still be skipped.
- **`.no-autolink` class** → ineligible if any ancestor-or-self carries the class. Use the class-token-safe test `contains(concat(' ', normalize-space(@class), ' '), ' no-autolink ')` so `no-autolink-foo` does not match. This is how an editor silences a single paragraph or block.
- **Raw deny-XPath** → an additional exclusion the admin can add for exotic cases.
- **Allow-only raw XPath** (include-only) → when set, the candidate set is `(<allow_only_xpath>)//text()` instead of `//text()`; the deny predicate is then applied on top. This is the feature no free plugin offered: "link only within `.entry-content`" (or any XPath).

Targeting (post types / terms) is checked before the engine runs at all; if the current post is out of scope, the content filter returns untouched.

## 5. Matching engine (`Linker`) — algorithm

`Linker` is a **pure deep module**: its public method takes `(string $html, Keyword[] $keywords, Ruleset $rules)` and returns the linked HTML. It makes **no WordPress calls** — so it is unit-testable in isolation with Pest (given HTML + ruleset + keywords → expected HTML). All WordPress concerns live in the glue (§6).

Algorithm:

1. **Pre-check.** If none of the keyword surface forms appears in the raw HTML (`stripos`, case-insensitive), return the input unchanged. This keeps the cost near zero on posts with no matches.
2. **Parse.** Wrap the content in a container element, then `DOMDocument::loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)`. The XML-encoding prefix preserves UTF-8; the flags stop libxml adding `<html>/<body>/<!DOCTYPE>`. **Do not** use the deprecated `mb_convert_encoding(…, 'HTML-ENTITIES')` (deprecated since PHP 8.2) — extract output by serialising the container's child nodes instead.
3. **Select eligible text nodes** via the composed XPath (§4): candidate set (allow-only or whole document) minus the deny predicate.
4. **Order keywords** longest surface-form first (across base + variants, both between groups and within a group), so multi-word phrases and longer forms win. Note: with real word boundaries, "bevakningskamera" cannot match inside "bevakningskameror", so variants are purely additive and never double-match.
5. **Match & insert.** Walk eligible text nodes in document order. For each keyword group, find its first eligible occurrence using a literal pattern: `'/(?<!\p{L})' . preg_quote($form, '/') . '(?!\p{L})/iu'`. Split the text node and insert `<a class="…" href="…" [rel] [target]>matched text</a>`. Count it. Respect the per-keyword `max` (default 1 → first occurrence only) and stop entirely at the global `max_links_per_post`.
6. **Serialise** by concatenating `saveHTML()` over the container's children; return that.

Link attributes are built once per group: `rel` from `nofollow`/`new_tab` (`noopener` when opening a new tab), `target="_blank"` when `new_tab`, plus `link_class`. The final attribute array passes through the `…_link_attributes` filter (§7) so a match can be decorated programmatically.

## 6. Architecture & file structure

Per the WordPress section of Kntnt's standard. The decisive win over Interlinko: separation into deep modules, with the matching engine WordPress-free and therefore testable.

```
kntnt-<name>/
├── kntnt-<name>.php          ← header, PHP-version guard, autoloader, Plugin::get_instance()
├── autoloader.php            ← PSR-4 for \Kntnt\<Project>
├── install.php               ← grants the editor+ capability
├── uninstall.php             ← deletes the two options + the capability
├── README.md                 ← incl. the roadmap note in §11
├── CLAUDE.md                 ← @AGENTS.md bridge
├── AGENTS.md                 ← References → agents.d/
├── agents.d/coding-standard/ ← scaffolded by the coding-standard skill
├── classes/
│   ├── Plugin.php            ← singleton; wires components in dependency order; registers hooks
│   ├── Linker.php            ← PURE engine (no WP calls): link(html, Keyword[], Ruleset): html
│   ├── Ruleset.php           ← value object; compiles tag list + class + raw XPath into the eligibility query, holds link attrs + caps
│   ├── Keyword.php           ← value object: base, variants[], url, max?
│   ├── Settings_Repository.php   ← read/write the settings option
│   ├── Keyword_Repository.php    ← read/write the keywords option
│   ├── Content_Filter.php    ← hooks the_content; targeting + should_run; builds Ruleset; calls Linker; applies filters
│   ├── Capabilities.php      ← capability registration/mapping
│   └── Admin/Tools_Page.php  ← Tools page; keyword CRUD (editor+) + admin-only rules section
├── migrations/               ← empty until a real need
├── js/  css/  languages/
└── tests/
    ├── Unit/                 ← Pest: Linker (pure), Ruleset, value objects, repositories (Brain Monkey for WP funcs)
    └── Integration/          ← WordPress Playground: the_content end-to-end, capability gating
```

Bootstrap: `kntnt-<name>.php` → guard PHP version → require `autoloader.php` → register activation/deactivation → `Plugin::get_instance()`. The `Plugin` constructor instantiates components and registers their hooks.

## 7. Public API (hooks)

A small, deliberate set (prefix `kntnt_<project>_`), documented in README:

- `…_keywords` (array $keywords) → array. Filter the keyword set before matching. Lets code inject/override/remove entries (also serves anyone who prefers config-as-code).
- `…_deny` (array $deny, WP_Post $post) → array. Adjust the deny rules (tag list / XPath) per context.
- `…_allow_only` (string $xpath, WP_Post $post) → string. Set/override the include-only expression per context.
- `…_should_run` (bool $run, WP_Post $post) → bool. Short-circuit per request/post (e.g. disable on a template).
- `…_link_attributes` (array $attrs, array $context) → array. Filter the generated `<a>` attributes (rel, class, target, data-*) per match. This is also the seam the future hovercard hooks into.

A `…_content_priority` filter (default a late-ish priority, after `wpautop`/shortcodes) may expose the `the_content` priority.

## 8. Admin UI & capabilities

One page under **Tools**. Two areas:

- **Keywords** — CRUD over the keyword list (base form + a separate field for variants + URL + optional max). Gated by the custom editor+ capability.
- **Settings / rules** — deny tags, `skip_class`, raw deny-XPath, allow-only XPath, link defaults (`nofollow`, `new_tab`, `link_class`), global cap, targeting. Rendered only for `manage_options`.

`install.php` grants the custom capability to roles that have `edit_others_posts` (editor, admin). All output escaped at the point of output; all input sanitised; nonces on every form; capability checks in the handlers (not just nonces — a nonce is CSRF protection, not authorisation). `uninstall.php` removes both options and the capability.

## 9. Generated link markup

`<a class="kntnt-autolink" href="<esc_url>">matched text</a>`, with `rel`/`target` per the global defaults. Followable by default (no `nofollow`) so search engines crawl the internal links — this is the whole SEO point. The `kntnt-autolink` class lets the theme restyle auto-links (e.g. a dotted underline) with zero plugin code.

## 10. Explicitly out of scope (anti-bloat)

No inflection/plural engine. No CSS→XPath converter. No min-distance between links. No per-keyword rule level. No statistics. No live preview. No DB table. No write on the read path.

## 11. Deferred / roadmap (put this line in README)

**Optional hovercard** — a Wikipedia-style hover preview of the target article. It must be **additive**: the link still navigates on click (never `preventDefault`), because search engines only care about the real `<a href>`. It is a separate presentation concern (JS + CSS + a data source + real accessibility work for WCAG 1.4.13, keyboard, and touch), so it does **not** belong in v1. The current design already supports it with no changes: a future hovercard hooks into the `…_link_attributes` filter and the `kntnt-autolink` class, plus a small REST endpoint returning title/excerpt. Natural shape: a small companion plugin that resolves internal URL→post-ID in the filter, adds `data-post-id`, and serves previews via REST. v1 does nothing extra (YAGNI).

README roadmap line: *"Future: optional hovercard (hover preview) as a separate companion plugin, consuming the `…_link_attributes` filter + `kntnt-autolink` class + a REST preview endpoint."*

## 12. Open items to resolve at kickoff

1. **Project name (blocking).** Drives the namespace `\Kntnt\<Project>`, hooks `kntnt_<project>_*`, slug, directory, text domain. Recommendation: `kntnt-autolink` → `\Kntnt\Autolink`, `kntnt_autolink_*`, text domain `kntnt-autolink`. (Alternative `kntnt-internal-links` is clearer about *internal* linking but makes longer hook names.)
2. **`the_content` priority.** Default late (after `wpautop`/shortcodes); expose via the `…_content_priority` filter. No decision needed before coding.
3. **Deployment step.** Turn off Slim SEO Pro's Autolink module so two linkers don't both process `the_content`.

## 13. Coding-standard points that bite hardest here

The full standard is loaded by the `coding-standard` / `coder` skills; these are the ones to keep front of mind:

- `declare(strict_types=1);` in every PHP file.
- Namespace `\Kntnt\<Project>`, PSR-4 in `classes/`, one class per file, filename = class name (`Pascal_Snake_Case`, e.g. `Content_Filter.php`).
- `[ … ]` array literals, never `array(…)`. Trailing commas in multi-line. Natural conditions (no Yoda unless it genuinely reads better).
- Modern PHP (typed properties, `readonly`, constructor promotion, `match`, enums for closed sets, arrow functions, null-safe). Target the latest stable PHP available, not an EOL version.
- PHPDoc on every file/class/method/property; English identifiers and comments. Paragraphs of statements with a `//` topic sentence; no narrating the obvious.
- User-facing strings translatable with the text domain; **the source string in `__()` is English** (Interlinko's mistake was authoring French source strings).
- Escape at output (`esc_html`/`esc_attr`/`esc_url`); sanitise every superglobal; nonces **and** capability checks on admin actions; capabilities, not roles.
- Engine code (`Linker`, `Ruleset`, value objects) stays free of WordPress calls so it unit-tests without a WP bootstrap. Build it test-first.

## 14. Build order

1. Resolve the name (§12.1) and scaffold: `coding-standard` skill → standard files; create the plugin skeleton (§6) with bootstrap, autoloader, value objects.
2. **`Linker` test-first** (`tdd`): cover word boundaries (no partial-word matches), heading/skip-tag exclusion via ancestor walk, `.no-autolink`, allow-only XPath, longest-first, first-occurrence + global cap, UTF-8 integrity, idempotence (don't link inside links it just made).
3. `Ruleset` compilation tests (tag list + class + raw XPath → eligibility query).
4. WordPress glue: repositories, `Content_Filter` (targeting, `should_run`, filters), capabilities, install/uninstall. Brain Monkey unit tests.
5. Admin `Tools_Page` (keyword CRUD + admin-only rules).
6. Integration tests on WordPress Playground: `the_content` end-to-end, capability gating.
7. README (incl. the §11 roadmap line) and the filter documentation.
