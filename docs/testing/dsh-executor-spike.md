# DeepSeek Harness executor-runtime spike

## Verdict

The bounded plumbing probe passed: both runtimes returned the independently computed SHA-256, and the dsh event retained explicit runtime provenance while the pi control did not.

```sh
H=$(sha256sum tools/harpp2/CONSTITUTION.md | cut -d' ' -f1); grep -qF "$H" tools/harpp2/runs/dsh-spike.dsh.answer.md && grep -qF "$H" tools/harpp2/runs/dsh-spike.pi.answer.md; rc=$?; printf 'expected=%s\ndsh=%s\npi=%s\nexit=%d\n' "$H" "$(tr -d '\r\n' < tools/harpp2/runs/dsh-spike.dsh.answer.md)" "$(tr -d '\r\n' < tools/harpp2/runs/dsh-spike.pi.answer.md)" "$rc"
```

```text
expected=483de0f39d7705f247ebc9afd020f70a31a9a86decbc12843be94b01e3d700b0
dsh=483de0f39d7705f247ebc9afd020f70a31a9a86decbc12843be94b01e3d700b0
pi=483de0f39d7705f247ebc9afd020f70a31a9a86decbc12843be94b01e3d700b0
exit=0
```

This proves only one-task executor plumbing, not parity in reasoning quality, reliability, security, or cost.

## Installation and exact versions

The isolated install completed in 238 seconds with the pinned command below; it added 522 packages and did not modify the repository package manifest or lockfile.

```sh
cd tools/dev/dsh-spike && timeout 300 npm install --prefix home --no-save @deepseek-ai/dsh@0.1.5-rc.2
```

```text
npm warn EBADENGINE Unsupported engine {
npm warn EBADENGINE   package: 'commander@15.0.0',
npm warn EBADENGINE   required: { node: '>=22.12.0' },
npm warn EBADENGINE   current: { node: 'v22.11.0', npm: '10.9.0' }
npm warn EBADENGINE }
...
added 522 packages in 4m

65 packages are looking for funding
  run `npm fund` for details
exit=0 elapsed_seconds=238
```

The exact tool versions were:

```sh
printf 'pi='; pi --version; printf 'npm='; npm --version; printf 'install_node='; node --version; printf 'runtime_node='; "$HOME/.local/node-v22.23.2-linux-x64/bin/node" --version; printf 'dsh='; cd tools/dev/dsh-spike && DSH_HOME="$PWD/home" "$HOME/.local/node-v22.23.2-linux-x64/bin/node" home/node_modules/@deepseek-ai/dsh/lib/bin.js --version
```

```text
pi=0.84.2
npm=10.9.0
install_node=v22.11.0
runtime_node=v22.23.2
dsh=0.1.5-rc.2
```

The machine-readable installation record is present and contains the pin and exact command:

```sh
cat tools/harpp2/runs/dsh-spike.install.json
```

```json
{
  "package": "@deepseek-ai/dsh",
  "requested_version": "0.1.5-rc.2",
  "resolved_version": "0.1.5-rc.2",
  "install_command": "cd tools/dev/dsh-spike && timeout 300 npm install --prefix home --no-save @deepseek-ai/dsh@0.1.5-rc.2",
  "exit_code": 0,
  "elapsed_seconds": 238,
  "npm_version": "10.9.0",
  "install_node_version": "v22.11.0",
  "runtime_node_version": "v22.23.2",
  "runtime_home": "tools/dev/dsh-spike/home",
  "install_output_summary": "added 522 packages; npm emitted EBADENGINE warnings because install-time Node v22.11.0 is below transitive requirements; the probe uses Node v22.23.2"
}
```

## Credential and profile

The credential source was the existing `deepseek.key` entry in `~/.pi/agent/auth.json`. The backend reads it without printing it and supplies it only as `DEEPSEEK_API_KEY` to the child process.

```sh
python3 - <<'PY'
import json,os
p=os.path.expanduser('~/.pi/agent/auth.json'); d=json.load(open(p)); v=d.get('deepseek',{}).get('key')
print('source=~/.pi/agent/auth.json:deepseek.key')
print('usable=' + str(isinstance(v,str) and bool(v)).lower())
PY
```

```text
source=~/.pi/agent/auth.json:deepseek.key
usable=true
```

