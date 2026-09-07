You are the /implement + /review agent for Ikabud Kernel OS (repo root /var/www/html/ikabudsix, main 948732a).
Execute the **CapabilityAuthorityAuditor disabled-module scan** kernel micro-gate per the authoritative contract:

    .ai/contract-auditor-disabled-scan-gate-2026-09-07.md

READ THE CONTRACT FIRST. Small, additive, kernel-only. Do not broaden.

## Exact bug (verified)
kernel/Workbench/Audit/CapabilityAuthorityAuditor.php:
- discoverModules() (L116-152) SKIPS manifests with `enabled === false` or `_enabled === false` (L151-152).
- scanFiles() (L752-768) + phpAndManifestFiles() (L771-791) walk the whole modules root and collect EVERY .php
  regardless of module enabled state → disabled modules' source is misclassified as active kernel consumers →
  false-positive critical findings (e.g. dormant modules/cms-akira/<member> source calling absent cms.* caps).
- The class doc (L15-16) says installed manifests are enabled "unless ... enabled or _enabled to false" — scanFiles
  violates that contract.

## Deliverables (contract scope ONLY)
1. Fix scanFiles()/phpAndManifestFiles() so PHP files under a module directory whose module.json declares
   `enabled === false` OR `_enabled === false` are EXCLUDED from the scan. Disabled-set predicate mirrors
   discoverModules() exactly (per-member dir under modules/, incl. nested like modules/cms-akira/<member>).
   src/ + kernel/ scans unaffected. Keep it pure + efficient (compute disabled dir set once).
2. Extend tests/capability_authority_audit_test.php (or a focused new test in repo style):
   - a DISABLED fixture module (module.json `enabled:false`) whose PHP calls an unknown/absent capability → NO
     finding;
   - the SAME source under an ENABLED fixture module → the finding IS produced (enabled behavior preserved);
   - confirm the local real-repo assertion `$real === []` passes while the 13 dormant modules/cms-akira members are
     present with `_enabled:false` (that is the fork P1 state; run it).
3. One-line doc note if the auditor's doc/cli reference mentions scan coverage.
4. Append result to the contract file.

## Verification
- php -l; targeted phpstan (no baseline additions); cs-fixer CI style.
- `php tests/capability_authority_audit_test.php` → 18/18 locally (dormant fork present).
- New fixture tests pass; full `composer test`; CI 6/6.
- Both logs clean.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
