# Akira CMS Completeness — Answers to Q1–Q6

**Evidence base.** I read the Akira suite manifests/handlers (`modules/cms-akira/*`), the kernel contribution registry (`src/helpers/module-manager.php`), the kernel control-plane admin (`src/http/core-routes.php`, `templates/pages/admin-modules.disyl`), the reference CMS (`/var/www/html/applicationostest/modules/cms`), and the repo's own ADRs/plans (`docs/architecture/cms-akira-identity-adr.md`, `docs/architecture/cms-akira-extensibility-adr.md`, `docs/architecture/akira-beyond-the-cms.md`, `docs/kernel/cms-akira-reference-cms-adoption-roadmap.md`, `.ai/current-task.md`, `.ai/akira-completion-plan.md`). I also fetched admin-structure docs for Craft, Statamic, Strapi and Ghost (Drupal's site served a JS challenge, so Drupal below is general knowledge).

**Two corrections before answering.**

1. **The brief's parity framing is one revision stale.** The identity ADR (2026-09-09) says *"no feature for parity alone."* The newer `docs/architecture/akira-beyond-the-cms.md` (2026-09-13) refines it explicitly: *"parity is no longer refused on principle. The refused category is undemonstrative breadth… Table stakes are a different matter, and refusing them was an error."* Storage, editing, media, taxonomy, navigation, search and SEO are now *"allowed and expected."* This matters: the director's complaint is about table stakes, and the current doctrine already concedes the point. The governing rule is now **"a product surface may use only substrate guarantees that actually exist."**

2. **§2's "seven modules with zero UI" is true as *route ownership*, but not as *user surface*.** Only **two** of the seven are genuinely orphaned. Evidence:
   - `cms-akira-editor` — consumed by `cms-akira-builder` (`akira.editor.sanitize@1` in its `depends`) and by core's editorial API. It is an input subsystem, not a destination.
   - `cms-akira-media` — surfaced at `GET/POST /cms-akira-shell/media`; shell `depends` on `akira.media.*@1`.
   - `cms-akira-workflow` — surfaced inside the post form (`POST /cms-akira-shell/posts/{slug}/workflow`).
   - `cms-akira-seo` — consumed by shell (`akira.seo.content_health@1`) **and** declares a dashboard widget contribution.
   - `cms-akira-navigation`, `cms-akira-search` — referenced **only** by themselves and by the `profile-*` manifests. Genuinely orphaned.
   - `cms-akira-ai` — `_enabled:false`, one read-only deterministic capability, no surface. Orphaned by design.
   
   So the real count is **2 orphaned + 1 disabled**, not 7 hidden features. I flag one measurement I cannot reproduce: §2 says 14 sidebar links render, but the Akira manifests declare a maximum of **10** (9 in `cms-akira-shell` `nav` + 1 Theme Studio contribution; 9 render while Theme Studio is filtered). A rendered-HTML scrape of the tenant-54 sidebar would settle whether the extra four are kernel-nav items bleeding into the count.

---

## Q1 — The floor

**Position.** The generic production floor is **eight sections**, justified by what breaks without them. Akira's governance identity adds **two more**, and reclassifies one.

| # | Section | What breaks without it | Generic or Akira-specific |
|---|---|---|---|
| 1 | Auth/session boundary | Nothing is protected; every "permission" is cosmetic | Generic |
| 2 | Content list + create/edit | The product's entire job cannot be done | Generic |
| 3 | Content model (types/fields) | New content shapes require a developer and a schema edit | Generic |
| 4 | Taxonomy | Content cannot be organised, archived, or found | Generic |
| 5 | Media library | Publishing is text-only; no images/files | Generic |
| 6 | Users | Cannot onboard a second person or revoke a leaver | Generic |
| 7 | Authority/permissions | Least privilege is impossible; one shared superuser | Generic |
| 8 | Settings | Site title, URL/permalink, timezone, pagination require DB/code edits | Generic |
| 9 | Navigation/menus | Site menus require a developer; dead links | Generic (table stakes) |
| 10 | Theme activation + basic customization | The site cannot be made presentable or branded without code | Generic (table stakes) |
| 11 | Revision/rollback | A bad edit is unrecoverable | Generic |
| 12 | **Authority-legibility surface** | A governed system whose operator cannot see *who may do what, what was denied and why, which grant exists, which routes are undeclared* is indistinguishable from an ungoverned one | **Akira-specific** |
| 13 | **Provenance surface** (per-change actor/capability/policy) | The kernel's differentiator (audited capability writes) is invisible inside the product | **Akira-specific** |
| 14 | Extension/module lifecycle | New capability providers cannot be added safely | **Akira-specific in *kind*** — it belongs to the **kernel control plane**, not the tenant shell |

