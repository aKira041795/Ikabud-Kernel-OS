You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 4 gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main da9dc0f — Phases 0-3 merged). Execute Phase 4 per the authoritative
approved contract:

    .ai/contract-independent-cms-phase4-2026-09-07.md   (READ FIRST)
    .ai/current-task.md   (native re-scope + naming + tenant-separation + MySQL-5.7 + entity-view sections)

Phase 4 = re-scope the dormant `cms-akira-navigation` into a NATIVE Akira navigation module (own
cms_akira_menus/menu_items, akira.navigation.*@1, no cms.menus.*/akira.content.get@1 residue) and TRACK + enable.
Kernel READ-ONLY. Follow the Phase 3 (editor) pattern exactly: re-scope → tests → track → certify.

## Deliverables (contract scope — honor exactly; see contract for full details)
1. modules/cms-akira/cms-akira-navigation: module.json (akira.navigation.menus/tree/resolve@1 + governed admin
   mutation caps; depends cms-akira-core; owns/reads cms_akira_menus + cms_akira_menu_items; remove
   cms.menus.*/akira.content.get@1 residue; _enabled:false), native handlers, migrations, README.
2. Migrations (MySQL 5.7 InnoDB utf8mb4_unicode_ci; composite unique index byte budget; stable Akira FK keys).
3. Track via .gitignore (modules/cms-akira/cms-akira-navigation/**); commit source + tests.
4. Tests: CRUD/tree/resolve; tenant isolation shared+dedicated; mutation idempotency/audit/invalidation; explicit
   projections; authority zero; certify.
5. Append result to the contract.

## Verification (do all)
- Navigation tests green; P1 38 + P2 38 + shell 21 + install 23 + editor 25 green; authority 18/18; capability
  audit zero; module:certify --all (incl navigation); composer full; phpstan + cs-fixer (tracked navigation); logs
  clean; CI 6/6 expected (commit + push for CI).
- grep: no cms.menus.* / akira.content.get@1 / cmsActiveTheme in tracked navigation.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 5A/5B ready?)
