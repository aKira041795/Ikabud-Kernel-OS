You are the /implement + /review agent for Ikabud Kernel OS (repo root /var/www/html/ikabudsix, main HEAD 068e801,
Gates 1+2 merged). Execute **Stabilization Gate 3 (FINAL)** per the authoritative contract:

    .ai/contract-stabilization-gate3-http-2026-09-06.md

READ THE CONTRACT FIRST — it is small and authoritative. It folds the 5-round multi-model debate revisions #6
(HTTP outcome envelope — no redundant nested version) and #7 (claim() bounded-wait parameter) and records the
preflight decision: NO kernel HTTP seam consumes Idempotency in this repo; the mobile seam is module-owned
(modules/daily-ledger) → NOT touched (NO-BROADEN; wiring = MAIN-CMS-REPO milestone). Gate 3 in-repo = primitive-level
HTTP-adoption capability, ADDITIVE-ONLY.

## Key context (verified at main 068e801)
- kernel/Http/Idempotency.php — the shared primitive. claim(key, tenantId, payloadHash, ?PDO), commit(key, tenantId,
  outcome, ?PDO), release(...), legacy check()/store() (ZERO in-repo callers). WAIT_CAP_SECONDS=300 /
  LOCK_RETRY_SECONDS=2 hardcoded; no wait parameter. observe() envelope-less handling added in Gate 2.
- canonicalPayloadHash() (Gate 2) is the SOLE canonicalizer — do not add another.
- Tests to keep green: durable_idempotency (30), workflow_engine/concurrency/lifecycle/guard (32/38/12/35/11),
  eventbus_durable_outbox (22), idempotency_canonicalizer (5), capability authority (18) + audit (4).

## Deliverables (within contract scope ONLY)
1. **claim() bounded-wait parameter (revision #7)**: append `?int $waitCapSeconds = null` (null → existing
   WAIT_CAP_SECONDS=300) so WorkflowEngine/EventBus 4-arg call sites are source-compatible and unchanged. A small
   bound (e.g. 2s) yields at most a single GET_LOCK attempt then `in_progress` without executing. Document.
2. **Additive HTTP-adoption surface + outcome contract (revision #6)**: provide a documented, tested path for an HTTP
   adopter to use the primitive: claim key = client Idempotency-Key; fingerprint =
   canonicalPayloadHash(['method'=>uppercase, 'path'=>path-without-query, 'body'=>parsed-body]); on `new` execute +
   commit(key, tenant, ['status'=>int, 'body'=>string, 'headers'=><allowlisted replay metadata>], pdo) — NO redundant
   `version` inside the outcome (envelope already carries it); duplicate → replay $stored['outcome'] without
   execution; conflict → 409 semantics; in_progress → 425 with Retry-After: 2 (documented mapping). Keep it as
   documented primitive usage + tests — do NOT wire any router/handler (none exists at kernel level; module wiring is
   out of scope).
3. **Parsed-body contract**: document (in the code docblock / contract) the JSON/form fingerprinting rules (JSON
   decoded once associatively with JSON_THROW_ON_ERROR; whitespace-only → null; types retained; form-urlencoded →
   string map). Tests prove method/path/body distinctions + body type fidelity. Do NOT change unrelated parsers.
4. **Tests** (plain-PHP, bootstrap.php): tests/idempotency_http_adoption_test.php covering duplicate replay of the
   committed HTTP outcome (status/body/headers, no nested version), changed-payload conflict, bounded-wait
   in_progress (2s bound, single attempt, no execution), 300s default preserved (WorkflowEngine/EventBus unchanged),
   fingerprint distinctions (method/path/body; 1 vs 1.0; list order), tenant isolation, legacy check()/store() +
   keyless unchanged.
5. **Docs**: workflow-system.md:369 note + roadmap/plan notes record Gate 3 resolution + the MAIN-CMS-REPO seam-wiring
   milestone (daily-ledger + mobile POST/PUT seam) + the 425/409/Retry-After adopter mapping.
6. Append result to .ai/contract-stabilization-gate3-http-2026-09-06.md (status → IMPLEMENTED).

## Verification (do all)
- php -l on every touched file.
- New idempotency_http_adoption_test + full regression: durable_idempotency, workflow suite, outbox, canonicalizer,
  capability suites.
- grep: exactly ONE canonicalizer (only Idempotency::canonicalPayloadHash owns the algorithm).
- BOTH storage/logs/app.log + error.log clean after every run. No PHPStan baseline additions. composer test full.
- git diff --stat within allowed scope only (no router/handler/module/daily-ledger changes).

## Constraints
- Bounded repair: max ~3 rounds. This is the FINAL gate — do not broaden. If wiring a seam appears necessary to make
  a test pass, STOP → that is out of scope; tests must be primitive-level.
- Do NOT weaken baselines or the workflow/capability suites.

## Report (compact result block)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification: (test names + pass counts, log status, single-canonicalizer grep)
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
