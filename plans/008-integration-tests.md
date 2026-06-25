# Plan 008: Integration tests on WordPress Playground (`the_content` end-to-end + capability gating)

> **Executor instructions**: This plan stands up a real WordPress (via Playground) and asserts end-to-end behaviour. Playground/CLI behaviour varies; the STOP conditions are important here. Update `plans/README.md` when done.
>
> **Drift check (run first)**: confirm Plans 001–007 committed and green (`composer test` and `composer analyse` pass). Confirm `node`/`npx` available (`node --version` → v26.x). Confirm the plugin loads under `php -l kntnt-autolink.php`.

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: MED (environment-dependent; Playground CLI version differences)
- **Depends on**: 006, 007
- **Category**: tests
- **Planned at**: greenfield, written 2026-06-25

## Why this matters

The unit suite proves the engine and the glue in isolation; only an integration test proves the plugin actually links content when WordPress renders a real post, that `wpautop`/shortcodes interplay at the chosen `the_content` priority works, and that the capability gating holds in a real role context. The design mandates Playground integration tests for exactly these two scenarios (design §6, §14.6). Playground (WASM PHP + SQLite) boots in 1–2 seconds with no server, so this stays cheap.

## Current state

After Plan 007 the plugin is feature-complete and unit-green. `tests/Integration/` exists but is empty (Plan 001 created the directory). Node 26.3.1 with `npx` is available; the Playground CLI is `@wp-playground/cli` (run via `npx @wp-playground/cli@latest`).

**The two scenarios to assert** (design §14.6):
1. **`the_content` end-to-end**: with a keyword configured and a published post containing that keyword in a paragraph and the same keyword inside an `<h2>`, the rendered front-end HTML contains `<a class="kntnt-autolink" href="…">keyword</a>` for the paragraph occurrence and does NOT link the heading occurrence.
2. **Capability gating**: a user with the keyword capability can load the Tools page; the structural-rules (XPath) fields are absent for a non-`manage_options` user and present for an admin. (A lighter assertion is acceptable — see Step 3 — if driving authenticated requests in Playground proves heavy.)

**Conventions**: integration tests are Bash + Playground per the standard (`tests/Integration/`). Keep them out of the fast `composer test` path (the `phpunit.xml` Integration suite can stay empty of PHP; these are shell-driven).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Run integration tests | `bash tests/Integration/run.sh` | exit 0, "ALL INTEGRATION TESTS PASSED" |
| Playground CLI help | `npx @wp-playground/cli@latest --help` | prints usage (confirms availability) |
| Unit suite (unaffected) | `composer test` | still all pass |

## Scope

**In scope** (create):
- `tests/Integration/run.sh` — the test runner.
- `tests/Integration/blueprint.json` — Playground blueprint that mounts this plugin, activates it, and seeds data.
- `tests/Integration/seed.php` (or inline blueprint steps) — sets the keyword + settings options and creates the test post.
- `tests/Integration/README.md` — how to run them and the prerequisites (Node + npx).

**Out of scope**:
- Any change to plugin source. If a test reveals a bug, STOP and report it (it likely belongs to the plan that owns that code), do not patch source from this plan.
- DDEV-based tests — Playground suffices here (design/standard: DDEV only when Playground cannot exercise the behaviour). Do not set up DDEV.

## Git workflow

- Branch `advisor/008-integration-tests`. Do not push.

## Steps

### Step 1: Confirm the Playground CLI runs

```bash
npx --yes @wp-playground/cli@latest --help
```

If this fails (offline, or the package name/flags differ on the installed version), STOP and report the exact error — the rest of the plan depends on the CLI's interface, which you must confirm before scripting against it.

Capture from the help output the flags this version uses for: mounting a plugin (`--mount` / `--plugin`), running a blueprint (`--blueprint`), and executing PHP/wp-cli (`--command` or a `runPHP`/`wp-cli` blueprint step). Use the actual flags in the scripts below; the names in this plan are the expected ones but verify them.

### Step 2: Write the blueprint and seed

`tests/Integration/blueprint.json` — boot WordPress, mount this plugin from the repo root, activate it, then run a seed step that sets the options and creates a post. Expected shape (adapt step names to the confirmed CLI/blueprint schema):

```json
{
	"$schema": "https://playground.wordpress.net/blueprint-schema.json",
	"landingPage": "/?p=2",
	"preferredVersions": { "php": "8.4", "wp": "latest" },
	"steps": [
		{ "step": "login", "username": "admin", "password": "password" },
		{ "step": "runPHP", "code": "<?php require_once getenv('DOCROOT') . '/wp-load.php'; /* seed: see seed.php content inlined here */" }
	]
}
```

Note: the plugin's PHP floor is **8.4**, which current Playground builds support — so `preferredVersions.php: "8.4"` should select a runtime that satisfies the plugin's hard guard (`version_compare(PHP_VERSION,'8.4','<')`) and the plugin activates normally. **If the installed Playground cannot provide 8.4** (older CLI), set `preferredVersions.php` to the highest it offers and confirm whether 8.4 is selectable; if it genuinely cannot reach 8.4, STOP and report — do not weaken the plugin's PHP guard to make the test pass. Record the finding so the maintainer can decide (e.g. pin a newer Playground CLI, or accept that integration runs use the highest available and note the gap).

