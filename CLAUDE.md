@AGENTS.md

## Release configuration

Version locations (keep in sync):

- `kntnt-autolink.php` — the plugin header `Version:` line.
- `CHANGELOG.md` — the latest `## [x.y.z]` heading.

Build: stage the runtime files under a top-level `kntnt-autolink/` folder and zip it as `kntnt-autolink.zip` — `kntnt-autolink.php`, `autoloader.php`, `install.php`, `uninstall.php`, `classes/`, `languages/`, `README.md`, `LICENSE`, `CHANGELOG.md`. Exclude tests, dev tooling (`composer.*`, `phpstan.neon`, `phpunit.xml`, `vendor/`, `node_modules/`), `plans/`, the design doc, `agents.d/`, `AGENTS.md`, `CLAUDE.md`, the governance files (`CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`) and the empty `js/`, `css/`, `migrations/` directories.