**Against the director's three named gaps:**
- *Theme customizer* — **exists and works**, hidden by a role gate. Not a floor gap; a visibility bug.
- *Themes install* — activation/rollback/validate exist; package **install** is an operator/kernel concern (see Q2). Not a tenant-shell floor requirement.
- *Module/extension install* — **already exists** at the kernel control plane: `templates/pages/admin-modules.disyl` posts to `/api/v1/admin/modules/install`, with enable/disable/settings and diagnostics, linked from the kernel nav (`src/helpers/module-manager.php:3659`). The 404 is for `/cms-akira-shell/modules`, which *should* not exist by identity (per-tenant enablement belongs to policy/control plane, not the app shell).

**Industry cross-check (this is the load-bearing finding).** Among the named CMSs, **none treat theme-install or module-install as a tenant-shell admin section**:
- **Craft** (web-confirmed): *"Craft doesn't impose a content model"*; plugins are found/installed/configured in the control panel, themes are not an admin section.
- **Statamic** (web-confirmed): addons install via **Composer**, not an admin screen.
- **Strapi** (web-confirmed): administers Content Manager, Content-Type Builder, Media Library, RBAC, API tokens, SSO, webhooks — and has **no themes at all** (headless/API-first).
- **Ghost** (web-confirmed, general-knowledge detail): themes are filesystem/CLI artifacts; there is **no plugin admin UI** by design.
- **WordPress** (from the brief; general knowledge): the one system that *does* put Appearance→Themes and Plugins in the admin — the shape the director is measuring against.
- **Drupal** (general knowledge; drupal.org blocked me): separates **Structure**, **Appearance** and **Extend** — closer to WordPress but still installs via `/admin/modules`.

**Conclusion:** "themes install + theme customizer + module install" is a *WordPress* admin shape, not an industry floor. A credible CMS needs theme *activation/customization*, extension *lifecycle*, and **navigation + settings + visible authority** — which is a different list from the one the director named.

---

## Q2 / Master section table

Legend: **R** required · **R≠** required-but-different · **X** deliberately excluded from tenant shell. Effort S/M/L. "Kernel" = control plane, not Akira shell.

