#!/usr/bin/env bash
# R6 P0-exit gate: the editorial journey itself uses only HTTP. CLI/SQL are used
# solely to provision fixtures, verify durable state, and clean up.
set -Eeuo pipefail

if [[ "${RUN_TWO_TENANT_JOURNEY:-0}" != "1" ]]; then
  echo "SKIP: set RUN_TWO_TENANT_JOURNEY=1 (see docs/testing/akira-two-tenant-journey.md)"
  exit 0
fi

for command in curl php mysql; do command -v "$command" >/dev/null || { echo "Missing $command" >&2; exit 2; }; done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
A_HOST="${JOURNEY_TENANT_A_HOST:-akiracms.test}"
A_ADMIN="${JOURNEY_TENANT_A_ADMIN:-charlienacario884}"
A_PASS="${JOURNEY_TENANT_A_PASS:?set JOURNEY_TENANT_A_PASS}"
B_HOST="${JOURNEY_TENANT_B_HOST:-akiracms-b.test}"
B_KEY="${JOURNEY_TENANT_B_KEY:-akira-r6-b}"
B_DB="${JOURNEY_TENANT_B_DB:-akira_r6_b}"
B_ADMIN="${JOURNEY_TENANT_B_ADMIN:-akira_r6_admin}"
B_AUTHOR="${JOURNEY_TENANT_B_AUTHOR:-akira_r6_author}"
B_PASS="${JOURNEY_TENANT_B_PASS:?set JOURNEY_TENANT_B_PASS}"
DB_HOST="${JOURNEY_DB_HOST:-127.0.0.1}"
DB_PORT="${JOURNEY_DB_PORT:-3306}"
DB_USER="${JOURNEY_DB_USER:?set JOURNEY_DB_USER}"
DB_PASS="${JOURNEY_DB_PASS:-}"
SCHEME="${JOURNEY_SCHEME:-http}"
KEEP="${JOURNEY_KEEP_TENANT_B:-0}"
RUN_ID="$(date +%s)-$$"
B_SLUG="r6-b-$RUN_ID"
A_SLUG="r6-a-$RUN_ID"
TMP="$(mktemp -d)"
B_ID=""
MYSQL=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --batch --skip-column-names)
[[ -n "$DB_PASS" ]] && MYSQL+=("-p$DB_PASS")

