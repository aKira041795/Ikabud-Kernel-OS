#!/usr/bin/env bash
#
# chair-duty — the standing check. Runs whether or not anyone remembers to ask.
#
# WHY THIS EXISTS
#
#   Every other harness check is a CAPABILITY: it works when invoked, and the only party that invokes
#   is the chair — which is also the party that stops. Measured 2026-09-20: four runs died in flight
#   and sat unnoticed for 16 to 23 hours, not because a detector failed but because nothing was on duty
#   in the interval where a process can die. The ledger held the evidence the whole time; nobody read it.
#
#   The chair has no perception and no continuity — it exists only while invoked. So the observer cannot
#   be the chair. This script is the duty: a thing that runs anyway.
#
# WHAT IT DOES
#
#   Evaluates the stop invariant, compares it with the last recorded verdict, and PUSHES to the director
#   only when the verdict CHANGES. That last part matters: a repeating alarm for an unchanged condition
#   trains its reader to ignore it, which is precisely the failure this harness keeps fixing — a warning
#   that is always present stops being read. Idempotent, safe to run as often as you like.
#
# WHAT IT CANNOT SEE  (the honest limit, and it is not a code problem)
#
#   `continue-check` reads the plan's obligations plus the ledger's open runs. It is therefore only as
#   complete as the plan: work that was never written down cannot be seen by it, by cron, or by anything
#   else. Writing an obligation down at the moment it is identified is the chair's duty, not this
#   script's. A detected stall needs a written-down obligation; a written-down obligation needs the chair.
#
# INSTALLING THE DUTY  (chair-owned config — deliberately not committed anywhere automatic)
#
#   */10 * * * *  cd /var/www/html/ikabudsix && bash tools/chair-duty.sh >> storage/logs/chair-duty.log 2>&1
#
# USAGE
#
#   bash tools/chair-duty.sh           evaluate; push only if the verdict changed
#   bash tools/chair-duty.sh --force   evaluate and push regardless (use when testing the channel)

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_DIR="$ROOT/storage/private/chair"
VERDICT_FILE="$STATE_DIR/duty.verdict"
OUT="$STATE_DIR/duty.last"

mkdir -p "$STATE_DIR"

php "$ROOT/tools/chair.php" continue-check > "$OUT" 2>&1
EXIT=$?

# The signature is the verdict, not the timestamp: the exit code plus the lines that name what is wrong.
# Identical signature => identical condition => stay silent.
SIGNATURE="$(
  printf 'exit=%s\n' "$EXIT"
  grep -E 'OPEN |STRANDED|CHAIR |A run is unfinished|must NOT idle' "$OUT" | head -12
)"

if [ "${1:-}" != "--force" ] && [ -f "$VERDICT_FILE" ] && [ "$SIGNATURE" = "$(cat "$VERDICT_FILE")" ]; then
  echo "chair-duty: verdict unchanged (exit $EXIT) — not pushing."
  exit 0
fi

if [ "$EXIT" = "0" ]; then
  MESSAGE="chair-duty: might idle. No chair-actionable obligation remains."
  STATUS="done"
else
  DETAIL="$(grep -E 'OPEN |STRANDED|A run is unfinished|must NOT idle' "$OUT" | head -5 | tr '\n' ' ' | tr -s ' ')"
  MESSAGE="chair-duty: must NOT idle (verdict exit $EXIT). ${DETAIL}"
  STATUS="blocked"
fi

# The push channel. `harpp status` CANNOT deliver: the server requires a conversation_id and the CLI
# exposes no option for one, so every payload fails with a bare exit 1 and no explanation — measured
# 2026-09-20 across every --status value. `harpp msg send` exposes --conversation-id and its errors are
# legible: "conversation_id is required; only the owner can start new conversations"
# (422, conversation_required).
#
# Only the owner can create a conversation, so the id is CONFIGURATION. This script must not invent one
# — and must not claim delivery without it.
CONVERSATION="${CHAIR_DUTY_CONVERSATION:-}"

if [ -z "$CONVERSATION" ]; then
  echo "DELIVERY: local-only — director NOT notified"
  echo "  reason: no conversation configured, and only the owner can create one."
  echo "  once the owner names a conversation: CHAIR_DUTY_CONVERSATION=<id> bash tools/chair-duty.sh --force"
  exit 4
fi

if harpp msg send --conversation-id "$CONVERSATION" --body "$MESSAGE" > /dev/null 2>&1; then
  printf '%s' "$SIGNATURE" > "$VERDICT_FILE"
  echo "chair-duty: pushed to the director ($STATUS)."
  exit 0
fi

# No silent non-delivery: a failed push is a failed duty, and it says so out loud with the retry.
echo "DELIVERY: local-only — director NOT notified"
echo "retry: harpp msg send --conversation-id $CONVERSATION --body \"$MESSAGE\""
exit 4