| Section | Required? | Why | Akira state (measured) | Effort | Dependency |
|---|---|---|---|---|---|
| Dashboard | R | Orientation; safe landing after login | Renders (`/cms-akira-shell`) | S | — |
| Posts / content | R | The core job | Renders; list, editor, preview, revisions, CSRF, idempotency, optimistic concurrency | S | — |
| Content types | R | Model evolution without code | Renders `/content-types` | S | — |
| Categories / taxonomy | R | Organisation, archives | Renders `/categories` | S | — |
| Media | R | Images/files in content | Renders `/media` (shipped 2026-09-12) | S | — |
| Navigation / menus | R | Site menus without a developer | **Headless**: `akira.navigation.*@1` has 9 capabilities, 0 routes; not consumed by shell | M | Read-governance gate |
| Users | R | Onboard/revoke people | Renders `/users`; role + active POST routes | S | — |
| Permissions / authority | R≠ | Authority must be *versioned and revocable*, not a checkbox grid | Renders `/permissions` (`akira.policy.list/set_roles@1`). Must become policy-row versioning with reason + audit (R3) | M | Policy-seed conventions |
| Settings | R | Title/URL/timezone/pagination without DB edits | **404** `/cms-akira-shell/settings`. Reference has 34 `settings_fields`; kernel has `/admin/platform` | M | Read-governance; some settings are policy rows, not a blob |
| Themes (activate/rollback/validate) | R | Presentability, brand, safe swap | **Exists, hidden.** `/cms-akira-theme`; 3 non-API routes; Activate + Rollback in handler | S | **Fix role gate** |
| Customizer | R≠ | Brand without code, declarative (no PHP) | **Exists, hidden.** Declarative schema via `akira.theme.customizer.*@1`; Save + cache invalidation | S | Same role gate |
| Modules (lifecycle) | R (kernel) | Add/remove capability providers safely | **Exists at `/admin/modules`**: zip install, enable/disable, settings, diagnostics | S | Kernel; no tenant-shell entry |
| Extensions (typed contributions) | R≠ | Extend without ambient hooks; show capability diff | Contribution registry exists (`kernelContributionRegistry`); **no capability-diff/trust surface** | M | Extension-point ADR (T3) |
| Page builder / compositions | R≠ | Structured, diffable composition — never HTML-as-source | Composition list + edit + builder API + React scaffold (`/compositions`) | M | Block-schema contract; gated (T4) |
| Redirects | R (table stakes) | Broken URLs after slug changes | Missing (no route) | S–M | Production-floor phase |
| Import / export | R (table stakes) | Migration, backup, dev→prod | Missing. Product thesis calls it **governed bundles** (dry-run diff + audited apply) | M–L | Composition model |
| Report approvals / workflow inbox | R≠ | Human approval in the lifecycle | Workflow module headless (1 API route); transitions available in post form; **no inbox** | M | Workflow + read governance |
| AI automation | R≠ **and gated** | Governed authorship, not a chat box | `cms-akira-ai` disabled; deterministic summariser; no provider SDK. `AIGovernance` is file-based (named defect) | L | **P3 delegation primitive** |
| SEO / Search | R (table stakes) | Discoverability | SEO: headless + dashboard widget declared. Search: headless, 0 routes | M | Read governance; list-screen + public search |
| Authority / denial console | **Akira-required** | "Authority exists" ≠ "authority is understandable" | Workbench exists as a **developer** instrument, not an operator console | L | Census + Phase D |
| Provenance / history surface | **Akira-required** | Prove what published, by whom, under which policy | Audit + revisions exist; no per-change product timeline/proof export | M–L | P4 primitive |
| Module health | R | Operator confidence | Renders `/health` | S | — |
| `react-builder` (reference-only) | **X** | Duplicate of page-builder; Akira has one governed builder | n/a | — | — |
| `weather` (reference-only) | **X** | Product-specific vertical, not a CMS requirement | n/a | — | — |
| Marketplace / plugin store | **X** | Unsigned supply chain; explicit refusal | n/a | — | — |
| Widgets (WP-style) | **X** | Ambient global hooks = the anti-pattern | Contribution points instead | — | — |
| Comments | Not required | Deliberately absent in Akira's model; can be an extension if a consumer appears | n/a | — | — |

**On the reference's 18:** I do **not** accept them as a target. The reference's own `nav` renders only **8 links** — fewer than Akira's 9 rendered sidebar links. Its 18 route prefixes include a duplicate builder (`page-builder` + `react-builder`), a vertical (`weather`), and reference-specific sections (`report-approvals`, `ai-automation`). The defensible target is the union in the table above, minus the X rows.

---

## Q3 — The seven

**Position: (c) surface through other sections as the default; (b) headless as a first-class *installation profile*; (a) a dedicated screen only for `navigation`.**

The test is one question: **is the capability a destination, or an input/derivation/consumer of another screen?** Destinations need nav entries; providers, transformers and read models do not.

- `editor` → **input subsystem**. Renders inside the post editor. (c). Evidence: consumed by builder and core.
- `media` → already **(c) done** inside the shell.
- `workflow` → **(c)** for transitions (in the post form) **plus one destination**: the approval inbox (§Q2 "report-approvals"). A queue is a destination.
- `seo` → **(c)**: fields in the post editor + the already-declared dashboard widget.
- `search` → **(c)**: a search box on list screens and public search. A ranking backend needs no standalone admin page.
- `ai` → **(c)** and **gated**: in-editor suggestion affordances over `akira.ai.summary.suggest@1`, only after the P3 delegation primitive exists. It must not become a screen that implies governance it does not have.
- `navigation` → **(a)**: menus are a genuine destination. No host screen can express menu CRUD, and there is no workflow/SEO screen to attach it to. This is the one module that earns a sidebar entry.

**Why (b) is still legitimate, and even privileged:** the repo already ships `cms-akira-profile-headless`, which installs `core + workflow + search` **without a shell**. Screen-less-by-design is an intended, supported configuration. So the correct framing is not "each module must have a UI" but "each module must have either a host surface or a headless profile, and must not be an unlabelled orphan."

