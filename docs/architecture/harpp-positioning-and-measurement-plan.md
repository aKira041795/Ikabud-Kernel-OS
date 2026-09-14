# HARPP — Positioning and Measurement Plan

**Recorded:** 2026-09-14 · **Source:** external landscape assessment of the 2026 coding-agent field, commissioned
by the owner · **Status:** adopted as the strategic frame for the next milestone (CD-15)

This document exists so that future slices align to a stated position rather than to an impression. It is
deliberately conservative about HARPP's standing and specific about what would change that.

---

## 1. The honest position

> **HARPP is not ahead of Claude Code, Codex CLI, Gemini CLI, OpenHands, Aider, Mini-SWE-Agent, Pi, OpenCode or
> OpenClaw as a coding agent. It is attempting a different layer of the problem: contract-governed autonomous
> project completion.**

Stated as a claim that could be wrong:

> HARPP explores whether **delegated authority, explicit contracts, cheap heterogeneous executors and
> independently re-derived evidence** can produce reliable autonomous engineering at materially lower total
> cost and director attention than a single strong agent.

There is currently **no evidence that HARPP is better than existing systems.** The empirical base is four
slices, one session, one repository, one operator. Any claim beyond "a different layer is being explored" is
unsupported today, and this document should be quoted against anyone (including the Chair) who drifts into
claiming superiority.

## 2. What HARPP is not competing on

| Dimension | HARPP today | Mature agents/platforms |
|---|---|---|
| Raw coding ability | depends on the underlying model | **stronger, more polished** |
| IDE / terminal UX | early, specialised | **far stronger** |
| Sandboxing and isolation | developing | **stronger** |
| Context handling, tool execution, model integration | basic | **vastly ahead** |
| Production maturity at scale | very early | **vastly ahead** |
| Security hardening | early | **major platforms ahead** |

**Decision:** do not rebuild these. **Use mature harnesses as executors.** Keeping the executor replaceable
(Pi, Codex, DeepSeek, Claude, local workers) is a deliberate architectural property, not a temporary
arrangement, and it is what makes the positioning below possible.

## 3. Where the difference actually is

Existing orchestration ("meta-harness") projects normalise **executors** — one interface over many agents.
HARPP asks a different question:

> Who has authority to decide what, under which approved contract; how long should autonomous work continue;
> what constitutes a legitimate stop; and what evidence establishes completion?

And it separates two governance questions that permission models usually conflate:

```
"May the agent execute this command?"          <- sandbox / approval policy
                    vs
"Has the Director already delegated authority for this decision?"
        YES -> Chair decides, records rationale, continues the project
        NO  -> contract boundary crossed -> Director required (L4)
```

The distinctive elements, in the order an external reviewer rated them:

1. **Contract authority** — explicit, machine-parsed envelopes rather than instructions to behave.
2. **The AI Chair** — *"the delegated project authority beneath the contract; decides, records, continues."*
   Ambiguity is explicitly **not** an escalation condition; L4 is reserved for changing or violating the
   approved contract.
3. **A deterministic stop invariant** — approved contract + remaining obligations + no legitimate blocker ⇒
   stopping is an error, and that is *tested* rather than described.
4. **Decision provenance** — Chair decisions recorded (CD-1…CD-14) so judgement can be audited later without
   interrupting progress.
5. **Evidence and independent re-derivation** — claims as objects, re-derived by execution where a pure tool
   can (HARPP 5, partially landed).

The field is moving toward these concerns: published industry reporting describes a large gap between
organisations' confidence in agent security and their capacity to verify and control agent behaviour. The
obsession with authority, contracts, bounded execution, provenance, evidence and release gates is not solving
an imaginary problem.

## 4. Generations, to keep ambition honest

```
GEN 1  coding assistant        "help me write code"
GEN 2  coding agent            "complete this task"          Claude Code, Codex CLI, Aider, Pi
GEN 3  agent platform          "manage these tasks/agents"    OpenHands, multi-agent, cloud agents
GEN 4  governed autonomous     "complete this approved project, make subordinate decisions
        engineering             yourself, prove what happened, stop only when the contract says so"
```

**HARPP is attempting Gen 4. It has not demonstrated Gen 4 at meaningful scale.** That sentence is the whole
of the current claim.

## 5. The cost thesis, which may be the real differentiator

HARPP exists because frontier-model-only development was not economically attractive for the owner. That
constraint shapes the optimisation function, and it is shared by many others — a school lab on recycled PCs, a
small software shop that cannot justify several frontier subscriptions per developer, a solo developer running
one strong subscription plus cheap APIs and local workers.

```
NOT:  MAXIMUM AGENT INTELLIGENCE

BUT:  MINIMUM COST -> SUFFICIENT INTELLIGENCE
      + DETERMINISTIC TOOLS + RAG + CHEAP EXECUTORS
      + EXPENSIVE REASONING ONLY WHERE IT MATTERS
      + AN AUTONOMOUS, ACCOUNTABLE CHAIR
      -> VERIFIED PROJECT COMPLETION
```

