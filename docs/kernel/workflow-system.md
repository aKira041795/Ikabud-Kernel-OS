# Workflow System

**Subsystem:** `kernel/WorkflowRuntime.php` + `kernel/WorkflowEngine.php`  
**Status:** Production  
**Last updated:** 2026-09-06
**Version:** `WorkflowRuntime` 1.0.0 · `WorkflowEngine` 1.1.0

## Overview

The Workflow Runtime provides a state-machine engine for multi-step business processes. It manages workflow definitions, state transitions, guard evaluation, action execution, and event emission. Modules register as callers to define and operate workflows.

## Core Class

### WorkflowRuntime

`kernel/WorkflowRuntime.php`

The WorkflowRuntime operates as a **capability-driven state machine** — all state queries and transitions go through the capability bus (`workflow.state.get@1` and `workflow.transition@1`), which delegate to `stateGet()` and `transition()`. Both methods accept a single payload array (validated against JSON schemas) and return an `['ok' => bool, ...]` response array.

```php
$wf = app()->workflow();

// Define a workflow (idempotent — safe to call on every boot)
$wf->ensureDefinition(
    workflowKey: 'order_fulfillment',
    module: 'ecommerce',
    entityType: 'order',
    initialState: 'pending',
    states: [
        ['key' => 'pending', 'label' => 'Pending'],
        ['key' => 'processing', 'label' => 'Processing'],
        ['key' => 'shipped', 'label' => 'Shipped'],
        ['key' => 'delivered', 'label' => 'Delivered'],
        ['key' => 'cancelled', 'label' => 'Cancelled'],
    ],
    transitions: [
        ['from' => 'pending', 'action' => 'process', 'to' => 'processing', 'roles' => ['admin', 'superadmin']],
        ['from' => 'processing', 'action' => 'ship', 'to' => 'shipped', 'roles' => ['admin', 'superadmin']],
        ['from' => 'shipped', 'action' => 'deliver', 'to' => 'delivered', 'roles' => ['admin', 'superadmin']],
        ['from' => ['pending', 'processing'], 'action' => 'cancel', 'to' => 'cancelled', 'roles' => ['admin', 'superadmin']],
    ],
);

// Execute a transition via capability bus (payload-driven)
$result = app()->cap()->call('workflow.transition@1', [
    'workflow_key' => 'order_fulfillment',
    'module' => 'ecommerce',
    'entity_type' => 'order',
    'entity_id' => (string)$orderId,
    'action' => 'process',
]);
// → ['ok' => true, 'from' => 'pending', 'to' => 'processing']

// Query state via capability bus
$state = app()->cap()->call('workflow.state.get@1', [
    'workflow_key' => 'order_fulfillment',
    'module' => 'ecommerce',
    'entity_type' => 'order',
    'entity_id' => (string)$orderId,
]);
// → ['ok' => true, 'workflow' => ['state' => 'processing', 'allowed_actions' => [...]]]
```

### Constructor

```php
new WorkflowRuntime(App $app)
```

Requires the `App` instance for database access and event emission.

### Registered Callers

Callers are module identifiers authorized to define and execute workflows. Registration is dynamic via `registerCaller()`.

**Default callers:** `cms`, `guidance`, `workflow`, `kernel`

```php
$wf->registerCaller('ecommerce');
```

### Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `registerCaller` | `(string $caller): void` | Register a module as authorized workflow caller |
| `ensureDefinition` | `(string $workflowKey, string $module, string $entityType, string $initialState, array $states, array $transitions): void` | Register/update workflow definition (idempotent, cache-aware) |
| `getDefinition` | `(string $workflowKey, string $module, string $entityType): ?array` | Fetch definition row from DB |
| `transition` | `(mixed $payload): array` | Execute state transition via capability payload. Required keys: `workflow_key`, `module`, `entity_type`, `entity_id`, `action`. Returns `['ok' => bool, ...]` |
| `stateGet` | `(mixed $payload): array` | Get current state + allowed actions. Required keys: `workflow_key`, `module`, `entity_type`, `entity_id`. Returns `['ok' => bool, 'workflow' => [...]]` |
| `getOrCreateInstance` | `(string $workflowKey, string $module, string $entityType, string $entityId, string $defaultState): ?array` | Fetch or auto-create a workflow instance row |
| `allowedActions` | `(array $definition, string $state, ?string $role, array $guardContext = []): array` | Compute allowed transitions from current state for a role |
| `ensureCmsContentWorkflow` | `(): void` | Convenience: define the `cms.content` workflow (draft→review→approved→published) |

