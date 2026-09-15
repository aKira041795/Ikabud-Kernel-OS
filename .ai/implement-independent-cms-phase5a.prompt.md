You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 5A gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main 003809a — Phases 0-4 merged). Execute Phase 5A per the authoritative
approved contract:

    .ai/contract-independent-cms-phase5a-2026-09-07.md   (READ FIRST — full specifics)
    .ai/current-task.md   (native re-scope + naming + tenant-separation + MySQL-5.7 sections)

Phase 5A = re-scope the dormant `cms-akira-media` into a NATIVE Akira media module (own cms_akira_media, native
akira.media.*@1 library/get/resolve/upload/update/delete, MIME/size/path/authz abuse safety, tenant storage
isolation, no cms.media.*/akira.content.get@1 residue) and TRACK + enable. Kernel READ-ONLY. Follow the Phase 4
(navigation) member EXACTLY as the structural pattern — read modules/cms-akira/cms-akira-navigation/ (module.json
shape, capability declaration + v2-mutation style, migrations, tests, gitignore tracking) and mirror it for media.

## Key contract points (see contract file for the full authoritative list)
1. module.json: kind extension / extends cms-akira-core / depends cms-akira-core only; owns+reads cms_akira_media;
   exposes akira.media.library@1 / get@1 / resolve@1 (projections, fail closed) + governed v2 mutation caps
   upload/update/delete@1 (idempotency + durable audit + effects.invalidates single tag entity.list.media);
   drop the legacy nav entry to /admin/cms-akira-media; retain _enabled:false.
2. cms_akira_media migration: tenant_id + stable ASCII media key unique per tenant, filename/mime_type/size_bytes,
   server-derived storage location, alt/dims, MySQL 5.7 InnoDB utf8mb4_unicode_ci, byte-budget composite unique.
3. Security abuse tests (MIME whitelist, size cap, path-traversal defense, tenant authz isolation, upload
   idempotency/audit). Local-filesystem storage under module tenant storage dir with documented cleanup policy; no
   object-storage SDK this gate.
4. Track via .gitignore (modules/cms-akira/cms-akira-media/**); commit source + tests.
5. Append result to the contract.

## Verification (do all)
- Media tests green + dedicated-tenant 6/6; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35
  green; authority 18/18; capability audit zero; module:certify --all (incl media); composer full; phpstan +
  cs-fixer clean on tracked media; logs clean.
- grep over tracked media (incl staged ignored): no cms.media.* / akira.content.get@1 / cmsActiveTheme / legacy
  cmsRender / legacy cms tables; record the command proving ignored source was included (git ls-files).
- CI 6/6 expected: create branch feat/independent-cms-phase5a-media, commit, push, gh pr create, gh pr checks
  --watch (6/6), gh pr merge --merge --delete-branch, checkout main + pull. Report the PR number.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 5B SEO ready?)