The seed (inline PHP or `seed.php`) must:
- `update_option('kntnt_autolink_settings', [...defaults...], false)` (or rely on defaults).
- `update_option('kntnt_autolink_keywords', [[ 'id'=>'k1','base'=>'autolink','variants'=>[],'url'=>'https://example.com/target','max'=>1 ]], false)`.
- Create a published post (id 2) with content `<h2>About autolink</h2><p>This is autolink in a paragraph.</p>`.

### Step 3: Write `run.sh` — boot, request, assert

The runner boots Playground with the blueprint, fetches the rendered post HTML, and greps for the expected/forbidden markup. Expected shape:

```bash
#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../.." && pwd )"
PASS=0 FAIL=0

assert_contains() { # haystack_file needle label
	if grep -qF "$2" "$1"; then echo "PASS: $3"; PASS=$((PASS+1));
	else echo "FAIL: $3 (missing: $2)"; FAIL=$((FAIL+1)); fi
}
assert_absent() {
	if grep -qF "$2" "$1"; then echo "FAIL: $3 (unexpected: $2)"; FAIL=$((FAIL+1));
	else echo "PASS: $3"; PASS=$((PASS+1)); fi
}

# Boot Playground with the plugin mounted + blueprint, capture the rendered post.
# (Use the confirmed CLI flags from Step 1. Example, verify against --help:)
OUT="$(mktemp)"
npx --yes @wp-playground/cli@latest server \
	--blueprint "$PLUGIN_DIR/tests/Integration/blueprint.json" \
	--mount "$PLUGIN_DIR:/wordpress/wp-content/plugins/kntnt-autolink" \
	&  # or the CLI's one-shot "run" mode if it exposes one; prefer non-server if available
SERVER_PID=$!
# Wait for readiness, then fetch:
# curl -s http://127.0.0.1:9400/?p=2 > "$OUT"
# kill "$SERVER_PID"

# Scenario 1: paragraph occurrence linked, heading occurrence not.
assert_contains "$OUT" '<a class="kntnt-autolink" href="https://example.com/target">autolink</a>' "paragraph keyword is linked"
assert_absent  "$OUT" '<h2>About <a class="kntnt-autolink"' "heading keyword is NOT linked"

echo "----"; echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ] && echo "ALL INTEGRATION TESTS PASSED" || { echo "INTEGRATION TESTS FAILED"; exit 1; }
```

The exact mechanism for "boot, wait, fetch, shutdown" depends on the CLI version confirmed in Step 1 — if the CLI offers a one-shot mode that runs a blueprint and prints a rendered URL's HTML, prefer it over the background-server + curl dance. Make the script robust: `trap` to kill the server on exit; poll for readiness rather than a fixed `sleep`.

For **scenario 2 (capability gating)**, the lighter, reliable assertion: add a blueprint `runPHP` step that, after seeding, renders the Tools page callback under two simulated users and writes the output to files, then grep that the admin output contains `allow_only_xpath` and the editor output does not. If driving `wp_set_current_user` + capturing admin-page HTML in Playground proves too fiddly, assert the unit-level guarantee is already covered by Plan 007's tests and record scenario 2 as "covered by unit tests; Playground asserts front-end only" in `tests/Integration/README.md` — do not fake a passing assertion.

### Step 4: Document and finalise

Write `tests/Integration/README.md`: prerequisites (Node ≥ 20, `npx`), how to run (`bash tests/Integration/run.sh`), the PHP-version caveat from Step 2, and which scenarios are covered here vs. by unit tests.

**Verify**: `bash tests/Integration/run.sh` → exits 0, prints "ALL INTEGRATION TESTS PASSED"; `composer test` still passes (unchanged).

## Test plan

- `tests/Integration/run.sh` drives Playground and asserts scenario 1 (and scenario 2 where feasible).
- These are deliberately NOT part of `composer test` (which must stay fast and hermetic). They are run on demand and in CI separately.
- Verification: `bash tests/Integration/run.sh` → all pass.

## Done criteria

- [ ] `npx @wp-playground/cli@latest --help` runs (CLI confirmed available)
- [ ] `bash tests/Integration/run.sh` exits 0 and prints "ALL INTEGRATION TESTS PASSED"
- [ ] Scenario 1 asserts BOTH the paragraph link present AND the heading link absent
- [ ] Scenario 2 is either asserted in Playground OR explicitly documented as unit-covered in `tests/Integration/README.md` (no faked assertion)
- [ ] `composer test` still passes unchanged (no plugin source modified by this plan)
- [ ] `git status` shows only files under `tests/Integration/`
- [ ] `plans/README.md` row for 008 updated

## STOP conditions

Stop and report (do not improvise, and do not modify plugin source) if:
- The installed Playground cannot provide PHP 8.4 and the plugin's version guard therefore deactivates it — report the available versions and the options (pin a newer Playground CLI vs. accept the gap); this is a maintainer decision, not an executor one.
- A scenario-1 assertion fails because the keyword is NOT linked where it should be, or IS linked in the heading — that is a real bug in Plans 003/006; report it against the owning plan with the rendered HTML, do not patch from here.
- The CLI interface differs so much from Step 2/3's assumptions that you cannot script it reliably — report what the installed `--help` shows.

## Maintenance notes

- Pin a known-good `@wp-playground/cli` version in `tests/Integration/README.md` once one works, so CI is reproducible (the `@latest` here is for first bring-up).
- If a future change adjusts the default `the_content` priority or the deny-tag list, scenario 1's expectations may shift — keep the seed and assertions in sync with the design defaults.
- The PHP-version caveat (Playground vs. the plugin's 8.4 floor) is the most likely source of flakiness on older Playground CLIs; the README must spell it out for whoever runs CI.
