# Hand-off — star-swarm-galaga-p2 not verified after 1 executors

Every mechanically correctable cause was addressed first: the executor was promoted
(flash/low → sol/medium) and the same item resumed. What is left needs a **chair correction**.

## What the driver recorded
```json
{
    "objective": "tools/harpp2/objectives/star-swarm-galaga-p2.md",
    "status": "escalated",
    "condition": "boundary",
    "reason": "outside objective scope: .ai/trust-surface-amendments.json; outside objective scope: tools/harpp2/projects/star-swarm-galaga.json",
    "chunks_attempted": 3,
    "verified": 0,
    "blocked": 1,
    "stalls": 0,
    "no_progress": 2,
    "approach": 2,
    "last_action": "DIFFERENT APPROACH: do not repeat the prior action; isolate and implement another concrete route to an acceptance failure.",
    "active_lane": null,
    "updated_at": "2026-09-18T08:58:40+00:00"
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
