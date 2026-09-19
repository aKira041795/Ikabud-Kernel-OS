# HARPP module review

**Review date:** 2026-09-16  
**Scope:** `modules/harpp`, `templates/modules/harpp`, and the coupling to `tools/harpp-bridge`  
**Mode:** read-only; no activation, migration, tenant-database write, or live bridge call was performed.

## Executive conclusion

HARPP is a substantial, independent, auth-owned tenant application: a phone-oriented PWA and REST/MCP bridge for owner/harness messaging, governed decisions and ADRs, runner work queues, risk-gated execution, artifacts, status, notifications, and local-machine deployment. It belongs in this repository **if this kernel is intended to host that operator/harness control plane**, but it is not a passive library and should not be enabled casually: it adds 37 tenant tables, 36 capability providers, 143 routes, a second authentication surface, Web Push, and a machine credential with owner/admin authority (`modules/harpp/module.json:8-46`, `modules/harpp/module.json:68-339`, `modules/harpp/routes.php:5-171`).

**Recommendation: activate after the listed corrections.** The templates and two non-DB tests pass, and the module uses supported route, capability, entity-source, asset, and DiSyL mechanisms. Before activation, remove raw password-reset tokens from logs, explicitly classify the manifest as `standalone-application`, reconcile the VAPID subject/UI contract, move entity-view config loading to helper bootstrap, update stale compatibility documentation, and run all 19 DB-writing tests against a disposable database. The **single biggest risk** is compromise or misuse of the per-tenant bridge key: one shared machine credential is mapped to the first active owner/admin and can record owner decisions, manipulate runs, approve/reject risk-gated work, and drive deploy workflows (`modules/harpp/services/HarppBridgeAuthService.php:74-100,132-139`; `modules/harpp/routes.php:105-158`).

Evidence inventory command:

```text
$ php -r '$j=json_decode(file_get_contents("modules/harpp/module.json"),true); echo "version={$j["version"]} tables=".count($j["owns_tables"])." capabilities=".count($j["capabilities"]["exposes"])." migrations=".count($j["migrations"]).PHP_EOL;'; php -r '$r=require "modules/harpp/routes.php"; foreach($r as $m=>$v) echo "$m ".count($v).PHP_EOL;'
version=2.5.0 tables=37 capabilities=36 migrations=19
GET 60
POST 75
PUT 1
PATCH 1
DELETE 6
```

## 1. What it is

A human can treat HARPP as a separately authenticated operations console, not as a CMS Akira feature. The hosted module persists the authoritative conversations, decisions, ADRs, notifications, work/run state, artifacts, runner health, and deploy requests. The local bridge under `tools/harpp-bridge` performs machine-side polling, agent execution, Wake-on-LAN, MCP exposure, and deploy execution. The browser PWA is the operator/director channel. The 24 service files implement those domains; 151 handler functions expose page/API endpoints; 14 external templates render the shell; 16 assets provide UI and PWA behavior; two bin scripts generate VAPID material and backfill legacy enrollment; 21 PHP test programs cover static contracts, crypto, and DB integration (`modules/harpp/README.md:1-13`; `modules/harpp/services/HarppBridgeService.php:15-300`).

| Area | Inventory | What it does | Evidence |
|---|---:|---|---|
| Services | 24 PHP files | Auth/users/reset; settings; decisions/ADRs; messaging/notifications/push; collaboration/workspaces/projects; integrity/outbox; bridge; deploy; runs/risk; context/memory/artifacts/status | `modules/harpp/helpers.php:17-40`; public methods are declared throughout `modules/harpp/services/*.php` |
| Handlers/routes | 151 functions; 143 routes | PWA pages, auth-owned JSON API, bridge API, static PWA resources | `modules/harpp/routes.php:5-171`; `modules/harpp/handlers.php:116-541` |
| Entity views | 2 entities, 4 views | Table/detail contracts for decisions and ADRs | `modules/harpp/module.json:624-704`; `modules/harpp/helpers/views/harpp_decision.disyl:1-24`; `modules/harpp/helpers/views/harpp_adr.disyl:1-21` |
| Templates | 14 | Login, layout, messenger, decisions, advisor, deploy, overview/status/runners, notifications, users/settings/workspaces | `templates/modules/harpp/*.disyl`; file listing below |
| Assets/PWA | 16 | 11 page controllers plus shared PWA client, service worker, manifest, and icon | `modules/harpp/assets/sw.js:1-7`; `modules/harpp/assets/pwa.js:1-130`; `modules/harpp/assets/manifest.webmanifest:1-14` |
| Bin | 2 | Generate/write VAPID keys; one-time legacy enrollment backfill | `modules/harpp/bin/harpp-vapid:9-18,79-101`; `modules/harpp/bin/legacy-enrollment-backfill.php:9-26` |
| Migrations | 19 | 37 owned `harpp_*` tables/alterations and bootstrap/backfill data | `modules/harpp/module.json:8-66` |
| Tests | 21 `*_test.php` + runner/support scripts | 2 pure static/crypto tests; 19 DB integration tests; consolidated runner and guards | `modules/harpp/tests/run-all.sh:1-66` |

