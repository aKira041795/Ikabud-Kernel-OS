# CHAIR BRIEF — Sol architectural consult: "Akira in our own terms"

repo: /var/www/html/ikabudsix (branch `main`, HEAD a53eb2c)
you: GPT Sol (architecture + high-value reasoning)
chair: this session's assistant. You are being consulted, not asked to code.
read the repo yourself as needed — this brief is the question, not the evidence.

## 1. The user directive (verbatim intent)

> "We are shedding WordPress's influence and strike a new CMS that will define Akira with respect
> to the ikabud philosophy. Akira will still be easy to use, themable, extended through submodules,
> driven by ARK and Theme Editor. Page builder is still fine. The difference, we scope these in our
> own terms now. We're not competing, we're leveraging what we have. And, through this, we make a
> difference. Not forced but because we're uniquely thinking differently."

They have granted implementation autonomy and explicitly asked that you be involved.

## 2. What is already set (read these; do not re-derive)

- `docs/architecture/akira-beyond-the-cms.md` — five pillars P1..P5. P1 (provenance) shipped.
  **P2 (enforcement) is named the actual next ground**; P3 delegation / P4 verification / P5 consent
  are explicitly deferred *behind* P2.
- `docs/architecture/kernel-substrate-thesis.md` — Akira is the POC, kernel is the substrate,
  Workbench is the instrument. Four falsifiable claims F1–F4.
- `docs/architecture/akira-product-direction.md`, `docs/architecture/cms-akira-extensibility-adr.md`,
  `docs/architecture/ark-authority-adr.md`.
- `.ai/c5-governance-census.contract.md` — C5 = the instrument (shipped, uncommitted).
- `docs/kernel/cms-akira-reference-cms-adoption-roadmap.md` — T1..T5 (T1/T2/T3 merged, T4 page
  builder unblocked, T5 deferred).

## 3. Ground truth I measured this session (use it, don't re-run it)

`php ikabud workbench:governance --all`:

```
cms-akira-builder      6/6   100.0%
cms-akira-core         5/5   100.0%
cms-akira-shell       15/15  100.0%
cms-akira-theme        4/4   100.0%
cms-akira-workflow     1/1   100.0%
cms-akira-* (rest)     0/0      —  no business routes at all
gui-settings           0/2     0.0%  UNDECLARED
Akira total           31/33   93.9%
daily-ledger           0/52    0.0%  UNDECLARED
```

Mechanism confirmed by reading `src/helpers/module-manager.php` (`executeModuleHandler()`):
dispatch ends at **line 3007: `$routeCallable($params);`** — a bare call. No capability
authorization, no kernel audit, no kernel idempotency wraps it. The kernel *has* all three
(`CapabilityAuthorizationRegistry`, `kernel.audit.record@1`, `kernel.idempotency.{claim,commit,release}@1`)
— they are simply not on the route path.

Also: `daily-ledger` reimplemented governance locally (`dl_auditLog()`, cache-backed idempotency)
because the substrate offered a handler-first domain no path. That is the diagnosis the direction
doc gives, and it is correct.

## 4. The questions

**Q1 — Is P2 the right next ground, and is C6 the right primitive?**
Or is there something more load-bearing that the doc got wrong? Attack it. If P2 is right, say so
plainly and say what would have to be true for it to be wrong. Do not be diplomatic.

**Q2 — "Akira in our own terms" — what is the product, concretely?**
The risk named in the thesis doc is R1: *the POC becomes the product by accident*, and the
unfalsifiable-slogan risk in the direction doc. I need a product definition that is:
- recognizably a CMS to a normal user (easy to use, themable, submodules, ARK + Theme Studio,
  page builder retained) — the user insists on this, it is not negotiable;
- structurally unlike a CMS, because authority is a runtime property rather than a plugin's opinion;
- **falsifiable** — I must be able to write a test that fails if the claim is false.
Give me the minimum set of user-visible properties that satisfy all three, and the ones I should
refuse to build. Be concrete about what a user *does* differently, not about architecture words.

**Q3 — C6 enforcement design.** Constraint: a module declares the authority each route requires;
dispatch enforces it; exemptions are explicit and reasoned; the census measures the remainder; the
release gate refuses to let it grow. Evaluate these design questions and pick:
1. Where does the declaration live — `routes.php` value shape (route → capability id), `module.json`
   (`capabilities.routes`), or the existing `workbench-contract.json` exempt list? Justify against
   the existing conventions in this repo (the census already reads a declaration — read it, see
   `kernel/Workbench/Governance/GovernanceCensus.php`).
2. What is the *identity* of a route-dispatch call on the bus? A route handler is `caller_module`
   but has no capability id of its own. Do we synthesize a synthetic capability id per route, reuse
   the handler's business capability, or authorize as the *caller* with an actor role? What breaks
   in `CapabilityAuthorizationRegistry` (read `authorize()` — it needs capability_id, version,
   provider, caller_module, actor_role, tenant, policy row) if we pick each option?
3. Fail-open vs fail-closed on the *first* release. Akira today is 31/33; daily-ledger is 0/52 and
   is a live money domain. What is the migration path that does not break running tenants, and does
   not merely relabel the failure? (The census contract explicitly forbids relabelling ungoverned as
   exempt — hold that line.)
4. What is the smallest change that makes P2 *true* rather than *declared*?
5. What must C6 deliberately NOT do?

**Q4 — Sequencing.** Rank: C6 enforcement, T4 page builder (unblocked), P3 delegation, T5. What is
the single next slice, and what is the smallest end-to-end demonstration that P2 works — one that a
skeptic can break?

**Q5 — The uncomfortable question.** The census says Akira is 93.9% governed *statically* (a bus call
is reachable in the handler). Is that number evidence of anything, or is it a metric that measures
the metric? If the latter, say so and say what the honest version of the number is.

## 5. Constraints on your answer

- MySQL 5.7 compatible (no window functions, no CTEs), Bluehost shared hosting is the target.
- No new dependencies. Reuse kernel primitives; do not design parallel machinery.
- Oracle-safety: the Workbench instrument must stay domain-neutral
  (`grep -riE "daily-ledger|akira|content_type" kernel/Workbench/` must stay empty).
- `modules/daily-ledger/**` is untracked and is a **live money domain** — analyse only, never edit.
- Prefer the smallest correct diff. Name the substrate claim each proposal proves.

## 6. Output format (keep it tight — I have to act on it)

```
Q1 verdict:            <P2 right / wrong — one paragraph, no hedging>
Q2 product definition: <the 5-7 user-visible properties + explicit refusals>
Q3 design:             1..5, each with the decision and the reason it wins
Q4 sequence:           <the single next slice>
Q5 honest number:      <what the ratio really means>
risks:                 <what you would bet against>
```
