You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 7 gate (search)** in Ikabud
Kernel OS 6.x (repo root /var/www/html/ikabudsix, main 66d4b84 — Phases 0-6 merged). Execute the search re-scope +
rename per the authoritative approved contract:

    .ai/contract-independent-cms-phase7-2026-09-07.md   (READ FIRST — full specifics)
    .ai/current-task.md   (gate 7 Search + native re-scope + naming + tenant-separation sections)

Phase 7 = Git-move `modules/cms-akira/cms-akira-search-adapter/` → `modules/cms-akira/cms-akira-search/` and re-scope
it into a NATIVE Akira search member: own cms_akira_search_documents (tenant-scoped, unique
(tenant_id, entity_type, document_key)), native akira.search.document.build@1 / upsert@1 / delete@1 / query@1,
deterministic rebuild, allowlisted projections, converge-on-Post-lifecycle (publish/unpublish/delete) via the
documented seam or a RECORDED Kernel prerequisite, NO search.index.* / akira.content.get@1 residue; TRACK +
enable (_enabled:false). Kernel READ-ONLY. Follow the tracked sibling members (navigation/media/seo/workflow) as
the structural pattern.

## Key contract points (see contract file for the full authoritative list)
1. Git-move dir + rename id/name to cms-akira-search; kind adapter → extension; depends cms-akira-core only;
   owns/reads cms_akira_search_documents; migrations = 001 marker + 002_create_native_search_documents.sql
   (MySQL 5.7 InnoDB utf8mb4_unicode_ci, byte-budget composite unique incl tenant prefix); remove
   akira.content.get@1 + search.index.upsert@1 residue + legacy /admin nav; _enabled:false.
2. Update the TWO dormant profile manifests (profile-headless + profile-visual, `installs` + `depends`) to
   reference cms-akira-search instead of cms-akira-search-adapter (LOCAL edit only — profiles stay gitignored
   until Phase 9; do NOT track them here).
3. Capabilities akira.search.*@1: document.build (allowlisted projection, fail closed), upsert@1 + delete@1
   (GOVERNED v2: idempotent + durable same-PDO audit + effects.invalidates single tag entity.list.search-document),
   query@1 (allowlisted, paged, deterministic, fail closed). depends kernel.idempotency.* + kernel.audit.record@1
   + protocol-v2 policy seeding (as siblings).
4. Lifecycle convergence: publish → upsert doc; unpublish/delete → remove doc. Use the deterministic documented
   seam the tracked members use; if true tenant-local outbox infra is absent, record the Kernel prerequisite
   explicitly (like Phase 6 did) and prove convergence via the seam + a deterministic rebuild command tested
   end-to-end (drift → rebuild → converged).
5. Track via .gitignore (modules/cms-akira/cms-akira-search/**); commit source + tests incl. the git-move rename.
6. Append result to the contract.

## Verification (do all)
- Search tests green + dedicated-tenant case; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 +
  media 37 + seo 39 + theme 36 + workflow 42 green; authority 18/18; capability audit zero; module:certify --all
  (incl search); composer full; phpstan + cs-fixer clean on tracked search; logs clean.
- grep repo-wide (tracked tree): no cms-akira-search-adapter in any tracked file; no search.index.* /
  akira.content.get@1 in tracked search; record git ls-files proof of the rename (old path absent, new path
  present). Confirm 002 migration + unique index; MySQL 5.7 clean.
- CI 6/6 expected: branch feat/independent-cms-phase7-search, commit (incl git-move), push, gh pr create,
  gh pr checks --watch (6/6), gh pr merge --merge --delete-branch, checkout main + pull. Report PR number. Then
  docs-result PR merged.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 8 AI ready?)
