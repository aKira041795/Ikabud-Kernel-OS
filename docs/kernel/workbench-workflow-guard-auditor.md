# Workbench Workflow Guard Auditor

`php ikabud workbench:audit` is a deterministic static regression tripwire for the canonical concurrency, idempotency, fail-closed persistence, and MySQL 5.7 patterns in `kernel/WorkflowEngine.php`. It requires no database or network, and findings are ingested into the Workbench `IssueLedger`. Critical findings make the command exit non-zero.

## Narrow heuristic guarantee

The auditor provides exactly this guarantee: within each relevant method, it checks for the presence and ordering of the canonical hardened source patterns that its `token_get_all()` statement model recognizes. These include prepared and executed lock/claim/update statements, transaction markers, dispatch placement, recognized rejection and interruption forms, and forbidden MySQL-8-only SQL text.

A clean result means those recognized method-local patterns are present in the expected order. It does **not** prove that `WorkflowEngine.php` is correct, that statements execute on the same feasible path, that variables have a particular value at a program point, or that every exit path preserves an invariant. The scanner is a heuristic regression tripwire, not a structural proof or PHP correctness prover.

The real `kernel/WorkflowEngine.php` zero-finding baseline and adversarial fixture suite form the tripwire's regression net. They verify stable detection of the canonical patterns; they do not expand the guarantee into semantic program analysis.

## Deliberate limits and runtime authority

Reaching-definitions analysis, path-sensitive value environments, all-path exit analysis, interprocedural aliases, dynamically generated SQL, `eval`, reflection-generated calls, custom proxy magic, and deliberate obfuscation are explicitly out of scope. Further dataflow escalation is not part of this auditor.

The off-by-default runtime dispatch invariant in `WorkflowEngine`, the Phase-1 runtime/concurrency suites, normal code review, and release-gate review are the correctness authorities. When enabled in test/CI, the runtime guard fails closed before capability dispatch if the current database connection still has an open run-lock transaction. The static auditor supplements those controls; it does not replace them.
