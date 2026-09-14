# Akira CMS completeness — synthesis and Phase 0 contract

Chair synthesis of a two-model research and debate round, 2026-09-13.
Governing directive: *"not a reflex reaction but a grounded roadmap."*

## Method (so the confidence is auditable)

| Step | Artifact |
|---|---|
| Grounded research | WordPress admin menu read from `wp-admin/menu.php`; reference CMS measured at `/var/www/html/applicationostest/modules/cms`; live tenant 54 inventory |
| Brief | `.ai/akira-cms-completeness.brief.md` |
| Independent answer A | `.ai/research/akira-cms-completeness.dsflash.md` (DeepSeek Flash, had web access) |
| Independent answer B | `.ai/research/akira-cms-completeness.codexsol.md` (Codex Sol, `:medium`) |
| Chair verification | Re-checked the strongest claims in code and schema — see below |
| Reconciliation round | Each model given the other's answer plus the verification table; both produced committed positions |
| Chair ruling | Recorded at the end |

Neither model saw the other's answer in round 1. Both were told to challenge the brief.

---

## 1. Chair corrections — my own brief was wrong

The debate's most valuable output was falsifying the person who wrote the brief. All five were
verified in code, not conceded on argument:

| I claimed | Truth | How it was caught |
|---|---|---|
| "Every tenant has its own database — **no `tenant_id` columns**" | **FALSE.** `tenant_id` appears in **11** Akira migration files | Codex Sol. I had propagated this from repository instructions — and had *already printed the column myself* when probing `cms_akira_posts` |
| "A **complete** feature is hidden by a one-word mismatch" | **Overstated.** No `akira.theme.install@1`, no upload route, no admission surface exists | Codex Sol. Verified: declared caps are activate/blocks/customize/customizer.schema/customizer.values/registry/resolve/validate |
| "**Seven** modules have zero UI" | **Wrong inference.** Route ownership ≠ user surface; media/editor/workflow are surfaced through the shell | Both models |
| "**14** sidebar links render" | **FALSE.** Source predicts **9** for `administrator`, 10 after the gate fix | Both models. I counted every `href`, including per-post edit links |
| "The theme activation route is fine" | **FALSE.** `capabilities.routes` declares only the two customize routes; **`POST /cms-akira-theme/activate` is undeclared** | Codex Sol |

**And one thing I told the director that was wrong.** I said two of his three complaints "aren't
missing — they're invisible." That was half right: the *customizer* is built and hidden, but **there is
genuinely no theme install**. His complaint was correct and my correction was overconfident.

What did survive verification: **the role gate is real.** `kernelContributionRoleAllowed()` excludes
Theme Studio because the contribution declares `roles: ["admin"]` and the only admin has
`administrator`.

---

## 2. What converged (both models, after seeing each other)

1. **Source state ≠ deployed state.** This is the central finding. Every model claim, and every one of
   mine, describes *this checkout* — not necessarily what tenant 54 runs. Codex Sol marks every
   live-tenant cell **Unverified** for exactly this reason.
2. **Theme install does not exist**, and belongs to the **kernel control plane** as governed package
   admission (signed/approved declarative packages), not as a tenant-shell ZIP upload. Both now agree;
   DeepSeek initially treated it as merely kernel-scoped, Codex Sol showed it is absent entirely.
3. **Navigation is the one genuine orphan** among the screen-less modules. Media/editor/workflow/SEO
   are surfaced through hosts. Search can remain a service with maintenance controls.
4. **Sections must model operator journeys, not module boundaries and not the reference CMS's 18.**
   The reference's own nav renders only 8 links; its 18 prefixes include a duplicate builder and a
   vertical.
5. **Two Akira-required surfaces a conventional CMS cannot have:** an authority/denial console and
   per-change provenance. Governance makes them necessary, not optional.
6. **"Permissions" and "Settings" change in kind.** Policy row is the sole role authority, so a
   checkbox role matrix would create a second authority; a generic settings blob is obsolete for
   anything authority-relevant.
7. **The role-gate fix is the cheapest credibility win** and is agreed by both — but it is now scoped
   correctly: it exposes the *activate/customize/rollback* surface, **not** install.

## 3. Still contested — chair ruling

| Point | DeepSeek | Codex Sol | Chair ruling |
|---|---|---|---|
| Tenant-shell Modules/Extensions page | Reject (kernel owns enablement); accept read-only projection | Lists them as required sections | **Codex Sol's substance, DeepSeek's placement.** The *capability* is required; the *surface* belongs to the kernel control plane. A tenant-shell install page is refused |
| Import/export at launch | "Required later", not launch floor | Operationally required, not necessarily a section | **DeepSeek.** Not a launch journey; required before any second customer |
| "7 modules" framing | 1 orphan + 1 missing inbox + install gap | Measurement error exposing 2 orphan risks | **Both, merged.** It was a measurement error; the real residue is 1 orphan (`navigation`) + 1 missing inbox |
| Definition of done granularity | Phase-scoped journeys | One 40-point milestone | **DeepSeek.** Phase-scoped journeys are the shippable unit; the milestone is the aggregate |

---

