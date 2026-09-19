#!/usr/bin/env bash
# Probe: the submission must carry every field HARPP requires.
#
# The defect, measured 2026-09-19: every escalation was rejected with
#
#   HARPP bridge error 422: Unprocessable Entity
#   "Valid title, body, requested_decision, priority, source, and workbench_state are required."
#
# `deliverDecision()` sent five of the six. It never passed --workbench-state, and the bridge's
# `kw.get("workbench_state", default)` does not apply its default because the key arrives present
# holding None. Adding the flag turned the same call into {"ok": true, "data": {"decision_id": 111}}.
#
# The instrument is a STUB harpp that records the arguments it was handed and claims success. It
# asserts nothing about the queue, so this probe cannot put anything in front of the director.
#
# This probe FAILS today, which is the point.

set -uo pipefail

TASK="probe-submit-shape"
STUB_DIR="$(mktemp -d)"
ARGS_FILE="$STUB_DIR/args.txt"
LOG="$(mktemp)"
trap 'rm -rf "$STUB_DIR" "$LOG"' EXIT

# A harpp that records what it was asked to send.
cat > "$STUB_DIR/harpp" <<STUB
#!/bin/sh
echo "\$@" >> "$ARGS_FILE"
echo '{"ok": true, "data": {"decision_key": "stub"}}'
exit 0
STUB
chmod +x "$STUB_DIR/harpp"

PATH="$STUB_DIR:$PATH" php tools/chair.php decide --escalate \
    --task="$TASK" \
    --title="Submit shape probe" \
    --options="a|Option A|does a|free|none|reversible; b|Option B|does b|dear|wide|not-reversible" \
    --recommend=a \
    --rationale="asserting the request carries every field the server requires" \
    > "$LOG" 2>&1

if [ ! -s "$ARGS_FILE" ]; then
    echo "FAIL: nothing was submitted at all -- the stub was never called."
    sed 's/^/      /' "$LOG"
    exit 1
fi

if ! grep -q -- '--workbench-state' "$ARGS_FILE"; then
    echo "FAIL: the submission omits --workbench-state, which HARPP requires."
    echo "      The server answers 422 for this, and the director is never notified."
    echo "      --- submitted ---"
    sed 's/^/      /' "$ARGS_FILE"
    exit 1
fi

for field in --title --body --requested --priority --source; do
    if ! grep -q -- "$field" "$ARGS_FILE"; then
        echo "FAIL: the submission omits $field."
        sed 's/^/      /' "$ARGS_FILE"
        exit 1
    fi
done

echo "PASS: the submission carries all six fields the server requires."
exit 0
