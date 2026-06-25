# Plan 009: `README.md`, filter documentation, and the translation template

> **Executor instructions**: Documentation and i18n only — no behaviour changes. Verify the documented hook names against the actual source. Update `plans/README.md` when done.
>
> **Drift check (run first)**: confirm Plans 001–007 committed (006 and 007 define the hooks/markup this documents). `grep -rn "apply_filters\|add_filter" classes/` to read the actual hook names before writing them down — document what the code does, not what this plan guesses.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW
- **Depends on**: 006, 007 (documents their hooks/markup)
- **Category**: docs
- **Planned at**: greenfield, written 2026-06-25

## Why this matters

The plugin's public value is partly its small, deliberate filter API and its identifying `kntnt-autolink` class — the seams Slim SEO Pro and Interlinko lacked (design §1, §7, §9). None of that is usable without documentation. The design also requires a specific roadmap line in the README (the future hovercard, design §11) and English-source translatable strings (design §13). This plan makes the API discoverable and ships the translation template.

## Current state

After Plan 007 the plugin is feature-complete and tested but has no `README.md` and no `.pot`. The hooks exist in `classes/Content_Filter.php` (Plan 006); the generated markup is produced by `classes/Linker.php` (Plan 003) using the class from settings (default `kntnt-autolink`).

**The five filters + priority filter** (verify exact names in source before documenting):
`kntnt_autolink_keywords`, `kntnt_autolink_deny`, `kntnt_autolink_allow_only`, `kntnt_autolink_should_run`, `kntnt_autolink_link_attributes`, `kntnt_autolink_content_priority`.

**The required roadmap line** (design §11, paste verbatim):
> *Future: optional hovercard (hover preview) as a separate companion plugin, consuming the `kntnt_autolink_link_attributes` filter + `kntnt-autolink` class + a REST preview endpoint.*

**Conventions**: Markdown with **one physical line per paragraph** (no hard-wrapping prose at a column — Kntnt standard). Blank line between paragraphs; one line per list item. Do not reflow code fences. English throughout.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Verify hook names | `grep -rno "kntnt_autolink_[a-z_]*" classes/` | the six hook names above |
| Generate POT (if WP-CLI present) | `wp i18n make-pot . languages/kntnt-autolink.pot` | `.pot` created |
| Suite unaffected | `composer test` | still passes |

## Scope

**In scope** (create):
- `README.md`
- `languages/kntnt-autolink.pot`
- Optionally `docs/` files if you prefer to split API docs out — but a single `README.md` is sufficient and preferred (YAGNI).

**Out of scope**:
- Any source/behaviour change. If a documented hook does not exist in source, STOP — that is a Plan 006 bug, not a doc fix.
- Building the hovercard or a REST endpoint (design §11 — explicitly deferred).

## Git workflow

- Branch `advisor/009-readme-and-docs`. Do not push.

## Steps

### Step 1: Write `README.md`

Sections (one physical line per paragraph):
- **Title + one-paragraph description**: rule-based keyword→URL autolinking; replaces Slim SEO Pro's Autolink module; include-only targeting via XPath; followable links by default for SEO.
- **Requirements**: WordPress 6.5+, PHP 8.4+.
- **Installation & deployment**: install/activate; **disable Slim SEO Pro's Autolink module** so two linkers don't both process `the_content` (design §2, §12.3); keep the rest of Slim SEO Pro.
- **Usage**: Tools → Autolink; add keywords (base form, variants, URL, optional max); editors-and-above manage keywords; admins also see the structural rules (deny tags, `.no-autolink` class, raw deny/allow-only XPath, link defaults, global cap, targeting).
- **Styling**: every generated link carries `class="kntnt-autolink"`; theme can restyle (e.g. dotted underline) with zero plugin code.
- **The `.no-autolink` class**: add it to any element to stop linking inside it and its descendants.
- **Filter reference**: a subsection per filter with signature, when it fires, and a short code example. Cover all six. Verify each name against source first (Step 0 grep).
- **Roadmap**: paste the §11 line verbatim.
- **Architecture note** (brief): the matching engine (`Linker`) is WordPress-free and unit-tested; see `classes/`.
- **License**: GPL-2.0-or-later.

For the filter examples, show realistic snippets, e.g.:

```php
// Only link inside the main content area on this site.
add_filter( 'kntnt_autolink_allow_only', fn ( string $xpath ): string => '//main' );

// Add a data attribute to every generated link (the hovercard seam).
add_filter( 'kntnt_autolink_link_attributes', function ( array $attrs, array $context ): array {
	$attrs['data-url'] = $context['url'];
	return $attrs;
}, 10, 2 );
```

### Step 2: Generate the translation template

If WP-CLI is available: `wp i18n make-pot . languages/kntnt-autolink.pot` (text domain `kntnt-autolink`). If WP-CLI is not installed, create a minimal valid `.pot` header by hand listing the domain and the strings found via `grep -rn "__(\|esc_html__(\|esc_attr__(" classes/`. The `.pot` must declare `Project-Id-Version: Kntnt Autolink` and the `kntnt-autolink` text domain.

**Verify**: `languages/kntnt-autolink.pot` exists and contains the strings from the admin page (Plan 007).

### Step 3: Cross-check the docs against the code

`grep -rno "kntnt_autolink_[a-z_]*" classes/` and confirm every hook documented in the README exists in source with that exact name, and every hook in source is documented. Fix the README to match the code (never the reverse from this plan).

**Verify**: each of the six hook names appears in both the README and `grep` output.

## Test plan

- No code tests (docs only). The verification is the cross-check in Step 3 and that `composer test` is unaffected.
- Verification: `composer test` → unchanged; `grep` cross-check passes.

## Done criteria

- [ ] `README.md` exists with all sections in Step 1, including the verbatim §11 roadmap line
- [ ] All six hook names in the README match `grep -rno "kntnt_autolink_[a-z_]*" classes/` exactly (no documented-but-absent hook, no undocumented hook)
- [ ] The deployment section states the Slim SEO Pro Autolink-module disable step
- [ ] `languages/kntnt-autolink.pot` exists with the `kntnt-autolink` text domain and the admin strings
- [ ] Markdown uses one physical line per paragraph (no mid-paragraph hard wraps)
- [ ] `composer test` still passes
- [ ] `plans/README.md` row for 009 updated

## STOP conditions

Stop and report if:
- A hook documented by the design (§7) does not exist in source under that name — report it as a Plan 006 gap; do not invent or rename hooks in docs to paper over it.
- The generated `.pot` is empty of the admin strings — the strings may not be wrapped in `__()`/`esc_html__()` in Plan 007; report it as a Plan 007 i18n gap.

## Maintenance notes

- The README's filter reference is the public API contract surface. Any future hook addition/rename must update it in lockstep; a reviewer should diff hook names in code against the README on every PR that touches `Content_Filter`.
- Regenerate `kntnt-autolink.pot` whenever a user-facing string changes (`wp i18n make-pot`).
- The roadmap line points at a *separate companion plugin* (design §11) — keep the hovercard out of this plugin to preserve the YAGNI boundary.
