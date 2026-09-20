#!/usr/bin/env bash
# tools/harpp2/notify-desktop.sh — a DESKTOP surface for the harness, so the director stops having to ask.
#
# WHY (director, 2026-09-19): "can I have a visual notif when I am at my workstation so I don't always ask
# the status?" The harness already speaks on two surfaces — .ai/inbox.log (tailed in a VS Code terminal) and
# HARPP for when he is away — but both require him to LOOK. A verdict that lands silently while he is at the
# desk is a status update that failed to arrive.
#
# WHY IT TAILS THE INBOX RATHER THAN CHANGING THE CHAIN
# chain.sh already writes every verdict through say() into .ai/inbox.log, so the messages exist; only the
# surface is missing. Reading the existing surface means no second source of truth and no edit to a script a
# live run is executing (bash reads a running script lazily, so editing chain.sh mid-run can corrupt it).
#
# WHAT IT NOTIFIES
# The events worth interrupting a person for: a phase verified, a phase escalated, a hand-off needing a chair
# correction, and the end of the chain. Routine progress does NOT pop a window — a notifier that cries wolf
# gets muted, and then it is worth nothing.
#
# usage:  setsid bash tools/harpp2/notify-desktop.sh >/dev/null 2>&1 &
#   INBOX / CHAIN override the watched files; NOTIFY_FALLBACK is where a notification goes when no desktop is
#   reachable (a headless run must not silently drop it); NOTIFY_DRY_RUN=1 records without popping, which is
#   how this script is tested without spamming the director's screen.
#
# THE FALLBACK LOG LIVES OUTSIDE THE REPOSITORY, deliberately. It did not at first, and that mattered: a file
# created under .ai/ during a live run is an untracked change in the run's delta, which is attributed to the
# executor and can refuse an honest chunk for being "outside objective scope" (measured on p2, 2026-09-18).
# Nothing a watcher writes may land inside the tree a run is being judged on.
set -uo pipefail

REPO="${REPO:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
INBOX="${INBOX:-$REPO/.ai/inbox.log}"
CHAIN="${CHAIN:-$REPO/tools/harpp2/runs/chain.log}"
STATE_HOME="${XDG_STATE_HOME:-$HOME/.local/state}"
FALLBACK="${NOTIFY_FALLBACK:-$STATE_HOME/ikabud-harness-notifications.log}"
EVENTS='ITEM (verified|escalated):|-> (verified|escalated)|ALL ITEMS VERIFIED|HAND-OFF|NEEDS_CHAIR'

notify() {
    local title="$1" body="${2:-}"
    mkdir -p "$(dirname "$FALLBACK")" 2>/dev/null || true
    printf '%s %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$title" >>"$FALLBACK" 2>/dev/null || true
    if [ "${NOTIFY_DRY_RUN:-0}" = "1" ]; then
        printf 'DRY-RUN: %s | %s\n' "$title" "$body"
        return 0
    fi
    # A missing desktop is not an error: the fallback line above already recorded it.
    if command -v notify-send >/dev/null 2>&1 && [ -n "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]; then
        notify-send -a "Ikabud harness" -u normal -h string:x-canonical-private-synchronous:ikabud-harness \
            "$title" "$body" >/dev/null 2>&1 || true
    fi
}

[ -f "$INBOX" ] || : >"$INBOX" 2>/dev/null || true
[ -f "$CHAIN" ] || : >"$CHAIN" 2>/dev/null || true

notify "harness watcher armed" "notifying: verified | escalated | hand-off | chain complete"

# -q keeps tail from printing "==> file <==" headers when two files are followed.
# -n0 means "only what happens from now on": restarting the watcher must not replay history as fresh alerts.
tail -q -n0 -F "$INBOX" "$CHAIN" 2>/dev/null \
    | grep --line-buffered -E "$EVENTS" \
    | while IFS= read -r line; do
        clean="$(printf '%s' "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        case "$clean" in
            *ALL\ ITEMS\ VERIFIED*) notify "Star Swarm: all phases verified" "$clean" ;;
            *HAND-OFF*|*NEEDS_CHAIR*) notify "Star Swarm: needs your chair correction" "$clean" ;;
            *escalated*) notify "Star Swarm: phase escalated" "$clean" ;;
            *) notify "Star Swarm: phase verified" "$clean" ;;
        esac
    done
