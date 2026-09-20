# Hand-off — star-swarm-deterministic-probes not verified after 2 executors

Every mechanically correctable cause was addressed first: the executor was promoted
(flash/low → sol/medium) and the same item resumed. What is left needs a **chair correction**.

## What the driver recorded
```json
{
    "objective": "tools/harpp2/objectives/star-swarm-deterministic-probes.md",
    "status": "escalated",
    "condition": "boundary",
    "reason": "test assertion removed or weakened: tests/browser/star-swarm.spec.ts",
    "chunks_attempted": 2,
    "verified": 0,
    "blocked": 2,
    "stalls": 0,
    "no_progress": 0,
    "approach": 0,
    "last_action": "Choose the smallest unfinished product chunk ending in a live deterministic check.",
    "active_lane": null,
    "updated_at": "2026-09-17T00:51:57+00:00"
}
```

## Classify before doing anything

| Category | Meaning | Correction |
|---|---|---|
| objective defect | the item, its acceptance command, or its scope was wrong | fix the objective, resume the SAME item |
| executor defect | both lanes failed the same way | change model/brief, resume the SAME item |
| harness defect | dispatch, lock, journal or driver behaviour blocked the work | fix the harness, resume the SAME item |
| **real boundary** | proceeding would destroy data, weaken security, or exceed authority | **director decision — this is the only case that reaches him** |

## Proposed correction

<one step; the chair fills this in and resumes — the chain does not stop here by default>
