<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Record an audit log entry.
 *
 * @param array<string, mixed> $payload
 */
function record_audit_log(
    string $action,
    string $entityType,
    ?string $entityId,
    array $payload = [],
    ?int $userId = null,
    ?mysqli $connection = null
): void {
    $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    try {
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | $jsonOptions);
    } catch (Throwable $exception) {
        $fallbackPayload = [
            'error' => 'Failed to encode audit payload',
            'message' => $exception->getMessage(),
        ];

        try {
            $payloadJson = json_encode($fallbackPayload, $jsonOptions);
        } catch (Throwable $fallbackException) {
            $payloadJson = sprintf(
                '{"error":"unencoded_audit_payload","message":"%s"}',
                addslashes($fallbackException->getMessage())
            );
        }
    }

    try {
        $databaseConnection = $connection instanceof mysqli
            ? $connection
            : getDatabaseConnection();
    } catch (Throwable $exception) {
        error_log('[audit] Connection unavailable: ' . $exception->getMessage());

        return;
    }

    try {
        $query = 'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, payload) VALUES (?, ?, ?, ?, ?)';
        $statement = $databaseConnection->prepare($query);

        if (! $statement) {
            throw new RuntimeException('Unable to prepare audit log statement.');
        }

        $userIdValue = $userId;
        $entityIdValue = $entityId !== null ? (string) $entityId : null;

        $statement->bind_param(
            'issss',
            $userIdValue,
            $action,
            $entityType,
            $entityIdValue,
            $payloadJson
        );

        $statement->execute();
        $statement->close();
    } catch (Throwable $exception) {
        error_log('[audit] Failed to record log entry: ' . $exception->getMessage());
    }
}

/**
 * Compute changes between two associative arrays.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 *
 * @return array<string, array{before: mixed, after: mixed}>
 */
function compute_audit_changes(array $before, array $after): array
{
    $changes = [];
    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

    foreach ($keys as $key) {
        $beforeValue = $before[$key] ?? null;
        $afterValue = $after[$key] ?? null;

        if (audit_values_are_equal($beforeValue, $afterValue)) {
            continue;
        }

        $changes[$key] = [
            'before' => $beforeValue,
            'after' => $afterValue,
        ];
    }

    return $changes;
}

/**
 * Determine if two audit values should be considered equal.
 *
 * @param mixed $first
 * @param mixed $second
 */
function audit_values_are_equal($first, $second): bool
{
    if ($first === $second) {
        return true;
    }

    if (is_numeric($first) && is_numeric($second)) {
        return (string) $first === (string) $second;
    }

    if ((is_array($first) || is_object($first)) && (is_array($second) || is_object($second))) {
        try {
            return json_encode($first, JSON_THROW_ON_ERROR) === json_encode($second, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return serialize($first) === serialize($second);
        }
    }

    return false;
}
