**Q1 verdict:**            **P2 is right, and C6 is the right next primitive.** The current substrate is optional plumbing: a request reaches module code before authority is established, so F4 is false. C6 should make a declared business capability a prerequisite for entering a route handler. The direction document would be wrong only if Akira abandoned the authority-native claim, if a lower boundary already guarded every mutation, or if HTTP routes were not a meaningful execution boundary because writes primarily entered through jobs/events/CLI. None is presently true. One correction: route enforcement proves non-bypassability only for routed requests; it must not be advertised as universal F4 until other mutation entry points are inventoried and guarded.

**Q2 product definition:** 

1. **Ordinary content work:** a user creates, edits, previews, publishes, unpublishes, restores, and schedules structured content through a simple administration UI.  
   *Test:* an editor can complete the full journey without code or direct database access.

2. **ARK themes through Theme Studio:** a user selects a validated theme, changes tokens/layout settings, previews the result, activates it, and rolls back without deploying executable theme code.  
   *Test:* switching A→B changes public rendering; rollback reproduces A; PHP-bearing themes are rejected.

3. **A real page builder:** a user arranges typed blocks, receives server-rendered preview, and publishes the structured composition through the same authority rules as other content.  
   *Test:* saved source is validated JSON, preview and public rendering agree, and revoked blocks produce a safe fallback.

4. **Permission visible at the moment of consequence:** direct URLs, forms, APIs, and the builder all permit or refuse the same operation. A refusal names the required action and reason rather than merely hiding a button.  
   *Test:* revoke publish authority and every publish entrance fails before content changes.

5. **Reviewable submodules:** enabling or upgrading a submodule shows what actions it requires; the tenant grants or refuses them, and later revocation removes or safely degrades its contribution.  
   *Test:* an ungranted contribution cannot execute even if its route is called directly.

6. **Receipts and reversible history:** after publication, a user can inspect who changed what, under which authority, what was live at a selected time, and restore a prior revision.  
   *Test:* the UI reconstructs a historical publication and its authority record without reading module source.

7. **One understandable control surface:** Workbench/Trust Center shows undeclared routes, granted capabilities, recent decisions, and policy drift for any installed module.  
   *Test:* the same query works unchanged for Akira and a non-content module.

**Refuse:** executable themes, ambient hooks, unbounded plugins, arbitrary HTML/JavaScript as builder source, client-authoritative rendering, per-module permission/audit systems, capability auto-grants, marketplace work, WordPress-parity checklists, and AI authorship before bounded delegation exists. These either erase the authority boundary or prove no substrate claim.

**Q3 design:**

1. **Declare required authority in `module.json` under `capabilities.routes`; retain reasoned exemptions under `governance.exemptions`.** Use exact keys such as `"POST /api/...": "akira.post.create@1"`. `module.json` already owns routes, exposed/depended capabilities, activation, and runtime contracts; production dispatch must not depend on a Workbench test artifact. Changing `routes.php` values would disturb the established path→handler convention and duplicate parsing changes across routing machinery. Validate that every declared capability is exposed or depended upon and reject stale/unknown route declarations. This proves **F2**: one domain-neutral declaration model.

2. **Reuse the handler’s business capability; authorize the route module as caller and the authenticated actor role.** Do not synthesize route capabilities. Synthetic IDs have no registered provider or policy row, so `authorize()` returns missing provider/policy/version unless a duplicate capability universe is built; they also separate authorization from the operation being authorized. “Actor role without a capability” cannot work because the registry correctly rejects `missing_capability_id`; role is a constraint, not authority. Add a small bus-level authorization probe that reuses provider resolution and the registry, requires an active policy row, and invokes no provider. Do not duplicate provider selection in `module-manager.php`. Anonymous reads eventually need a trusted request-boundary role such as `public`, never a role supplied by request data. This proves routed **F4** and contributes to **F3**.

3. **Fail closed for declared/enrolled routes; compatibility-open only for frozen legacy debt.** A global first-release fail-close would break live tenants and the 52 ledger operations. Ship three honest states:
   - declared route: authorization is mandatory and registry failure denies;
   - valid exemption: proceeds for its stated reason;
   - undeclared legacy route: temporarily proceeds with a high-severity observation and remains `undeclared`.
   
   Freeze each module’s undeclared baseline in the release gate: no new module or route may add debt, and enrolled modules must reach zero. Migrate the ledger later in owner-approved batches by exposing real business capabilities, seeding policy, changing adapters to call them, proving parity, and only then enabling enforcement. Its local audit/idempotency is not an exemption and must not improve the ratio. This proves incremental **F4** without falsifying availability claims.

4. **The smallest truth-making change is a guard immediately before `$routeCallable($params)`.** Resolve the exact method/path declaration, perform mandatory bus-backed authorization using module, actor, tenant, capability version and resolved provider, and invoke the handler only after an allowed decision. Unknown declaration, missing policy, unavailable registry, or denial must prevent invocation for enrolled routes. Add an integration fixture whose handler changes a sentinel before making any bus call; revoked policy must leave the sentinel untouched. Merely adding declarations, warnings, or checking inside the handler does not make P2 true. Update the census to distinguish `dispatch-enforced`, `handler-bus-reachable`, `exempt`, and `undeclared`; do not silently redefine the old metric. This proves routed **F4**.

5. **C6 must not** invent route capabilities, auto-seed permissive policies, infer authority from handler names, turn GET routes into blanket exemptions, wrap arbitrary handlers in generic transactions/audit/idempotency, map HTTP payloads into business calls, edit `daily-ledger`, add domain knowledge to Workbench, implement delegation/T5/UI polish, or claim coverage of CLI/events/jobs. Audit and idempotency belong inside the business capability’s tenant transaction; a generic route wrapper cannot guarantee their atomicity.

**Q4 sequence:**           **C6 enforcement → T4 page builder → P3 delegation → T5.** The single next slice is one real Akira mutation—preferably post creation—declared in `module.json`, authorization-checked before handler invocation, and protected by a zero-growth gate. Demonstrate it over HTTP: revoke the route module/actor policy, POST directly, observe a readable denial and no handler sentinel/domain row; restore policy, POST successfully; replay the same idempotency key and observe one domain transition. Then remove or weaken the declaration and show CI failing. A skeptic must be able to break the claim by moving a mutation before the handler’s existing bus call; the dispatch guard must still prevent it.

**Q5 honest number:**      **93.9% is static bus-reachability coverage, not governance coverage.** It means 31 of 33 selected mutation handlers contain, or can statically reach, tokens matching a capability call. It does not prove that the call executes on every path, corresponds to the route’s required authority, is authorized under a policy, precedes direct mutation, or commits audit/idempotency atomically. It is useful migration inventory, but calling it “governed” measures the metric. The honest statements are: **31/33 candidate handlers are bus-reachable; 0/33 routes are presently guaranteed by dispatch to establish declared authority before handler code.**

**risks:**                 I would bet against declaration honesty unless runtime evidence and adversarial tests accompany it; against compatibility mode ever disappearing without a dated debt gate; against anonymous/public reads fitting the current non-empty-role requirement without explicit design; and against the current token census surviving richer route/closure patterns without false positives. I would also reject any claim that C6 completes F4 before scheduled jobs, events, CLI handlers, and direct callable entry points receive the same inventory.
