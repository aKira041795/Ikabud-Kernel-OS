#!/usr/bin/env bash
# tools/harpp2/notify_acceptance_test.sh
#
# Chair-authored gate. Written BEFORE the fix, and it must fail against the current chain.sh.
#
# THE DEFECT (measured 2026-09-18)
# `tools/harpp2/chain.sh` notify() computes the acceptance line from `$objective_file` at line 33, but only
# assigns `objective_file` at line 41:
#
#     local acceptance; acceptance="$(grep -E '^\$ ' "$objective_file" ...)"     # line 33 — not yet set
#     ...
#     local objective_file="$objective"                                          # line 41 — too late
#
# Under `set -u` the expansion happens in the command-substitution subshell, so the parent survives, the
# substitution yields nothing, and every owner notification has reported:
#
#     acceptance: (none declared in the objective)
#
# while the objectives it names declare four gates. Evidence in the product's own inbox:
#     grep -n "acceptance:" .ai/inbox.log | tail -3      →  "(none declared in the objective)" ×3
#
# A notification that lies about what was gated is worse than no notification: it is the surface the owner
# trusts when the harness is away.
#
# WHAT THIS TEST ASSERTS (the same user-observable outcome a fix must preserve)
# Given an objective file that declares gates, the message notify() emits names those gates.
# It does not care HOW the fix is made — only that the message becomes true.
#
# usage: bash tools/harpp2/notify_acceptance_test.sh
# exit:  0 when the acceptance line names the declared gates, 1 otherwise.

set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CHAIN="$REPO/tools/harpp2/chain.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ── fixture: an objective that declares two gates, exactly like the real ones ────────────────────────
OBJECTIVE="$WORK/fixture-item.md"
cat > "$OBJECTIVE" <<'MD'
# OBJECTIVE — fixture

## Acceptance

```
$ php tests/alpha_gate_test.php
$ bash tools/harpp2/stability.sh tests/browser/beta.spec.ts 10
```
MD

printf '{"status":"escalated","reason":"fixture reason"}\n' > "$WORK/fixture-item.json"

# ── drive the REAL notify() from the REAL chain.sh, with its dependencies stubbed ────────────────────
DIR="$WORK"
LOG="$WORK/chain.log"
REPO_STUB="$WORK"
CONVERSATION=1
HARPP2_NOTIFY=0          # never contact HARPP from a test
SAID="$WORK/said.txt"
say() { printf '%s\n' "$*" >> "$SAID"; }      # stub for say.sh
log() { printf '[chain] %s\n' "$*" >> "$LOG"; }
slug_of() { basename "$1" .md; }

# Extract the two functions verbatim, so the test exercises the shipped code and not a copy of it.
eval "$(awk '/^slug_of\(\)/,/^}/' "$CHAIN")"
eval "$(awk '/^notify\(\)/,/^}/' "$CHAIN")"

if ! declare -F notify >/dev/null; then
    echo "FAIL: notify() could not be extracted from $CHAIN — has it been renamed?" >&2
    exit 1
fi

notify "$OBJECTIVE" verified >/dev/null 2>&1

MESSAGE="$(cat "$SAID" 2>/dev/null || true)"
printf 'emitted message:\n%s\n\n' "${MESSAGE:-(nothing)}"

# ── assertions ───────────────────────────────────────────────────────────────────────────────────────
failed=0
if printf '%s' "$MESSAGE" | grep -q 'alpha_gate_test.php'; then
    echo "  [PASS] the acceptance line names the first declared gate"
else
    echo "  [FAIL] the acceptance line does NOT name the first declared gate"
    failed=1
fi
if printf '%s' "$MESSAGE" | grep -q 'stability.sh tests/browser/beta.spec.ts 10'; then
    echo "  [PASS] the acceptance line names the second declared gate"
else
    echo "  [FAIL] the acceptance line does NOT name the second declared gate"
    failed=1
fi
if printf '%s' "$MESSAGE" | grep -q '(none declared in the objective)'; then
    echo "  [FAIL] the message still claims the objective declares no gates"
    failed=1
else
    echo "  [PASS] the message no longer claims the objective declares no gates"
fi

if [ "$failed" -eq 0 ]; then
    echo "  NOTIFY ACCEPTANCE PASS"
    exit 0
fi
echo "  NOTIFY ACCEPTANCE FAIL"
exit 1
