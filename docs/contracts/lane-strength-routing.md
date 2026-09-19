# CONTRACT — lane strength routing

## Objective
Route a task to the model whose strength matches the kind of work, and record which lane was chosen and
why. Delegation should get better as verification gets cheaper, not merely cheaper: a probe already judges
any model's output identically, so the thing worth choosing well is which model to spend on which work.

## Architectural constraints
- No new dependency. Stdlib and the kernel Workbench classes only.
- The registry is data inside `tools/chair.php`, not a configuration service and not a new file to keep in sync.
- A lane that is not installed must degrade to a printed brief, never to a silent no-op.

## Files likely affected
- `tools/chair.php`

## Acceptance criteria
- `php tools/chair.php lanes` prints the registry: each kind of work, the lane it routes to, and why.
- The registry names at least the kinds `visual`, `mechanical`, and `reasoning`.
- `chair run` records the lane it chose and the reason, so a run's provenance says who did the work and why them.

## Required tests
- `php tools/chair.php lanes | grep -q visual`

## Risks
- A registry that hardcodes model names rots as models are retired. The reason text is what carries the
  judgement; the model name is the current best answer to it.

## Forbidden changes
- `kernel/`
- `tests/`
