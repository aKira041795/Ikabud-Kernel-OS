# P1 ADDENDUM #2 — versioned bridge ids + dormant-submodule marking (apply with P1 addendum #1)

The kernel micro-gates are MERGED (ARK runtime PR #27 → c014cdf; resolver @1-fallback symmetry PR #28 → 948732a).
Amend the P1 execution accordingly.

## 1. Bridge capability ids are now VERSIONED (supersedes the unversioned-id requirement)
- `EntityViewResolver::resolve()` (list) AND now `resolveAsResult()` + `resolveDetail()` fall back from the
  unversioned `entity.list.{type}` / `entity.get.{type}` to the versioned `entity.list.{type}@1` /
  `entity.get.{type}@1` when only the versioned id is registered.
- THEREFORE cms-akira-core's module.json MUST expose the NORMAL VERSIONED bridge capability ids:
  `entity.list.post@1` and `entity.get.post@1` (plus `cms.post.get@1`, `cms.post.list@1`). This satisfies the kernel
  manifest validator (`manifest.v1.capabilities.expose-id` requires versioned ids) and the resolver reaches them via
  fallback. Do NOT declare unversioned expose ids. The two-layer contract (R2) is unchanged: the bridge handlers
  registered for these ids call `cms.post.list@1` / `cms.post.get@1` through CapabilityBus and project fresh DTOs.
- The ARK renderer keys remain `entity.list.post` / `entity.detail.post` in the theme's renderer-registry.json
  (that mapping is independent of capability versioning) — `app()->arkRenderers()->resolve('entity.list.post', …)`
  etc.

## 2. Mark the 13 dormant Akira submodules `"_enabled": false` (decision 2 — restores the authority baseline)
- In modules/cms-akira/, every member EXCEPT `cms-akira-core` gets `"_enabled": false` added to its module.json
  (top-level). This is the auditor's + module-manager's documented contract
  (CapabilityAuthorityAuditor.php:151-152 honors enabled/_enabled === false; module-manager keys off `_enabled`).
- Members to mark: cms-akira-ai, -builder, -editor, -media, -navigation, -seo, -theme, -workflow, -search-adapter,
  and the 4 profiles (-profile-minimal/standard/visual/headless).
- cms-akira-core stays enabled (it is the P1 host). Verify AFTER marking:
  `php tests/capability_authority_audit_test.php` → **18/18** (was 17/18 solely due to these dormant members).

## 3. Re-run P1 verification fully (addendum #1 items unchanged)
- Theme at storage/cms-themes/cms-akira-posts (validate clean), routes consume arkRenderers()->resolve, fail-closed
  guards, R1 source_schema/field_contracts, R2 two-layer CapabilityBus bridges, R3 no create/update exposure, R5
  tenant provisioning, R6 stored-output security/XSS negatives.
- P1 integration test (post_read_render_test) must now PASS (core activates: versioned bridge exposes are
  validator-clean, resolver reaches them, dormant members excluded).
- `php tests/capability_authority_audit_test.php` → 18/18; full composer test green EXCEPT any remaining
  pre-existing daily-ledger cs-fixer drift (gitignored module, not a gate) — report precisely.
- Both logs clean; kernel untouched.

## Everything else still applies
`.ai/implement-fork-p1.prompt.md` + `.ai/implement-fork-p1.addendum.md` + the two authoritative contracts
(`contract-fork-cms-akira-2026-09-07.md`, `chair-adjudication-fork-cms-akira-2026-09-07.md`).
