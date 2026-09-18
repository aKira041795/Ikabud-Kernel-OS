#!/usr/bin/env bash
# Additive DeepSeek Harness executor for harpp2 and the bounded probe mode.
# Usage:
#   dispatch-dsh.sh <name> <prompt> <answer-file>
#   dispatch-dsh.sh <name> <model> <thinking> <brief>

set -uo pipefail

if [ "$#" -ne 3 ] && [ "$#" -ne 4 ]; then
    echo "usage: dispatch-dsh.sh <name> <prompt> <answer-file> | <name> <model> <thinking> <brief>" >&2
    exit 2
fi

NAME="$1"
MODE="probe"
MODEL=""
THINKING=""
BRIEF=""
PROMPT=""
ANSWER_ARG=""
PERMISSION_MODE="read-only"
TIMEOUT_SECONDS=180
if [ "$#" -eq 3 ]; then
    PROMPT="$2"
    ANSWER_ARG="$3"
else
    MODE="harpp2"
    MODEL="$2"
    THINKING="$3"
    BRIEF="$4"
    PERMISSION_MODE="workspace-write"
    TIMEOUT_SECONDS="${DSH_TIMEOUT_SECONDS:-1500}"
    if ! [[ "$TIMEOUT_SECONDS" =~ ^[1-9][0-9]*$ ]]; then
        echo "[harpp2:dsh] BLOCKED: DSH_TIMEOUT_SECONDS must be a positive integer" >&2
        exit 4
    fi
fi

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE="$(cd "$DIR/../.." && pwd)"
SPIKE_DIR="$WORKSPACE/tools/dev/dsh-spike"
DSH_HOME="$SPIKE_DIR/home"
DSH_JS="$DSH_HOME/node_modules/@deepseek-ai/dsh/lib/bin.js"
AUTH_FILE="${HOME:?HOME is required}/.pi/agent/auth.json"
JOURNAL="$DIR/runs/journal.jsonl"
if [ "$MODE" = "harpp2" ]; then
    LOG="$DIR/runs/${NAME}.log"
else
    LOG="$DIR/runs/${NAME}.dsh.log"
fi
LOCK="$DIR/runs/.lane.lock"
mkdir -p "$DIR/runs"

json_event() {
    EVENT="$1" EVENT_NAME="$NAME" EVENT_JOURNAL="$JOURNAL" \
        EVENT_EXIT="${2:-}" EVENT_LOG="${3:-}" EVENT_MODE="$MODE" \
        EVENT_TIMEOUT="$TIMEOUT_SECONDS" EVENT_PERMISSION="$PERMISSION_MODE" \
        EVENT_MODEL="$MODEL" EVENT_THINKING="$THINKING" EVENT_BRIEF="$BRIEF" \
        EVENT_RESULT="${4:-}" python3 - <<'PY'
import datetime, json, os
row = {
    "event": os.environ["EVENT"],
    "name": os.environ["EVENT_NAME"],
    "runtime": "dsh",
    "mode": os.environ["EVENT_MODE"],
    "permission_mode": os.environ["EVENT_PERMISSION"],
    "timeout_seconds": int(os.environ["EVENT_TIMEOUT"]),
    "at": datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
}
for source, target in (("EVENT_MODEL", "model"), ("EVENT_THINKING", "thinking"), ("EVENT_BRIEF", "brief")):
    if os.environ.get(source):
        row[target] = os.environ[source]
if os.environ.get("EVENT_EXIT"):
    row["exit"] = int(os.environ["EVENT_EXIT"])
if os.environ.get("EVENT_LOG"):
    row["log"] = os.environ["EVENT_LOG"]
if os.environ.get("EVENT_RESULT"):
    row["result"] = os.environ["EVENT_RESULT"]
with open(os.environ["EVENT_JOURNAL"], "a", encoding="utf-8") as stream:
    stream.write(json.dumps(row, separators=(",", ":")) + "\n")
PY
}

# A lock held by this process or one of its ancestors is this harness's own
# lane, not a competing writer. Otherwise fail closed, exactly as dispatch.sh
# does for a live competing lane.
SELF_PIDS=" $$ "
_walk="$$"
while [ "${_walk:-1}" -gt 1 ] 2>/dev/null; do
    _walk="$(ps -o ppid= -p "$_walk" 2>/dev/null | tr -d ' ')"
    [ -n "$_walk" ] || break
    SELF_PIDS="$SELF_PIDS$_walk "
done
OWNS_LOCK=0
if [ -f "$LOCK" ]; then
    HOLDER="$(cat "$LOCK" 2>/dev/null || true)"
    if [ -n "$HOLDER" ] && kill -0 "$HOLDER" 2>/dev/null; then
        case "$SELF_PIDS" in
            *" $HOLDER "*) : ;; # nested in the lane that owns this lock
            *)
                echo "[harpp2:dsh] REFUSED: a lane is already running (pid $HOLDER)." >&2
                exit 3
                ;;
        esac
    else
        rm -f "$LOCK"
    fi
fi
if [ ! -f "$LOCK" ]; then
    printf '%d' "$$" > "$LOCK"
    OWNS_LOCK=1
