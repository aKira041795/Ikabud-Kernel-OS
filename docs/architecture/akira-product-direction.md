# CMS Akira — Product Direction (chair-authored, 2026-09-10)
> **DEMOTED 2026-09-10 — see [kernel-substrate-thesis.md](kernel-substrate-thesis.md).**
> Per the product owner: *"Akira as CMS module is a POC, kernel as substrate is my thesis.
> everything else happens because the kernel exists. workbench is therefore our proofing and
> testing."* Akira's role is now **calibration** — the fully-instrumented, known-good
> reference that defines what "governed" looks like. Read this file for Akira's product
> surface; read the thesis file for what the program is actually for.
Authority: direction approved by the product owner ("I am fine with your ideas").
Execution model: the chair (this assistant) owns direction, contracts and review;
implementation is delegated to Sol (`openai-codex/gpt-5.6-sol:low`) and Flash
(`deepseek/deepseek-v4-flash`). Every cycle ships as a small PR behind CI.

## The thesis

Akira does not compete on "easier than Elementor". It competes on **consequence**.

> **Akira is the CMS that can prove what it published — and the only one where agents
> can work safely.**

Mainstream CMSs give convenience. None can prove what was live at a given moment, who
authorised it, or that an extension stayed inside its declared authority. Those are
exactly what the Ikabud kernel already guarantees, so they become the product's
differentiator rather than marketing claims.

## Why only this kernel can ship these

| Kernel guarantee | Product surface it unlocks |
|---|---|
| every action is a capability + policy row + actor, in one tenant transaction | provable authorship, revocable authority, agent scopes |
| idempotency keys + audit records | replayable history, duplicate-proof publishing, receipts |
| first-class revisions + deterministic DiSyL render | time travel, semantic diffs, release sets |
| real tenant isolation (`dbForTenant`) | governed bundles and promotion between tenants |
| extension contract (`exposes`/`depends`/`contributes`) | capability diffs at install, revocation with safe fallback |

## Three pillars

**P1 — Provenance & time travel ("content you can prove").**
A provenance drawer on every entity: revision chain, the capability invoked, the policy
decision, actor, idempotency key and correlation id, plus *view as of* any revision and
a **semantic, block-level diff**. Regulated content teams (health, finance, education,
government) buy this; it is also the clearest demonstration that the kernel works.

**P2 — Governed portability: bundles.**
A bundle = theme + block library + composition set + settings + content hash, exported
and applied to another tenant with a **dry-run diff** and an audited, idempotent apply.
This is the grown-up form of T5: dev→staging→prod promotion, agency→client handoff,
franchise template tenants, disaster recovery. Portability is only safe because tenancy,
policy and idempotency already exist.

**P3 — Extensions that cannot exceed their contract.**
At install/update show the **capability diff** ("this version adds 2 capabilities, touches
3 tables") and require approval; per-tenant enablement; revocation that degrades to a safe
fallback (already proven: a block whose capability is revoked renders a fallback instead of
breaking the page). Plus a per-tenant **Trust Center**: capabilities in use, who may
publish, recent governed actions, drift from declared policy.

## The forward wedge: governed AI authorship

Because authority is a capability + policy row, an agent can be granted *exactly* scoped
power — draft-only, one content type, never publish — with receipts and human approval
through the workflow engine. Everyone else asks buyers to trust a vendor's agent; Akira can
show what the agent was allowed to do and what it actually did. `cms-akira-ai` grows from a
deterministic summary helper into an **agent surface** (`akira.agent.*` capabilities).

## Refusals (identity is mostly refusals)

- no pixel-paint / Elementor-style canvas; no HTML-as-source (ADR)
- no client-authoritative rendering: the canvas edits JSON, the server renders
- no plugin model with unbounded trust
- no feature-breadth race with WordPress or Drupal
- no AI behaviour that is not a capability with a policy row, idempotency and audit
- no test that passes because it did not run (verification integrity is doctrine)

## Ikabud philosophy — binding constraints on every cycle

1. **Capability-first.** New behaviour arrives as a declared capability with `exposes`
   (and `depends`), never as a direct cross-module call or a new global.
2. **One tenant transaction.** Mutations commit idempotency + audit + domain change
   together, or not at all.
3. **Governed reads too.** History/provenance reads are capabilities; unauthorized
   callers are denied and that path is tested.
4. **Deterministic server render.** No HTML-as-source, no client-side block rendering.
5. **Module boundaries.** No cross-module table access; the architecture check is the
   arbiter.
6. **Verification integrity.** Evidence, not assertion: type-check/build, module tests,
   live HTTP/browser verification, CI green. Failures must be legible (never exit 0).
7. **Smallest correct change**, with the previous behaviour preserved where it was right.

## Cycle plan

| Cycle | Deliverable | Notes |
|---|---|---|
| done | Honest gate (PR #107) | CLI failures exit 1 and self-report; CI keeps artifacts; certify job has a DB |
| **C4** | **Provenance drawer + "view as of"** (P1) | reads existing audit + revisions + render; the positioning demo |
| C5 | Governed bundles (P2) | T5 revisited as trusted portability; dry-run diff + audited apply |
| C6 | Extension trust: capability diff + Trust Center (P3) | install/update approval, revocation fallback |
| C7 | Governed AI authorship (`akira.agent.*`) | scoped agent capabilities + approval inbox + receipts |

Each cycle: contract → implementation (delegated) → chair verification (browser + tests +
CI) → PR → merge. A cycle that cannot be verified is not finished; the chair escalates
rather than masking.

## Success signals

- A composed page can be opened at any historical revision and rendered exactly as it was.
- Every published change answers: who, when, under which capability and policy decision.
- A theme + block library + page set can move between tenants with a reviewable diff.
- An operator can see, and revoke, exactly what an extension or agent is allowed to do.
