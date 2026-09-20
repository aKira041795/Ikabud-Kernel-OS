#!/usr/bin/env bash
#
# HARPP v2 — the dispatcher, in full.
#
# It borrows exactly one thing from v1, because reality proved it: `pi -p` prints nothing until it finishes,
# so an idle-detecting terminal backgrounds it, and the detach kills `pi` with EBADF. `setsid` + `script -qec`
# (a pseudo-terminal, its own session) fixes that, and `-e` returns the child's real exit code.
#
# It records to an append-only journal. There is no ledger, no trust surface, no commit gate, no taxonomy,
# no preflight.
#
# One working tree, one writer. At-desk work must NOT depend on HARPP being reachable, so the local lock is
# authoritative and fails closed, while the away-mode check is advisory: if it cannot reach the service it
# warns and continues, because "HARPP is unreachable" is not a reason to stop working at the desk.
# Override a false positive with HARPP2_FORCE=1.
#
# Usage:
#   setsid bash tools/harpp2/dispatch.sh <name> <model> <thinking> <brief> < /dev/null > /dev/null 2>&1 &
# Then:
#   tail -f tools/harpp2/runs/<name>.log

set -uo pipefail

NAME="${1:?usage: dispatch.sh <name> <model> <thinking> <brief>}"
MODEL="${2:?model required}"
THINKING="${3:?thinking required}"
BRIEF="${4:?brief required}"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p "$DIR/runs"
JOURNAL="$DIR/runs/journal.jsonl"
LOG="$DIR/runs/${NAME}.log"
WORKSPACE="$(cd "$DIR/../.." && pwd)"

# ── One working tree, one writer ─────────────────────────────────────────────
# At the desk the harness runs directly; away from the desk HARPP's watch daemon drives the same workspace.
# They must never write to this tree at the same time, so:
#   1. the local lock is authoritative and fails closed (a second at-desk lane is refused);
#   2. the away-mode check is advisory (unreachable service = warn, do not stop, per the at-desk rule).
LOCK="$DIR/runs/.lane.lock"
if [ -f "$LOCK" ]; then
    HOLDER="$(cat "$LOCK" 2>/dev/null || true)"
    if [ -n "$HOLDER" ] && kill -0 "$HOLDER" 2>/dev/null; then
        echo "[harpp2] REFUSED: a lane is already running (pid $HOLDER). One working tree, one writer." >&2
        printf '{"event":"refused","name":"%s","reason":"lane_already_running","holder":%s,"at":"%s"}\n' \
            "$NAME" "$HOLDER" "$(date -Is)" >> "$JOURNAL"
        exit 3
    fi
    rm -f "$LOCK"
fi

# A job that is this harness's OWN run is not a competing writer. Without this exclusion, tracking a run
# (so its completion gets notified) makes that run refuse its own lanes — a self-inflicted deadlock, hit
# on 2026-09-16: the away-mode guard read the driver's own tracking job as another harness in the tree.
SELF_PIDS="$$"
_walk="$$"
while [ "${_walk:-1}" -gt 1 ] 2>/dev/null; do
    _walk=$(ps -o ppid= -p "$_walk" 2>/dev/null | tr -d ' ')
    [ -n "$_walk" ] || break
    SELF_PIDS="$SELF_PIDS $_walk"
done

if command -v harpp >/dev/null 2>&1; then
    AWAY="$(HARPP2_WS="$WORKSPACE" HARPP2_SELF_PIDS="$SELF_PIDS" timeout 20 harpp job list 2>/dev/null | python3 -c '
import json, sys, os
ws = os.environ.get("HARPP2_WS", "")
self_pids = set(str(os.environ.get("HARPP2_SELF_PIDS", "")).split())
try:
    d = json.load(sys.stdin)
except Exception:
    print("")
    raise SystemExit
for j in d.get("jobs", []):
    if str(j.get("status")) != "running" or str(j.get("repo")) != ws:
        continue
    # this dispatch, or anything it is nested inside, is not a competing writer
    if str(j.get("pid", "")) in self_pids:
        continue
    # nor is a job that names this harness: it IS this harness
    if "harpp2" in (str(j.get("task", "")) + str(j.get("log", ""))).lower():
        continue
    print(str(j.get("id", "?")) + "  " + str(j.get("task", "")))
' 2>/dev/null || true)"
    if [ -n "$AWAY" ]; then
        if [ "${HARPP2_FORCE:-}" = "1" ]; then
            echo "[harpp2] WARNING: HARPP reports a running away-mode job on this workspace; proceeding (HARPP2_FORCE=1)." >&2
        else
            echo "[harpp2] REFUSED: HARPP's away-mode harness is running a job on this workspace:" >&2
            echo "$AWAY" >&2
            echo "[harpp2] REFUSED: HARPP away-mode job is running on this workspace." >&2
            echo "[harpp2] Wait for it to finish, or re-run with HARPP2_FORCE=1." >&2
            printf '{"event":"refused","name":"%s","reason":"away_mode_job_running","holder":"harpp","at":"%s"}\n' \
                "$NAME" "$(date -Is)" >> "$JOURNAL"
            exit 3
        fi
    fi
fi

printf '%d' "$$" > "$LOCK"
trap 'rm -f "$LOCK"' EXIT

printf '{"event":"start","name":"%s","model":"%s","thinking":"%s","brief":"%s","at":"%s","pid":%d}\n' \
    "$NAME" "$MODEL" "$THINKING" "$BRIEF" "$(date -Is)" "$$" >> "$JOURNAL"

PROMPT="You are the executor under the HARPP v2 constitution at tools/harpp2/CONSTITUTION.md — read it first.
Read the brief at the path attached, and implement it exactly. Ordinary engineering decisions are yours: make them.
Do not ask for permission for a reversible decision, and do not ask questions at all: the owner may be present but he
is NOT in the process loop, and a question back to him is a defect.
Do not create governance artifacts: no measurement framework, pillar, census, taxonomy, contract file, or status
report about the process. Expect to be asked one question at the end: 'why couldn't you continue?' — answer it in one
paragraph, and name which of the three conditions stopped you (authority, boundary, irreversibility).
Report evidence, not claims: every claim must be a command you actually ran, with its real output beneath it."
if [ -f "$DIR/../../.ai/harpp2-judgement.md" ]; then
    PROMPT="$PROMPT
For continuation and prior blockers, also read .ai/harpp2-judgement.md."
fi

script -qec "pi -p -a --thinking $THINKING --model $MODEL --name $NAME @$BRIEF $(printf '%q' "$PROMPT")" "$LOG" >/dev/null 2>&1
EXIT_CODE=$?

printf '{"event":"finish","name":"%s","exit":%d,"at":"%s","log":"%s"}\n' \
    "$NAME" "$EXIT_CODE" "$(date -Is)" "$LOG" >> "$JOURNAL"

echo "[harpp2] $NAME exit=$EXIT_CODE log=$LOG"
