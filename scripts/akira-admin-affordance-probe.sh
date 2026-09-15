#!/usr/bin/env bash
#
# Admin-surface affordance probe for CMS Akira.
#
# Measures, per admin route, whether the surface carries the affordances a CMS list is expected
# to have: the shared shell chrome (sidebar + Sign out), a table, a search box, a filter select,
# pagination, bulk selection, and a create action.
#
# WHY IT IS A SCRIPT AND NOT A ONE-LINER
# A long inline shell loop was line-spliced by the terminal wrapper on 2026-09-15 and returned a
# clean-looking table of zeros. The output of a broken probe is indistinguishable from a product
# that renders nothing, so the probe is kept reproducible and its own preconditions are asserted.
#
# WHY IT CHECKS A KNOWN-GOOD PAGE FIRST
# An expired session returns the kernel 403 page for every route. That page has no table, no
# search, no filter and no pagination — so a sweep over a dead session manufactures a complete,
# plausible and entirely false gap report. The known-good assertion exists to make that
# impossible: if the sanity target does not render a table, the run aborts instead of lying.
#
# Read-only: issues GETs only, after a single login.
#
# Usage:  bash scripts/akira-admin-affordance-probe.sh [route ...]
# Env:    AKIRA_HOST AKIRA_USER AKIRA_PASS AKIRA_SCHEME (defaults: akiracms.test / tenant admin / http)

set -uo pipefail

HOST="${AKIRA_HOST:-akiracms.test}"
USER_NAME="${AKIRA_USER:-chilenacario884}"
PASS="${AKIRA_PASS:-aki123!#}"
SCHEME="${AKIRA_SCHEME:-http}"

TMP="$(mktemp -d)"
JAR="$TMP/cookies"
trap 'rm -rf "$TMP"' EXIT

die() { printf 'FATAL: %s\n' "$*" >&2; exit 2; }

ROUTES=("$@")
if [ "${#ROUTES[@]}" -eq 0 ]; then
  ROUTES=(
    /cms-akira-shell
    /cms-akira-shell/posts
    /cms-akira-shell/categories
    /cms-akira-shell/content-types
    /cms-akira-shell/media
    /cms-akira-shell/compositions
    /cms-akira-shell/redirects
    /cms-akira-shell/search
    /cms-akira-shell/workflow
    /cms-akira-shell/authority
    /cms-akira-shell/provenance
    /cms-akira-shell/permissions
    /cms-akira-shell/settings
    /cms-akira-shell/users
    /cms-akira-shell/modules
    /cms-akira-shell/health
    /cms-akira-shell/backups
    /cms-akira-shell/exports
    /cms-akira-shell/forbidden
    /cms-akira-theme
  )
fi

# ---------------------------------------------------------------- authenticate exactly once
# The kernel auth limiter answers 429 after repeated POST /api/v1/auth/login. One login per run.
LOGIN_CODE="$(curl -sS --resolve "$HOST:80:127.0.0.1" -b "$JAR" -c "$JAR" -o "$TMP/login" -w '%{http_code}' \
  -X POST -H 'Accept: application/json' \
  --data-urlencode "username=$USER_NAME" \
  --data-urlencode "password=$PASS" \
  "$SCHEME://$HOST/api/v1/auth/login")"

[ "$LOGIN_CODE" = "200" ] || die "login returned HTTP $LOGIN_CODE for '$USER_NAME' (429 = rate limited, not an auth fault)"
grep -q '"ok":true' "$TMP/login" || die "login body is not ok: $(head -c 200 "$TMP/login")"

fetch() {
  local route="$1"
  curl -sS --resolve "$HOST:80:127.0.0.1" -b "$JAR" -c "$JAR" \
    -o "$TMP/page" -w '%{http_code}' \
    "$SCHEME://$HOST$route?cb=$RANDOM$RANDOM"
}

# ---------------------------------------------------------------- known-good sanity target
SANITY=/cms-akira-shell/posts
fetch "$SANITY" >/dev/null
if ! grep -q '<table' "$TMP/page"; then
  die "sanity target $SANITY rendered no <table> ($(wc -c <"$TMP/page") bytes) — session or cache is the fault, not the product; refusing to report a gap analysis"
fi

printf '%-34s %6s %4s  %s\n' ROUTE BYTES CODE 'SHELL TBL SRCH FILT PAGE BULK NEW'
printf '%s\n' "-------------------------------------------------------------------------------"

count() { grep -c -i -- "$1" "$TMP/page" 2>/dev/null || true; }

for route in "${ROUTES[@]}"; do
  code="$(fetch "$route")"
  bytes="$(wc -c <"$TMP/page")"

  shell="-" table="-" srch="-" filt="-" page="-" bulk="-" new="-"

  # The shell chrome marker is the aria-label the shared helper emits.
  [ "$(count 'aria-label="Akira administration"')" -gt 0 ] && shell="yes"
  [ "$(count '<table')" -gt 0 ] && table="yes"
  [ "$(count 'type="search"')" -gt 0 ] && srch="yes"
  [ "$(count '<select')" -gt 0 ] && filt="yes"
  { [ "$(count 'rel="next"')" -gt 0 ] || [ "$(count 'page=')" -gt 0 ]; } && page="yes"
  { [ "$(count 'type="checkbox"')" -gt 0 ] || [ "$(count 'ids\[')" -gt 0 ]; } && bulk="yes"
  if [ "$(count 'href="[^"]*/new')" -gt 0 ] || [ "$(count '>New ')" -gt 0 ]; then new="yes"; fi

  printf '%-34s %6s %4s  %-5s %-3s %-4s %-4s %-4s %-4s %s\n' \
    "$route" "$bytes" "$code" "$shell" "$table" "$srch" "$filt" "$page" "$bulk" "$new"
done

printf '\nshell = shared sidebar chrome present. "-" means absent or not detectable by this probe.\n'
