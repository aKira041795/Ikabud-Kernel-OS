# Close the static-analysis errors in the retrieval index

## Objective

`vendor/bin/phpstan analyse -c phpstan.neon` reports **18 errors** across
`kernel/Workbench/Retrieval/RetrievalIndex.php` and `kernel/Workbench/Retrieval/run.php`. Nothing in
these two files is baselined, so these are errors the repository's static-analysis gate will fail on.
They were introduced while building the retrieval index today and have been carried since.

Measured now:

```
vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G \
    kernel/Workbench/Retrieval/RetrievalIndex.php kernel/Workbench/Retrieval/run.php
  [ERROR] Found 18 errors
```

Fix them by correcting the code. Report the real before/after count.

## Architectural constraints

- **Fix the code. Do NOT add these to `phpstan-baseline.neon`.** Editing a quality-gate baseline is
  forbidden at repository level, no exceptions, and it is explicitly in the Forbidden-changes list
  below. Suppressing an error is not fixing it.
- Do not silence errors with `@phpstan-ignore`, bare `// @phpstan-ignore-next-line`, or by widening a
  type to `mixed` to make an error disappear. If a `@phpstan-ignore` is genuinely the only honest
  option for one specific error, say so in your report with the reason and leave the rest fixed.
- The errors are a mixture and are expected to include: array-offset reads on shapes that already
  guarantee the key (which `??` is redundant against), `array_values()` on something already a list,
  a property written and never read, untyped `array` parameters needing
  `@param array<string, mixed>`, and at least one duplicated array key. Handle each on its merits —
  the correct fix for a redundant `??` is usually to drop it, not to add a cast.
- Several errors are in docblocks rather than logic. A precise `@param`/`@return` shape is a real fix
  here, and the file already documents its return shapes this way; match the existing style.
- Behaviour must not change. This is a typing and correctness pass, not a rewrite.

## Files likely affected

- `kernel/Workbench/Retrieval/RetrievalIndex.php`
- `kernel/Workbench/Retrieval/run.php`

## Acceptance criteria

- Static analysis is clean on the two files: `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel/Workbench/Retrieval/RetrievalIndex.php kernel/Workbench/Retrieval/run.php`
- The retrieval self-test is still green: `php kernel/Workbench/Retrieval/run.php --self-test`
- The module test is still green: `php tests/retrieval_index_test.php`
- No entry is added to `phpstan-baseline.neon`.

## Required tests

- `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel/Workbench/Retrieval/RetrievalIndex.php kernel/Workbench/Retrieval/run.php`

## Risks

- **Baselining instead of fixing.** The quickest way to make the command exit 0, and forbidden.
- **Silencing with ignores.** An `@phpstan-ignore` turns a red gate green while leaving the defect.
- **Changing behaviour while satisfying the analyser.** Widening a type or reordering an array to
  please a docblock can quietly alter what the index returns. The two test commands above are the
  guard against that, and they must still pass.
- **Fixing the reported errors and introducing new ones.** The count must reach 0, not move.

## Forbidden changes

- `phpstan-baseline.neon`
- `phpstan.neon`
- `tests/`
- `tools/`
- `public/`
- Do not weaken, delete or loosen any self-test check; the retrieval self-test currently passes 64.
- Do not change retrieval behaviour: which documents are returned, their ranking, or the retired-material
  exclusion.
