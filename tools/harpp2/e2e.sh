#!/usr/bin/env bash
# tools/harpp2/e2e.sh — THE PROJECT COMPLETION GATE.
#
# Why this file exists (2026-09-16, the director's question):
#   "how do you send a message to me that the project has completed e2e?"
#   Until now: no mechanism existed. Only per-ITEM verdicts were sent, and those carry the worker's
#   claim about one slice. Nothing in the harness could decide "the project is done", so the director
#   had to ask — which is the defect this file repairs.
#
# The rule implemented here: completion is a VERDICT PRODUCED BY GATES, sent BY THE GATE, whose body
#   is the gates' exit codes. Never prose. Never the worker's word. Never the chair's memory — this
#   script either runs and is seen, or it does not run and nothing claims completion.
#
# usage: tools/harpp2/e2e.sh <project> [--no-send]
#   env: HARPP2_CONVERSATION (default 126) · HARPP2_NOTIFY=0 to suppress sending
# exit: 0 PASS · 1 FAIL · 2 no manifest · 3 no gates · 4 filed locally, NOT delivered
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"; cd "$ROOT"
SAY_INBOX="$ROOT/.ai/inbox.log"
# shellcheck source=tools/harpp2/say.sh
. "$ROOT/tools/harpp2/say.sh"
project="${1:?usage: e2e.sh <project> [--no-send]}"; shift || true
SEND=1; [ "${1:-}" = "--no-send" ] && SEND=0
[ "${HARPP2_NOTIFY:-1}" = "1" ] || SEND=0
CONVERSATION="${HARPP2_CONVERSATION:-126}"

manifest="tools/harpp2/projects/${project}.json"
[ -f "$manifest" ] || { echo "e2e: no manifest for '${project}' (${manifest})"; exit 2; }

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
START="$(date +%s)"
mkdir -p .ai/e2e
BUNDLE=".ai/e2e/${project}-${STAMP}.json"
RESULTS=".ai/e2e/${project}-${STAMP}.tsv"
: > "$RESULTS"

URL=""; ARTIFACT=""; GATES=()
while IFS=$'\t' read -r kind value; do
    case "$kind" in
        URL) URL="$value";;
        ARTIFACT) ARTIFACT="$value";;
        GATE) GATES+=("$value");;
    esac
done < <(python3 - "$manifest" <<'PY'
import json, sys
m = json.load(open(sys.argv[1]))
print("URL\t" + str(m.get("url", "")))
print("ARTIFACT\t" + str(m.get("artifact", "")))
# A milestone declares REQUIREMENTS (data, one gate each) and may add project-level gates.
# The flat `gates` list remains supported so existing project manifests keep working unchanged.
for r in m.get("requirements", []) or []:
    gate = r.get("gate")
    if gate:
        print("GATE\t" + str(gate))
for g in m.get("gates", []) or []:
    print("GATE\t" + g)
PY
)
[ "${#GATES[@]}" -gt 0 ] || { echo "e2e: ${project} declares no gates — nothing would be proven"; exit 3; }

echo "e2e ${project} · $(date -Is) · ${#GATES[@]} gates"
fail=0; n=0; passed=0
for cmd in "${GATES[@]}"; do
    n=$((n + 1))
    log="$(mktemp)"
    ( eval "$cmd" ) >"$log" 2>&1; rc=$?
    tail_out="$(tail -3 "$log" | tr '\n' ' ' | tr '\t' ' ' | cut -c1-220)"
    rm -f "$log"
    if [ "$rc" = "0" ]; then
        passed=$((passed + 1)); echo "  PASS [$rc] $cmd"
    else
        fail=1; echo "  FAIL [$rc] $cmd"; echo "        ${tail_out}"
    fi
    printf '%s\t%s\t%s\t%s\n' "$rc" "$cmd" "$tail_out" "$( [ "$rc" = 0 ] && echo PASS || echo FAIL )" >> "$RESULTS"
done

