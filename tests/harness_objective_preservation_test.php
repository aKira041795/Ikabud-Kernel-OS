<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('harness-objective-preservation', TestHarness::MODE_PURE);
$h->fingerprint('.github/instructions/ai-autonomy-escalation.instructions.md');
$h->fingerprint('.ai/ai-autonomy-harness.contract.md');

$root = $h->basePath();
$policy = file_get_contents($root . '/.github/instructions/ai-autonomy-escalation.instructions.md');
$contract = file_get_contents($root . '/.ai/ai-autonomy-harness.contract.md');
$policy = $policy === false ? '' : $policy;
$contract = $contract === false ? '' : $contract;

$heading = '## Objective preservation — meta-work must not displace object-work';
$operativeRule = 'If the harness can perform the next safe, authorised, reversible action toward the contracted outcome, it **MUST prefer that action over further governance analysis**.';
$mustNotClauses = [
    'Meta-work **MUST NOT** become a substitute for execution.',
    'Meta-work **MUST NOT** expand merely because a possible governance weakness exists.',
    'Meta-work **MUST NOT** suspend authorised reversible work.',
    'Meta-work **MUST NOT** turn every discovered ambiguity into director consultation.',
];
$absoluteProhibitions = [
    '- Weakening auth, authorisation, policy, or security.',
    '- Disabling, skipping, deleting, or weakening a test or gate to obtain a pass.',
    '- Editing a quality-gate baseline.',
    '- Deleting audit data or falsifying provenance.',
    '- Silent non-delivery to the director.',
];

$h->section('Normative objective-preservation policy');
$h->test('the objective-preservation heading exists', str_contains($policy, $heading));
$headings = preg_match_all('/^## .+$/m', $policy, $matches) === false ? [] : $matches[0];
$driftIndex = array_search('## Drift, defined against the contract', $headings, true);
$h->test(
    'the objective-preservation section immediately follows Drift',
    is_int($driftIndex) && ($headings[$driftIndex + 1] ?? null) === $heading
);
$h->test('the MUST-prefer operative rule is present', str_contains($policy, $operativeRule));
foreach ($mustNotClauses as $clause) {
    $h->test('required MUST-NOT clause is present: ' . $clause, str_contains($policy, $clause));
}
$h->test(
    'the forcing-function question is present',
    str_contains($policy, '*"did the project actually stop because this was missing?"*')
);

$h->section('Absolute prohibitions remain byte-identical');
foreach ($absoluteProhibitions as $prohibition) {
    $h->test('absolute prohibition is unchanged: ' . $prohibition, str_contains($policy, $prohibition));
}

$h->section('Standing Chair remit');
$compactRule = 'If the next safe, authorised, reversible action toward the contracted outcome is available, the Chair **MUST prefer that action over further governance analysis**.';
$h->test(
    'the compact objective-preservation rule and normative pointer are inherited',
    str_contains($contract, $compactRule)
        && str_contains($contract, 'Objective preservation — meta-work must not displace object-work')
);

$h->done();
