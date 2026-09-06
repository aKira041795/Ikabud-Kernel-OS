# Stabilization Gate 3 — HTTP idempotency adoption of the shared primitive (ADDITIVE-ONLY, LAST gate)

task: Make the HTTP idempotency path (`kernel/Http/Idempotency.php`) adopt the SAME shared
claim/commit/release primitive additively — payload-conflict states available to HTTP retries, existing keyless /
legacy `check()`/`store()` behavior unchanged, full regression of the HTTP retry semantics — and append the
bounded-wait claim parameter (revision #7).

objective: Mobile POST/PUT retries can use one idempotency model end-to-end: claim key = client Idempotency-Key,
payload fingerprint = canonicalPayloadHash over the request, duplicate replays the committed HTTP outcome, changed
payload → conflict, contention beyond a configurable bound → in_progress (425-style fail-closed). Zero-exception
baselines retained. The actual module/mobile seam WIRING is recorded, not built (NO-BROADEN → MAIN-CMS-REPO).

## Gate 3 preflight RECORD (verified at main 068e801)
- `kernel/Http/Idempotency.php` IS the shared primitive (6.3): claim/commit/release over kernel_idempotency_keys +
  legacy check()/store(). claim(): `claim(string $key, int $tenantId, string $payloadHash, ?PDO $db = null)` with
  hardcoded WAIT_CAP_SECONDS=300 / LOCK_RETRY_SECONDS=2 (no wait parameter — revision #7 target).
- `Idempotency::check()`/`store()` have ZERO in-repo callers (grep-verified across kernel/, src/, modules/, tests/,
  public/). "Preserve all existing check()/store() callers unchanged" is trivially satisfied.
- NO kernel HTTP middleware seam consumes Idempotency in this repo. The mobile/offline retry seam is MODULE-OWNED:
  modules/daily-ledger/ handlers.php/offline/pos read `idempotency_key` from the JSON body and use their OWN replay
  helper (dl_loadIdempotentResponse + POS-local logic), NOT the kernel primitive.
- DECISION (recorded): because module adoption is a MAIN-CMS-REPO milestone (NO-BROADEN, stabilization plan), Gate 3
  in THIS kernel repo is scoped to the primitive-level HTTP-adoption capability + bounded-wait parameter + outcome
  contract + full primitive regression. The daily-ledger seam is NOT touched here. Wiring = main-CMS-repo adoption.

scope:
  allowed:
    - kernel/Http/Idempotency.php — additive changes only
    - tests/ — new/updated primitive-level HTTP-adoption + bounded-wait tests
    - docs — workflow-system.md:369 note + roadmap/plan notes record Gate 3 resolution + seam decision
    - .ai — this contract; stabilization plan Gate 3 status
  prohibited:
    - NO wiring into any router/handler/middleware seam in this repo (no seam exists at kernel level; module seams
      are out of scope by NO-BROADEN)
    - NO change to legacy check()/store() behavior or callers (none exist, but keep them as-is)
    - NO change to WorkflowEngine/EventBus claim() call sites' behavior (they must stay source-compatible)
    - NO second canonicalizer, no schema/DDL, no cross-module access
    - NO conflict-state schema churn; status ENUM stays processing/completed

constraints:
  - REVISION #7 (bounded wait): append a claim() wait parameter bounding the TOTAL wait (deadline), e.g.
    `claim(string $key, int $tenantId, string $payloadHash, ?PDO $db = null, ?int $waitCapSeconds = null)` where
    null → existing WAIT_CAP_SECONDS=300 (source-compatible with WorkflowEngine/EventBus, which pass 4 args). A small
    bound (mobile 2s) must yield at most a single GET_LOCK attempt then return `in_progress` without executing.
    WAIT_CAP_SECONDS / LOCK_RETRY_SECONDS stay as the default budget. Document the parameter.
  - REVISION #6 (outcome contract): commit() already writes the envelope `_kernel_idempotency` (which carries the
    version). For HTTP adopters the OUTCOME value is the replayable response: `['status' => int,
    'body' => string (original body), 'headers' => list/array of allowlisted replay metadata]` — with NO redundant
    nested `version` inside the outcome. Provide the documented outcome contract + tests (no HTTP caller changes).
  - ONE canonicalizer stays `Idempotency::canonicalPayloadHash()` (sole). HTTP request fingerprint =
    `canonicalPayloadHash(['method' => uppercase, 'path' => path without query, 'body' => parsed body])`. No second
    canonicalizer.
  - Parsed-body contract (documented + tested at the primitive level, NOT wired): application/json decoded once,
    associative, JSON_THROW_ON_ERROR; whitespace-only → null; arrays/objects/scalars/null retain types; form-urlencoded
    → string-valued map; multipart/other → out of scope for fingerprinting. Parser incompatibility does NOT change
    unrelated callers.
  - Legacy compatibility: envelope-less rows already observe in_progress/duplicate (Gate 2); legacy check()/store()
    continue to work unchanged; keyless requests unaffected.
  - Fail-closed semantics (documented): uncertain execution/outcome commit remains processing; only certain
    pre-side-effect failure releases. Duplicate replays `$stored['outcome']`; conflict → 409 semantics;
    in_progress → 425 with Retry-After: 2 (documented mapping, enforced at the primitive/consumer contract level).

acceptance:
  - claim() accepts a bounded wait: with a 2s bound and an actively-held lock (or an unowned processing row), it
    returns `in_progress` within ~the bound (single lock attempt), never executing; the 300s default is unchanged and
    WorkflowEngine/EventBus callers compile + pass unchanged.
  - An additive HTTP-adoption surface is demonstrated by tests: duplicate request (same key + same canonical
    fingerprint) replays the committed outcome (status/body/allowlisted headers) without re-execution; changed payload
    (same key, different body) → conflict; first request claims + commits outcome.
  - Legacy check()/store() + keyless behavior unchanged (tests present); canonicalPayloadHash(['method','path',
    'body']) distinguishes method/path/body; body type distinctions (1 vs 1.0, list order) remain significant.
  - All existing suites still green (durable_idempotency 30, workflow 32/38/12/35/11, outbox 22, canonicalizer 5,
    capability 18/4); zero new findings in both logs; single canonicalizer grep holds; no PHPStan baseline additions.
  - Docs record: (a) Gate 3 primitive-level resolution, (b) the main-CMS-repo seam-wiring milestone (daily-ledger +
    mobile POST/PUT seam adopting this surface), (c) the 425/409/Retry-After mapping for adopters.

verification:
  - new tests: tests/idempotency_http_adoption_test.php (duplicate replay, conflict, bounded-wait in_progress,
    outcome contract w/o redundant version, method/path/body fingerprint distinctions, tenant isolation, legacy
    check/store unchanged) — plain-PHP style requiring bootstrap.php.
  - `php tests/idempotency_canonicalizer_test.php`, `durable_idempotency_test.php`, `eventbus_durable_outbox_test.php`,
    workflow suite, capability suites.
  - grep single canonicalizer; BOTH logs clean; `composer test` full at end.

risk:
  - LOW-MEDIUM. All changes additive to the primitive; no seam wiring, no DDL, no caller behavior change. The main
    risk is scope creep toward module wiring, which the prohibited list blocks.

unresolved:
  - none in-repo. Real seam wiring is the MAIN-CMS-REPO adoption milestone (recorded, not built here).

status: IMPLEMENTED

## Implementation result (2026-09-06)
- `Idempotency::claim()` now appends `?int $waitCapSeconds = null`; null retains the 300-second default and a cap at
  or below the two-second retry interval makes at most one advisory-lock attempt before returning `in_progress`.
- The class-level HTTP adopter contract records the sole fingerprint recipe, parsed JSON/form rules, replay outcome
  (`status`/`body`/allowlisted `headers`, no nested version), 409/425 + `Retry-After: 2` mapping, and fail-closed release
  discipline. No router, handler, module, parser, schema, WorkflowEngine call site, or EventBus call site was changed.
- `tests/idempotency_http_adoption_test.php` proves first claim/commit, exact duplicate replay without execution,
  conflict, bounded contention, tenant isolation, fingerprint/type fidelity, default compatibility, and unchanged
  legacy/keyless behavior.
- Real daily-ledger and mobile POST/PUT seam wiring remains the recorded MAIN-CMS-REPO milestone.
