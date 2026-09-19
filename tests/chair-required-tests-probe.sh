#!/usr/bin/env bash
# Probe: every command a contract declares must actually be run.
#
# The recurring defect, 2026-09-19/20: contracts list their full verification --
#
#   ## Required tests
#   - <the probe>
#   Also run, and they must still pass:
#   - npx playwright test ... --reporter=line
#   - php tests/star_swarm_visual_test.php
#
# and the harness runs ONLY the first. It even says so:
#
#   note: 3 command-shaped lines found in Required tests; the first is the probe.
#
# So the asset-version guard was declared in every star-swarm contract and executed by nobody. The
# lane forgot to bump ?v=, the harness reported PASS, and the chair caught it by hand every time --
# five times in one day. The whole point of a declared test is that something runs it.
#
# This probe builds a contract whose SECOND declared command fails. A harness that runs what the
# contract declares must refuse; a harness that runs only the first will happily pass.
#
# This probe FAILS today, which is the point.

set -uo pipefail

TASK="probe-required-tests"
TMP="$(mktemp -d)"
CONTRACT="$TMP/contract.md"
LOG="$TMP/log.txt"
trap 'rm -rf "$TMP"' EXIT

cat > "$CONTRACT" <<'CONTRACT'
# Required-tests probe

## Objective

A contract declaring two verification commands, the second of which fails. A harness that runs what
the contract declares must not report success.

## Architectural constraints

- none

## Files likely affected

- `tools/chair.php`

## Acceptance criteria

- both declared commands are executed: `true`

## Risks

- a harness that runs only the first declared command reports success while the rest never ran

## Required tests

- `bash -c 'exit 0'`
- `bash -c 'exit 1'`

## Forbidden changes

- `nothing-is-changed-by-this-probe/`
CONTRACT

php tools/chair.php plan --contract="$CONTRACT" --task="$TASK" > "$LOG" 2>&1
STATUS=$?

if [ "$STATUS" -ne 0 ]; then
    echo "SKIP: could not register the probe contract (plan exited $STATUS)."
    sed 's/^/      /' "$LOG"
    exit 2
fi

php tools/chair.php probe --task="$TASK" >> "$LOG" 2>&1
STATUS=$?

if [ "$STATUS" -eq 0 ]; then
    echo "FAIL: a contract whose SECOND required test fails was reported as passing."
    echo "      The harness ran only the first declared command; the rest were ignored."
    echo "      That is why a declared version guard never ran and a lane's stale asset"
    echo "      version reached PASS five times in one day."
    echo "      --- output ---"
    sed 's/^/      /' "$LOG"
    exit 1
fi

echo "PASS: the harness runs every command the contract declares."
exit 0