# The artifact must be produced by THIS run: evidence that predates the verdict is not evidence for it.
art_note="(none declared)"
if [ -n "$ARTIFACT" ]; then
    if [ -f "$ARTIFACT" ]; then
        if [ "$(stat -c %Y "$ARTIFACT")" -ge "$START" ]; then
            art_note="${ARTIFACT} ($(stat -c %s "$ARTIFACT") bytes, produced by this run)"
        else
            fail=1; art_note="${ARTIFACT} STALE — older than this run, so it does not evidence this verdict"
        fi
    else
        fail=1; art_note="${ARTIFACT} MISSING"
    fi
fi

verdict=FAIL; [ "$fail" = 0 ] && verdict=PASS
sha="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

python3 - "$BUNDLE" "$RESULTS" "$project" "$verdict" "$sha" "$URL" "$art_note" "$STAMP" "$passed" "$n" <<'PY'
import json, sys
bundle, results, project, verdict, sha, url, art, stamp, passed, total = sys.argv[1:11]
gates = []
for line in open(results):
    parts = line.rstrip("\n").split("\t", 3)
    if len(parts) < 4:
        continue
    rc, cmd, tail, res = parts
    gates.append({"cmd": cmd, "exit": int(rc), "result": res, "tail": tail})
json.dump({"schema": "harpp2.project-e2e:v1", "project": project, "verdict": verdict,
           "gates_passed": int(passed), "gates_total": int(total), "commit": sha, "url": url,
           "artifact": art, "finished_at": stamp, "gates": gates},
          open(bundle, "w"), indent=2)
PY

echo
echo "RESULT: ${project} E2E ${verdict} — ${passed}/${n} gates · artifact ${art_note}"
echo "BUNDLE: ${BUNDLE}"
[ "$verdict" = "PASS" ] || echo "WORK IS NOT COMPLETE — a gate above failed."

# Speak where the owner is sitting: the editor terminal. HARPP (below) covers him being away.
if [ "$verdict" = "PASS" ]; then
    say "E2E ${verdict}: ${project} (${passed}/${n} gates)" \
        "artifact: ${art_note}" "url: ${URL}" \
        "commit: ${sha} · bundle: ${BUNDLE}" \
        "all gates passed — the product is complete end to end. Nothing to ask."
else
    say "E2E ${verdict}: ${project} (${passed}/${n} gates)" \
        "FAILING: $(awk -F'\t' '$4=="FAIL"{print $2; exit}' "$RESULTS")" \
        "artifact: ${art_note}" "url: ${URL}" \
        "commit: ${sha} · bundle: ${BUNDLE}" \
        "work is NOT complete; the chain continues."
fi

if [ "$SEND" = 1 ]; then
    body="project: ${project}
verdict: ${verdict} — ${passed}/${n} gates passed. The exit codes below ARE the verdict.
url: ${URL}
artifact: ${art_note}
commit: ${sha}
bundle: ${BUNDLE}

gates:"
    while IFS=$'\t' read -r rc cmd _tail res; do
        body="${body}
  [${res} exit ${rc}] ${cmd}"
    done < "$RESULTS"
    if [ "$verdict" = "PASS" ]; then
        body="${body}

Every gate ran and passed: the product is playable end to end and nothing else regressed. Nothing to ask."
    else
        body="${body}

At least one gate failed — see the tails above. Work is NOT complete; the chain continues."
    fi

    out="$(timeout 90 harpp msg send --conversation-id "$CONVERSATION" \
        --title "E2E ${verdict}: ${project} (${passed}/${n} gates)" \
        --body "$body" --idempotency-key "${project}-${sha}-${verdict}-${STAMP}" 2>&1)"; rc=$?
    if [ "$rc" = "0" ]; then
        echo "DELIVERED: message $(printf '%s' "$out" | grep -oE '"id": *[0-9]+' | grep -oE '[0-9]+' | tail -1) → conversation ${CONVERSATION}"
    else
        echo "DELIVERY: local-only — director NOT notified (harpp msg send exit ${rc})"
        echo "retry: harpp msg send --conversation-id ${CONVERSATION} --title 'E2E ${verdict}: ${project} (${passed}/${n} gates)'"
        exit 4
    fi
fi

[ "$verdict" = "PASS" ] && exit 0 || exit 1