> **Note:** There is no `can()` or `history()` method on WorkflowRuntime. Use `stateGet()` to get `allowed_actions` for the current state (equivalent to `can()`). History tracking is via `workflow.transitioned` events emitted on each transition — subscribe via EventBus if you need audit trails.

### Workflow Definition Format

Definitions are stored in the `workflow_definitions` table and synced via `ensureDefinition()`. States and transitions are passed as arrays of associative arrays (not keyed maps).

```php
$wf->ensureDefinition(
    'order_fulfillment',       // workflow_key
    'ecommerce',                // module
    'order',                    // entity_type
    'pending',                  // initial_state
    [                           // states: list of {key, label}
        ['key' => 'pending', 'label' => 'Pending'],
        ['key' => 'processing', 'label' => 'Processing'],
        ['key' => 'shipped', 'label' => 'Shipped'],
        ['key' => 'delivered', 'label' => 'Delivered'],
        ['key' => 'cancelled', 'label' => 'Cancelled'],
    ],
    [                           // transitions: list of {from, action, to, roles?, guard?}
        ['from' => 'pending', 'action' => 'process', 'to' => 'processing', 'roles' => ['admin', 'superadmin']],
        ['from' => 'processing', 'action' => 'ship', 'to' => 'shipped', 'roles' => ['admin', 'superadmin']],
        ['from' => 'shipped', 'action' => 'deliver', 'to' => 'delivered', 'roles' => ['admin', 'superadmin']],
        ['from' => ['pending', 'processing'], 'action' => 'cancel', 'to' => 'cancelled', 'roles' => ['admin', 'superadmin']],
    ],
);
```

**Guard types** (evaluated in `evaluateGuard()`):
- **callable**: `'guard' => fn($ctx) => $ctx['inventory'] > 0` — invoked with guard context, must return truthy
- **string**: `'guard' => 'functionName'` — resolved as global function name
- **declarative**: `'guard' => ['field' => 'status', 'operator' => 'eq', 'value' => 'paid']` — operators: eq, neq, in, not_in, gt, gte, lt, lte, empty, not_empty
- **absent**: no guard key = always passes

### Transition Flow

```
transition(payload) → capability bus → WorkflowRuntime::transition(payload)
       ↓
1. Validate payload schema (workflow_key, module, entity_type, entity_id, action)
2. Load workflow definition from DB
3. Get or create instance row (auto-creates at initial_state if missing)
4. Resolve caller identity + role
5. Build guard context from payload.guard_context + system state
6. Compute allowed_actions for current state + role (with guard evaluation)
7. Find matching action → get target state
8. UPDATE workflow_instances with optimistic concurrency (WHERE state = expected_from)
9. Emit event: 'workflow.transitioned' via EventBus
10. Return ['ok' => true, ...] or ['ok' => false, 'error' => '...']
```

### Database Resilience

The WorkflowRuntime is fault-tolerant regarding database availability:
- If the database table does not exist, operations degrade gracefully
- State queries return `null` for unknown subjects
- Transition failures return `['success' => false, 'reason' => '...']`

### Events

Every successful transition emits an event via `EventBus`:

```
workflow.order_fulfillment.process  → {subjectId, from: 'pending', to: 'processing'}
workflow.order_fulfillment.ship     → {subjectId, from: 'processing', to: 'shipped'}
```

Modules can subscribe to these events for side effects (notifications, logging, cascading workflows).

---

## WorkflowEngine — Multi-Step Runner (New in 6.1)

**File:** `kernel/WorkflowEngine.php` | **Version:** 1.1.0

The `WorkflowEngine` extends the single-transition `WorkflowRuntime` with
**multi-step ordered workflows** defined in YAML. Each step can be a validate,
transition, notify, webhook, or export action.

### Architecture

```
WorkflowEngine (multi-step runner)
    └── WorkflowRuntime::ensureDefinition() — syncs YAML to DB
            └── WorkflowRuntime::transition() — single state transitions

EventBus events → WorkflowEngine::handleEvent()
    → Auto-starts workflows with matching subscriptions
    → Steps execute in order defined in YAML
```

### Usage

