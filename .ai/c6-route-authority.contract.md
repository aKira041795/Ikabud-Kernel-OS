# C6 CONTRACT — Route authority: enforcement, not courtesy

status: READY_FOR_IMPLEMENTATION
chair: this session · consulted: GPT Sol (`.ai/mission-sol-response.md`)
repo: /var/www/html/ikabudsix — branch to create: `feat/c6-route-authority`

## Why

`docs/architecture/akira-beyond-the-cms.md` names **P2 — Enforcement** as the next ground:
*"authority is a property of the request, not a courtesy of the handler."*

Measured ground truth (`php ikabud workbench:governance --all`, C5):

```
Akira (cms-akira-* + gui-settings)   31 / 33   93.9%   <- static bus-reachability only
daily-ledger                          0 / 52    0.0%   <- undeclared
```

Sol's correction, adopted as the honest framing: **93.9% is static bus-reachability, not
governance.** `0 / 33` routes are presently guaranteed by dispatch to establish declared authority
before handler code runs. The mechanism:

`src/helpers/module-manager.php:3007` — `$routeCallable($params);` — a bare call. No capability
authorization, no kernel audit, no kernel idempotency. The kernel has all three; they are not on
the route path. **F4 (non-bypassability) is therefore false.**

## Objective

Make a declared capability a **prerequisite for entering a routed business operation**, and make
the ungoverned remainder visible and non-growing — without breaking running tenants.

## Scope

### allowed
- `kernel/Capabilities/CapabilityBus.php` — add one **authorization probe** (no provider invocation).
- `src/helpers/module-manager.php` — declaration loader + guard immediately before dispatch.
- `public/index.php` — pass the matched route pattern + method into `executeModuleHandler()`.
- `kernel/Workbench/Governance/GovernanceCensus.php` — distinguish `dispatch-enforced` from
  `handler-bus-reachable`; **do not silently redefine** the existing metric.
- `modules/cms-akira/cms-akira-core/module.json` — declare the post routes' authority.
- `ikabud` — `workbench:governance --gate` (zero-growth on the undeclared baseline).
- `tests/module_route_authority_test.php` — new.
- `docs/architecture/akira-beyond-the-cms.md` — record the shipped P2 status.

### forbidden
- **No synthetic route capabilities.** A route authorizes against the handler's *business*
  capability. Synthetic ids have no provider and no policy row, so `authorize()` returns
  `missing_policy_row` unless a duplicate capability universe is built — that is a parallel system.
- **No auto-seeded permissive policies.** An auto-seeded row turns fail-closed into decoration.
- **No authority inferred from handler names.**
- **No blanket GET exemption** — GET routes are outside the business denominator, not exempt from
  declaration if they later declare authority.
- **No generic transaction/audit/idempotency wrapper around handlers.** Audit and idempotency belong
  inside the business capability's tenant transaction; a route wrapper cannot guarantee atomicity.
- **No mapping HTTP payloads into business calls.**
- **No edits to `modules/daily-ledger/**`** (untracked, live money domain — analyse only).
- **No domain knowledge in `kernel/Workbench/`**
  (`grep -riE "daily-ledger|akira|content_type" kernel/Workbench/` must stay empty).
- No delegation/P3, no T4 builder, no T5, no UI polish.
- **No claim that C6 completes F4.** Jobs, events, CLI and direct callables are separate entry
  points and remain uninventoried.

## Design decisions (adopted from Sol, Q3)

**D1 — Declaration lives in `module.json`.**
`capabilities.routes` is a map `"METHOD /pathtemplate": "capability.id@1"`, exact keys, e.g.
`"POST /api/v1/cms-akira/posts": "akira.post.create@1"`.
`module.json` already owns routes, exposes/depends, activation and runtime contracts; production
dispatch must not depend on a Workbench test artifact. Reasoned exemptions stay in
`governance.exemptions` (already read by the census — required non-empty `reason`).
Validation: every declared capability must be exposed or depended on by the same module; stale or
unknown declarations are rejected.

**D2 — Identity: reuse the business capability; caller = the route's module; role = the
authenticated actor.**
The guard authorizes capability × provider × caller_module(route module) × actor_role × tenant.
A probe on the bus reuses provider resolution, `applyPolicy` caller gating and
`CapabilityAuthorizationRegistry` — it invokes **no provider**.

**D3 — Three honest states.**
| State | Behavior |
|---|---|
| **declared** | authorization is **mandatory**; denial, missing policy row, or unavailable registry **prevents handler invocation** |
| **exempt** | proceeds; recorded with its declared reason |
| **undeclared** | compatibility-open for now: proceeds, emits a high-severity `route.authority.undeclared` observation, and counts against a **frozen baseline** |

The gate refuses growth: no new module or route may add undeclared routes; enrolled modules must
reach zero. Compatibility is debt with a gate, not a policy.

**D4 — The truth-making change is the guard immediately before `$routeCallable($params)`.**
Declarations, warnings, or in-handler checks do **not** make P2 true. Denial must leave the handler
body unexecuted.

## Acceptance (each falsifiable)

1. **Sentinel test.** A fixture module route whose handler mutates a sentinel *before* any bus call.
   With a policy row denying the route module → POST leaves the sentinel untouched and the response
   is a readable denial. This test fails if the guard is removed or moved into the handler.
2. **Happy path.** Policy allowing → handler runs (sentinel mutated).
3. **Undeclared route** → handler runs, and a `route.authority.undeclared` observation is written;
   the census still reports it `undeclared` (not `governed`, not `exempt`).
4. **Exemption without a reason** → rejected, not silently accepted.
5. **Declaration validation** → a declaration naming an unknown/stale capability is rejected.
6. **Zero growth.** `workbench:governance --gate` exits non-zero when the undeclared count exceeds
   the frozen baseline, and passes at baseline.
7. **Instrument honesty.** The census reports `dispatch-enforced` and `handler-bus-reachable`
   separately; the old `governed` number is not silently redefined.
8. **Akira slice live.** `POST /api/v1/cms-akira/posts` declared; full HTTP demonstration:
   revoke policy → readable denial, no row created; restore → success; replay idempotency key →
   one domain transition.
9. **No regression.** Full suite `0 failed`; MySQL 5.7-safe; `phpstan` + `php-cs-fixer` clean.

## Risk

- **R1 — first-release fail-close breaks live tenants.** Mitigated by D3's three states; only
  *declared* routes fail closed, and only Akira's post routes are declared in this slice.
- **R2 — declaration dishonesty.** A module could declare a capability it does not really need.
  Mitigated by acceptance 8 (runtime evidence) and the sentinel test (adversarial).
- **R3 — the ratio becomes a metric that measures the metric.** Mitigated by acceptance 7.
- **R4 — compatibility mode becomes permanent.** Mitigated by acceptance 6 (the gate) and by
  recording the debt explicitly.

## Deliverable

Result block: status, changed, verification, evidence (raw output), scope, risks, unresolved.
