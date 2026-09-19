# SLICE — prohibition leeway: two over-broad mechanisms, repaired

project: harness-guardrail · status: DIRECTOR_AUTHORISED (CD-48) · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "prohibition-leeway", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

exceptions:
  - what:     add taxonomy assertions to the existing autonomy test
    why:      the two matcher repairs must be proved by driving the real CLI, and every existing taxonomy assertion already lives in that file; a second file would fork the evidence
    scope:    tests/ai_autonomy_test.php
    decided_when: 2026-09-15T00:00:00+00:00
    authority: CD-48

## THIS CONTRACT WILL BE REFUSED BY `plan`. THAT IS EXPECTED — READ FIRST.

**Authority: owner directive 2026-09-15, verbatim: *"our objective, prohibition is fine but allow leeway. pure
prohibition stifles the harness."*** (recorded as CD-48). This contract names the **trust surface**, so `plan`
**must** exit 2. **That refusal is rule 1 working, and it is your first claim.**

## The ruling you are implementing

**A prohibition and a mechanism that fires on innocent work are different things.** The rule stays. A mechanism
that cannot tell the work from the harm it forbids is a **defect in the mechanism**, and repairing it does not
weaken the rule.

## Objective

Repair two mechanisms that are over-broad, each measured from a real run record. Neither repair may reduce what
the prohibition protects, and **neither may make the matcher judge the change** — that fix stays rejected
(CD-22): a matcher able to classify a change as *safe* can be argued into classifying a weakening as safe.

### Repair A — the `authority` matcher matches substrings where it should match tokens

`taxonomyMatcherMatches()` in `tools/ai-autonomy.php` currently reads:

```php
'authority' => preg_match('#kernel/Capabilities|CapabilityAuthorization|SecurityHeaders|auth|JWT|policy#i', $path) === 1,
```

The bare alternative `auth` matches anywhere, so a path like
`modules/gui-settings/tests/gui_settings_route_authority_test.php` trips the **absolute** prohibition on
authorisation weakening — the exact prohibition whose record says *no justification can authorise it*. A slice
whose purpose is to **add** authority coverage could not name the file after the work.

**A path is a sequence of tokens; the matcher reads it as a string.** Fix that, and only that: a token matches
only as a **whole path token** (delimited by `/`, `_`, `.`, `-`, or the ends of the path). Then `authority` is
not `auth`, while `auth.php`, `auth-owned-*.php`, `JWT`, and `policy` paths are still caught.

**Enumerate the protected tokens explicitly.** Nothing becomes protected by accident, and nothing stops being
protected silently. The set must still cover what the old pattern covered for genuine paths:
`kernel/Capabilities` (path prefix), `CapabilityAuthorization`, `SecurityHeaders`, `auth`, `JWT`, `policy`.
Add the obvious auth-family tokens the old substring happened to catch and you must not lose —
`authn`, `authz`, `authentication`, `authorisation`, `authorization`, `policies`, `passwords`, `session` — and
say in the docblock which are new and why the old pattern caught them. **`authority` is NOT a protected token**:
it names the subject of the slice, not an authentication mechanism, and that is the entire point of the repair.

### Repair B — "an existing test file" is decided after the run has already answered it

`isExistingTestPath()` in `tools/ai-autonomy.php`:

```php
/** A test path that already exists is a verification artefact whose edit weakens verification. */
function isExistingTestPath(string $path): bool { ... return $isTest && file_exists($path); }
```

The comment states the intent correctly — *a new test file is an addition, not a weakening*. The **timing** is
wrong. `tools/ai-run.php:scopeConformance()` shells out to `tools/ai-autonomy.php check … --path=<p>` **after**
the run, so `file_exists()` is `true` for a file **this run created**, and a new test is judged a pre-existing
test under modification. Under this mechanism **no slice can create a test file at all.**

Fix the timing, not the rule: decide from the **dispatch baseline the run record already holds**
(`scope_baseline_paths`), which the runner knows and the driver does not.

- A test path **present at dispatch** stays **absolutely** protected — unchanged force.
- A test path **absent at dispatch** is an **addition**, which is what the comment always said.
- **Pre-dispatch callers keep today's behaviour exactly.** `plan` and an ordinary `check` pass no baseline, and
  at that moment the file has not been created, so `file_exists()` remains the right question.

## Architectural constraints