control_scalar() {
  JOURNEY_VALUE="$1" php -r 'require "bootstrap.php"; echo app()->controlDb()->query(getenv("JOURNEY_VALUE"))->fetchColumn();'
}
cleanup() {
  local rc=$?
  if [[ -n "$B_ID" && "$KEEP" != "1" ]]; then
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$B_DB\`" >/dev/null 2>&1 || true
    JOURNEY_TID="$B_ID" php -r 'require "bootstrap.php"; $db=app()->controlDb(); $id=(int)getenv("JOURNEY_TID"); $db->prepare("DELETE FROM kernel_tenant_domains WHERE tenant_id=?")->execute([$id]); $db->prepare("DELETE FROM kernel_tenant_db_connections WHERE tenant_id=?")->execute([$id]); $db->prepare("DELETE FROM kernel_tenants WHERE id=?")->execute([$id]);' >/dev/null 2>&1 || true
  fi
  rm -rf "$TMP"
  exit "$rc"
}
trap cleanup EXIT

pass() { printf 'PASS: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
status_of() { awk 'toupper($1) ~ /^HTTP\// { code=$2 } END { print code }' "$1"; }
assert_status() { [[ "$(status_of "$1")" == "$2" ]] || fail "$3 (HTTP $(status_of "$1"), expected $2)"; pass "$3"; }
field() {
  local file="$1" name="$2"
  php -r '$h=file_get_contents($argv[1]); $n=preg_quote($argv[2],"/"); if(!preg_match("/<input[^>]*name=[\"\x27]".$n."[\"\x27][^>]*value=[\"\x27]([^\"\x27]*)/i",$h,$m) && !preg_match("/<input[^>]*value=[\"\x27]([^\"\x27]*)[\"\x27][^>]*name=[\"\x27]".$n."[\"\x27]/i",$h,$m)) exit(1); echo html_entity_decode($m[1], ENT_QUOTES|ENT_HTML5);' "$file" "$name"
}
get() { local host="$1" jar="$2" path="$3" out="$4"; curl -sS --resolve "$host:80:127.0.0.1" -b "$jar" -c "$jar" -D "$out.headers" -o "$out" "$SCHEME://$host$path"; }
post() { local host="$1" jar="$2" path="$3" out="$4"; shift 4; curl -sS --resolve "$host:80:127.0.0.1" -b "$jar" -c "$jar" -D "$out.headers" -o "$out" -X POST "$@" "$SCHEME://$host$path"; }
login() {
  local host="$1" jar="$2" user="$3" passw="$4" out
  out="$TMP/login-$user"
  post "$host" "$jar" /api/v1/auth/login "$out" -H 'Accept: application/json' --data-urlencode "username=$user" --data-urlencode "password=$passw"
  assert_status "$out.headers" 200 "$user logs in through Kernel HTTP auth"
  grep -q '"ok":true' "$out" || fail "$user login response is not ok"
}
render_form() {
  get "$1" "$2" "$3" "$4"
  assert_status "$4.headers" 200 "render $3"
  grep -q 'name="_token"' "$4" || fail "rendered form lacks canonical _token"
  ! grep -q 'name="_csrf_token"' "$4" || fail "rendered form contains obsolete _csrf_token"
  grep -q 'x-data="akiraContentEditor()"' "$4" || fail "rendered form lacks named Alpine component"
  ! grep -q 'x-data="{body:' "$4" || fail "rendered form contains inline Alpine object"
}
transition() {
  local host="$1" jar="$2" slug="$3" action="$4" key="$5" expected_code="${6:-303}" form
  form="$TMP/form-$host-$action"
  render_form "$host" "$jar" "/cms-akira-shell/posts/$slug/edit" "$form"
  local token expected
  token="$(field "$form" _token)"; expected="$(field "$form" expected_status)"
  post "$host" "$jar" "/cms-akira-shell/posts/$slug/workflow" "$form.transition" --data-urlencode "_token=$token" --data-urlencode "action=$action" --data-urlencode "expected_status=$expected" --data-urlencode "idempotency_key=$key"
  assert_status "$form.transition.headers" "$expected_code" "$action transition over HTTP"
}

# Sanctioned provisioning path: tenant:create -> tenant:db:set -> tenant:provision -> ModuleInstallService CLI.
php ikabud tenant:create "$B_KEY" "$B_HOST" --entry=cms-akira-shell
B_ID="$(control_scalar "SELECT id FROM kernel_tenants WHERE tenant_key='$B_KEY' ORDER BY id DESC LIMIT 1")"
[[ "$B_ID" =~ ^[1-9][0-9]*$ ]] || fail "could not resolve tenant B id"
php ikabud tenant:db:set "$B_ID" --host="$DB_HOST" --port="$DB_PORT" --name="$B_DB" --user="$DB_USER" --pass="$DB_PASS"
php ikabud tenant:provision "$B_ID" --admin-user="$B_ADMIN" --admin-pass="$B_PASS" --admin-name='R6 Admin'
php ikabud tenant:module:install "$B_ID" cms-akira-profile-standard --set-entry
pass "tenant B #$B_ID provisioned with standard Akira profile"
# Apache workers may retain the previous/missing host lookup for the Kernel's
# bounded host-cache TTL. Waiting exercises normal resolution, not an injected reset.
sleep "${JOURNEY_TENANT_CACHE_WAIT:-31}"

# Author fixture is tenant-local test setup; password hashing uses PHP's production primitive.
HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$B_PASS")"
"${MYSQL[@]}" "$B_DB" -e "ALTER TABLE users MODIFY role ENUM('contributor','author','editor','admin','administrator','superadmin','manager','viewer') NOT NULL DEFAULT 'viewer'; INSERT INTO users (username,email,password_hash,full_name,role,is_active) VALUES ('$B_AUTHOR','$B_AUTHOR@example.test','$HASH','R6 Author','author',1)"

A_JAR="$TMP/a.cookies"; B_ADMIN_JAR="$TMP/b-admin.cookies"; B_AUTHOR_JAR="$TMP/b-author.cookies"
login "$B_HOST" "$B_ADMIN_JAR" "$B_ADMIN" "$B_PASS"
render_form "$B_HOST" "$B_ADMIN_JAR" /cms-akira-shell/posts/new "$TMP/b-new"
TOKEN="$(field "$TMP/b-new" _token)"
post "$B_HOST" "$B_ADMIN_JAR" /cms-akira-shell/posts "$TMP/b-create" --data-urlencode "_token=$TOKEN" --data-urlencode 'title=R6 tenant B post' --data-urlencode "slug=$B_SLUG" --data-urlencode 'content=HTTP-only acceptance body' --data-urlencode "idempotency_key=create-$RUN_ID"
assert_status "$TMP/b-create.headers" 303 'admin creates tenant B post over HTTP'
transition "$B_HOST" "$B_ADMIN_JAR" "$B_SLUG" submit "submit-$RUN_ID"

login "$B_HOST" "$B_AUTHOR_JAR" "$B_AUTHOR" "$B_PASS"
transition "$B_HOST" "$B_AUTHOR_JAR" "$B_SLUG" approve "unauthorized-$RUN_ID" 422
pass 'author unauthorized approval is denied at the real HTTP seam'

transition "$B_HOST" "$B_ADMIN_JAR" "$B_SLUG" approve "approve-$RUN_ID"
# Capture approved CSRF/concurrency fields, then publish twice with exactly the same payload.
render_form "$B_HOST" "$B_ADMIN_JAR" "/cms-akira-shell/posts/$B_SLUG/edit" "$TMP/b-approved"
TOKEN="$(field "$TMP/b-approved" _token)"; EXPECTED="$(field "$TMP/b-approved" expected_status)"; PUBKEY="publish-$RUN_ID"
for replay in first replay; do
  post "$B_HOST" "$B_ADMIN_JAR" "/cms-akira-shell/posts/$B_SLUG/workflow" "$TMP/publish-$replay" --data-urlencode "_token=$TOKEN" --data-urlencode 'action=publish' --data-urlencode "expected_status=$EXPECTED" --data-urlencode "idempotency_key=$PUBKEY"
  assert_status "$TMP/publish-$replay.headers" 303 "publish $replay returns 303"
done

STATE="$("${MYSQL[@]}" "$B_DB" -e "SELECT CONCAT(p.status,'|',i.state,'|',(SELECT COUNT(*) FROM workflow_transition_logs l WHERE l.instance_id=i.id AND l.action='publish'),'|',(SELECT COUNT(*) FROM audit_logs a WHERE a.module='cms-akira-workflow' AND a.action='akira.workflow.transition' AND a.entity_id='$B_SLUG' AND JSON_UNQUOTE(JSON_EXTRACT(a.new_data,'$.action'))='publish')) FROM cms_akira_posts p JOIN workflow_instances i ON i.module='cms-akira-workflow' AND i.entity_id=CONCAT('tenant-$B_ID:',p.slug) WHERE p.tenant_id=$B_ID AND p.slug='$B_SLUG'")"
[[ "$STATE" == 'published|published|1|1' ]] || fail "durable publish state mismatch: $STATE"
pass 'post/workflow are published and replay leaves exactly one transition and one audit row'

# Create a governed marker in A, then prove both directions through each real host/session.
login "$A_HOST" "$A_JAR" "$A_ADMIN" "$A_PASS"
render_form "$A_HOST" "$A_JAR" /cms-akira-shell/posts/new "$TMP/a-new"
TOKEN="$(field "$TMP/a-new" _token)"
post "$A_HOST" "$A_JAR" /cms-akira-shell/posts "$TMP/a-create" --data-urlencode "_token=$TOKEN" --data-urlencode 'title=R6 tenant A marker' --data-urlencode "slug=$A_SLUG" --data-urlencode 'content=Tenant A isolation marker' --data-urlencode "idempotency_key=a-create-$RUN_ID"
assert_status "$TMP/a-create.headers" 303 'admin creates tenant A isolation marker'

get "$A_HOST" "$A_JAR" "/cms-akira-shell/posts/$B_SLUG/edit" "$TMP/a-read-b"; assert_status "$TMP/a-read-b.headers" 404 'tenant A cannot read tenant B post'
TOKEN="$(field "$TMP/a-new" _token)"
post "$A_HOST" "$A_JAR" "/cms-akira-shell/posts/$B_SLUG/workflow" "$TMP/a-write-b" --data-urlencode "_token=$TOKEN" --data-urlencode 'action=unpublish' --data-urlencode 'expected_status=published' --data-urlencode "idempotency_key=a-cross-$RUN_ID"
assert_status "$TMP/a-write-b.headers" 422 'tenant A cannot mutate tenant B post'
get "$B_HOST" "$B_ADMIN_JAR" "/cms-akira-shell/posts/$A_SLUG/edit" "$TMP/b-read-a"; assert_status "$TMP/b-read-a.headers" 404 'tenant B cannot read tenant A post'
post "$B_HOST" "$B_ADMIN_JAR" "/cms-akira-shell/posts/$A_SLUG/workflow" "$TMP/b-write-a" --data-urlencode "_token=$(field "$TMP/b-approved" _token)" --data-urlencode 'action=submit' --data-urlencode 'expected_status=draft' --data-urlencode "idempotency_key=b-cross-$RUN_ID"
assert_status "$TMP/b-write-a.headers" 422 'tenant B cannot mutate tenant A post'

# Governed cleanup of the temporary A marker; B/control-plane cleanup is handled by trap.
render_form "$A_HOST" "$A_JAR" "/cms-akira-shell/posts/$A_SLUG/edit" "$TMP/a-edit"
post "$A_HOST" "$A_JAR" "/cms-akira-shell/posts/$A_SLUG/delete" "$TMP/a-delete" --data-urlencode "_token=$(field "$TMP/a-edit" _token)" --data-urlencode "expected_updated_at=$(field "$TMP/a-edit" expected_updated_at)" --data-urlencode "idempotency_key=a-delete-$RUN_ID"
assert_status "$TMP/a-delete.headers" 303 'tenant A marker removed through governed HTTP delete'
pass "R6 HTTP-only two-tenant acceptance journey complete"
