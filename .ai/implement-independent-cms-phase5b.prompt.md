You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 5B gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main bcd3085 — Phases 0-5A merged). Execute Phase 5B per the authoritative
approved contract:

    .ai/contract-independent-cms-phase5b-2026-09-07.md   (READ FIRST — full specifics)
    .ai/current-task.md   (native re-scope + naming + tenant-separation + MySQL-5.7 sections)

Phase 5B = re-scope the dormant `cms-akira-seo` into a NATIVE Akira SEO module (own cms_akira_seo_metadata keyed
tenant+entity type/key with evidenced composite unique index, native akira.seo.get@1 / meta.build@1 / upsert@1 /
delete@1, canonical/robots/OG escaping policy, stale-reference fail-closed, no cms.seo.*/akira.content.get@1
residue) and TRACK + enable. Kernel READ-ONLY. Follow the Phase 4 (navigation) and Phase 5A (media) members
EXACTLY as the structural pattern — read modules/cms-akira/cms-akira-navigation/ and
modules/cms-akira/cms-akira-media/ (module.json shape, capability declaration + v2-mutation style, migrations,
tests, gitignore tracking) and mirror for SEO.

## Key contract points (see contract file for the full authoritative list)
1. module.json: kind extension / extends cms-akira-core / depends cms-akira-core only; owns+reads
   cms_akira_seo_metadata; exposes akira.seo.get@1 + meta.build@1 (projections, fail closed) + governed v2 mutation
   caps upsert@1/delete@1 (idempotency + durable audit + effects.invalidates single tag entity.list.seo-metadata);
   drop residue (cms.seo.resolve@1, akira.content.get@1); retain _enabled:false.
2. cms_akira_seo_metadata migration: tenant_id + entity_type + entity_key with real composite UNIQUE index
   (tenant_id, entity_type, entity_key) evidenced; title/meta_description/canonical_url/robots/og_* columns;
   MySQL 5.7 InnoDB utf8mb4_unicode_ci byte budget.
3. Escaping + stale behavior: canonical/robots/OG output escaped (no attribute/HTML injection — test hostile
   input); get/meta.build on missing record fail closed deterministically; unique duplicate handled.
4. Track via .gitignore (modules/cms-akira/cms-akira-seo/**); commit source + tests.
5. Append result to the contract.

## Verification (do all)
- SEO tests green + dedicated-tenant 6/6; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 +
  media 37 green; authority 18/18; capability audit zero; module:certify --all (incl seo); composer full; phpstan
  + cs-fixer clean on tracked seo; logs clean.
- grep over tracked seo (incl staged ignored): no cms.seo.* / akira.content.get@1 / cmsActiveTheme / legacy cms
  seo tables; record the command proving ignored source was included (git ls-files).
- CI 6/6 expected: create branch feat/independent-cms-phase5b-seo, commit, push, gh pr create, gh pr checks
  --watch (6/6), gh pr merge --merge --delete-branch, checkout main + pull. Report the PR number. After merge,
  append the result to the contract and merge that docs PR too (as done for 5A).

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (theme re-scope ready?)
