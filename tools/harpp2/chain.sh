#!/usr/bin/env bash
#
# HARPP v2 — the item chain: one plan item, then the next, then the next.
#
# WHY THIS EXISTS (measured, not speculated): the driver declares `objective verified` when ONE item's check passes
# and then exits. Nothing picks up item 2. Observed twice on 2026-09-16 — run 2 stopped after the theme item, run 3
# will stop after A.1. Without a chain, "item after item" is the chair relaying, which is not autonomous completion.
#
# It also TRACKS each run as a HARPP job, so completion is reported to the owner instead of waiting to be noticed.
#
# Usage:  setsid bash tools/harpp2/chain.sh <objective> [<objective> ...] < /dev/null > /dev/null 2>&1 &
# Observe: tail -f tools/harpp2/runs/chain.log

set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$DIR/../.." && pwd)"
LOG="$DIR/runs/chain.log"
CONVERSATION="${HARPP2_CONVERSATION:-126}"   # 8 and 138 both return bridge 404; 126 is a live channel (proved 2026-09-16)
SAY_INBOX="$REPO/.ai/inbox.log"
# shellcheck source=tools/harpp2/say.sh
. "$DIR/say.sh"

log() { printf '[chain] %s %s\n' "$(date -Is)" "$*" >> "$LOG"; }

# Notify the owner through the MESSAGE transport. Measured 2026-09-16: the job monitor did not fire —
# three tracked jobs finished and stayed `running` with `reported_at: None`, and the watch daemon's log has
# no job lines at all, while the message path carries 144 delivery receipts. The director should never have
# to ask what happened. Advisory: unreachable HARPP never stops the work.
notify() {
    local objective="$1" status="$2"
    local slug; slug="$(slug_of "$objective")"
    local state="$DIR/state/${slug}.json"
    local reason; reason="$(python3 -c "import json;print(json.load(open('$state')).get('reason') or '')" 2>/dev/null || true)"
    local next_step="chain continues"
    [ "$status" = "verified" ] || next_step="executor promoted, then hand-off if it fails again"
    # The caller passes a slug; the acceptance lives in the objective FILE. Resolve the file FIRST: grepping the
    # unresolved name as if it were a path reported "(none declared in the objective)" for objectives that declare
    # four gates — a notification that lies.
    local objective_file="$objective"
    [ -f "$objective_file" ] || objective_file="$DIR/objectives/${objective}.md"
    # The acceptance is what the driver actually gated — read it from the objective, never derive it from the slug.
    local acceptance; acceptance="$(grep -E '^\$ ' "$objective_file" 2>/dev/null | sed 's/^\$ //' | paste -sd'|' - | sed 's/|/ | /g')"
    [ -n "$acceptance" ] || acceptance="(none declared in the objective)"
    # Terminal FIRST, and unconditionally: the owner is at his workstation, and this is the surface
    # that still works when HARPP is unreachable. HARPP below covers him being away.
    say "ITEM ${status}: ${slug}" "acceptance: ${acceptance}" "reason: ${reason:-none}" "next: ${next_step}"
    command -v harpp >/dev/null 2>&1 || { log "DELIVERY: local-only — director NOT notified via HARPP (no harpp CLI); terminal message written"; return 0; }
    [ "${HARPP2_NOTIFY:-1}" = "1" ] || return 0
    local out rc
    out="$(timeout 60 harpp msg send --conversation-id "$CONVERSATION" \
        --title "harpp2: ${slug} -> ${status}" \
        --body "objective: ${objective}
status: ${status}
reason: ${reason}
acceptance (what the driver gated): ${acceptance}
next: ${next_step}" 2>&1)"; rc=$?
    if [ "$rc" = "0" ]; then
        log "owner notified: ${slug} ${status} (message $(printf '%s' "$out" | grep -oE '"id": *[0-9]+' | grep -oE '[0-9]+' | tail -1))"
    else
        # Non-delivery is not advisory: the director must never be silently uninformed.
        log "DELIVERY: local-only — director NOT notified (harpp msg send exit ${rc})"
        log "retry: harpp msg send --conversation-id ${CONVERSATION} --title 'harpp2: ${slug} -> ${status}'"
    fi
}

slug_of() { basename "$1" .md; }

# A driver already running owns this tree; wait for it rather than becoming a second writer.
wait_for_free_tree() {
    while pgrep -f 'harpp2\.php run' >/dev/null 2>&1; do sleep 20; done
}

