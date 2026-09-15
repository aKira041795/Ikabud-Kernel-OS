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