```php
$engine = app()->workflowEngine();

// Load YAML definitions from a module's workflows/ directory
$engine->loadDefinitions('/path/to/modules/reports/workflows', 'reports');

// Start a workflow run
$run = $engine->start(
    workflowKey: 'report-approval',
    module: 'reports',
    payload: ['report_id' => $reportId, 'initiator' => $userId],
    entityType: 'report',
    entityId: (string)$reportId,
);
// → ['ok' => true, 'run_id' => 42]

// Advance to next step (auto-invoked by start() if steps exist)
$engine->advance($run['run_id']);

// Cancel a run
$engine->cancel($run['run_id'], 'Cancelled by user');

// Replay from a specific step
$engine->replay($run['run_id'], 'validate');

// Subscribe to events for auto-start
$engine->subscribe('reports', 'report.export.requested', 'report-approval', ['format' => 'pdf'], 'report');
$engine->handleEvent('report.export.requested', ['id' => $reportId]);
```

### Workflow YAML Format

Workflow YAML files live in `modules/<id>/workflows/*.yaml`. The `loadDefinitions()` method parses them with a minimal line-based parser and syncs definitions to the `workflow_definitions` table. The parser expects this structure:

```yaml
key: report.approval
label: Report Approval
entity_type: report_export
initial_state: pending

# Optional: auto-start trigger via EventBus
trigger:
  event: report.export.requested
  filter:
    requires_approval: "1"

states:
  - key: pending
    label: Pending Review
  - key: approved
    label: Approved
  - key: rejected
    label: Rejected
  - key: delivered
    label: Delivered
  - key: failed
    label: Failed

transitions:
  - from: pending
    action: approve
    to: approved
    roles: [administrator, superadmin]
  - from: pending
    action: reject
    to: rejected
    roles: [administrator, superadmin]
  - from: approved
    action: deliver
    to: delivered
    roles: [administrator, superadmin]
  - from: failed
    action: retry
    to: pending
    roles: [administrator]

# Multi-step capability execution (optional)
steps:
  - key: validate_request
    label: Validate Export Request
    capability: report.export.validate@1
    max_attempts: 1
    args:
      export_source: "{payload.export_source}"
      export_format: "{payload.export_format}"
  - key: generate_report
    label: Generate Report
    capability: report.export.generate@1
    max_attempts: 3
    args:
      export_source: "{payload.export_source}"
      export_format: "{payload.export_format}"
      title: "{payload.title}"
```

**Key differences from the old doc format:**
- Top-level key is `key:` (not `name:`)
- Steps use `key:` (not `id:`) and `capability:` (not `type:`)
- Auto-start is configured via `trigger:` with `event:` and optional `filter:`, not an `events:` section
- Step args support `{payload.*}` placeholder resolution

### Multi-Step Execution

When a YAML definition includes `steps:`, the `WorkflowEngine` creates `workflow_run_steps` records on `start()`. Each step references a capability ID — the engine calls it through the capability bus (`app()->cap()->call()`). Steps execute sequentially; if a step fails and `max_attempts` is > 1, it retries up to that limit before marking the run as failed.

Step argument values support `{payload.*}` references which are resolved against the run's original payload at execution time.

### Concurrency and Delivery Idempotency

`advance()` uses a short claim transaction:

1. Lock the `workflow_runs` row with `SELECT ... FOR UPDATE`.
2. Refuse the claim if another step for the run is already `running`.
3. Select the next `pending` or `failed` step.
4. Atomically claim it with a guarded `UPDATE ... WHERE status IN ('pending', 'failed')`, including `attempt = attempt + 1`; exactly one affected row is required.
5. Commit the transaction **before** dispatching the capability.

The database lock therefore protects only selection and claim. It is never held across a potentially long capability call. A competing `advance()` (including a lock timeout/deadlock while claiming) returns:

```php
['ok' => false, 'run_id' => $runId, 'run_busy' => true, 'error' => 'run_busy']
```

`cancel()` and `replay()` use the same run-row guard. If a step is currently `running`, both operations are refused with the same `run_busy` shape; neither can clear or reset an in-flight marker. This is the intentionally minimal cancellation semantic: callers retry cancellation only after the capability settles. Otherwise replay's reset commits before `advance()` claims the replayed step.

Dispatch and completion persistence are separate phases. Capability exceptions retain the normal `failed`/`retry_pending` behavior. Once dispatch returns, a completion-write failure changes the step to non-retryable `interrupted` when possible (or leaves it `running` if even that write fails). `advance()` and `replay()` refuse either fail-closed state, so an uncertain side effect is never dispatched again automatically.

`start()` deduplicates active runs by the tuple `(workflow_key, module, entity_type, entity_id)`. A tuple-scoped MySQL advisory lock (`GET_LOCK`/`RELEASE_LOCK`) on the same connection as the creation transaction serializes even an empty index range without DDL and is compatible with MySQL 5.7. A contender re-reads after acquiring the mutex (and once more after a lock timeout). If a matching `pending` or `running` run already exists, no row or steps are created and the existing identifier is returned without re-advancing it:

