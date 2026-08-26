<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/database.php';

$language = api_v1_detect_language();

try {
    api_v1_require_method(['GET'], $language);
    $auth = authenticate_api_request(['desembarques:read'], $language);
    $user = $auth['user'];
    $userId = (int) ($user['id'] ?? 0);
    $userRole = (string) ($user['role'] ?? '');
    $userName = mb_strtolower(trim((string) ($user['name'] ?? '')));
    $userEmail = mb_strtolower(trim((string) ($user['email'] ?? '')));

    $queryParams = [
        'fecha_inicio' => isset($_GET['fecha_inicio']) ? (string) $_GET['fecha_inicio'] : '',
        'fecha_fin' => isset($_GET['fecha_fin']) ? (string) $_GET['fecha_fin'] : '',
        'cliente_id' => isset($_GET['cliente_id']) ? (string) $_GET['cliente_id'] : '',
        'status_id' => isset($_GET['status_id']) ? (string) $_GET['status_id'] : '',
    ];

    $errors = [];
    $startDate = validate_api_date_filter($queryParams['fecha_inicio'], 'fecha_inicio', $errors, $language);
    $endDate = validate_api_date_filter($queryParams['fecha_fin'], 'fecha_fin', $errors, $language);

    if ($startDate !== null && $endDate !== null) {
        $startObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $endObj = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if ($startObj instanceof DateTimeImmutable && $endObj instanceof DateTimeImmutable && $startObj > $endObj) {
            $errors['fecha_fin'] = translate('reports.validation.date_range', [], $language);
        }
    }

    $clientFilterId = null;
    $statusFilterId = null;

    if ($userRole !== 'cliente') {
        $clientIdRaw = trim($queryParams['cliente_id']);

        if ($clientIdRaw !== '') {
            if (! ctype_digit($clientIdRaw)) {
                $errors['cliente_id'] = translate('desembarques.validation.client_invalid', [], $language);
            } else {
                $clientFilterId = (int) $clientIdRaw;
            }
        }
    }

    $statusIdRaw = trim($queryParams['status_id']);

    if ($statusIdRaw !== '') {
        if (! ctype_digit($statusIdRaw)) {
            $errors['status_id'] = translate('desembarques.validation.status_invalid', [], $language);
        } else {
            $statusFilterId = (int) $statusIdRaw;
        }
    }

    if ($errors !== []) {
        api_v1_error(422, translate('validation.errors', [], $language), $errors);
    }

    /** @var mysqli $connection */
    $connection = getDatabaseConnection();

    $query = <<<SQL
        SELECT
            d.id,
            d.referencia,
            d.fecha_desembarque,
            d.descripcion,
            d.observaciones,
            d.destino,
            d.folio_aviso,
            d.fecha_embarque,
            d.barco,
            d.cliente,
            d.dias_transcurridos,
            d.dias_fuera,
            d.client_id,
            d.created_by,
            d.created_at,
            creator.name AS created_by_name,
            creator.email AS created_by_email,
            client.name AS client_name,
            client.email AS client_email,
            status.id AS status_id,
            status.slug AS status_slug,
            status.name_es AS status_name_es,
            status.name_en AS status_name_en,
            COALESCE(obs.total, 0) AS observaciones_count,
            obs.last_created_at AS observaciones_last_created_at
        FROM desembarques d
        LEFT JOIN users creator ON creator.id = d.created_by
        LEFT JOIN clients client ON client.id = d.client_id
        LEFT JOIN desembarque_statuses status ON status.id = d.status_id
        LEFT JOIN (
            SELECT desembarque_id, COUNT(*) AS total, MAX(created_at) AS last_created_at
            FROM desembarque_observaciones
            GROUP BY desembarque_id
        ) obs ON obs.desembarque_id = d.id
        WHERE d.deleted_at IS NULL
    SQL;

    $conditions = [];
    $types = '';
    $params = [];

    if ($userRole === 'cliente') {
        $conditions[] = 'client.user_id = ?';
        $types .= 'i';
        $params[] = $userId;

        $fallbackMatches = [];

        if ($userEmail !== '') {
            $fallbackMatches[] = $userEmail;
        }

        if ($userName !== '' && $userName !== $userEmail) {
            $fallbackMatches[] = $userName;
        }

        if ($fallbackMatches !== []) {
            $subConditions = [];

            foreach ($fallbackMatches as $match) {
                $subConditions[] = 'LOWER(TRIM(d.cliente)) = ?';
                $types .= 's';
                $params[] = $match;
            }

            $conditions[] = '(' . implode(' OR ', $subConditions) . ')';
        }
    } elseif ($clientFilterId !== null) {
        $conditions[] = 'd.client_id = ?';
        $types .= 'i';
        $params[] = $clientFilterId;
    }

    if ($statusFilterId !== null) {
        $conditions[] = 'd.status_id = ?';
        $types .= 'i';
        $params[] = $statusFilterId;
    }

    if ($startDate !== null) {
        $conditions[] = 'd.fecha_desembarque >= ?';
        $types .= 's';
        $params[] = $startDate;
    }

    if ($endDate !== null) {
        $conditions[] = 'd.fecha_desembarque <= ?';
        $types .= 's';
        $params[] = $endDate;
    }

    if ($conditions !== []) {
        $query .= ' AND ' . implode(' AND ', $conditions);
    }

    $query .= ' ORDER BY d.fecha_desembarque DESC, d.id DESC';

    $statement = $connection->prepare($query);

    if (! $statement instanceof mysqli_stmt) {
        api_v1_error(500, translate('reports.error.prepare_statement', [], $language));
    }

    if ($types !== '') {
        $bindParams = [$types];

        foreach ($params as $index => &$value) {
            $bindParams[] =& $params[$index];
        }

        $statement->bind_param(...$bindParams);
    }

    $statement->execute();
    $result = $statement->get_result();

    $records = [];
    $totalCount = 0;
    $totalDiasTranscurridos = 0;
    $totalDiasFuera = 0;

    if ($result instanceof mysqli_result) {
        $today = new DateTimeImmutable('today');

        while ($row = $result->fetch_assoc()) {
            $totalCount++;

            $landingDateRaw = isset($row['fecha_desembarque']) ? trim((string) $row['fecha_desembarque']) : '';
            $departureDateRaw = isset($row['fecha_embarque']) ? trim((string) $row['fecha_embarque']) : '';
            $diasTranscurridos = isset($row['dias_transcurridos']) ? (int) $row['dias_transcurridos'] : 0;
            $diasFuera = isset($row['dias_fuera']) ? (int) $row['dias_fuera'] : 0;

            if ($landingDateRaw !== '') {
                try {
                    $landingDate = new DateTimeImmutable($landingDateRaw);
                    $diasTranscurridos = max(0, (int) $landingDate->diff($today)->format('%r%a'));

                    if ($departureDateRaw !== '') {
                        $departureDate = new DateTimeImmutable($departureDateRaw);
                        $diasFuera = max(0, (int) $landingDate->diff($departureDate)->format('%r%a'));
                    }
                } catch (Throwable $exception) {
                    // Keep stored values on parsing errors.
                }
            }

            $totalDiasTranscurridos += $diasTranscurridos;
            $totalDiasFuera += $diasFuera;

            $statusId = isset($row['status_id']) ? (int) $row['status_id'] : null;
            $statusNameEs = isset($row['status_name_es']) ? (string) $row['status_name_es'] : '';
            $statusNameEn = isset($row['status_name_en']) ? (string) $row['status_name_en'] : '';
            $statusSlug = isset($row['status_slug']) ? (string) $row['status_slug'] : '';
            $statusLabel = $language === 'en' && $statusNameEn !== '' ? $statusNameEn : $statusNameEs;

            if ($statusLabel === '' && $statusNameEn !== '') {
                $statusLabel = $statusNameEn;
            }

            if ($statusLabel === '' && $statusSlug !== '') {
                $statusLabel = $statusSlug;
            }

            $observacionesLegacy = isset($row['observaciones']) ? trim((string) $row['observaciones']) : '';
            $observacionesCount = isset($row['observaciones_count']) ? (int) $row['observaciones_count'] : 0;

            if ($observacionesLegacy !== '') {
                $observacionesCount += 1;
            }

            $records[] = [
                'id' => (int) $row['id'],
                'referencia' => (string) $row['referencia'],
                'descripcion' => (string) $row['descripcion'],
                'destino' => (string) $row['destino'],
                'folio_aviso' => (string) $row['folio_aviso'],
                'fecha_desembarque' => $landingDateRaw,
                'fecha_embarque' => $departureDateRaw,
                'dias_transcurridos' => $diasTranscurridos,
                'dias_fuera' => $diasFuera,
                'cliente' => (string) $row['cliente'],
                'client_id' => isset($row['client_id']) ? (int) $row['client_id'] : null,
                'status' => [
                    'id' => $statusId,
                    'slug' => $statusSlug,
                    'name' => $statusLabel,
                ],
                'observaciones' => [
                    'total' => $observacionesCount,
                    'legacy' => $observacionesLegacy !== '' ? $observacionesLegacy : null,
                    'last_created_at' => isset($row['observaciones_last_created_at'])
                        ? (string) $row['observaciones_last_created_at']
                        : null,
                ],
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'created_by' => [
                    'id' => isset($row['created_by']) ? (int) $row['created_by'] : null,
                    'name' => isset($row['created_by_name']) ? (string) $row['created_by_name'] : null,
                    'email' => isset($row['created_by_email']) ? (string) $row['created_by_email'] : null,
                ],
            ];
        }
    }

    $statement->close();

    api_v1_success(
        [
            'items' => $records,
        ],
        [
            'total' => $totalCount,
            'totals' => [
                'dias_transcurridos' => $totalDiasTranscurridos,
                'dias_fuera' => $totalDiasFuera,
            ],
            'filters' => [
                'fecha_inicio' => $startDate,
                'fecha_fin' => $endDate,
                'cliente_id' => $clientFilterId,
                'status_id' => $statusFilterId,
            ],
            'rate_limit' => $auth['rate_limit'],
        ]
    );
} catch (Throwable $exception) {
    api_v1_handle_exception($exception, $language);
}

/**
 * @param array<string, string> $errors
 */
function validate_api_date_filter(?string $value, string $field, array &$errors, string $language): ?string
{
    $value = $value !== null ? trim($value) : '';

    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (! $date || $date->format('Y-m-d') !== $value) {
        $errors[$field] = translate('validation.invalid_date', [], $language);
        return null;
    }

    return $value;
}
