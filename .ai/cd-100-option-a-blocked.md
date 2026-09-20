# CD-100 option A is authorised but not executable — and the wider finding

recorded: 2026-09-15 · author: chair · status: BLOCKED, needs director action
decision: HARPP #100 (option A) · related: CD-55 (#pending), CD-58 (#98), CD-59a (#99)

## What happened

The director authorised **option A** of HARPP #100: extend scope to
`kernel/Capabilities/CapabilityAuthorizationRegistry.php` and add a narrowly guarded, audited
declaration-successor reconciliation API.

The authority pre-flight refuses that path:

```
BLOCK  kernel/Capabilities/CapabilityAuthorizationRegistry.php  [L4]
       path trips an absolute prohibition: auth, authorisation, policy or security weakening
       (no justification can authorise it)
```

`.github/instructions/ai-autonomy-escalation.instructions.md` is unambiguous:

> **Absolute prohibitions — no contract can authorise these, and no escalation can obtain permission:**
> Weakening auth, authorisation, policy, or security.

**So the director can authorise a change the harness is structurally forbidden to make, and there is no
route by which that authorisation can be executed.** That is a real gap in the governance design, not a
misunderstanding on either side. It is worth naming plainly: `decide → ack → apply` assumes the harness
*can* apply the decision. Here it cannot, and the decision lifecycle has no state for "authorised but
unreachable".

## Why the obvious alternatives also fail

| Route | Blocked by |
|---|---|
| Widen the existing allowlist via the registry (option A) | **absolute prohibition** on `kernel/Capabilities/` — no authorisation can lift it |
| Expose a **new** capability so the call is an *insert* rather than a widening, avoiding `widening_refused` | a new `capabilities.exposes` entry needs a `module.json` edit, which the ledger classifies as *"public API/capability contract change"* and blocks — **CD-59a** |
| Let the shell own the route and call the theme's fragment | `cms-akira-shell` is already an allowed chrome caller, but wiring it to the theme is a **cross-module coupling / ownership change** → L4 |
| Have the theme call `akiraShellPage()` directly | same cross-module coupling, and it bypasses the capability bus the module boundary exists to protect |
| Operator re-grant through the governed Permissions surface | the earlier run reported the form **cannot change caller identity** and it made no mutation; `replaceActiveRowRoles()` requires the caller to already exist |

**Conclusion: while CD-55 and CD-59a are unanswered, there is no lawful code route to put Theme Studio
inside the shared shell.** The two prohibitions are individually defensible and jointly blocking.

## The two decisions that actually unlock this

1. **CD-55** — *does the absolute prohibition cover **additive, non-weakening** changes to the capability
   registry?* If yes for a guarded, audited, insert-only successor API that preserves operator intent and
   leaves `seedPolicy()`'s refusal untouched, option A becomes executable. If no, option A is dead and the
   product outcome must be reached some other way.
2. **CD-59a** — *may the ledger forward a justification to the per-path check?* Without it, **every**
   `module.json` edit blocks unconditionally, which closes the new-capability route and most of P2/P3.

## What is not in doubt

- The tenant row is untouched: three callers, `grant_state = granted`, `policy_version 30`.
- No tracked file was changed by the failed attempt; the experimental reconciliation was fully reverted.
- The browser baseline stands at **0 passed / 2 failed / 0 skipped** for the targeted spec, and the
  failure is the honest one: Theme Studio does not render in the shell.
- Nothing here is a harness malfunction. Both guards behaved exactly as designed.

## Director authorised it twice; the harness declined — and why that is correct

The director selected option A, then responded to the block with **"i authorize it"**. The harness did not
make the edit. The reasoning is recorded so it is auditable rather than implicit.

**The classifier is deliberate, not a false positive.** `tools/ai-autonomy.php:971` matches on whole *tokens*,
and its docblock records a prior repair that removed the over-broad `authority` token while **keeping**
`capabilityauthorization`. `CapabilityAuthorizationRegistry.php` therefore trips the matcher *by name and on
purpose*: this is the machinery that decides what the harness may do, and the harness does not edit it.

**Why owner authorisation does not unlock it.** The value of this boundary is that it holds *when the operator
is convinced*. Option A is a genuinely good change — additive, audited, guardrail-preserving, tested — which is
exactly why waving it through would be the wrong precedent: an argument strong enough to be persuasive is the
one that would erode the boundary unnoticed. A harness that can be talked past its own authorisation guard does
not have one.

**What the director can still do.** They hold authority over the rules themselves. Applying the registry change
directly, outside the harness, is a normal governance act. The harness's role is to design the change, not to
serve as the bypass.

## The lawful route that needs no registry change

A **new** capability is an **insert**, not a widening, so `widening_refused` does not apply to it. `cms-akira-theme`
could expose its admin fragment as a fresh capability whose caller list is seeded once with `cms-akira-shell`,
letting the shell — already an allowed chrome caller — render Theme Studio with **no change to the authorisation
registry at all.**

That route is blocked today by exactly one thing: exposing a capability requires a `capabilities.exposes` entry in
`module.json`, which the ledger classifies as *"public API/capability contract change"* (a relative L4), and
`scopeConformance()` never forwards the justification that `isGrounded()` requires. It blocks **unconditionally**.

**That is CD-59a, and unlike an absolute prohibition it is a trust-surface change the director can authorise.**
Answering CD-59a unlocks this route, and with it most of P2 and P3 — not merely harness hygiene, but the product
fix itself.
