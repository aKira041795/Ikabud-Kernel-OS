# Offline HARPP simulator

This directory simulates only the HARPP CLI subset used by `tools/ai-autonomy.php`: decision submission and listing, acknowledgement and application, and idempotent message sending. `director-answer` stands in for the director channel. The store records the real lifecycle sequence `NOTIFIED → VIEWED → DECIDED → ACKNOWLEDGED → APPLIED`.

It uses only Python's standard library, performs no network I/O, and neither reads nor writes `~/.config/harpp`. `config.json` contains only inert sandbox values. State is written only to `$HARPP_SIM_STORE` (default `.ai/harpp-sim/store.json`). `HARPP_NOTIFY=0` mirrors production suppression by returning `{"ok":true,"suppressed":true}` without recording anything.

## What this does not prove

A green simulation does **not** prove the real network path, bridge API, push or desktop notifications, delivery to the director, or anything about the live queue. A green simulation must never be reported as production delivery.

## Exact round trip

Run from the repository root. The `/tmp` paths keep generated evidence out of the working tree.

```bash
rm -rf /tmp/ikabudsix-harpp-sim
mkdir -p /tmp/ikabudsix-harpp-sim/decisions
export PATH="$PWD/.ai/harpp-sim:$PATH"
export HARPP_SIM_STORE=/tmp/ikabudsix-harpp-sim/store.json
export HARPP_CONFIG="$PWD/.ai/harpp-sim/config.json"

php tools/ai-autonomy.php plan --contract=.ai/ai-autonomy-harness.contract.md --decisions-dir=/tmp/ikabudsix-harpp-sim/decisions
php tools/ai-autonomy.php defer --task=harpp-sim --id=harpp-sim-d1 --question='Choose the bounded route?' --why='The simulated round trip needs a director choice.' --option='stay|Stay bounded|Continue inside scope|low|simulation only|reversible' --option='stop|Stop|Leave the checkpoint unchanged|low|simulation only|reversible' --recommend=stay --contract=.ai/ai-autonomy-harness.contract.md --decisions-dir=/tmp/ikabudsix-harpp-sim/decisions
.ai/harpp-sim/director-answer 1 --decision stay --rationale 'Simulation selected the bounded option.'
php tools/ai-autonomy.php status --remote --decisions-dir=/tmp/ikabudsix-harpp-sim/decisions
php tools/ai-autonomy.php resume harpp-sim-d1 --from-harpp --decisions-dir=/tmp/ikabudsix-harpp-sim/decisions
php tools/ai-autonomy.php status --decisions-dir=/tmp/ikabudsix-harpp-sim/decisions
python3 -m json.tool /tmp/ikabudsix-harpp-sim/store.json
```

Suppression is a separate fresh run and must exit `4` while retaining its local artifacts:

```bash
HARPP_NOTIFY=0 php tools/ai-autonomy.php defer --task=harpp-suppressed --id=harpp-suppressed-d1 --question='Suppressed delivery?' --why='Prove suppression is not delivery.' --option='stay|Stay bounded|No remote delivery|low|simulation only|reversible' --option='stop|Stop|Leave the checkpoint unchanged|low|simulation only|reversible' --recommend=stay --contract=.ai/ai-autonomy-harness.contract.md --decisions-dir=/tmp/ikabudsix-harpp-sim/suppressed-decisions
test -f /tmp/ikabudsix-harpp-sim/suppressed-decisions/harpp-suppressed-d1.json
```
