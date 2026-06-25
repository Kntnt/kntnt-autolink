# Integration tests

End-to-end tests that boot a real WordPress on [WordPress Playground](https://wordpress.github.io/wordpress-playground/) (WASM PHP + SQLite, no server to install) with this plugin mounted and activated, then assert its behaviour over HTTP.

## Prerequisites

Node.js ≥ 20 with `npx`. The runner downloads `@wp-playground/cli` on first use and caches it. No database or web server is required.

## Running

```bash
bash tests/Integration/run.sh
```

A successful run ends with `ALL INTEGRATION TESTS PASSED` and exit code 0. Override the port with `KNTNT_AUTOLINK_PORT=9500 bash tests/Integration/run.sh` if 9412 is taken.

## What is covered

`run.sh` boots WordPress at PHP 8.4, mounts the repository as the plugin, runs `blueprint.json` (which activates the plugin and runs `seed.php`), fetches the rendered front page, and asserts:

- **Scenario 1 — `the_content` end-to-end.** A page contains the keyword `autolink` inside an `<h2>` and inside a `<p>`. After rendering through the full `the_content` pipeline (`wpautop`/`wptexturize` at priority 10, then this plugin at priority 20), the paragraph occurrence is linked as `<a class="kntnt-autolink" href="https://example.com/target">autolink</a>` and the heading occurrence is not — exactly one auto-link on the page.
- **Scenario 2 — capability gating.** In a real role context, the editor role (which gains the capability on activation) can manage keywords but not the structural rules, while the administrator can do both. The seed records this as `CAPCHECK ek=1 eo=0 ak=1 ao=1 ENDCAP`, which the runner asserts. The admin-page request handling (capability-before-nonce, sanitised dispatch) is covered in depth by the unit suite (`tests/Unit/Tools_Page_Test.php`).

The seed publishes the test page as the site's front page so it renders deterministically at `/` (no permalink or post-id guessing).

## PHP version

The plugin's hard floor is PHP 8.4. Playground supports 8.4 directly (`--php 8.4`), so the version guard in `kntnt-autolink.php` passes and the plugin activates normally. Do not lower that guard to satisfy any environment.

## Pinned CLI version

The runner pins `@wp-playground/cli@2.0.22`. The current 3.x line throws `EBADF` file-lock errors under the WASM runtime on macOS/arm64, which makes the server return empty response bodies regardless of the plugin under test — an environmental defect in that CLI build, not in the plugin. 2.0.22 boots cleanly and serves correctly. Re-evaluate the pin when a newer 3.x build resolves the lock-manager issue.
