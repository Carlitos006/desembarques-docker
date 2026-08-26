<?php

declare(strict_types=1);

/**
 * Formats a date string for display within observation metadata.
 */
function formatObservacionesDateDisplay(?string $value, string $format = 'd/m/Y H:i', ?string $fallback = null): string
{
    if ($value === null || $value === '') {
        return $fallback ?? '';
    }

    try {
        $date = new DateTimeImmutable($value);

        return $date->format($format);
    } catch (Throwable $exception) {
        return $fallback ?? (string) $value;
    }
}

/**
 * Determines whether a desembarque row has unread observations for the provided session state.
 *
 * @param array<string, mixed>       $row
 * @param array<int|string, string> $lastSeen
 */
function hasUnreadObservacionesForRow(array $row, array $lastSeen): bool
{
    $recordId = isset($row['id']) ? (int) $row['id'] : 0;

    if ($recordId <= 0) {
        return false;
    }

    $lastCreatedAtRaw = isset($row['observaciones_last_created_at'])
        ? trim((string) $row['observaciones_last_created_at'])
        : '';

    if ($lastCreatedAtRaw === '') {
        return false;
    }

    $recordKey = (string) $recordId;
    $lastSeenValue = '';

    if (isset($lastSeen[$recordId])) {
        $lastSeenValue = trim((string) $lastSeen[$recordId]);
    } elseif (isset($lastSeen[$recordKey])) {
        $lastSeenValue = trim((string) $lastSeen[$recordKey]);
    }

    if ($lastSeenValue === '') {
        return true;
    }

    try {
        $lastCreatedAt = new DateTimeImmutable($lastCreatedAtRaw);
        $lastSeenDate = new DateTimeImmutable($lastSeenValue);

        return $lastSeenDate < $lastCreatedAt;
    } catch (Throwable $exception) {
        return $lastSeenValue < $lastCreatedAtRaw;
    }
}

/**
 * Retrieves unread observations visible to the provided user.
 *
 * @param array<string, mixed>       $user
 * @param array<int|string, string> $observacionesLastSeen
 *
 * @return array<int, array<string, mixed>>
 */
function fetchUnreadObservacionesItems(
    mysqli $connection,
    array $user,
    array $observacionesLastSeen,
    string $currentLanguage
): array {
    $query = <<<SQL
        SELECT
            d.id,
            d.referencia,
            d.observaciones,
            d.client_id,
            client.name AS client_name,
            client.email AS client_email,
            obs.total AS observaciones_total,
            obs.last_created_at AS observaciones_last_created_at
        FROM desembarques d
        LEFT JOIN clients client ON client.id = d.client_id
        LEFT JOIN (
            SELECT desembarque_id, COUNT(*) AS total, MAX(created_at) AS last_created_at
            FROM desembarque_observaciones
            GROUP BY desembarque_id
        ) obs ON obs.desembarque_id = d.id
        WHERE d.deleted_at IS NULL AND obs.last_created_at IS NOT NULL
    SQL;

    $types = '';
    $params = [];
    $conditions = [];

    $userRole = (string) ($user['role'] ?? '');
    $userId = isset($user['id']) ? (int) $user['id'] : 0;
    $userName = mb_strtolower(trim((string) ($user['name'] ?? '')));
    $userEmail = mb_strtolower(trim((string) ($user['email'] ?? '')));

    if ($userRole === 'cliente') {
        $clientConditions = [];
        $clientTypes = '';
        $clientParams = [];

        $clientConditions[] = 'client.user_id = ?';
        $clientTypes .= 'i';
        $clientParams[] = $userId;

        if ($userEmail !== '') {
            $clientConditions[] = 'LOWER(TRIM(client.email)) = ?';
            $clientTypes .= 's';
            $clientParams[] = $userEmail;
        }

        if ($userName !== '' && $userName !== $userEmail) {
            $clientConditions[] = 'LOWER(TRIM(client.name)) = ?';
            $clientTypes .= 's';
            $clientParams[] = $userName;
        }

        $fallbackConditions = [];

        if ($userName !== '') {
            $fallbackConditions[] = 'LOWER(TRIM(d.cliente)) = ?';
            $clientTypes .= 's';
            $clientParams[] = $userName;
        }

        if ($userEmail !== '' && $userEmail !== $userName) {
            $fallbackConditions[] = 'LOWER(TRIM(d.cliente)) = ?';
            $clientTypes .= 's';
            $clientParams[] = $userEmail;
        }

        if ($fallbackConditions !== []) {
            $clientConditions[] = '(' . implode(' OR ', $fallbackConditions) . ')';
        }

        $conditions[] = '(' . implode(' OR ', $clientConditions) . ')';
        $types .= $clientTypes;

        foreach ($clientParams as $clientParam) {
            $params[] = $clientParam;
        }
    }

    if ($conditions !== []) {
        $query .= ' AND ' . implode(' AND ', $conditions);
    }

    $query .= ' ORDER BY obs.last_created_at DESC, d.id DESC';

    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException(translate('reports.error.prepare_statement', [], $currentLanguage));
    }

    try {
        if ($types !== '') {
            $bindParams = [$types];

            foreach ($params as $index => &$value) {
                $bindParams[] =& $params[$index];
            }

            $statement->bind_param(...$bindParams);
        }

        $statement->execute();

        $result = $statement->get_result();
        $items = [];

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (! hasUnreadObservacionesForRow($row, $observacionesLastSeen)) {
                    continue;
                }

                $legacyObservaciones = isset($row['observaciones']) ? (string) $row['observaciones'] : '';
                $hasLegacyObservaciones = trim($legacyObservaciones) !== '';
                $observacionesCount = isset($row['observaciones_total']) ? (int) $row['observaciones_total'] : 0;

                if ($hasLegacyObservaciones) {
                    $observacionesCount += 1;
                }

                $lastCreatedAt = isset($row['observaciones_last_created_at'])
                    ? (string) $row['observaciones_last_created_at']
                    : '';

                $items[] = [
                    'id' => (int) $row['id'],
                    'referencia' => (string) ($row['referencia'] ?? ''),
                    'count' => $observacionesCount,
                    'last_created_at' => $lastCreatedAt,
                    'last_created_at_display' => formatObservacionesDateDisplay($lastCreatedAt, 'd/m/Y H:i', ''),
                ];
            }

            $result->free();
        }

        $statement->close();

        return $items;
    } catch (Throwable $exception) {
        $statement->close();

        throw $exception;
    }
}
