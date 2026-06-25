# Plan 005: Capabilities and lifecycle (`Capabilities`, `install.php`, `uninstall.php`)

> **Executor instructions**: Build test-first where a unit test adds value (capability mapping); the install/uninstall scripts are verified by static analysis + a focused Brain Monkey test. Honour "STOP conditions". Update `plans/README.md` when done.
>
> **Drift check (run first)**: confirm Plans 001–004 committed and green. Confirm the option keys `kntnt_autolink_settings` and `kntnt_autolink_keywords` are the constants used in `classes/Settings_Repository.php` / `classes/Keyword_Repository.php`. If they differ, STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED (uninstall deletes data; capability grant touches roles)
- **Depends on**: 001 (and the option keys defined in 004)
- **Category**: security / lifecycle
- **Planned at**: greenfield, written 2026-06-25

## Why this matters

The design splits authority (design §2, §8, §13): editors-and-above manage the keyword list via a **custom capability**, while structural rules stay admin-only. This plan creates that capability and grants it to roles holding `edit_others_posts` (editor, admin) on activation, and removes everything cleanly on uninstall (both options + the capability). Capabilities-not-roles and complete uninstall are explicit standard requirements; doing this wrong either over-grants authority or leaves orphaned data/capabilities behind.

## Current state

After Plan 004 the repositories and their option keys exist. The main file (`kntnt-autolink.php`, Plan 001) already `register_activation_hook`s a callback that `require`s `install.php` — which does not exist yet. Nothing registers the uninstall path yet.

**Capability**: `kntnt_autolink_manage_keywords` — granted to every role that has `edit_others_posts`.

**Conventions**: `declare(strict_types=1)`. `install.php` and `uninstall.php` run **without the autoloader** in some contexts (`uninstall.php` is invoked by WordPress directly), so they use fully-qualified function calls and do not assume plugin classes are loaded unless they `require` them. `uninstall.php` must guard on `WP_UNINSTALL_PLUGIN`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Capability test | `vendor/bin/pest tests/Unit/Capabilities_Test.php` | pass |
| All unit tests | `composer test` | all pass |
| Static analysis | `composer analyse` | "No errors" |
| Syntax lint | `php -l install.php && php -l uninstall.php` | "No syntax errors detected" (×2) |

## Scope

**In scope** (create):
- `classes/Capabilities.php`
- `install.php`
- `uninstall.php`
- `tests/Unit/Capabilities_Test.php`

**Out of scope**:
- Registering the uninstall hook. WordPress auto-detects `uninstall.php` at the plugin root — no registration needed. Do NOT also call `register_uninstall_hook` (that would double-run).
- Seeding default settings on activation is optional and NOT required — `Settings_Repository::get_settings()` already supplies defaults in-memory when the option is absent (Plan 004). Do not write the settings option on activation.
- The admin capability checks themselves (Plan 007 uses the capability this plan creates).

## Public contract

```php
// Capabilities
public const MANAGE_KEYWORDS = 'kntnt_autolink_manage_keywords';
public function grant(): void;    // add MANAGE_KEYWORDS to roles with edit_others_posts
public function revoke(): void;   // remove MANAGE_KEYWORDS from all roles
public function register_hooks(): void;   // (optional) map meta caps if needed; see Step 3
```

## Git workflow

- Branch `advisor/005-capabilities-and-lifecycle`. Commit per file. Do not push.

## Steps

### Step 1: `Capabilities` — test first

Cases (Brain Monkey + a small fake role set):
1. `grant()` iterates roles and calls `WP_Role::add_cap('kntnt_autolink_manage_keywords')` on each role that `has_cap('edit_others_posts')`, and does NOT add it to roles lacking it. Stub `wp_roles()` to return an object whose `->roles` / `get_role()` you control, or stub `get_editable_roles()` + `get_role()`. Assert add_cap is called for editor/administrator, not for subscriber/author.
2. `revoke()` calls `remove_cap('kntnt_autolink_manage_keywords')` on every role.

**Verify**: `vendor/bin/pest tests/Unit/Capabilities_Test.php` → fails first.

### Step 2: `Capabilities` — implement