**Reject "build UI for each" (option a as a blanket rule).** It would add seven nav entries, contradict the extensibility ADR's refusal of "a generalized registry without a consumer," and produce `undemonstrative breadth` — the exact failure the doctrine names.

---

## Q4 — The governance angle

**Sections the kernel makes necessary that a conventional CMS does not need:**

1. **Authority / denial console.** Conventional CMSs cannot show *why* an action was denied because authority is ambient. Akira can: actor, grant, excluded capability, required actor, policy revision. `akira-beyond-the-cms.md` promotes this to product item **#2** precisely because *"an instrument that lies is worse than no instrument."*
2. **Per-entity provenance + proof export.** Every write carries a capability id, policy decision, idempotency key, audit row and correlation id. No mainstream CMS can answer *"who authorised this, under which grant"* off-server. This is product items #3–#4.
3. **Capability diff / trust surface at extension lifecycle.** Because modules declare `exposes`/`depends`, install can show *"this version adds 2 capabilities, touches 3 tables"* and revocation degrades to a safe fallback. Plugin stores cannot.
4. **Delegation / agent grants.** A bounded machine actor (draft-only, one type, never publish) is expressible only because authority is a capability + policy row.
5. **Backup/export as governed bundles**, not a zip. Dry-run diff + audited apply is only safe because tenancy/policy/idempotency exist.

**Sections governance makes obsolete or different in kind:**

- **"Permissions" stops being a role matrix.** Per R3, the **policy row is the sole role authority**; the screen becomes an *authority editor* that versions a policy and records a reason, where revocation blocks a write at the bus with zero code. A WordPress-style checkbox grid is obsolete — it would be a second authority source.
- **"Settings" must be narrowed and split.** A generic config blob is obsolete for anything authority-relevant: `role_permissions` is policy, not a setting; publication rules are policy. What remains (title, timezone, pagination, permalinks) must be tenant-scoped and audited. The named defect — `AIGovernance` persisting JSON under `storage/` and gating by role — is the anti-pattern to delete, not extend.
- **"Users" grows into actor identity + grant issuance** (delegation), not just accounts.
- **"Themes" becomes deterministic declarative artifacts + validation**, never a code editor. A theme carrying PHP/SQL/migrations must be rejected at validate.
- **"Plugins/Extensions" install moves to the kernel control plane.** Tenant shells do not manage their own enablement.
- **A standalone "Widgets" concept is obsolete** (contribution points instead). So is a second builder, and so is any *merged single authority percentage* in reporting.

---

## Q5 — Definition of done (falsifiable)

"Production-ready" is **two named milestones**, each falsifiable, each requiring a named operator journey completed end-to-end over HTTP with no developer, no CLI, and no DB edits.

**Milestone 1 — Akira CMS product-complete.**
- **J1 Theme journey.** As the `administrator` on a fresh tenant: see *Theme Studio* in the sidebar → activate theme B → the public page renders B with zero code change → customize a color/typography value → public page reflects it → rollback restores A. **Fails if** the link is absent, activation needs CLI, output is unchanged, rollback is missing, or a PHP-bearing theme is accepted.
- **J2 Publish-with-authority journey.** Author drafts + submits (cannot publish) → editor approves/publishes via the workflow → a duplicate publish is idempotent (one transition, one audit, one outbox row) → a non-publish role cannot push live. **Fails if** any direct status-flip is reachable.
- **J3 Second-person journey.** Admin creates a user, assigns a narrower role, that user logs in and can do only what policy allows. **Fails if** any role check is hardcoded in a handler.
- **J4 Recovery journey.** Edit → revert a revision → audit trail shows actor + capability + policy. **Fails if** history is unreadable or the revert is ungoverned.
- **J5 Media authority journey.** Upload a disallowed type → refused by `akira.media.upload@1` with 422 (**not** hidden by the form); request deletion; admin approves.
- **J6 Table-stakes journey.** Set site title + permalink + timezone in Settings; create a nav menu; set SEO meta; create a redirect; fetch sitemap/feed. **Fails if** any requires a developer.
- **J7 Governance-legibility journey.** For a real denial, the console explains actor, grant, excluded capability, required actor, policy revision. **Fails if** instrument output disagrees with the enforced policy.
- **J8 Extension-lifecycle journey.** Enable/disable a module; its nav contribution/widget appears/disappears; a revoked-capability contribution renders a safe fallback. **Fails if** disabling leaves a dead link or a hard break.