## 4. PHASE 0 — the contract (do this before planning any feature as fact)

**Objective.** Replace every source-derived claim about Akira with a deployed-state measurement, so the
roadmap is built on what tenant 54 actually runs.

**Why it is a prerequisite, not a parallel task.** The chair's brief mis-stated an architectural
invariant (`tenant_id`) that was *in the schema it had already read*, and both models then reasoned
from the false invariant. Feature planning on unverified state repeats that error at larger scale.

**Scope — measure and record, change nothing.**

1. **Build identity** — deployed commit hash for tenant 54 vs this checkout; if different, every
   source-derived statement in §2 is provisional.
2. **Database topology** — `SHOW CREATE TABLE` for every Akira-owned table; `tenant_id` presence,
   index position, constraints; migration ledger and drift; **does tenant 54 use a dedicated database
   or a shared schema?** This settles the false "no `tenant_id`" invariant.
3. **Runtime module state** — enabled modules, selected profile, dependency closure, capability
   provider registry, quarantined contributions. Note the anomaly: `cms-akira-theme` is `_enabled:false`
   in its manifest while the tenant serves `/cms-akira-theme` 200.
4. **Authority state** — effective policy rows and versions for the authenticated `administrator`;
   runtime route-authority declarations; direct invocation result for theme activation and each
   visible mutation.
5. **Rendered UI** — authenticated sidebar HTML for `administrator`; **normalised nav destinations,
   not all page `href`s**; HTTP status and authorization result per destination; contribution
   visibility for participant, administrator and denied users.
6. **Theme state** — packages physically present and their provenance; active theme and customization
   values; activation/validate/rollback/audit/cache behaviour; **confirm no admission path exists**.
7. **Operability** — audit, outbox, idempotency and correlation records from real journeys; app and
   error logs; asset availability and CDN dependencies; backup/restore and upgrade/rollback behaviour.

**Deliverable.** A single measurement report where every row is `verified (evidence)` or
`unverified`, and no row is inferred from source. The merged section table's "live tenant state"
column is filled from this report and nothing else.

**Acceptance (can fail).**
- Reproduces the exact sidebar destination count for `administrator` from authenticated HTML, and it
  matches or contradicts the source-predicted 9/10 with a stated explanation.
- States tenant 54's topology unambiguously and resolves the `tenant_id` question with `SHOW CREATE
  TABLE` output.
- Names the deployed commit and says whether it equals this checkout.
- Confirms or refutes the existence of any theme package admission path in the deployed kernel.
- **Fails if** any Akira table or tenancy claim is asserted without its own evidence line.

**Explicitly out of scope:** any code change, any fix, any new capability, the role-gate fix.

---

## 5. Phase roadmap (provisional until Phase 0 lands)

| Phase | Content | Depends on | Independently shippable because |
|---|---|---|---|
| **P0** | Deployed-state reconciliation (above) | — | It is measurement; it changes nothing |
| **P1** | Role-gate fix + remove `Activate` from the active theme + declare `POST /cms-akira-theme/activate` in `capabilities.routes` | P0 confirms the gate behaviour live | One word + two declaration fixes; verified by sidebar HTML |
| **P2** | Content spine: navigation operator screen, redirects/URL lifecycle, SEO editing, search operations | P0; typed settings | Each is one journey end-to-end |
| **P3** | Settings, Approval Inbox, Health hardening, Users/session revocation | P2 | Settings unblocks every later journey |
| **P4** | Kernel: theme **package admission** + module lifecycle hardening + extension trust/capability-diff | P0; artifact/signature model | Kernel-scoped; independent of the shell |
| **P5** | Authority console + provenance surface | Settled policy semantics | The Akira-required differentiator |
| **P6** | Recovery/export as governed bundles; production floor | P5 | Last, and least urgent |

**Falsifiable acceptance for "production-ready"** — seven journey groups named by Codex Sol
(deployment/topology, authority/navigation, publishing, URL/navigation/search, presentation/theme,
package/module, operability), each completable over HTTP by an operator with no developer, no CLI and
no DB edit, and each able to fail.

---

## 6. Refusals (converged)

Theme/plugin marketplace · unsigned browser uploads · PHP or SQL in themes · theme/plugin source
editor · WordPress-style ambient global hooks · child themes and per-route override chains · a second
builder (`react-builder`) · HTML-as-source · client-authoritative rendering · a generic Widgets
section · AI automation at launch · autonomous AI publishing · a generic reports suite · comments ·
a general-purpose import framework · commerce features · a broad analytics platform · a separate Pages
implementation · a generic Tools menu · a role-checkbox Permissions grid competing with the policy row
· a tenant-shell module/installer page duplicating the kernel control plane.

## 7. Open director decisions

1. **Does the roadmap proceed as P0→P6, or is P1 (the visible win) pulled forward ahead of P0?**
   The chair's recommendation: **P0 first.** It is cheap, it is measurement-only, and P1's acceptance
   depends on knowing the live sidebar truth.
2. **Is tenant 54's topology intended to be dedicated-database or shared-schema?** This is an
   architecture decision the models cannot make; Phase 0 will report what *is*, not what *should* be.
