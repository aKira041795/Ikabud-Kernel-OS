You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 4A gate (theme)** in Ikabud
Kernel OS 6.x (repo root /var/www/html/ikabudsix, main ca5e428 — Phases 0-5B merged). Execute the theme re-scope
per the authoritative approved contract:

    .ai/contract-independent-cms-phase4a-2026-09-07.md   (READ FIRST — full specifics)
    .ai/current-task.md   (gate 4A Theme + native re-scope + naming + tenant-separation sections)

Phase 4A = re-scope the dormant `cms-akira-theme` (currently pure LEGACY-CMS residue) into a NATIVE, TABLE-FREE
Akira theme module that owns ARK theme resolution + tenant-scoped activation (module setting, no new table),
exposing akira.theme.resolve@1 / registry@1 / validate@1 + governed v2 activate@1, and TRACK + enable
(_enabled:false). Kernel READ-ONLY (read ArkRendererResolver/ThemeManifestValidator/ThemeDefinitionLoader but do
not modify them). Follow the Phase 4/5A/5B sibling members as the structural pattern
(modules/cms-akira/cms-akira-navigation|media|seo).

## Key contract points (see contract file for the full authoritative list)
1. Re-scope module.json: kind extension / extends cms-akira-core / depends cms-akira-core only; owns_tables [] +
   reads_tables []; migrations = ONLY table-free 001_initial.sql ledger marker (NO 002); no legacy /admin nav;
   remove akira.content.get@1 residue; _enabled:false.
2. Capabilities akira.theme.*@1: resolve (module-setting → active_theme_slug request context → validated
   'cms-akira-posts' fallback; reject unvalidated slugs), registry (projected-fields-only), validate (manifest,
   renderer-registry uniqueness, disyl:lint, traversal protection, projected context_keys, fallback), and governed
   v2 activate (module SETTING write — no table; idempotent + durable same-PDO audit + effects.invalidates
   theme.active; protocol-v2 policy seeding as siblings).
3. Integration seam: core's cacPostThemeSlug() reads kernel_request_context_get('active_theme_slug') with
   'cms-akira-posts' fallback and passes it to app()->arkRenderers(). Your module must feed that seam so its
   resolved slug is what render paths use when enabled — wire the MINIMAL seam (do not rewrite core render
   logic), never resurrect cmsActiveTheme(). Record the exact seam in README.
4. Admin UI: minimal Akira shell-guarded theme surface (list/active/validate/activate) under the shell route
   namespace or capability-backed JSON endpoints + shell panel — never legacy cmsRender/cmsAdminContext. Record
   the decision.
5. Track via .gitignore (modules/cms-akira/cms-akira-theme/**); commit source + tests.
6. Append result to the contract.

## Verification (do all)
- Theme tests green + dedicated-tenant case; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 +
  media 37 + seo 39 green; authority 18/18; capability audit zero; module:certify --all (incl theme); composer
  full; phpstan + cs-fixer clean on tracked theme; logs clean.
- grep over tracked theme (incl staged ignored): no cmsActiveTheme / cmsRender / cmsAdminContext / cmsRequireCap /
  akira.content.get@1 / /cms/admin/; record the git ls-files proof. php ikabud theme:validate + disyl:lint on the
  cms-akira-posts theme clean.
- CI 6/6 expected: branch feat/independent-cms-phase4a-theme, commit, push, gh pr create, gh pr checks --watch
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
recommended_next_state: (Phase 6 Workflow ready?)
