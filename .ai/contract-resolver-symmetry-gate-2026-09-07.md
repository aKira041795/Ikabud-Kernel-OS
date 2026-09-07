# Kernel Gate: EntityViewResolver `@1`-fallback symmetry (resolveAsResult + resolveDetail)

task: Make `EntityViewResolver` internally consistent: `resolve()` (kernel/EntityContext/EntityViewResolver.php
L374-384) already falls back from the unversioned `entity.list.{type}` to the versioned `entity.list.{type}@1`
capability id. `resolveAsResult()` (L505-506) and `resolveDetail()` (L576) do NOT — they call the unversioned id with
no `@1` fallback. Add the same fallback to both so module manifests can declare the normal VERSIONED capability ids
(`entity.list.post@1`, `entity.get.post@1`) and the resolver still finds them. This unblocks CMS Akira fork P1 (the
manifest validator requires versioned exposed capability ids; the fork's Entity View bridges must be versioned).

objective: A ~6-line additive symmetry fix + tests. No semantic change to the unversioned-first resolution order;
when the exact unversioned id IS registered it still wins; `@1` is used only as the existing fallback.

scope:
  allowed:
    - kernel/EntityContext/EntityViewResolver.php — add the identical fallback block to resolveAsResult() and
      resolveDetail() that resolve() already has (try unversioned `entity.list.{type}` / `entity.get.{type}`; if not
      registered and `{id}@1` is registered, append `@1`).
    - tests/ — extend or add a focused test: versioned-only registration of `entity.list.X@1` and `entity.get.X@1`
      resolves through resolveAsResult() and resolveDetail(); unversioned registration still wins when present.
    - docs — one-line note in docs/kernel/entity-view-system.md or the API reference if it documents the fallback.
  prohibited:
    - NO change to resolve() (already correct) or resolveDetail()'s capability-id format (`entity.get.{type}`) beyond
      the fallback.
    - NO weakening the manifest validator (versioned exposes remain REQUIRED).
    - NO change to registerView / view contracts / DefaultEntityRenderer / CapabilityBus.
    - NO fork/module edits; NO-BROADEN.

constraints:
  - Mirror resolve()'s exact fallback expression (L380-384) so all three paths share identical semantics.
  - Keep the unversioned-first order; versioned is purely the fallback.
  - Must not regress: daily-ledger entity views + entity_view_render_cache_test (32) + ark_renderer_runtime_test (17)
    stay green (the fixture theme + existing tests use whatever resolution currently works).

acceptance:
  - A module that exposes ONLY `entity.list.post@1` + `entity.get.post@1` (versioned, manifest-validator-compliant)
    is resolvable via resolveAsResult('entity.list.post') and resolveDetail('entity.get.post', …).
  - resolve() behavior unchanged; unversioned registration still takes precedence everywhere.
  - Full `composer test` green (NOTE: local full-suite authority-audit failure from the dormant unaligned
    modules/cms-akira submodules is a KNOWN pre-existing out-of-scope condition — it is gitignored/absent in CI and
    will be resolved by the fork P1 dormant-marking gate; the resolver gate's CI must still pass 6/6).
  - capability:audit + Workbench baselines ZERO (in CI, where the dormant fork is absent); php -l / phpstan (no
    baseline additions) / cs-fixer clean; both logs clean.

verification:
  - New focused test (versioned-only registration through resolveAsResult + resolveDetail; unversioned precedence).
  - `php tests/entity_view_render_cache_test.php`, `php tests/ark_renderer_runtime_test.php`, full `composer test`.
  - Both storage/logs/app.log + error.log clean.

risk:
  - LOW. Pure additive fallback symmetry; the pattern already exists in resolve(). Only regression risk is a typo'd
    capability id, covered by the focused test + full suite.

status: READY_FOR_IMPLEMENTATION (chair-approved micro-decision 1 of the P1 blocker #2)

result: PARTIAL — Added the mirrored `@1` fallback to `resolveAsResult()` and `resolveDetail()`, a focused 4/4 plain-PHP regression test, and the entity-context documentation note. PHP lint, targeted PHPStan, targeted CS Fixer, entity-view cache (32/32), ARK runtime (17/17), and the focused test pass. Full `composer test` is 99/103 locally: the known dormant `modules/cms-akira` tree causes authority audit 17/18 and also emits invalid-manifest log failures in three API/profile tests; no resolver-gate regression was observed. Full-repository CS Fixer also reports pre-existing daily-ledger style drift; changed PHP files are clean. Logs were cleared after verification and remain clean after the focused test.
