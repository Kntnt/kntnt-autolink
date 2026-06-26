#!/usr/bin/env bash
#
# End-to-end integration test on WordPress Playground.
#
# Boots a real WordPress (PHP 8.4 + SQLite, no server install) with this plugin
# mounted and activated, seeds a link group and a front page via the blueprint,
# fetches the rendered front page over HTTP, and asserts:
#
#   Scenario 1 (the_content end-to-end): the paragraph occurrence of the link
#     group's phrase is linked and the heading occurrence is not.
#   Scenario 2 (per-group policy end-to-end): a second group's own nofollow and
#     new-tab behaviour surfaces on its link as rel="nofollow noopener" and
#     target="_blank", proving the policy is per-group, not global.
#   Scenario 3 (capability gating): in a real role context, the editor role can
#     manage link groups but not the structural rules, while the administrator
#     can do both.
#   Scenario 4 (REST re-render in a non-admin context): the "render rows" route,
#     dispatched while wp-admin is not loaded, returns the real table body — plus
#     the total/per-page pagination metadata the admin JS reads to keep the
#     pagination chrome honest — without fataling on the admin-only
#     convert_to_screen(), the regression the table's server-side re-render depends on.
#   Scenario 5 (list search / sort / pagination end-to-end): the "render rows"
#     route honours the search (by phrase and by URL), sort (first phrase and group
#     cap) and page parameters, and a mutation re-renders the current view, proving
#     the query layer is wired through REST exactly as the admin JS relies on.
#   Scenario 6 (bulk REST route end-to-end): a POST to the literal /link-groups/bulk
#     resolves to the bulk handler (not update with id="bulk"), returns 200, and
#     actually mutates — bulk set-cap raises a group's cap and bulk delete removes
#     another.
#   Scenario 7 (Settings-API sanitiser end-to-end): the structural-rules sanitiser
#     options.php runs on save rejects a post type outside the registered public
#     set, parses the no-JS comma string for deny tags into a list, and coerces the
#     post cap to a positive integer — the saved option round-trips for the engine.
#   Scenario 8 (term-targeting end-to-end): the REST term-search route returns the
#     matching terms of a registered taxonomy, gated to manage_options and rejecting
#     an unregistered taxonomy with a 400; the settings sanitiser round-trips the
#     taxonomy => term-ids map; and the engine, through the real has_term, links only
#     a post carrying a selected term and of an enabled post type.
#
# Prerequisites: Node >= 20 with npx (uses @wp-playground/cli).
# Usage: bash tests/Integration/run.sh
#
# Note: the CLI version is pinned to 2.0.22. The 3.x line currently throws
# EBADF file-lock errors under the WASM runtime on macOS/arm64, returning empty
# response bodies regardless of the plugin (see tests/Integration/README.md).

set -uo pipefail

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../.." && pwd )"
PORT="${KNTNT_AUTOLINK_PORT:-9412}"
BASE="http://127.0.0.1:${PORT}"
CLI_VERSION="@wp-playground/cli@2.0.22"

PASS=0
FAIL=0
SERVER_PID=""
LOG="$( mktemp )"
OUT="$( mktemp )"

cleanup() {
	if [ -n "$SERVER_PID" ]; then
		pkill -P "$SERVER_PID" 2> /dev/null || true
		kill "$SERVER_PID" 2> /dev/null || true
	fi
	rm -f "$LOG" "$OUT"
}
trap cleanup EXIT

assert_contains() { # file needle label
	if grep -qF -- "$2" "$1"; then
		echo "PASS: $3"
		PASS=$(( PASS + 1 ))
	else
		echo "FAIL: $3 (missing: $2)"
		FAIL=$(( FAIL + 1 ))
	fi
}

assert_count() { # file needle expected label
	local n
	n=$( grep -oF -- "$2" "$1" | wc -l | tr -d ' ' )
	if [ "$n" = "$3" ]; then
		echo "PASS: $4 (count=$n)"
		PASS=$(( PASS + 1 ))
	else
		echo "FAIL: $4 (expected $3, got $n)"
		FAIL=$(( FAIL + 1 ))
	fi
}

