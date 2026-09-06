# PHASE 3 (ARK) + PHASE 5 (ARK sliver) — RECORD ONLY — main CMS app repo (2026-09-06)

status: BLOCKED / WRONG-REPO (architectural record — not implementable in this workspace)

## Why
Per the master roadmap contract (`.ai/kernel-6x-roadmap-contract-2026-09-06.md`), Phase 3 (ARK authority layer +
renderer breadth on hardened Entity view) and the ARK portion of Phase 5 (parallel visible sliver) target the CMS
**ARK theme** at `storage/cms-themes/ark/` with its `renderer-registry.json` (27 renderer declarations), the
theme-owned customizer, and the ARK authority-layer plan (`docs/themes/ark-authority-layer-plan.md`).

Verified 2026-09-06: this workspace (`/var/www/html/ikabudsix`, Ikabud-Kernel-OS barebones kernel) has
`modules/` = gui-settings only and NO `storage/cms-themes/` tree. The ARK theme and full CMS surface live in the
main application repo (per repo memory: aKira041795/Ikabud-CMS-Kernel / application repo). ARK renderers are
Entity-view ADOPTION VEHICLES and consume the hardened entity view from Phase 2 — they cannot be built or tested
here.

## Contract draft for the main app repo (when that workspace is opened)
- Phase 3 objective: after Phase-2 render-path caching lands, extend ARK renderer breadth + authority layer on the
  hardened Entity view; certify renderers against entity-view contract; keep renderer-registry.json in sync.
- Phase 5 (ARK sliver) objective: a small, demo-able ARK surface each cycle; non-blocking.
- Acceptance skeleton: renderer-registry count grows; each renderer certified by theme:validate/inspect;
  Playwright storefront journeys (PW-2/PW-3) green on the main app repo.
- Delegation note: implement+review on Codex sol via the Pi harness, same governed loop as Phases 1/2/4.

## Phase 5 Workbench sliver (implementable HERE — folded into Phase 4)
The non-ARK half of Phase 5 (keep the developer surface visible each cycle) is satisfied in this kernel repo by the
Phase-4 Workbench auditor + existing `/superadmin/workbench` IssueLedger UI surfacing its findings. No separate
contract needed; Phase 4 covers it.

status: BLOCKED until the main CMS application repo is opened as a workspace. Do NOT fabricate ARK files here.
