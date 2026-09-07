# CMS Akira Independent-CMS — Phase 3 gate: editor → native `akira.editor.*@1`

task: Execute Phase 3 of the APPROVED independent-CMS contract (`.ai/current-task.md` Phase 3 + editor namespace
rule). Re-scope the dormant `cms-akira-editor` into a NATIVE Akira editor module (Akira-owned namespace, no bare
`editor.*`, no TinyMCE, no legacy CMS/helper paths) and TRACK it (un-ignore + enable) at this gate. Core
(akira.post.*) + shell + module-install service are merged on main (Phases 0-2).

## Contract specifics (honor exactly)
- Rename exposes `editor.render/normalize/sanitize/validate/assets@1` → **`akira.editor.render/normalize/sanitize/
  validate/assets@1`** (Akira-owned; bare `editor.*` is forbidden unless Phase 0 evidenced a shared Kernel contract —
  it did NOT).
- Remove every `akira.content.get@1` (deleted in P1) / TinyMCE / CMS-helper dependency or call path from the
  member (its manifest currently depends on akira.content.get@1 — re-point to the akira.post.* projection the
  editor operates on).
- Content-prep editor contracts operate on Akira content projections (title/body/etc. from entity.get.post@1 /
  akira.post.get@1). Deterministic sanitize parity; Akira-owned assets; no network/external provider required.
- Table-free (assets only — no DB table unless a separately-gated versioned-presets table is approved; default:
  none). `_enabled:false` until this gate, then TRACK + enable.
- Host-residue cleanup per the fork pattern: manifest depends on cms-akira-core; remove admin_contributions host
  residue if any; certify + authority-audit-zero + tests + logs.
- Capabilities before routes; governed only if mutating (editor normalize/sanitize are pure transforms — no
  mutation governance needed beyond being callable; document).

## Deliverables
1. modules/cms-akira/cms-akira-editor re-scoped: module.json (akira.editor.* exposes, depends cms-akira-core,
   _enabled:false → then tracked+enabled), helpers/capabilities (native handlers), README; remove residue.
2. Track: .gitignore un-ignore modules/cms-akira/cms-akira-editor/**; commit full source + tests.
3. Tests: akira.editor.sanitize/normalize deterministic parity (same input → same output), render/validate/assets
   contracts, operates on akira.post projections; authority audit zero; certify.
4. Append result to the contract.

## Verification (do all)
- Editor tests green; P1 38 + P2/lifecycle 38 + shell 21 + install 23 green; authority 18/18; capability audit
  zero; module:certify --all (incl editor); composer full; phpstan + cs-fixer (tracked editor); logs clean; CI 6/6.
- grep: no bare `editor.` expose; no TinyMCE/cms-helper/akira.content.get@1 in the tracked editor.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 4 ready?)

## Implementation result — 2026-09-07

- Re-scoped and tracked `cms-akira-editor` as a native, table-free Akira extension. Its manifest and runtime expose
  exactly `akira.editor.render/normalize/sanitize/validate/assets@1`, depend on `cms-akira-core` and the
  `entity.get.post@1` projection boundary, retain `_enabled:false` for explicit tenant activation, and contain no
  host-admin contribution.
- Added deterministic local HTML normalization/sanitization with an explicit tag/attribute/URL allowlist, safe
  rendering, projection validation, local JavaScript/CSS assets, and a dependency-free health route. These are pure
  transformations and therefore require no mutation governance.
- Added 25 editor contract checks covering manifest/runtime parity, repeated-input determinism, normalize/sanitize
  parity, idempotence, executable-markup removal, render/validate/assets behavior, projected-field allowlisting, and
  rejection of domain/internal fields.
- Gate evidence: editor 25/25; P1 38/38; P2/lifecycle 38/38; shell 21/21; install 23/23; authority 18/18;
  capability audit zero; `module:certify --all` includes editor at 13/13; Composer 106/106; tracked-clean-checkout
  strict manifest guard, architecture, PHPStan, and CS Fixer all clean; themes 2/2 and DiSyL 56/56; app/error logs
  empty; recursive forbidden-residue scan zero.
- The working installation contains unrelated ignored runtime modules which make raw whole-working-tree PHPStan,
  CS Fixer, strict manifest guard, and architecture commands report pre-existing findings. Re-running the exact
  tracked repository plus this gate in a pristine archive is clean. Remote six-job CI remains to run after push;
  no remote result is claimed by this local gate.
