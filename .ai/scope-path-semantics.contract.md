# CONTRACT — Scope-path semantics: fix the parser at the root, and make the lint's phantom rule sound

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — work in the current tree; do not create or switch branches

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: approved plan step 4 (2026-09-14), plus Chair decision CD-7 in `.ai/chair-decisions.md`
directive: this is the **root fix, not a workaround**. The repository doctrine is to repair the engine
rather than annotate the caller — the same rule applied to DiSyL applies here to the contract parser.

## Objective

`Forbidden changes` is parsed by one rule: "the first whitespace token of a bullet is a path". That rule
silently mis-represents authority in two directions, and the conformance lint that reports on it is unsound
in the same way. Fix the **parser** so every bullet lands in exactly one honest bucket, then make the lint's
phantom detection agree with it instead of inventing findings.

## Verified facts — measured, do not re-derive

1. **A non-path bullet is silently dropped.** `## Forbidden changes` in
   `.ai/ai-autonomy-mechanise-doctrine.contract.md` contained **8** bullets; `plan --json` returned **7**
   `forbidden_scope` entries. The bullet beginning `` `git add` `` vanished entirely — a binding prohibition
   that does not bind, with no error, no warning and no trace.
2. **A glob widens to its parent directory.** The bullet `` `.ai/*.contract.md` `` was parsed as
   `{"path":".ai","kind":"directory"}`. That over-broad entry then intersected the same contract's own
   **allowed** path `.ai/ai-autonomy-harness.contract.md`. Widening a forbidden list is fail-closed safe in
   isolation, but the resulting collision would escalate work the contract explicitly authorises.
3. **The lint's phantom rule produces only false positives.** `isPhantom` is applied at
   `tools/ai-contract-lint.php:229` to the driver's **normalised** `forbidden_scope`: the parser strips the
   trailing slash, so the legitimate prohibition `kernel/` arrives as `kernel`, and a bare-word test (no `/`
   and no `.`) labels it a phantom. Both of this session's contracts are reported `phantoms=1 [kernel]`.
4. **The lint's primary path cannot see the defect it exists for.** A genuine phantom (fact 1) is *dropped*
   by the parser, so it never appears in `forbidden_scope`; only the raw-text fallback at
   `tools/ai-contract-lint.php:125` can observe it. Primary and fallback therefore detect different things.
5. The driver now **warns** about both defects (`D7`/`D8`, committed `ed48fff`). Those warnings are sound and
   must keep working; this slice removes the underlying cause without regressing them.

## Deliverables

### P1 — Every forbidden bullet lands in exactly one bucket

