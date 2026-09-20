# Slice 3 — the authority duplication census

Status: DEFINED from reconnaissance (2026-09-20). Probe not yet written. This file exists so the
obligation is recorded rather than carried in the chair's head — work that is never written down cannot
be seen by the harness, by the duty, or by cron. That is the failure this repo has been fixing all day.

## The problem, in one sentence

A capability's authority is expressed in **four places that must agree**, nothing reconciles them, and
the programme has recorded nine separate instances of "a declaration does not control what it appears to
control".

## The four sources, located (not assumed)

| # | Source | File / store | What it expresses |
|---|---|---|---|
| 1 | `capabilities.exposes[]` | `modules/*/module.json` | which capabilities exist, plus mutation metadata (`requires_protocol`, `effects.invalidates`) |
| 2 | `capabilities.routes` | `modules/*/module.json` | route → capability map, e.g. `"POST /api/v1/cms-akira/posts" => "akira.post.create@1"` |
| 3 | declared policy rows | `kernel/Services/ModuleInstallService.php` (~line 70-90, feeding `seedPolicy()` at `:90`) | the role set the code intends per capability |
| 4 | the policy store | `capability_authorization_policies` (versioned; one active row per capability) | what the system actually permits on a tenant |

## Proof the divergence is real — the census's first catch, already in hand

Decision 115, measured on tenant 54:

```
capability    : akira.shell.admin_page@1
policy_version: 30   (= the store's ACTIVE version)
widened field : caller_module
STORED / DECLARED roles: admin,administrator,superadmin   (identical)
```

The shipped declaration asks for a broader **`caller_module`** than the live active row grants. The
kernel correctly refuses it, so the code can never obtain the authority it declares — and until today it
was one indistinguishable warning among nine routine ones. **A census that compared source 1/2/3 against
source 4 would have surfaced this without anyone reading a log.**

## What the census must do

For every capability reachable from the manifests, report divergences between the four sources:

1. **declared-but-absent** — a capability in `exposes` with no policy row (or no version at all) in the store.
2. **route-unmapped** — a route in `capabilities.routes` naming a capability that is not in `exposes`.
3. **capability-unrouted** — a capability in `exposes` that no route reaches (write capabilities are the
   interesting case; read capabilities legitimately have no route).
4. **authority-divergent** — the declared role set / `caller_module` differs from the store's **active**
   row, in either direction, with the direction named. Widening is refusal-by-design; narrowing is an
   operator decision. They are different findings and must not be merged.
5. **superseded-declaration** — the declaration is compared against a version that is not the active one
   (the defect fixed in `beace5c`): the seed is inert bookkeeping and the reported divergence at that
   version is not a live divergence.

## Acceptance shape (for the probe, next)

- The census is **read-only**: it opens no transaction, writes no policy row, changes no authority. It is
  a report, so it is safe to run anywhere.
- On tenant 54 it must report `akira.shell.admin_page@1` as `authority-divergent` on `caller_module`
  against the active version — the known-good positive case, so a silent census fails.
- A capability whose declaration matches the active row must NOT be reported. A census that flags
  everything is the same defect as a warning that is always red.
- It must state the **counts per finding class**, so divergence is measurable over time rather than a
  boolean.

## Why this is in the chair's authority

It only *reports*. Correcting a divergence changes what the system permits — widening the store is
authority, narrowing the declaration is intent — so those corrections stay L4/director. The census makes
them visible and specific; it decides nothing.
