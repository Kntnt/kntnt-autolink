@AGENTS.md

## Release configuration

Version locations (keep in sync):

- `kntnt-autolink.php` — the plugin header `Version:` line.
- `classes/Plugin.php` — the `Plugin::VERSION` constant (drives asset cache-busting and the version the Migrator stamps).
- `CHANGELOG.md` — the latest `## [x.y.z]` heading.

Build: stage the runtime files under a top-level `kntnt-autolink/` folder and zip it as `kntnt-autolink.zip` — `kntnt-autolink.php`, `autoloader.php`, `install.php`, `uninstall.php`, `classes/`, `css/`, `js/`, `languages/`, `README.md`, `LICENSE`, `CHANGELOG.md`. The `css/` and `js/` directories ship the admin stylesheets and scripts (the list-table/modal `css/admin.css` + `js/admin.js` and the chip inputs `css/chips.css` + `js/chips.js` + `js/terms.js`) and must be included. Exclude tests, dev tooling (`composer.*`, `phpstan.neon`, `phpunit.xml`, `vendor/`, `node_modules/`), `plans/`, the design doc, `agents.d/`, `AGENTS.md`, `CLAUDE.md`, the governance files (`CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`) and the empty `migrations/` directory.
