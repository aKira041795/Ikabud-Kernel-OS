# CONTRACT — Make the builder spec tell the truth, and fix the baseURL that sent it to the wrong host

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — work in the current tree; do not create or switch branches

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: owner directive 2026-09-14 (CD-8, `.ai/chair-decisions.md`) — *"if the model, using the harness, can
identify the problem and provides options, therefore it can answer the issues that surfaced, therefore it is
allowed to redefine the context within the scope."*
directive: **you hold the decision.** The block flow, the route the assertions belong on, and the repair of
every impossible assertion are IN-CONTRACT and yours to choose. Do not stop to ask. Enumerate your options,
choose one, record why, and finish the work. If you find yourself writing *"this would be authoring rather
than repairing"*, re-read CD-8 — that is a named defect, not a reason to stop.

## Objective

`tests/browser/akira-builder-admin.spec.ts` has never been able to pass. It was written against an imagined
UI, and it has never executed past line 79, so everything after that point — save, preview, validate, publish
— has never run. Make it assert the UI that actually shipped, over the journey it was always meant to prove,
and remove the configuration fault that made its navigation land on the wrong host.

## Verified facts — measured, do not re-derive

1. **The spec asserts `"Composition editor"` on the list route.** `app.tsx:52` selects the panel from the
   server-provided `boot.mode`; list mode renders `<h2>Compositions</h2>` (`app.tsx:80`) and
   `"Composition editor"` exists **only** in `EditPanel` (`app.tsx:435`). The string cannot appear on that
   route. *(Repaired by the previous run; keep the repair and record it.)*
2. **The suite's only relative `page.goto()` is in this spec.** `.env` sets `APP_URL=http://ikabudsix.test`
   (the **kernel** host) and the config resolves `baseURL: APP_URL || TENANT_URL`, so a relative `goto`
   resolves to the kernel host → **404** → no builder root. Every other spec uses absolute URLs.
   *(Worked around by the previous run; the **root** fix is deliverable S2.)*
3. **The spec clicks `button:has-text("+ heading")` and no heading block exists.** The active theme exposes
   only `hero`, `richtext`, `card-grid`, `quote`, `cta` (`GET /api/v1/cms-akira-theme/blocks`), and the
   control renders `+ Add {label}` (`app.tsx:497`). **Unfixed — this is yours.**
4. **All four defects came from one commit**, `a6c76f5` (Phase 10B), which introduced the spec and the
   list/edit split together. This is not staleness; the assertions have been unsatisfiable since authorship.
5. The builder's admin-role guards were repaired in the previous run via `cabBuilderIsAdminActor()` reusing
   the canonical tier. **That work is done and verified — do not touch it again.**

## Deliverables

### S1 — Rewrite the spec against the shipped UI

Exercise the journey the spec was always meant to prove, end to end:

```
open the builder (list) → open/create a composition → add real blocks → save draft
  → preview / validate → publish → the published state is visible
```

- Choose the block flow **yourself** from the blocks that exist (fact 3). A heading section may be
  representable by `richtext` or `hero` — decide, use it, and state which real block you chose and why.
- Assertions must sit on the route that truly renders the thing being asserted (fact 1).
- Use resilient locators — `getByRole`, `getByLabel`, `getByTestId`, `getByText` — over deep CSS chains or
  `nth-child`.
- Where the original spec asserted something the UI never did, fix the assertion to the shipped behaviour
  **and** preserve the intent. If the UI genuinely cannot complete a step of the journey, that is a product
  finding (S4), not something to assert around.

### S2 — Fix the baseURL precedence at the root

`playwright.config.js` prefers `APP_URL` while its own comment says the default should be *"the tenant host
these specs actually drive"*. Make the configuration agree with its stated intent, so a relative navigation
lands on the tenant host. Then prove it: a relative `goto('/cms-akira-shell/...')` must reach the tenant, and
**the whole suite must still pass** — this config is shared, so a regression elsewhere is a failure of S2, not
an unrelated inconvenience.

### S3 — Change report with evidence