Real inventory output:

```text
$ find modules/harpp -type f | awk -F/ '{print ($3=="assets"||$3=="bin"||$3=="database"||$3=="docs"||$3=="services"||$3=="tests"||$3=="templates"||$3=="helpers")?$3:"root"}' | sort | uniq -c
     16 assets
      2 bin
     19 database
      2 docs
      2 helpers
      5 root
     25 services
      1 templates
     25 tests

$ find templates/modules/harpp -type f | sort
templates/modules/harpp/advisor.disyl
templates/modules/harpp/decision-detail.disyl
templates/modules/harpp/decisions.disyl
templates/modules/harpp/deploy.disyl
templates/modules/harpp/layout.disyl
templates/modules/harpp/login.disyl
templates/modules/harpp/messenger.disyl
templates/modules/harpp/notifications.disyl
templates/modules/harpp/overview.disyl
templates/modules/harpp/runners.disyl
templates/modules/harpp/settings.disyl
templates/modules/harpp/status.disyl
templates/modules/harpp/users.disyl
templates/modules/harpp/workspaces.disyl
```

The `services` count above includes `.gitkeep`; there are 24 PHP service classes/files. The `tests` count includes support scripts, so it is not a pass count.

## 2. How it reaches the harness and director

### Actual call path

1. Pi/MCP/CLI invokes a local function. MCP advertises director-facing tools such as `harpp_submit_decision`, `harpp_send_message`, and `harpp_poll_messages` (`tools/harpp-bridge/harpp_mcp.py:41-142`).
2. Those functions call `harpp_client.py`; e.g. `submit_decision()` posts `/api/v1/harpp/bridge/decisions` (`tools/harpp-bridge/harpp_client.py:329-343`) and `send_message()` posts `/api/v1/harpp/bridge/messages` (`tools/harpp-bridge/harpp_client.py:426-452`). Actionable messages also create a real decision (`tools/harpp-bridge/harpp_client.py:491-515`).
3. `api()` joins the configurable base URL to the v1 path and sends `X-HARPP-BRIDGE-KEY` and `X-HARPP-TENANT-ID` over HTTPS (`tools/harpp-bridge/harpp_client.py:269-308`).
4. Kernel module routing maps those paths to `harppBridge*` handlers (`modules/harpp/routes.php:50-73,117-158`).
5. Every bridge handler calls `harppBridgeAuthenticated()`, which reads the two headers and validates the key/tenant (`modules/harpp/handlers.php:456-464`). The validator compares the tenant, SHA-256 hash with `hash_equals`, applies a 5-failure/60-second rate bucket, and resolves the first active owner/admin as a `harpp_bridge` actor (`modules/harpp/services/HarppBridgeAuthService.php:13-16,74-100,132-139,159-180`).
6. Handlers delegate to `HarppBridgeService`, then domain services: decisions → `HarppDecisionService`, messages → `HarppMessagingService`, runs/wakes → `HarppRunService`, status → `HarppStatusService` (`modules/harpp/services/HarppBridgeService.php:15-122`).
7. The director sees messages and decisions through authenticated PWA pages/API and Web Push. Harness messages must target an owner-created conversation (`modules/harpp/services/HarppBridgeService.php:60-71`); owner messages polled by the harness are also made durable work runs (`modules/harpp/services/HarppBridgeService.php:74-87`).

