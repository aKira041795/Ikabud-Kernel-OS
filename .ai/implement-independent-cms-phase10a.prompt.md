You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 10A gate (builder composition
authority, server)** in Ikabud Kernel OS 6.x (repo root /var/www/html/ikabudsix, main d63d657 — Phases 0-9 merged).
Execute sub-gate 10A per the authoritative approved contract:

    .ai/contract-independent-cms-phase10a-2026-09-08.md   (READ FIRST — full specifics)
    .ai/current-task.md   (gate 10 Builder + naming/tenant/MySQL sections)

Phase 10A = re-scope the dormant `cms-akira-builder` into a NATIVE, SERVER-SIDE Akira composition authority: own
cms_akira_compositions + cms_akira_composition_revisions (tenant-scoped), native akira.builder.*@1 capabilities
(compositions/get/revisions/render + governed v2 create/update/publish/unpublish/delete/validate), revision/publish
contract with optimistic concurrency, idempotency, durable audit, tenant isolation, preview/published separation,
fail-closed tree/block/prop validation, and deterministic render through ArkRendererResolver→DiSyL with an
explicit Akira theme slug. NO React/Vite admin app in this sub-gate (that is 10B). TRACK + enable
(_enabled:false). Kernel READ-ONLY. Follow the tracked sibling members as structural pattern.

## Key contract points (see contract file for the full authoritative list)
1. Re-scope module.json: kind extension / extends cms-akira-core / depends cms-akira-core + kernel
   idempotency/audit caps; owns/reads cms_akira_compositions + cms_akira_composition_revisions; migrations = 001
   marker + 002_create_compositions.sql (MySQL 5.7 InnoDB utf8mb4_unicode_ci, byte-budget indexes, no JSON_TABLE);
   remove scaffold residue; _enabled:false.
2. Capabilities akira.builder.*@1: compositions@1/get@1/revisions@1/render@1 (projections, fail closed) +
   governed v2 create/update/publish/unpublish/delete/validate@1 (idempotency + durable same-PDO audit +
   effects.invalidates single tag entity.list.composition + protocol-v2 policy seeding). update@1 requires a base
   revision id (optimistic concurrency; stale → typed conflict result). render@1 resolves the Akira theme slug via
   akira.theme.resolve@1 and renders through ArkRendererResolver→DiSyL; fails closed.
3. Validation fail-closed: allowlisted blocks/props; reject script/javascript:/data:/raw include/PHP/SQL; content
   HTML sanitized via editor semantics (reuse cms-akira-editor sanitize through capability, do not rebuild an
   unsafe path); depth/size caps; unknown block/prop rejected.
4. Publish contract: publish promotes current preview tree → published_revision_id + version bump; preview edits
   never mutate published until publish; render published returns published tree. Cross-member SQL writes to core
   tables are FORBIDDEN — if published output must appear on the public Post path, document the integration seam as
   a recorded decision/prerequisite in README (no cms_akira_posts write from builder).
5. Tenant separation at ModuleDB boundary; isolation tests shared + dedicated.
6. Track via .gitignore (modules/cms-akira/cms-akira-builder/**); commit source + tests.
7. Append result to the contract.

## Verification (do all)
- Builder tests green + dedicated-tenant case; all prior suites green at published counts; authority 18/18;
  capability audit zero; module:certify --all (incl builder); composer full; phpstan + cs-fixer clean on tracked
  builder; logs clean.
- grep over tracked builder (incl staged ignored): no cms.builder.* / legacy modules/cms include or table /
  akira.content.get@1 / eval of user tree / cmsActiveTheme / JSON_TABLE / window fn; record git ls-files proof.
  MySQL 5.7 clean.
- CI 6/6 expected: branch feat/independent-cms-phase10a-builder, commit, push, gh pr create, gh pr checks --watch
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
recommended_next_state: (Phase 10B admin UI + preview + profile-visual track ready?)
