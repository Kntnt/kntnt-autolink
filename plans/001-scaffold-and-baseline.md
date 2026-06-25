# Plan 001: Scaffold the plugin skeleton, tooling, and a green verification baseline

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in the "STOP conditions" section occurs, stop and report — do not improvise. When done, update the status row for this plan in `plans/README.md`.
>
> **Drift check (run first)**: This is a greenfield plan. The project directory should contain only `kntnt-autolink-design.md` and the `plans/` directory. Run `ls -A`. If `composer.json`, `classes/`, or a `.git` directory already exist, this plan has already partly run — STOP and report what exists before proceeding.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: dx
- **Planned at**: greenfield (no git repo as of 2026-06-25; this plan creates it)

## Why this matters

Nothing can be built or verified until the plugin has a loadable skeleton and a working test/static-analysis toolchain. This plan establishes the "verification baseline" every later plan depends on: a green `composer test`, a clean `composer analyse`, a syntactically valid plugin bootstrap, and the Kntnt coding-standard files in the repo so every later executor writes code the right way. No domain logic is written here — only the frame that makes the domain logic testable.

## Current state

Empty project. The directory `/Users/thomas/Projects/kntnt-autolink/` contains only:

- `kntnt-autolink-design.md` — the full design spec (read it; it is the source of truth for everything).
- `plans/` — this plan set.

Confirmed available on this machine (do not re-install): PHP 8.5.7, Composer 2.10.1, Node 26.3.1 with `npx`, DDEV 1.25.2, `uv` 0.11.24, git 2.54.0.

**Canonical names — use these verbatim everywhere in this and every later plan:**

| Token | Value |
|---|---|
| Plugin slug / directory / text domain | `kntnt-autolink` |
| Main file | `kntnt-autolink.php` |
| PHP namespace root | `\Kntnt\Autolink` |
| Hook / option / capability prefix | `kntnt_autolink_` |
| Settings option key | `kntnt_autolink_settings` |
| Keywords option key | `kntnt_autolink_keywords` |
| Custom capability | `kntnt_autolink_manage_keywords` |
| Generated link CSS class | `kntnt-autolink` |
| Version | `1.0.0` |
| Minimum PHP | `8.4` |