For every assertion you alter, report: the original assertion · the artifact fact that contradicts it with
`file:line` · why it cannot hold as written · what it now asserts · the user-observable outcome it still
proves. This is the audit trail that distinguishes repair from tuning-until-green, and it is the accepted
form of "redefining the context within the scope".

### S4 — Product findings are recorded, never absorbed

If a step of the journey is impossible because the **product** is wrong — a control that cannot be reached, a
route that 404s for a legitimate key, a publish that does not publish — record it as a finding with evidence.
**Do not adjust the check to agree with a defect.** A green suite that implies a journey works when it does
not is worse than a red one.

### S5 — Robustness, not just green

The spec must not be flaky-by-construction: no arbitrary `waitForTimeout`, no assertion that passes because
the page happened to be empty. Prefer retryable assertions and Playwright auto-waiting. If a step is genuinely
timing-dependent, say why.

## Architectural constraints

- **Never weaken a verification.** No `test.skip`, `test.fail`, `test.describe.skip`, no deleted journey
  steps, no relaxing an assertion to something weaker than the original intent, no trimming the journey to
  the part that passes. Repair and weakening are different acts; S3 is where you evidence the difference.
- Do **not** change product UI to satisfy the test. If the UI is wrong, S4.
- Do not touch the builder's authorization guards or any role semantics — repaired and verified already.
- Login **once per role per run**: the kernel auth limiter returns `429` after repeated
  `POST /api/v1/auth/login`. Report the `auth.login_rate_limited` count for your run (expect `0`).
- Prefer Playwright over curl for UI journeys. The suite must stay headless in CI (`PW_HEADED` unset).
- If a path you need is not in `allowed_scope`, **report it rather than adding it silently**.
- No new dependency, no schema change, no network beyond the local tenant.

## Files likely affected

- `tests/browser/akira-builder-admin.spec.ts` — the rewrite (S1)
- `playwright.config.js` — baseURL precedence (S2)
- `tests/browser/` — shared helpers or fixtures, only if genuinely required
- `docs/testing/` — only if an existing doc contradicts the shipped behaviour

## Acceptance criteria

1. The full Playwright suite passes, and the report states total / passed / failed, with real output.
2. `akira-builder-admin.spec.ts` exercises its journey **past line 79** — specifically save, validate and
   publish — and asserts the published state. Report the step list that actually executed.
3. A relative `page.goto()` in the spec reaches the **tenant** host; show the URL and the HTTP status.
4. Every changed assertion appears in the S3 table with `file:line` evidence for why the original could not hold.
5. Zero tests added to a skip list, zero `test.fail`, zero journey steps removed. Show the spec diff stat and
   state plainly that nothing was weakened.
6. `grep -c 'auth.login_rate_limited' storage/logs/app.log` for the run, and `error.log` gains no product errors.
7. Any product finding from S4 is reported with evidence.

## Required tests

- `npx playwright test --reporter=list` — full suite, with the pass/fail totals and the exit code.
- The builder spec run alone, with its executed steps listed.
- The relative-navigation proof for S2 (URL + status), ideally as an assertion inside the spec.
- `git diff --stat` for the spec and the config, plus `git diff` for `playwright.config.js` (small enough to show).
- `node -e "require('./playwright.config.js')"` or equivalent to prove the config still loads.

## Risks

- The spec is shared infrastructure: changing `playwright.config.js` affects every spec. The full-suite run is
  the control, not a formality.
- The temptation to make publish pass by asserting something weaker is exactly the failure this contract is
  written to prevent. If publish cannot be verified, report it as a product finding and keep the assertion
  honest rather than green.
- The journey may legitimately require a published-capable post to attach to (the original spec's comment at
  line 68 mentions one but never creates it). Creating that fixture is in scope; make it deterministic and
  clean up after itself.

## Forbidden changes

- `modules/cms-akira/cms-akira-builder/helpers.php`
- `modules/cms-akira/cms-akira-builder/handlers.php`
- `modules/cms-akira/cms-akira-builder/admin-ui/src/`
- `modules/cms-akira/cms-akira-shell/`
- `kernel/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
- `.github/workflows/`
