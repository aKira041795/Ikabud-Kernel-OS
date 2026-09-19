You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 6 gate (workflow)** in Ikabud
Kernel OS 6.x (repo root /var/www/html/ikabudsix, main 3efb021 — Phases 0-5B + 4A merged). Execute the workflow
re-scope per the authoritative approved contract:

    .ai/contract-independent-cms-phase6-2026-09-07.md   (READ FIRST — full specifics)
    .ai/current-task.md   (gate 6 Workflow + native re-scope + tenant-separation sections)

Phase 6 = re-scope the dormant `cms-akira-workflow` (legacy handler stubs + akira.content.get@1 residue) into a
NATIVE, TABLE-FREE Akira workflow module that maps Akira entity lifecycle (post) onto the Kernel WorkflowEngine /
WorkflowRuntime via akira.workflow.*@1 capabilities (evaluate + governed transition + run introspection), with
Akira keys (no cms.content), role denial, concurrency/retry/cancel/replay, tenant isolation, correlation, and NO
duplicate workflow tables. Kernel READ-ONLY (use app()->workflow()/workflowEngine() + Kernel caps
workflow.state.get@1 / workflow.transition@1; do not modify Kernel). Follow the table-free theme member (4A) and
table-owning siblings (navigation/media/seo) as structural pattern.

## Key contract points (see contract file for the full authoritative list)
1. Re-scope module.json: kind extension / extends cms-akira-core; depends = cms-akira-core + Kernel workflow
   caps; owns_tables [] + reads_tables []; migrations = ONLY table-free 001_initial.sql marker (NO 002, no
   cms_akira_workflow* tables); remove legacy handler + akira.content.get@1 residue; _enabled:false.
2. Capabilities akira.workflow.*@1 (provider = this module, bridge to Kernel runtime): evaluate@1 (entity
   type+key → status + allowed actions for current role; fail closed; role denial), transition@1 (GOVERNED v2:
   allowed-only, idempotent + durable same-PDO audit + effects.invalidates single tag entity.list.workflow-run),
   optional runs@1 (projected run introspection, tenant+entity filtered). depends:
   kernel.idempotency.{hash,claim,commit,release}@1 + kernel.audit.record@1 + workflow.state.get@1 + protocol-v2
   policy seeding (as siblings).
3. Akira definition seed: register ONE Akira-owned workflow definition keyed akira.post / entityType post (states
   mirroring the post lifecycle) via ensureDefinition() at enable time, idempotent; NEVER reuse cms.content/cms.
4. Tenant separation at the capability boundary: tenant A cannot evaluate/transition tenant B's entity (negative
   cross-tenant case). No own table → record whether Kernel workflow rows are shared vs tenant-local and classify
   as recorded prerequisite if shared (do not claim unproven dedicated parity).
5. IMPORTANT: keep module ID cms-akira-workflow stable — profile-headless + profile-visual already depend on it.
   Do NOT edit profile manifests in this gate.
6. Track via .gitignore (modules/cms-akira/cms-akira-workflow/**); commit source + tests.
7. Append result to the contract.

## Verification (do all)
- Workflow tests green; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 + media 37 + seo 39 +
  theme 36 green; authority 18/18; capability audit zero; module:certify --all (incl workflow); composer full;
  phpstan + cs-fixer clean on tracked workflow; logs clean.
- grep over tracked workflow (incl staged ignored): no cms.content / akira.content.get@1 / cmsRequireCap /
  cmsRender / cmsActiveTheme / cms_akira_workflow table creation; confirm no migration 002; record the git
  ls-files proof.
- CI 6/6 expected: branch feat/independent-cms-phase6-workflow, commit, push, gh pr create, gh pr checks --watch
  (6/6), gh pr merge --merge --delete-branch, checkout main + pull. Report PR number. Then docs-result PR merged.

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