A bullet whose first token is not path-like must be represented as a **rule**, not dropped:
`forbidden_rules` (or the contract's existing equivalent) must carry the bullet text, and `plan --json` must
expose it. The invariant to state in a test: **the number of bullets under `## Forbidden changes` equals the
number of resulting representations (paths + rules).** No silent loss, ever. If a bullet is genuinely
ambiguous, represent it as a rule — never as nothing.

### P2 — A glob is a glob, not its parent directory

`` `.ai/*.contract.md` `` must not become directory `.ai`. Prefer an explicit `kind: glob` (or equivalent)
carrying the pattern, so intersection detection stays precise and a contract can still forbid a file pattern
without forbidding the whole tree. Whatever representation you choose, prove with a test that a glob entry
does **not** intersect an allowed file in the same directory.

### P3 — Make the lint's phantom rule sound

- Apply the phantom test to the **raw bullet text**, not to normalised paths; use the driver's `kind` where
  available. A trailing slash means directory and is never a phantom.
- A phantom must be defined as *a raw bullet that cannot be bound to any parsed entry* — which makes facts 1
  and 3 two symptoms of one rule, and fixes both.
- A single primary path is enough; if the raw-text fallback must stay for unparseable contracts, state in a
  comment which contracts reach it and why the two paths cannot disagree.
- Self-test with fixtures reproducing **both** the `` `git add` `` drop and the legitimate `kernel/`
  directory, asserting the former is reported and the latter is not.

### P4 — Corpus before/after, with every change explained

For all contracts in `.ai/` (`php tools/ai-contract-lint.php` currently sees 62), print the
allowed/forbidden classification **before and after** your change and account for every difference.
**No contract may silently gain authorisation.** A change that widens an allowed scope, removes a forbidden
entry, or flips a contract from `parse=FAIL` to `parse=ok` with a materially different envelope must be
listed and explained individually. This is the risk control for the whole slice: the parser change touches
the authority boundary of every contract in the corpus at once.

### P5 — Do not regress the driver's warnings

`plan --json` must still exit 0 and still emit `warnings`. After P1/P2 a dropped bullet should no longer
occur, so the D8 warning becomes a backstop rather than a routine message — do not delete it, and do not let
it double-report the same bullet once P1 works.

## Architectural constraints

- **Fail closed.** Where a bullet's classification is ambiguous, the safe direction is to treat it as a
  binding rule. Never resolve ambiguity toward less enforcement.
- Backwards compatibility is required for contracts that already parse: their resulting envelope must be
  unchanged except where the old result was demonstrably wrong (facts 1 and 2), and each such exception is
  listed under P4.
- Do not redesign the contract schema. The smallest change that satisfies P1–P3 wins; additive keys are
  acceptable, breaking renames are not.
- No new dependency, no network, no DB, no bootstrap, no `~/.config/harpp` access, no HARPP call.
- PHP 8.2 compatible; PHPStan clean for changed files under `-c phpstan.neon`.
- If a needed path is not in `allowed_scope`, **report it rather than adding it silently**.

## Files likely affected

- `kernel/Workbench/Development/DevelopmentTaskContract.php` — the root cause (P1, P2)
- `tools/ai-contract-lint.php` — the unsound rule (P3)
- `tools/ai-autonomy.php` — only if P5 needs warning de-duplication
- `tests/` — the kernel test covering the contract parser, plus new fixtures if none covers it

## Acceptance criteria

1. A `## Forbidden changes` bullet whose first token is not a path is represented as a **rule** and is
   visible in `plan --json`; the bullets-in == representations-out invariant holds in a test.
2. `` `.ai/*.contract.md` `` no longer yields directory `.ai`, and a test proves it does not intersect an
   allowed file in the same directory.
3. `php tools/ai-contract-lint.php` reports **0** phantoms for a contract whose only forbidden entries are
   legitimate paths including `kernel/` and `tests/`, and still reports a phantom for a dropped
   non-path bullet.
4. P4's before/after table is produced for the whole corpus and every difference is explained. Any
   unexplained difference fails this acceptance.
5. Contracts that already parsed still parse with an equivalent envelope, except the explained exceptions.
6. `php tests/ai_autonomy_test.php` still exits `0` with **at least** its current case count, none relaxed.

## Required tests

- The kernel parser test (existing or new) proving P1 and P2, including one case per fact in the Verified
  facts list.
- `php tools/ai-contract-lint.php` on a fixture contract with legitimate `kernel/` and `tests/` prohibitions
  → `phantoms=0`, and on a fixture with a dropped bullet → phantom reported.
- The P4 corpus before/after table, pasted in full.
- `php tests/ai_autonomy_test.php` → exit `0`, with the passed/failed/skipped counts.
- `php -l` on every changed PHP file.
- `/usr/bin/php8.3 vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G <changed files>`
  — real output. Judge against the config's own path set, never a widened one.
- Re-run these three probes against a contract that allows the paths, and paste the exit codes:
  `--path=phpstan.neon`, `--path=tests/entity_fallback_test.php`, `--path=.github/workflows/ci.yml`
  (all must remain `ESCALATE`/`3`).

## Risks

- Changing the parser changes the authority boundary of all 62 contracts at once — P4 is the control, and an
  unexplained difference is the failure mode to look for, not a formatting detail.
- Fixing fact 2 could narrow a forbidden entry that something currently relies on; P4 must show which.
- A `forbidden_rules` channel that is populated but never enforced would repeat the original defect in a new
  shape. Whatever you add must be enforced by `check`, or the warning must say plainly that it is advisory.
- Do not "fix" the lint by simply dropping its phantom feature; the true-positive case (fact 1) must still be
  detectable.

## Forbidden changes

- `phpstan-baseline.neon`
- `phpstan.neon`
- `composer.json`
- `package.json`
- `.github/workflows/`
- `.ai/decisions/`
- `src/`
- `modules/`
- `public/`
