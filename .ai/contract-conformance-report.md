# Contract-corpus conformance report

**Status:** READY_FOR_IMPLEMENTATION · **Date:** 2026-09-14
**Tool:** `php tools/ai-contract-lint.php` (read-only; delegates parsing to `tools/ai-autonomy.php plan --json`)
**Scope:** every `.ai/*.contract.md` present in the working tree. No contract is edited by this report.

This is the artefact a later run uses to retrofit **deliberately** instead of in bulk. A contract is
classified from its declared status only; its intent is never inferred from its filename.

## Summary (measured after the A3 worked retrofit)

| metric | value |
|---|---|
| contracts scanned | 62 |
| parse = ok | 16 |
| parse = FAIL | 46 |
| class = live | 36 |
| class = stale | 1 |
| class = unknown | 25 |
| live contracts that FAIL to parse | 31 |
| contracts with ≥1 phantom | 13 |
| live contracts with ≥1 phantom | 4 |
| contracts with no `status:` line | 17 |

Before the A3 worked retrofit the corpus had **47** parse failures (the verified-facts figure). A3
retrofitted exactly one of them — `p2.1-navigation-surface` — leaving **46**. The classification below
is of the original **47** failures, with the retrofitted example noted as done.

The contract's verified facts cite 60 total contracts / 47 failures. The tree now holds 62: two
contracts were added after those facts were measured (`contract-corpus-conformance` and
`ai-autonomy-mechanise-doctrine`), both of which parse. The failing set is unchanged at 47 pre-retrofit,
so the extra two are parse-clean.

## Full lint table

```
<name>  parse=ok|FAIL  harness_ref=yes|no  phantoms=N  status=<value>|none|PLACEHOLDER  class=live|stale|unknown
```

