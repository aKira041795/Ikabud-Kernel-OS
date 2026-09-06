<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Shared durable idempotency primitive over kernel_idempotency_keys.
 *
 * The table's response_json column stores a versioned envelope containing the
 * normalized payload hash while processing and the committed outcome when
 * complete. The unique (idempotency_key_hash, tenant_id) index is the atomic
 * database claim; a connection-scoped advisory lock lets concurrent callers
 * wait for and reuse the winner's committed result.
 *
 * HTTP adoption is deliberately primitive-level: use the client
 * Idempotency-Key as the key and hash
 * canonicalPayloadHash(['method' => strtoupper($method), 'path' => $pathWithoutQuery,
 * 'body' => $parsedBody]). JSON bodies are decoded exactly once, associatively,
 * with JSON_THROW_ON_ERROR; whitespace-only JSON is null, and decoded scalar,
 * null, object-map, and list types are retained. Form-urlencoded bodies are
 * string-valued maps. Multipart and other body formats are outside this
 * fingerprint contract.
 *
 * A new claim may execute and commit an HTTP outcome shaped as
 * ['status' => int, 'body' => string, 'headers' => allowlisted replay metadata].
 * The outcome must not contain a version: encodeEnvelope() already versions it.
 * A duplicate replays that outcome without execution; conflict maps to HTTP 409,
 * and in_progress maps to HTTP 425 with Retry-After: 2. Replay header allowlists
 * must exclude hop-by-hop headers and Set-Cookie. Release only a failure known to
 * precede every side effect; uncertain execution or commit remains processing.
 */
class Idempotency
{
    /**
     * A contender waits in short server-side lock calls and re-reads committed
     * state between them. Five minutes is an operational safety cap, not a
     * workflow-duration timeout: expiry fails closed as "in_progress" and
     * never reclaims or executes an uncertain processing claim.
     */
    private const WAIT_CAP_SECONDS = 300;
    private const LOCK_RETRY_SECONDS = 2;

    /**
     * Hash a value using the Kernel's sole idempotency canonicalization.
     * Associative keys are sorted recursively; list order and scalar types are
     * preserved by JSON encoding (including the distinction between 1 and 1.0).
     */
    public static function canonicalPayloadHash(mixed $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (is_object($item)) {
                $item = get_object_vars($item);
            }
            if (!is_array($item)) {
                if (is_resource($item)) {
                    throw new \InvalidArgumentException('Resources cannot be normalized for idempotency');
                }
                return $item;
            }

            if (array_is_list($item)) {
                return array_map(fn (mixed $entry): mixed => $canonicalize($entry), $item);
            }

            ksort($item, SORT_STRING);
            foreach ($item as $key => $entry) {
                $item[$key] = $canonicalize($entry);
            }
            return $item;
        };

        $json = json_encode(
            $canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
        return hash('sha256', $json);
    }

    /**
     * Atomically claim a tenant-scoped key.
     *
     * $waitCapSeconds bounds total advisory-lock contention. Null preserves the
     * five-minute default used by WorkflowEngine and EventBus. A cap no greater
     * than LOCK_RETRY_SECONDS performs at most one GET_LOCK attempt; zero makes
     * that attempt non-blocking. Exhaustion returns in_progress and never grants
     * permission to execute.
     *
     * @return array{status: 'new'}|array{status: 'duplicate', outcome: mixed}|array{status: 'conflict'}|array{status: 'in_progress'}
     */
    public static function claim(
        string $key,
        int $tenantId,
        string $payloadHash,
        ?PDO $db = null,
        ?int $waitCapSeconds = null,
    ): array {
        self::assertInputs($key, $tenantId, $payloadHash);
        $waitCapSeconds ??= self::WAIT_CAP_SECONDS;
        if ($waitCapSeconds < 0) {
            throw new \InvalidArgumentException('waitCapSeconds must not be negative');
        }
        $db ??= self::db();
        $keyHash = hash('sha256', $key);
        $lockName = self::lockName($keyHash, $tenantId);

        $deadline = microtime(true) + $waitCapSeconds;
        do {
            $remaining = max(0.0, $deadline - microtime(true));
            $lockTimeout = $waitCapSeconds === 0
                ? 0
                : min(self::LOCK_RETRY_SECONDS, max(1, (int)ceil($remaining)));
            if (!self::acquireLock($db, $lockName, $lockTimeout)) {
                // The winner still owns the lock. Its committed row is visible
                // before it releases that lock, so observe publication directly.
                $observed = self::observe($db, $keyHash, $tenantId, $payloadHash);
                if ($observed !== null && $observed['status'] !== 'in_progress') {
                    return $observed;
                }
                if ($waitCapSeconds <= self::LOCK_RETRY_SECONDS || microtime(true) >= $deadline) {
                    return ['status' => 'in_progress'];
                }
                continue;
            }

            try {
                $observed = self::observe($db, $keyHash, $tenantId, $payloadHash);
                if ($observed === null) {
                    $stmt = $db->prepare(
                        "INSERT INTO kernel_idempotency_keys "
                        . "(idempotency_key_hash, tenant_id, status, response_json, created_at) "
                        . "VALUES (:hash, :tenant, 'processing', :response, NOW())"
                    );
                    $stmt->execute([
                        ':hash' => $keyHash,
                        ':tenant' => $tenantId,
                        ':response' => self::encodeEnvelope($payloadHash, null),
                    ]);

                    // The claimant keeps ownership until commit() or release().
                    return ['status' => 'new'];
                }
                if ($observed['status'] !== 'in_progress') {
                    self::releaseLock($db, $lockName);
                    return $observed;
                }

                // We acquired an unowned lock but found processing state. The
                // former owner may have crashed after a side effect; never reclaim.
                self::releaseLock($db, $lockName);
                return ['status' => 'in_progress'];
            } catch (Throwable $e) {
                self::releaseLock($db, $lockName);
                throw $e;
            }
        } while (microtime(true) < $deadline);

        return ['status' => 'in_progress'];
    }

