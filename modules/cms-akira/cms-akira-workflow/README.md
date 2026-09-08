# CMS Akira Workflow

Native Akira Post workflow authority. The stable module id is `cms-akira-workflow`; it extends `cms-akira-core` and owns no module tables. Core owns `cms_akira_posts`; the workflow bridge has one deliberate cross-member write that materializes its lifecycle projection atomically on the shared tenant PDO.

## Definition authority

Enable/bootstrap idempotently calls Kernel `WorkflowRuntime::ensureDefinition()` for exactly one definition:

- workflow key: `akira.post`
- module: `cms-akira-workflow`
- entity type: `post`
- states: `draft`, `review`, `approved`, `published`
- transitions: `submit`, `approve`, `reject`, `publish`, `unapprove`, `unpublish`

Transition roles are encoded in the definition. Evaluation uses the current Kernel actor and returns no payload-supplied role or tenant identity.

## Capabilities

- `akira.workflow.evaluate@1` — accepts `entity_type` + opaque printable-ASCII `entity_key`; returns only `workflow_key`, `entity_type`, `entity_key`, `status`, and allowlisted `allowed_actions` (`action`, `to`, `label`). It fails closed without trusted tenant and actor context.
- `akira.workflow.transition@1` — governed protocol-v2 mutation requiring `idempotency_key`, `expected_status`, and an allowed `action`. Its policy binds the two production callers: the workflow JSON route (`cms-akira-workflow`) and the editorial UI (`cms-akira-shell`). Kernel idempotency claim/commit/release, `WorkflowRuntime::transition()`, the core Post status projection, and Kernel audit use the same tenant application PDO transaction. Published workflow state projects `status=published` plus `published_at`; every non-public state projects `status=draft`. Audit and output carry correlation. Its sole invalidation is `entity.list.workflow-run`.
- `akira.workflow.runs@1` — read-only Kernel run projection for exactly one current-tenant entity. It returns only run id, public identity/status, lifecycle timestamps, and cancellation reason; payload/context/definition ids and internal tenant subject ids are never exposed.

The implementation calls `app()->workflow()` directly for state and transition. Kernel registers the `workflow.*` capability provider before extensions load and freezes its caller allowlist at that point; the direct documented runtime surface preserves the outer capability actor context and permits the runtime write, idempotency evidence, and audit evidence to share one PDO transaction. A narrow wrapper temporarily removes only the extension's ModuleDB identity while the Kernel-owned service executes, then restores it unconditionally; this is required because the current `WorkflowRuntime` predates an internal KernelPDO escalation seam. The manifest still declares both Kernel workflow capabilities as installation prerequisites. Run introspection uses `app()->workflowEngine()` through the same narrow service wrapper.

## Tenant and persistence classification

Kernel workflow tables and `cms_akira_posts` are colocated in each tenant database. Workflow instance subjects remain tenant-namespaced as defense in depth. At this capability boundary every internal instance/run subject is deterministically namespaced with the trusted Kernel tenant id; tenant and role payload fields are rejected, run reads require an exact tenant-namespaced entity, and internal ids are never projected. Thus tenant B cannot evaluate, transition, or list tenant A's workflow subject.

The member creates no workflow or content tables and ships only the table-free `001_initial.sql` marker. Kernel dedicated-database migration/provisioning parity for workflow and audit tables remains a recorded Kernel prerequisite.

## Concurrency, retries, cancel/replay, and propagation

- `expected_status` supplies client optimistic concurrency; Kernel also performs a conditional state update to reject races.
- Durable idempotency provides deterministic same-payload replay, conflict detection, in-progress handling, and safe release before publication becomes uncertain.
- Kernel WorkflowEngine owns active-run locking, step retry limits, cancellation while no dispatch is in flight, and replay reset/advance semantics. `akira.workflow.runs@1` only projects those runs and cannot mutate them.
- Kernel `WorkflowRuntime` emits transition events before the outer member transaction commits and does not provide a tenant-local transactional outbox. Guaranteed lifecycle propagation/outbox parity is therefore explicitly a **recorded Kernel prerequisite**; this member does not promise convergence from that event.

## Security

The HTTP mutation route uses Kernel authentication/tenant context, applies CSRF to cookie sessions, and leaves Bearer validation and tenant reconciliation to the Kernel API/JWT boundary. Capability authorization policy v2 permits only lifecycle roles, while the workflow definition narrows each action by state and role. No tenant, role, actor, workflow key, module, or internal entity id is accepted from capability payloads.

## Verification

```sh
php modules/cms-akira/cms-akira-workflow/tests/workflow_contract_test.php
php ikabud module:certify cms-akira-workflow
php ikabud capability:audit --json
```
