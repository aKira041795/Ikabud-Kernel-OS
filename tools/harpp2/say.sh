#!/usr/bin/env bash
# tools/harpp2/say.sh — ONE message, TWO surfaces. Source this file, then call say().
#
# WHY (director, 2026-09-16): "or, a terminal message here, at VSCode". When the owner is at his
# workstation the harness must speak where he is already looking — a terminal inside the editor —
# not into a service he has to go and open. HARPP stays for when he is away; that is its purpose.
#
# Surfaces, both written every time, so a message cannot be missed by being on the wrong one:
#   1. .ai/inbox.log — append-only, tailed live in a VS Code terminal:  tail -F .ai/inbox.log
#   2. stdout        — so a foreground run shows it too.
# HARPP is a THIRD surface and it is the CALLER's job: say() never sends, so it stays usable from a
# context where delivery is not wanted (a test, a dry run).
#
# usage: say "<TITLE>" ["<line>" ...]

: "${SAY_INBOX:=$PWD/.ai/inbox.log}"

say() {
    local title="${1:-}"; shift || true
    mkdir -p "$(dirname "$SAY_INBOX")" 2>/dev/null || true
    {
        printf '\n\033[1;7m %s \033[0m %s\n' "$title" "$(date -Is)"
        local line
        for line in "$@"; do printf '   %s\n' "$line"; done
    } >> "$SAY_INBOX" 2>/dev/null || true
    printf '\n\033[1;7m %s \033[0m %s\n' "$title" "$(date -Is)"
    local line
    for line in "$@"; do printf '   %s\n' "$line"; done
}