### Named coordination structures

| Structure | Path through the module/local client | Evidence |
|---|---|---|
| `harpp_runner_wake_requests` | Owner nudge → `HarppRunService::requestWake`; relay claims/delivers/fails via bridge | `modules/harpp/services/HarppRunService.php:75-179`; `tools/harpp-bridge/harpp_client.py:542-557` |
| `harpp_work_runs` | Poll/queue → claim/lease/run/complete/reconcile/report | `modules/harpp/services/HarppBridgeService.php:74-87,110-170`; `modules/harpp/services/HarppRunService.php:217-557` |
| risk gate (migration 017) | Completion classifies risk; high/critical run becomes `AWAITING_APPROVAL`; token approval/rejection routes | `modules/harpp/database/migrations/017_harpp_risk_gate.sql:10-19`; `modules/harpp/services/HarppRunService.php:304-444`; `tools/harpp-bridge/harpp_client.py:600-608` |
| artifact bundle | Successful run/approved decision builds durable review bundle; MCP reads it | `modules/harpp/services/HarppArtifactService.php:43-189`; `tools/harpp-bridge/harpp_client.py:656-684`; `tools/harpp-bridge/harpp_mcp.py:163-200` |
| `harpp_daemon_status` | watch loop reports runner/version/workflows → bridge status report → status page | `tools/harpp-bridge/harpp_client.py:561-569`; `tools/harpp-bridge/harpp_wake.py:1972-1986`; `modules/harpp/services/HarppStatusService.php:14-93` |
| `HarppDeployService` | Phone queues metadata-only job; local deploy worker polls, claims, executes locally, reports receipt | `modules/harpp/services/HarppDeployService.php:95-318`; `modules/harpp/README.md:462-488` |

### Coupling answer

The hosted module does **not** import, execute, or locate `tools/harpp-bridge`; coupling is HTTP contract only. The local CLI itself must be launched from whatever installed path contains its Python files, but the server has no filesystem-path dependency. Host is configurable as `base_url`, must begin `https://`, and is neither fixed to `akiracms.test` nor localhost (`tools/harpp-bridge/harpp_client.py:147-168`). The API path is fixed at `/api/v1/harpp/bridge/*`. There is no client-version negotiation or server-side version check: the client sends a fixed `User-Agent: harpp-bridge-client/1.0`, while repository version markers disagree (`tools/harpp-bridge/harpp_client.py:286-293`; `tools/harpp-bridge/VERSION:1`; `tools/harpp-bridge/harpp_mcp.py:41-43`). Compatibility therefore depends on behavioral v1 endpoint compatibility, not an enforced version.

```text
$ nl -ba tools/harpp-bridge/VERSION; rg -n 'SERVER_VERSION|PROTOCOL_VERSION|User-Agent' tools/harpp-bridge/{harpp_client.py,harpp_mcp.py}
     1  1.1.0
tools/harpp-bridge/harpp_client.py:290:        "User-Agent": "harpp-bridge-client/1.0",
tools/harpp-bridge/harpp_mcp.py:42:SERVER_VERSION = "1.4.0"
tools/harpp-bridge/harpp_mcp.py:43:PROTOCOL_VERSION = "2024-11-05"
```

## 3. Configuration, secrets, and files

No environment variable is strictly required merely to mark the module active. Correct operation requires tenant schema/activation state and a usable auth-owned owner account. Web Push can auto-generate a key file, but production should supply stable per-instance VAPID values. Harness connectivity additionally requires a generated per-tenant bridge key and local bridge config.