**Conventions to follow** (from Kntnt's coding standard, which Step 7 installs into `agents.d/coding-standard/` — read those files once they exist):

- `declare( strict_types = 1 );` as the first statement after `<?php` in every PHP file.
- WordPress flavour formatting: **tabs** for indentation (display 4 cols), **padded parens** `if ( $x === null )`, `$snake_case` variables, `snake_case` methods, `Pascal_Snake_Case` classes (e.g. `Content_Filter`), `SCREAMING_SNAKE_CASE` constants.
- `[ ... ]` array literals, never `array( ... )`. Trailing commas in multi-line arrays/parameter lists.
- One class per file; filename equals the class name exactly (`Content_Filter.php`), case-sensitive.
- PSR-4 source dir is `classes/` (not `src/`), mapping `\Kntnt\Autolink\Foo_Bar` → `classes/Foo_Bar.php`.
- PHPDoc block on every file, class, method, property, constant, with `@since 1.0.0`. English identifiers and comments. User-facing strings English source, wrapped in `__()`/`esc_html__()` etc. with text domain `kntnt-autolink`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Install deps | `composer install` | exit 0, `vendor/` created |
| Unit tests | `composer test` | exit 0, Pest runs, smoke test passes |
| Static analysis | `composer analyse` | exit 0, "No errors" |
| PHP syntax lint | `php -l kntnt-autolink.php` | "No syntax errors detected" |

## Suggested executor toolkit

- Invoke the `kntnt-code-skills:coding-standard` skill (Step 7) to scaffold the standard. If the skill is not available in your environment, run its script directly — the exact command is given in Step 7.

## Scope

**In scope** (create these):
- `.gitignore`, `kntnt-autolink.php`, `autoloader.php`, `composer.json`, `phpstan.neon`, `phpunit.xml`
- `classes/Plugin.php`
- `tests/Pest.php`, `tests/Unit/Smoke_Test.php`
- `js/.gitkeep`, `css/.gitkeep`, `languages/.gitkeep`, `migrations/.gitkeep`
- `agents.d/`, `AGENTS.md`, `CLAUDE.md` (via the coding-standard skill in Step 7)

**Out of scope** (do NOT create here — later plans own them):
- `classes/Keyword.php`, `classes/Ruleset.php` (Plan 002)
- `classes/Linker.php` (Plan 003)
- `classes/Settings_Repository.php`, `classes/Keyword_Repository.php` (Plan 004)
- `classes/Capabilities.php`, `install.php`, `uninstall.php` (Plan 005)
- `classes/Content_Filter.php` and the real component wiring inside `Plugin.php` (Plan 006)
- `classes/Admin/Tools_Page.php` (Plan 007)
- `README.md` (Plan 009)

Do NOT write any domain logic. `Plugin::get_instance()` stays an empty-bodied singleton until Plan 006.

## Git workflow

- This plan runs `git init` (Step 1). After it, commit the scaffold: `git add -A && git commit -m "Scaffold plugin skeleton and test toolchain"`.
- Later plans branch from here; commit per logical unit. Do not push unless instructed.

## Steps

### Step 1: Initialise git and ignore build artifacts

`git init`. Create `.gitignore`:

```gitignore
/vendor/
/node_modules/
.DS_Store
.phpunit.result.cache
/.phpunit.cache/
*.log
```

**Verify**: `git status` → shows untracked files, no errors.

### Step 2: Write the main plugin file `kntnt-autolink.php`

A WordPress plugin header, a hard PHP-version guard (the plugin targets PHP 8.4; bail cleanly on older), the autoloader require, activation/uninstall registration, then the singleton boot. Target shape:

```php
<?php
/**
 * Plugin Name:       Kntnt Autolink
 * Plugin URI:        https://github.com/Kntnt/kntnt-autolink
 * Description:       Rule-based keyword→URL autolinking with include-only targeting, deep-module architecture, and a small filter API.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.4
 * Author:            Kntnt
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kntnt-autolink
 * Domain Path:       /languages
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

// Refuse to load on an unsupported PHP version instead of fataling mid-request.
if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
	add_action( 'admin_notices', static function (): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Kntnt Autolink requires PHP 8.4 or later and has been deactivated.', 'kntnt-autolink' );
		echo '</p></div>';
	} );
	return;
}

require_once __DIR__ . '/autoloader.php';

// Activation grants the custom capability (see install.php, Plan 005).
register_activation_hook( __FILE__, static function (): void {
	require_once __DIR__ . '/install.php';
} );

// Boot the plugin: the singleton wires every component and registers its hooks.
add_action( 'plugins_loaded', static function (): void {
	Plugin::get_instance();
} );
```

Note: `install.php` does not exist until Plan 005. The `register_activation_hook` callback only `require`s it on activation, so the plugin still loads without it; PHP does not evaluate the missing file at load time. If you want `php -l` and `composer analyse` to pass before Plan 005, that is fine — neither executes the activation callback.

**Verify**: `php -l kntnt-autolink.php` → `No syntax errors detected`.

### Step 3: Write `autoloader.php` (PSR-4 for `\Kntnt\Autolink`)

A minimal, dependency-free SPL autoloader mapping the namespace to `classes/`:

```php
<?php
/**
 * PSR-4 autoloader for the \Kntnt\Autolink namespace.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

spl_autoload_register( static function ( string $class ): void {

	// Only handle this plugin's namespace; ignore everything else.
	$prefix = 'Kntnt\\Autolink\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	// Map the relative class name to a file under classes/, preserving sub-namespaces.
	$relative = substr( $class, strlen( $prefix ) );
	$path = __DIR__ . '/classes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $path ) ) {
		require_once $path;
	}

} );
```

**Verify**: `php -l autoloader.php` → `No syntax errors detected`.

### Step 4: Write the singleton skeleton `classes/Plugin.php`

Empty wiring for now — Plan 006 fills the constructor. Target shape:

```php
<?php
/**
 * Plugin bootstrap singleton.
 *
 * Instantiates every component in dependency order and registers their
 * WordPress hooks. The constructor is the single wiring point.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Plugin {

	/** @since 1.0.0 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the one shared instance, creating it on first call.
	 *
	 * @since 1.0.0
	 */
	public static function get_instance(): Plugin {
		return self::$instance ??= new self();
	}

	/**
	 * Wires components and registers hooks.
	 *
	 * Intentionally empty until Plan 006 wires the Content_Filter.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

}
```

**Verify**: `php -l classes/Plugin.php` → `No syntax errors detected`.

### Step 5: Write `composer.json`

Defines PSR-4 autoloading for tests, the dev toolchain, and the `test`/`analyse` scripts. Target:

```json
{
	"name": "kntnt/kntnt-autolink",
	"description": "Rule-based keyword→URL autolinking for WordPress.",
	"type": "wordpress-plugin",
	"license": "GPL-2.0-or-later",
	"require": {
		"php": ">=8.4"
	},
	"require-dev": {
		"pestphp/pest": "^4.0",
		"brain/monkey": "^2.6",
		"mockery/mockery": "^1.6",
		"phpstan/phpstan": "^2.0",
		"szepeviktor/phpstan-wordpress": "^2.0",
		"php-stubs/wordpress-stubs": "^6.5"
	},
	"autoload": {
		"psr-4": {
			"Kntnt\\Autolink\\": "classes/"
		}
	},
	"autoload-dev": {
		"psr-4": {
			"Kntnt\\Autolink\\Tests\\": "tests/"
		}
	},
	"scripts": {
		"test": "pest",
		"analyse": "phpstan analyse"
	},
	"config": {
		"allow-plugins": {
			"pestphp/pest-plugin": true
		},
		"sort-packages": true
	}
}
```

Then run `composer install`.

**Verify**: `composer install` → exit 0, `vendor/bin/pest` and `vendor/bin/phpstan` exist.

If Composer cannot resolve a dependency version (e.g. a `^` constraint is unavailable for PHP 8.4), STOP — see STOP conditions; do not silently downgrade a tool to an EOL version.

### Step 6: Configure Pest and PHPStan, add a smoke test

**`phpunit.xml`** (Pest reads PHPUnit's config):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
	<testsuites>
		<testsuite name="Unit">
			<directory>tests/Unit</directory>
		</testsuite>
		<testsuite name="Integration">
			<directory>tests/Integration</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

**`tests/Pest.php`** (Pest's per-suite bootstrap; Brain Monkey wiring is added per-test where needed):

```php
<?php

declare( strict_types = 1 );

// Bind Pest's base test case to the Unit and Integration directories.
pest()->extend( PHPUnit\Framework\TestCase::class )->in( 'Unit', 'Integration' );
```

**`tests/Unit/Smoke_Test.php`** — proves the toolchain runs:

```php
<?php

declare( strict_types = 1 );

it( 'has a working test toolchain', function (): void {
	expect( true )->toBeTrue();
} );

it( 'autoloads the Plugin singleton class', function (): void {
	expect( class_exists( \Kntnt\Autolink\Plugin::class ) )->toBeTrue();
} );
```

**`phpstan.neon`**:

```neon
includes:
	- vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
	level: max
	paths:
		- classes
		- kntnt-autolink.php
		- autoloader.php
	bootstrapFiles:
		- vendor/php-stubs/wordpress-stubs/wordpress-stubs.php
```

Note: `szepeviktor/phpstan-wordpress` pulls in `php-stubs/wordpress-stubs` and may already register the stubs; if PHPStan reports "Bootstrap file already declared" or duplicate-symbol errors, remove the explicit `bootstrapFiles` line (the extension handles it) and re-run. That adjustment is in scope.

**Verify**:
- `composer test` → Pest runs, both smoke tests pass (2 passed).
- `composer analyse` → `[OK] No errors`.

### Step 7: Install the Kntnt coding standard into the repo

Invoke the `kntnt-code-skills:coding-standard` skill against this project directory with modules `php,wordpress` (the skill always adds `general`). This writes `agents.d/coding-standard/general.md`, `php.md`, `wordpress.md`, a `manifest.json`, ensures `AGENTS.md` References, and bridges `CLAUDE.md` with `@AGENTS.md`.

If the skill is not available to you, run its script directly (the plugin root is the directory containing `scripts/scaffold.py` for `kntnt-code-skills`; on this machine it is under `~/.claude/plugins/marketplaces/kntnt-code-skills`):

```bash
uv run "$KNTNT_CODE_SKILLS_ROOT/scripts/scaffold.py" \
    --project-dir . \
    --modules-dir "$KNTNT_CODE_SKILLS_ROOT/lib/coding-standard" \
    --include php,wordpress
```

If you cannot locate the skill or its script, STOP and report — do not hand-write the standard files; they are generated artifacts with content hashes.

**Verify**: `ls agents.d/coding-standard/` → `general.md php.md wordpress.md manifest.json`; `test -f AGENTS.md && test -f CLAUDE.md && echo OK` → `OK`.

### Step 8: Create the remaining empty plugin directories

```bash
mkdir -p js css languages migrations tests/Integration
touch js/.gitkeep css/.gitkeep languages/.gitkeep migrations/.gitkeep
```

**Verify**: `ls -d js css languages migrations tests/Integration` → all listed.

## Test plan

- One smoke test file, `tests/Unit/Smoke_Test.php`, with two cases: the toolchain runs, and the `Plugin` class autoloads. No domain assertions — domain tests belong to Plans 002+.
- Verification: `composer test` → 2 passed.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `php -l kntnt-autolink.php` and `php -l autoloader.php` and `php -l classes/Plugin.php` → "No syntax errors detected"
- [ ] `composer install` exits 0
- [ ] `composer test` exits 0, 2 tests pass
- [ ] `composer analyse` exits 0, "No errors"
- [ ] `agents.d/coding-standard/` contains `general.md`, `php.md`, `wordpress.md`, `manifest.json`; `AGENTS.md` and `CLAUDE.md` exist
- [ ] `git status` shows no files outside the in-scope list created by this plan
- [ ] `plans/README.md` status row for 001 updated to DONE

## STOP conditions

Stop and report back (do not improvise) if:

- `composer install` fails to resolve a dependency, OR the only resolvable version of a dev tool requires dropping below PHP 8.4 or an EOL PHP — report the resolver output; do not downgrade silently.
- The `kntnt-code-skills:coding-standard` skill and its `scaffold.py` script are both unavailable — report; the standard files must be generated, not hand-authored.
- `composer analyse` reports errors that are not about the WordPress-stubs bootstrap line (Step 6 covers that one) — report them.
- Any pre-existing `composer.json`, `classes/`, or `.git` was found at drift check.

## Maintenance notes

- The PSR-4 map in `composer.json` (`classes/`) and the runtime `autoloader.php` must stay in agreement: both map `\Kntnt\Autolink\X` → `classes/X.php`. If one moves, move the other.
- `phpstan.neon` `level: max` is the bar for all later code. If a later plan's executor lowers it, that is a regression a reviewer should reject.
- Pest's `tests/Pest.php` currently extends the plain `TestCase`. Plan 004 will add Brain Monkey `setUp`/`tearDown` for the repository unit tests; that wiring is added there, not here.
- The activation hook `require`s `install.php`, created in Plan 005. Until then, activating the plugin in a live WordPress would fatal on the missing file — acceptable, because the plugin is not deployed until the build is complete.
