# Contract — Extension point consumption census

task:
  id: task-extension-census
  objective: A read-only census that reports which declared extension points have no consumer, and fails
    on contributions to points no manifest declares.

## Objective

`modules/cms-akira/cms-akira-core/module.json` declares **five** extension points:

```
cms.sidebar · cms.settings.sections · cms.content.processors · cms.editor.tools · cms.dashboard.widgets
```

Measured on this tree, 2026-09-20: `grep '"contributes"'` across every module manifest returns
**nothing**. Not three of five unconsumed — all five, with no consumer anywhere.

An extension point with no consumer is speculative contract surface. It is published as an interface a
third party could build against, and nothing exercises it: no evidence it works, no test that would
notice it breaking, and no way to distinguish "supported extension point" from "a name in a manifest".
That is the same defect as a declaration that does not control what it appears to control.

**This slice adds the ability to see the surface. It does not remove anything** — deleting declared
contract surface changes what a third party may build against, so that stays with the director.

## Files likely affected

- `kernel/Workbench/Governance/ExtensionPointCensus.php` (new)
- `ikabud` (add the reading command)

## Architectural constraints

- **Read-only.** No write, no schema, no manifest edit, no extension point removed or added.
- `ExtensionPointCensus::report(array $declaredPoints, array $contributions): array` is **pure**: no I/O,
  inputs unchanged, deterministic ordering. Return shape exactly as the probe fixes it.
- **Two invariants, deliberately different:**
  - an **orphan** contribution (naming a point no manifest declares) is a defect in code and is counted
    as `orphan`;
  - an **unconsumed** declared point is **reported**, never fatal. A census that fails on it would be
    permanently red, and a guard that is always red is a guard nobody reads.
- An orphan must **not** count as consumption: it consumes nothing, because the point it names does not
  exist.
- `counts` keys in this order: `declared, consumed, unconsumed, orphan`; `declared = consumed +
  unconsumed`.
- `unconsumed` is ordered deterministically (by point name) so two runs are comparable.
- The command exits 0 when it reports — a report is not a failure. It prints the counts and the
  unconsumed inventory, and it names how many manifests it read, so a silent empty census is visible as
  a reading of zero files rather than as a clean surface.
- PHP 8.3. No new dependency. No public API or capability contract change.

## Acceptance criteria

1. The chair-authored probe passes in full:
   `php tests/extension_point_census_test.php` → exit 0, `0 failed`.
2. The census is **wired, not merely defined**:
   `grep -c "ExtensionPointCensus" ikabud` → ≥ 2.
3. The command runs and reports: `php ikabud extension:census` → exit 0.
4. **It reads the real manifests** — a census that finds nothing is a silent failure. The five declared
   points must appear in its output:
   `php ikabud extension:census | grep -cE "cms\.(sidebar|settings|content|editor|dashboard)"` → ≥ 5.
5. No syntax error: `php -l kernel/Workbench/Governance/ExtensionPointCensus.php` → exit 0.
6. No harness regression: `php tools/chair.php --self-test` → exit 0, `0 failed`.
7. Static analysis clean on the new file, **with the config**:
   `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel/Workbench/Governance/ExtensionPointCensus.php` → exit 0.

## Required tests

```
php tests/extension_point_census_test.php
php -l kernel/Workbench/Governance/ExtensionPointCensus.php
php tools/chair.php --self-test
php ikabud extension:census
vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel/Workbench/Governance/ExtensionPointCensus.php
```

## Risks

- **Finding nothing and looking clean.** If the command reads no manifests it reports an empty surface,
  which is indistinguishable from a healthy one. The command must say how many manifests it read.
- **Failing on unconsumed points.** That makes it permanently red. Reported, not failed.
- **Counting an orphan as a consumer.** It consumes nothing; merging the two hides a broken
  contribution behind a healthy count.
- Removing the five points, or adding consumers for them, is **out of scope** and belongs to the
  director: it changes published contract surface either way.
- `composition.detail` is a DIFFERENT thing — a theme view contract pinned in two drift tests
  (`tests/akira_theme_view_contract_drift_test.php`, `modules/cms-akira/cms-akira-theme/tests/theme_view_contract_drift_test.php`).
  It is not one of these five points; do not fold it in.

## Forbidden changes

- `tests/extension_point_census_test.php` (chair-owned acceptance)
- `tests/`
- `modules/`
- `kernel/Capabilities/`
- `kernel/Services/`
- `src/`
- `migrations/`
- `storage/`
- `tools/`
- `phpstan-baseline.neon`
- Do not weaken, skip, delete or disable any test or gate to obtain a pass.
- Do not remove, rename or add any extension point or contribution.
