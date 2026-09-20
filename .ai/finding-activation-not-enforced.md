# Finding: tenant activation is not enforced at capability dispatch

**Discovered:** Phase 0 (2026-09-13) · **Severity:** governance boundary · **Status:** FILED, not fixed

## The measurement

Tenant 54 (`akiracms-001`) has activation rows for **8** modules. `cms-akira-workflow` is **not** among
them — `SELECT COUNT(*) FROM tenant_module_settings WHERE module_id LIKE '%workflow%'` → **0**.

Yet:

| Fact | Evidence |
|---|---|
| `cms-akira-shell` **depends on** workflow capabilities | manifest `capabilities.depends`: `akira.workflow.evaluate@1`, `akira.workflow.transition@1` |
| `cms-akira-workflow` **provides** them | `akira.workflow.evaluate@1`, `akira.workflow.transition@1`, `akira.workflow.runs@1` |
| Those capabilities **executed** | **6** audit rows, `module=cms-akira-workflow`, `action=akira.workflow.transition`, 2026-09-13 09:28–09:45 |
| Profiles that would install it never ran | `cms-akira-profile-visual` and `cms-akira-profile-headless` both list `installs: cms-akira-workflow`; neither has an activation row for this tenant |

## What this means

**Per-tenant module activation does not gate capability execution.** A capability provided by a module
with no activation row for the tenant is still resolvable and still runs. The shell's declared
dependency closure is satisfied against **globally discovered** modules, not against the tenant's
activated set.

So `_module_enabled` / `_module_activation_state` currently control:
- which settings rows exist for the tenant,
- what the kernel control plane displays,

but **not** whether the tenant can execute that module's capabilities.

This is the third instance this session of the same defect class — **a declaration that does not
control what it appears to control**:

1. `theme:validate` passed what activation rejected.
2. The Theme Studio nav declared `["admin"]` while its policy admitted `administrator` — the
   declaration hid a feature it did not govern.
3. **Tenant activation declares entitlement while capability dispatch ignores it.**

## Recommendation

**Enforce activation at capability dispatch, and make dependency closure activation-aware.**

1. `CapabilityBus` should refuse to resolve a provider whose module has no activation row for the
   resolved tenant — fail-closed, consistent with the route dispatch guard.
2. `tenant:module:install` must compute the **dependency closure** and activate it, so activating the
   shell activates workflow automatically. Without this, step 1 breaks the live tenant immediately:
   the shell would lose its workflow capability.

## Why this is filed rather than fixed

This is an **L4** under the autonomy policy on two counts: it changes when capabilities are reachable
(a capability-contract change), and the live tenant would break between steps 1 and 2 unless the
closure work lands first. It also requires a product decision the chair should not take alone —
whether activation is intended as an **entitlement boundary** (then enforce it) or merely as a
**provisioning record** (then document that, and stop presenting it as control).

If the former, the fix is two coupled changes and needs its own contract and regression test. If the
latter, the honest fix is to relabel the surface and record why activation does not gate execution.

**Interim position:** do not treat activation state as a security control in any review, test, or
claim until this is resolved. That matters for P4 (module lifecycle hardening), which must not be
built on an activation model that does not enforce.