So the proposition is not *"here is another coding agent"* but:

> **A governance harness that makes whatever AI compute you can afford behave like a coherent engineering
> organisation.**

## 6. The next milestone is measurement, not more machinery

**Do not add orchestration.** Independent verification must be finished and then *exercised*. The programme:

- Run HARPP on **10–20 complete bounded slices**, across **different executors**.
- Record per slice, from artefacts rather than from recollection:

| Metric | Where it comes from |
|---|---|
| completed without Director intervention | run ledger + decision record |
| Chair decisions per slice | `.ai/chair-decisions.md` |
| **incorrect** Chair decisions | adjudicated after the fact, with the artefact |
| contract violations | `check` results, scope diffs |
| repair / replan cycles | runs per slice |
| claim verification failures (`CONTRADICTED`) | `verify` output |
| cost per slice | requires new capture (below) |
| tokens per slice | requires new capture |
| wall-clock time per slice | run ledger timestamps |
| model-lane distribution (which executor did each slice) | run ledger `lane` |
| **deterministic-tool share** — work decided without a model | contracts, `check`, gates, `verify` |
| **director minutes, split** into concept/business decisions · harness intervention · verification | logged by the director, not inferred |

The two added rows exist because the cost thesis is otherwise unfalsifiable. *Cheapest adequate intelligence* is
only defensible if the numbers show **how often the cheap lane sufficed** and **how much was decided by software
rather than by any model**. A low-cost result achieved by using a smaller model for everything is a different
claim from one achieved by routing most decisions to deterministic tools.

Director minutes are split for the same reason: the thesis is that the director's attention **shifts to concept
and business judgement**. A single "minutes" figure would hide exactly the movement being claimed.

- Then run the **same jobs directly** with Codex, Claude or Pi alone, and compare.

The outcome may be that HARPP loses. Either result is informative, because it finally evaluates the actual
thesis rather than an architectural opinion.

## 7. Measurement integrity — the risk this plan creates

The metrics that judge the harness would be **collected and reported by the harness** (the Chair). That is a
self-validation risk, and the assessment's own warning about a self-validating experiment applies here.

Mitigations, to be binding on the programme:

1. **Derive, never self-report.** Every metric that software can compute must come from artefacts — the run
   ledger, the decision record, `verify` output. The Chair does not report its own diligence.
2. **Director minutes are logged by the director.** They are the metric most easily flattered and least
   amenable to derivation.
3. **Adjudicate the "incorrect decision" column afterwards**, against the artefact, and keep the count even
   when it is unflattering. CD-6, CD-11 and CD-12 are exactly this kind of entry and are already in the record.
4. **Sample-audit by an independent reviewer.** One audit of a slice subset by someone who did not run it.
5. **Treat coverage honestly.** Four slices, one repository, one operator is a pilot, not a study; scale and
   diversity are part of the claim, not a caveat bolted onto it.

## 8. First data point — derived from artefacts, 2026-09-14

Produced by grepping the record rather than by recollection, because §7.1 binds the programme to that. This is
one session, one repository, one operator: a pilot row, not a study.

| Metric | Value (derived) |
|---|---|
| slices dispatched | **6** (+1 attempt that failed on an executor usage cap) |
| delivered without director intervention | **6 of 6** — one needed lane reallocation, one needed the Chair to finish a deliverable the run left incomplete |
| director directives required | **3** — approve the plan, a doctrine change, and one authorization decision. **Two of the three are exactly the classes the model reserves for the director** (concept/architecture, and authority/security) |
| Chair decisions recorded | **15** (CD-1…CD-15) — ≈2.5 per slice |
| **Chair errors requiring correction** | **3** (CD-6 fabricated readiness, CD-11 commit during a live run, CD-12 a published claim that was false) — i.e. **half the slices involved a Chair error** |
| contract violations | **0** |
| claim-verification failures | not yet applicable — `verify` landed in the final slice |
| cost / tokens | **not captured** — the first gap the programme exposes |
| commits | **16** today, of which **11** harness and **5** from a concurrent lane |

**The instructive number is the error column.** A flattering summary of the same session would read "six slices
shipped unattended". The honest one is that **the Chair was wrong three times, and each error was found by
reading an artefact — a log's size, a file's mtime, a staged diff — not by a gate.** No deterministic check
caught any of them. That is direct support for the assessment's rating of independent verification as
"not there yet", and it is why the metric exists: a governance harness that cannot count its own mistakes
cannot be evaluated at all.

It also shows the artefact discipline working: every one of the three errors was caught *because* work left
traceable artefacts, including the refusal to accept a report on its own word.

## 9. What this changes about day-to-day work

- No new capability surface until independent verification is exercised at least once end to end on real
  slices (per the assessment, and consistent with CD-13's ordering).
- Corpus retrofit stays third, and stays just-in-time (CD-2) — fabricating envelopes from legacy contracts
  would grant authority nobody approved.
- Executor choice remains driven by cost shape, and executors remain replaceable.
- Every slice should leave the artefacts the metric table needs, or the programme cannot run later.
