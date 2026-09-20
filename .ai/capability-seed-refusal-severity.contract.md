# Contract — Capability seed refusal severity

task:
  id: task-seed-refusal-severity
  objective: Distinguish a DANGEROUS capability-widening refusal from BOOKKEEPING, so the permanent
    warning stops without weakening the guard.

## Objective

`CapabilityAuthorizationRegistry::seedPolicy()` reconciles declared capability policy against a stored
row **at the same `policy_version`**. When that row already exists, is `grant_state = granted`, and the
declaration would widen it, the seed refuses and logs
`capability.policy.seed.widening_refused` at **warning** with `reason: operator_regrant_required`.

Measured 2026-09-20 on tenant 54, `akira.post.publish@1`: the store holds 30 policy versions; v1–v21
carry `allowed_roles = admin`, v22–v30 carry `admin,administrator,superadmin`, and **v30 is active**. The
code declares its seed against **v1**, so on **every bootstrap** it compares a wide declaration against
v1's narrow row, correctly refuses, and warns. The live authority is already exactly what the code wants.

Consequences, all observed:

- `storage/logs/app.log` is ~2212 bytes of permanent warning noise after any bootstrap on this tenant.
- Every "logs are clean" acceptance criterion in this repository must carve out an exception — including
  a chair-authored criterion earlier the same day.
- The real damage: readers learn to ignore warnings, so the refusal that *does* matter is invisible.

**The distinction to encode.** Refusing to widen the row at the store's **active** version means the code
is asking for authority the system does not grant: a human must look — keep it a **warning**. Refusing to
widen a **superseded** version means the store has already moved past it and the seed is inert
bookkeeping; it must not rewrite history — log **info**.

## Files likely affected

- `kernel/Capabilities/CapabilityAuthorizationRegistry.php`

## Architectural constraints

- The refusal **must still refuse**: on `$widenedFields !== []` the loop continues and writes nothing.
  Only the log *severity* changes. No row, no grant, no `is_active`, no role set may differ as a result
  of this change — the effective authority is byte-identical before and after.
- `seedRefusalSeverity(int $storedPolicyVersion, int $activePolicyVersion): string` must be a **pure
  static**, returning exactly `'warning'` or `'info'`. Rule: equal to the active version → `'warning'`;
  anything else (superseded, or a store ahead of us) → `'info'`.
- Resolve the active policy version **once per `seedPolicy()` call**, never once per row. It is
  `MAX(policy_version) WHERE is_active = 1` for the affected store; re-read or cache it as the existing
  code style dictates. Do not add a query inside the `foreach`.
- `logSeedDecision()` keeps its signature and its single `write_log()` call site; the severity is
  computed by the caller, or passed in — either way `widening_refused` is the only decision whose
  severity is conditional. `narrowing_applied` stays `info`.
- No new dependency. No schema change. No public API change. PHP 8.3-compatible, no MySQL-8-only SQL.

## Acceptance criteria

1. The chair-authored probe passes, all checks:
   `php tests/capability_seed_refusal_severity_test.php` → exit 0, `0 failed`.
2. The decision is **wired, not merely defined** — a defined-but-unused function still warns forever:
   `grep -c "seedRefusalSeverity" kernel/Capabilities/CapabilityAuthorizationRegistry.php` → ≥ 2.
3. The refusal still refuses — the branch still `continue`s past the update:
   `grep -A6 "widening_refused" kernel/Capabilities/CapabilityAuthorizationRegistry.php | grep -c "continue;"` → ≥ 1.
4. No syntax error: `php -l kernel/Capabilities/CapabilityAuthorizationRegistry.php` → exit 0.
5. No harness regression: `php tools/chair.php --self-test` → exit 0, `0 failed`.
6. Static analysis clean on the touched file, **with the config** (a bare path does not apply
   `phpstan.neon` and reports nothing):
   `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel/Capabilities/CapabilityAuthorizationRegistry.php` → exit 0.
7. The active-version case is **not** silenced — the guard keeps its teeth:
   `php tests/capability_seed_refusal_severity_test.php` → exit 0 **including** the group
   `the dangerous case stays loud` (`refusing to widen the ACTIVE version is a WARNING`). The probe is
   chair-owned; a lane that edits it to obtain a pass has tuned the acceptance, not the code.

## Required tests

```
php tests/capability_seed_refusal_severity_test.php
php -l kernel/Capabilities/CapabilityAuthorizationRegistry.php
php tools/chair.php --self-test
vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel/Capabilities/CapabilityAuthorizationRegistry.php
```

## Risks

- **Over-silencing.** A fix that downgrades *every* refusal hides the case that matters. Prohibited; the
  probe asserts the active case stays `'warning'` and a wrong implementation fails it.
- **Fixing the noise instead of the cause.** The seed stays inert against superseded versions — correct,
  since the store has moved on. Re-planting or rewriting superseded rows is explicitly out of scope and
  would be an authority change.
- **Per-row cost.** Resolving the active version inside the loop adds a query per declaration; forbidden.
- A reviewer may reasonably ask whether the seed's declared version should track the active one instead.
  That is an authority/store question, not this defect, and belongs to the director as a separate
  decision — record it, do not implement it here.

## Forbidden changes

- `tests/capability_seed_refusal_severity_test.php` (chair-owned acceptance — editing it is tuning it)
- `tests/`
- `kernel/DiSyL/`
- `modules/`
- `src/`
- `migrations/`
- `storage/`
- `tools/`
- `phpstan-baseline.neon`
- Do not weaken, skip, delete or disable any test or gate to obtain a pass.
- Do not change authorization semantics: no role set, grant state, active flag or policy row may differ.