run_item() {
    local objective="$1"
    local slug; slug="$(slug_of "$objective")"
    local state="$DIR/state/${slug}.json"
    local out="$DIR/runs/${slug}.run.out"

    # Never re-run an item that already verified — a chain that repeats work is not progress.
    if [ -f "$state" ]; then
        local prior; prior="$(python3 -c "import json;print(json.load(open('$state')).get('status'))" 2>/dev/null || echo unknown)"
        if [ "$prior" = "verified" ]; then
            log "$objective already verified — skipping"
            return 0
        fi
    fi

    wait_for_free_tree
    log "starting $objective"
    php "$DIR/harpp2.php" run --objective="$DIR/objectives/${objective}.md" >> "$out" 2>&1 &
    local driver=$!

    # Track it, so completion reaches the owner (advisory: no HARPP, no notification, run continues).
    sleep 5
    local pid; pid="$(ps -eo pid,args | awk '/^ *[0-9]+ php .*harpp2\.php run/ {print $1}' | head -1)"
    if [ -n "${pid:-}" ] && command -v harpp >/dev/null 2>&1 && [ "${HARPP2_NOTIFY:-1}" = "1" ]; then
        timeout 60 harpp job track --pid "$pid" --model "${HARPP2_MODEL:-deepseek/deepseek-v4-flash}" \
            --task "harpp2 item: ${slug}" --conversation "$CONVERSATION" \
            --log "$out" --repo "$REPO" --timeout 10800 \
            --verify "grep -q '\"status\": \"verified\"' $state" >/dev/null 2>&1 \
            && log "tracked pid=$pid (harpp will report completion)" \
            || log "track failed (continuing; notification is advisory)"
    fi

    wait "$driver"
    local status; status="$(python3 -c "import json;print(json.load(open('$state')).get('status'))" 2>/dev/null || echo unknown)"
    log "$objective -> $status"
    notify "$objective" "$status"
    [ "$status" = "verified" ]
}

[ "$#" -ge 1 ] || { echo "usage: chain.sh <objective> [<objective> ...]" >&2; exit 2; }

# A non-verified item is NOT automatically a stop. The chair's doctrine (director, 2026-09-16):
# a stop is legitimate only when proceeding would destroy data, weaken security, or exceed authority.
# Everything else is a defect in the objective or the tooling, and defects are corrected — not obeyed.
# So: correct what is mechanically correctable (a different executor), resume the SAME item, and only
# hand off when the correction needs judgement — and then with the category and the proposed fix named.
# The ladder is a per-item CHOICE, not a constant: cheap-first is right for mechanical work (a declared-field
# rule, a census count), and wrong for design work — for the game's visual pass the director's guidance is that
# sol does this better, so starting on flash would spend an attempt to learn nothing.
#   HARPP2_LADDER="model:thinking,model:thinking"   e.g. "openai-codex/gpt-5.6-sol:medium,openai-codex/gpt-5.6-sol:high"
if [ -n "${HARPP2_LADDER:-}" ]; then
    ATTEMPT_MODELS=()
    ATTEMPT_THINKING=()
    IFS=',' read -r -a _ladder <<< "$HARPP2_LADDER"
    for _entry in "${_ladder[@]}"; do
        ATTEMPT_MODELS+=("${_entry%%:*}")
        ATTEMPT_THINKING+=("${_entry##*:}")
    done
else
    ATTEMPT_MODELS=("deepseek/deepseek-v4-flash" "openai-codex/gpt-5.6-sol")
    ATTEMPT_THINKING=("low" "medium")
fi

for objective in "$@"; do
    verified=0
    for i in "${!ATTEMPT_MODELS[@]}"; do
        export HARPP2_MODEL="${ATTEMPT_MODELS[$i]}"
        export HARPP2_THINKING="${ATTEMPT_THINKING[$i]}"
        log "$objective attempt $((i + 1))/${#ATTEMPT_MODELS[@]} lane=$HARPP2_MODEL/$HARPP2_THINKING"
        if run_item "$objective"; then verified=1; break; fi
        log "$objective NOT verified on $HARPP2_MODEL/$HARPP2_THINKING — correcting, not stopping"
    done

    if [ "$verified" = "1" ]; then
        log "$objective VERIFIED with lane=$HARPP2_MODEL/$HARPP2_THINKING — continuing"
        continue
    fi

    state="$DIR/state/$(slug_of "$objective").json"
    esc="$DIR/escalations/$objective-$(date +%Y%m%d-%H%M%S).md"
    {
        echo "# Hand-off — $objective not verified after ${#ATTEMPT_MODELS[@]} executors"
        echo
        echo "Every mechanically correctable cause was addressed first: the executor was promoted"
        echo "(flash/low → sol/medium) and the same item resumed. What is left needs a **chair correction**."
        echo
        echo "## What the driver recorded"
        echo '```json'
        cat "$state" 2>/dev/null || echo '{}'
        echo '```'
        echo
        echo "## Classify before doing anything"
        echo
        echo "| Category | Meaning | Correction |"
        echo "|---|---|---|"
        echo "| objective defect | the item, its acceptance command, or its scope was wrong | fix the objective, resume the SAME item |"
        echo "| executor defect | both lanes failed the same way | change model/brief, resume the SAME item |"
        echo "| harness defect | dispatch, lock, journal or driver behaviour blocked the work | fix the harness, resume the SAME item |"
        echo "| **real boundary** | proceeding would destroy data, weaken security, or exceed authority | **director decision — this is the only case that reaches him** |"
        echo
        echo "## Proposed correction"
        echo
        echo "<one step; the chair fills this in and resumes — the chain does not stop here by default>"
    } > "$esc"
    log "HAND-OFF: $objective needs a chair correction — $esc (NOT a director stop unless a boundary is named)"
    exit 1
done

log "ALL ITEMS VERIFIED"
