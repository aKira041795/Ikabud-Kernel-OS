#!/usr/bin/env bash
#
# Dispatch a lane run so it survives the terminal harness backgrounding it.
#
# WHY THIS EXISTS
# ---------------
# `pi -p` prints nothing until it finishes. The terminal tool therefore observes no output,
# declares the command idle, and backgrounds it — which detaches stdio and kills `pi` with
# EBADF. On 2026-09-15 both dispatch attempts died exactly this way, on two different lanes
# (`deepseek/deepseek-v4-flash` and `openai-codex/gpt-5.6-sol`): each recorded `running` with a
# dead pid, a 0-byte log and `exit=NULL`. It is not a model problem, not a lane problem and not
# a `tail`-buffering problem — it is a tty problem.
#
# FIX: run `pi` under `script`, which allocates a pseudo-terminal, inside `setsid`, which gives
# it its own session so it is not killed when the spawning terminal detaches. The runner itself
# is launched detached, so the terminal command returns immediately and nothing is backgrounded
# mid-flight by the idle detector.
#
# Usage:
#   setsid bash .ai/dispatch-lane.sh <name> <model> <thinking> <contract> \
#       < /dev/null > /dev/null 2>&1 &
#
# Repair lineage (optional env vars, forwarded to `ai-run.php start`):
#   PREDECESSOR=<run-id> REPAIR_LEVEL=L2 APPROACH_CHANGE="<what changed>" \
#       setsid bash .ai/dispatch-lane.sh ...
#
# Example:
#   cd /var/www/html/ikabudsix
#   setsid bash .ai/dispatch-lane.sh theme-studio-policy-seed-sol \
#       openai-codex/gpt-5.6-sol medium .ai/theme-studio-policy-seed.contract.md \
#       < /dev/null > /dev/null 2>&1 &
#
# Observe:
#   php tools/ai-watch.php          # liveness — reports STALE if pi died, exit 3
#   tail -f .ai/runs/<name>.log     # the lane's own output
#
# Always run `php tools/ai-authority-preflight.php --contract=<contract>` first. A contract that
# will escalate at `finish` is not worth a dispatch.

set -uo pipefail

NAME="${1:?usage: dispatch-lane.sh <name> <model> <thinking> <contract> [justification]}"
MODEL="${2:?model required}"
THINKING="${3:?thinking required}"
CONTRACT="${4:?contract required}"
# Optional. Must appear verbatim in a contract acceptance/constraints line, or it grounds nothing.
JUSTIFICATION="${5:-}"

LOG=".ai/runs/${NAME}.log"
REPORT=".ai/runs/${NAME}.report.txt"

mkdir -p .ai/runs

PROMPT="Implement the attached contract exactly. Read it ALL before editing anything.
Diagnose the mechanism FIRST and prove which hypothesis is true before changing any code.
Do not edit the tenant database. Do not weaken or remove an existing assertion.
Run every Required test and report exact pass/fail/skip counts."

START_ARGS=(--contract="$CONTRACT" --lane="$MODEL" --name="$NAME" --log="$LOG" --report="$REPORT")
# Optional repair lineage: record WHAT was promoted and WHICH run it repairs, so the ledger
# shows the repair ladder rather than a chain of unexplained re-dispatches.
if [ "${PREDECESSOR:-}" != "" ]; then START_ARGS+=(--predecessor="$PREDECESSOR"); fi
if [ "${REPAIR_LEVEL:-}" != "" ]; then START_ARGS+=(--repair-level="$REPAIR_LEVEL"); fi
if [ "${APPROACH_CHANGE:-}" != "" ]; then START_ARGS+=(--approach-change="$APPROACH_CHANGE"); fi
php tools/ai-run.php start "${START_ARGS[@]}" >/dev/null 2>&1

# `script -qec` gives pi a real tty and returns the child's exit code (`-e`) — without a tty it
# dies on detach, and without `-e` the harness would record `script`'s status instead of pi's.
script -qec "pi -p -a --thinking $THINKING --model $MODEL --name $NAME @$CONTRACT $(printf '%q' "$PROMPT")" "$LOG" >/dev/null 2>&1
EXIT_CODE=$?

echo "[dispatch-lane] pi exited $EXIT_CODE" >> "$LOG"

FINISH_ARGS=(--id="$NAME" --exit="$EXIT_CODE" --log="$LOG" --report="$REPORT")
if [ "$JUSTIFICATION" != "" ]; then FINISH_ARGS+=(--justification="$JUSTIFICATION"); fi
php tools/ai-run.php finish "${FINISH_ARGS[@]}" >> "$LOG" 2>&1
