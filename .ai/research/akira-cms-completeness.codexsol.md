# Akira CMS production-completeness assessment

## Executive position

Akira should **not** reproduce the reference CMS’s 18-section menu. Its credible production surface should be organized around operator journeys and the kernel’s differentiators:

1. **Content operations** — content, structure, media, navigation, redirects, search/SEO.
2. **Presentation** — declarative themes and governed compositions.
3. **Administration** — users, capability policy, typed settings, module/extension lifecycle, health.
4. **Governance** — approval work queue, provenance/audit, capability and policy explanations.

The seven modules without module-owned screens should primarily be surfaced through these host sections. Building seven new navigation destinations would expose implementation boundaries rather than operator tasks.

The immediate increment is the Theme Studio visibility correction. It is small and visibly valuable, but it must not be represented as completing theme installation: the repository has activation, customization and rollback, but no governed theme-install capability or route.

There is also a material contradiction that must be resolved before making the tenancy claim: the brief says Akira has **no `tenant_id` columns**, while current Akira migrations define them extensively.

---

# Evidence and research basis

## Repository evidence inspected

Principal sources:

- `docs/architecture/cms-akira-identity-adr.md`
- `docs/architecture/cms-akira-extensibility-adr.md`
- `docs/architecture/akira-product-direction.md`
- `docs/architecture/akira-beyond-the-cms.md`
- `docs/testing/akira-two-tenant-journey.md`
- `modules/cms-akira/*/module.json`
- `modules/cms-akira/*/routes.php`
- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-theme/handlers.php`
- `src/helpers/module-manager.php`
- Akira module migration SQL

## External research

Web access was available. I consulted official documentation for:

- [WordPress Administration Screens](https://wordpress.org/documentation/article/administration-screens/)
- [Drupal Administrative Overview](https://www.drupal.org/docs/user_guide/en/config-overview.html)
- [Craft CMS Control Panel](https://craftcms.com/docs/5.x/system/control-panel.html)
- [Ghost Editor](https://ghost.org/help/using-the-editor/)
- [Statamic Control Panel Overview](https://statamic.dev/control-panel/overview)
- [Strapi Content Manager](https://docs.strapi.io/cms/features/content-manager)
- [Strapi Content-Type Builder](https://docs.strapi.io/cms/features/content-type-builder)
- [Strapi Media Library](https://docs.strapi.io/cms/features/media-library)
- [Shopify Theme Editor](https://shopify.dev/docs/storefronts/themes/tools/online-editor)

The cross-product conclusion is general knowledge supported by those systems: credible CMSs converge on **content, assets, structure, access, configuration and delivery/presentation**, but they do not converge on a single menu taxonomy. Ghost has a narrower publishing surface; Strapi emphasizes content models and APIs; Shopify combines CMS functions with commerce; Drupal and Craft expose broader configuration and extension administration. Therefore menu parity is not a valid completeness test.

---

# Adversarial review of §2

## Claims supported by source

1. **There are 15 Akira child manifests in the repository.**  
   This proves 15 available suite members, not that all 15 are installed and enabled for tenant 54. Tenant database/control-plane evidence is required for the latter.

2. **The shell declares 36 routes.**  
   `cms-akira-shell/routes.php` contains 17 GET and 19 POST routes. Three GET routes are public (`/`, `/posts`, `/posts/{slug}`), so “36 shell routes” is true but is not the same as “36 admin routes.”

3. **Theme Studio exists.**  
   Source declares:
   - `GET /cms-akira-theme`
   - `POST /cms-akira-theme/activate`
   - `POST /cms-akira-theme/customize`

   Its handler renders an installed-theme list, activation forms, customizer controls, save action and conditional rollback.

4. **The role mismatch is real.**  
   `cms-akira-theme/module.json` restricts its sidebar contribution to `["admin"]`.  
   `kernelContributionRoleAllowed()` performs exact role membership. An `administrator` therefore does not see the contribution.

5. **The seven named modules have no module-owned non-API admin page routes.**  
   Their route files expose health, stream or API endpoints rather than dedicated admin pages.

## Claims that are wrong or overstated

### 1. “Every tenant has its own database — no `tenant_id` columns” is contradicted by the repository

Current migrations define `tenant_id` in, among others:

- `cms-akira-core/database/migrations/002_create_posts.sql`
- `004_create_taxonomies.sql`
- `005_create_content_types.sql`
- `006_create_post_taxonomies.sql`
- `007_create_post_revisions.sql`
- `cms-akira-builder/database/migrations/002_create_compositions.sql`
- `cms-akira-media/database/migrations/002_create_native_media.sql`
- `cms-akira-navigation/database/migrations/002_create_native_navigation.sql`
- `cms-akira-search/database/migrations/002_create_native_search_documents.sql`
- `cms-akira-seo/database/migrations/002_create_native_seo_metadata.sql`

The navigation tests even describe a “shared-schema tenant B” scenario. Dedicated databases and tenant discriminator columns can coexist, but that is not the stated “no `tenant_id` columns” architecture.

**Evidence that settles it:** `SHOW CREATE TABLE` for tenant 54’s Akira tables, tenant connection mappings, and an explicit ADR deciding whether these columns are transitional defence-in-depth or prohibited architecture.

### 2. “A complete feature is hidden” is too strong

Theme Studio is substantially implemented, but the theme lifecycle is not complete:

- no `akira.theme.install@1` capability was found;
- no install route or package-admission UI was found;
- the theme activation form route is not listed in `capabilities.routes`;
- repository architecture notes identify theme activation as outstanding route-authority work;
- every listed theme, including the active one, receives an `Activate` button.

The accurate claim is: **a useful activate/customize/rollback surface is hidden**, not “theme installation is complete.”

### 3. “Seven capability providers have no screens, therefore their presentation layer was not built” is false as a general inference

At least three are already presented through the shell:

- `media` powers `/cms-akira-shell/media`;
- `editor` is embedded in post editing;
- `workflow` supplies editor lifecycle actions.

SEO declares a dashboard widget contribution. Search can reasonably remain a public service with maintenance controls in Health/Settings. Module route ownership is therefore a poor proxy for whether users can operate a capability.

### 4. “14 sidebar links actually rendered” is not reproducible from current source

`akiraShellPage()` builds:

- five participant links;
- four additional administrator links;
- enabled sidebar contributions.

Only Theme Studio currently declares an Akira sidebar contribution, and that contribution is filtered out for `administrator`. Current source therefore predicts **9 links for that administrator**, or 10 after correcting Theme Studio, not 14.

**Evidence that settles it:** authenticated HTML from tenant 54, the exact deployed commit hash, enabled-module snapshot, and a list of `<nav>` link destinations. The deployed tenant may differ from this checkout.

### 5. Route-count labels are ambiguous

Calling shell “12 admin UI routes,” core “5,” and builder “1” is not reproducible directly from the route files. Core and builder predominantly expose APIs; the shell owns their visible pages. A route census needs a written classification rule distinguishing public, admin-page, form-action, API and health routes.

**Confidence in this audit: high.**  
It would change if tenant 54 is demonstrably running a different commit or schema from the checkout.

---

# Q1 — Minimum production floor

## Position

A credible general-purpose CMS needs **six functional surfaces**, not a prescribed menu. Akira needs four additional governance surfaces because of its stated identity.

## General CMS floor

| Surface | Why production operation breaks without it |
|---|---|
| **Content workspace** | Operators cannot create, edit, schedule/publish, unpublish, delete or recover content without developer intervention. |
| **Content structure** | A general-purpose CMS cannot safely evolve fields, types and classifications if structure exists only in code or raw SQL. A publishing-only product such as Ghost may deliberately narrow this. |
| **Media/assets** | Editors cannot manage images/files, metadata, references and safe deletion. Media hidden inside an editor is acceptable only if the complete asset lifecycle remains operable. |
| **Navigation and URL lifecycle** | Operators cannot control discoverability, menus or preserve URLs after slug/site changes. Redirect management can be embedded here. |
| **Users and access** | Teams cannot onboard/offboard users or separate drafting, approval and publishing authority. |
| **Site configuration and delivery** | Operators cannot change site identity, delivery behavior or presentation safely. In a headless CMS this means API/webhook/delivery settings rather than themes. |

A dashboard is useful but not part of the functional floor: a CMS can operate without one. Comments, AI, plugins, a visual builder and multiple content labels such as separate Posts/Pages are also not universal requirements.

## Akira-specific floor

Akira additionally requires:

| Surface | Why Akira’s thesis breaks without it |
|---|---|
| **Capability policy / authority administration** | Policy rows are not meaningfully operator-governed if changing them requires SQL or a developer. |
| **Approval work queue** | A governed workflow that can only be exercised by opening individual content records is not operational at team scale. |
| **Provenance and audit** | “Can prove what it published” is not a product capability if evidence is inaccessible to operators. |
| **Module/extension trust and health** | Tenant-specific module closures and typed contributions need inspectable status, capability diffs, enablement and revocation. Otherwise modular governance is invisible. |

**Confidence: high.**  
I would lower it if Akira’s intended production profile were explicitly narrowed to single-user, headless-only publishing; that would remove menus, visual themes and approval workflow from that profile’s floor.

---

# Q2 — Assessment of the reference CMS’s 18 sections

“Required” below means required for Akira’s standard visual production profile. Profile-specific qualifications are explicit.

| Reference section | Decision | Reason |
|---|---|---|
| `ai-automation` | **Deliberately excluded** | No launch journey depends on AI, and current `cms-akira-ai` is a deterministic summarizer rather than governed provider-backed AI. |
| `categories` | **Required-but-different** | Classification is required, but it belongs under a unified **Content Structure / Taxonomies** surface rather than being treated as a universal standalone object. |
| `content` | **Required** | This is the primary author/editor lifecycle. |
| `content-types` | **Required** | Akira promises schema-declared content; operators need safe type evolution without SQL. |
| `customize` | **Required-but-different** | It must be an ARK-schema customizer with validated tokens, preview and governed persistence—not arbitrary code/CSS editing. |
| `extensions` | **Required-but-different** | Show typed contributions, capability requirements and revocation; no marketplace, ambient hooks or unsigned uploads. |
| `import-export` | **Deliberately excluded from initial production scope** | Generic file import/export is not a universal authoring requirement. Recovery must exist operationally; future portability should be governed bundles with dry-run diffs. |
| `media` | **Required** | Editors need asset upload, metadata, reuse and safe deletion. |
| `menus` | **Required** | A visual site cannot be operated without navigation management. Headless profiles may omit it. |
| `modules` | **Required-but-different** | It must manage tenant module closure, dependencies, health and activation—not upload arbitrary PHP. |
| `page-builder` | **Required-but-different for the visual profile** | Akira already promises governed structured-JSON compositions. It is optional for standard/headless profiles and must never store HTML as source. |
| `permissions` | **Required-but-different** | It is a capability-policy and grant-state console, not only a conventional role/checkbox matrix. |
| `react-builder` | **Deliberately excluded** | “React” is an implementation choice, not a second product capability. One composition editor should exist regardless of client framework. |
| `redirects` | **Required** | Slug changes and migrations otherwise produce broken inbound links and SEO loss. |
| `report-approvals` | **Required-but-different** | Build a workflow inbox and decision history rather than a generic reporting section. |
| `settings` | **Required-but-different** | Typed contributed settings must be capability-gated, validated, versioned and audited. |
| `themes` | **Required-but-different for visual profiles** | Manage validated declarative ARK packages, activation and rollback; no executable themes. |
| `users` | **Required** | Operators must onboard, deactivate and assign editorial responsibilities without developer access. |

**Confidence: medium-high.**  
The main judgment call is `page-builder`: I classify it as required only for the visual profile because the accepted Akira architecture already makes compositions a product feature. It would become deliberately optional if Akira formally launches only a conventional or headless profile.

---

# Q3 — What to do with the seven screen-less modules

## Position: **(c) Surface them through operator-oriented host sections**

Do not build seven module dashboards. Module boundaries are dependency and authority boundaries; they are not automatically information-architecture boundaries.

| Module | Correct surface |
|---|---|
| `ai` | Contextual editor suggestion, plus policy/provider controls in Settings or Trust Center only when real AI is installed. No standalone AI page at launch. |
| `editor` | Embedded in Content editing. |
| `media` | Existing Media section; add metadata/reference management there. |
| `navigation` | Menus/Navigation section hosted by the shell. |
| `search` | Public search; reindex/status controls under Health or Settings. |
| `seo` | Fields in the content editor, defaults in Settings, health summary on Dashboard. |
| `workflow` | Actions and history in the editor, plus a cross-content Approval Inbox. |

This is already partly how Akira works. Media, editor and workflow are direct counterexamples to the claim that each provider needs its own routes.

A module may remain entirely headless only when:

1. its capabilities are consumed through another complete surface;
2. configuration is declaratively documented;
3. health and policy state are inspectable;
4. disabling it has an explicit, safe fallback;
5. no routine operator task requires CLI or SQL.

**Confidence: high.**  
I would change this only if these modules were sold and operated independently rather than as members of one Akira suite.

---

# Q4 — Governance-driven sections and obsolete conventions

## Position

The kernel does not eliminate administration; it changes the objects being administered from broad roles and executable plugins to capabilities, policy versions, declared contributions and receipts.

## Sections made necessary by the kernel model

### 1. Authority / Trust Center

This replaces a simple permission matrix with:

- capability and provider;
- allowed actor roles;
- caller module;
- tenant scope;
- policy/declaration version;
- grant state: granted, suspended or revoked;
- reason and actor for changes;
- effective access explanation;
- dependencies and affected screens.

The UI must call governed policy capabilities. It must not directly update policy tables.

### 2. Provenance and audit

Every content record should expose a timeline connecting:

- revision;
- workflow transition;
- capability invoked;
- actor and actor source;
- policy version/decision;
- idempotency and correlation IDs;
- publication result;
- relevant extension/theme version.

A searchable cross-site audit view is also necessary. A database log invisible to operators does not satisfy the product claim.

### 3. Module and extension trust

Installation/activation should show:

- dependency closure;
- declared routes and contributions;
- capabilities added/removed;
- tables owned/read;
- policy rows to be seeded;
- signature/provenance of the deployment-approved package;
- validation or quarantine failures;
- safe behavior after disable/revocation.

### 4. Approval inbox

Workflow state already exists, but operators need queues for “awaiting review,” conflicts and rejected work. The queue should link to content-specific evidence rather than become a generic reporting engine.

### 5. Auditable settings

Settings need typed schemas, capability checks, previews/diffs where material, audit receipts and cache-effect reporting. “Save succeeded” is insufficient when configuration changes public output.

### 6. Module health

Because the shell composes multiple capability providers, operators need to distinguish:

- not installed;
- installed but disabled;
- dependency unavailable;
- policy denied;
- contribution quarantined;
- migration pending;
- provider unhealthy.

## Conventional sections made obsolete

- **Plugins as arbitrary executable uploads**
- **Theme/plugin code editors**
- **Generic ambient hook administration**
- **A second React Builder menu**
- **Widgets as unrestricted executable components**
- **Separate module pages solely because modules exist**
- **Separate Posts and Pages implementations** when schema-declared content types can model both
- **A generic Tools dumping ground** for unrelated privileged operations

A conventional Users section and Permissions section are not obsolete; they are transformed. Users manage identities and status, while Authority manages what those identities and modules may do.

**Confidence: high on the conceptual model; medium on UI consolidation.**  
Actual operator usability testing could show that Authority and Audit should be separate destinations rather than tabs in one Trust Center.

---

# Section delivery table

Effort assumes existing capability implementations remain usable. It includes production UI, policy integration and browser tests—not only route creation.

| Section | Required? | Why | Akira state | Effort | Dependency |
|---|---|---|---|---:|---|
| Dashboard | Useful, not floor | Queues and health need a landing view | Renders; SEO widget contribution exists | S | Contribution tests |
| Content | Yes | Authoring and lifecycle | Renders; workflow/revisions integrated | M hardening | Governance gate |
| Content Structure | Yes | Schema and taxonomy operation | Content types/categories render | M | Safe schema-change rules |
| Media | Yes | Asset lifecycle | Shell page and mutations exist | M | Reference tracking, metadata |
| Menus / Navigation | Yes, visual profile | Operable site navigation | Capabilities exist; no shell page | M | Navigation capability integration |
| Redirects | Yes | Preserve URLs | No named surface/module found | M | Publication/slug events |
| Search | Yes for public site | Discoverability | Capability provider; no operator controls | M | Public rendering, reindex job |
| SEO | Yes for public site | Metadata/canonical control | Capabilities + dashboard widget; no editor/settings UI | M | Settings contribution + editor |
| Themes | Yes, visual profile | Presentation lifecycle | Hidden activate/customize/rollback surface; no install | M | Role fix, authority declaration |
| Theme Customizer | Yes, visual profile | Safe operator presentation changes | Implemented but hidden with Themes | S hardening | Theme visibility |
| Compositions / Builder | Profile-specific | Structured landing pages | List/editor routes and APIs exist | M | ARK block contracts |
| Users | Yes | On/offboarding and assignment | Renders | S/M | Session invalidation tests |
| Authority / Permissions | Akira-specific yes | Operate capability policy | Basic role editing renders | L | Policy explanation/grant model |
| Approval Inbox | Akira-specific yes | Team workflow operation | Workflow embedded per post; no queue | M | Workflow query capability |
| Settings | Yes | Operate site and modules | `/cms-akira-shell/settings` absent | M | Typed settings registry |
| Modules | Akira-specific yes | Operate tenant closure | Health exists; `/modules` absent | L | Install planner, rollback |
| Extensions | Akira-specific yes | Inspect typed contributions and trust | Registry exists; `/extensions` absent | L | Module lifecycle + capability diff |
| Provenance / Audit | Akira-specific yes | Make governance claim usable | Underlying audit/revisions exist; no complete operator surface cited | L | Read policy and correlation model |
| Health | Akira-specific yes | Diagnose composed providers | Renders | M hardening | Standard diagnostics |
| Import / Export | No for first release | Not needed for primary journey | Deferred by ADR | — | Future bundle contract |
| AI Automation | No | No required journey or real governed AI | Deterministic summary provider only | — | Delegation and provider governance |
| React Builder | No | Duplicate implementation-labelled section | Builder client assets exist | — | Use one builder only |

---

# Q5 — Falsifiable definition of “production-ready”

## Position

Akira is production-ready only when an operator can launch, govern, change and diagnose a visual site without CLI, SQL or source edits after deployment. Passing route counts is insufficient.

## Named acceptance journey: **Governed Site Launch and Change**

Run on a clean MySQL 5.7 tenant using production HTTP routing.

### A. Site administration

1. Administrator logs in and sees every contribution allowed by effective policy, including Theme Studio.
2. Administrator changes site title, locale/time zone, URL behavior and SEO defaults through typed Settings.
3. Every settings mutation produces one audit receipt and the documented cache invalidations.
4. Unauthorized roles neither see nor invoke the same mutations.

### B. Structure and team

5. Administrator creates or modifies a content type and taxonomy without SQL.
6. Administrator creates an author and editor, assigns effective capability policy, then signs out.
7. The authority screen explains why each role can draft, approve or publish.

### C. Content lifecycle

8. Author creates a draft, selects taxonomy, uploads/reuses media, supplies alt text and SEO metadata.
9. Author saves twice with the same idempotency key; only one mutation/revision is recorded.
10. Author submits for review but cannot approve or publish.
11. Editor finds the item in an Approval Inbox, reviews the semantic diff and approves.
12. An authorized publisher publishes it.
13. Public output displays only the published revision through ARK/DiSyL and includes correct metadata, media and navigation.
14. Revision, workflow, capability, policy version, actor, correlation ID and publication receipt are visible from the content record.

### D. URL and search lifecycle

15. Publisher changes the slug.
16. The old URL redirects to the new URL.
17. Public search returns the new published projection and never returns a draft.
18. Reindexing can be requested and diagnosed without CLI access.

### E. Presentation lifecycle

19. Administrator opens Theme Studio from normal navigation.
20. Administrator selects a deployment-approved declarative theme package, sees validation and capability/package provenance, activates it and previews public output.
21. Administrator customizes schema-declared values.
22. Invalid values and packages containing PHP, SQL, traversal or unknown renderers fail closed.
23. Activation/customization is audited and cache-invalidated once.
24. Rollback restores the prior public render.

### F. Module and extension lifecycle

25. Administrator views installed suite members, dependencies, owned tables, routes, contributions and capability diff.
26. Administrator enables a compatible first-party extension for this tenant.
27. Its typed contribution appears without code changes.
28. Revoking its render capability causes a safe fallback.
29. Disabling it removes its contributions without breaking the host screen.
30. Incompatible or unsigned third-party packages cannot be installed through the admin.

### G. Isolation and recovery

31. Repeat the content journey on tenant B.
32. A cannot read, mutate, search, render or audit B’s data, and vice versa.
33. The release test proves the actual database topology and checks for forbidden cross-tenant rows.
34. An operator can execute the documented backup/restore procedure and restore a tenant to a clean installation. This may be deployment tooling rather than an admin section.

### H. Operability and UI

35. All required routes return the intended 2xx/3xx result; no required navigation destination returns 404.
36. Health distinguishes disabled, denied, migration-pending and failed providers.
37. Primary journeys work with keyboard navigation and at supported mobile/desktop widths.
38. Production assets do not silently depend on unavailable development CDNs.
39. Browser console, application log and error log remain clean during the journey.
40. Release CI runs the real two-tenant HTTP journey rather than skipping it.

## Explicit failure conditions

Akira is **not** production-ready if any of these is true:

- a routine task requires SQL, source editing or developer CLI;
- a primary content write bypasses the capability bus;
- a route is visible but policy-denied without explanation;
- a capability is allowed but its required UI is hidden by role-string drift;
- duplicate submission produces duplicate domain or audit effects;
- public rendering exposes a draft;
- an extension contribution executes after revocation;
- a tenant boundary relies solely on payload-provided `tenant_id`;
- “passing” release CI skipped the HTTP/isolation journey;
- the declared database-isolation architecture does not match deployed schemas.

## Not in scope

- Marketplace
- Unsigned third-party browser uploads
- Executable PHP/SQL themes
- WordPress-style global hooks
- Child themes or per-route override hierarchies
- Separate React and non-React builders
- HTML-as-source or client-authoritative rendering
- Comments/community features
- Commerce administration
- Generic analytics suite
- AI automation or autonomous publishing
- Multilingual/editorial-calendar features unless a named launch customer requires them
- Full arbitrary-file import framework
- Pixel-level visual design canvas

**Confidence: high.**  
Load/SLA targets, supported browsers and backup RPO/RTO still require product-owner and hosting commitments before the criteria can become a complete release contract.

---

# Q6 — Ordered, independently shippable sequence

## Position

Ship visible credibility first, then establish truthful architecture and operator journeys. Settings/modules/extensions should not be built as disconnected CRUD pages.

## Phase 0 — Reveal and verify Theme Studio

**Smallest first increment**

1. Correct Theme Studio authorization so the canonical administrator role sees it. Prefer capability/effective-policy visibility; at minimum include the supported administrator aliases.
2. Add a regression test using the real contribution registry and an `administrator` context.
3. Verify authenticated tenant-54 HTML contains the link.
4. Verify list, activate, customize, audit, cache invalidation and rollback over HTTP.
5. Remove or disable `Activate` for the already-active theme.

**Demonstration:** the director logs in and reaches a previously hidden working Theme Studio from the sidebar.

**Effort:** S.  
**Independently shippable:** yes.

## Phase 1 — Truth and governance gate

1. Define route-census categories and publish reproducible counts.
2. Reconcile the dedicated-database/no-`tenant_id` architecture with actual migrations.
3. Complete route-authority declarations, including theme activation and shell post deletion.
4. Make the two-tenant HTTP journey mandatory in release CI.
5. Add a “nav destination exists and is authorized” browser test for every rendered link.

**Demonstration:** an evidence report proves topology, route authority, navigation integrity and isolation.

**Effort:** M/L.  
**Must precede claims of production readiness.**

## Phase 2 — Complete the everyday operator loop

Parallel workstreams:

- **2A:** Menus UI over `cms-akira-navigation`.
- **2B:** SEO fields/defaults and redirect lifecycle.
- **2C:** Search status/reindex and public-result verification.
- **2D:** Media metadata, usage references and safe deletion.
- **2E:** Content type/taxonomy/revision UX hardening.

**Demonstration:** an editor can create, classify, enrich, publish, find and safely rename content.

**Effort:** M per stream.  
**Independently shippable:** each stream, provided Phase 1 gates remain green.

## Phase 3 — Settings and workflow operations

1. Typed, contributed Settings host.
2. Approval Inbox.
3. Health diagnostics for provider/policy/migration/contribution states.
4. Consolidate AI/search/SEO/workflow controls into these hosts rather than separate module menus.

**Demonstration:** a non-developer configures the site and runs a multi-role editorial queue.

**Effort:** M/L.

## Phase 4 — Governed package lifecycle

Build in dependency order:

1. Theme package admission/install for deployment-approved declarative packages.
2. Tenant module closure view and staged enable/disable/rollback.
3. Extension contribution and capability-diff view.
4. Revocation/quarantine and safe-fallback demonstration.

Modules must precede Extensions because extension enablement depends on module installation and dependency closure. Theme install can run partly in parallel but should reuse the same artifact-provenance vocabulary.

**Demonstration:** an operator can safely change what code-free packages and suite members are active without marketplace uploads.

**Effort:** L.

## Phase 5 — Authority, provenance and trust

1. Effective-policy explanation.
2. Grant-state administration with mandatory reasons.
3. Record-level provenance drawer.
4. Cross-content audit search.
5. Publication receipt/export if cryptographic verification is ready.

The authority-store semantics must be settled before this UI is trusted.

**Demonstration:** an operator answers who published an item, under which capability and policy, and can revoke future authority.

**Effort:** L.

## Phase 6 — Production certification

1. Run the full named journey on fresh MySQL 5.7 tenants.
2. Validate backup/restore and upgrade/rollback.
3. Accessibility, responsive, dependency and error-path testing.
4. Publish supported profile matrix: standard, visual, headless.
5. Freeze a release evidence bundle with exact commit, schemas and test results.

**Demonstration:** a repeatable release candidate either passes or fails without subjective interpretation.

**Effort:** M/L.

### Dependency summary

```text
Theme visibility ───────────────────────────────┐
                                               ├─ Production certification
