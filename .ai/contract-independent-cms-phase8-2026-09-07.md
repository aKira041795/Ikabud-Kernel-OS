# CMS Akira Independent-CMS — Phase 8 gate: ai → native `akira.ai.*@1` (table-free, provider-free)

task: Execute Phase 8 of the APPROVED independent-CMS contract (`.ai/current-task.md` gate 8). Re-scope the dormant
`cms-akira-ai` (legacy-CMS handler stubs + `akira.content.get@1` residue) into a NATIVE, TABLE-FREE, PROVIDER-FREE
Akira AI module that retains `akira.ai.summary.suggest@1`, consumes core post projections (entity.get.post@1),
returns a TYPED NON-FATAL UNAVAILABLE result when no AI backing is available, and never becomes a dependency of any
other member/profile. Phases 0-7 merged (core akira.post.* + entity projections, shell, install service, editor,
navigation, media, seo, theme, workflow, search). Kernel READ-ONLY.

## Current architecture you MUST preserve (read first)
- `modules/cms-akira/cms-akira-editor/` is the closest sibling (table-free; depends `entity.get.post@1`; consumes
  core projections). Mirror its structure + capability handling.
- `modules/cms-akira/cms-akira-core/` exposes `akira.post.*@1` + `entity.list.post@1`/`entity.get.post@1`
  (depends kernel.idempotency.* + kernel.audit.record@1). Cross-member reads are via capabilities/Entity Views
  only — the AI module reads post content through `entity.get.post@1` (kernel EntityView bridge), never SQL into
  `cms_akira_posts`.
- There is NO Kernel-governed `ai.*` capability today and no provider SDK is allowed — the AI module must work
  deterministically WITHOUT any external AI/LLM/network at this gate and expose a typed non-fatal unavailable
  result for the not-backed case. Do NOT invent or require kernel ai.* infra.

## Contract specifics (honor exactly — master contract gate 8)
- Re-scope `cms-akira-ai`: `kind: extension`, `extends: cms-akira-core`, depends = cms-akira-core ONLY via
  `entity.get.post@1` (plus nothing else — no kernel.ai.* dependency since it does not exist). `owns_tables: []`,
  `reads_tables: []`; migrations = ONLY table-free `001_initial.sql` marker (NO 002 — no table). Remove the
  legacy `/admin/cms-akira-ai` handler + `akira.content.get@1` residue; `_enabled:false`.
- Capability `akira.ai.summary.suggest@1` (freeze name; single first-mode capability):
  - Payload: an Akira entity key (post slug/key — opaque ASCII reference, never foreign SQL / never tenant from
    payload).
  - Behavior: consume the entity.get.post@1 projection; from its ALLOWLISTED text fields produce a deterministic
    suggestion result — typed structure e.g. `{ status: 'ok', summary: <string>, keywords: <string[]> }` (extractive
    summary + keyword extraction — NO external AI/LLM/network; pure deterministic local algorithm over the
    allowlisted projection fields so it is testable offline).
  - TYPED NON-FATAL UNAVAILABLE: when the referenced entity is missing/unreadable, or when the AI backing would be
    unavailable (no provider configured — always true at this gate unless a config flag enables local mode), the
    capability returns a structured non-fatal result e.g.
    `{ status: 'unavailable', reason: '<machine-readable reason>' }` — it MUST NOT throw/500 and MUST NOT leak
    another tenant's content. Local deterministic mode is the shipped behavior; the unavailable branch is for the
    genuinely-not-backed/config-off case and both branches are tested.
  - Read-only: no mutation caps; no effects.invalidates needed (nothing cached); no requires_protocol v2 (no write).
- No provider SDK, no table, no network. AI is NEVER a dependency of another member/profile — verify nothing in the
  suite depends on cms-akira-ai (grep).
- `_enabled:false` retained → TRACK: un-ignore `modules/cms-akira/cms-akira-ai/**` in `.gitignore`.
- README: AI authority model (deterministic local suggest over core projections, typed unavailable contract, no
  provider/table boundary, capability list), note that kernel ai.* backing remains an optional future seam and the
  module never blocks on it.

