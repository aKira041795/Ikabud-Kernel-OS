# OBJECTIVE — the owner notification must tell the truth about what was gated

system: HARPP v2 · 2026-09-18 · chair-authored. Executor: `dsh` via `HARPP2_DISPATCH=tools/harpp2/dispatch-dsh.sh`.
authority: CD-82 (`.ai/chair-decisions.md`) — the dsh parity experiment needs one real work item whose gate the
chair authored *before* the fix, so the acceptance verdict is not self-authored.

## The defect (measured, not theorised)

`tools/harpp2/chain.sh` `notify()` derives the acceptance line from `$objective_file` **before** assigning it:

- line 33 computes `acceptance="$(grep -E '^\$ ' "$objective_file" ...)"`;
- line 41 assigns `local objective_file="$objective"`.

Under `set -u` the unbound expansion happens inside the command-substitution subshell, so the parent survives,
the substitution yields nothing, and the fallback at line 34 reports
`acceptance: (none declared in the objective)` — for objectives that declare four gates.

Live evidence, from the product's own inbox (`grep -n "acceptance:" .ai/inbox.log | tail -3` →
`(none declared in the objective)` on every line), and reproduced by the gate below, which prints:

```
ITEM verified: fixture-item acceptance: (none declared in the objective) reason: none next: chain continues
```

This is the surface the owner trusts when the harness is away. A notification that misreports what was gated is
worse than no notification.

## Scope

- `tools/harpp2/chain.sh`
- `tools/harpp2/objectives/harness-notify-acceptance.md`

## Acceptance

```
$ bash tools/harpp2/notify_acceptance_test.sh
```

The gate is **chair-authored and already red**. It extracts the real `notify()` from the real `chain.sh`,
drives it with a fixture objective that declares two gates, and asserts the emitted message names both of them.
It does not care how the fix is made — only that the message becomes true.

## Regression guards

```
$ bash -n tools/harpp2/chain.sh
$ php tools/harpp2/boundaries_self_test.php
```

## Boundaries

- **Do not modify the gate.** `tools/harpp2/notify_acceptance_test.sh` is the acceptance instrument and is
  outside your scope. Making it pass by editing it is the one thing that turns this item into a failure.
- Keep the change minimal: the acceptance line must be computed *after* the objective path is resolved, and
  `objective_file` must still fall back to `$DIR/objectives/${objective}.md`. Do not restructure `notify()`,
  do not rename functions, do not change its output format beyond the acceptance value becoming truthful.
- No new dependency, no new file except the ones listed in Scope, no change to how the chain launches lanes,
  and no `git add`/`commit`/`push`.
- If the gate cannot be made green without weakening it, say so and stop — that is a finding, not a failure.
