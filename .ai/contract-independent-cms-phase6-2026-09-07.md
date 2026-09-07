# CMS Akira Independent-CMS — Phase 6 gate: workflow → native `akira.workflow.*@1` over Kernel WorkflowEngine

task: Execute Phase 6 of the APPROVED independent-CMS contract (`.ai/current-task.md` gate 6). Re-scope the dormant
`cms-akira-workflow` (currently legacy-CMS handler stubs + `akira.content.get@1`/`workflow.state.get@1` deps) into
a NATIVE, TABLE-FREE Akira workflow module that maps Akira entity lifecycle (post) onto the Kernel WorkflowEngine /
WorkflowRuntime — evaluate + governed transition + run introspection, with Akira keys (never `cms.content`), role
denial, concurrency/retry/cancel/replay, tenant isolation, correlation. NO duplicate workflow tables. Phases 0-5B+4A
merged (core akira.post.*, shell, install service, editor, navigation, media, seo, theme).

## Kernel surface you MUST use (read these first; keep Kernel READ-ONLY)
- `kernel/App.php` registers Kernel-owned caps `workflow.state.get@1` + `workflow.transition@1` (payload passthru
  to `WorkflowRuntime::stateGet()/transition()`), `app()->workflow()` returns `WorkflowRuntime`,
  `app()->workflowEngine()` returns `WorkflowEngine`. WorkflowRuntime exposes `ensureDefinition()`,
  `getDefinition()`, `allowedActions()`, `getOrCreateInstance()`, `stateGet()`, `transition()`; WorkflowEngine
  exposes `start/advance/cancel/replay/getRun/getRuns/listRuns`. There is a LEGACY `ensureCmsContentWorkflow()`
  that registers the `cms.content` definition — the Akira member must NOT touch or depend on it (no `cms.content`
  assumptions).
- Sibling native members as structural pattern: `modules/cms-akira/cms-akira-navigation|media|seo|theme/`
  (module.json shape, capability handler maps, protocol-v2 governed mutations, tests, gitignore tracking). The
  theme member (4A) is the closest table-free precedent for settings/no-table + module-registry escalation.

## Contract specifics (honor exactly — master contract gate 6)
- Re-scope `cms-akira-workflow`: `kind: extension`, `extends: cms-akira-core`, depends = cms-akira-core +
  Kernel workflow caps ONLY. Owns `[]`, reads `[]`, migrations = ONLY table-free `001_initial.sql` marker (no
  002 — Kernel WorkflowEngine persistence only, no `cms_akira_workflow*` tables). Remove legacy handler stub +
  `akira.content.get@1` residue; `_enabled:false`.
- Capability family `akira.workflow.*@1` (freeze names), provider = this module (bridge to Kernel runtime):
  - `akira.workflow.evaluate@1` — given an Akira entity type+key (post slug/key — opaque ASCII string reference,
    never foreign SQL), return the workflow status + allowed actions for the CURRENT role (fail closed, role
    denial honored); projection allowlist only.
  - `akira.workflow.transition@1` — GOVERNED v2 mutation (`requires_protocol: v2`, effects.invalidates a single
    canonical tag `entity.list.workflow-run`): perform an allowed transition for an Akira entity via the Kernel
    runtime; role denial enforced; idempotent via kernel.idempotency + durable same-PDO audit via
    kernel.audit.record@1 (as siblings); returns projected new state.
  - Optional (only if your tests cover them deterministically): `akira.workflow.runs@1` (projected run
    introspection getRuns/listRuns with tenant + entity filters).
  - depends: kernel.idempotency.{hash,claim,commit,release}@1 + kernel.audit.record@1 + workflow.state.get@1
    (or document why the module calls `app()->workflow()` directly instead — prefer the capability so the kernel
    policy/schema metadata applies) + protocol-v2 policy seeding via CapabilityAuthorizationRegistry as siblings.
- Akira workflow definition: register ONE Akira-owned workflow definition keyed e.g. `akira.post` over
  entityType `post` (states/transitions mirroring the Akira post lifecycle — draft/review/approved/published with
  the same transition semantics core exposes) via `ensureDefinition()` at enable/seed time (idempotent). NEVER
  reuse `cms.content`/`cms` module/entityType.
- Concurrency/retry/cancel/replay: your contract tests must cover at least concurrent transition rejection (or
  kernel-guarded), cancel, and replay determinism as far as the Kernel engine supports them — run introspection is
  read-only projection. If full cancel/replay coverage requires Kernel behavior outside this member, document the
  exact recorded prerequisite and cover what is deterministic now.
- Tenant separation: entity type/key and workflow identity are tenant-scoped; never take tenant from payload.
  Table-free tenant isolation is at the CAPABILITY boundary: tenant A cannot evaluate/transition tenant B's Akira
  entity (negative cross-tenant case). No own table means no dedicated-DB table test — but record whether Kernel
  workflow rows are kernel-level (shared) vs tenant-local and classify as recorded prerequisite if shared, per
  master contract (transactional outbox / recorded Kernel prerequisite). Do not claim dedicated-DB table parity
  you did not prove.
- `_enabled:false` retained → TRACK: un-ignore `modules/cms-akira/cms-akira-workflow/**` in `.gitignore`.
- README: Akira workflow authority (definition key, states/transitions), capability list, idempotency/audit/
  role-denial semantics, kernel-prerequisite classification (workflow persistence shared vs tenant-local),
  evaluate/transition projection allowlists.