    /** Commit a claimed key and persist its reusable outcome. */
    public static function commit(string $key, int $tenantId, mixed $outcome, ?PDO $db = null): bool
    {
        self::assertKeyAndTenant($key, $tenantId);
        $db ??= self::db();
        $keyHash = hash('sha256', $key);
        $lockName = self::lockName($keyHash, $tenantId);

        if (!self::ownsLock($db, $lockName)) {
            return false;
        }

        try {
            $stmt = $db->prepare(
                "SELECT response_json FROM kernel_idempotency_keys "
                . "WHERE idempotency_key_hash = :hash AND tenant_id = :tenant AND status = 'processing' LIMIT 1"
            );
            $stmt->execute([':hash' => $keyHash, ':tenant' => $tenantId]);
            $raw = $stmt->fetchColumn();
            if (!is_string($raw)) {
                throw new RuntimeException('Idempotency key is not claimed for processing');
            }
            $stored = self::decodeEnvelope($raw);
            $payloadHash = (string)($stored['payload_hash'] ?? '');
            if ($payloadHash === '') {
                throw new RuntimeException('Idempotency claim payload hash is missing');
            }

            $update = $db->prepare(
                "UPDATE kernel_idempotency_keys SET status = 'completed', response_json = :response "
                . "WHERE idempotency_key_hash = :hash AND tenant_id = :tenant AND status = 'processing'"
            );
            $update->execute([
                ':response' => self::encodeEnvelope($payloadHash, $outcome),
                ':hash' => $keyHash,
                ':tenant' => $tenantId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Idempotency commit lost its processing claim');
            }
            return true;
        } finally {
            self::releaseLock($db, $lockName);
        }
    }

    /** Release a failed processing claim so a later retry can execute. */
    public static function release(string $key, int $tenantId, ?PDO $db = null): bool
    {
        self::assertKeyAndTenant($key, $tenantId);
        $db ??= self::db();
        $keyHash = hash('sha256', $key);
        $lockName = self::lockName($keyHash, $tenantId);

        if (!self::ownsLock($db, $lockName)) {
            return false;
        }

        try {
            $stmt = $db->prepare(
                "DELETE FROM kernel_idempotency_keys "
                . "WHERE idempotency_key_hash = :hash AND tenant_id = :tenant AND status = 'processing'"
            );
            $stmt->execute([':hash' => $keyHash, ':tenant' => $tenantId]);
            return $stmt->rowCount() === 1;
        } finally {
            self::releaseLock($db, $lockName);
        }
    }

    /**
     * Legacy HTTP lookup helper. New adopters should use claim().
     *
     * @return array<string, mixed>|null
     */
    public static function check(string $key, int $tenantId): ?array
    {
        $hash = hash('sha256', $key);
        $stmt = self::db()->prepare(
            "SELECT response_json FROM kernel_idempotency_keys "
            . "WHERE idempotency_key_hash = ? AND tenant_id = ? AND status = 'completed'"
        );
        $stmt->execute([$hash, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || empty($row['response_json'])) {
            // Preserve the legacy helper's processing marker. HTTP adoption of
            // payload-aware claim/commit remains a separately tested follow-on.
            self::db()->prepare(
                "INSERT INTO kernel_idempotency_keys (idempotency_key_hash, tenant_id, status, created_at) "
                . "VALUES (?, ?, 'processing', NOW()) "
                . "ON DUPLICATE KEY UPDATE status = IF(status = 'completed', status, 'processing')"
            )->execute([$hash, $tenantId]);
            return null;
        }

        $decoded = json_decode((string)$row['response_json'], true);
        if (is_array($decoded) && isset($decoded['_kernel_idempotency'])) {
            return is_array($decoded['outcome'] ?? null) ? $decoded['outcome'] : null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $response */
    public static function store(string $key, int $tenantId, array $response): void
    {
        $hash = hash('sha256', $key);
        self::db()->prepare(
            "UPDATE kernel_idempotency_keys SET status = 'completed', response_json = ? "
            . 'WHERE idempotency_key_hash = ? AND tenant_id = ?'
        )->execute([json_encode($response), $hash, $tenantId]);
    }

    private static function acquireLock(PDO $db, string $lockName, int $timeout): bool
    {
        $stmt = $db->prepare('SELECT GET_LOCK(:lock_name, :timeout)');
        $stmt->bindValue(':lock_name', $lockName, PDO::PARAM_STR);
        $stmt->bindValue(':timeout', $timeout, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn() === 1;
    }

    /**
     * @return array{status: 'duplicate', outcome: mixed}|array{status: 'conflict'}|array{status: 'in_progress'}|null
     */
    private static function observe(PDO $db, string $keyHash, int $tenantId, string $payloadHash): ?array
    {
        $stmt = $db->prepare(
            'SELECT status, response_json FROM kernel_idempotency_keys '
            . 'WHERE idempotency_key_hash = :hash AND tenant_id = :tenant LIMIT 1'
        );
        $stmt->execute([':hash' => $keyHash, ':tenant' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $stored = self::decodeEnvelope((string)($row['response_json'] ?? ''));
        if ($stored['enveloped'] !== true) {
            if (($row['status'] ?? '') === 'completed') {
                return ['status' => 'duplicate', 'outcome' => $stored['outcome']];
            }
            return ['status' => 'in_progress'];
        }
        if (($stored['payload_hash'] ?? null) !== $payloadHash) {
            return ['status' => 'conflict'];
        }
        if (($row['status'] ?? '') === 'completed') {
            return ['status' => 'duplicate', 'outcome' => $stored['outcome'] ?? null];
        }
        return ['status' => 'in_progress'];
    }

    private static function ownsLock(PDO $db, string $lockName): bool
    {
        $stmt = $db->prepare('SELECT IS_USED_LOCK(:lock_name) = CONNECTION_ID()');
        $stmt->execute([':lock_name' => $lockName]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private static function releaseLock(PDO $db, string $lockName): void
    {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute([':lock_name' => $lockName]);
        } catch (Throwable $e) {
            // Preserve the primary failure. Connection close also releases it.
        }
    }

    private static function lockName(string $keyHash, int $tenantId): string
    {
        return 'kernel:idem:' . substr(hash('sha256', $tenantId . ':' . $keyHash), 0, 52);
    }

    private static function assertInputs(string $key, int $tenantId, string $payloadHash): void
    {
        self::assertKeyAndTenant($key, $tenantId);
        if (preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1) {
            throw new \InvalidArgumentException('payloadHash must be a lowercase SHA-256 hash');
        }
    }

    private static function assertKeyAndTenant(string $key, int $tenantId): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Idempotency key must not be empty');
        }
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('A positive tenant ID is required for idempotency');
        }
    }

    private static function encodeEnvelope(string $payloadHash, mixed $outcome): string
    {
        return json_encode([
            '_kernel_idempotency' => ['version' => 1, 'payload_hash' => $payloadHash],
            'outcome' => $outcome,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{enveloped: bool, payload_hash?: mixed, outcome: mixed} */
    private static function decodeEnvelope(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['_kernel_idempotency'] ?? null)) {
            return ['enveloped' => false, 'outcome' => $decoded];
        }
        return [
            'enveloped' => true,
            'payload_hash' => $decoded['_kernel_idempotency']['payload_hash'] ?? null,
            'outcome' => $decoded['outcome'] ?? null,
        ];
    }

    private static function db(): PDO
    {
        if (function_exists('app') && $app = \app()) {
            return $app->db();
        }
        throw new RuntimeException('Application not available for database access');
    }
}
