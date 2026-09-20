# Akira CMS — master execution plan

**Owner:** director · **Chair:** this session · **Last updated:** 2026-09-16
**Contract status:** `APPROVED` — completion authority delegated to the Chair.
**Loop rule:** each phase is delegated with a written contract, then verified by the chair against
evidence before the next is dispatched. Nothing is marked complete without a live measurement.

**Continuation mandate (normative).** The absence of an explicit next instruction is **not** permission
to stop. While `unsatisfied_obligations > 0` and no contract blocker exists, this plan drives the next
bounded action. Ambiguity is resolved by the Chair and recorded (provenance, not interruption);
only contract invalidation escalates to the owner. The deterministic stop invariant and the enumerated
`stop_reason` values live in `.github/instructions/ai-autonomy-escalation.instructions.md`.

```
IF   contract.status == APPROVED
AND  unsatisfied_obligations > 0
AND  no_contract_blocker
THEN state != IDLE
```

---

## Status board

| Phase | Scope | Contract | Status | Verified by |
|---|---|---|---|---|
| **P0** | Deployed-state reconciliation | — | ✅ **COMPLETE** | 7 measurement passes, all acceptance criteria passed |
| **P1** | Theme Studio visibility + route authority | — | ✅ **COMPLETE** | nav 9→10, route `state:"declared"`, no mutation |
| **P1.1** | Audit provenance defect | — | ✅ **COMPLETE** | `actor_source` recorded, warning logged, probe cleaned |
| **P1.2** | Tenancy invariant ADR | ADR-002 | ✅ **RATIFIED** | source instruction corrected |
| **P1.3** | Activation enforcement (B1) | `.ai/p1.3-activation-enforcement.contract.md` | ✅ **COMPLETE** | tri-state gate: active→allow, inactive→**refuse** `module_not_activated`, unresolved→**allow** (fail-safe). Live tenant fully intact (18 routes 200), suite **118/0/33**, shell 116/0 |
| **P2.1** | Navigation operator surface | `.ai/p2.1-navigation-surface.contract.md` | ✅ **COMPLETE** | nav 10→11, `/cms-akira-navigation` 200, route declared, audit actor=1 |
| **P2.2** | Redirects / URL lifecycle | — | ⛔ **RE-SCOPED** — post slugs are immutable, so arbitrary-path redirects are dead work | see `.ai/finding-slugs-immutable.md` |
| **P2.2a** | Post-path redirects | `.ai/p2.2a-post-redirects.contract.md` | ✅ **COMPLETE** | `/cms-akira-shell/redirects` **200**; open-redirect guard chair-verified live (3 targets → **422**, 0 rows written); targeted tests 31/0 + 24/0; 404 path unchanged; migration applied via governed CLI |
| **P2.3** | SEO editing surface | `.ai/p2.3-seo-surface.contract.md` | ✅ **COMPLETE** | nav 11→12, `/cms-akira-seo` 200, idempotent, audit actor=1 |
| **P2.4** | Search operations console | `.ai/p2.4-search-console.contract.md` | ✅ **COMPLETE** | `/cms-akira-shell/search` **200**; index rebuilt 0→3 docs; `q=Akira` returns a real row while a control term returns no-results; shell 116/0; **found `akira.search.query@1` had NO policy row (fail-open)** and governed it |
| **P3.1** | Settings surface | — | ✅ **SUPERSEDED** | see the later P3.1 row below — Settings surface as scoped here was replaced by Akira site settings (COMPLETE (thin) + P3.1b). Marked 2026-09-20: a top-to-bottom read of this board reported it as `queued` work that had already shipped. |
| **P3.2** | Approval Inbox | — | ✅ **SUPERSEDED** | see the later P3.2 row below — shipped as the workflow / approvals console, COMPLETE |
| **P3.3** | Users / session revocation | — | ✅ **SUPERSEDED** | see the later P3.3 row below — COMPLETE, CD-53 and CD-54 |
| **P4.0** | Theme customizer actually controls the theme | `.ai/p4.0-theme-customizer.contract.md` | ✅ **COMPLETE** | controls 2→23, live `--color-primary` changed `#005c55`→`#d4145a` and restored, shell contract 116/0 |
| **P4.1** | Theme package admission | — | ⛔ **BLOCKED** | needs trust/signature model (L4) |
| **P4.2** | Module lifecycle hardening | — | ⬜ **QUEUED — unblocked** | **Corrected 2026-09-20.** The recorded blocker was "depends on P1.3 resolution", and **P1.3 is ✅ COMPLETE** (see above). The row was stale, which made an unblocked, unclaimed obligation invisible to a top-to-bottom read. Found by `php tools/chair.php continue-check`, which read this table and named it. |
| **P4.3** | Extension trust / capability diff | — | ⬜ queued | — |
| **P5.1** | Authority / reconciliation console | `.ai/p5.1-authority-surface.contract.md` | ✅ **COMPLETE** | nav 12→13, `/cms-akira-shell/authority` 200, found 2 real gaps |
| **P5.1a** | Declare the 2 routes P5.1 found undeclared | — | ✅ **COMPLETE** | undeclared POSTs theme 0 / shell 0 |
| **P5.2** | Provenance surface | `.ai/p5.2-provenance-surface.contract.md` | ✅ **COMPLETE** | nav 13→14, `/cms-akira-shell/provenance` 200, unattributed count 7 |
| **P5.2-fix** | Restore shell table-free contract | `.ai/p5.2fix-provenance-capability.contract.md` | ✅ **COMPLETE** | `kernel.provenance.list@1` registered; shell SQL-free; shell contract 116/0; author 403 |
| **P4.2a-r1** | Modules manager view | `.ai/p4.2a-module-manager-view.contract.md` | ⛔ **BLOCKED (correct return)** | module-owned capability cannot reach kernel-owned install control tables; fully reverted by Sol, cleanup chair-verified |
| **P4.2a-r2** | Modules manager view | `.ai/p4.2a-r2-module-manager-view.contract.md` | ✅ **COMPLETE** | `/cms-akira-shell/modules` **200** listing all 15 suite members; rendered state matches store exactly (8 enabled); shell contract 116/0; CD-001 escalation is one seam, 4 lines, migrations outside it |
| **P3.1** | Akira site settings surface | `.ai/p3.1-akira-settings.contract.md` | ✅ **COMPLETE (thin)** | vertical proven: `public_posts_archive` changes rendered behaviour **200 → 404**, page reads store, fail-closed 422, shell 116/0. **Only 1 setting shipped** — see P3.1b |
| **P3.2** | Workflow / approvals console | `.ai/p3.2-workflow-console.contract.md` | ✅ **COMPLETE** | `/cms-akira-shell/workflow` **200**; 3 real entities rendered; runs column truthfully "No runs recorded"; shell 116/0, console test 20/0; policy row + contribution roles match exactly |
| **P3.1b** | More site settings on the proven pattern | `.ai/p3.1b-more-settings.contract.md` | ✅ **COMPLETE** | 3 settings added, each with a named render-time consumer: `public_post_single` `handlers.php:73-76`, `public_archive_page_size` `:37-41`, `public_archive_sort` `:38-42`. The orphan `maintenance_mode` constant (no consumer, no defaults entry, no validation) was **reverted** — it was the exact defect this slice forbade. `composer test` 154 files: 121 passed / 33 skipped / **0 failed**. Live proof: page size 12→6 story links, 1→2, restored→6. Reject list documented (theme-presentation items, `rss_enabled`/`sitemap_enabled`/`comments_enabled` — no subsystem, `page_cache_ttl` — kernel-owned). |
| **P6a** | Backup and export console | `.ai/p6a-backup-export-console.contract.md` | ✅ **COMPLETE** | `/cms-akira-shell/backups` **200**; real artifact on disk (3756 B SQL dump, verified independently); export returns 3 real records; `ModuleDataResetService` unreachable; shell 116/0 |
| **P6b** | Full-suite regression fix | — | ✅ **COMPLETE** | `read_authority_probe_test` red since P5.1 (our 7 new GET declarations vs a stale exact-set expectation); invariant re-verified for all 7, comparison made order-independent → **suite 117/33/0** |
| **P3.3** | Users / session revocation | `.ai/p3.3-revoke-sessions.contract.md` | ✅ **COMPLETE** | CD-53 (24/0 unit, shell 116/0, policy row at the exact `set_active` tier) + CD-54 (the capability 403'd every operator; found by opening a browser, fixed and verified) |
| **P6c** | `sitemap.xml` + `robots.txt` | — | ✅ **COMPLETE** | CD-56: 200 + correct `Content-Type`, 15 `<loc>`, 39/0 tests. Two findings recorded, one of them fixed by the next row |
| **D-shell** | Theme Studio into the shared shell + shell performance | — | ✅ **COMPLETE** | CD-58/59: shell-owned `/cms-akira-theme` (no policy widening needed); **1.572s → 0.390s per GET (−75%)**, 43 SELECTs and 43 prepared statements removed per request |
| **P0-cache** | A cached response replays its own `Content-Type` | `.ai/page-cache-content-type.contract.md` | ✅ **COMPLETE** | CD-61: `pageCacheResolveContentType()` + pure `pageCacheServeHeaders()`; test **11/0/0**; live red→green probe (same ETag, `text/html` → `application/xml`); legacy entries and untyped non-HTML bodies both fail safe |
| **Harness** | Objective preservation — meta-work is subordinate to the plan | `.ai/objective-preservation.contract.md` | ✅ **COMPLETE** | CD-60/61: normative section **+31/−0**, Chair remit **+5/−0**, guard test **14/14** including the five absolute prohibitions byte-identical |
| **Builder spec** | `akira-builder-admin.spec.ts` against the real UI | — | ✅ **COMPLETE** | rewritten 2026-09-14; the browser suite is 17 tests / 7 files and all 17 passed with 0 skipped (CD-59) |

---

## Remaining obligations (drives the stop invariant)

`unsatisfied_obligations` is this list. It is not empty, so the harness is **not** permitted to idle.

| # | Obligation | Blocked by |
|---|---|---|
| ~~1~~ | ~~P3.3 users / session revocation~~ | ✅ **COMPLETE** 2026-09-15 — CD-53 (verified) and CD-54 (the capability it shipped **403'd every operator**; found by opening a browser, fixed, verified) |
| ~~2~~ | ~~**P6 recovery / export, production floor**~~ | ✅ **COMPLETE** 2026-09-20 — commit `aae747c`. Chair-authored probe went red-baseline (0/9) → 16/0, falsified (revert → RED, restore byte-identical). Verified live on tenant 54: a dry run leaves posts **38 → 38** with the only bundle audit written being `akira.bundle.diff`; a replayed apply writes nothing (**38 → 38**) twice with the same idempotency key; a removal plan is refused **409** with the entries named; both capabilities governed admin-tier; `error.log` **0 bytes**. Criterion 8 (unauthorized role) NOT exercised — stated, not implied. |
| ~~3~~ | ~~Rewrite `tests/browser/akira-builder-admin.spec.ts`~~ | ✅ **COMPLETE** — rewritten 2026-09-14 against the real UI; the journey passed inside the CD-59 full-suite run (**17 tests / 7 files, 17 passed / 0 failed / 0 skipped**) |
| ~~4~~ | ~~P4.2b module install lifecycle~~ | ✅ **COMPLETE** 2026-09-20. The view shipped as P4.2a-r2; the state machine is `kernel/Services/ModuleInstallService.php`, exposing `install()` / `uninstall()` / `suiteState()` / `setEnabled()`, and the CLI drives it (`tenant:module:install`, `tenant:module:uninstall` (data-preserving), `module:list|enable|disable|uninstall`). Verified here rather than assumed: `golden_module_lifecycle_test` **PASS (33 checks)**, `module_install_activation_test` **23 passed / 0 failed**, `module_manager_capability_test` **5 passed / 0 failed**. NOT claimed: packaging/trust, which is P4.1 and director-owned. |
| 5 | P4.3 extension trust / capability diff | partially P4.1 |
| 6 | P4.1 theme package admission | **director**: trust basis (signed / deployment-approved / operator-vetted) |
| 7 | **Deliver the verified tree** (commit) | **director** — 24 blocking runs; closing them needs a verifier change (HARPP decision 103, PENDING). Per CD-60d this does **not** suspend the plan |

**New obligation, 2026-09-14 — the builder browser spec.** HARPP decision 95 (option A) was approved
and applied: `cms-akira-builder` now accepts the canonical admin tier in all three of its guards
(`helpers.php:153`, `helpers.php:39`), and the live page no longer renders *"Error: Administrator role
required."* That resolves the **product** defect.

It does **not** reach 9 passed, because `akira-builder-admin.spec.ts` carries four independent
impossibilities, all introduced by the same commit (`a6c76f5`, Phase 10B) — the spec was written
against an imagined UI:

1. ✅ the role guard (above) — a real product defect, now fixed;
2. ✅ it asserted `"Composition editor"` on the **list** route, but `app.tsx:52` selects the panel from
   `boot.mode` and that string exists only in `EditPanel` (`app.tsx:435`) — unsatisfiable;
3. ✅ the suite's **only relative `page.goto()`**; `baseURL` resolves to `APP_URL`, which `.env` sets to
   the **kernel** host, so it 404'd — every other spec navigates absolutely;
4. ⬜ it clicks `button:has-text("+ heading")`, but **no heading block exists** — the theme exposes only
   `hero/richtext/card-grid/quote/cta`, and the button renders `+ Add <label>` (`app.tsx:497`).

The spec has never executed past line 79, so everything after it (save draft, preview, validate,
publish, `.ab-pill-pub`) is likewise unverified. **It needs rewriting against the real UI under its own
contract** — a Test-Writer job, not a line-by-line patch. Patching incrementally would amount to
authoring the test, which is a different role.

**Cleared 2026-09-14** (verified complete, removed from this list): P2.2a post-path redirects,
P2.4 search operations, P3.1 settings (thin), **P3.1b** additional settings, P3.2 approval inbox.

Obligations 6 and 7 are **contract blockers**, not Chair decisions: no amount of Chair authority can
authorise an unapproved trust model for accepting executable theme/module packages. Everything else
proceeds without owner intervention.

---

## Chair decisions (provenance, not interruption)

Recorded here so the owner can inspect decisions after the fact. None of these required owner
intervention; they were resolved from the contract, ADRs and prior decisions.

---

**CHAIR DECISION CD-001** · 2026-09-14
**Issue:** `cms-akira-core` cannot wrap `ModuleInstallService` — that service writes kernel-owned
control tables (`kernel_tenants`, install generations, activation state), and `KernelPDO.php:88-100`
blocks a module from requesting kernel escalation.
**Options:**
1. Narrow Kernel-owned escalation inside `ModuleInstallService` for its **own** control-table writes only.
2. A new kernel-owned trusted-service seam for module use.
3. Make `cms-akira-core` co-owner of tenancy/install control tables.
**Chosen:** **1**
**Reason:** Option 3 is rejected outright — it would grant a module full CRUD over tenancy and install
control tables and weaken isolation (Sol had already refused it). Option 1 is the smallest change that
preserves the invariant and reuses the existing `KernelPDO::kernelEscalationEnter/Leave` primitive
already used at `kernel/App.php:216` and by `kernel.provenance.list@1`. Option 2 adds a new seam for no
additional benefit.
**Guardrails written into the contract:** escalation is bounded to the service's own control-table
writes; it must not cover module migration callbacks; no module becomes a co-owner of kernel tables;
`kernel/App.php`, `kernel/Contracts/` and `kernel/Database/` are in forbidden scope.
**Authority:** project contract (this plan) + `.ai/p4.2a-r2-module-manager-view.contract.md`
**Owner intervention: not required.**

Related: the r1 `BLOCKED` return is **not** a failure. Sol refused the unsafe option, reverted fully,
restored tenant 54, and escalated with a recommendation — the escalation protocol working as designed.

---

**CHAIR DECISION CD-002** · 2026-09-14
**Issue:** the P1.3 implementation modified `src/helpers/module-registry.php` (+117) and
`src/helpers/module-manager.php` (+5), neither of which the contract listed. Scope listed
`kernel/Capabilities/` but omitted `src/helpers/`, where module activation state actually lives.
**Options:** (1) ratify — the paths are additive and serve the contract objective; (2) revert and
re-issue a corrected contract; (3) escalate as a contract breach.
**Chosen:** **1** — ratify.
**Reason:** the contract's objective is an activation gate on capability dispatch; the tri-state
resolver is a necessary component of it, and `src/helpers/module-registry.php` is its correct home
(that is where module discovery/activation state is read). Changes are **purely additive** — 122
insertions, 0 deletions — and touch no forbidden path (`migrations/`, `storage/cms-themes/`,
`modules/gui-settings/`, `phpstan-baseline.neon` all untouched; `kernel/App.php` mtime unchanged).
No security is weakened. The omission is a **chair authoring error**, not a breach by the implementer.
Option 2 would burn a full run to correct my own listing. Option 3 would escalate my error to the owner.
**Follow-up:** future contracts must state “if a required path is not listed, report it rather than
silently expanding scope” — the implementer should have flagged this even though the change was sound.
**Authority:** project contract (this plan) + `.ai/p1.3-activation-enforcement.contract.md`
**Owner intervention: not required.**

---

**CORRECTION to the defect description (found by Phase 1 measurement).** The programme described
P1.3 as *“activation declares an entitlement that capability dispatch ignores”*. **That was wrong.**
`CapabilityBus::applyPolicy()` **already consulted** activation at HEAD. The real defects were:
(a) the boolean `moduleIsActive` **conflated *unresolved* with *inactive*** — fail-catastrophic;
(b) it read the **ambient** tenant rather than a resolved one; (c) refusals were logged as
`caller_policy`, making an activation refusal indistinguishable from a policy refusal.
So the slice **hardened and corrected** enforcement rather than adding a missing check. Recording this
because the wrong description would have sent a future reader looking for absent code.

---

## Completed — with the evidence that proves it

### P0 · Deployed-state reconciliation ✅
Seven measurement passes. Every row `verified (evidence)` or `unverified`.
**Corrected three of the chair's own claims** (`tenant_id` exists 12/12; nav is 9 not 14; theme install
does not exist).
Report: `.ai/phase0-reconciliation-report.md`

### P1 · Theme Studio visibility ✅
`roles: ["admin"]` → `["admin","administrator","superadmin"]`; `POST /cms-akira-theme/activate` declared.
**Evidence:** sidebar 9 → 10; `route.authority.denied` with `state:"declared"`; `active_theme_slug`
unchanged after the denied POST.
Record: `.ai/p1-theme-studio-visibility.md`

### P1.1 · Audit provenance ✅
Root cause: `kernel.audit.record@1` wrote NULL to all three actor columns when no user was present.
Now records `cli:unattributed` / `unauthenticated` **and logs a warning**.
**Evidence:** row persisted with `actor_source='cli:unattributed'`; warning present in `app.log`; probe
row deleted; PHPStan clean.

### P1.2 · Tenancy invariant ✅
**ADR-002 RATIFIED** — dedicated database is the isolation boundary; `tenant_id` is mandatory
defence-in-depth and leads every composite unique key. Also corrected the source instruction that
caused the error (`.github/instructions/verification-harness.instructions.md`), which had claimed
"tenant tables have no `tenant_id`" and propagated into a brief and two model answers.

### P5.1 · Authority surface ✅ (+ P5.1a)
The **Akira-required** surface both reviewers named: makes authority legible and turns declaration-vs-
policy disagreement from a silent condition into a visible one.

**Owned by `cms-akira-shell`** at `/cms-akira-shell/authority`. Read-only — no mutation, no schema
change. Chair-verified: **nav 12 → 13**, page **200** authenticated, contribution roles
`admin,administrator,superadmin`, residue clean (`menus=0`, `seo_metadata=0`). Tests: authority 8/0,
shell contract 116/0.

**It found two real gaps on its first run — and one was the chair’s own incomplete fix:**

| Module | Undeclared POST route |
|---|---|
| `cms-akira-theme` | `POST /api/v1/cms-akira-theme/themes/{slug}/activate` — **P1 declared the form route but not the JSON API route** |
| `cms-akira-shell` | `POST /cms-akira-shell/posts/{slug}/delete` |

**P5.1a (chair): declared both.** Pre-flight passed on both counts — handlers already call their
capabilities (`catThemeActivateJson` at `handlers.php:16`; `akiraShellPostDelete` via
`akiraShellMutation`), and both capabilities have active policy rows admitting `administrator` with a
permitting `caller_module`. Verified after: JSON valid, site 200/200, admin 200 including the new
surface, **undeclared POSTs theme 0 / shell 0**.

**Two structural findings the screen surfaced:**
1. **Three sidebar contributions cannot be role-reconciled at all** — Theme Studio, Navigation and SEO
   metadata point at **GET routes with no capability declaration**, so there is nothing to compare
   against. Nav visibility is therefore governed by the contribution’s hand-written roles only. That is
   the enabling condition for defect class #2, and it is now visible.
2. Only **51 active policy rows** out of 1514 exist, which is what the console displays by design.

**Honest note:** the screen reports **no live role-set mismatch today** — Sol said so rather than
fabricating one, which is exactly the behaviour the contract demanded.

### P2.3 · SEO operator surface ✅
Delegated with the P2.1 contract template plus three new standing rules (probe columns, clean up
residue, never delete audit rows). **Sol's report verified accurately on every point checked** — the
first delegation with no correction needed.

**Delivered:** SEO module `handlers.php`, `helpers.php`, `module.json`, `routes.php`, `templates/`,
`tests/`. Two contributions: the pre-existing dashboard widget **and** a new sidebar entry.

**Chair-verified:**
| Check | Result |
|---|---|
| Routes declared | ✅ `POST /cms-akira-seo/upsert`, `POST /cms-akira-seo/delete` |
| Declaration test | ✅ `handlers.php:135` (upsert), `handlers.php:174` (delete) |
| Contribution role sets | ✅ both `admin,administrator,superadmin` — matches policy |
| **Sidebar** | ✅ **11 → 12**, `/cms-akira-seo` present |
| `/cms-akira-seo` | ✅ **200** authenticated |
| Idempotency | ✅ two identical POSTs → metadata `1`, audits `1` |
| Audit provenance | ✅ row #2675 `actor_user_id=1` |
| Residue | ✅ `cms_akira_seo_metadata` = 0 rows; audit retained |
| Tests | ✅ SEO surface 12/0 · route-authority 29/0 · PHPStan clean |

**Sol disclosed a harness trap worth keeping:** `php ikabud module:validate <module>` without a host
context resolves base scope and reports false dependency failures — it needs `HTTP_HOST=akiracms.test`.

### P2.1 · Navigation operator surface ✅
Delegated to Codex Sol with a contract that forced a **pre-flight before code**. Sol ran it, found two
blockers, and **stopped** — the correct behaviour — which surfaced a fifth instance of the mismatch
pattern and prevented a broken build.

**Chair corrections to the contract after round 1:** the declaration test applies to *new* handlers
(satisfied by construction), and `canNavigationActor()` alignment was added to scope.

**Delivered:** `handlers.php`, `helpers.php`, `module.json`, `routes.php`, `templates/admin.disyl`,
`tests/navigation_surface_test.php` (8 passed).

**Chair-verified, not taken on report:**
| Check | Result |
|---|---|
| Route declared | ✅ `POST /cms-akira-navigation/menu/create` → `akira.navigation.menu.create@1` |
| Declaration test | ✅ `handlers.php:117` calls the capability |
| Contribution roles | ✅ `admin,administrator,superadmin` — matches the policy row |
| **Sidebar for `administrator`** | ✅ **10 → 11**, `/cms-akira-navigation` present |
| `/cms-akira-navigation` | ✅ **200** authenticated |
| Audit provenance | ✅ row #2674 `actor=1`, `source=kernel` — the P1.1 fix working in practice |
| Site health | ✅ 200/200 |

**Sol improved on the contract:** it aligned the role set *and* added the repository’s documented
superadmin rule (`role === 'superadmin'` **and** `source === 'kernel'`), with a now-truthful error
message: *"Admin, administrator, or kernel superadmin role required."*

**Chair cleanup:** Sol left 1 verification menu in live tenant data (`evidence-primary`). Removed the
menu; **kept audit row #2674 deliberately** — deleting it would tamper with the provenance record.

**Chair’s own error, recorded:** 3 new fatal errors in `error.log` were **mine** — I queried
`SELECT id, menu_key, name FROM cms_akira_menus` and the table has `tenant_id, menu_key, location,
created_at`. That is the **fourth time this session** I guessed a column instead of probing. The
repo’s own rule exists for this and I keep violating it.

**ADR-002 RATIFIED** — dedicated database is the isolation boundary; `tenant_id` is mandatory
defence-in-depth and leads every composite unique key.
Also corrected the source instruction that caused the error (`.github/instructions/verification-harness.instructions.md`).

---

## The single pattern this project keeps producing

Four defects, one shape: **a declaration that does not control what it appears to control.**

| # | Declaration | Reality |
|---|---|---|
| 1 | `theme:validate` passes | activation rejects the same theme |
| 2 | nav declares `["admin"]` | policy admits `administrator` — a working feature invisible |
| 3 | tenant activation declares entitlement | capability dispatch ignores it — a non-activated module executes |
| 4 | `canNavigationActor()` says "Administrator role required" | refuses `administrator` |

**This is the highest-value thing the plan has produced.** Any future work should ask: *does this
declaration actually govern the thing it names?* Four independent instances is a systemic property, not
coincidence.

---

## Blocked on director decisions (2)

### B1 · Is tenant activation an entitlement boundary, or a provisioning record?
- If **entitlement** → enforce at capability dispatch **and** make dependency closure activation-aware
  (both changes, in that order, or the live tenant breaks).
- If **provisioning only** → relabel the surface and record why it does not gate execution.
Interim rule already in force: **do not treat activation as a security control** in any review or claim.
Filed: `.ai/finding-activation-not-enforced.md`

### B2 · Is the tenancy topology dedicated-database permanently?
ADR-002 ratifies the *invariant* (both, simultaneously) and preserves portability. It does **not**
decide whether new tenants must be dedicated. Explicitly left open.

---

## Phase definitions (what "done" means for each)

**P2 · Content spine** — every journey completable by an operator over HTTP, no developer, no CLI.

Scoped by measurement (2026-09-13), which changes the effort per slice substantially:

| Slice | Provider exists? | Activated for 54? | Real shape |
|---|---|---|---|
| P2.1 Navigation | ✅ 9 caps | ✅ | **UI only** — in flight |
| P2.2 Redirects | ❌ **none** | n/a | **Capability design first**, then UI — the reference has `redirects`; Akira has no provider at all |
| P2.3 SEO | ✅ `get`, `meta.build`, `content_health`, `upsert`, `delete` | ✅ | **UI only** — same shape as P2.1 |
| P2.4 Search | ✅ `document.build`, `upsert`, `delete`, `query`, `rebuild` | ❌ **not activated** | UI is straightforward, but the module is not activated — building a surface for a non-activated module is questionable until **B1** is resolved |

**Consequence:** P2.2 is larger than the roadmap assumed (it is capability work, not a screen), and P2.4
is entangled with the activation question. P2.3 is the clean next slice after P2.1.

**P3 · Operator essentials** — Settings (site identity, permalink, timezone; **no** authority-relevant
keys — those are policy rows) · Approval Inbox (cross-content review queue, not per-record) · Users +
session revocation.

**P4 · Kernel lifecycle** — theme package admission with a trust model · module lifecycle with
activation-aware closure · extension trust surface showing capability diff. Two of three blocked.

**P5 · What only a governed CMS can offer** — authority/denial console explaining actor, grant,
excluded capability, required actor, policy revision · per-change provenance surface.
Must not precede P1.1 (already done) or P3.

**P6 · Production floor** — governed export/import bundles (dry-run diff + audited apply), recovery,
backup proof.

---

## Refusals (converged, both models independent)

Marketplace · unsigned uploads · PHP/SQL in themes · theme source editor · WP-style ambient hooks ·
child themes · per-route overrides · second builder · HTML-as-source · client-authoritative render ·
generic Widgets · AI at launch · autonomous AI publishing · generic reports suite · comments ·
general import framework · commerce · broad analytics · separate Pages implementation · generic Tools
menu · checkbox Permissions grid competing with the policy row · tenant-shell module installer.

---

## Open questions carried from P0 (not decisions, investigations)

1. `canonical_domain` is empty for tenant 54 while host routing works — what actually resolves the host?
2. 1463 inactive policy rows in 5 days — is the churn intended?
3. `akira-ark-demo` declares `name = "akira-ark"` — which package is authoritative?
4. Deployed tree is **dirty** (9 files) — the deployed commit is not a reproducible artifact.

## Working agreements learned (so they are not relearned)

- Verify the harness before believing a finding — 3 false alarms this session came from my own probes.
- Measure with the system's own definition of the thing being counted — 3 headline numbers were wrong
  because I counted a plausible proxy instead.
- `php -l` on `.disyl` is meaningless; use `_lint_disyl.php`.
- `php scripts/run-tests.php <file>` ignores the path and runs the whole suite, unlinking
  `storage/modules.json` and poisoning web APCu.
- In CLI without a host, `app()->db()` is the **base** DB, not the tenant's.