```
ai-autonomy-harness                                  parse=ok   harness_ref=yes phantoms=0  status=ADOPTED — STANDING REFERENCE (this file is no longer a one-shot slice) class=stale
ai-autonomy-harpp-binding                            parse=FAIL harness_ref=no  phantoms=3  status=READY_FOR_IMPLEMENTATION                 class=live [seven, HARPP_NOTIFY, harpp]
ai-autonomy-mechanise-doctrine                       parse=ok   harness_ref=no  phantoms=1  status=READY_FOR_IMPLEMENTATION                 class=live [kernel]
ai-autonomy-remote-shape-repair                      parse=ok   harness_ref=no  phantoms=1  status=READY_FOR_IMPLEMENTATION                 class=live [this]
akira-cli-capability-scope                           parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
akira-dedicated-test-tenant                          parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
akira-editorial-finish                               parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
akira-entity-view-contracts                          parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION (investigation-gated) class=live
akira-media-surface                                  parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
akira-mutation-cache-invalidation                    parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
akira-theme-scope                                    parse=ok   harness_ref=yes phantoms=2  status=none                                     class=unknown [migrations, modules]
authority-beyond-http                                parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
authority-entrypoints                                parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
authority-route-coverage-akira                       parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
authority-route-coverage-privilege                   parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
authority-scope                                      parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
c5-governance-census                                 parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
c6-route-authority                                   parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
contract-corpus-conformance                          parse=ok   harness_ref=no  phantoms=1  status=READY_FOR_IMPLEMENTATION                 class=live [kernel]
cycle4-provenance                                    parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
cycle4b-fixture-and-verify                           parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
disyl-include-root-cache-key                         parse=FAIL harness_ref=no  phantoms=0  status=QUEUED — dispatch after the read-authority-extension lane releases the working tree class=live
disyl-rawtext-control-tags                           parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
disyl-style-compiled-fix                             parse=FAIL harness_ref=no  phantoms=0  status=PLACEHOLDER                              class=unknown
harness-acceptance-a-simulated-harpp                 parse=ok   harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
media-contribute-authority                           parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
media-delete-request                                 parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
module-test-visibility-repair                        parse=FAIL harness_ref=no  phantoms=0  status=BLOCKING — PR #135 is RED and must not merge until green class=live
module-test-visibility                               parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
p1.3-activation-enforcement                          parse=ok   harness_ref=no  phantoms=1  status=none                                     class=unknown [migrations]
p2-closure-revocation                                parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
p2.1-navigation-surface                              parse=ok   harness_ref=yes phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
p2.2-redirects                                       parse=FAIL harness_ref=no  phantoms=0  status=none                                     class=unknown
p2.2a-post-redirects                                 parse=ok   harness_ref=no  phantoms=0  status=none                                     class=unknown
p2.3-seo-surface                                     parse=FAIL harness_ref=no  phantoms=0  status=none                                     class=unknown
p2.4-search-console                                  parse=ok   harness_ref=no  phantoms=1  status=none                                     class=unknown [migrations]
p3.1-akira-settings                                  parse=ok   harness_ref=no  phantoms=1  status=none                                     class=unknown [migrations]
p3.1b-more-settings                                  parse=ok   harness_ref=no  phantoms=1  status=none                                     class=unknown [migrations]
p3.2-workflow-console                                parse=ok   harness_ref=no  phantoms=1  status=none                                     class=unknown [migrations]
p4.0-theme-customizer                                parse=FAIL harness_ref=no  phantoms=0  status=none                                     class=unknown
p4.2a-module-manager-view                            parse=FAIL harness_ref=no  phantoms=0  status=none                                     class=unknown
p4.2a-r2-module-manager-view                         parse=ok   harness_ref=no  phantoms=1  status=none                                     class=unknown [migrations]
p5.1-authority-surface                               parse=FAIL harness_ref=no  phantoms=0  status=none                                     class=unknown
p5.2-provenance-surface                              parse=FAIL harness_ref=no  phantoms=0  status=none                                     class=unknown
p5.2fix-provenance-capability                        parse=FAIL harness_ref=no  phantoms=0  status=none                                     class=unknown
p6a-backup-export-console                            parse=ok   harness_ref=no  phantoms=1  status=none                                     class=unknown [migrations]
playwright-rate-limit-cap                            parse=ok   harness_ref=yes phantoms=5  status=none                                     class=unknown [kernel, modules, src, config, migrations]
policy-seeding-hygiene                               parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
r5-read-governance                                   parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
read-authority-extension                             parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
read-authority-probe                                 parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
repair-disyl-script-interpolation                    parse=FAIL harness_ref=no  phantoms=0  status=PLACEHOLDER                              class=unknown
t1-theme-lifecycle-gap-closure                       parse=FAIL harness_ref=no  phantoms=0  status=PLACEHOLDER                              class=unknown
t2-theme-block-definitions                           parse=FAIL harness_ref=no  phantoms=0  status=PLACEHOLDER                              class=unknown
t3-extension-registry                                parse=FAIL harness_ref=no  phantoms=0  status=changed: implementation_summary: (registry/shell_wiring/theme_contribution/seo_widget) class=unknown
t4a-builder-canonical-blocks                         parse=FAIL harness_ref=no  phantoms=0  status=PLACEHOLDER                              class=unknown
t4a-verify-repair                                    parse=FAIL harness_ref=no  phantoms=0  status=PLACEHOLDER                              class=unknown
t4b-builder-admin-editor                             parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
t4c-canvas                                           parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live
t4pre-disyl-block-engine                             parse=FAIL harness_ref=no  phantoms=0  status=PLACEHOLDER                              class=unknown
thesis-measurement                                   parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_MEASUREMENT                    class=live
triage-test-gate                                     parse=FAIL harness_ref=no  phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live

SUMMARY total=62 live=36 stale=1 unknown=25 live_parse_failures=31 live_with_phantoms=4 with_phantoms=13 missing_status=17
```

## Classification of the 47 pre-retrofit failures

Counts: **live 31 · stale 0 · unknown 9 · placeholder 7 = 47.** Classification is by declared status
only. `t3-extension-registry` has a malformed `status:` line (`changed: implementation_summary: …`) and
is therefore grouped with the unknown-status set, not guessed.

### (a) live and should be retrofitted — 31

These self-declare `READY_FOR_IMPLEMENTATION` / `QUEUED` / `BLOCKING` / `READY_FOR_MEASUREMENT` and are
the only failures that gate a run:

- `ai-autonomy-harpp-binding`
- `akira-cli-capability-scope`
- `akira-dedicated-test-tenant`
- `akira-editorial-finish`
- `akira-entity-view-contracts` (`READY_FOR_IMPLEMENTATION (investigation-gated)`)
- `akira-media-surface`
- `akira-mutation-cache-invalidation`
- `authority-beyond-http`
- `authority-entrypoints`
- `authority-route-coverage-akira`
- `authority-route-coverage-privilege`
- `authority-scope`
- `c5-governance-census`
- `c6-route-authority`
- `cycle4-provenance`
- `cycle4b-fixture-and-verify`
- `disyl-include-root-cache-key` (`QUEUED — dispatch after …`)
- `disyl-rawtext-control-tags`
- `media-contribute-authority`
- `media-delete-request`
- `module-test-visibility-repair` (`BLOCKING — PR #135 is RED …`)
- `module-test-visibility`
- `p2-closure-revocation`
- `policy-seeding-hygiene`
- `r5-read-governance`
- `read-authority-extension`
- `read-authority-probe`
- `t4b-builder-admin-editor`
- `t4c-canvas`
- `thesis-measurement` (`READY_FOR_MEASUREMENT`)
- `triage-test-gate`