| Name/key/file | Purpose | Required? | Secret / per-instance | Read/write evidence |
|---|---|---|---|---|
| `HARPP_VAPID_PRIVATE_KEY` | ES256 VAPID signing key | Recommended for production push; required if only public key is configured | **Secret; generate per instance; never commit** | `modules/harpp/services/HarppPushService.php:232-246` |
| `HARPP_VAPID_PUBLIC_KEY` | Matching browser application-server key | Optional if private key supplied (derived) or fallback file used | Public, per instance | `modules/harpp/services/HarppPushService.php:234-246` |
| `HARPP_VAPID_SUBJECT` | VAPID contact identity | Optional; defaults `mailto:harpp@localhost` | Per instance, not secret | `modules/harpp/services/HarppPushService.php:236-244` |
| `storage/harpp/harpp-vapid.json` | Stable fallback VAPID pair when env key absent | Runtime fallback only | **Contains private key; mode 0600; never commit** | `modules/harpp/services/HarppPushService.php:249-294,336-355` |
| `.env` | Optional destination used by VAPID generator | Optional | **Contains private key; never commit** | `modules/harpp/bin/harpp-vapid:79-101` |
| `bridge_api_key_hash`, `bridge_api_key_rotated_at` | Tenant bridge credential hash/status | Bridge key required for harness connectivity, not browser-only activation | Raw key shown once; raw value must never be committed | `modules/harpp/module.json:499-501`; `modules/harpp/services/HarppBridgeAuthService.php:28-67,104-120` |
| `HARPP_BASE_URL` / local `base_url` | Hosted tenant origin | Required by local bridge | Per installation; non-secret | `tools/harpp-bridge/harpp_client.py:147-168` |
| `HARPP_BRIDGE_KEY` / local `bridge_key` | Raw machine credential | Required by local bridge | **Secret, per tenant; never commit** | `tools/harpp-bridge/harpp_client.py:147-168,286-300` |
| `HARPP_TENANT_ID` / local `tenant_id` | Header tenant binding | Required by local bridge | Per tenant; not secret | `tools/harpp-bridge/harpp_client.py:147-168,286-289` |
| `HARPP_CONFIG` / `~/.config/harpp/config.json` | Override/default local bridge config file | One of env/config mechanisms required | **Contains raw bridge key; never commit; protect locally** | `tools/harpp-bridge/harpp_client.py:6-10,24,85-92,125-168` |
| `app.jwt.expiration` | HARPP JWT/cookie lifetime | Kernel default 86400 exists | Kernel config | `modules/harpp/services/HarppAuthService.php:79,347` |
| `cookie.samesite` | HARPP cookie policy | Kernel default `Strict` exists | Kernel config | `modules/harpp/services/HarppAuthService.php:341-365` |
| `external_base_url()` / request host+scheme | Password reset URL origin | Runtime URL required for reset email | Deployment config; host is validated in fallback | `modules/harpp/services/HarppPasswordResetService.php:121-133` |
| kernel `buildEmailTemplate`/`sendEmail` | Deliver reset links | Required for usable forgot-password flow, not activation | Mail transport configuration is external and **unverified** | `modules/harpp/services/HarppPasswordResetService.php:135-155` |
| feature settings | lifecycle, retention, outbox, strict validation on; workspace/participant/receipt/approval/fanout off | Defaults exist; migration 007 persists feature flags | Tenant-specific, non-secret | `modules/harpp/module.json:499-510`; `modules/harpp/database/migrations/007_harpp_integrity_collaboration_foundation.sql:389-400` |
| UI settings | push/channel/decision/message/important-only/archive/country/region | Optional; defaults exist | Tenant-specific, non-secret | `modules/harpp/module.json:512-621`; `modules/harpp/services/HarppSettingsService.php:29-99` |
| `HARPP_WAKE_*`, `HARPP_DEPLOY_*`, `HARPP_AUTHORITY`, provider keys | Optional local daemon/deploy/governance behavior | Not required to activate hosted module; required only for selected local features | Provider/deploy secrets must not be committed | `tools/harpp-bridge/harpp:903-909`; `tools/harpp-bridge/harpp_client.py:45-92,125-168`; `tools/harpp-bridge/harpp_wake.py:39-53,3818`; `tools/harpp-bridge/harpp_deploy_worker.py` |

Direct environment-read evidence:

```text
$ rg -n 'getenv\(' modules/harpp/services
modules/harpp/services/HarppPushService.php:234: getenv('HARPP_VAPID_PUBLIC_KEY')
modules/harpp/services/HarppPushService.php:235: getenv('HARPP_VAPID_PRIVATE_KEY')
modules/harpp/services/HarppPushService.php:236: getenv('HARPP_VAPID_SUBJECT')
```

