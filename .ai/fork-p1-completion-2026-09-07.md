# CMS Akira Fork — P1 Completion Record (2026-09-07)

Milestone: **Presentation Contract Reset — P1 canonical Post READ/RENDER path: COMPLETE.**

## Delivery (working tree; modules/* + storage/cms-themes are runtime units, gitignored in the kernel repo)
- `modules/cms-akira/cms-akira-core`: self-owned Post content authority (`cms_akira_posts` migration 002,
  `cms.post.get@1`/`cms.post.list@1` published-only, tenant from kernel context); versioned Entity View bridges
  `entity.list.post@1`/`entity.get.post@1` (CapabilityBus → fresh allowlisted DTOs, R2); explicit-field view
  contracts `registerView('post','list'/'detail')` (R1 source_schema primitives + field_contracts); fail-closed
  route guards; `/posts` + `/posts/{slug}` routes; obsolete `helpers/providers.php` removed.
- `storage/cms-themes/cms-akira-posts/`: minimal ARK theme (renderer-registry.json mapping
  `entity.list.post`→`article-grid`, `entity.detail.post`→`article-page`; tokens.json; theme.manifest.json;
  article-grid/article-page DiSyL renderers) — validated clean via `php ikabud theme:validate`.
- 13 dormant submodules marked `"_enabled": false` (per-member manifest governance).
- Kernel: untouched. No mutation capabilities (R3). Stored-output security + slug canonicalization + XSS negatives
  (R6). Tenant provisioning + migration-ledger rerun behavior (R5) recorded + tested.

## Kernel gates merged to make this possible (all 6/6 CI, zero-exception baselines)
1. PR #27 `feat/ark`: opt-in ARK renderer-selection runtime (`ArkRendererResolver`, `App::arkRenderers()`) — the
   missing runtime consumer for renderer-registry.json.
2. PR #28 `fix(entity-view)`: EntityViewResolver `@1`-fallback symmetry in resolveAsResult() + resolveDetail() —
   enabled versioned bridge ids (manifest validator requires versioned exposes).
3. PR #31 `fix(audit)`: CapabilityAuthorityAuditor scanFiles() skips disabled module dirs — restored the closed-world
   audit to its documented contract (enabled/_enabled false modules excluded from consumer scanning).

## Evidence (final local state)
- CMS Akira P1 integration: **38/38 passed** (canonical Post path: capability → Entity View → ARK renderer selection
  → DiSyL → HTML; projection boundary; registration guard; tenant isolation; partial-provisioning rerun).
- Capability authority audit: **18/18** with the 13 dormant members present; real-repo audit zero findings.
- `composer test` **103/103**; capability:audit + workbench:audit zero; theme validation clean; both logs 0 bytes.
- Fork delta vs pristine applicationostest copy: 22 intended files (core P1 deltas + dormant marking + theme).

## The canonical Post path is now REAL (the fork mandate)
```
DB (cms_akira_posts, tenant-scoped)
   → cms.post.list@1 / cms.post.get@1   (content authority = cms-akira-core)
   → entity.list.post@1 / entity.get.post@1  (Entity View bridges → fresh DTO)
   → ARK renderer selection  (renderer-registry.json via ArkRendererResolver)
   → article-grid.disyl / article-page.disyl  (DiSyL)
   → HTML  (explicit fields only; no internal/tenant leakage)
```
Domain owns truth · Entity View = semantic firewall · ARK = visual authority · DiSyL = runtime · Kernel =
governance. No duplicate registries, no '*' fallback, no cms-legacy dependency.

## Next (per fork contract)
- **P2 gate** (own contract): `cms.post.create@1`/`cms.post.update@1` (admin-only) + `effects.invalidates:
  ["entity.list.post"]` → `invalidateEntityCache('post', tenant)` freshness proof + durable idempotency + audit
  topology preflight (R4: name the audit destination; single-connection or documented cross-DB recovery).
- **P3**: ARK authority ADR ratify/freeze (MAY/MAY-NOT model already recorded in the fork contract).
- **P4** (deferred marker): builder-as-composition-editor reconnect; re-enable dormant members individually.
