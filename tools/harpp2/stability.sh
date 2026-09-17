#!/usr/bin/env bash
# tools/harpp2/stability.sh — make repeatability a GATE, not an aspiration.
#
# WHY THIS EXISTS (lesson L4, 2026-09-16/17). A driver that runs an acceptance once cannot tell a flaky probe from a
# missing feature: iteration 3b was recorded as `verified: 0, no_progress: 4` and escalated as `irreversibility` while
# every gate passed minutes later. The isolated cause was a probe that sampled a shot crossing its window once, racing
# the projectile. Polling fixed that assertion; the other probes still sample live animation once.
#
# So flakiness is measured here, by repetition, as a first-class result: "it passed when I tried it" is not evidence.
#
# usage: tools/harpp2/stability.sh <spec-path> [runs]     (default 10 runs)
# exit:  0 only when EVERY run passed
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"; cd "$ROOT"

spec="${1:?usage: stability.sh <spec-path> [runs]}"
runs="${2:-10}"
logs="${TMPDIR:-/tmp}/harpp2-stability"
mkdir -p "$logs"

echo "stability: ${spec} × ${runs}"
passed=0; failed=0
for i in $(seq 1 "$runs"); do
    if timeout 300 npx playwright test "$spec" >"$logs/run-$i.log" 2>&1; then
        passed=$((passed + 1))
        printf '  run %s/%s: pass\n' "$i" "$runs"
    else
        failed=$((failed + 1))
        printf '  run %s/%s: FAIL\n' "$i" "$runs"
        # Name the assertion, so a flake is never just "a failure".
        grep -E "^\s+Error:|Expected:|Received:" "$logs/run-$i.log" | head -4 | sed 's/^/      /'
        cp "$logs/run-$i.log" "$logs/first-failure.log" 2>/dev/null || true
    fi
done

echo "  => ${passed}/${runs} runs passed (failures: ${failed})"
if [ "$failed" -eq 0 ]; then
    echo "  STABILITY PASS"
    exit 0
fi
echo "  STABILITY FAIL — first failing run kept at ${logs}/first-failure.log"
exit 1
