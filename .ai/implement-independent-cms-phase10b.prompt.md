You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 10B gate (builder admin UI +
preview + CSP + profile-visual track)** in Ikabud Kernel OS 6.x (repo root /var/www/html/ikabudsix, main 89f00e7 —
Phases 0-10A merged). Execute the FINAL member gate per the authoritative approved contract:

    .ai/contract-independent-cms-phase10b-2026-09-08.md   (READ FIRST — full specifics)
    .ai/current-task.md   (gate 10 Builder + security/tenant/MySQL sections)

Phase 10B = build the Akira BUILDER ADMIN on the Phase 10A server authority: authenticated JSON capability-bridge
endpoints, a React/Vite/TS functional composition-editor MVP under modules/cms-akira/cms-akira-builder/admin-ui
(node 18 + Vite ^5 + React ^18 + TS ^5 — the ONLY node on this host is v18.19.1; do NOT use Vite 6/7), shell-guarded
admin pages mounting the app under /cms-akira-shell, CSP-safe asset build under public/, then certify/track/enable
profile-visual from empty tenant state. Kernel READ-ONLY. This is the LAST member gate.

## Key contract points (see contract file for the full authoritative list)
1. Auth JSON endpoints in cms-akira-builder routes (/api/v1/cms-akira/builder/*) bridging to the 10A capability
   handler map (list/get/create/update-draft-with-base/validate/publish/unpublish/delete/revisions/render-preview).
   Kernel-auth + role-guarded; thin HTTP layer, no new business logic.
2. React/Vite/TS app in admin-ui/: npm install + type-check + build must succeed on node 18. Functional MVP:
   compositions list, JSON/validated-block tree editor (10A allowlist), save draft (base revision), validate,
   revisions, preview pane (server-rendered draft vs published), publish/unpublish with confirm. React escapes by
   default; preview HTML in a sandboxed/iframe area. Fail closed on errors.
3. Shell pages under the shell guard: /cms-akira-shell/compositions + /{key}/edit serving the built assets + a
   container div; add nav entries to cms-akira-shell module.json (Compositions). NO second entry module.
4. Asset build: npm run build → public/admin/assets/cms-akira-builder/* (static 'self'); no unsafe-eval needed.
   Commit admin-ui/src source; follow repo bundle-under-public convention. Document CSP posture in README.
5. profile-visual: un-ignore + commit modules/cms-akira/cms-akira-profile-visual/**; certify 13/13 metadata-only;
   prove install/enable from empty tenant state via the module-install path test; record outcome.
6. Tests: extend builder tests for the HTTP bridge (authz denial, fail-closed, preview vs published); add a
   Playwright-tier PW-2 journey (shell login → create composition → save → preview → publish) with documented
   runnable evidence. All prior suites green.
7. Append result to the contract.

## Verification (do all)
- npm ci/install + type-check + build succeed under node 18.
- New tests green; builder 34+6; prior suites at published counts; authority 18/18; capability audit zero;
  module:certify --all (incl builder + profile-visual 13/13); composer full; phpstan + cs-fixer clean on tracked
  additions; logs clean; Playwright journey evidence.
- grep: no legacy cmsRender/cmsAdminContext/modules/cms in new path; no unsafe-eval requirement in bundle config;
  profile-visual tracked with no entry/auth residue; git ls-files proof.
- CI 6/6: branch feat/independent-cms-phase10b-builder-ui, commit, push, gh pr create, gh pr checks --watch (6/6),
  gh pr merge --merge --delete-branch, checkout main + pull. Report PR #. Then docs-result PR merged.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (ALL member gates complete → final full verification + completion record)