- **Two repairs. Nothing else.** Do not touch any other matcher, the prohibition list, the authority ladder, or
  any other rule. Each is a separate finding with its own contract.
- **Backward compatible by default.** When the new inputs are absent, every verdict must be **byte-identical** to
  today. This is what makes the change auditable; a repair that changes default behaviour is a rewrite.
- **Do not weaken reach.** After the repair, every path that was correctly flagged before must still be flagged.
  Name in your report which ones you re-checked, and how.
- **Do not inspect the change.** Neither `taxonomyMatcherMatches()` nor `isExistingTestPath()` may look at what
  a diff does. The prohibition stays absolute; only *how a path is read* and *when a question is asked* change.
- **The prohibition list itself is untouched.** No entry is removed, softened, or re-classified.

## Files likely affected

- `tools/ai-autonomy.php`
- `tools/ai-run.php`
- `tests/ai_autonomy_test.php`

## Acceptance criteria

Every criterion must be shown by a command you declare, and the assertions must drive the **real CLI**
(`proc_open` on `tools/ai-autonomy.php check … --json`), which is how every existing taxonomy assertion works.

1. **The false positive is gone.** `check --path=modules/gui-settings/tests/gui_settings_route_authority_test.php`
   no longer reports the authority prohibition. This is the case that cost S2 its run.
2. **Reach is preserved.** Each of these **is still** flagged by the authority prohibition:
   `kernel/JWT.php`, `kernel/Capabilities/CapabilityAuthorizationRegistry.php`,
   `tests/auth_owned_reserved_role_validation_test.php`, and a path containing `SecurityHeaders`, a `policy`
   token, and a `CapabilityAuthorization` token.
3. **The token boundary is exact.** `authority` and `authorities` are not flagged; `auth` and `auth-owned` are.
4. **Existing-test protection is unchanged for genuine edits.** A test path passed with the baseline flag
   indicating it existed at dispatch **is still** flagged as an absolute existing-test prohibition.
5. **A created test is an addition.** The same path passed with the baseline flag indicating it did **not**
   exist at dispatch is **not** flagged as an existing test.
6. **Defaults are unchanged, and the exceptions are listed.** With no new input, `check --json` for a set of
   representative paths produces the same verdict as before the repair — **except** the paths you deliberately
   changed. List those exceptions explicitly by path and verdict, with the before/after for each. "Unchanged
   behaviour" is a claim you must support path by path, and the changed paths are the interesting part.
7. Say plainly whether this edit **weakens** any existing check, and if it does, say so and stop.

## Required tests

```
php tests/ai_autonomy_test.php
```

It must exit 0 with zero failures and **no new skips**; add the assertions for criteria 1–6 to it (it already
holds 31 taxonomy/prohibition assertions — add to it, do not fork it). Then run the regression cover for the
other touched file:

```
php tests/ai_run_test.php
php tests/ai_project_test.php
```

## Risks

- **Tokenising too narrowly silently drops protection.** That is the failure that matters: a genuine auth path
  stops being flagged and nobody notices. Enumerate the tokens, then prove each one still fires.
- **Tokenising too broadly re-creates the false positive.** If `authority` ends up matching, S2 stays blocked and
  the repair has bought nothing.
- **Threading the baseline wrongly inverts the rule.** If the flag is read as "is a test" rather than "existed at
  dispatch", creations are prohibited again or edits become permitted. Test both directions.
- **The runner and the driver must agree.** A flag the runner sends and the driver ignores (or vice versa) leaves
  both the old behaviour and a false sense that it was fixed.

## Report format — required

The report is **evidence**: every statement must be backable by a command. `parseProseClaim()`
(`tools/ai-run.php:1407`) binds report **prose** as a claim by design, so a numbered list or a plain sentence
becomes a claim of its own, binds `UNVERIFIED`, and blocks the slice **even when the work is complete**. Four
runs have been blocked exactly that way.

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Nothing before the first `CLAIM:` and nothing after the last `OBSERVED:` block. No headings, no lists, no
narrative. **Declare only keys your command's output actually prints.**

## Forbidden changes

- `kernel/` — no engine change; this is a tool-table and matcher repair.
- `src/` — no application change.
- `modules/` — no product change.
- `phpstan-baseline.neon` — never edited to make a gate pass.
- `.github/workflows/` — no CI definition change.
- `tools/ai-loop.php` — not involved in either defect.
- `tools/ai-project.php` — not involved in either defect.
