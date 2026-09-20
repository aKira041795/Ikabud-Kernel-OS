# dsh executor parity — acceptance evidence

## Verdict

This parity slice stopped on a **boundary**. HARPP v2 returned `verified`, but it did so from its initial acceptance pass and never dispatched an executor lane. That is a valid Star Swarm acceptance verdict, but it is not the contract's required dsh parity verdict. Forcing a dispatch now would require manufacturing a failing product/gate or changing the protected driver, both forbidden by the brief.

```sh
python3 - <<'PY'
import json
p='tools/harpp2/state/star-swarm-deterministic-probes.jsonl'
rows=[json.loads(x) for x in open(p)]
start=max(i for i,r in enumerate(rows) if r.get('event')=='run_started')
run=rows[start:]
print('events=' + ','.join(r.get('event','') for r in run))
print('dispatch_events=' + str(sum(r.get('event')=='dispatch' for r in run)))
print('objective_verified_events=' + str(sum(r.get('event')=='objective_verified' for r in run)))
PY
php tools/harpp2/harpp2.php status --objective=tools/harpp2/objectives/star-swarm-deterministic-probes.md
```

```text
events=run_started,command,command,command,command,command,objective_verified
dispatch_events=0
objective_verified_events=1
HARPP v2
Objective: tools/harpp2/objectives/star-swarm-deterministic-probes.md
Status: verified
Chunks: 2 attempted, 0 verified, 2 blocked
Why: all objective acceptance gates passed
```

The exact next action is for the chair to replace the now-green fixed target with a sandboxed objective whose pre-existing acceptance is currently red, or explicitly authorize a driver feature that forces one lane before acceptance. This slice does neither.

## Adapter evidence

The adapter passed syntax checking and remained executable.

```sh
bash -n tools/harpp2/dispatch-dsh.sh
rc=$?
printf 'bash_n_exit=%d executable=%s\n' "$rc" "$(test -x tools/harpp2/dispatch-dsh.sh && echo yes || echo no)"
```

```text
bash_n_exit=0 executable=yes
```

One four-argument trivial brief completed in 6 seconds. It used dsh's `workspace-write` mode, the runtime's only write-capable confined mode. It permits writes under the session workspace; the brief supplies HARPP's declared scope and HARPP's snapshot/delta check remains the acceptance backstop. The invocation used `DSH_TIMEOUT_SECONDS=120`, proving the override from the value actually reported by the adapter.

```sh
DSH_TIMEOUT_SECONDS=120 bash tools/harpp2/dispatch-dsh.sh dsh-parity-four-arg deepseek/deepseek-v4-flash low "$brief"
```

```text
[harpp2:dsh] dsh-parity-four-arg exit=0 log=/var/www/html/ikabudsix/tools/harpp2/runs/dsh-parity-four-arg.log timeout=120s permission=workspace-write
four_arg_exit=0 elapsed_seconds=6
HARPP2_RESULT: {"artifact_paths":[],"evidence_commands":[],"summary":"adapter check completed","stop_condition":null,"question":null,"destructive_operations":[]}
```

The four-argument default is 1500 seconds; probe mode remains 180 seconds.

```sh
grep -n 'TIMEOUT_SECONDS=180\|DSH_TIMEOUT_SECONDS:-1500' tools/harpp2/dispatch-dsh.sh
```

```text
22:TIMEOUT_SECONDS=180
32:    TIMEOUT_SECONDS="${DSH_TIMEOUT_SECONDS:-1500}"
```

The three-argument checksum probe also completed in 6 seconds under `read-only` mode and matched the independent checksum.

```sh
expected=$(sha256sum tools/harpp2/CONSTITUTION.md | cut -d' ' -f1)
bash tools/harpp2/dispatch-dsh.sh dsh-parity-three-arg 'Print only the SHA-256 checksum of tools/harpp2/CONSTITUTION.md. Compute it from the file; do not modify files.' tools/harpp2/runs/dsh-parity-three-arg.answer.md
actual=$(tr -d '\r\n' < tools/harpp2/runs/dsh-parity-three-arg.answer.md)
printf 'expected=%s\nactual=%s\nmatch=%s\n' "$expected" "$actual" "$(test "$expected" = "$actual" && echo yes || echo no)"
```

```text
[harpp2:dsh] dsh-parity-three-arg exit=0 answer=/var/www/html/ikabudsix/tools/harpp2/runs/dsh-parity-three-arg.answer.md log=/var/www/html/ikabudsix/tools/harpp2/runs/dsh-parity-three-arg.dsh.log timeout=180s permission=read-only
expected=483de0f39d7705f247ebc9afd020f70a31a9a86decbc12843be94b01e3d700b0
actual=483de0f39d7705f247ebc9afd020f70a31a9a86decbc12843be94b01e3d700b0
match=yes
```