**Configuration defect:** `vapid_subject` is exposed as an editable tenant setting (`modules/harpp/module.json:617-620`) but push ignores it and reads only `HARPP_VAPID_SUBJECT` (`HarppPushService.php:236`). Smallest correction: remove the editable field and document env-only behavior, or deliberately make the service read the setting; the README currently promises env-only key material (`modules/harpp/README.md:92-93,516`).

## 4. Match to current kernel conventions

| Topic | Finding | Divergence/correction |
|---|---|---|
| Manifest schema | Current CMS Akira manifests explicitly declare `suite` and `kind`; HARPP declares neither (`modules/harpp/module.json:1-8`; `modules/cms-akira/cms-akira-shell/module.json:1-9`). Current validation treats omission as legacy standalone behavior (`src/helpers/module-manager.php:4234-4251`). | Functional but legacy-shaped. Add only `"kind": "standalone-application"`; do **not** falsely put this independent app in the `cms-akira` suite. |
| Routing | Same current route-map form, method buckets, and `module:function` targets (`modules/harpp/routes.php:5-171`; `modules/cms-akira/cms-akira-shell/routes.php:5-64`). | No correction. HARPP deliberately uses `/harpp/*` UI and `/api/v1/harpp/*` API namespaces. |
| Handler naming | Camel-case module-prefixed functions match the present convention. One outlier is `harppRunnersPage` while sibling page handlers are `harppPage*` (`modules/harpp/handlers.php:144-151`; route at `modules/harpp/routes.php:14`). | Cosmetic, not runtime-blocking: rename to `harppPageRunners` and update the route if consistency is desired. |
| Capability registration | `harpp_capability_handlers()` matches the loader's normalized export-function convention (`modules/harpp/helpers.php:44-84`; `src/helpers/module-routes.php:149-184`). All 36 exposes have handlers per the accepted validator fact and static contract. | No correction to registration style. |
| Capability policy rows | HARPP declares manifest caller policy for only a subset (`modules/harpp/module.json:346-475`). The current installer seeds DB rows only for exposes with `requires_protocol`; HARPP declares none (`kernel/Services/ModuleInstallService.php:66-91`). | Before activation, make an explicit 36-capability caller/role decision and verify resulting authorization behavior. Do not assume installer-created rows: automatic count is currently zero. |
| Entity sources | Manifest `entity_sources` is current and auto-registers views (`modules/harpp/module.json:624-704`; `src/helpers/module-routes.php:338-377`). HARPP also has richer DiSyL view configs. | `TemplateEngine::loadViewConfigs()` belongs in helper bootstrap per engine documentation, but HARPP calls it from `handlers.php` (`kernel/DiSyL/TemplateEngine.php:274-328`; `modules/harpp/handlers.php:20-23`). Move that call to `helpers.php` so views register independent of route-handler loading; retain only one authoritative definition if duplicate registration order matters. |
| DiSyL dialect | Templates use current `{extends}`, `{block}`, `{if ... || ...}`, filters, and `{verbatim}` forms and parse with the repository's v4 parser. | No template correction found. Linter CLI positional-file behavior is an engine/tool issue, not a template issue; see §5. |
| Docs/version | README/compatibility matrix still say module 2.1.0, 26 tables, 31 capabilities, migrations through 015/008, and bridge Phase 2 unimplemented. Actual manifest is 2.5.0/37/36/19 and includes run/risk/artifact/status/wake code. | Update `modules/harpp/README.md:22-28,116-119` and `modules/harpp/docs/compatibility-matrix.md:7-12`; reconcile bridge `VERSION` 1.1.0, MCP 1.4.0, and User-Agent 1.0. |


## 5. Template compilation/lint

All 14 HARPP templates pass the repository's v4 DiSyL parser when the linter is correctly scoped:

```text
$ php _lint_disyl.php --path templates/modules/harpp
DiSyL Template Linter v1.0
─────────────────────────────────
Found 14 template(s) to validate

─────────────────────────────────
  ✓ 14 file(s) valid

exit=0
```

I also ran the brief's literal command once for every file. Every invocation exited 0, but each said `Found 110 template(s)` rather than one file. `_lint_disyl.php` ignores positional paths and only accepts `--path` (`_lint_disyl.php:24-59`). Representative real result, repeated identically for all 14 paths:

```text
$ php _lint_disyl.php templates/modules/harpp/advisor.disyl
DiSyL Template Linter v1.0
─────────────────────────────────
Found 110 template(s) to validate

─────────────────────────────────
  ✓ 110 file(s) valid

exit=0
```

Thus the templates really do parse, confirmed by `--path`; the literal per-file command does not prove per-file selection. The smallest correction belongs in the linter/engine CLI: accept a positional file path or reject it instead of silently linting everything. No workaround was placed in templates.

## 6. Tests

**Result: 2 passed, 0 failed, 19 skipped. A skip is not a pass.** The consolidated `run-all.sh` was not run because it creates a disposable MySQL schema and copies/truncates/restores repository logs (`modules/harpp/tests/run-all.sh:12-31`), contrary to this review's no-database-write and report-only boundaries. The 19 DB-touching PHP tests were likewise not run. This means live schema, handler/DB integration, queue, risk, artifact, status, wake, password, and workspace behavior remain **unverified in this repository**.

Real classification:

```text
$ printf 'PHP *_test.php: '; find modules/harpp/tests -maxdepth 1 -type f -name '*test.php' | wc -l; printf 'DB-touching *_test.php: '; n=0; for f in modules/harpp/tests/*test.php; do if rg -q 'dbForTenant|->exec\\(|->prepare\\(|->query\\(' "$f"; then n=$((n+1)); fi; done; echo "$n"; printf 'Pure *_test.php: '; n=0; for f in modules/harpp/tests/*test.php; do if ! rg -q 'dbForTenant|->exec\\(|->prepare\\(|->query\\(' "$f"; then n=$((n+1)); fi; done; echo "$n"
PHP *_test.php: 21
DB-touching *_test.php: 19
Pure *_test.php: 2
Pure tests:
modules/harpp/tests/integrity_collaboration_contract_test.php
modules/harpp/tests/push_payload_crypto_test.php
```

Executed results:

```text
$ php modules/harpp/tests/integrity_collaboration_contract_test.php
HARPP integrity/collaboration contract: 269 checks passed
exit=0
$ php modules/harpp/tests/push_payload_crypto_test.php
PASS encrypted Web Push payload round-trip
exit=0
```

Assertions actually executed:

- The 269 static contract assertions cover manifest version/table/migration registration, integrity/collaboration/deploy capabilities and handler maps, lifecycle transition matrix parity, no decision/ADR deletion, ADR minting and approval checks, CSRF call ordering, authority binding, opaque IDs, migration ordering/resumability text, collaboration visibility/quorum, deploy state/credential exclusions, and deploy route methods (`modules/harpp/tests/integrity_collaboration_contract_test.php:41-118`). They do **not** execute tenant SQL or HTTP requests.
- One crypto assertion aggregate generated VAPID/client P-256 keys, built an encrypted push request, decrypted it, and checked record size, key length, payload equality, terminator, content encoding, and plaintext absence (`modules/harpp/tests/push_payload_crypto_test.php:8-58`).
- Skipped: `artifact_bundle`, `context_summary`, `decision_direct_transition`, `decision_inbox`, `mcp_spine`, `memory_search`, `password_management`, phases 2–5, `review_remediation`, `risk_gate`, `runner_fleet`, `runner_reconcile`, `runner_work_queue`, `status_overview`, `wake_requests`, and `workspace_management` CLI tests. They must run later against an explicitly disposable DB.

## 7. Security review

