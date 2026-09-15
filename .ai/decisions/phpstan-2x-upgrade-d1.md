# Decision phpstan-2x-upgrade-d1

## Question

How should the 247 findings newly detected by PHPStan 2.x be handled?

## Why now

The 2.2.14 upgrade surfaces pre-existing redundancy and type findings; editing a quality-gate baseline is an L4 trigger, so it was deferred rather than assumed.

## Options

| id | label | effect | cost | blast radius | reversibility |
|---|---|---|---|---|---|
| split | Split: regenerate baseline + fix the genuine findings | ~240 redundancy detections accepted as recorded debt; the 6 genuine annotation findings fixed | 60m | kernel and src annotations plus the baseline file | reversible |
| regen-only | Regenerate the baseline only | all 247 accepted as debt with no code churn | 10m | the baseline file only | reversible |
| fix-all | Fix all 247 findings | removes ~240 defensive guards across kernel and src | 1-2d | wide, kernel and src | partially_reversible |

## Recommendation

`split` — Option 'split' best preserves the approved task boundary.

`default_if_no_response: stop`

## Impact of no decision

The bounded run remains stopped until the director answers.

## Already done

- Upgraded phpstan/phpstan 1.12.33 -> 2.2.14
- Migrated phpstan.neon excludePaths to the 2.x (?) marker
- Established CI-parity reference: PHP 8.3 + config path set restricted to tracked files
- Regenerated the baseline under 2.2.14 (2466 accepted)
- Fixed 6 genuine findings; 133 test assertions green

## Evidence refs

- phpstan.neon
- phpstan-baseline.neon

## Checkpoint

- State: pre-change
- Git head: null
- Resume command: `php tools/ai-autonomy.php resume phpstan-2x-upgrade-d1 --choose=<OPTION> --decisions-dir=.ai/decisions`
