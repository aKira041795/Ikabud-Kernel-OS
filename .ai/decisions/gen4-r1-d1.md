# Decision gen4-r1-d1

## Question

GEN4-R1 S2's acceptance criteria cannot be met without changing the trust surface you froze. Lift the freeze to widen the admissible evidence surface, or conclude the measurement here?

## Why now

S2's work is correct and shipped (census 47/47; policy allowed_roles=admin granted in tenant 54; its test passes 14/14 with a negative control), but its evidence cannot be produced. COMMAND_ALLOWLIST admits only php tests/<name>.php (ROOT only), php -l, the contract linter and the Python rules; php -r, php ikabud workbench:governance, grep, git, module test paths and any ;-chained or redirected form are refused, and an unlisted command binds no claim - so five clean CLAIM/COMMAND blocks extracted ZERO claims. The commands that verify ordinary product work are inadmissible, and the admissible substitutes are vacuously true of the change, which B-F1 forbids. Honest evidence therefore requires changing the frozen trust surface, which CD-41 reserves to the owner. Two further measured defects were deliberately NOT repaired: the authority matcher matches 'auth' as an unanchored substring, so the word 'authority' in a filename trips an absolute prohibition; and isExistingTestPath() evaluates file_exists() after the run, so no slice can create a test file (CD-46).

## Options

| id | label | effect | cost | blast radius | reversibility |
|---|---|---|---|---|---|
| widen-and-rerun | Widen the allowlist, then re-run S2 | Admit bounded read-only commands (the governance census) and module test paths; S2 re-runs to a clean completion and later product slices become measurable | one bounded trust-surface edit plus one re-run | medium: the allowlist is the trusted command boundary, so each new rule is a new execution surface | reversible |
| bounded-commands-only | Widen to bounded commands only | As above but module test paths stay inadmissible; slices must be evidenced by existing root tests | one bounded edit | lower: no new path classes become executable | reversible |
| conclude-measured | Conclude GEN4-R1 as measured | Record the distribution and stop; no harness change now, and ordinary product slices remain unevidenced | zero | none now | reversible |

## Recommendation

`widen-and-rerun` — Option 'widen-and-rerun' best preserves the approved task boundary.

`default_if_no_response: stop`

## Impact of no decision

The bounded run remains stopped until the director answers.

## Already done

- None.

## Evidence refs

- None.

## Checkpoint

- State: pre-change
- Git head: null
- Resume command: `php tools/ai-autonomy.php resume gen4-r1-d1 --choose=<OPTION> --decisions-dir=.ai/decisions`