### (b) stale by status — 0

No failing contract carries a stale status. A stale contract failing to parse is explicitly allowed not
to affect the lint exit code, so this set being empty is not itself a problem.

### (c) unknown status, needs owner — 9

No `status:` line, or an unrecognised value. These need the author to declare a lifecycle state before
retrofit; scope cannot be inferred from them.

- `p2.1-navigation-surface` — **retrofitted by A3**; now `parse=ok`, `harness_ref=yes`, `phantoms=0`,
  `status=READY_FOR_IMPLEMENTATION`
- `p2.2-redirects`
- `p2.3-seo-surface`
- `p4.0-theme-customizer`
- `p4.2a-module-manager-view`
- `p5.1-authority-surface`
- `p5.2-provenance-surface`
- `p5.2fix-provenance-capability`
- `t3-extension-registry` (malformed status line)

### (d) placeholder status, needs owner — 7

Still carry the unfilled template alternation `PASS|FAIL|PARTIAL|BLOCKED`; the author must supply the
real state:

- `disyl-style-compiled-fix`
- `repair-disyl-script-interpolation`
- `t1-theme-lifecycle-gap-closure`
- `t2-theme-block-definitions`
- `t4a-builder-canonical-blocks`
- `t4a-verify-repair`
- `t4pre-disyl-block-engine`

## Phantom entries by name

A phantom is a forbidden-scope entry that is a single bare word with no `/` or `.` — the prose-as-path
signature the kernel parser produces when a forbidden bullet is not a path. Entries are listed per
contract (count in parentheses). Contracts not listed have zero phantoms. These are **not** edited here;
fixing them is a later, deliberate retrofit.

- `ai-autonomy-harpp-binding` (3): `seven`, `HARPP_NOTIFY`, `harpp`
- `ai-autonomy-mechanise-doctrine` (1): `kernel`
- `ai-autonomy-remote-shape-repair` (1): `this`
- `akira-theme-scope` (2): `migrations`, `modules`
- `contract-corpus-conformance` (1): `kernel`
- `p1.3-activation-enforcement` (1): `migrations`
- `p2.4-search-console` (1): `migrations`
- `p3.1-akira-settings` (1): `migrations`
- `p3.1b-more-settings` (1): `migrations`
- `p3.2-workflow-console` (1): `migrations`
- `p4.2a-r2-module-manager-view` (1): `migrations`
- `p6a-backup-export-console` (1): `migrations`
- `playwright-rate-limit-cap` (5): `kernel`, `modules`, `src`, `config`, `migrations`

Note the last five for `playwright-rate-limit-cap` and the single `migrations` across the conforming
P-series: these are legitimate **directory** prohibitions whose trailing slash is stripped by
`normalizePath`, so the bare word is produced by the driver, not by prose. They are phantoms under the
contract's literal definition and are reported as such, but they are not prose defects. The kernel
parser itself is out of scope for this slice.

## Method and caveats

- `parse` is the exit code of `php tools/ai-autonomy.php plan --json --contract=<file>`; the parser is
  never re-implemented.
- `phantoms` prefers the driver's `forbidden_scope`; for contracts the driver rejects, the raw
  `## Forbidden changes` section is read only to apply the same bare-word heuristic.
- `status` is the first `^status:` line; `PLACEHOLDER` when it still contains
  `PASS|FAIL|PARTIAL|BLOCKED`; `none` when absent.
- `class` is status-only: `live` for `READY_FOR_IMPLEMENTATION` / `QUEUED` / `BLOCKING` /
  `READY_FOR_MEASUREMENT`, `stale` for `DONE` / `SHIPPED` / `CLOSED` / `COMPLETE` / `SUPERSEDED` /
  `ADOPTED`, `unknown` otherwise.
- Exit code: `3` while any live contract fails to parse or carries a phantom (currently 31 and 4
  respectively); `0` once those are cleared; `2` on usage error. Stale/unknown history does not gate.
- This report changed no contract. The only contract edited in this slice is
  `p2.1-navigation-surface` (A3).
