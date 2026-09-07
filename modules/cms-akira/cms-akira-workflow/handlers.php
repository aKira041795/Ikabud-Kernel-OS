<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, string> $params */
function cawWorkflowHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => CAW_WORKFLOW_MODULE_ID,
        'version' => '1.0.0',
        'authority' => 'native',
        'persistence' => 'kernel-workflow',
    ], JSON_UNESCAPED_SLASHES);
}

function cawWorkflowJsonError(Throwable $error): void
{
    $status = 500;
    $message = 'Workflow operation failed.';
    $retryAfter = null;
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CawWorkflowException) {
            $status = $cursor->httpStatus;
            $message = $cursor->getMessage();
            $retryAfter = $cursor->retryAfter;
            break;
        }
    }
    if ($retryAfter !== null) {
        header('Retry-After: ' . $retryAfter);
    }
    app()->json(['ok' => false, 'error' => $message], $status);
}

/** @return array<string, mixed> */
function cawWorkflowInput(): array
{
    $ctx = function_exists('module') ? module(CAW_WORKFLOW_MODULE_ID) : null;
    if ($ctx !== null) {
        $input = $ctx->input();
        if (is_array($input) && $input !== []) {
            return $input;
        }
    }
    $raw = file_get_contents('php://input');
    $json = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    return is_array($json) ? $json : $_POST;
}

function cawWorkflowEnforceMutationCsrf(): void
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $cookieNames = [(string) config('app.cookie_name', 'guidance_token')];
    if (function_exists('declaredModuleAuthCookieNames')) {
        foreach (declaredModuleAuthCookieNames() as $cookieName) {
            if (is_string($cookieName)) {
                $cookieNames[] = $cookieName;
            }
        }
    }
    $hasAuthCookie = false;
    foreach (array_unique($cookieNames) as $cookieName) {
        if ($cookieName !== '' && isset($_COOKIE[$cookieName])) {
            $hasAuthCookie = true;
            break;
        }
    }
    if ($hasAuthCookie || preg_match('/^Bearer\s+\S+$/i', $authorization) !== 1) {
        app()->csrfEnforce();
    }
}

/** @param array<string, string> $params */
function cawWorkflowEvaluateJson(array $params = []): void
{
    $payload = ['entity_type' => $params['entity_type'] ?? '', 'entity_key' => $params['entity_key'] ?? ''];
    app()->json(caw_cap_akira_workflow_evaluate_1($payload));
}

/** @param array<string, string> $params */
function cawWorkflowRunsJson(array $params = []): void
{
    $payload = ['entity_type' => $params['entity_type'] ?? '', 'entity_key' => $params['entity_key'] ?? ''];
    app()->json(caw_cap_akira_workflow_runs_1($payload));
}

/** @param array<string, string> $params */
function cawWorkflowTransitionJson(array $params = []): void
{
    cawWorkflowEnforceMutationCsrf();
    $payload = cawWorkflowInput();
    $payload['entity_type'] = $params['entity_type'] ?? ($payload['entity_type'] ?? null);
    $payload['entity_key'] = $params['entity_key'] ?? ($payload['entity_key'] ?? null);
    $payload['idempotency_key'] = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($payload['idempotency_key'] ?? '')));
    try {
        $result = app()->cap()->call('akira.workflow.transition@1', $payload, [
            'caller' => ['module' => CAW_WORKFLOW_MODULE_ID, 'user' => app()->user()],
            'mode' => 'first',
        ]);
        app()->json($result);
    } catch (Throwable $error) {
        cawWorkflowJsonError($error);
    }
}
