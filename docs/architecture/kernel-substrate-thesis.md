# Kernel Substrate Thesis — Akira is the proof, Workbench is the instrument

status: direction (chair, 2026-09-10) · authority: user directive 2026-09-10

## The thesis

> Akira (CMS) is a POC. The kernel as substrate is the thesis. Everything else happens
> because the kernel exists. Workbench is our proofing and testing.

Restated so that it can **fail**: *one governed substrate carries any domain; domains are
additive (module code only, no substrate surgery); and their governance is observable
without reading the domain's source.*

A thesis that cannot fail is a slogan. It fails if any of these is false.

| # | Falsifiable claim | Fails if |
|---|---|---|
| **F1** | **Domain generality** — a second, maximally-unlike domain stands on the same substrate | it needs a new kernel primitive, migration to `kernel/*`, or a substrate exception |
| **F2** | **Governance universality** — one instrument governs/observes all domains | Workbench needs domain-specific branches to see a domain |
| **F3** | **Explicability** — for any capability call, the system answers *who / what / under which policy / decision / reason / audit record* | answering requires reading module source |
| **F4** | **Non-bypassability** — nothing acts outside the substrate | a module mutates state unlogged, unlogged-by-idempotency, or unchecked |

F1 and F2 are the load-bearing claims. F4 is the one that makes the substrate worth
existing at all: a substrate you can route around is a suggestion.

## Ground truth measured 2026-09-10

**Workbench is already the instrument, and already domain-neutral.**

```
grep -rlniE "cms-akira|akira\.|content_type|cms_" kernel/Workbench/   →  no files
ikabud workbench:validate <module> [--json]     # validate a module's test contract claims
ikabud workbench:run <module> --gate=...        # record a gated run
ikabud workbench:explain <run-id>               # explain a durable run
ikabud workbench:audit | benchmark | doctor | init | task:*
```

Subsystems: `Contracts` (test contracts + migrator + validator) · `Development` (task
contracts → artifacts → verification artifacts → git evidence) · `Audit`
(`CapabilityAuthorityAuditor`, `WorkflowGuardAuditor`) · `Graph` (module/capability graph,
spec generation) · `Comprehension` (explain a module) · `Runs`, `Issues`, `Planning`,
`Intelligence`, `Governance`, `Benchmark`, `Evidence`, `Extensions`.

**Two real domains already exist on the substrate.**

| | Akira (content) | daily-ledger (money/ops) |
|---|---|---|
| Nature | content, revisions, composition | POS, deliveries, offline sync, reconciliation |
| Auth | shell-owned | owns `kernel.auth.authenticate@1` |
| Governed exports | — | sales, variances, branch summary, month-end |
| Capability depends | on other `cms-akira-*` | **`[]` — stands alone** |
| Workbench contract | — | `workbench-contract.json` present |
| Tracking | tracked, CI-covered | **untracked** (local only) |
| Measured by Workbench | partially | **never** |

So the substrate already carries a content domain *and* a money domain with zero
outbound capability dependencies. F1 is not speculative — it is **unmeasured**.

## The gap is a run, not a build

Akira is instrumented; daily-ledger has never been measured. Nothing in this repo has
ever put the *same* instrument across *both* and published the comparison. That comparison
is the proof of the thesis, and it is cheap because every part already exists.

What must be produced for each claim:

- **F1** — run the module lifecycle (discover → enable → migrate → capability resolution)
  against daily-ledger and count every substrate exception needed. Then answer: *what did
  the ledger need that content did not?* Expected answer: nothing. A non-empty list is the
  most valuable finding available, because it says exactly how the substrate is
  content-shaped.
- **F2/F3** — `workbench:validate <module>` over `daily-ledger` and over `cms-akira-*`,
  side by side, same command, same output shape. Any branch in Workbench that knows a
  domain's name is a bug in the thesis.
- **F4** — attempt the bypasses on purpose: direct DB write, capability call without policy
  row, mutation without audit, replay without idempotency. Each attempt must be *refused*
  and the refusal must be *readable*. Refusals are evidence; silently-succeeded bypasses
  are thesis failures.

## Consequences for the program

1. **Akira is calibration, not ambition.** Its value is being the fully-instrumented,
   known-good reference that defines what "governed" looks like. It stops absorbing effort
   that buys no substrate claim.
2. **Every cycle must name the primitive it proves.** A change to Akira that cannot name a
   substrate claim moves down the queue. C4 (provenance = authority over time) is a good
   example: it must read identically over a content revision and a ledger entry, so it is a
   cross-domain primitive, not a CMS feature.
3. **Workbench becomes a deliverable.** Today it is a toolkit of governance subsystems.
   Under this thesis its job is to render, for any module + tenant: capabilities
   exposed/consumed, policy rows and decisions, denial reasons, audit trail, provenance,
   contract validation — **domain-independent**. Its own subsystems are also the AI-dev
   control plane, so the same instrument proves both layers.
4. **CMS polish that proves nothing stops.** WordPress parity is not the benchmark.
   `Benchmark/CompetitiveBenchmark` is the wrong yardstick; the yardstick is *a second
   domain at near-zero marginal substrate cost*.

## Risks

- **R1 — the POC becomes the product by accident.** Akira is visible and pleasant to
  polish, so it will keep absorbing effort unless every change names its claim. Mitigation:
  the "name the primitive" rule above, applied at review.
- **R2 — the substrate is content-shaped.** The kernel's primitives (entity views, entity
  context, capabilities) were designed while content was the only domain. F1 is exactly the
  test of this, and failure is a *finding*, not a setback: better to learn it now than after
  three more domains are built on a content-shaped abstraction.

## Next

Measure first, decide ownership after. daily-ledger can be exercised **in place, as a local
experiment**, without admitting it to the repo — running the instrument costs nothing and
produces the evidence the ownership decision deserves.

Admitting daily-ledger into tracked history is a product-ownership decision (it carries a
real business domain), and it stays with the user. It is not a prerequisite for measurement.
