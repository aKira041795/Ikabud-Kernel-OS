# CONTRACT — Read authority: prove the primitive on one route

task: read-authority-probe
objective: Establish whether the authority model can express and enforce **read** authority at all.
           Nothing in this repository has ever declared a read route, so this is untested, not merely
           unused. Prove it on one route before proposing it for sixteen.

authority: chair (this contract) — implementation delegated, result verified independently.
status: READY_FOR_IMPLEMENTATION

## Why this slice exists

Measured 2026-09-12 across all 17 `module.json` files in the repo:

```
GET declared : 0
POST declared: 26
```

`capabilities.routes` is a map of `"<METHOD> <path>" => "<capability>"`, so the shape permits GET. But
no module has ever used it, which means one of two things is true and **we do not currently know
which**:

- **(a)** read authority works and simply has no callers, or
- **(b)** read authority was never exercised, so whatever gap exists is undiscovered.

Meanwhile `cms-akira-shell` declares 14 routes and **all of them are POST**, while `routes.php` serves
**16 GET routes** including `/cms-akira-shell/posts` (drafts), `/cms-akira-shell/permissions` and
`/cms-akira-shell/users`. Every read in the product — and in `daily-ledger`, and in the kernel — is
outside dispatch authority today.

A draft leak or a permission read is not a lesser concern than an unauthorised write.

## Scope

### allowed
- `modules/cms-akira/cms-akira-shell/module.json` — declare **one** GET route authority.
- `modules/cms-akira/cms-akira-shell/helpers.php` — only if a read capability must be declared or
  seeded for the declared route to resolve.
- `tests/` — a new test for the probe.
- Read-only inspection of `src/helpers/module-manager.php` (the dispatch guard),
  `kernel/Authority*`, and `kernel/Workbench/Governance/GovernanceCensus.php`.

### prohibited
- Declaring the remaining 15 GET routes. This slice proves one; the extension is a separate decision.
- Any schema change or migration.
- Any change to `authorize()`, `AuthorityScopeResolver`, the entry-point wiring, or the dispatch
  guard. If the guard cannot express a read denial, that is a **finding to report**, not a licence
  to edit it.
- Editing `templates/**` or any rendered output. This slice is authority plumbing, not UI.
- Touching `modules/daily-ledger`.
- Weakening any existing route declaration to make a number look better.

## The probe

Pick the **single most sensitive** read: `/cms-akira-shell/posts` (the draft-bearing post list) or
`/cms-akira-shell/permissions` if the post list turns out to carry a pre-existing handler-level
guard that would mask the dispatch result. State which you chose and why.

Declare it as `GET <path> => <read capability>@1`, following the existing map shape **exactly**.

## Acceptance

**A1 — Does dispatch enforce it?** With the route declared and a caller holding *no* grant for the
read capability, the request must be denied **before the handler body executes**, and denied in the
same shape writes already use (`state: route_authority_denied`). Evidence: a log line or response
proving the handler did not run, not merely that a 403 was returned.

**A2 — Does an allowed caller still succeed?** With a grant present, the read returns its normal
output — unchanged, byte-for-byte if practical. A read that becomes unreadable for authorised users
is a regression, not a fix.

**A3 — Does the census see it?** `GovernanceCensus` / `workbench:governance` must count the new
declaration, and the routed ratio must move by exactly the amount a single declaration implies —
no more. If the census silently ignores GET declarations, that is a **finding**, and it is the most
important thing this slice can discover.

**A4 — Baseline honesty.** `.governance-baseline.json` must not be edited to make the gate pass.
If the gate now fails because coverage *rose*, report it; do not paper over it. If it fails because
coverage *fell*, that is a regression — find it.

**A5 — Report the answer plainly.** The deliverable is a yes/no on whether read authority is a
working primitive, with the evidence for it, and an explicit statement of what remains unknown.

## Verification

```
php -l on every touched file
php tests/<new test>.php                     # must fail before the change, pass after
composer test                                # full suite; report pre-existing failures separately
php ikabud disyl:lint                        # if any template is touched (it should not be)
workbench:governance --gate                  # routed + non-HTTP baselines
```

Note: `composer test` currently reports one failure, `disyl_include_root_test`, which fails on clean
`main` locally and is green in CI. It is a known environment-state issue, **not** caused by this
slice — treat it as pre-existing, and say so rather than attributing it to this work.

## Risk

- **The guard may be write-shaped.** If denial is expressed only for mutating methods, the honest
  outcome is `BLOCKED — ARCHITECTURAL_DECISION_REQUIRED`, not a patch to the guard inside this slice.
- **Existing handler-level guards may mask the result.** `akiraShellAuthorize()` and
  `akiraShellAdminReadDenied()` already exist. If a handler guard denies first, the probe proves
  nothing about dispatch — detect this and report it.
- **The census may not model reads.** Then the primitive is partly real and partly invisible, and
  that is the finding.

## Out of scope but worth noting in the result

- The 15 remaining GET routes in `cms-akira-shell`.
- `AIGovernance` persisting settings, review queue and audit as JSON under `storage/` and gating by
  role — off the capability bus, and the same anti-pattern P2 exists to eliminate. A separate slice.
- `modules/*/tests/` are not executed by the main CI test job.
- `node_modules` committed inside the builder module tree.

## Result format

```
status:        PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:          read-authority-probe

chosen_route:  <method+path, and why this one>
capability:    <the read capability declared>

A1 dispatch_enforces_reads:  yes | no | unknown
   evidence:
A2 allowed_caller_unchanged: yes | no
   evidence:
A3 census_counts_reads:      yes | no
   evidence:
A4 baseline:                 untouched | edited (why)
A5 answer:                   <is read authority a working primitive? what remains unknown?>

changed:       <files>
verification:  <commands + outcomes>
scope:         <any file touched outside the allowed list>
risks:
unresolved:
recommended_next_state:
```