Topology + governance truth ─┬─ Core UX ───────┤
                             ├─ Settings/inbox ─┤
                             └─ Package lifecycle ─ Trust/provenance
```

**Confidence: high on sequencing.**  
I would move package lifecycle earlier only if the first production customer must install or vary suite members without deployment assistance.

---

# Things deliberately not to build

| Do not build | Reason |
|---|---|
| A clone of all 18 reference destinations | Menu parity is not an operator outcome. |
| Seven module-specific dashboards | Exposes architecture rather than tasks and duplicates host UI. |
| Plugin/theme marketplace | Explicit supply-chain refusal; not needed for the launch journey. |
| Unsigned browser uploads | Violates the trust model. |
| PHP or SQL in themes | Violates ARK’s declarative sandbox. |
| Theme/plugin source editor | Creates ambient executable authority. |
| WordPress-style global hooks | Contributions must remain typed and capability-bound. |
| Child themes and per-route override chains | Creates unresolved precedence and customization sprawl. |
| Separate `page-builder` and `react-builder` products | Client technology is not a product section. |
| HTML-as-source builder | Breaks deterministic validation and render authority. |
| Client-authoritative public rendering | Breaks the declared publication boundary. |
| Generic Widgets section | Blocks should be typed theme/extension contributions. |
| AI automation for launch | No required journey and no production AI authority/provider model yet. |
| Autonomous AI publishing | Contradicts human approval and bounded authority. |
| Generic reports suite | Build the specific approval and governance views operators need. |
| Comments | Not required for governed publishing and introduces moderation/abuse scope. |
| General-purpose import framework | Build governed bundles later; do not admit arbitrary data/code formats now. |
| Commerce features | Shopify’s admin proves these are commerce requirements, not CMS floor. |
| A broad analytics platform | External analytics or focused health metrics suffice for the defined journeys. |
| Separate Pages implementation | Use schema-declared content types unless a distinct lifecycle is proven necessary. |
| A generic Tools menu | Privileged operations should live in typed, auditable task surfaces. |

---

# Confidence summary

| Answer | Confidence | Evidence that would change the conclusion |
|---|---|---|
| Q1 — floor | **High** | A formal launch profile narrowing Akira to single-user/headless operation |
| Q2 — 18 sections | **Medium-high** | Customer journeys proving builder, redirects or content-type editing are deployment-only rather than operator tasks |
| Q3 — seven modules | **High** | Evidence that modules are independently sold/operated and require separate operator personas |
| Q4 — governance angle | **High** | A kernel model where policy/audit are intentionally developer-only rather than product-visible |
| Q5 — definition of done | **High** | Different SLA, browser, backup, accessibility or hosting commitments |
| Q6 — sequence | **High** | A committed customer requiring self-service module installation before editorial completeness |
| §2 audit | **High for source, medium for tenant 54** | Authenticated tenant-54 HTML, deployed commit hash, module-setting rows and `SHOW CREATE TABLE` output contradicting this checkout |

The highest-priority evidence gap is not another feature census. It is reconciling the actual tenant schema and deployed HTML with the architectural and measured claims.
