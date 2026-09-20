# MEASUREMENT CONTRACT — F1–F4: does the substrate actually carry a second domain?

status: READY_FOR_MEASUREMENT
owner: measurement agent (Sol)
repo: /var/www/html/ikabudsix

## Why

The program thesis (user directive 2026-09-10): *Akira is a POC, the kernel as substrate is the
thesis, everything else happens because the kernel exists, Workbench is the proofing/testing
instrument.* Background: `docs/architecture/kernel-substrate-thesis.md`.

Your job is to **measure**, not to build, and specifically to **try to falsify** the claims
below. A confirmed thesis with weak evidence is worthless; a disconfirmed claim is the most
valuable thing you can return. Do not help the hypothesis.

## SAFETY CONSTRAINTS — read first, they override the task

`modules/daily-ledger` is a live money domain. It contains a **destructive "live deployment
reset" that wipes `dl_*` data**, a POS, cashier shifts, withdrawals and deliveries.

- Do **NOT** enable `daily-ledger` for any existing tenant, and do **NOT** touch tenant 54.
- Do **NOT** run any migration for it. Do **NOT** run any handler that writes.
- Do **NOT** invoke, test, or trigger the deployment reset or any data-wipe path.
- Do **NOT** modify `modules/daily-ledger/**` and do **NOT** commit it (it is untracked by
  design — that is an ownership decision belonging to the user, not to us).
- Read-only commands and static analysis only. If a question can only be answered by writing
  data, say so and skip it.

## The four claims

| # | Claim | Falsified if |
|---|---|---|
| F1 | **Domain generality** — a second, maximally-unlike domain stands on the same substrate | it needs a new kernel primitive, a substrate exception, or module code cannot express it |
| F2 | **Governance universality** — one instrument observes all domains | the instrument needs domain-specific branches to see the domain |
| F3 | **Explicability** — for any capability call the system answers who / what / which policy / decision / reason / audit record | answering needs module source |
| F4 | **Non-bypassability** — nothing conducts business outside the substrate | real operations run with no policy, no audit, no idempotency |

## Measurements to produce

**M1 — Surface census (F4).** For `daily-ledger`, count and classify:
- capabilities declared in `module.json` (`exposes`)
- routes declared in `routes.php` (by method), and handler functions in `handlers*.php`
- which handlers call the capability bus, and which call `kernel.audit.record@1` or the
  idempotency capabilities directly
- the resulting **governance ratio** — and say precisely what the denominator means, so the
  number is not a rhetorical trick. (Only count functions that represent *operations*, and
  state your classification rule.)

**M2 — Dispatch path (F4 mechanism).** Determine, by reading the code, whether a
route-dispatched handler (`module-id:functionName`) receives any of: capability policy
authorization, audit recording, idempotency. Name the file and line where dispatch happens and
state plainly whether anything wraps it. This is the crux of F4 — be exact and quote the code.

**M3 — Instrument reach (F2/F3).** Run the Workbench instrument against the second domain and
show its raw output:
- `php ikabud workbench:validate daily-ledger --json` (and without `--json` if useful)
- whether `workbench:explain` / `workbench:audit` can say anything about it
- any place the instrument needed to know the domain's name or business concepts — a branch
  like that is a direct F2 falsification. Report it if it exists.

**M4 — Comparison (the actual proof).** One table, same axes, both domains:

| Axis | cms-akira-* | daily-ledger |
|---|---|---|
| capabilities exposed / routes / handlers | | |
| governed operations ratio | | |
| own auth? | | |
| capability `depends` | | |
| workbench contract present? | | |
| instrument can enumerate surface | | |
| instrument can explain a decision | | |

**M5 — What the ledger needed that content did not (F1).** List every substrate extension the
second domain required: new kernel primitive, kernel change, capability-bus special case,
activation exception, anything. **"Nothing" is an acceptable and valuable answer — but only if
you actually checked.** If it needed something, name it; that list is the finding.

**M6 — Evidence of intent.** Quote the ledger's own documentation about its architecture
(its README states business logic is handler-based and not exposed as capabilities). Confirm or
refute that claim against the code.

## Rules

- Every claim needs a raw command or a quoted `file:line`. No assertion without evidence.
- Distinguish **measured** from **inferred**, and label each.
- Do not weaken, fix, or refactor anything to make the numbers look better. You are auditing,
  not cleaning.
- If you cannot measure something safely, say so and explain what would be required.
- Leave the working tree exactly as you found it. No commits, no branch, no `.ai/**` edits.

## Deliverable

Compact result: per-claim verdict (SUPPORTED / FALSIFIED / UNMEASURED) with the evidence, the M4
comparison table, the M5 list, and an explicit "what I could not measure and why". Prefer being
right over being reassuring.