The journal records dsh provenance, permission modes, and the actual timeout values. The four-argument adapter's default could not be exercised by the objective because HARPP did not dispatch.

```sh
python3 - <<'PY'
import json
for line in open('tools/harpp2/runs/journal.jsonl'):
    row=json.loads(line)
    if row.get('name') in ('dsh-parity-four-arg','dsh-parity-three-arg'):
        print(json.dumps(row,separators=(',',':')))
PY
```

```text
{"event":"start","name":"dsh-parity-four-arg","runtime":"dsh","mode":"harpp2","permission_mode":"workspace-write","timeout_seconds":120,"at":"2026-09-18T13:33:04+08:00","model":"deepseek/deepseek-v4-flash","thinking":"low","brief":"/tmp/dsh-parity-trivial.tngs9s.md"}
{"event":"finish","name":"dsh-parity-four-arg","runtime":"dsh","mode":"harpp2","permission_mode":"workspace-write","timeout_seconds":120,"at":"2026-09-18T13:33:10+08:00","model":"deepseek/deepseek-v4-flash","thinking":"low","brief":"/tmp/dsh-parity-trivial.tngs9s.md","exit":0,"log":"/var/www/html/ikabudsix/tools/harpp2/runs/dsh-parity-four-arg.log","result":"completed"}
{"event":"start","name":"dsh-parity-three-arg","runtime":"dsh","mode":"probe","permission_mode":"read-only","timeout_seconds":180,"at":"2026-09-18T13:33:23+08:00"}
{"event":"finish","name":"dsh-parity-three-arg","runtime":"dsh","mode":"probe","permission_mode":"read-only","timeout_seconds":180,"at":"2026-09-18T13:33:29+08:00","exit":0,"log":"/var/www/html/ikabudsix/tools/harpp2/runs/dsh-parity-three-arg.dsh.log","result":"completed"}
```

## Instrument and parity command

The required stability command was run unpiped. All 10 attempts passed in 99 seconds; no gate was unable to run.

```sh
bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10
```

```text
stability: tests/browser/star-swarm.spec.ts × 10
  run 1/10: pass
  run 2/10: pass
  run 3/10: pass
  run 4/10: pass
  run 5/10: pass
  run 6/10: pass
  run 7/10: pass
  run 8/10: pass
  run 9/10: pass
  run 10/10: pass
  => 10/10 runs passed (failures: 0)
  STABILITY PASS
stability_exit=0 elapsed_seconds=99
```

The exact parity command completed in 105 seconds with exit 0. HARPP ran all five gates: stability 10/10, concept 23/0, visual 21/0, Playwright 2/0, and screenshot 4/0. It then verified immediately.

```sh
HARPP2_DISPATCH="$PWD/tools/harpp2/dispatch-dsh.sh" php tools/harpp2/harpp2.php run --objective=tools/harpp2/objectives/star-swarm-deterministic-probes.md
```

```text
stability: tests/browser/star-swarm.spec.ts × 10
  => 10/10 runs passed (failures: 0)
  STABILITY PASS
[exit 0]
=== summary ===
  23 passed, 0 failed
[exit 0]
=== summary ===
  21 passed, 0 failed
[exit 0]
  2 passed (6.0s)
[exit 0]
=== summary ===
  4 passed, 0 failed
[exit 0]
[harpp2] objective verified
parity_run_exit=0 elapsed_seconds=105
```

## What dsh did and did not do

There were **zero dsh objective-lane attempts**. dsh completed one trivial four-argument adapter invocation and one read-only three-argument checksum probe; it did not inspect, edit, test, or implement the Star Swarm objective. The state's `2 attempted` count is inherited from the earlier non-dsh run, not this slice. Therefore this result proves adapter plumbing and HARPP's existing gates, but proves no implementation parity for dsh.

A pi control run on this same green objective is **not warranted**: the same initial acceptance short-circuit would prevent a pi lane too. A control becomes warranted only after a legitimate red sandboxed objective is selected and dsh has actually received a lane.

## Protected paths

```sh
git diff --stat -- tools/harpp2/harpp2.php tools/harpp2/dispatch.sh tools/harpp2/chain.sh tools/harpp2/verify.php tools/harpp2/assertions.php tools/ai-autonomy.php tools/ai-run.php
rc=$?
printf 'protected_diff_stat_exit=%d\n' "$rc"
```

```text
protected_diff_stat_exit=0
```
