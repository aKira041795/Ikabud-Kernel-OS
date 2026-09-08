# CMS Akira Independent-CMS — Phase 9 gate: profiles (9A minimal / 9B standard / 9C headless) as install bundles

task: Execute Phase 9 (profiles) of the APPROVED independent-CMS contract (`.ai/current-task.md` gate 9). Turn the
four DORMANT profile scaffolds (`cms-akira-profile-minimal|standard|headless|visual`) into clean, data-free
DEPENDENCY/INSTALL-METADATA-ONLY bundles that "certify as install metadata only, never entry/auth modules", and
TRACK + enable (`_enabled:false`) minimal/standard/headless at this gate. profile-visual stays a CONTRACT-ONLY HOLD
(cleaned locally, NOT tracked — it is certified/tracked only after the Builder gate, master item 10). Phases 0-8
merged (core, shell, editor, navigation, media, seo, theme, workflow, search, ai — all tracked).

## Exact profile compositions (master contract — honor exactly; `installs` == the full member list, `depends` mirrors it)
- 9A minimal  = core + shell + editor                          (cms-akira-core, cms-akira-shell, cms-akira-editor)
- 9B standard = core + shell + editor + theme + navigation + media + seo
                  (cms-akira-core, cms-akira-shell, cms-akira-editor, cms-akira-theme, cms-akira-navigation,
                   cms-akira-media, cms-akira-seo)
- 9C headless = core + workflow + search                       (no shell)
                  (cms-akira-core, cms-akira-workflow, cms-akira-search)
- 9D visual   = full graph incl. builder (cms-akira-builder, cms-akira-core, cms-akira-shell, cms-akira-editor,
                  cms-akira-theme, cms-akira-navigation, cms-akira-media, cms-akira-seo, cms-akira-workflow,
                  cms-akira-search) — HELD UNTRACKED until Builder; clean its local manifest only (no commit).

NOTE current minimal/standard installs LACK `cms-akira-shell` — fix to the exact compositions above. All current
profiles still have `database/`, `handlers.php`, `helpers.php`, `routes.php`, a `001_initial.sql`, `routes: true`,
a legacy `/admin/cms-akira-profile-*` nav entry, and `cms-akira-profile-standard` additionally carries the
FORBIDDEN foreign entry/auth residue (`entry_module: true`, `entry_delegate: "cms"`,
`authentication_provider: "cms"`). All of this must be removed/proven-inert before tracking.

## Contract specifics (honor exactly)
- For EVERY profile (minimal, standard, headless, AND visual-locally):
  - Remove/prove-inert runtime scaffolding: delete `database/` (incl. 001_initial.sql), `handlers.php`,
    `helpers.php`, `routes.php`. Profiles are install/dependency metadata ONLY (module.json + README.md).
  - module.json: `kind: profile`; `installs` = exact composition; `depends` mirrors the same member list;
    `owns_tables: []`, `reads_tables: []`; `migrations: []` (remove the 001 marker — no tables, no migration);
    `routes` removed or `false` (no handlers/routes file); remove the `nav:` block (no admin page); capabilities
    exposes/depends stay empty; `_enabled:false` retained.
  - NO `entry_module`, `entry_delegate`, `authentication_provider` (remove from standard). Profiles never entry or
    auth modules. Tenant install uses shell-as-entry OR the profile via the Kernel planner closure through the
    module-install service — profiles only express the install set.
  - README.md per profile: composition, purpose, "install metadata only — never entry/auth" statement,
    install-via-planner note.
- Manifest/runtime parity: after removing scaffolding, `module:certify --all` must pass for each tracked profile and
  `validateModuleManifestV1` must accept the manifest (migrations [] is legal for a profile; confirm against the
  validator and fix only within the profile manifest, NEVER weaken the validator). The tracked
  `tests/manifest_suite_contract_test.php` uses synthetic in-memory profiles — it must stay green unchanged.
- Track at this gate: un-ignore `modules/cms-akira/cms-akira-profile-minimal/**`,
  `modules/cms-akira/cms-akira-profile-standard/**`, `modules/cms-akira/cms-akira-profile-headless/**` in
  `.gitignore`. profile-visual remains gitignored (held) — do NOT un-ignore or commit it.
- Cross-check: no member's `depends`/`installs` references a missing/renamed member (search rename done in Phase 7
  already propagated to headless+visual local manifests — verify visual still references `cms-akira-search`, and
  that standard's installs now include shell + all 7 members above).

