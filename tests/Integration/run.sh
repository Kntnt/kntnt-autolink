#!/usr/bin/env bash
#
# End-to-end integration test on WordPress Playground.
#
# Boots a real WordPress (PHP 8.4 + SQLite, no server install) with this plugin
# mounted and activated, seeds a keyword and a front page via the blueprint,
# fetches the rendered front page over HTTP, and asserts:
#
#   Scenario 1 (the_content end-to-end): the paragraph occurrence of the keyword
#     is linked and the heading occurrence is not.
#   Scenario 2 (capability gating): in a real role context, the editor role can
#     manage keywords but not the structural rules, while the administrator can
#     do both.
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

echo "---- Scenario 1: the_content end-to-end ----"
assert_contains "$OUT" '<a class="kntnt-autolink" href="https://example.com/target">autolink</a>' "paragraph keyword is linked"
assert_count "$OUT" 'class="kntnt-autolink"' '1' "heading keyword is NOT linked (exactly one autolink on the page)"

echo "---- Scenario 2: capability gating in a real role context ----"
assert_contains "$OUT" 'CAPCHECK ek=1 eo=0 ak=1 ao=1 ENDCAP' "editor manages keywords only; administrator manages keywords and rules"

echo "----"
echo "PASS=$PASS FAIL=$FAIL"
if [ "$FAIL" -eq 0 ]; then
	echo "ALL INTEGRATION TESTS PASSED"
	exit 0
else
	echo "INTEGRATION TESTS FAILED"
	exit 1
fi
