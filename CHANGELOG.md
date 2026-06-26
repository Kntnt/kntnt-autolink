# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- A domain glossary (`CONTEXT.md`) defining the project's ubiquitous language (Autolink, Link group, Phrase, Group cap, Post cap, Term targeting).
- Architecture decision records for the planned admin UI redesign: a server-rendered admin UI with a REST-backed modal (ADR 0001), a Settings/Tools split of the admin information architecture (ADR 0002), and the self-healing in-place upgrade routine that carries released 1.0.0 sites onto the link-group model despite the brief's "no migration" premise (ADR 0003).

### Changed

- Replaced the keyword model with the **link group** model: a set of equal, interchangeable phrases that share one URL, one group cap, and their own nofollow and new-tab behaviour. The option `kntnt_autolink_keywords` is renamed to `kntnt_autolink_link_groups` and the capability `kntnt_autolink_manage_keywords` to `kntnt_autolink_manage_link_groups`. An in-place update from 1.0.0 is repaired by a version-keyed upgrade routine that re-grants the renamed capability and folds the legacy keyword entries into link groups, so an updated site is never locked out of the Tools manager and keeps its data.
- Moved the per-link `nofollow` and new-tab behaviour from the global structural rules onto each link group.
- Tools → Autolink now lists link groups in a native `WP_List_Table` (Phrases · URL · Group cap) with an add/edit `<dialog>` modal and Edit/Delete row actions, saving over a REST API (`kntnt-autolink/v1`) secured with `X-WP-Nonce` and a capability check; the table body re-renders server-side after each change with no full page reload.
- The Tools → Autolink list gained native search (matching a group by any phrase or its URL), sortable Phrases (by first phrase) and Group cap columns, and pagination — all resolved server-side and preserved across an add, edit or delete, since the REST re-render carries the current search, sort and page. A `kntnt_autolink_per_page` filter tunes how many groups a page shows.
- The Tools → Autolink list gained row selection with bulk **Delete** and bulk **Set group cap…** actions. Both apply over a capability-then-nonce gated REST bulk route (`POST kntnt-autolink/v1/link-groups/bulk`) and re-render the table without a full page reload, preserving the current search, sort and page; bulk set-cap honours the same positive-integer rule as the per-group cap. A no-JS fallback handles each bulk action through a native confirmation screen (the set-cap screen carrying a number field), nonce-protected before any irreversible delete.
- Relocated the structural rules to their own Settings → Autolink page, realising the Tools/Settings menu split.
- Rebuilt the Settings → Autolink page on the native WordPress Settings API (`register_setting`, three `add_settings_section` groups — Targeting, Link behaviour & limits, Content eligibility — and an `add_settings_field` per control), saving through `options.php`. Post types and deny tags use a new reusable chip input (vanilla ES2022, no build step): post types are a closed selector limited to the registered public post types, deny tags are free-text chips prefilled with the `h1–h6, a, code, pre, script, style` defaults. Each field carries a grey help line. The chip widget degrades without JavaScript to a plain comma/newline textarea that still saves; the sanitise callback rejects post types outside the registered public set and coerces the post cap to a positive integer.
- Renamed the `kntnt_autolink_keywords` filter to `kntnt_autolink_link_groups` (now passing `Link_Group[]`); the `kntnt_autolink_link_attributes` context now carries `group_id` and `matched_text` instead of `keyword_id` and `base`.

### Fixed

- A `PUT`/`PATCH` to `kntnt-autolink/v1/link-groups/{id}` for an unknown id now returns `404` instead of silently creating a new group.
- Removed an out-of-scope cross-navigation link from the Tools screen to the Settings screen (deferred to the admin redesign).

## [1.0.0] - 2026-06-25

### Added

- First release.
- Rule-based keyword-to-URL autolinking, applied to `the_content` at a configurable priority (default `20`).
- A WordPress-free matching engine (`Linker`) with exact, case-insensitive matching, Unicode word boundaries, longest-first ordering, first-occurrence linking, a per-keyword cap and a global per-post cap.
- Include-only XPath targeting, a deny-tag list, a skip class (`.no-autolink`) and a raw deny-XPath, all compiled into a single eligibility query.
- Two non-autoloaded options (settings and keywords), read once per request.
- A Tools page: keyword management for editors and above, structural rules for administrators only.
- A custom capability (`kntnt_autolink_manage_keywords`), granted on activation and removed on uninstall.
- A filter API: `kntnt_autolink_keywords`, `kntnt_autolink_deny`, `kntnt_autolink_allow_only`, `kntnt_autolink_should_run`, `kntnt_autolink_link_attributes` and `kntnt_autolink_content_priority`.
- A `kntnt-autolink` class on every generated link, for theming and as an extension seam.
- A Pest unit suite and a WordPress Playground integration suite.

[Unreleased]: https://github.com/Kntnt/kntnt-autolink/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Kntnt/kntnt-autolink/releases/tag/v1.0.0
