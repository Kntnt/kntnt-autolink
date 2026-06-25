# Contributing to Kntnt Autolink

Thank you for considering a contribution. Bug reports, feature requests, translations, documentation fixes and pull requests are all welcome.

By taking part you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Reporting bugs and requesting features

Please [open an issue](https://github.com/Kntnt/kntnt-autolink/issues), and search the existing issues first to avoid duplicates. For a usage question rather than a defect, use [Discussions](https://github.com/Kntnt/kntnt-autolink/discussions). To report a security vulnerability, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## Setting up a development environment

```bash
git clone https://github.com/Kntnt/kntnt-autolink.git
cd kntnt-autolink
composer install
```

The integration suite additionally needs Node.js with `npx`, which it uses to run WordPress Playground.

## Coding standard

The project follows the Kntnt coding standard recorded in `AGENTS.md` and `agents.d/`. In short: `declare( strict_types = 1 )` in every file, WordPress-flavour formatting (tabs, padded parentheses, `$snake_case`, `Pascal_Snake_Case` class names), `[ ... ]` array literals with trailing commas, one class per file, a PHPDoc block on every file, class, method and property, and English source strings wrapped for translation with the `kntnt-autolink` text domain.

The matching engine (`Linker`, `Ruleset`, `Keyword`) must stay free of WordPress calls so it can be unit-tested without a bootstrap. Keep WordPress concerns in the glue.

## Before you open a pull request

Run the full check suite and make sure it is green:

```bash
composer test                 # Pest unit suite
composer analyse              # PHPStan at level max
bash tests/Integration/run.sh # WordPress Playground end-to-end
```

New behaviour comes with tests, written test-first where practical. Do not weaken a test, a runtime guard or the static-analysis level to make a change pass.

## Pull request checklist

- The change is focused, and its purpose is clear from the description.
- Tests cover the new behaviour and the whole suite passes.
- `composer analyse` reports no errors.
- User-facing strings are translatable, and `languages/kntnt-autolink.pot` is updated when they change.
- The changelog records anything a user or integrator would want to know.
