You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 1 gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main bd6274e — Phase 0 merged). Execute Phase 1 per the authoritative approved
contract:

    .ai/contract-independent-cms-phase1-2026-09-07.md   (READ FIRST)
    .ai/current-task.md   (parent contract: Phase 1 + capability-namespace + content-lifecycle sections)

Phase 1 = the content-authority core: ATOMIC rename to canonical `akira.post.*@1` + governed
publish/unpublish/delete lifecycle. Kernel READ-ONLY (Phase 0 prereqs merged). This edits the TRACKED
modules/cms-akira/cms-akira-core — CI scans it, so phpstan + cs-fixer must stay clean.

## Deliverables (contract scope — honor exactly)
1. modules/cms-akira/cms-akira-core: module.json exposes → `akira.post.get/list/create/update/publish/unpublish/
   delete@1` (provider cms-akira-core; mutations requires_protocol v2 + effects.invalidates ["entity.list.post"];
   depends kernel.idempotency.hash/claim/commit/release@1 + kernel.audit.record@1 unchanged). Capability handler map
   cac_cap_cms_post_* → cac_cap_akira_post_* + the 3 lifecycle handlers (publish/unpublish/delete). Entity View
   bridges entity.list.post@1 / entity.get.post@1 call akira.post.list@1/get@1. Routes/handlers call akira.post.*.
2. Lifecycle caps governed v2: status transition + published_at; delete = soft (`deleted_at` via NEW migration 003,
   MySQL 5.7 idempotent) OR hard (choose + document); optimistic concurrency guard; durable idempotency via
   kernel.idempotency.* on the caller PDO/txn; durable audit (kernel.audit.record@1, correlation_id, same boundary —
   mutation fails if audit can't commit); effects.invalidates single tag.
3. Re-baseline fork tests → akira.post.* (P1 read/render + P2 mutation stay green) + lifecycle coverage
   (publish/unpublish/delete allow/deny, idempotency, invalidation freshness, audit).
4. Co-activation ADR note (short, appended to the phase contract): akira.post.* canonical; cms.post.* NOT
   re-introduced; legacy cms never a dependency.
5. Append result to the contract.

## Verification (do all)
- Fork tests green (re-baselined + lifecycle); authority 18/18; module:certify cms-akira-core; theme validate;
  full composer test.
- grep: no `cms.post.` remains in tracked cms-akira-core (only akira.post.); no cmsActiveTheme.
- php -l; phpstan on tracked cms-akira-core; cs-fixer CI style; both logs clean. CI 6/6 expected.
- Kernel READ-ONLY. Pristine fork diff baseline = /var/www/html/applicationostest/modules/cms-akira (modules are
  gitignored except tracked cms-akira-core — use git diff for the tracked core + note dormant members are untouched).

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 2 ready?)