*Milestone 1 does **not** require delegation, provable-history export, or a non-deterministic Theme Studio.*

**Milestone 2 — authority-native vision complete.** Everything in M1 **plus** delegation as a product (F) and provable history as a product (G), each naming the enforced primitive it uses; Theme Studio after F/G; profiles + production floor after that.

**Explicitly NOT in scope** (see the Not-build list below): P3/P4/P5 as *general* guarantees; Daily Ledger parity; a merged coverage number; live AI enablement; a marketplace; kernel-scoped authority; the media reference-count until a governed cross-module read exists; parity-only feature count.

**Coverage honesty (from the current census, measured 2026-09-13):** WRITE 32/37 = 86.5%; READ 9/47 = 19.1%; NON-HTTP 1/95 = 1.1%. These are published **separately** and never merged. Any surface shipped in M1 must leave the read figure moving and the write figure unchanged.

---

## Q6 — Sequence

**Smallest first increment, given §2 finding 1:** fix the Theme Studio contribution role gate.

- **Cause (measured):** `cms-akira-theme/module.json` declares `"roles": ["admin"]`, but the tenant admin is `administrator`. The gate is strict `in_array` (`src/helpers/module-manager.php:426`), and the shell's own `nav` uses `["admin","administrator","superadmin"]` (`cms-akira-shell/module.json`).
- **Fix:** make the contribution's role set resolve from the same authority the shell uses — not a hardcoded second list — and add a test that nav visibility equals the caller's policy. This is an S, same-day change that makes a *working* feature visible and directly answers the director.
- **Guard:** do not simply add `"administrator"` as a third string; that perpetuates the duplicate-authority pattern R3 exists to delete. The durable fix derives visibility from the policy-derived role, with the literal list as a transitional fallback.

**Then adopt the existing plan rather than inventing a parallel one.** `.ai/current-task.md` and `.ai/akira-completion-plan.md` already define Phase A–I. My ordered phase list:

