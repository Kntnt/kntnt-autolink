# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
