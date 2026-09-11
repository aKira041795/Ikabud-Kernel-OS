# C6 RESULT — Route authority: enforcement, not courtesy

status: PASS
task: Make declared capability authority a prerequisite for entering a routed operation.
contract: `.ai/c6-route-authority.contract.md`
chair consult: `.ai/mission-sol-response.md` (GPT Sol; Q1–Q5 adopted)

## Changed

| File | What |
|---|---|
| `kernel/Capabilities/CapabilityBus.php` | `authorize()` — bus-level authorization probe. Shares provider resolution, `applyPolicy` caller gating and `CapabilityAuthorizationRegistry` with `call()`, invokes **no provider**, and is **fail-closed** where `call()` is fail-open (no policy row ⇒ refused). |
| `src/helpers/module-manager.php` | Declaration reader (`capabilities.routes`, `governance.exemptions`), key resolution, decision, and `moduleRouteAuthorityEnforce()` — the guard, called immediately before `$routeCallable($params)`. |
| `public/index.php` | Passes the matched route pattern + method into `executeModuleHandler()` so the guard matches the router's exact declaration key. |
| `kernel/Workbench/Governance/GovernanceCensus.php` | Reports `dispatch` (`enforced`/`exempt`/`undeclared`) and `reach` (`bus-reachable`/…) as two independent facts. The old single number is not silently redefined — it is now reported as `bus_reachable`. |
| `ikabud` | `workbench:governance --gate` (zero-growth, exit 1) and `--update-baseline`. |
| `modules/cms-akira/cms-akira-core/module.json` | Declares the five post mutations' authority. |
| `tests/module_route_authority_test.php` | New — 28 assertions incl. the sentinel falsifier. |
| `tests/workbench_governance_census_test.php` | Updated for the split metric. |
| `.governance-baseline.json` | Frozen undeclared-route debt. |
| `.github/workflows/ci.yml` | Gate runs in CI. |

## Verification

**Full suite** — `composer test`: 105 files, **97 passed, 0 failed**, 8 skipped.
**Static** — PHPStan 0 errors on changed files; `architecture:check` 6/6.
**Gate** — at baseline `GOVERNANCE GATE=PASS` (exit 0); with one declaration removed
`GOVERNANCE GATE=BLOCKED … cms-akira-core: undeclared rose 0 -> 1` (exit 1).

**Falsification.** Bypassing the guard (`if (false && !moduleRouteAuthorityEnforce(`) makes the
handler body run; the test then dies and the integrity guard exits **1**, not 0. The test cannot
report green on a missing enforcement point.

**Live HTTP, tenant 54 (`akiracms.test`), real Apache:**

```
POST /api/v1/cms-akira/posts                                  -> 201, post created (draft)
  log: route.authority.allowed {"state":"declared","allowed":true,"reason":"authorized",
                                "capability_id":"akira.post.create@1","actor_role":"author"}
same Idempotency-Key replayed                                  -> 201, SAME post id/correlation;
                                                                  count(slug) = 1  (one transition)
POST /api/v1/cms-akira/posts/{slug}/publish  as role=author    -> 403
  body: {"ok":false,"error":"Forbidden","reason":"unknown_role",
         "capability":"akira.post.publish@1","state":"route_authority_denied"}
  sentinel: the target post status remained "draft" — the handler body did not run
  log: route.authority.denied {…,"reason":"unknown_role","actor_role":"author"}
```

Probe data was removed afterwards: tenant 54 back to 0 posts, 0 revisions, all policy rows active.

## Honest number

| | dispatch-enforced | bus-reachable | undeclared | total |
|---|---|---|---|---|
| Akira | **5** | 31 | 28 | 33 |
| `daily-ledger` | 0 | 0 | 52 | 52 |

The previously published 93.9% was **bus-reachability**, not governance. `0/33` routes were
guaranteed by dispatch before this change; `5/33` are now.

## Findings (reported, not papered over)

1. **The authorization registry reads `app()->db()`** — the kernel store, not the tenant's DB
   (`CapabilityAuthorizationRegistry::db()`). For a tenant-local module, authority is kernel-scoped.
   Verified by experiment: deactivating the tenant row changed nothing; deactivating the kernel row
   did. Whether this is intended multi-tenancy semantics or a gap belongs to P5.
2. **Policies are re-asserted from code.** `seedPolicy()` uses `ON DUPLICATE KEY UPDATE … is_active
   = VALUES(is_active)`, so a database revocation is silently undone on the next activation —
   observed live (row returned to `is_active=1`, full role list, after one request). Policy-as-code
   is defensible; a silent no-op is not.
3. **`unknown_role` mislabels a role mismatch.** `CapabilityAuthorizationRegistry::authorize()`
   returns `unknown_role` when the actor's role is present but not in `allowed_roles`. F3 requires a
   refusal to name the real reason; this one should be `role_not_allowed`.

## Scope

Unexpected files: none. `modules/cms-akira/cms-akira-builder/module.json` and
`storage/cms-themes/akira-ark-demo/` were already dirty/untracked before this task and are **not**
part of this change.

## Not claimed

C6 does **not** complete F4. Scheduled jobs, event handlers, CLI handlers and other direct callables
remain uninventoried. `daily-ledger` remains 0/52 by design — it is untracked and is not edified by
relabelling its debt as exempt.

## Recommended next state

1. `cms-akira-builder` (6), `cms-akira-theme` (4), `cms-akira-shell` (15), `cms-akira-workflow` (1),
   `gui-settings` (2) — declare authority, module by module, driving the ratio up.
2. Decide the registry's database scope (finding 1) before P5 consent work.
3. Fix the `unknown_role` label (finding 3).
4. Then T4 page builder, then P3 delegation.
