You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 3 gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main 179c87e — Phases 0-2 merged). Execute Phase 3 per the authoritative
approved contract:

    .ai/contract-independent-cms-phase3-2026-09-07.md   (READ FIRST)
    .ai/current-task.md   (Phase 3 + editor-namespace rule)

Phase 3 = re-scope the dormant `cms-akira-editor` into a NATIVE Akira editor (`akira.editor.*@1`, no bare editor.*,
no TinyMCE/legacy CMS) and TRACK + enable it. Kernel READ-ONLY. cms-akira-core (akira.post.*) + shell + install
service are merged — the editor operates on Akira content projections (entity.get.post@1 / akira.post.get@1).

## Deliverables (contract scope — honor exactly)
1. modules/cms-akira/cms-akira-editor: module.json (exposes → akira.editor.render/normalize/sanitize/validate/
   assets@1; depends cms-akira-core; remove akira.content.get@1 + any TinyMCE/cms-helper residue; _enabled:false
   still, tracked at this gate), capability handlers native, README.
2. Track: .gitignore un-ignore modules/cms-akira/cms-akira-editor/**; commit source + tests.
3. Tests: deterministic sanitize/normalize parity, render/validate/assets, operates on akira.post projections;
   authority audit zero; certify.
4. Append result to the contract.

## Verification (do all)
- Editor tests green; P1 38 + P2/lifecycle 38 + shell 21 + install 23 green; authority 18/18; capability audit
  zero; module:certify --all (incl editor); composer full; phpstan + cs-fixer (tracked editor); logs clean; CI 6/6.
- grep: no bare `editor.` expose; no TinyMCE/cms-helper/akira.content.get@1 in tracked editor.

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