| Phase | Deliverable | Ships independently? | Depends on | Effort |
|---|---|---|---|---|
| **P0** | Theme Studio visibility fix + nav/policy parity test | Yes — director-visible | — | S |
| **P1** | Surface the orphaned providers: **navigation** (one destination) + **search** (list + public) + **SEO** (editor fields + existing widget) | Yes, per module | Read-governance gate per route | M |
| **P2** | **Settings** surface (tenant-scoped, audited; excludes authority-relevant keys, which are policy) | Yes | Read governance | M |
| **P3** | **Read-governance gate** for every new and existing GET (declare + dispatch-enforce or recorded exemption), keeping the 9/47 census | Cross-cutting; runs with P1/P2 | — | M (parallel) |
| **P4** | **Approvals inbox** (workflow destination) + provenance/revision timeline surface | Yes | P3 | M |
| **P5** | Production floor: redirects, sitemap/schema, backup/export (governed bundles), image handling, scheduling | Yes, per item | P2/P3 | M–L |
| **P6** | Extension **capability-diff / trust** surface at the kernel control plane; Akira links to it | Yes | Extension-point ADR | M |
| **P7** | Operator **Workbench console** (product #2) | Yes | P3 + census | L |
| **P8** | **Delegation (P3)** → **Provable history (P4)** → AI as agent surface | Only after primitives exist | P3/P4 substrate | L |

**Sequencing rule that must not be broken:** the owner's ordering is **admin surface → Workbench → delegation → provable history → Theme Studio → profiles → production floor** (`akira-beyond-the-cms.md`), and the completion plan adds the honesty constraint **"a product surface may use only substrate guarantees that actually exist."** The one insertion I recommend is P0 as a hotfix, because the only reason Theme Studio is "missing" is a bug, and demonstrating it immediately is the cheapest credibility win available.

**Parallelism:** the theme fix, settings and navigation are independent. The read-governance gate must accompany each new surface, not follow it. Workbench (P7) can run in parallel from P3.

**Two paths, named honestly:** `theme gate: A→B→C` and `product track: P0→P8` (adapted from D→I). Theme-first does **not** reach either milestone alone.

---

## Things to deliberately not build

1. **A theme marketplace / plugin store / unsigned third-party upload.** Supply-chain surface; explicit ADR refusal.
2. **A tenant-shell "Modules" and "Extensions" page duplicating the kernel control plane.** Per-tenant enablement is a policy/control-plane concern; the shell must not manage its own authority.
3. **A second theming system, child themes, or per-route template overrides.** One base theme + per-tenant declarative overlay is the ceiling.
4. **Executable PHP in themes, or `functions.php`-style theme code.** ARK is declarative; validate rejects PHP/SQL/migrations.
5. **Ambient global hooks / WordPress plugin model.** Extensions are typed, capability-bound contribution points.
6. **HTML-as-source, an Elementor-style canvas, or client-authoritative rendering.** Builder source is structured JSON; the server renders deterministically.
7. **A role-checkbox "Permissions" grid that competes with the policy row.** Policy row is the sole authority; the screen is an authority editor.
8. **A generic settings blob for authority-relevant keys.** Those are policy rows; a blob becomes a silent second authority.
9. **Live or extended `cms-akira-ai` / file-based `AIGovernance`** before the P3 delegation primitive exists. The file-based governance is a named defect: migrate or delete it.
10. **A dedicated nav entry for every installed module.** Providers surface through hosts or ship in the headless profile.
11. **A second page builder / a `react-builder` duplicate.** One governed builder.
12. **A "Weather" or other vertical inside the CMS floor.** Product-specific, not a CMS requirement.
13. **A merged single authority-coverage percentage**, or counting `unresolved` as covered/debt.
14. **A widget/comment system for parity alone**, absent a live consumer.
15. **Daily Ledger parity, a rebrand, or a new major version** as part of "production-ready Akira."
16. **Reference-CMS sections copied wholesale** (`report-approvals`, `ai-automation`, `import-export` verbatim). Build the *journey*, governed, not the screen shape.

---

## Confidence and what would change my mind

| Answer | Confidence | Basis | What would change my mind |
|---|---|---|---|
| **Q1 floor** | **High** | Measured Akira state + web-confirmed Craft/Statamic/Strapi/Ghost; the floor is forced by breakage, not convention | Evidence that a credible CMS in the target market cannot operate without a *tenant-shell* module/theme installer (i.e., shared-hosting operators genuinely cannot use CLI/control-plane install) |
| **Q2 the 18** | **High** | Full reference route/nav read; reference nav renders only 8 links | A target-market buyer explicitly requires the reference's `react-builder`/`ai-automation`/`report-approvals` shapes as-is |
| **Q3 the seven** | **High** | Grep-proven consumers: navigation/search orphaned; editor/media/seo/workflow surfaced; headless profile is first-class | A discovered consumer of `akira.navigation.*@1` / `akira.search.*@1` in code I did not read, or a product decision that search deserves a destination screen |
| **Q4 governance** | **Med-High** | Identity/extensibility ADRs + measured census; some conclusions (Settings must be narrowed) are inference from R3 | Evidence that a single settings blob can be policy-bound without a second authority; or an owner decision to keep a WP-style role matrix |
| **Q5 definition of done** | **High** | Journeys are HTTP-observable and can fail; milestones mirror the shipped completion plan | Discovery that a named journey cannot be completed over HTTP even after the planned phases (would falsify the milestone) |
| **Q6 sequence** | **Med-High** | P0 cause is code-proven; ordering is copied from the repo's own owner ordering; dependency edges are explicit | The owner reorders #1–#7, or the P0 policy-derived fix proves infeasible and forces a transitional literal list longer than one release |
| **§2 "seven hidden" correction** | **High** | Direct grep of capability ids across manifests and handlers | A runtime path I did not read that renders `cms-akira-navigation`/`-search` surfaces |
| **§2 "14 sidebar links"** | **Low** | Cannot reproduce from manifests (max 10) | A rendered-HTML sidebar scrape on tenant 54 with the `administrator` session |

**Single highest-value next action:** fix the Theme Studio role gate (P0), because it converts the director's strongest evidence — *"a complete feature is hidden by a one-word mismatch"* — from a complaint into a demonstration, at the cost of one afternoon and one regression test. Everything else in this answer is then sequenced to keep that honesty: every subsequent surface must move the read-authority figure and leave the write-authority figure unchanged.