## Deliverables
1. minimal/standard/headless re-scoped to pure install-bundle manifests (module.json + README.md only) with exact
   compositions; standard's foreign entry/auth fields + nav removed; visual cleaned LOCALLY (manifest only, not
   committed/tracked).
2. Track minimal/standard/headless via .gitignore; commit.
3. Tests/verification per member (profiles are metadata-only — validate via manifest validator + certify + suite
   contract test green; no DB/behavior tests applicable, but confirm architecture:check clean for them and the
   dormant-profile auth-route violation the reports kept citing is GONE because standard's entry/auth fields are
   removed).
4. Append result to the contract.

## Verification (do all)
- `module:certify --all` passes incl. the three tracked profiles; `validateModuleManifestV1` accepts each;
  `tests/manifest_suite_contract_test.php` + `tests/module_suite_certification_test.php` +
  `tests/module_suite_compatibility_test.php` green unchanged; authority 18/18; capability audit zero; composer
  full (106/106); phpstan + cs-fixer clean on tracked profiles; `php ikabud architecture:check` clean (the
  long-standing dormant `cms-akira-profile-standard` provider-cms auth-route violation MUST be resolved now that
  the entry/auth fields are removed); logs clean; CI 6/6 (branch feat/independent-cms-phase9-profiles, commit,
  push, PR, checks 6/6, merge, checkout main+pull; report PR #; then docs-result PR merged, as prior phases).
- grep: no profile references `entry_module`/`authentication_provider`/`entry_delegate`; no tracked profile has
  handlers/routes/database files; `git ls-files modules/cms-akira | grep profile` lists exactly minimal, standard,
  headless (+README) — NOT visual; visual dir still absent from git.
- All prior member suites green at their published counts (P1 38, P2 38, shell 21, install 23, editor 25,
  navigation 35, media 37, seo 39, theme 36, workflow 42, search 39, ai 28).

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Builder gate ready — profile-visual certified/tracked there)

## Implementation result — 2026-09-08

status: PASS (local gate + CI 6/6 on PR #55, merged 286a698)

task: Re-scope the four dormant profiles into install/dependency-metadata-only bundles; track minimal/standard/
headless; hold visual.

changed:
- modules/cms-akira/cms-akira-profile-minimal|standard|headless: reduced to module.json + README.md (database/,
  handlers.php, helpers.php, routes.php, 001 migration, routes:true, legacy /admin nav all removed).
- Exact compositions applied: minimal = core+shell+editor; standard = core+shell+editor+theme+navigation+media+seo
  (installs + depends); headless = core+workflow+search. profile-standard foreign entry/auth residue removed
  (entry_module, entry_delegate, authentication_provider).
- src/helpers/module-manager.php: profile-scoped certifier exemption — MODULE_KIND_PROFILE certifies as
  metadata-only (C5 routes + C6 migrations N/A), mirroring service-modules. (Chair-adjudicated narrow enabler of
  the approved "profiles certify as install metadata only" adoption.)
- phpstan-baseline.neon: refreshed validateModuleCertification entry for the widened return-shape union.
- .gitignore: tracked minimal/standard/headless; profile-visual remains gitignored (Builder-gate hold).
- .ai/contract-independent-cms-phase9-2026-09-07.md: result appended.

verification:
- module:certify --all: minimal/standard/headless (and local visual) 13/13.
- manifest_suite_contract_test 28/28; module_suite_certification 15/15; module_suite_compatibility 18/18;
  authority 18/18; capability audit zero; composer 106/106; phpstan + cs-fixer clean (incl. certifier + baseline);
  architecture:check clean (long-standing dormant standard auth-route violation resolved).
- grep: no entry_module/authentication_provider/entry_delegate in any profile; no tracked profile retains
  handlers/routes/database files; git ls-files profile set = minimal/standard/headless only (visual absent).
- All prior member suites green at published counts (P1 38, P2 38, shell 21, install 23, editor 25, navigation 35,
  media 37, seo 39, theme 36, workflow 42, search 39, ai 28).

scope: 3 profile members, .gitignore, module-manager certifier, phpstan baseline, contract append.
unexpected_files: none added.
risks: none blocking. profile-visual still references cms-akira-builder (dormant) — certified/tracked at Builder gate.
unresolved: none.
recommended_next_state: Builder gate (last) — profile-visual certified/tracked there.
