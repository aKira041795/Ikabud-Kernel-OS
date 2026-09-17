#!/usr/bin/env bash
# tools/harpp2/status.sh — "what is happening now?" in ONE command.
#
# WHY THIS EXISTS (CD-74 / lesson L6, 2026-09-16): iteration 3b escalated at 14:23:51 and delivered
# its notice to the terminal inbox twice and to HARPP once — and nothing woke the chair. The tree sat
# idle for 4h15m until the director asked "what's happening now?". A verdict that reaches nobody costs
# the same as no verdict; this command is how "nobody" gets an answer without asking a human.
#
# Read-only. Answers four questions in order: is anything running · does anything await the chair ·
# what was the last project verdict · what state is every item in.
#
# usage: tools/harpp2/status.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"; cd "$ROOT"
DIR="tools/harpp2"

echo "=== HARPP status · $(date -Is) ==="

echo
echo "-- running --"
running="$(ps -eo pid,etime,args | grep -E 'chain\.sh|harpp2\.php run|dispatch\.sh|[p]i -p' | grep -v grep | head -6)"
if [ -n "$running" ]; then
    printf '%s\n' "$running" | awk '{printf "  pid %-9s up %-10s %s\n", $1, $2, substr($0, index($0, $3), 72)}'
else
    echo "  (idle — nothing dispatched)"
fi

echo
echo "-- awaiting the chair --"
awaiting=0
for f in "$DIR"/escalations/*.md; do
    [ -e "$f" ] || continue
    case "$f" in *.resolved*) continue;; esac   # a closure marker is not an open escalation
    slug="$(basename "$f")"
    slug="${slug%-????????-??????.md}"
    # A closed item must leave this list, or the list trains its reader to ignore it (L6).
    resolved="$(ls "$DIR"/escalations/ 2>/dev/null | grep -c "^${slug}.*\.resolved")"
    [ "${resolved:-0}" -gt 0 ] && continue
    state="$DIR/state/${slug}.json"
    item_state="$(python3 -c "import json;print(json.load(open('$state')).get('status'))" 2>/dev/null || echo unknown)"
    if [ "$item_state" != "verified" ]; then
        printf '  %-32s %s (%s)\n' "$slug" "$(basename "$f")" "$item_state"
        awaiting=$((awaiting + 1))
    fi
done
[ "$awaiting" -eq 0 ] && echo "  (none — every escalated item has since been verified)"

echo
echo "-- last project verdict --"
python3 - <<'PY'
import glob, json, os
files = sorted(glob.glob('.ai/e2e/*.json'))
if not files:
    print('  (no project E2E verdict recorded yet)')
else:
    for f in files[-2:]:
        try:
            d = json.load(open(f))
        except Exception:
            print(f'  (unreadable: {f})'); continue
        print(f"  {d.get('project')}: {d.get('verdict')} {d.get('gates_passed')}/{d.get('gates_total')} gates"
              f" · {os.path.basename(f)}")
PY

echo
echo "-- item states --"
for f in "$DIR"/state/*.json; do
    [ -e "$f" ] || continue
    printf '  %-34s ' "$(basename "$f" .json)"
    python3 -c "import json;d=json.load(open('$f'));print(d.get('status'), '| verified', d.get('verified'), '| no_progress', d.get('no_progress'))" 2>/dev/null || echo "(unreadable)"
done

echo
echo "-- chain tail --"
tail -4 "$DIR/runs/chain.log" 2>/dev/null | cut -c1-150 | sed 's/^/  /' || echo "  (no chain log)"

echo
echo "-- newest inbox message (the surface the owner reads) --"
tail -3 .ai/inbox.log 2>/dev/null | sed 's/^/  /' || echo "  (no inbox log)"