```php
<?php
/**
 * Registers and removes the custom capability that gates keyword management.
 *
 * The keyword list is editor-and-above: the capability is granted to every role
 * that can edit others' posts. Structural rules stay on manage_options and are
 * not represented here.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Capabilities {

	/** @since 1.0.0 */
	public const MANAGE_KEYWORDS = 'kntnt_autolink_manage_keywords';

	/**
	 * Grant the capability to every role that can edit others' posts.
	 *
	 * @since 1.0.0
	 */
	public function grant(): void {
		foreach ( wp_roles()->roles as $slug => $detail ) {
			$role = get_role( $slug );
			if ( $role !== null && $role->has_cap( 'edit_others_posts' ) ) {
				$role->add_cap( self::MANAGE_KEYWORDS );
			}
		}
	}

	/**
	 * Remove the capability from every role.
	 *
	 * @since 1.0.0
	 */
	public function revoke(): void {
		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			get_role( $slug )?->remove_cap( self::MANAGE_KEYWORDS );
		}
	}

}
```

**Verify**: `vendor/bin/pest tests/Unit/Capabilities_Test.php` → passes; `composer analyse` → clean.

### Step 3: `install.php` — activation

Runs in the plugin's process during activation. The main file already `require`s it; the autoloader is loaded by then (the main file requires `autoloader.php` before registering the hook), so `Capabilities` is available.

```php
<?php
/**
 * Activation routine: grant the keyword-management capability.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

// Grant the custom capability to editor-and-above roles.
( new Capabilities() )->grant();
```

**Verify**: `php -l install.php` → "No syntax errors detected".

### Step 4: `uninstall.php` — complete data removal

Invoked by WordPress directly (no plugin bootstrap). Guard on the constant; delete both options and the capability using fully-qualified calls. Do not rely on the autoloader being present — but you may `require` it to reuse `Capabilities`:

```php
<?php
/**
 * Uninstall routine: remove both options and the custom capability.
 *
 * Runs in WordPress's uninstall context without the plugin bootstrapped.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

// Refuse to run outside WordPress's uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove both stored options (settings + keywords).
delete_option( 'kntnt_autolink_settings' );
delete_option( 'kntnt_autolink_keywords' );

// Remove the custom capability from every role.
require_once __DIR__ . '/autoloader.php';
( new \Kntnt\Autolink\Capabilities() )->revoke();
```

Keep the option keys here in exact agreement with the repository constants (Plan 004). If you prefer to avoid the autoloader in uninstall, inline the role loop instead of constructing `Capabilities` — but the keys/strings must match.

**Verify**: `php -l uninstall.php` → "No syntax errors detected".

### Step 5: Full suite + static analysis

**Verify**: `composer test` → all pass; `composer analyse` → "No errors".

## Test plan

- `tests/Unit/Capabilities_Test.php` — grant adds the cap only to roles with `edit_others_posts`; revoke removes it from all. Model after `tests/Unit/Settings_Repository_Test.php` (Brain Monkey).
- `install.php` / `uninstall.php` are verified by `php -l` and by the `Capabilities` unit test (they are thin wrappers); a full activation/uninstall round-trip is covered by the integration suite (Plan 008).
- Verification: `composer test` → all pass.

## Done criteria

- [ ] `composer test` exits 0; `Capabilities_Test` passes
- [ ] `php -l install.php` and `php -l uninstall.php` clean
- [ ] `composer analyse` exits 0, "No errors"
- [ ] `uninstall.php` guards on `WP_UNINSTALL_PLUGIN` and deletes `kntnt_autolink_settings` AND `kntnt_autolink_keywords` AND revokes `kntnt_autolink_manage_keywords` (grep to confirm all three strings present)
- [ ] `git status` shows only in-scope files
- [ ] `plans/README.md` row for 005 updated

## STOP conditions

Stop and report if:
- The option-key strings in the repositories differ from the strings you are deleting in `uninstall.php` (drift) — they must match exactly or uninstall leaves orphaned data.
- `wp_roles()` / `get_role()` cannot be stubbed in the capability test — report rather than testing against a live WordPress.

## Maintenance notes

- The capability is granted on activation only. If the plugin is already active and you change the grant logic, existing installs won't re-grant until reactivated — note this in any future change.
- `uninstall.php` is the single place that must list *every* persistent artifact. If a future version adds an option, transient, or capability, add its removal here — otherwise uninstall leaves residue (a standard violation).
- The capability is mapped to `edit_others_posts`-holders at grant time, not dynamically. If the design later wants dynamic mapping (e.g. via `map_meta_cap`), that becomes `register_hooks()` and a `map_meta_cap` filter — out of scope for v1 (YAGNI).