## Deliverables
1. modules/cms-akira/cms-akira-ai re-scoped (native module.json akira.ai.summary.suggest@1 + entity.get.post@1 dep
   + empty owns/reads + only table-free 001 migration; native handlers/helpers + capability handler map; remove
   residue), README.
2. Deterministic suggest algorithm + typed non-fatal unavailable branch, both tested.
3. Track via .gitignore; commit source + tests.
4. Tests (mirror editor/sibling layout): suggest returns deterministic ok result for an existing post projection
   (allowlisted fields only, stable output), typed unavailable for missing entity + config-off branch (no throw),
   tenant isolation (tenant A cannot suggest over tenant B entity — negative), projection allowlist enforced (no
   raw/unlisted field leakage), authority zero on this member, certify, no-dependents grep.
5. Append result to the contract.

## Verification (do all)
- AI tests green; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 + media 37 + seo 39 + theme 36
  + workflow 42 + search 39 green; authority 18/18; capability audit zero; module:certify --all (incl ai);
  composer full; phpstan + cs-fixer (tracked ai); logs clean; CI 6/6 (branch feat/independent-cms-phase8-ai,
  commit, push, PR, checks 6/6, merge, checkout main+pull; report PR #; then docs-result PR merged, as prior
  phases).
- grep over tracked ai (incl staged ignored): no `akira.content.get@1`, `cmsRequireCap`, `cmsRender`,
  `cmsActiveTheme`, no table creation / migration 002, no provider SDK/network call, no `search.index.*`; record
  the git ls-files proof. Confirm no member/profile depends on cms-akira-ai.
- MySQL 5.7 clean (no own table).

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 9 profiles ready?)

---

## Result (Phase 8)

status: PASS

task: Re-scope dormant `cms-akira-ai` as a native, table-free, provider-free extension exposing deterministic `akira.ai.summary.suggest@1` over the tenant-scoped core Post projection.

changed:
  - `.gitignore` tracks `modules/cms-akira/cms-akira-ai/**`
  - `modules/cms-akira/cms-akira-ai/module.json` freezes one read-only capability, only the core module/projection dependency, empty owns/reads, and the sole table-free migration marker
  - native helpers, health handler/route, README, and Phase 8 contract/integration test

implementation_summary: The capability accepts only an opaque ASCII Post key and resolves it through `entity.get.post@1` under the active Kernel tenant context. A second fail-closed projection allowlist reduces `title`, `subtitle`, and `body` to normalized plain text. A deterministic local algorithm returns an extractive summary and stable frequency/source-order keywords. Missing/cross-tenant entities, invalid references, broadened projections, and tenant config-off return typed non-fatal `unavailable` data without throwing or echoing content. Local mode defaults on and is tenant-disableable through the Kernel-owned module-setting seam. The runtime has no foreign SQL, module table, mutation/effect/protocol contract, provider SDK, or remote transport, and no member/profile depends on AI.

verification:
  - AI contract 28/28; PHP syntax clean
  - P1 38/38, P2 38/38, shell 21/21, install 23/23, editor 25/25, navigation 35/35, media 37/37, SEO 39/39, theme 36/36, workflow 42/42, search 39/39
  - navigation/media/SEO/theme/search dedicated regressions 6/6, 6/6, 6/6, 7/7, 7/7
  - capability authority 18/18; capability audit zero
  - `module:certify --all` passed, including AI 13/13
  - `composer test` 106/106; AI-scoped PHPStan and PHP CS Fixer clean
  - tracked/staged path proof listed README, one marker migration, runtime, manifest, route, and test; runtime forbidden-residue scan and suite dependent-manifest scan returned no matches
  - application/error logs clean after AI positive and negative paths
  - implementation PR #53 merged after CI 6/6: Kernel contracts, coding standards, static analysis, MySQL 8, MySQL 5.7, MariaDB 10.6

scope: `.gitignore`, native AI member, and this result record only.

unexpected_files: none. Existing unrelated untracked `.ai/*` and ignored dormant members/profiles/runtime files were not staged.

risks: The local algorithm is intentionally extractive and bounded rather than generative. Any future Kernel-governed AI backing is a separate optional gate and cannot be assumed by callers.

unresolved: none for Phase 8.

recommended_next_state: Phase 9 profiles ready.
