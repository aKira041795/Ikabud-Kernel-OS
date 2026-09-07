# Kernel Gate: CapabilityAuthorityAuditor scanFiles() must skip disabled module dirs

task: Make `CapabilityAuthorityAuditor` honor its OWN documented contract consistently. `discoverModules()`
(kernel/Workbench/Audit/CapabilityAuthorityAuditor.php:151-152) treats a module manifest with `enabled:false` or
`_enabled:false` as NOT enabled and excludes it. But `scanFiles()` (L752-768) + `phpAndManifestFiles()` (L771-791)
walk the ENTIRE modules root and collect every `.php`, regardless of module enabled state — so source files beneath
explicitly disabled modules are misclassified as active kernel consumers and produce false-positive critical
findings (e.g. a dormant cms-akira submodule's source legitimately calling cms.* capabilities that are absent
because the module is disabled). Fix scanFiles() to skip PHP files under disabled module directories.

objective: The closed-world authority audit returns ZERO findings on the real repo even when disabled modules
(present but `_enabled:false`) contain calls to capabilities their own dormant providers would expose. This unblocks
CMS Akira fork P1's mandatory 18/18 authority baseline (dormant-marked submodules must not generate findings).

scope:
  allowed:
    - kernel/Workbench/Audit/CapabilityAuthorityAuditor.php — scanFiles()/phpAndManifestFiles() to exclude files
      beneath modules whose module.json sets `enabled === false` or `_enabled === false`.
    - tests/ — extend capability_authority_audit_test.php (or a focused new test) to prove: (a) a DISABLED fixture
      module (`enabled:false`) whose PHP calls an unknown/absent capability produces NO finding; (b) an ENABLED
      module doing the same still produces the finding (behavior preserved); (c) real-repo audit is `[]` when the
      13 dormant cms-akira members are `_enabled:false` locally (note: they are gitignored → in CI the real-repo
      assertion already passes; the local run must too).
    - docs — one-line note in the auditor class doc or cli-tools-reference if applicable.
  prohibited:
    - NO change to discoverModules() enabled semantics, providerInventory, auditCall findings codes, or the
      closed-world KERNEL_CAPABILITIES inventory.
    - NO change to how ENABLED modules are audited (must stay byte-equivalent for enabled modules).
    - NO fork/module edits, NO-BROADEN.

constraints:
  - Disabled-set determination must mirror discoverModules() exactly: a module dir (containing module.json) is
    disabled iff its manifest has `enabled === false` or `_enabled === false`. Reuse the same predicate.
  - The exclusion applies ONLY to files under the modules root (the `src/` + `kernel/` scans are unaffected).
  - Must handle nested module dirs under modules/ (e.g. modules/cms-akira/<member>/module.json) — disable is
    per-member dir.
  - Efficient: compute the disabled dir set once, exclude during the recursive walk (no full-tree scan then filter
    unless simpler — implementer's choice, but keep it O(modules)).
  - The auditor is a no-DB static scanner; keep it pure.

acceptance:
  - Local real-repo run `php tests/capability_authority_audit_test.php` → 18/18 (real-repo assertion `$real === []`)
    WITH the 13 cms-akira members present as `_enabled:false` and their source still on disk.
  - Disabled-module fixture test: source under a disabled module producing zero findings; the SAME source under an
    enabled module producing the finding.
  - Full `composer test` green (CI 6/6 — CI lacks the gitignored dormant fork so the real-repo assertion is
    trivially satisfied there; the new disabled-module fixture test is what guards this fix in CI).
  - capability:audit + Workbench baselines ZERO; php -l / phpstan (no baseline additions) / cs-fixer clean; both
    logs clean.

verification:
  - New fixture tests (disabled module skipped, enabled module still audited).
  - `php tests/capability_authority_audit_test.php` (18/18 locally with dormant fork present).
  - Full `composer test`; CI 6/6.

risk:
  - LOW. Restores the auditor to its documented contract; enabled-module auditing unchanged (guarded by the
    enabled-fixture test). Only behavior change is excluding disabled modules' source from consumer scanning — the
    intended fix.

status: READY_FOR_IMPLEMENTATION (chair-approved kernel micro-gate 3; unambiguous audit-correctness fix surfaced by
fork P1)

## Implementation result (2026-09-07)

status: PASS

- Added one shared strict disabled-manifest predicate and pruned disabled module directories during only the
  `modules/` consumer-source walk; `src/` and `kernel/` scanning is unchanged.
- Extended the existing enabled unexposed-call fixture with identical source in a nested `enabled:false` module;
  the single expected finding remains attributed to the enabled module.
- Local dormant-fork verification found all 13 `_enabled:false` CMS Akira members and the real-repository audit
  returned zero findings (`18 passed, 0 failed`).
- Verification passed: PHP syntax lint, targeted PHPStan, targeted PHP-CS-Fixer CI rules, full `composer test`
  (`103/103`), `capability:audit`, `workbench:audit`, and clean `app.log`/`error.log`. No baseline was added.
- The six-job GitHub CI workflow was not triggered from this uncommitted worktree; the latest main run is 6/6.
  Repo-wide local PHPStan/CS-Fixer additionally sees gitignored, out-of-scope fork modules absent from CI, so the
  contract-scoped checks were used.