fi
cleanup() {
    rm -f "${TMP_ANSWER:-}"
    if [ "$OWNS_LOCK" -eq 1 ]; then rm -f "$LOCK"; fi
}
trap cleanup EXIT

if [ ! -f "$DSH_JS" ]; then
    echo "[harpp2:dsh] BLOCKED: pinned dsh runtime is not installed at $DSH_JS" >&2
    exit 4
fi
if [ ! -r "$AUTH_FILE" ]; then
    echo "[harpp2:dsh] BLOCKED: credential store is not readable at ~/.pi/agent/auth.json" >&2
    exit 4
fi
if [ "$MODE" = "harpp2" ] && [ ! -r "$BRIEF" ]; then
    echo "[harpp2:dsh] BLOCKED: brief is not readable: $BRIEF" >&2
    exit 4
fi

NODE_BIN=""
for candidate in "$HOME/.local/node-v22.23.2-linux-x64/bin/node" "$(command -v node 2>/dev/null || true)"; do
    [ -x "$candidate" ] || continue
    if "$candidate" -e 'const [a,b]=process.versions.node.split(".").map(Number); process.exit(a>22||(a===22&&b>=19)?0:1)' 2>/dev/null; then
        NODE_BIN="$candidate"
        break
    fi
done
if [ -z "$NODE_BIN" ]; then
    echo "[harpp2:dsh] BLOCKED: dsh dependencies require Node >=22.19.0" >&2
    exit 4
fi

# Read the existing key directly into this process environment. It is never
# printed, passed as an argument, written to disk, or included in the journal.
DEEPSEEK_API_KEY="$(python3 - "$AUTH_FILE" <<'PY'
import json, sys
try:
    value = json.load(open(sys.argv[1], encoding="utf-8"))["deepseek"]["key"]
except Exception:
    raise SystemExit(2)
if not isinstance(value, str) or not value:
    raise SystemExit(3)
sys.stdout.write(value)
PY
)"
credential_rc=$?
if [ "$credential_rc" -ne 0 ]; then
    unset DEEPSEEK_API_KEY
    echo "[harpp2:dsh] BLOCKED: ~/.pi/agent/auth.json has no usable deepseek.key (reader exit $credential_rc)" >&2
    exit 4
fi
export DEEPSEEK_API_KEY
export DSH_HOME DSH_TELEMETRY_DISABLED=1 DSH_TELEMETRY_MODE=DISABLED
export DSH_PERMISSION_MODE="$PERMISSION_MODE"

if [ "$MODE" = "harpp2" ]; then
    PROMPT="You are the executor under the HARPP v2 constitution at tools/harpp2/CONSTITUTION.md — read it first.
Read the executor brief at $BRIEF and implement it exactly. Ordinary reversible engineering decisions are yours: make them. Do not ask questions. Work only in the writable scope declared by that brief. Never destroy data, weaken security or a test, add a runtime dependency, publish, push, or release. Report evidence, not claims. End with exactly the single-line HARPP2_RESULT record required by the brief."
    rm -f "$LOG"
    json_event start
    (
        cd "$WORKSPACE"
        timeout --signal=TERM "$TIMEOUT_SECONDS" "$NODE_BIN" "$DSH_JS" --profile headless "$PROMPT"
    ) >> "$LOG" 2>&1
    rc=$?
else
    case "$ANSWER_ARG" in
        /*) ANSWER="$ANSWER_ARG" ;;
        *) ANSWER="$WORKSPACE/$ANSWER_ARG" ;;
    esac
    mkdir -p "$(dirname "$ANSWER")"
    rm -f "$ANSWER" "$LOG"
    TMP_ANSWER="$(mktemp "${ANSWER}.tmp.XXXXXX")"
    json_event start
    (
        cd "$WORKSPACE"
        timeout --signal=TERM "$TIMEOUT_SECONDS" "$NODE_BIN" "$DSH_JS" --profile headless "$PROMPT"
    ) > "$TMP_ANSWER" 2> "$LOG"
    rc=$?
    if [ "$rc" -eq 0 ]; then
        mv "$TMP_ANSWER" "$ANSWER"
        TMP_ANSWER=""
    fi
fi
unset DEEPSEEK_API_KEY

if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then
    printf '[harpp2:dsh] TIMEOUT after %ss (exit=%d)\n' "$TIMEOUT_SECONDS" "$rc" | tee -a "$LOG" >&2
    json_event timeout "$rc" "$LOG" timeout
    result=timeout
elif [ "$rc" -eq 0 ]; then
    result=completed
else
    result=runtime_failure
fi
json_event finish "$rc" "$LOG" "$result"
if [ "$MODE" = "harpp2" ]; then
    printf '[harpp2:dsh] %s exit=%d log=%s timeout=%ss permission=%s\n' "$NAME" "$rc" "$LOG" "$TIMEOUT_SECONDS" "$PERMISSION_MODE"
else
    printf '[harpp2:dsh] %s exit=%d answer=%s log=%s timeout=%ss permission=%s\n' "$NAME" "$rc" "$ANSWER" "$LOG" "$TIMEOUT_SECONDS" "$PERMISSION_MODE"
fi
exit "$rc"