echo "Booting WordPress Playground (PHP 8.4) with the plugin mounted..."
npx --yes "$CLI_VERSION" server \
	--php 8.4 \
	--port "$PORT" \
	--mount "${PLUGIN_DIR}:/wordpress/wp-content/plugins/kntnt-autolink" \
	--blueprint "${PLUGIN_DIR}/tests/Integration/blueprint.json" \
	> "$LOG" 2>&1 &
SERVER_PID=$!

# Wait until boot + blueprint finish (the CLI prints "WordPress is running").
ready=0
for _ in $( seq 1 150 ); do
	if grep -qa "WordPress is running" "$LOG"; then
		ready=1
		break
	fi
	if ! kill -0 "$SERVER_PID" 2> /dev/null; then
		echo "Server process exited before becoming ready. Last log lines:"
		grep -av "fcntl" "$LOG" | tail -30
		exit 1
	fi
	sleep 2
done

if [ "$ready" -ne 1 ]; then
	echo "Server did not become ready in time. Last log lines:"
	grep -av "fcntl" "$LOG" | tail -30
	exit 1
fi

# Fetch the rendered front page (follow the canonical redirect), with retries.
for _ in $( seq 1 10 ); do
	curl -fsSL "${BASE}/" -o "$OUT" 2> /dev/null && [ -s "$OUT" ] && break
	sleep 1
done

if [ ! -s "$OUT" ]; then
	echo "Could not fetch a non-empty front page from the Playground server."
	exit 1
fi

echo "---- Scenario 1: the_content end-to-end (deny-tags proven independent of the cap) ----"
assert_contains "$OUT" '<a class="kntnt-autolink" href="https://example.com/target">autolink</a>' "paragraph phrase is linked"
assert_contains "$OUT" '<h2>About autolink</h2>' "heading phrase stays unlinked verbatim, though the group cap (5) leaves linking budget"
assert_count "$OUT" 'href="https://example.com/target"' '1' "only the paragraph is linked: the heading is skipped by deny-tags, not by an exhausted cap"

echo "---- Scenario 2: per-group nofollow / new-tab end-to-end ----"
assert_contains "$OUT" '<a class="kntnt-autolink" rel="nofollow noopener" target="_blank" href="https://example.com/nofollow">nofollowme</a>' "the nofollow group emits rel=nofollow and opens a new tab, per group"

echo "---- Scenario 3: capability gating in a real role context ----"
assert_contains "$OUT" 'CAPCHECK ek=1 eo=0 ak=1 ao=1 ENDCAP' "editor manages link groups only; administrator manages link groups and rules"

echo "---- Scenario 4: REST table re-render in a non-admin context ----"
assert_contains "$OUT" 'RESTCHECK status=200 rows_ok=1 meta_ok=1 ENDREST' "render-rows route returns the real table body plus the total/per-page pagination metadata, without a convert_to_screen fatal"

echo "---- Scenario 5: list search / sort / pagination end-to-end ----"
assert_contains "$OUT" 'LISTCHECK searchphrase=1 searchurl=1 sortpage=1 phrasesort=1 mutation=1 ENDLIST' "render-rows route honours search, sort and page, and a mutation preserves the current view"

echo "---- Scenario 6: bulk REST route end-to-end ----"
assert_contains "$OUT" 'BULKCHECK status=200 setcap=1 delete=1 ENDBULK' "bulk route resolves and applies set-cap and delete over REST"

echo "---- Scenario 7: Settings-API sanitiser round-trip end-to-end ----"
assert_contains "$OUT" 'SETTINGSCHECK posttypes=1 denytags=1 cap=1 ENDSETTINGS' "saved settings round-trip: unregistered post types rejected, no-JS deny-tag string parsed, post cap coerced positive"

echo "---- Scenario 8: term-targeting route + sanitiser + engine end-to-end ----"
assert_contains "$OUT" 'TERMSCHECK route=1 badtax=1 gate=1 roundtrip=1 enginein=1 engineout=1 ENDTERMS' "term-search route returns terms for a registered taxonomy (gated to manage_options, rejecting an unknown taxonomy), the term map round-trips through the sanitiser, and the engine links only a post carrying a selected term"

echo "----"
echo "PASS=$PASS FAIL=$FAIL"
if [ "$FAIL" -eq 0 ]; then
	echo "ALL INTEGRATION TESTS PASSED"
	exit 0
else
	echo "INTEGRATION TESTS FAILED"
	exit 1
fi
