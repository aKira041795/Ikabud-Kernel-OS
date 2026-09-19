# CD-58 WITHDRAWN IN ERROR — REINSTATED 2026-09-15

status: **REINSTATED** · the withdrawal recorded below was made on a partial diagnosis and is **void**
decision: HARPP #98 (`CD-58`), refined by HARPP **#100** filed by the delegated run
disposition requested: **answer it — the widening is deliberately refused, which is the original question**

## Why the withdrawal was wrong

I withdrew CD-58 because a grep showed the committed source already names the fourth caller
(`cms-akira-shell/helpers.php:48`), and I inferred "the row is merely stale, so no decision is needed."

**That inference was wrong. A delegated Sol run proved it in ten minutes.** The seeding API refuses the
change **by design** — `seedPolicy()` applies narrowing edits but deliberately refuses to **widen** an
allowlist, returning `widening_refused` and `operator_regrant_required`. It also falsified both of my
mechanistic hypotheses:

| Hypothesis | Verdict from the delegated run |
|---|---|
| module load-order makes the seed inert | **false** — `cms-akira-core` loads before `cms-akira-shell`, so `cacActivePolicyVersion()` already exists |
| `seedPolicyForCurrentScope()` is insert-only | **false as stated** — it does update, but only *narrowing* changes; widening is refused |

The row has three callers not because it is stale, but because **the system will not widen it without an
operator re-grant.** So the original CD-58 question — *how does a capability gain a caller?* — was the right
question all along, and the `admin-shell-integrity` report had said so in the first place. I doubted a correct
finding on the strength of a shallower one, which is the same diagnostic-order error this file was written to
condemn.

The delegated run also attempted a shell-local reconciliation, was refused by KernelPDO's module-ownership
guard, **fully reverted it**, left no tracked file changed and did not touch the tenant database. The tenant row
is still exactly: three callers, `grant_state = granted`.

## The real lesson

The answer was not findable by grepping, because it lives in a guard's **behaviour**, not in a string. I spent an
hour failing to resolve it, then delegated — and the lane resolved it with a precise mechanism, a falsified
hypothesis table, a reverted experiment, and a narrower proposal than mine.

The failure was therefore not *"escalating instead of diagnosing"*. It was **doing root-cause analysis myself
with expensive reasoning instead of delegating it**, and then escalating when it did not converge.

---

*Original withdrawal record follows, retained for provenance. It is VOID.*

## Original entry: CD-58 withdrawn — it was never a director decision

status: withdrawn (VOID — see above) · recorded 2026-09-15 · author: chair
decision: HARPP #98 (`CD-58`)
disposition requested: **close as unnecessary**

## What was filed

That `akira.shell.admin_page@1`'s caller allowlist refuses `cms-akira-theme`, and that deciding *how a capability
gains a caller* required the director.

## What is actually true

The committed source already includes the theme:

```
modules/cms-akira/cms-akira-shell/helpers.php:48
'caller_module' => 'cms-akira-shell,cms-akira-seo,cms-akira-navigation,cms-akira-theme',
```

The live tenant row — `policy_version 30`, `capability_id akira.shell.admin_page@1`, `is_active 1` — lists only
three callers. **The code is correct; the tenant's row is stale.** The remaining work is to make a corrected seed
converge, which is a bounded code fix dispatched under `.ai/theme-studio-policy-seed.contract.md`.

No authorisation is needed for the theme to join the shell. CD-58 asked for permission that the codebase had
already granted itself.

## Why this is the more important failure, and why it stopped the flow

I queried the **live database row first** and read the **source second**. The symptom looked like an authority
gap, so I escalated an authority gap. One `grep` of the source would have shown the fix was already committed.

This is the third escalation today that dissolved on inspection:

| # | What I saw | What I concluded | What was true |
|---|---|---|---|
| 1 | `403`, `Reason: unknown_role` | a policy refusal | `unknown_role` is emitted when there is **no authenticated actor** (`CapabilityAuthorizationRegistry.php:79`) — the session was dead |
| 2 | Tenant row with 3 callers | the code excludes the theme | the code includes it (`helpers.php:48`) |
| 3 | Both of the above | a director decision (CD-58) | a stale row and a dead session |

**The escalation mechanism made this comfortable.** Filing produces a visible artifact, a notification and a
ticket id — it *feels* like progress. Diagnosing is real work. So I reached for filing before exhausting
diagnosis, and each time the "decision" evaporated the moment someone looked at the source.

The standing policy names this exactly, and I inverted it:

> *"The distinction is **decision provenance** versus **decision interruption**. The owner can inspect provenance
> afterwards; it must not be used to interrupt progress."*

I used provenance as interruption. My own project plan said the same thing — *"Both are recorded with options and
recommendations. Work proceeds around them"* — and then I did not proceed.

**A pending decision is a note, not a stop.** It halts work only when the next bounded action provably requires
the answer.

## Rules adopted

1. **Exhaust deterministic diagnosis before filing.** Tests, `grep`, schema probes, logs, and the repo's own
   Playwright suite (`npx playwright test`). All three escalations above would have dissolved.
2. **Never file as a substitute for diagnosis.** The escalation budget is the director's attention; spending it
   on a question the source already answers is the most expensive mistake available here.
3. **A filed decision does not stop in-scope work.** Only the answer does, and only when the next action needs it.

## What still needs the director

**CD-59 stands unchanged** (HARPP #99) and is a genuine trust-surface question:

- `ai-autonomy.php:1074-1082` — `isGrounded($justification, $contract)` requires a justification that
  `scopeConformance()` never passes, so every `module.json` slice blocks unconditionally.
- 20 of 50 recorded runs sit `blocked`, and `commit-check` reports the ledger clean.

That one does not dissolve on inspection, and it is the only decision presently required.
