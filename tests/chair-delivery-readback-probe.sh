#!/usr/bin/env bash
# Probe: a claimed delivery must be CORROBORATED, not believed.
#
# The defect this exists for, measured 2026-09-19: `chair.php decide --escalate` printed [DELIVERED],
# exited 0, and wrote `delivered: true` to the ledger -- and the decision never reached HARPP. An
# independent read-back of the queue found no such key. The harness had asked "did the writer say it
# succeeded?" when the only question that matters is "can I read it back?".
#
# The instrument is a STUB harpp on PATH that answers `{"ok": true}` and persists nothing. That is not
# a contrivance: it is exactly what the real bridge did. A harness that trusts the writer will call
# this a success; a harness that reads back will notice there is nothing to read.
#
# Expected: chair.php must NOT claim delivery against a writer that persisted nothing. It must print
# the undelivered line and exit 4.
#
# This probe FAILS today, which is the point.

set -uo pipefail

TASK="probe-delivery-readback"
STUB_DIR="$(mktemp -d)"
LOG="$(mktemp)"
trap 'rm -rf "$STUB_DIR" "$LOG"' EXIT

# A harpp that always claims success and records nothing.
cat > "$STUB_DIR/harpp" <<'STUB'
#!/bin/sh
echo '{"ok": true}'
exit 0
STUB
chmod +x "$STUB_DIR/harpp"

PATH="$STUB_DIR:$PATH" php tools/chair.php decide --escalate \
    --task="$TASK" \
    --title="Delivery read-back probe" \
    --options="a|Option A|does a|free|none|reversible; b|Option B|does b|dear|wide|not-reversible" \
    --recommend=a \
    --rationale="proving that a claim without a read-back is not a delivery" \
    > "$LOG" 2>&1
STATUS=$?

if grep -q '\[DELIVERED\]' "$LOG"; then
    echo "FAIL: the harness claimed DELIVERED against a writer that persisted nothing."
    echo "      A delivery claim must be corroborated by a read-back, not taken from the writer."
    echo "      --- output ---"
    sed 's/^/      /' "$LOG"
    exit 1
fi

if [ "$STATUS" -ne 4 ]; then
    echo "FAIL: expected exit 4 (filed locally, director not notified), got $STATUS."
    sed 's/^/      /' "$LOG"
    exit 1
fi

if ! grep -q 'director NOT notified' "$LOG"; then
    echo "FAIL: exited 4 without printing the undelivered line."
    sed 's/^/      /' "$LOG"
    exit 1
fi

echo "PASS: a writer that persisted nothing is not treated as a delivery."
exit 0
