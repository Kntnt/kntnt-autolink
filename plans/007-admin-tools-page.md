# Plan 007: Admin `Tools_Page` — keyword CRUD + admin-only rules section

> **Executor instructions**: Build the page, then add a focused Brain Monkey unit test for the request-handling logic (capability + nonce gating, sanitised dispatch). Honour "STOP conditions". Update `plans/README.md` when done.
>
> **Drift check (run first)**: confirm Plans 001–006 committed and green. Confirm `Keyword_Repository` exposes `all()`, `save(Keyword)`, `delete(string $id)`, `replace_all(array)`; `Settings_Repository` exposes `get_settings()`, `save_settings(array)`; `Capabilities::MANAGE_KEYWORDS === 'kntnt_autolink_manage_keywords'`. If any differ, STOP.

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: MED (admin input handling — the security surface: nonces, capabilities, sanitisation, escaping)
- **Depends on**: 004, 005
- **Category**: security / dx
- **Planned at**: greenfield, written 2026-06-25

## Why this matters

This is the only human-facing surface and the plugin's main security boundary (design §8, §13). It must split authority correctly — editors-and-above manage keywords, admins-only edit the structural rules (a bad XPath from a non-technical editor would break matching) — and apply the full WordPress security discipline: nonce **and** capability checks on every action (a nonce is CSRF protection, not authorisation), every superglobal sanitised, every output escaped at the point of output. Raw XPath fields are admin-only precisely because they are interpolated verbatim into the engine query (Plan 002 trusts them).

## Current state

After Plan 006 the plugin links content on the front end but has no UI; settings/keywords are only editable via code. Repositories and the capability exist.

**Page**: under **Tools** (`add_management_page`), slug `kntnt-autolink`, menu/page title "Autolink".

**Two areas on the one page**:
- **Keywords** — CRUD over the keyword list (base form + a variants field + URL + optional max). Gated by `Capabilities::MANAGE_KEYWORDS` (`kntnt_autolink_manage_keywords`).
- **Settings / rules** — `deny_tags`, `skip_class`, `deny_xpath`, `allow_only_xpath`, link defaults (`nofollow`, `new_tab`, `link_class`), `max_links_per_post`, targeting (`post_types`, `terms`). Rendered and saved **only for `manage_options`**.

**Security rules** (apply to every handler):
- Capability check first (`current_user_can(...)`), then nonce verification (`wp_verify_nonce` / `check_admin_referer`). Both, on every mutating action.
- Sanitise every `$_POST`/`$_GET` access — never bare `$_POST['x']`. (The repositories also sanitise on save, Plan 004 — defence in depth; do both.)
- Escape at output: `esc_html`, `esc_attr`, `esc_url`, `esc_html__`/`esc_attr__` for translatable strings. Text domain `kntnt-autolink`.
- The raw-XPath fields (`deny_xpath`, `allow_only_xpath`) are rendered/saved ONLY inside the `manage_options` block.

**No JavaScript** is required for v1. Use server-rendered forms: a table of existing keywords (each row a small form with Save/Delete) plus an "Add keyword" row. Keep it plain — YAGNI (design §10 forbids bloat; no live preview, no JS framework).

**Conventions**: `declare(strict_types=1)`; tabs; padded parens; PHPDoc `@since 1.0.0`; all strings translatable with text domain `kntnt-autolink`, English source.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| This suite | `vendor/bin/pest tests/Unit/Tools_Page_Test.php` | pass |
| All unit tests | `composer test` | all pass |
| Static analysis | `composer analyse` | "No errors" |
| Syntax lint | `php -l classes/Admin/Tools_Page.php` | "No syntax errors detected" |

## Scope

**In scope** (create):
- `classes/Admin/Tools_Page.php` (note the `Admin/` sub-namespace → `\Kntnt\Autolink\Admin\Tools_Page`)
- `tests/Unit/Tools_Page_Test.php`
- Edit `classes/Plugin.php` to instantiate `Tools_Page` and register its hooks (admin only).