The exact dsh invocation profile was `--profile headless`, with `DSH_PERMISSION_MODE=read-only` and `DSH_TELEMETRY_DISABLED=1`. Its shipped persona wording and the complete task wording were:

```sh
printf '%s\n' 'profile=headless' 'permission=read-only' 'prompt=Print only the SHA-256 checksum of tools/harpp2/CONSTITUTION.md. Compute it from the file in this working tree; do not guess and do not modify any file.'; grep -A3 '^    personaSuffix:' tools/dev/dsh-spike/home/node_modules/@deepseek-ai/dsh-headless/cordis.patch.yml
```

```text
profile=headless
permission=read-only
prompt=Print only the SHA-256 checksum of tools/harpp2/CONSTITUTION.md. Compute it from the file in this working tree; do not guess and do not modify any file.
    personaSuffix: Your working directory is {{cwd}}.
    personaPrefix: >-
      You are a coding agent powered by the {{model}} model.
```

A defensive comparison found no credential value in the dsh reasoning log:

```sh
python3 - <<'PY'
import json, os
log='tools/harpp2/runs/dsh-spike-dsh.dsh.log'
secret=json.load(open(os.path.expanduser('~/.pi/agent/auth.json')))['deepseek']['key']
data=open(log,encoding='utf-8',errors='replace').read()
print('credential_present_in_log=' + str(secret in data).lower())
PY
```

```text
credential_present_in_log=false
```

## Provenance and authority

The shared journal records the dsh runtime key and leaves that key absent from the pi control events:

```sh
python3 - <<'PY'
import json
rows=[json.loads(x) for x in open('tools/harpp2/runs/journal.jsonl')]
for r in rows:
    if r.get('name') in ('dsh-spike-dsh','dsh-spike-pi-control'):
        print(json.dumps(r,separators=(',',':')))
print('dsh_start_has_runtime=' + str(any(r.get('name')=='dsh-spike-dsh' and r.get('event')=='start' and r.get('runtime')=='dsh' for r in rows)).lower())
print('pi_control_has_runtime=' + str(any(r.get('name')=='dsh-spike-pi-control' and 'runtime' in r for r in rows)).lower())
PY
```

```text
{"event":"start","name":"dsh-spike-dsh","runtime":"dsh","at":"2026-09-18T13:20:17+08:00"}
{"event":"finish","name":"dsh-spike-dsh","runtime":"dsh","at":"2026-09-18T13:20:24+08:00","exit":0,"log":"/var/www/html/ikabudsix/tools/harpp2/runs/dsh-spike-dsh.dsh.log"}
{"event":"start","name":"dsh-spike-pi-control","model":"openai-codex/gpt-5.6-sol","thinking":"low","at":"2026-09-18T13:20:45+08:00"}
{"event":"finish","name":"dsh-spike-pi-control","exit":0,"at":"2026-09-18T13:20:57+08:00","log":"/var/www/html/ikabudsix/tools/harpp2/runs/dsh-spike-pi-control.log"}
dsh_start_has_runtime=true
pi_control_has_runtime=false
```

The answers remained claims until the independent `sha256sum` gate in the Verdict section returned zero; dsh did not determine acceptance.

## Failures and repairs

The install-time Node was below several transitive package engine requirements:

```sh
grep -E 'package:|required:|current:' /tmp/dsh-install.out | head -n 12
```

```text
npm warn EBADENGINE   package: 'commander@15.0.0',
npm warn EBADENGINE   required: { node: '>=22.12.0' },
npm warn EBADENGINE   current: { node: 'v22.11.0', npm: '10.9.0' }
npm warn EBADENGINE   package: 'undici@8.10.2',
npm warn EBADENGINE   required: { node: '>=22.19.0' },
npm warn EBADENGINE   current: { node: 'v22.11.0', npm: '10.9.0' }
npm warn EBADENGINE   package: '@earendil-works/pi-ai@0.85.1',
npm warn EBADENGINE   required: { node: '>=22.19.0' },
npm warn EBADENGINE   current: { node: 'v22.11.0', npm: '10.9.0' }
npm warn EBADENGINE   package: '@earendil-works/pi-telemetry@0.85.1',
npm warn EBADENGINE   required: { node: '>=22.19.0' },
npm warn EBADENGINE   current: { node: 'v22.11.0', npm: '10.9.0' }
```

Consequently, the installed launcher under the default Node silently returned no version text even with exit zero:

```sh
cd tools/dev/dsh-spike; DSH_HOME="$PWD/home" home/node_modules/.bin/dsh --version >/tmp/dsh-old.out 2>/tmp/dsh-old.err; printf 'default_node_dsh_exit=%d stdout_bytes=%s stderr_bytes=%s\n' "$?" "$(wc -c </tmp/dsh-old.out)" "$(wc -c </tmp/dsh-old.err)"
```

```text
default_node_dsh_exit=0 stdout_bytes=0 stderr_bytes=0
```

The backend repaired this runtime mismatch by selecting the already-installed Node v22.23.2 and refusing to run if no Node >=22.19.0 is available.

The isolated install created no manifest or lockfile and the product manifests have no diff:

```sh
test ! -e tools/dev/dsh-spike/home/package.json && test ! -e tools/dev/dsh-spike/home/package-lock.json && git diff --quiet -- package.json pnpm-lock.yaml; rc=$?; printf 'spike_home_manifest_or_lock_absent=%s\nproduct_manifest_diff_absent=%s\nexit=%d\n' "$(test ! -e tools/dev/dsh-spike/home/package.json -a ! -e tools/dev/dsh-spike/home/package-lock.json && echo true || echo false)" "$(git diff --quiet -- package.json pnpm-lock.yaml && echo true || echo false)" "$rc"
```

```text
spike_home_manifest_or_lock_absent=true
product_manifest_diff_absent=true
exit=0
```

## Final acceptance evidence

```sh
php tools/ai-contract-lint.php --contract=.ai/dsh-executor-spike.contract.md
```

```text
COVERAGE .ai/*.contract.md + .ai/projects/**/*.md (project.md and slice contracts)
dsh-executor-spike                                   parse=ok   harness_ref=yes phantoms=0  status=READY_FOR_IMPLEMENTATION                 class=live

SUMMARY total=1 live=1 stale=0 unknown=0 live_parse_failures=0 live_with_phantoms=0 with_phantoms=0 missing_status=0
exit=0
```

```sh
bash -n tools/harpp2/dispatch-dsh.sh; rc=$?; test -x tools/harpp2/dispatch-dsh.sh; x=$?; printf 'bash_n_exit=%d executable_test_exit=%d\n' "$rc" "$x"
```

```text
bash_n_exit=0 executable_test_exit=0
```

```sh
git diff --name-only -- tools/harpp2/dispatch.sh tools/harpp2/chain.sh tools/harpp2/harpp2.php tools/ai-autonomy.php tools/ai-run.php | wc -l | awk '{print "protected_changed_paths=" $1}'
```

```text
protected_changed_paths=0
```

The full required read-only status commands showed pre-existing Akira theme-editor work alongside this spike; no staging was performed:

```sh
git status --porcelain
git diff --stat
```

```text
 M .ai/harpp2-judgement.md
 M modules/cms-akira/cms-akira-theme/handlers.php
 M tests/browser/akira-theme-editor.spec.ts
 M tools/harpp2/projects/akira-theme-editor.json
?? .ai/dsh-executor-spike.contract.md
?? docs/testing/dsh-executor-spike.md
?? public/assets/js/akira-theme-studio.js
?? tools/dev/dsh-spike/
?? tools/harpp2/dispatch-dsh.sh
?? tools/harpp2/escalations/akira-theme-editor-milestone-20260917-025957.md
?? tools/harpp2/escalations/akira-theme-editor-milestone-20260917-030719.md
?? tools/harpp2/escalations/akira-theme-editor-milestone-20260917-110721.md
 .ai/harpp2-judgement.md                        |  18 ++++
 modules/cms-akira/cms-akira-theme/handlers.php |  35 +++++++-
 tests/browser/akira-theme-editor.spec.ts       |   3 +
 tools/harpp2/projects/akira-theme-editor.json  | 110 ++++++++++++-------------
 4 files changed, 107 insertions(+), 59 deletions(-)
```

## Chair recommendation

Adopt `dsh` only as an **experimental second executor backend**, not as a default or as an acceptance authority. The successful hash gate and provenance event justify retaining the additive backend for larger, separately authorised evaluation, while the release-candidate pin, 522-package install, strict newer-Node requirement, and single trivial probe are insufficient evidence for production equivalence. `harpp2` should continue to own objectives, independent checks, scope, and verdicts.
