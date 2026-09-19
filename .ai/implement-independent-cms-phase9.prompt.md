You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 9 gate (profiles)** in Ikabud
Kernel OS 6.x (repo root /var/www/html/ikabudsix, main 8a357a0 — Phases 0-8 merged). Execute the profiles gate per
the authoritative approved contract:

    .ai/contract-independent-cms-phase9-2026-09-07.md   (READ FIRST — full specifics + exact compositions)
    .ai/current-task.md   (gate 9 Profiles + profiles adoption lines)

Phase 9 = turn the four DORMANT profile scaffolds into clean, data-free DEPENDENCY/INSTALL-METADATA-ONLY bundles
(module.json + README.md only — remove database/, handlers.php, helpers.php, routes.php, 001_initial.sql,
routes:true, legacy /admin nav, and from profile-standard the FORBIDDEN entry_module/entry_delegate/
authentication_provider residue), fix each `installs` to the EXACT composition (see contract: minimal = core+shell+
editor; standard = core+shell+editor+theme+navigation+media+seo; headless = core+workflow+search), TRACK + enable
(_enabled:false) minimal/standard/headless at this gate, and hold profile-visual as a CONTRACT-ONLY HOLD (cleaned
locally only — do NOT track/commit it until the Builder gate). Kernel READ-ONLY.

## Key contract points (see contract file for the full authoritative list)
1. Every profile (incl. visual-locally): delete database/, handlers.php, helpers.php, routes.php; module.json →
   kind profile, installs + depends = exact member list, owns/reads [], migrations [], routes removed/false, nav
   removed, capabilities empty, _enabled:false. No entry_module/entry_delegate/authentication_provider anywhere.
2. standard installs currently lack cms-akira-shell + depend on fewer members than installs — fix to the full 7 +
   shell composition in BOTH installs and depends. headless/visual should already reference cms-akira-search
   (Phase 7 rename) — verify.
3. Manifest/runtime parity: module:certify --all passes for each tracked profile; validateModuleManifestV1 accepts
   migrations [] for a profile (fix ONLY within profile manifests — never weaken the validator); tracked
   manifest_suite/module_suite tests stay green unchanged (they use synthetic in-memory profiles).
4. Track minimal/standard/headless via .gitignore; visual stays gitignored. Commit source.
5. architecture:check must now be CLEAN — the long-standing dormant cms-akira-profile-standard provider-cms
   auth-route violation is resolved once its entry/auth fields are removed.
6. Append result to the contract.

## Verification (do all)
- module:certify --all incl. the 3 tracked profiles; manifest validator accepts each; manifest_suite_contract +
  module_suite_certification + module_suite_compatibility tests green UNCHANGED; authority 18/18; capability audit
  zero; composer full 106/106; phpstan + cs-fixer clean on tracked profiles; `php ikabud architecture:check`
  clean; logs clean; CI 6/6 (branch feat/independent-cms-phase9-profiles, commit, push, gh pr create,
  gh pr checks --watch 6/6, gh pr merge --merge --delete-branch, checkout main + pull; report PR #; then
  docs-result PR merged).
- grep: no entry_module / authentication_provider / entry_delegate in any profile; no tracked profile has
  handlers/routes/database files; `git ls-files modules/cms-akira | grep profile` = exactly minimal, standard,
  headless (+ README), NOT visual; all prior member suites green at their published counts (P1 38, P2 38, shell 21,
  install 23, editor 25, navigation 35, media 37, seo 39, theme 36, workflow 42, search 39, ai 28).

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Builder gate ready — profile-visual certified/tracked there)