**Out of scope**:
- Repositories, capability, engine (consume only).
- Any JavaScript or CSS file (the link styling is the theme's job per design §9; admin styling uses core classes).
- A settings API registration via `register_setting` is optional; a direct save handler is acceptable and simpler. Pick one; do not build both.

## Public contract

```php
// \Kntnt\Autolink\Admin\Tools_Page
public function __construct( Settings_Repository $settings, Keyword_Repository $keywords );
public function register_hooks(): void;     // admin_menu + admin_post_* handlers
public function add_page(): void;           // add_management_page(...)
public function render(): void;             // outputs the page (cap-gated sections)
// handlers: handle_save_keyword(), handle_delete_keyword(), handle_save_settings()
```

## Git workflow

- Branch `advisor/007-admin-tools-page`. Commit page, then wiring. Do not push.

## Steps

### Step 1: Register the page and hooks

`register_hooks()` adds:
- `add_action('admin_menu', $this->add_page(...))` → `add_management_page('Autolink', 'Autolink', Capabilities::MANAGE_KEYWORDS, 'kntnt-autolink', $this->render(...))`. (Using the keyword capability as the page-access cap means editors see the page; the rules section inside is further gated by `manage_options`.)
- `add_action('admin_post_kntnt_autolink_save_keyword', $this->handle_save_keyword(...))`
- `add_action('admin_post_kntnt_autolink_delete_keyword', $this->handle_delete_keyword(...))`
- `add_action('admin_post_kntnt_autolink_save_settings', $this->handle_save_settings(...))`

### Step 2: `render()` — output, fully escaped, cap-gated

- Always (cap `MANAGE_KEYWORDS`): render the Keywords table from `Keyword_Repository::all()`. Each existing keyword is a row in its own `<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">` with hidden `action=kntnt_autolink_save_keyword`, hidden `id`, `wp_nonce_field('kntnt_autolink_save_keyword')`, text inputs for base / variants (comma- or newline-separated) / url / max, and Save + a separate Delete form (`action=kntnt_autolink_delete_keyword`). Escape every value with `esc_attr`/`esc_url`/`esc_html`.
- An "Add keyword" empty row posts to the same save handler with an empty `id` (the repository/handler generates one).
- Only when `current_user_can('manage_options')`: render the Settings/rules form (`action=kntnt_autolink_save_settings`, its own nonce), with fields for every settings key incl. the two raw-XPath fields. If the user lacks `manage_options`, this whole block is absent from the output (not merely disabled).

Every translatable string uses `esc_html__( '…', 'kntnt-autolink' )` etc.

### Step 3: Handlers — capability THEN nonce, sanitise, dispatch, redirect

Each `admin_post_*` handler follows the same discipline. Example shape for the keyword save:

```php
/**
 * Persist a keyword from the admin form. Editor-and-above only.
 *
 * @since 1.0.0
 */
public function handle_save_keyword(): void {

	// Authorisation first: capability, then CSRF nonce. A nonce is not authz.
	if ( ! current_user_can( Capabilities::MANAGE_KEYWORDS ) ) {
		wp_die( esc_html__( 'You are not allowed to manage keywords.', 'kntnt-autolink' ), '', [ 'response' => 403 ] );
	}
	check_admin_referer( 'kntnt_autolink_save_keyword' );

	// Sanitise every field before building the value object.
	$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
	$base = isset( $_POST['base'] ) ? sanitize_text_field( wp_unslash( $_POST['base'] ) ) : '';
	$variants = $this->parse_variants( isset( $_POST['variants'] ) ? wp_unslash( $_POST['variants'] ) : '' );
	$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$max = isset( $_POST['max'] ) ? max( 1, absint( wp_unslash( $_POST['max'] ) ) ) : 1;

	// Persist via the repository (which sanitises again — defence in depth).
	if ( $base !== '' && $url !== '' ) {
		$this->keywords->save( new Keyword(
			id: $id !== '' ? $id : sanitize_key( wp_generate_uuid4() ),
			base: $base, variants: $variants, url: $url, max: $max,
		) );
	}

	// Redirect back to the page (Post/Redirect/Get).
	wp_safe_redirect( add_query_arg( 'page', 'kntnt-autolink', admin_url( 'tools.php' ) ) );
	exit;
}
```

The settings handler additionally **gates on `manage_options`** (not `MANAGE_KEYWORDS`) and routes the sanitised array to `Settings_Repository::save_settings()`. The raw-XPath fields are only read inside that `manage_options`-gated handler — an editor who forges a settings POST is rejected by the capability check.

### Step 4: Unit-test the handlers' gating and dispatch

With Brain Monkey, stub `current_user_can`, `check_admin_referer`, `wp_unslash`, `sanitize_*`, `esc_url_raw`, `wp_safe_redirect`, `wp_die`. Cases:
1. `handle_save_keyword` with `current_user_can(MANAGE_KEYWORDS)` false → calls `wp_die` (assert), does NOT call `Keyword_Repository::save` (use a Mockery mock).
2. `handle_save_keyword` authorised + valid input → calls `keywords->save` once with a `Keyword` whose fields equal the sanitised input.
3. `handle_save_settings` with `manage_options` false → `wp_die`, `Settings_Repository::save_settings` NOT called.
4. `handle_save_settings` authorised → `save_settings` called once with the sanitised array including the XPath fields.
5. `handle_delete_keyword` authorised → `keywords->delete($id)` called with the sanitised id.

Because `wp_die`/`exit` terminate, structure handlers so the testable work happens before `exit`, and stub `wp_safe_redirect`/`wp_die` to not actually exit (Brain Monkey `when(...)->justReturn(null)`); assert on the repository interactions.

**Verify**: `vendor/bin/pest tests/Unit/Tools_Page_Test.php` → passes.

### Step 5: Wire into `Plugin` (admin only)

In `Plugin::__construct`, after the content filter wiring, add:

```php
// Admin UI is only needed in the admin context.
if ( is_admin() ) {
	( new \Kntnt\Autolink\Admin\Tools_Page( $settings, $keywords ) )->register_hooks();
}
```

**Verify**: `php -l classes/Plugin.php` → clean; `composer analyse` → clean.

### Step 6: Full suite + static analysis

**Verify**: `composer test` → all pass; `composer analyse` → "No errors".

## Test plan

- `tests/Unit/Tools_Page_Test.php` — the 5 gating/dispatch cases in Step 4 (capability-before-nonce, sanitised dispatch, admin-only settings). Model after `tests/Unit/Content_Filter_Test.php` (Brain Monkey + Mockery).
- The rendered HTML's escaping is verified by inspection + `composer analyse` (with the WordPress-stubs extension flagging unescaped output if PHPStan rules are enabled) and end-to-end by the capability-gating integration test (Plan 008).
- Verification: `composer test` → all unit tests pass.

## Done criteria

- [ ] `composer test` exits 0; `Tools_Page_Test` passes (all 5 cases)
- [ ] `composer analyse` exits 0, "No errors"
- [ ] Every mutating handler calls BOTH `current_user_can(...)` and `check_admin_referer(...)` (grep each handler)
- [ ] The `deny_xpath` / `allow_only_xpath` fields appear ONLY within a `current_user_can( 'manage_options' )` branch (grep: both strings sit inside that guard in `render()` and `handle_save_settings()`)
- [ ] `grep -nE "\$_(POST|GET|REQUEST)\[" classes/Admin/Tools_Page.php` shows no access that isn't wrapped in `wp_unslash` + a `sanitize_*`/`absint`/`esc_url_raw`
- [ ] `Plugin::__construct` registers `Tools_Page` inside `is_admin()`
- [ ] `git status` shows only in-scope files + `classes/Plugin.php`
- [ ] `plans/README.md` row for 007 updated

## STOP conditions

Stop and report if:
- You cannot test a handler because `wp_die`/`exit` aborts the test even when stubbed — report; restructure so the gating logic is callable and observable, but do not remove the real `exit` from production paths.
- A repository method needed (e.g. `delete`) is missing or has a different signature (Plan 004 drift).
- You are tempted to add JavaScript to make the variants field dynamic — STOP; v1 is server-rendered (design §10). A plain textarea of newline-separated variants is the intended shape.

## Maintenance notes

- The authority split (keywords = `MANAGE_KEYWORDS`; rules = `manage_options`) is a core security decision (design §2, §8). A reviewer must reject any change that lets a keyword-capable editor reach the XPath fields.
- Capability-before-nonce ordering matters: the nonce only proves the request came from a form this user was shown; the capability proves they're allowed. Keep both, in that order.
- If a future version wants a nicer variants editor or live preview, that is explicitly out of scope for v1 (design §10) — it would be a separate, opt-in enhancement.
- All output escaping happens here at render time; storage sanitisation happens in the repositories. Both layers are intentional — do not remove either as "redundant".