| Surface/boundary | Finding | Assessment |
|---|---|---|
| Bootstrap hashes → human auth | Migration seeds three deterministic active accounts (`modules/harpp/database/migrations/002_harpp_bootstrap_users.sql:1-10`). Login rejects all listed hashes before `password_verify` (`modules/harpp/services/HarppAuthService.php:31-58,291-298`), and manifest repeats the block list (`modules/harpp/module.json:477-497`). Provisioning requires a named admin. | **Sound fail-closed bootstrap**, provided provisioning/reset replaces at least the owner credential before use. |
| Password reset → logs | Reset tokens are random, stored hashed, one-time, expiring, and password policy is 12+ with upper/lower/number (`modules/harpp/services/HarppPasswordResetService.php:45-58,77-108`). However, the full raw reset URL/token is written to application logs (`...PasswordResetService.php:60-64`). | **Unsound secret boundary. Must correct before activation:** never log the raw reset URL/token. |
| Browser auth → tenant | JWT requires `source=harpp`, positive user, matching tenant/store, and reloads an active non-deleted user (`modules/harpp/services/HarppAuthService.php:83-115`). Cookie is HttpOnly, SameSite-configured, Secure on HTTPS (`...HarppAuthService.php:341-365`). State-changing cookie-auth endpoints call kernel CSRF first (`modules/harpp/handlers.php:43-66,283-429`). | **Sound in reviewed code**, conditional on HTTPS and kernel JWT secret/config. |
| PWA/service worker → browser cache | Manifest scope/start URL are `/harpp/`; service worker pre-caches login/static shell, network-fetches navigations, and only writes module assets to cache (`modules/harpp/assets/manifest.webmanifest:1-14`; `modules/harpp/assets/sw.js:1-7`). It does not store authenticated API responses. | **Sound minimal PWA surface.** Offline navigation falls back to login, not cached private content. |
| VAPID/push → outbound network | Subscription endpoints require authenticated user + CSRF (`modules/harpp/handlers.php:428-430`). Push accepts only HTTPS, rejects credentials/fragments and every private/reserved DNS answer, pins the chosen IP in cURL, disables redirects, and limits protocols (`modules/harpp/services/HarppPushService.php:32-105,203-228,419-438`). Payload round-trip test passed. | **Sound SSRF/crypto boundary in reviewed paths.** Public-key GET is intentionally unauthenticated and exposes only a public key, though first access may create `storage/harpp/harpp-vapid.json`. |
| Bridge → owner authority | Raw key is generated with 32 random bytes, stored only as SHA-256, shown once, tenant-bound, rate-limited, and compared with `hash_equals` (`modules/harpp/services/HarppBridgeAuthService.php:74-120,155-180`). Local client refuses plaintext HTTP unless code/config is deliberately put in insecure mode (`tools/harpp-bridge/harpp_client.py:147-168,269-307`). | Cryptographic/auth transport is **sound for a high-entropy bearer key**. Authorization blast radius is **high**: bridge actor becomes first owner/admin and can exercise sensitive bridge routes. Rotation/revocation and filesystem protection are mandatory. `HARPP_INSECURE=1` must never be used outside local development. |
| Deploy → remote host | Server stores profile metadata, not FTP credentials; local worker performs upload (`modules/harpp/README.md:454-488`; `modules/harpp/services/HarppDeployService.php:28-318`). | Design boundary is sound, but deploy integration tests were skipped and local profile secret storage is unverified here. |
| Public endpoints → unauthenticated network | Intended public pages/assets: login, forgot/reset, service worker, manifest, icon; intended API: login, refresh, forgot/reset, VAPID public key (`modules/harpp/routes.php:6-8,21-23,76-81`; `modules/harpp/handlers.php:116-127,188-225,244-313,430`). Authenticated shell wrappers redirect through `harppPageUser()` (`handlers.php:121-169`). | Public surface is mostly appropriate. **Concern:** forgot-password logs a bearer reset token; refresh behavior was not integration-tested. No unauthenticated domain-data endpoint was found by static review. |

Static route scan output (wrappers account for the apparent page false positives):

```text
Route handlers with no explicit auth call:
GET /harpp/login
GET /harpp/forgot-password
GET /harpp/reset-password
GET /harpp -> harppPageMessenger
...authenticated shell wrappers...
GET /harpp/sw.js
GET /harpp/manifest.webmanifest
GET /harpp/icon.svg
GET /api/v1/harpp/push/vapid-public-key
POST /api/v1/harpp/auth/login
POST /api/v1/harpp/auth/refresh
POST /api/v1/harpp/auth/logout
POST /api/v1/harpp/auth/forgot-password
POST /api/v1/harpp/auth/reset-password
```

## 8. Activation cost (ordered, not performed)

These are engineering estimates, not elapsed measurements.

