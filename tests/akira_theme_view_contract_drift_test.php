<?php

declare(strict_types=1);

/**
 * Pure proof that a theme's declared entity-view fields and the module's
 * registered contract are compared with the two reasons kept distinct.
 *
 * `ThemeViewContractDrift::compare()` takes arrays and returns findings, so this
 * test requires the kernel class directly and passes it arrays. It never boots
 * the application: the two shipped maps are read as JSON fixtures and the
 * registered contract is rebuilt from the module's own registration through a
 * lone `EntityViewResolver` instance.
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Services/ThemeViewContractDrift.php';
require_once __DIR__ . '/../kernel/EntityContext/EntityViewResolver.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-core/helpers/entity-views.php';

use Ikabud\Kernel\EntityContext\EntityViewResolver;
use Ikabud\Kernel\Services\ThemeViewContractDrift;

$h = new TestHarness('akira-theme-view-contract-drift', TestHarness::MODE_PURE);
$h->fingerprint('kernel/Services/ThemeViewContractDrift.php');
$h->fingerprint('modules/cms-akira/cms-akira-core/helpers/entity-views.php');
$h->fingerprint('storage/cms-themes/akira-editorial/entity-view-map.json');
$h->fingerprint('storage/cms-themes/akira-ark/entity-view-map.json');
$h->fingerprint('storage/cms-themes/akira-ark-demo/entity-view-map.json');

$reasonOf = static fn (array $findings, string $reason): array => array_values(array_filter(
    $findings,
    static fn (array $finding): bool => ($finding['reason'] ?? '') === $reason
));

/**
 * Rebuild the "entity.view" => field-list map the way the theme validator does,
 * from the contracts the module itself registered.
 *
 * @param array<string, array<string, mixed>> $contracts
 * @return array<string, list<string>>
 */
function driftRegisteredFields(array $contracts): array
{
    $registeredFields = [];
    foreach ($contracts as $viewKey => $contract) {
        $fields = is_array($contract) ? ($contract['fields'] ?? null) : null;
        if ($fields === '*' || $fields === ['*']) {
            $registeredFields[(string) $viewKey] = ['*'];
        } elseif (is_array($fields)) {
            $registeredFields[(string) $viewKey] = array_values(array_filter($fields, 'is_string'));
        } else {
            $registeredFields[(string) $viewKey] = [];
        }
    }

    return $registeredFields;
}

/**
 * Load a shipped theme's declared entity views without touching the application.
 *
 * @return array<string, array<string, mixed>>
 */
function driftLoadDeclaredViews(string $slug): array
{
    $path = dirname(__DIR__) . '/storage/cms-themes/' . $slug . '/entity-view-map.json';
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !is_array($decoded['entity_views'] ?? null)) {
        return [];
    }

    return $decoded['entity_views'];
}

$resolver = new EntityViewResolver();
cacRegisterPostEntityViews($resolver);
$registeredFields = driftRegisteredFields($resolver->registeredViewContracts());

$h->section('Module registration is the source of truth');
$h->assertSame(
    ['title', 'subtitle', 'image', 'metadata', 'categories', 'actions', 'url'],
    $registeredFields['post.list'] ?? null,
    'post.list contract fields are the module-registered list'
);
$h->assertSame(
    ['title', 'subtitle', 'image', 'body', 'metadata', 'categories', 'actions', 'url'],
    $registeredFields['post.detail'] ?? null,
    'post.detail contract fields are the module-registered list'
);

$h->section('Shipped declarations are true (zero field_not_in_contract)');
foreach (['akira-editorial', 'akira-ark', 'akira-ark-demo'] as $slug) {
    $findings = ThemeViewContractDrift::compare(driftLoadDeclaredViews($slug), $registeredFields);
    $fieldErrors = $reasonOf($findings, ThemeViewContractDrift::REASON_FIELD_NOT_IN_CONTRACT);
    $h->test(
        "{$slug}: no declared field is absent from its contract",
        $fieldErrors === [],
        'field_errors=' . json_encode($fieldErrors)
    );
}

$h->section('composition.detail is contract_not_registered, never an error');
foreach (['akira-editorial', 'akira-ark'] as $slug) {
    $findings = ThemeViewContractDrift::compare(driftLoadDeclaredViews($slug), $registeredFields);
    $composition = array_values(array_filter(
        $findings,
        static fn (array $finding): bool => ($finding['entity'] ?? '') === 'composition' && ($finding['view'] ?? '') === 'detail'
    ));
    $h->assertCount(1, $composition, "{$slug}: composition.detail yields exactly one finding");
    $h->assertSame(
        ThemeViewContractDrift::REASON_CONTRACT_NOT_REGISTERED,
        $composition[0]['reason'] ?? null,
        "{$slug}: composition.detail reason is contract_not_registered"
    );
    $h->assertSame('*', $composition[0]['field'] ?? null, "{$slug}: whole-view finding carries the '*' placeholder");
}

$h->section('Both directions are proven on synthetic declarations');
$declaredFieldDrift = ['post' => ['list' => ['fields' => ['title', 'bogus']]]];
$fieldDriftFindings = ThemeViewContractDrift::compare($declaredFieldDrift, ['post.list' => ['title']]);
$h->assertCount(1, $fieldDriftFindings, 'a declared field the contract lacks yields one finding');
$h->assertSame(
    ThemeViewContractDrift::REASON_FIELD_NOT_IN_CONTRACT,
    $fieldDriftFindings[0]['reason'] ?? null,
    'the reason is field_not_in_contract'
);
$h->assertSame('post', $fieldDriftFindings[0]['entity'] ?? null);
$h->assertSame('list', $fieldDriftFindings[0]['view'] ?? null);
$h->assertSame('bogus', $fieldDriftFindings[0]['field'] ?? null);

$declaredMissingContract = ['widget' => ['detail' => ['fields' => ['alpha', 'beta']]]];
$missingContractFindings = ThemeViewContractDrift::compare($declaredMissingContract, ['post.list' => ['title']]);
$h->assertCount(1, $missingContractFindings, 'a declared view with no contract yields exactly one finding');
$h->assertSame(
    ThemeViewContractDrift::REASON_CONTRACT_NOT_REGISTERED,
    $missingContractFindings[0]['reason'] ?? null,
    'the reason is contract_not_registered'
);

$h->section('A wildcard contract admits every declared field');
$h->test(
    'wildcard contract yields no findings',
    ThemeViewContractDrift::compare(['post' => ['list' => ['fields' => ['anything', 'goes']]]], ['post.list' => ['*']]) === []
);

$h->section('Malformed declarations are tolerated without throwing');
$malformedCases = [
    'fields is not an array' => ['post' => ['list' => ['fields' => 'not-an-array']]],
    'fields mixes non-strings' => ['post' => ['list' => ['fields' => ['title', 42, null, ['nested']]]]],
    'view declaration is a scalar' => ['post' => ['list' => 'not-an-object']],
];
foreach ($malformedCases as $label => $declared) {
    $thrown = null;
    $findings = [];
    try {
        $findings = ThemeViewContractDrift::compare($declared, ['post.list' => ['title']]);
    } catch (Throwable $error) {
        $thrown = $error;
    }
    $h->test("{$label}: no exception escapes", $thrown === null, $thrown?->getMessage() ?? '');
    $h->test("{$label}: no field_not_in_contract fabricated", $reasonOf($findings, ThemeViewContractDrift::REASON_FIELD_NOT_IN_CONTRACT) === [], json_encode($findings));
}

$h->test(
    'an empty declaration set yields no findings',
    ThemeViewContractDrift::compare([], $registeredFields) === []
);

$h->done();
