# CMS Akira Independent-CMS — Phase 1 gate: core → canonical `akira.post.*@1` + lifecycle

task: Execute Phase 1 of the APPROVED independent-CMS contract (`.ai/current-task.md` Phases 1 + capability
namespace + content lifecycle sections; `.ai/chair-adjudication-independent-cms-2026-09-07.md`). Phase 1 = the
content-authority core: ATOMIC rename `cms.post.*@1` → canonical `akira.post.*@1` + add governed
publish/unpublish/delete lifecycle capabilities. P1/P2 re-baselined to the new names in the SAME gate (no interval
exposes duplicate or missing authority).

## Contract specifics (honor exactly)
- Canonical capability family (provider = cms-akira-core, the sole content authority):
  `akira.post.get@1`, `akira.post.list@1`, `akira.post.create@1`, `akira.post.update@1` (rename of the current
  cms.post.* P1/P2 set) PLUS `akira.post.publish@1`, `akira.post.unpublish@1`, `akira.post.delete@1` (new lifecycle).
- Atomic migration: module.json exposes, handlers, capability handler map, routes (render `/posts`/`/posts/{slug}` +
  mutation POST/PUT `/api/v1/cms-akira/posts[/{slug}]`), Entity View bridges (entity.list.post@1 / entity.get.post@1
  now project via akira.post.list/get@1), tests, and docs all move to `akira.post.*` in ONE gate. `cms.post.*` names
  are GONE from the fork (they were the fork's own P1/P2 naming, not a legacy dependency). Record the
  alias-or-prohibit co-activation ADR note: `cms.post.*` is NOT re-introduced; the legacy `cms` is never a
  dependency (no alias needed in this repo — record why).
- Lifecycle caps: `akira.post.publish@1` / `unpublish@1` (transition status draft↔published, set published_at) /
  `delete@1` (soft or hard delete — choose + document; hard delete must cascade projections/invalidate). Each is a
  governed v2 mutation per the security contract: session path (Kernel auth + CSRF + role admin) or JWT/API path
  (Kernel JWT + tenant binding); no tenant/role from payload; optimistic concurrency (updated_at/version guard);
  durable idempotency via kernel.idempotency.{hash,claim,commit,release}@1 on the caller PDO/transaction; durable
  audit via kernel.audit.record@1 with correlation_id on the SAME boundary (mutation fails if audit cannot be
  committed); `effects.invalidates: ["entity.list.post"]` (single tag) → invalidateEntityCache('post', tenant).
- Fail-closed allowlisted projections stay (P1 already enforces explicit-field DTOs; keep).
- `cms_akira_posts` migration: unchanged table (no schema change unless delete needs a soft-delete column — if soft
  delete chosen, add `deleted_at DATETIME NULL` + index via a NEW migration 003, MySQL 5.7, idempotent). Per-tenant
  rerun stays converged.

## Deliverables
1. modules/cms-akira/cms-akira-core: module.json (akira.post.* exposes incl. publish/unpublish/delete with
   requires_protocol v2 + effects.invalidates; depends kernel.idempotency.*/audit.record unchanged), capability
   handlers (helpers/capabilities.php: cac_cap_cms_post_* → cac_cap_akira_post_* + the 3 lifecycle handlers),
   entity-view bridges (call akira.post.get/list@1), routes/handlers (call the akira.post.* caps), tests.
2. Re-baseline the fork tests: post_read_render_test + post_mutation_test → akira.post.* (must stay green; add
   lifecycle coverage: publish/unpublish/delete allow/deny + idempotency + invalidation freshness + audit).
3. Short co-activation ADR note (in the phase contract or .ai) recording: akira.post.* is canonical; cms.post.* not
   re-introduced; legacy cms never a dependency.
4. Append result to the contract file.

## Verification (do all)
- New/updated fork tests green (P1 + P2 re-baselined + lifecycle); `php tests/capability_authority_audit_test.php`
  18/18; `module:certify cms-akira-core`; theme validate clean; full composer test green.
- grep: NO `cms.post.` remains in the tracked fork (only `akira.post.`); no `cmsActiveTheme`.
- php -l, phpstan (tracked cms-akira-core files), cs-fixer CI style clean; both logs clean.
- CI 6/6 (tracked core edits are scanned by CI phpstan/cs-fixer — keep them clean).
- Kernel READ-ONLY (Phase 0 kernel prereqs already merged).

## Co-activation ADR — 2026-09-07

`akira.post.*@1` is the sole canonical Post authority. The fork does not re-introduce
`cms.post.*`; no compatibility alias is needed because legacy `cms` is prohibited
from the Akira dependency graph. Co-activation must fail closed on ambiguous Post
entity authority rather than creating an alias or selecting a legacy provider.

## Phase 1 implementation result — 2026-09-07

Implemented the atomic namespace migration and governed draft/publish/unpublish/
soft-delete lifecycle in tracked `cms-akira-core`. Migration 003 adds indexed
`deleted_at` using MySQL-5.7-compatible idempotent SQL. Public reads exclude soft-
deleted rows; update/lifecycle operations require `expected_updated_at`; all five
mutations use Kernel protocol-v2 policy, durable idempotency and same-PDO audit,
then emit the single `entity.list.post` invalidation effect. P1/P2 fork tests pass
38/38 each, authority audit passes 18/18, PHPStan and CS Fixer are clean, themes
validate, and the full Composer suite passes 105/105. Kernel remained read-only.
The plain single-module certify CLI does not attach the discovered nested module
`_path`, so it reports unloaded handlers; certification passes 13/13 when the same
module helpers are preloaded. This pre-existing Kernel CLI defect is not changed
inside this gate.

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
