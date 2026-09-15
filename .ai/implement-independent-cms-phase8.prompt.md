You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 8 gate (ai)** in Ikabud Kernel
OS 6.x (repo root /var/www/html/ikabudsix, main f49c891 — Phases 0-7 merged). Execute the ai re-scope per the
authoritative approved contract:

    .ai/contract-independent-cms-phase8-2026-09-07.md   (READ FIRST — full specifics)
    .ai/current-task.md   (gate 8 AI + native re-scope sections)

Phase 8 = re-scope the dormant `cms-akira-ai` (legacy handler stubs + akira.content.get@1 residue) into a NATIVE,
TABLE-FREE, PROVIDER-FREE Akira AI module: retains akira.ai.summary.suggest@1 (single read-only first-mode cap),
consumes core post projections via entity.get.post@1 (never SQL into cms_akira_posts, never tenant from payload),
returns a deterministic local suggestion result {status:ok,summary,keywords} over allowlisted projection fields AND
a TYPED NON-FATAL UNAVAILABLE result {status:unavailable,reason} for missing-entity/config-off (never throws/500,
never leaks cross-tenant), and never becomes a dependency of another member/profile. Kernel READ-ONLY. There is NO
kernel ai.* capability and NO provider SDK allowed — do not invent or require either. Mirror the table-free editor
member (modules/cms-akira/cms-akira-editor/) as the structural pattern.

## Key contract points (see contract file for the full authoritative list)
1. Re-scope module.json: kind extension / extends cms-akira-core; depends = [entity.get.post@1] only;
   owns_tables [] + reads_tables []; migrations = ONLY table-free 001_initial.sql marker (NO 002); remove legacy
   /admin handler + akira.content.get@1 residue; _enabled:false.
2. akira.ai.summary.suggest@1: read-only; deterministic extractive summary + keyword extraction over allowlisted
   projection fields (offline-testable); typed non-fatal unavailable branch for missing entity / backing-off
   (no throw). No mutation caps / no effects / no requires_protocol v2 needed.
3. No provider SDK / no table / no network. AI never a dependency of any other member/profile (grep to confirm).
4. Track via .gitignore (modules/cms-akira/cms-akira-ai/**); commit source + tests.
5. Append result to the contract.

## Verification (do all)
- AI tests green; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 + media 37 + seo 39 + theme
  36 + workflow 42 + search 39 green; authority 18/18; capability audit zero; module:certify --all (incl ai);
  composer full; phpstan + cs-fixer clean on tracked ai; logs clean.
- grep over tracked ai (incl staged ignored): no akira.content.get@1 / cmsRequireCap / cmsRender / cmsActiveTheme /
  migration 002 / provider SDK / network call; record the git ls-files proof; confirm no member/profile depends on
  cms-akira-ai.
- CI 6/6 expected: branch feat/independent-cms-phase8-ai, commit, push, gh pr create, gh pr checks --watch (6/6),
  gh pr merge --merge --delete-branch, checkout main + pull. Report PR number. Then docs-result PR merged.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 9 profiles ready?)