```php
[
    'ok' => true,
    'run_id' => $existingRunId,
    'status' => 'pending', // or 'running'
    'deduplicated' => true,
]
```

This keeps the existing successful `start()` contract while making duplicate `handleEvent()` delivery converge on one active run. A completed, failed, or cancelled run does not block a later start for the same subject. If the advisory mutex times out and no committed winner can be read, `start()` fails explicitly with `error: run_creation_busy`.

Capability dispatch uses the canonical CapabilityBus path, `app()->cap()->call()`. `App::capabilities()` exposes the registration/inspection registry and intentionally has no `call()` method.

#### Workflow/capability correlation

Every WorkflowEngine step dispatch supplies an evidence-only `correlation_id` in this stable format:

```text
wf:run:<workflow_runs.id>:step:<workflow_run_steps.id>
```

The same value is written in the WorkflowEngine step-completed log context and the CapabilityBus `capability.call` context, allowing one workflow step and its capability dispatch to be found as a causal pair in `app.log`. Capability schema-violation and denied logs also preserve a supplied correlation ID. The option does not set `caller_user`, alter authorization or provider selection, or require database persistence. Direct, non-workflow capability calls remain compatible and have a null/absent correlation value unless their caller explicitly supplies one.

The generated `workflow_run_steps.idempotency_key` remains a trace identifier (`step_<step-key>_<run-id>_<ordinal>`). It is not the caller's durable key and does not replace the external-key contract below.

#### Durable external idempotency key

`start()` accepts an optional sixth argument, `externalKey`. Keyless callers use the unchanged active-run path and unchanged return shape. A keyed caller must have a positive current tenant (`app()->tenant()->current()`):

```php
$result = $engine->start(
    'report-approval',
    'reports',
    ['report_id' => 42, 'format' => 'pdf'],
    'report',
    '42',
    'client-request-0187',
);
```

The first call claims `(SHA-256(externalKey), tenant_id)` in `kernel_idempotency_keys`, executes the workflow, and commits a versioned envelope containing the run outcome. It returns `deduplicated: false`, `run_id`, and a persisted `result` snapshot. A duplicate with the same normalized invocation returns that same `run_id` and `result`, adds `deduplicated: true`, and creates or executes nothing. Reuse with a different normalized invocation fails explicitly:

```php
['ok' => false, 'conflict' => true, 'error' => 'idempotency_payload_conflict']
```

Normalization is deterministic. The hashed invocation contains `workflow_key`, `module`, normalized empty-string `entity_type`/`entity_id`, and `payload`. Associative keys are sorted lexicographically at every depth; list order is preserved; objects are normalized through their public properties; scalar and null JSON types are preserved. It is encoded with unescaped Unicode/slashes and preserved zero fractions, then SHA-256 hashed. Therefore associative key ordering and JSON whitespace do not conflict, while list ordering, value types, workflow identity, subject identity, or values do.

The shared primitive is `Ikabud\Kernel\Http\Idempotency::claim/commit/release`. Migration 011 supplies the required unique `(idempotency_key_hash, tenant_id)` index, so no DDL or missing-index fallback is needed. The unique insert is the atomic claim; a MySQL-5.7-compatible advisory lock serializes concurrent publication. A contender uses short `GET_LOCK` waits and re-reads the row between waits, so workflow duration is not limited by one lock timeout and a live loser deterministically observes the winner's committed outcome.

The wait has a documented five-minute operational safety cap. Reaching it, or acquiring an otherwise unowned lock while an existing row is still `processing`, returns the distinct fail-closed result `['ok' => false, 'in_progress' => true, 'error' => 'idempotency_in_progress']`; it never reclaims the row or executes again. Pre-execution failures may release a processing claim only from the connection that owns its exact advisory lock. `commit()` and `release()` verify ownership with `IS_USED_LOCK(...) = CONNECTION_ID()` and use a guarded `status = 'processing'` mutation; a non-owner receives `false` and cannot alter the claim. A post-execution commit uncertainty stays fail-closed and returns `idempotency_commit_failed`, rather than making a possibly executed side effect retryable.

The legacy HTTP `check/store` API remains compatible and its envelope-less rows now observe safely: processing is `in_progress`, while completed plain JSON is replayed as a duplicate and never misclassified as a payload conflict. `EventBus::fireDurable` is now converged on this primitive for caller-supplied keys and writes both keyed and keyless events to the Kernel-owned, tenant-local `kernel_durable_event_outbox`; keyed calls require an autocommit caller PDO, hash the normalized event envelope with the same canonicalizer, and replay the committed outbox row ID.