1. **Correct pre-activation defects — 0.5–1 day.** Remove reset-token logging; add explicit standalone kind; fix VAPID subject contract; move view-config boot; update compatibility/version docs.
2. **Disposable full verification — 0.5–1 day.** Provision an isolated MySQL tenant/schema, run all 19 skipped DB tests and `run-all.sh`, inspect failures, and discard the schema; the test guard explicitly requires isolation (`modules/harpp/tests/isolated-tenant-guard.php:5-7`).
3. **Migration rehearsal/backup — 0.5 day plus DB time.** Back up and apply 19 registered migrations in order. Migration 007 performs legacy backfill/validation and MySQL DDL auto-commits (`modules/harpp/docs/phase0-phase1-rollout.md:25-38`).
4. **Auth-owned seed/provision — 0.25 day.** Ensure the named tenant owner replaces deterministic row 1, decide whether admin/member fixtures remain, and verify all blocked hashes cannot authenticate (`modules/harpp/database/migrations/002_harpp_bootstrap_users.sql:1-10`; `modules/harpp/module.json:477-497`).
5. **Workspace/backfill check — 0.25 day.** Validate exactly one `legacy` workspace, memberships, conversation/decision scope, and message sequence before enabling scope flags (`modules/harpp/database/migrations/007_harpp_integrity_collaboration_foundation.sql:319-367`).
6. **Capability authorization review — 0.5 day.** Review all 36 exposes and caller/role policy; installer auto-seeds zero protocol rows because no expose declares `requires_protocol` (`kernel/Services/ModuleInstallService.php:66-91`; `modules/harpp/module.json:68-339`). Seed/approve the intended policy rows using the kernel's supported path.
7. **Settings defaults — 0.25 day.** Confirm migration-persisted feature flags and runtime defaults; deliberately leave scope/approval/fanout flags off until their rollout checks pass (`modules/harpp/module.json:499-621`; `modules/harpp/docs/phase0-phase1-rollout.md:11-34`).
8. **Templates/entity views/assets — 0.25 day.** Deploy the 14 templates and 16 assets, verify module static asset routing and PWA scope over HTTPS (`public/index.php:308-344`; §5 lint result), then smoke-test login and authenticated pages.
9. **VAPID and mail — 0.25 day.** Generate per-instance VAPID material, secure env/file permissions, configure reset mail, and test a real browser subscription/push. Never commit private key or raw reset token.
10. **Bridge coupling — 0.5 day.** Generate/secure the tenant bridge key, configure `base_url`/`tenant_id`, reconcile bridge version markers, run `harpp check`, and verify decision/message/run/status paths without `HARPP_INSECURE` (`tools/harpp-bridge/harpp_client.py:147-168,269-319`).
11. **Runner/deploy operations — 0.5–1 day.** Install a supervised daemon, choose wake/model/deploy policy, secure local config/profile files, and test lease reconciliation, risk approval, receipts, and deploy rollback on non-production targets.
12. **Commit activation state last — minutes after gates pass.** Current kernel requires explicit tenant `_module_enabled=true`; installed-but-inactive modules cannot participate (`src/helpers/module-registry.php:583-665`). Do this only after the prior gates, then confirm `/harpp/login`, root health, and audit/notification behavior.

The current install service's safe ordering is migrations → policy seeding → staged activation → committed activation (`kernel/Services/ModuleInstallService.php:150-202`), which should be preserved.

## 9. Recommendation

### Activate after the listed corrections

Reasons:

- The module has a coherent purpose and clean HTTP separation from the local harness.
- Its route/capability/entity/asset mechanisms are supported by this kernel.
- All 14 DiSyL templates parse with the current engine.
- The 269 static contract checks and encrypted-push round trip pass.
- It is **not** ready as-is because a raw password-reset bearer token is logged, operational docs/version markers are stale, one config field is misleading, its explicit manifest classification is absent, richer entity views are bootstrapped in the wrong lifecycle file, policy-row expectations are unclear, and 19 DB integration suites remain skipped.

The biggest risk is the bridge key's authority blast radius. Treat it as an owner-grade production secret, not an ordinary API token: isolate it per tenant/client, store it outside the repository, rotate it on any suspected exposure, and do not activate deploy/run approval surfaces until the skipped integration tests pass.

## Review boundary verification

No tenant activation/migration command or database-writing test was run. The only file intentionally created by this review is this report.

```text
$ git status --short .ai/harpp-module-review.report.md
?? .ai/harpp-module-review.report.md
```
