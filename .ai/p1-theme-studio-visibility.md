# P1 — Theme Studio visibility and route authority (COMPLETE)

**Date:** 2026-09-13 · **Tenant:** 54 · **Status:** complete and verified live

## Why P1 existed

Phase 0 established that Theme Studio was absent from the sidebar while `/cms-akira-theme` returned 200 —
and that the cause was a **declaration narrower than the policy it pointed at**, not an authorization
failure. Phase 0 also found `POST /cms-akira-theme/activate` undeclared in `capabilities.routes`.

## Pre-flight (the repo's own rule: never declare a route without it)

| Check | Result |
|---|---|
| Does the handler already call the capability? | **YES** — `handlers.php:132` and `:285` both call `akira.theme.activate@1`. Declaring mirrors an existing check; it does not add a new gate |
| Does an active policy row permit it? | **YES** — `roles=admin,administrator,superadmin`, `caller_module=cms-akira-theme`, `is_active=1` |
| What do neighbouring nav items declare? | shell admin items use `admin,administrator,superadmin` — the same set as the policy row |
| How does the gate compare? | `kernelContributionRoleAllowed()` → strict `in_array($role, $roles, true)` |

All four green. The fix could not narrow access: it aligns a declaration with an existing grant.

## The two changes (one file)

`modules/cms-akira/cms-akira-theme/module.json`

1. **`admin_contributions[0].roles`** — `["admin"]` → `["admin","administrator","superadmin"]`
   Now matches the active policy row for the capability the link leads to.
2. **`capabilities.routes`** — added `"POST /cms-akira-theme/activate": "akira.theme.activate@1"`
   Closes verified route-authority debt.

## Verification (live, not asserted)

| Evidence | Result |
|---|---|
| JSON valid | ✓ |
| `php ikabud module:validate cms-akira-theme` | ✓ all checks passed |
| **Sidebar destinations for `administrator`** | **9 → 10** — Theme Studio appears at position 2 |
| `/cms-akira-theme` | HTTP **200**, "CMS Akira Theme Studio" |
| **Route now enforced** | `route.authority.denied` with **`state:"declared"`**, `capability_id: akira.theme.activate@1`, HTTP **403** |
| Denial point | At the guard, **before** the handler body — the declaration test's "later failed declaration" scenario did not occur |
| **Nothing mutated** | `active_theme_slug` still `"akira-editorial"` after the denied POST |
| Site health | `/` 200 · `/posts` 200 · `/cms-akira-shell` 403 unauthenticated (correct fail-closed) |
| error.log | unchanged; the single line remains this session's own probe error |

The runtime proof used the non-mutating method: session cookie only (no JWT), valid CSRF token. The actor
is absent, so the guard denies and the handler never runs — proving the declaration is live rather than
merely present in the manifest.

## What P1 deliberately did NOT do

- No theme **install** capability. Phase 0 confirmed none exists; admission is kernel control-plane work
  (P4) requiring a signed-package model that does not yet exist.
- No change to the 1463 inactive policy rows or the policy-churn mechanism.
- No fix to the provenance gap below (separate slice).

---

## Corrected finding — the provenance gap is smaller than first reported

Phase 0 reported "10 of 132 audit rows (7.6%) have no actor". Reading the schema properly
(`actor_source` and `actor_module_user_id` exist) splits that into two different things:

| Group | Rows | Reality |
|---|---|---|
| `actor_source = 'cli:admin'` | **3** | **Correctly attributed, not a gap.** The actor is identified by `actor_source` plus `actor_module_user_id` (1, 1, 900054). CLI simply has no kernel session user |
| `actor_source = NULL` | **7** | **Genuinely anonymous** — all three actor columns empty |

**Corrected figure: 7 of 132 = 5.3% genuinely anonymous**, not 7.6%.

The seven, with their actions:

| Action | Rows | Module | When |
|---|---|---|---|
| `akira.builder.create` | 3 | cms-akira-builder | 2026-09-10 16:46–16:52 |
| `akira.theme.activate` | 2 | cms-akira-theme | 2026-09-09 17:56, 18:03 |
| **`akira.post.publish`** | **1** | cms-akira-core | 2026-09-12 22:43:15 |
| **`akira.post.create`** | **1** | cms-akira-core | 2026-09-12 22:43:15 |

The finding survives and remains serious — **a publish operation recording no actor at all** is a direct
contradiction of "provable history". But the magnitude was overstated, and the CLI rows were not a
defect. Note `post.create` and `post.publish` share a timestamp: one operation producing two
unattributed rows.

**This is the third time this session a headline number I produced was wrong on closer reading.** The
pattern is consistent: I measure something real, then state its size without checking how the system
defines the thing I am counting.