Stabilization Gate 3 resolves HTTP adoption at the additive primitive level. An adopter uses the client `Idempotency-Key`, calls `canonicalPayloadHash(['method' => strtoupper($method), 'path' => $pathWithoutQuery, 'body' => $parsedBody])`, and may pass a two-second fifth argument to `claim()`; omitted/null retains the 300-second WorkflowEngine/EventBus default. JSON is decoded once associatively with `JSON_THROW_ON_ERROR` (whitespace-only becomes null and JSON types are retained); form-urlencoded input is a string-valued map. A new claim executes and commits `['status' => int, 'body' => string, 'headers' => allowlisted replay metadata]`, without a nested version. Duplicate replays that outcome without execution; conflict maps to `409 idempotency_payload_conflict`; in-progress maps to `425 idempotency_in_progress` with `Retry-After: 2`. Hop-by-hop and `Set-Cookie` headers must never enter the replay allowlist. Claims are released only for failures certainly before all side effects; uncertainty remains processing.

No kernel router/handler seam exists here. Wiring the `modules/daily-ledger` and mobile POST/PUT seam to this surface is a **MAIN-CMS-REPO milestone**, not part of this additive Kernel gate; keyless behavior remains unchanged.

`WorkflowRuntime` is not coupled to this guard. It stores state-machine subjects in `workflow_instances`, not `workflow_runs`, and retains its independent optimistic transition behavior.

### Methods

| Method | Signature | Purpose |
|---|---|---|
| `loadDefinitions` | `(string $moduleDir, string $moduleId): array` | Scan `modules/<id>/workflows/*.yaml` and sync to DB. Returns list of loaded workflow keys. |
| `start` | `(string $workflowKey, string $module, array $payload = [], ?string $entityType = null, ?string $entityId = null, ?string $externalKey = null): array` | Start a run; an external key adds durable tenant-scoped result reuse and conflict detection |
| `advance` | `(int $runId): array` | Atomically claim and execute the next step; returns `run_busy: true` on contention |
| `cancel` | `(int $runId, string $reason): array` | Guarded cancellation; refuses with `run_busy: true` while a step is running |
| `replay` | `(int $runId, ?string $fromStep): array` | Guarded replay; refuses with `run_busy: true` while a step is running |
| `subscribe` | `(string $module, string $eventId, string $workflowKey, ?array $filter = null, ?string $entityType = null): void` | Register event→workflow auto-start subscription |
| `handleEvent` | `(string $eventId, array $payload = []): void` | Handle incoming event — auto-starts matching workflows |

### Events

**WorkflowRuntime:** Every successful transition emits `workflow.transitioned` via EventBus with payload: `{workflow_key, module, entity_type, entity_id, from_state, to_state, action}`.

**WorkflowEngine:** Step execution is logged via `write_log()`; no separate per-step events are emitted. Runs that complete emit `workflow.transitioned` when they transition states through the WorkflowRuntime.

### Included Workflows

| File | Purpose |
|---|---|
| `kernel/workflows/report-approval.yaml` | Multi-step report validation, generation, and notification |
| `kernel/workflows/cms-content-publish.yaml` | Content publishing workflow with review steps |

### Test Coverage

`tests/workflow_engine_test.php` — 32 tests covering the base engine lifecycle and API.

`tests/workflow_concurrency_test.php` — real two-connection/fork coverage for:
- empty-range concurrent start, active-run, and duplicate-event deduplication
- overlapping advance refusal and exactly-once side effects
- guarded cancel/replay while a capability is in flight
- atomic attempt increments

> Distribution note: `src/helpers/workflow-retention.php` is absent in this checkout due to an escalated upstream sync gap. The guarded include keeps engine bootstrap operational, and payload-hash recording remains inert until that helper is restored.

---

## Conventions

- Workflow keys use dot-separated format: `order.fulfillment`, `cms.content`
- Guard functions receive a context array (built from `payload.guard_context` + system state) and return `bool`
- Action functions are not directly invoked by WorkflowRuntime — side effects should subscribe to `workflow.transitioned` events via EventBus
- Entity IDs are stored as strings in `workflow_instances.entity_id`
- Callers must be registered before defining workflows
- `ensureDefinition()` is idempotent and safe to call on every boot (cache-aware with configurable TTL)
