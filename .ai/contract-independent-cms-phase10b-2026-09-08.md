# CMS Akira Independent-CMS — Phase 10B gate: builder admin UI + preview + CSP + profile-visual track

task: Execute sub-gate 10B (the final gate) of the APPROVED independent-CMS contract (`.ai/current-task.md`
gate 10). Build the Akira BUILDER ADMIN UI + authenticated preview transport + CSP-safe asset build on top of the
Phase 10A server composition authority (`akira.builder.*@1`, own cms_akira_compositions/_revisions, tracked
modules/cms-akira/cms-akira-builder), then certify/track/enable `profile-visual` from an empty tenant state.
Phases 0-10A merged (main 89f00e7). Kernel READ-ONLY. This is the LAST member gate.

## Current architecture (read first)
- `modules/cms-akira/cms-akira-builder/` (tracked, 10A): module.json (akira.builder.*@1 exposes, owns 2 tables,
  _enabled:false), helpers.php (fail-closed validate, revision/publish/optimistic-concurrency mutations, projections,
  render-through-ARK), tests builder_contract 34/34 + builder_dedicated_tenant 6/6.
- `modules/cms-akira/cms-akira-shell/` (tracked, single admin shell, Kernel-auth entry): routes /cms-akira-shell/*
  (dashboard/login/posts CRUD/health/forbidden), handlers render plain PHP admin pages, nav in module.json. This is
  the ONLY admin shell — the builder admin UI mounts under its route namespace + kernel-auth guard, NEVER legacy
  cmsRender/cmsAdminContext.
- `modules/cms-akira/cms-akira-core/` akira.post.*@1 + entity projections; `cms-akira-editor` akira.editor.sanitize@1
  (rich_text must equal its output); `cms-akira-theme` akira.theme.resolve@1 (theme slug); ArkRendererResolver →
  DiSyL with explicit slug; tracked Akira theme storage/cms-themes/cms-akira-posts (+ composition-page.disyl +
  entity.detail.composition registered in 10A).
- NO React/Vite app exists in this repo yet. Toolchain must target node v18.19.1 + npm 9.2.0 (the only node on this
  host): use Vite ^5 + React ^18 + TypeScript ^5 (Vite 5 supports node 18). Do NOT use Vite 6/7 (needs node 20+).

## Deliverables (honor exactly)
1. Authenticated builder JSON endpoints (routes in cms-akira-builder routes.php under /api/v1/cms-akira/builder/*)
   bridging to the akira.builder.* capability handler map, Kernel-auth + role-guarded (admin), session+JWT authz:
   compositions list/get; create/update (draft save with base_revision_id optimistic concurrency); validate;
   publish/unpublish/delete; revisions; render (preview draft vs published, fail closed). These are thin
   capability-bridge HTTP handlers over the 10A helpers — no new business logic in HTTP layer.
2. React/Vite/TS admin app under `modules/cms-akira/cms-akira-builder/admin-ui/` (npm install-able, type-checkable,
   buildable on node 18). Functional composition-editor MVP (NOT pixel-perfect):
   - compositions list (pick an Akira post to attach a composition to; list existing),
   - tree editor (JSON tree editor + basic validated block form: section/heading/paragraph/rich_text/image/button
     with the 10A allowlisted props; run akira.builder.validate@1 before save),
   - save draft (update, base revision), validate, revisions list,
   - preview pane (GET rendered HTML via the render endpoint — draft preview vs published separation visible),
   - publish / unpublish actions with confirm.
   - Fail-closed on the client too: render/save errors surfaced, no raw HTML injection into React (React escapes by
     default; preview HTML is server-rendered output displayed in an iframe/srcdoc or sandboxed area).
3. Shell pages mounting the app under the shell guard: list page + editor page at
   /cms-akira-shell/compositions + /cms-akira-shell/compositions/{key}/edit (handlers in cms-akira-shell) that
   serve the built assets + a container div; add nav entries to cms-akira-shell module.json nav (Compositions).
   Do NOT create a second entry module.
4. Asset build + CSP: `npm run build` emits the production bundle under `public/` (e.g. public/admin/assets/
   cms-akira-builder/*) so it is served as static 'self' assets. The app must not require 'unsafe-eval' (it is a
   prebuilt bundle; no runtime eval). No new nonce requirements; document the CSP posture in README. Commit the
   SOURCE under admin-ui/src; generated bundle may be committed under public/ (confirm repo convention: legacy
   builder bundles were committed under public/admin/assets — follow that).
5. profile-visual: after the builder admin + endpoints certify and tests pass, un-ignore
   `modules/cms-akira/cms-akira-profile-visual/**` and commit it (module.json already references builder + the full
   graph). Verify profile-visual certifies (13/13 metadata-only profile). Enablement from empty tenant state is
   proven by the install-path test (module-install service closure) — record the exact test + outcome in the result.
6. Tests: extend builder module tests or add shell/browser-layer tests for the admin endpoints (list/create/save/
   validate/publish/render-preview/unpublish/delete via HTTP bridge, authz denial, fail-closed errors, preview vs
   published). Add a Playwright-tier journey (PW-2) for the builder admin: shell login → compositions → create →
   save draft → preview → publish (reuse the repo's Playwright harness if present; otherwise a documented
   runnable script). Keep all prior suites green.
7. README (builder + profile-visual): admin app build/run instructions (npm ci/install, dev, type-check, build),
   route map, CSP posture, preview transport, install/enable path for visual profile, capability list.
8. Append result to the contract.

## Verification (do all)
- `npm ci`/`npm install` + `npm run type-check` + `npm run build` succeed under node 18 in admin-ui.
- New/extended PHP tests green; builder 34+6 green; all prior suites green at published counts; authority 18/18;
  capability audit zero; module:certify --all (incl builder + profile-visual 13/13); composer full; phpstan +
  cs-fixer clean on tracked builder/shell additions; logs clean; Playwright journey passes (or documented evidence).
- grep: no legacy cmsRender/cmsAdminContext/`modules/cms` in the new UI/server path; no unsafe-eval requirement in
  the built bundle config; profile-visual tracked + no entry/auth residue; git ls-files proof.
- CI 6/6: branch feat/independent-cms-phase10b-builder-ui, commit, push, PR, checks 6/6, merge, checkout
  main+pull; report PR #. Then docs-result PR merged.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (ALL independent-CMS member gates complete → final full verification + completion record)

## Implementation result — 2026-09-08

status: PASS (local gate + CI 6/6 on PR #59, merged c5cd987)

task: Phase 10B (final independent-CMS member gate) on the 10A server authority: build the Akira BUILDER
ADMIN (React/Vite/TS MVP under cms-akira-builder/admin-ui), authenticated JSON capability-bridge endpoints,
shell-guarded admin pages, CSP-safe committed asset build, certify/track/enable profile-visual from an
empty tenant state. Kernel READ-ONLY.

changed:
- modules/cms-akira/cms-akira-builder/routes.php: adds GET/POST /api/v1/cms-akira/builder/* endpoints
  (compositions/get/revisions/render + validate/create/update/publish/unpublish/delete) mapped to thin
  HTTP bridge handlers.
- modules/cms-akira/cms-akira-builder/handlers.php: cabBuilderApi* bridge — Kernel-auth + admin-role
  guard, JSON body read, entity ref from route/body (tenant always from Kernel), capability forwarding,
  typed CabBuilderException chain-walk to HTTP status, fail-closed 500. No new business logic.
- modules/cms-akira/cms-akira-builder/admin-ui/: React 18 + Vite ^5 + TypeScript ^5 functional
  composition-editor MVP (package.json + lockfile, vite.config.ts, tsconfig, index.html, src types/api/
  app/styles/main). List + attach-to-post, JSON tree editor + 10A allowlisted block form, save draft with
  base_revision_id, validate, revisions, preview (sandboxed-iframe srcDoc, draft vs published),
  publish/unpublish/delete with confirm. README.
- public/admin/assets/cms-akira-builder/*: committed production bundle (static 'self', no eval/sourcemaps).
- modules/cms-akira/cms-akira-shell/: routes + handlers + helpers for /cms-akira-shell/compositions +
  /{key}/edit mounting the app under the Kernel-auth shell guard; module.json nav Compositions added; no
  second entry module. shell_contract_test updated (nav 4, builder mount/route assertions).
- modules/cms-akira/cms-akira-profile-visual/ (module.json + README) unignored + committed; builder + shell
  READMEs updated; .gitignore unignores profile-visual.
- tests: builder_admin_http_bridge_test.php (20/20), builder_profile_visual_install_test.php (13/13);
  tests/browser/akira-builder-admin.spec.ts PW-2 journey (documented, runnable against a live tenant).
- .ai/contract-independent-cms-phase10b-2026-09-08.md: this result appended (tracked docs PR).

implementation_summary:
- Bridge is a thin HTTP layer over the 10A akira.builder.*@1 handler map; reads/capability calls reuse the
  10A helpers (no duplicated validation/optimistic-concurrency/idempotency/audit/tenant logic). Guard denies
  anonymous 401 and non-admin 403; malformed JSON 400; stale base 409; hostile tree rejected; unexpected
  throw fails closed 500. Governed mutation typed failures are recovered from the capability-bus wrapped
  chain. Preview vs published separation proven over HTTP (published render 404 until publish; editing
  preview never mutates published output).
- Admin app mounts inside the authenticated shell; boot JSON lists attachable posts; API base is
  /api/v1/cms-akira/builder with credentials:include; React escapes by default; preview HTML shown in
  <iframe sandbox srcDoc>. npm install/type-check/build succeed on node 18 (Vite ^5/React ^18/TS ^5; no
  Vite 6/7).
- profile-visual: data-free install bundle (module.json+README) certified 13/13; empty-tenant enable of the
  full visual closure incl. cms-akira-builder proven through the Kernel module-install activation path
  (staging non-routable -> committed generation -> enabled, idempotent rerun, tenant isolation).

verification:
- Builder 34/34 + dedicated 6/6 retained green; builder_admin_http_bridge 20/20; builder_profile_visual_install
  13/13; shell 25/25 (updated). npm ci-equivalent install + type-check + build pass on node 18.19.1.
- module:certify --all: builder + profile-visual (and all Akira members + gui-settings) 13/13.
- manifest_suite_contract 28/28, module_suite_certification 15/15, module_suite_compatibility 18/18,
  capability_authority_audit 18/18, capability_audit zero; architecture:check 6/6; phpstan clean on tracked
  builder/shell additions; php-cs-fixer clean on tracked additions; logs clean.
- grep: no legacy cmsRender/cmsAdminContext/modules/cms in the new UI/server path; no unsafe-eval/eval/new
  Function requirement in the bundle config or built output; profile-visual tracked with no entry/auth
  residue and no handlers/routes/database files; git ls-files proof recorded (admin-ui source + public
  bundle + profile-visual + tests committed).
- PW-2 browser journey spec committed (tests/browser/akira-builder-admin.spec.ts): shell login ->
  compositions -> create -> save draft -> preview -> publish. Documented runnable against a live tenant;
  the builder module is tracked _enabled:false so routing only exists after the module-install path, which
  is proven by the module-install activation tests (not by a browser). No live Akira tenant/server is
  available in this repo's kernel-only installer, so the journey is delivered as a documented runnable
  script (not executed) — evidence recorded in the spec header.
- CI 6/6 on PR #59: kernel-contracts, test (mysql-8), test (mysql-5.7), test (mariadb-10.6),
  static-analysis, coding-standards — merged c5cd987.

scope: cms-akira-builder (bridge + admin-ui + README), cms-akira-shell (routes/handlers/helpers/nav/test),
  public/admin/assets/cms-akira-builder bundle, profile-visual (track), tests/browser spec, .gitignore,
  READMEs. Kernel untouched.
unexpected_files: none.
risks: none blocking. The Playwright journey is documented but not executed here (requires a live tenant
  with the builder module installed and the full-deployment Playwright harness, which this kernel-only
  installer does not ship). Local strict manifest guard fails solely on the pre-existing gitignored
  daily-ledger module (absent from the committed repo / CI); committed manifests certify clean.
unresolved: none.
recommended_next_state: ALL independent-CMS member gates (0-10B) are complete. Recommended next state: a
  final full cross-suite verification + completion record of the independent-CMS programme, then a
  separately-gated core/public-path integration consuming akira.builder.render@1 if composition output is
  to replace Post body output (recorded seam).

