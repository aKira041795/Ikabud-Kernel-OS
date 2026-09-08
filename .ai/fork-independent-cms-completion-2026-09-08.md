# CMS Akira Independent-CMS — Programme Completion Record

date: 2026-09-08
status: ALL MEMBER GATES COMPLETE (Phases 0–10B)

## Scope delivered
CMS Akira is now an independent, self-contained CMS in the Ikabud Kernel OS 6.x monorepo under
`modules/cms-akira/` — a 15-member suite (10 native capability modules + single admin shell + 4 profiles +
README), fully separate from the legacy `cms` module (which lives in MAIN-CMS-REPO and is never copied).
Every member is bundled but `_enabled:false` (tenant-activated via the Kernel module-install service, never
auto-installed), per the user's directive.

## Members (all tracked; own tables where persistent; MySQL 5.7 Compatibility profile)
- cms-akira-core        — akira.post.{get,list,create,update,publish,unpublish,delete}@1 + entity.list/get.post@1; cms_akira_posts
- cms-akira-shell       — single Kernel-auth admin entry (`entry_module:true`), /cms-akira-shell
- cms-akira-editor      — akira.editor.{render,normalize,sanitize,validate,assets}@1 (table-free)
- cms-akira-navigation  — akira.navigation.{menus,tree,resolve,menu.*,item.*}@1; cms_akira_menus/_menu_items
- cms-akira-media       — akira.media.{library,get,resolve,upload,update,delete}@1; cms_akira_media (tenant storage)
- cms-akira-seo         — akira.seo.{get,meta.build,upsert,delete}@1; cms_akira_seo_metadata
- cms-akira-theme       — akira.theme.{resolve,registry,validate,activate}@1 (table-free; module-setting activation over ARK)
- cms-akira-workflow    — akira.workflow.{evaluate,transition,runs}@1 over Kernel WorkflowEngine (table-free)
- cms-akira-search      — akira.search.{document.build,upsert,delete,query,rebuild}@1; cms_akira_search_documents (renamed from search-adapter)
- cms-akira-ai          — akira.ai.summary.suggest@1 (table-free, provider-free, typed non-fatal unavailable)
- cms-akira-builder     — akira.builder.*@1 (compositions/revisions/publish/render); cms_akira_compositions/_composition_revisions + React/Vite admin-ui (10B)
- profile-minimal/standard/headless/visual — install/dependency-metadata-only bundles (exact compositions; no entry/auth residue)

## Gates merged (all CI 6/6: kernel-contracts, coding-standards, static-analysis, test mysql-8/5.7/mariadb)
| Gate | PR(s) | Result |
|---|---|---|
| P0 freeze + protocol-v2 + ARK de-legacy | #38 | PASS |
| P1 core akira.post.* + lifecycle | #39 | PASS |
| P2 shell + module-install service | #40 | PASS |
| P3 editor | #41 | PASS |
| P4 navigation | #42 | PASS |
| 4A theme (table-free ARK) | #47/#48 | PASS |
| 5A media | #43/#44 | PASS |
| 5B seo | #45/#46 | PASS |
| P6 workflow (Kernel WorkflowEngine) | #49/#50 | PASS |
| P7 search (rename + own table) | #51/#52 | PASS |
| P8 ai | #53/#54 | PASS |
| P9 profiles (metadata-only; certifier profile exemption) | #55/#56 | PASS |
| 10A builder server composition authority | #57/#58 | PASS |
| 10B builder admin UI + profile-visual track | #59/#60 | PASS |

Main HEAD: a0c4bd8 (after 10B docs).

## Final verification (this record)
- capability authority audit: 18 passed / 0 failed
- `capability:audit --json`: ok, 0 findings
- `module:certify --all`: all PASS (17/17 member+profile certs)
- `architecture:check`: 6/6 passed
- `composer test`: 106 files — 106 passed
- tracked members: 15 (10 capability modules + shell + 4 profiles + README); `_enabled:false` on all
- forbidden-residue: zero `akira.content.get@1` / `search.index.*` / `cms.menus/media/seo.*` / `cmsActiveTheme` /
  legacy cmsRender in the Akira path; profiles free of entry/auth fields and runtime scaffolding
- phpstan + php-cs-fixer clean on tracked additions; logs clean; MySQL 5.7 guards clean

## Recorded prerequisites / follow-ups (non-blocking)
- Dedicated-tenant Kernel migration/audit/idempotency/outbox provisioning parity (Kernel prerequisite, not Akira).
- Transactional outbox for guaranteed lifecycle publication (search/workflow) — recorded Kernel prerequisite.
- Public Post-path integration consuming `akira.builder.render@1` if composition output is to replace Post body
  output — **RESOLVED 2026-09-08**: merged PR #62 (core `cacPostDetailCompositionHtml` optional override +
  kernel `CapabilityBus::tryCall()` optional-consumer probe; 11/11 seam test, architecture 6/6, CI 6/6).
- Playwright PW-2 builder-admin journey — **attempted 2026-09-08**: real Akira tenant provisioned via the Kernel
  module-install service (profile-visual, entry cms-akira-shell) and shell routes verified; the sandbox kernel
  `/login` middleware self-redirects (ERR_TOO_MANY_REDIRECTS) before Akira can authenticate — a full-deployment
  prerequisite, not an Akira defect. Spec updated with the verified provisioning recipe + run command; remains a
  runnable spec against a full deployment.

## Notes
- Implementation delegated per the governed workflow: Codex Sol (GPT-5.6) primary; DeepSeek Pro then
  DeepSeek Flash (low reasoning) as fallback lanes when provider usage limits were reached (per chair directives).
- One chair-adjudicated narrow enabler: profile-kind certification exemption in `validateModuleCertification()`
  (C5/C6 N/A for MODULE_KIND_PROFILE) — required by the approved "profiles certify as install metadata only"
  adoption; phpstan baseline refreshed to match.
- Phase contracts recorded under `.ai/contract-independent-cms-phase*.md` (committed); implement prompts kept
  local (untracked).