- IMPORTANT: profiles `profile-headless` + `profile-visual` already depend on `cms-akira-workflow` by ID (keep
  the ID stable — do not rename). Profile manifest edits come later (Phase 9). Leave profile module.json files
  untouched in this gate EXCEPT you must not break their dependency reference.

## Deliverables
1. modules/cms-akira/cms-akira-workflow re-scoped (native module.json akira.workflow.* + empty owns/reads + only
   table-free 001 migration; native handlers + helpers + capability handler map; remove residue), README.
2. Akira workflow definition seed (idempotent ensureDefinition at enable) + capability bridge to Kernel runtime.
3. Track via .gitignore; commit source + tests.
4. Tests (mirror sibling layout): evaluate (allowed actions by role, fail closed, role denial), transition (governed
   v2: allowed only, denied roles, idempotent, audited, invalidates entity.list.workflow-run), runs projection
   (tenant+entity filtered, allowlisted), cross-tenant negative, definition idempotent seed, authority zero on this
   member, certify.
5. Append result to the contract.

## Verification (do all)
- Workflow tests green; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 + media 37 + seo 39 +
  theme 36 green; authority 18/18; capability audit zero; module:certify --all (incl workflow); composer full;
  phpstan + cs-fixer (tracked workflow); logs clean; CI 6/6 (branch feat/independent-cms-phase6-workflow, commit,
  push, PR, checks 6/6, merge, checkout main+pull; report PR #; then docs-result PR merged too, as prior phases).
- grep over tracked workflow (incl staged ignored): no `cms.content`, `cms.content`, `akira.content.get@1`,
  `cmsRequireCap`, `cmsRender`, `cmsActiveTheme`, no `cms_akira_workflow` table creation; record the git ls-files
  proof. Confirm no migration 002 exists.
- MySQL 5.7 clean (no own table). `composer test` full green.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 7 Search ready?)

---

## Result (Phase 6)

status: PASS

task: Re-scope dormant `cms-akira-workflow` into a native, table-free `akira.workflow.*@1` bridge over the Kernel
WorkflowRuntime/WorkflowEngine, retain the stable module id and `_enabled:false`, and track the complete member.

changed:
  - `.gitignore` (track `modules/cms-akira/cms-akira-workflow/**`)
  - `modules/cms-akira/cms-akira-workflow/**` (native manifest, table-free migration marker, runtime bridge,
    HTTP handlers/routes, README, and workflow contract test)

implementation_summary: Exposes allowlisted `akira.workflow.evaluate@1`, governed protocol-v2
`akira.workflow.transition@1`, and entity-filtered `akira.workflow.runs@1`. Enable/bootstrap idempotently registers
one `akira.post` definition for entity type `post`, with draft/review/approved/published and
submit/approve/reject/publish/unapprove/unpublish role semantics. Transition requires optimistic `expected_status`,
uses Kernel durable idempotency, participates with WorkflowRuntime and Kernel audit on one PDO transaction, carries
correlation, and has exactly `entity.list.workflow-run` as its invalidation. Kernel-shared workflow rows have no
tenant column, so the capability boundary derives an unprojected tenant-namespaced subject id solely from trusted
Kernel tenant context; payload tenant identity is rejected. Runs are exact-entity filtered and project no payload,
context, definition id, or internal subject id. Kernel run cancellation and failed/cancelled replay behavior are
covered deterministically. No member workflow tables or migration 002 exist.

verification:
  - workflow contract 42/42
  - P1 38/38, P2 38/38, shell 21/21, install 23/23, editor 25/25, navigation 35/35, media 37/37,
    SEO 39/39, theme 36/36
  - navigation/media/SEO/theme dedicated topology regressions 6/6, 6/6, 6/6, 7/7
  - capability authority 18/18; capability audit zero findings; workbench workflow guard zero findings
  - `module:certify --all` all pass, including workflow 13/13
  - `composer test` 106/106; workflow-scoped PHPStan and PHP CS Fixer clean
  - tracked proof: `git ls-files modules/cms-akira/cms-akira-workflow` lists exactly README, 001 marker,
    handlers, helpers, module manifest, routes, and contract test
  - tracked forbidden grep clean for legacy content authority/helpers/theme and duplicate workflow table creation;
    migration directory contains only `001_initial.sql`
  - final CI 6/6 on PR #49 (Kernel contracts, coding standards, static analysis, MySQL 8, MySQL 5.7,
    MariaDB 10.6); merged by merge commit and branch deleted

scope: `.gitignore`, `modules/cms-akira/cms-akira-workflow/**`

unexpected_files: none. Existing unrelated untracked `.ai/*` working-tree files were not staged or modified except
this authoritative contract result in the separate documentation PR.

risks: Kernel workflow definitions/instances/runs are shared Kernel persistence without `tenant_id`; boundary
namespacing is delivered, but dedicated-DB Kernel migration/audit parity is not claimed. WorkflowRuntime emits its
transition event before the member-owned outer transaction commits and has no tenant-local transactional outbox;
guaranteed propagation/outbox parity remains an explicit Kernel prerequisite. WorkflowRuntime also predates an
internal KernelPDO escalation seam, so the bridge narrowly suspends and unconditionally restores ModuleDB identity
only around calls to the documented Kernel workflow services.

unresolved: Recorded Kernel prerequisites above; no Phase 6 member blocker.

recommended_next_state: Phase 7 Search ready.
