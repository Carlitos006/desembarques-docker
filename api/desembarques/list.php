<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if (! isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/analytics.php';

/**
 * @param array<string, string> $errors
 */
function respondWithError(int $statusCode, string $message, array $errors = []): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'errors' => $errors,
    ]);
    exit;
}

/**
 * @param array<string, string> $errors
 */
function validateDateFilter(string $value, string $field, array &$errors, string $language): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (! $date || $date->format('Y-m-d') !== $value) {
        $errors[$field] = translate('validation.invalid_date', [], $language);
        return null;
    }

    return $date->format('Y-m-d');
}

/**
 * @param array<string, string> $row
 */
function formatDateDisplay(?string $value, string $format = 'd/m/Y', ?string $fallback = null): string
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

function dashboardNormalize(string $value): string
{
    $normalized = trim($value);

    if ($normalized === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $normalized = mb_strtolower($normalized, 'UTF-8');
    } else {
        $normalized = strtolower($normalized);
    }

    return preg_replace('/\s+/', ' ', $normalized);
}

function dashboardClientKey(string $name, string $email): string
{
    $normalizedName = dashboardNormalize($name);
    $normalizedEmail = dashboardNormalize($email);

    return $normalizedName . '|' . $normalizedEmail;
}

function dashboardHumanize(string $value): string
{
    $value = str_replace('_', ' ', $value);

    if (function_exists('mb_convert_case')) {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    return ucwords($value);
}

function dashboardFieldLabel(string $field, string $language): string
{
    $key = 'reports.dashboard.field.' . $field;
    $label = translate($key, [], $language);

    if ($label === $key || trim($label) === '') {
        return dashboardHumanize($field);
    }

    return $label;
}

function dashboardPeriodLabel(string $periodKey, string $language): string
{
    if (! preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $matches)) {
        return $periodKey;
    }

    $year = $matches[1];
    $month = $matches[2];

    $monthNames = [
        'es' => [
            '01' => 'Enero',
            '02' => 'Febrero',
            '03' => 'Marzo',
            '04' => 'Abril',
            '05' => 'Mayo',
            '06' => 'Junio',
            '07' => 'Julio',
            '08' => 'Agosto',
            '09' => 'Septiembre',
            '10' => 'Octubre',
            '11' => 'Noviembre',
            '12' => 'Diciembre',
        ],
        'en' => [
            '01' => 'January',
            '02' => 'February',
            '03' => 'March',
            '04' => 'April',
            '05' => 'May',
            '06' => 'June',
            '07' => 'July',
            '08' => 'August',
            '09' => 'September',
            '10' => 'October',
            '11' => 'November',
            '12' => 'December',
        ],
    ];

    $languageKey = in_array($language, ['es', 'en'], true) ? $language : 'es';
    $monthLabel = $monthNames[$languageKey][$month] ?? $month;

    return sprintf('%s %s', $monthLabel, $year);
}

function dashboardFormatIso(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);

    if ($trimmed === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($trimmed);

        return $date->format(DateTimeInterface::ATOM);
    } catch (Throwable $exception) {
        return null;
    }
}

/**
 * @param array<string, mixed> $row
 * @param array<int|string, string> $lastSeen
 */
function hasUnreadObservaciones(array $row, array $lastSeen): bool
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

$user = $_SESSION['user'];
$userRole = (string) ($user['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario', 'cliente'], true)) {
    respondWithError(403, translate('reports.permission_denied', [], $currentLanguage));
}

$userId = (int) $user['id'];
$userName = (string) ($user['name'] ?? '');
$userEmail = (string) ($user['email'] ?? '');
$observacionesLastSeen = isset($_SESSION['observaciones_last_seen']) && is_array($_SESSION['observaciones_last_seen'])
    ? $_SESSION['observaciones_last_seen']
    : [];

$rawStartDate = (string) ($_GET['fecha_inicio'] ?? '');
$rawEndDate = (string) ($_GET['fecha_fin'] ?? '');
$rawClientId = (string) ($_GET['cliente_id'] ?? '');
$rawStatusId = (string) ($_GET['status_id'] ?? '');
$rawOrigin = strtolower(trim((string) ($_GET['origen'] ?? '')));

$errors = [];

$startDate = validateDateFilter($rawStartDate, 'fecha_inicio', $errors, $currentLanguage);
$endDate = validateDateFilter($rawEndDate, 'fecha_fin', $errors, $currentLanguage);

if ($startDate !== null && $endDate !== null) {
    $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
    $endDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

    if ($startDateObj instanceof DateTimeImmutable && $endDateObj instanceof DateTimeImmutable && $startDateObj > $endDateObj) {
        $errors['fecha_fin'] = translate('reports.validation.date_range', [], $currentLanguage);
    }
}

$clientFilterId = null;
$statusFilterId = null;
$originFilter = null;

if ($userRole !== 'cliente') {
    $rawClientId = trim($rawClientId);
    if ($rawClientId !== '') {
        if (! ctype_digit($rawClientId)) {
            $errors['cliente_id'] = translate('reports.validation.client_invalid', [], $currentLanguage);
        } else {
            $clientFilterId = (int) $rawClientId;
        }
    }
}

$rawStatusId = trim($rawStatusId);
if ($rawStatusId !== '') {
    if (! ctype_digit($rawStatusId)) {
        $errors['status_id'] = translate('reports.validation.status_invalid', [], $currentLanguage);
    } else {
        $statusFilterId = (int) $rawStatusId;
    }
}

if ($rawOrigin !== '') {
    if (! in_array($rawOrigin, ['system', 'historical'], true)) {
        $errors['origen'] = translate('reports.validation.origin_invalid', [], $currentLanguage);
    } else {
        $originFilter = $rawOrigin;
    }
}

if ($errors !== []) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), $errors);
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

$query = <<<SQL
    SELECT
        d.id,
        d.referencia,
        d.fecha_desembarque,
        d.descripcion,
        d.observaciones,
        d.destino,
        d.folio_aviso,
        d.pedimento,
        d.cipl,
        d.manifiesto,
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
        COALESCE(NULLIF(TRIM(aviso_detail.source_type), ''), 'system') AS aviso_source_type,
        aviso_detail.aviso_status AS aviso_status,
        aviso_detail.notice_number AS aviso_notice_number,
        aviso_detail.document_code AS aviso_document_code,
        COALESCE(obs.total, 0) AS observaciones_count,
        obs.last_created_at AS observaciones_last_created_at
    FROM desembarques d
    LEFT JOIN users creator ON creator.id = d.created_by
    LEFT JOIN clients client ON client.id = d.client_id
    LEFT JOIN desembarque_statuses status ON status.id = d.status_id
    LEFT JOIN desembarque_aviso_details aviso_detail ON aviso_detail.desembarque_id = d.id
    LEFT JOIN (
        SELECT desembarque_id, COUNT(*) AS total, MAX(created_at) AS last_created_at
        FROM desembarque_observaciones
        GROUP BY desembarque_id
    ) obs ON obs.desembarque_id = d.id
    WHERE d.deleted_at IS NULL
SQL;

$types = '';
$params = [];
$conditions = [];

if ($userRole === 'cliente') {
    $normalizedClientName = mb_strtolower(trim($userName));
    $normalizedClientEmail = mb_strtolower(trim($userEmail));

    $clientConditions = [];
    $clientTypes = '';
    $clientParams = [];

    $clientConditions[] = 'client.user_id = ?';
    $clientTypes .= 'i';
    $clientParams[] = $userId;

    if ($normalizedClientEmail !== '') {
        $clientConditions[] = 'LOWER(TRIM(client.email)) = ?';
        $clientTypes .= 's';
        $clientParams[] = $normalizedClientEmail;
    }

    if ($normalizedClientName !== '' && $normalizedClientName !== $normalizedClientEmail) {
        $clientConditions[] = 'LOWER(TRIM(client.name)) = ?';
        $clientTypes .= 's';
        $clientParams[] = $normalizedClientName;
    }

    $fallbackConditions = [];

    if ($normalizedClientName !== '') {
        $fallbackConditions[] = 'LOWER(TRIM(d.cliente)) = ?';
        $clientTypes .= 's';
        $clientParams[] = $normalizedClientName;
    }

    if ($normalizedClientEmail !== '' && $normalizedClientEmail !== $normalizedClientName) {
        $fallbackConditions[] = 'LOWER(TRIM(d.cliente)) = ?';
        $clientTypes .= 's';
        $clientParams[] = $normalizedClientEmail;
    }

    if ($fallbackConditions !== []) {
        $clientConditions[] = '(' . implode(' OR ', $fallbackConditions) . ')';
    }

    $conditions[] = '(' . implode(' OR ', $clientConditions) . ')';
    $types .= $clientTypes;

    foreach ($clientParams as $clientParam) {
        $params[] = $clientParam;
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

if ($originFilter === 'historical') {
    $conditions[] = "aviso_detail.source_type = 'historical_import'";
} elseif ($originFilter === 'system') {
    $conditions[] = "(aviso_detail.source_type IS NULL OR TRIM(aviso_detail.source_type) = '' OR aviso_detail.source_type <> 'historical_import')";
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

try {
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException(translate('reports.error.prepare_statement', [], $currentLanguage));
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
            $diasTranscurridos = 0;
            $diasFuera = 0;

            $rawLandingDate = isset($row['fecha_desembarque']) ? trim((string) $row['fecha_desembarque']) : null;
            $rawDepartureDate = isset($row['fecha_embarque']) ? trim((string) $row['fecha_embarque']) : null;
            $landingDate = null;

            if ($rawLandingDate !== null && $rawLandingDate !== '') {
                try {
                    $landingDate = new DateTimeImmutable($rawLandingDate);
                    $daysDifference = (int) $landingDate->diff($today)->format('%r%a');
                    $diasTranscurridos = max(0, $daysDifference);
                } catch (Throwable $exception) {
                    $landingDate = null;
                }
            }

            if ($landingDate instanceof DateTimeImmutable && $rawDepartureDate !== null && $rawDepartureDate !== '') {
                try {
                    $departureDate = new DateTimeImmutable($rawDepartureDate);
                    $daysDifference = (int) $landingDate->diff($departureDate)->format('%r%a');
                    $diasFuera = max(0, $daysDifference);
                } catch (Throwable $exception) {
                    $diasFuera = 0;
                }
            }

            $totalDiasTranscurridos += $diasTranscurridos;
            $totalDiasFuera += $diasFuera;

            $statusId = null;
            if (isset($row['status_id']) && $row['status_id'] !== null && $row['status_id'] !== '') {
                $statusId = (int) $row['status_id'];
            }

            $statusNameEs = isset($row['status_name_es']) ? (string) $row['status_name_es'] : '';
            $statusNameEn = isset($row['status_name_en']) ? (string) $row['status_name_en'] : '';
            $statusSlug = isset($row['status_slug']) ? (string) $row['status_slug'] : '';
            $statusLabel = $currentLanguage === 'en' && $statusNameEn !== '' ? $statusNameEn : $statusNameEs;

            if ($statusLabel === '' && $statusNameEn !== '') {
                $statusLabel = $statusNameEn;
            }

            if ($statusLabel === '' && $statusSlug !== '') {
                $statusLabel = $statusSlug;
            }

            $avisoSourceType = strtolower(trim((string) ($row['aviso_source_type'] ?? 'system')));
            if ($avisoSourceType === '') {
                $avisoSourceType = 'system';
            }
            $isHistoricalAviso = $avisoSourceType === 'historical_import';

            $legacyObservaciones = isset($row['observaciones']) ? (string) $row['observaciones'] : '';
            $hasLegacyObservaciones = trim($legacyObservaciones) !== '';
            $observacionesCount = isset($row['observaciones_count']) ? (int) $row['observaciones_count'] : 0;

            if ($hasLegacyObservaciones) {
                $observacionesCount += 1;
            }

            $records[] = [
                'id' => (int) $row['id'],
                'referencia' => (string) $row['referencia'],
                'fecha_desembarque' => (string) $row['fecha_desembarque'],
                'fecha_desembarque_display' => formatDateDisplay($row['fecha_desembarque'] ?? null),
                'fecha_embarque' => (string) $row['fecha_embarque'],
                'fecha_embarque_display' => formatDateDisplay($row['fecha_embarque'] ?? null),
                'descripcion' => (string) $row['descripcion'],
                'observaciones' => '',
                'observaciones_count' => $observacionesCount,
                'has_legacy_observaciones' => $hasLegacyObservaciones,
                'observaciones_last_created_at' => (string) ($row['observaciones_last_created_at'] ?? ''),
                'observaciones_last_created_at_display' => formatDateDisplay($row['observaciones_last_created_at'] ?? null, 'd/m/Y H:i'),
                'has_unread_observaciones' => hasUnreadObservaciones($row, $observacionesLastSeen),
                'destino' => (string) $row['destino'],
                'folio_aviso' => (string) $row['folio_aviso'],
                'pedimento' => isset($row['pedimento']) && $row['pedimento'] !== null
                    ? (string) $row['pedimento']
                    : '',
                'cipl' => isset($row['cipl']) && $row['cipl'] !== null
                    ? (string) $row['cipl']
                    : '',
                'manifiesto' => isset($row['manifiesto']) && $row['manifiesto'] !== null
                    ? (string) $row['manifiesto']
                    : '',
                'barco' => (string) $row['barco'],
                'cliente' => (string) $row['cliente'],
                'client_id' => $row['client_id'] !== null ? (int) $row['client_id'] : null,
                'dias_transcurridos' => $diasTranscurridos,
                'dias_fuera' => $diasFuera,
                'created_by' => (int) $row['created_by'],
                'created_by_name' => (string) ($row['created_by_name'] ?? ''),
                'created_by_email' => (string) ($row['created_by_email'] ?? ''),
                'client_name' => (string) ($row['client_name'] ?? ''),
                'client_email' => (string) ($row['client_email'] ?? ''),
                'status_id' => $statusId,
                'status_slug' => $statusSlug,
                'status_label' => $statusLabel,
                'aviso_source_type' => $avisoSourceType,
                'aviso_origin' => $isHistoricalAviso ? 'historical' : 'system',
                'is_historical_aviso' => $isHistoricalAviso,
                'aviso_status' => (string) ($row['aviso_status'] ?? ''),
                'aviso_notice_number' => (string) ($row['aviso_notice_number'] ?? ''),
                'aviso_document_code' => (string) ($row['aviso_document_code'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'created_at_display' => formatDateDisplay($row['created_at'] ?? null, 'd/m/Y H:i'),
                'attachments' => [],
                'pedimento_header_numbers' => [],
                'pedimento_header_references' => [],
                'milestone_confirmation' => [
                    'confirmed' => false,
                    'confirmed_at' => '',
                    'confirmed_by_current_user' => false,
                    'confirmed_at_by_current_user' => '',
                    'confirmations_count' => 0,
                ],
            ];
        }

        $result->free();
    }

    $statement->close();

    if ($records !== []) {
        $recordIds = array_map(
            static function (array $record): int {
                return isset($record['id']) ? (int) $record['id'] : 0;
            },
            $records
        );

        $recordIds = array_values(array_filter($recordIds));

        if ($recordIds !== []) {
            $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
            $typesAttachments = str_repeat('i', count($recordIds));
            $attachmentsQuery = "SELECT id, desembarque_id, original_name, mime_type, extension, size FROM desembarque_files WHERE desembarque_id IN (" . $placeholders . ") AND COALESCE(purpose, 'attachment') <> 'source_excel' AND COALESCE(is_active, 1) = 1 ORDER BY created_at ASC, id ASC";

            $attachmentsStatement = $connection->prepare($attachmentsQuery);

            if (! $attachmentsStatement) {
                throw new RuntimeException(translate('desembarques.files.load_error', [], $currentLanguage));
            }

            $bindParams = [$typesAttachments];

            foreach ($recordIds as $index => $recordIdValue) {
                $bindParams[] =& $recordIds[$index];
            }

            $attachmentsStatement->bind_param(...$bindParams);
            $attachmentsStatement->execute();

            $attachmentsResult = $attachmentsStatement->get_result();
            $attachmentsByRecord = [];

            if ($attachmentsResult instanceof mysqli_result) {
                while ($attachmentRow = $attachmentsResult->fetch_assoc()) {
                    $recordId = isset($attachmentRow['desembarque_id']) ? (int) $attachmentRow['desembarque_id'] : 0;
                    $attachmentId = isset($attachmentRow['id']) ? (int) $attachmentRow['id'] : 0;

                    if ($recordId <= 0 || $attachmentId <= 0) {
                        continue;
                    }

                    $attachmentsByRecord[$recordId][] = [
                        'id' => $attachmentId,
                        'original_name' => (string) ($attachmentRow['original_name'] ?? ''),
                        'mime_type' => (string) ($attachmentRow['mime_type'] ?? ''),
                        'extension' => (string) ($attachmentRow['extension'] ?? ''),
                        'size' => isset($attachmentRow['size']) ? (int) $attachmentRow['size'] : 0,
                        'download_url' => '../api/desembarques/files/download.php?id=' . $attachmentId,
                    ];
                }

                $attachmentsResult->free();
            }

            $attachmentsStatement->close();

            $confirmationsTotals = [];
            $confirmationsByCurrentUser = [];
            $confirmationsCounts = [];

            $confirmationsQuery = sprintf(
                'SELECT desembarque_id, confirmed_by, confirmed_at FROM desembarque_milestone_confirmations WHERE desembarque_id IN (%s)',
                $placeholders
            );

            $confirmationsStatement = $connection->prepare($confirmationsQuery);

            if (! $confirmationsStatement) {
                throw new RuntimeException(translate('client_portal.milestones.confirm_error', [], $currentLanguage));
            }

            $confirmationsBindParams = [$typesAttachments];

            foreach ($recordIds as $index => &$recordIdValue) {
                $confirmationsBindParams[] =& $recordIds[$index];
            }
            unset($recordIdValue);

            $confirmationsStatement->bind_param(...$confirmationsBindParams);
            $confirmationsStatement->execute();

            $confirmationsResult = $confirmationsStatement->get_result();

            if ($confirmationsResult instanceof mysqli_result) {
                while ($confirmationRow = $confirmationsResult->fetch_assoc()) {
                    $recordId = isset($confirmationRow['desembarque_id']) ? (int) $confirmationRow['desembarque_id'] : 0;
                    $confirmedBy = isset($confirmationRow['confirmed_by']) ? (int) $confirmationRow['confirmed_by'] : 0;
                    $confirmedAt = isset($confirmationRow['confirmed_at']) ? (string) $confirmationRow['confirmed_at'] : '';

                    if ($recordId <= 0 || $confirmedBy <= 0) {
                        continue;
                    }

                    if (! isset($confirmationsTotals[$recordId])) {
                        $confirmationsTotals[$recordId] = $confirmedAt;
                    } elseif ($confirmedAt !== '' && $confirmationsTotals[$recordId] < $confirmedAt) {
                        $confirmationsTotals[$recordId] = $confirmedAt;
                    }

                    if (! isset($confirmationsCounts[$recordId])) {
                        $confirmationsCounts[$recordId] = [];
                    }

                    if (! in_array($confirmedBy, $confirmationsCounts[$recordId], true)) {
                        $confirmationsCounts[$recordId][] = $confirmedBy;
                    }

                    if ($confirmedBy === $userId) {
                        $confirmationsByCurrentUser[$recordId] = $confirmedAt;
                    }
                }

                $confirmationsResult->free();
            }

            $confirmationsStatement->close();

            $referenceTables = [
                'pedimentos' => 'desembarque_pedimentos',
                'manifests' => 'desembarque_manifests',
                'cipls' => 'desembarque_cipls',
            ];
            $referencesByRecord = [
                'pedimentos' => [],
                'manifests' => [],
                'cipls' => [],
            ];
            $referencesSeen = [
                'pedimentos' => [],
                'manifests' => [],
                'cipls' => [],
            ];

            foreach ($referenceTables as $referenceKey => $tableName) {
                $referenceQuery = sprintf(
                    'SELECT desembarque_id, reference FROM %s WHERE desembarque_id IN (%s) ORDER BY id ASC',
                    $tableName,
                    $placeholders
                );

                $referenceStatement = $connection->prepare($referenceQuery);

                if (! $referenceStatement) {
                    throw new RuntimeException(translate('desembarques.references.load_error', [], $currentLanguage));
                }

                $referenceBindParams = [$typesAttachments];

                foreach ($recordIds as $index => &$recordIdValue) {
                    $referenceBindParams[] =& $recordIds[$index];
                }
                unset($recordIdValue);

                $referenceStatement->bind_param(...$referenceBindParams);
                $referenceStatement->execute();

                $referenceResult = $referenceStatement->get_result();

                if ($referenceResult instanceof mysqli_result) {
                    while ($referenceRow = $referenceResult->fetch_assoc()) {
                        $recordId = isset($referenceRow['desembarque_id']) ? (int) $referenceRow['desembarque_id'] : 0;
                        $rawReference = isset($referenceRow['reference'])
                            ? (string) $referenceRow['reference']
                            : '';
                        $referenceValue = trim(strip_tags($rawReference));

                        if ($referenceValue === '') {
                            continue;
                        }

                        if (mb_strlen($referenceValue) > 191) {
                            $referenceValue = mb_substr($referenceValue, 0, 191);
                        }

                        if ($recordId <= 0) {
                            continue;
                        }

                        if (! isset($referencesSeen[$referenceKey][$recordId])) {
                            $referencesSeen[$referenceKey][$recordId] = [];
                        }

                        $normalizedKey = mb_strtolower($referenceValue);

                        if (isset($referencesSeen[$referenceKey][$recordId][$normalizedKey])) {
                            continue;
                        }

                        $referencesSeen[$referenceKey][$recordId][$normalizedKey] = true;
                        $referencesByRecord[$referenceKey][$recordId][] = $referenceValue;
                    }

                    $referenceResult->free();
                }

                $referenceStatement->close();
            }

            $pedimentoHeaderNumbers = [];
            $pedimentoHeaderNumbersSeen = [];
            $pedimentoHeaderReferences = [];
            $pedimentoHeaderReferencesSeen = [];
            $pedimentoHeaderRecordMap = [];
            $pedimentoHeaderPackages = [];
            $pedimentoHeaderPackageIndex = [];
            $pedimentoHeaderPackageReferenceSeen = [];

            $headersQuery = sprintf(
                'SELECT id, desembarque_id, num_pedimento, cve_pedimento, razon_social, fecha_entrada, fecha_pago FROM desembarque_pedimento_headers WHERE desembarque_id IN (%s) ORDER BY num_pedimento IS NULL ASC, num_pedimento ASC, id ASC',
                $placeholders
            );

            $headersStatement = $connection->prepare($headersQuery);

            if (! $headersStatement) {
                throw new RuntimeException('Unable to prepare the pedimento header lookup statement.');
            }

            $headersBindParams = [$typesAttachments];

            foreach ($recordIds as $index => &$recordIdValue) {
                $headersBindParams[] =& $recordIds[$index];
            }
            unset($recordIdValue);

            $headersStatement->bind_param(...$headersBindParams);
            $headersStatement->execute();

            $headersResult = $headersStatement->get_result();
            $headerIds = [];

                if ($headersResult instanceof mysqli_result) {
                    while ($headerRow = $headersResult->fetch_assoc()) {
                        $headerId = isset($headerRow['id']) ? (int) $headerRow['id'] : 0;
                        $recordId = isset($headerRow['desembarque_id']) ? (int) $headerRow['desembarque_id'] : 0;

                        if ($headerId <= 0 || $recordId <= 0) {
                            continue;
                        }

                        $pedimentoHeaderRecordMap[$headerId] = $recordId;
                        $headerIds[] = $headerId;

                        $rawNumber = isset($headerRow['num_pedimento']) ? (string) $headerRow['num_pedimento'] : '';
                        $numberValue = trim(strip_tags($rawNumber));

                        if ($numberValue !== '' && mb_strlen($numberValue) > 191) {
                            $numberValue = mb_substr($numberValue, 0, 191);
                        }

                        if (! isset($pedimentoHeaderPackages[$recordId])) {
                            $pedimentoHeaderPackages[$recordId] = [];
                        }

                        $pedimentoHeaderPackages[$recordId][] = [
                            'id' => $headerId,
                            'num_pedimento' => $numberValue !== '' ? $numberValue : null,
                            'cve_pedimento' => isset($headerRow['cve_pedimento']) && trim((string) $headerRow['cve_pedimento']) !== ''
                                ? trim((string) $headerRow['cve_pedimento'])
                                : null,
                            'razon_social' => isset($headerRow['razon_social']) && trim((string) $headerRow['razon_social']) !== ''
                                ? trim((string) $headerRow['razon_social'])
                                : null,
                            'fecha_entrada' => isset($headerRow['fecha_entrada']) && trim((string) $headerRow['fecha_entrada']) !== ''
                                ? trim((string) $headerRow['fecha_entrada'])
                                : null,
                            'fecha_pago' => isset($headerRow['fecha_pago']) && trim((string) $headerRow['fecha_pago']) !== ''
                                ? trim((string) $headerRow['fecha_pago'])
                                : null,
                            'pedimentos' => [],
                        ];

                        $pedimentoHeaderPackageIndex[$headerId] = [
                            'record_id' => $recordId,
                            'index' => count($pedimentoHeaderPackages[$recordId]) - 1,
                        ];

                        $pedimentoHeaderPackageReferenceSeen[$headerId] = [];

                        if ($numberValue === '') {
                            continue;
                        }

                        if (! isset($pedimentoHeaderNumbersSeen[$recordId])) {
                            $pedimentoHeaderNumbersSeen[$recordId] = [];
                        }

                        $normalizedNumber = mb_strtolower($numberValue);

                        if (isset($pedimentoHeaderNumbersSeen[$recordId][$normalizedNumber])) {
                            continue;
                        }

                        $pedimentoHeaderNumbersSeen[$recordId][$normalizedNumber] = true;
                        $pedimentoHeaderNumbers[$recordId][] = $numberValue;
                    }

                    $headersResult->free();
                }

            $headersStatement->close();

            if ($headerIds !== []) {
                $headerPlaceholders = implode(',', array_fill(0, count($headerIds), '?'));
                $headerTypes = str_repeat('i', count($headerIds));
                $linksQuery = 'SELECT header_id, reference FROM desembarque_pedimento_header_links WHERE header_id IN (' . $headerPlaceholders . ') ORDER BY id ASC';

                $linksStatement = $connection->prepare($linksQuery);

                if (! $linksStatement) {
                    throw new RuntimeException('Unable to prepare the pedimento header links lookup statement.');
                }

                $linksBindParams = [$headerTypes];

                foreach ($headerIds as $index => &$headerIdValue) {
                    $linksBindParams[] =& $headerIds[$index];
                }
                unset($headerIdValue);

                $linksStatement->bind_param(...$linksBindParams);
                $linksStatement->execute();

                $linksResult = $linksStatement->get_result();

                if ($linksResult instanceof mysqli_result) {
                    while ($linkRow = $linksResult->fetch_assoc()) {
                        $headerId = isset($linkRow['header_id']) ? (int) $linkRow['header_id'] : 0;

                        if ($headerId <= 0 || ! isset($pedimentoHeaderRecordMap[$headerId])) {
                            continue;
                        }

                        $recordId = $pedimentoHeaderRecordMap[$headerId];
                        $rawReference = isset($linkRow['reference']) ? (string) $linkRow['reference'] : '';
                        $referenceValue = trim(strip_tags($rawReference));

                        if ($referenceValue === '') {
                            continue;
                        }

                        if (mb_strlen($referenceValue) > 191) {
                            $referenceValue = mb_substr($referenceValue, 0, 191);
                        }

                        if (! isset($pedimentoHeaderReferencesSeen[$recordId])) {
                            $pedimentoHeaderReferencesSeen[$recordId] = [];
                        }

                        $normalizedReference = mb_strtolower($referenceValue);

                        if (! isset($pedimentoHeaderReferencesSeen[$recordId][$normalizedReference])) {
                            $pedimentoHeaderReferencesSeen[$recordId][$normalizedReference] = true;
                            $pedimentoHeaderReferences[$recordId][] = $referenceValue;
                        }

                        if (! isset($pedimentoHeaderPackageIndex[$headerId])) {
                            continue;
                        }

                        $packageInfo = $pedimentoHeaderPackageIndex[$headerId];
                        $packageRecordId = $packageInfo['record_id'];
                        $packageIndex = $packageInfo['index'];

                        if (! isset($pedimentoHeaderPackages[$packageRecordId][$packageIndex])) {
                            continue;
                        }

                        if (! isset($pedimentoHeaderPackageReferenceSeen[$headerId][$normalizedReference])) {
                            $pedimentoHeaderPackageReferenceSeen[$headerId][$normalizedReference] = true;
                            $pedimentoHeaderPackages[$packageRecordId][$packageIndex]['pedimentos'][] = $referenceValue;
                        }
                    }

                    $linksResult->free();
                }

                $linksStatement->close();
            }

            foreach ($records as &$record) {
                $recordId = isset($record['id']) ? (int) $record['id'] : 0;

                if ($recordId > 0 && isset($attachmentsByRecord[$recordId])) {
                    $record['attachments'] = $attachmentsByRecord[$recordId];
                }

                if ($recordId > 0) {
                    if (isset($confirmationsTotals[$recordId])) {
                        $record['milestone_confirmation']['confirmed'] = true;
                        $record['milestone_confirmation']['confirmed_at'] = (string) $confirmationsTotals[$recordId];
                    }

                    if (isset($confirmationsByCurrentUser[$recordId])) {
                        $record['milestone_confirmation']['confirmed_by_current_user'] = true;
                        $record['milestone_confirmation']['confirmed_at_by_current_user'] = (string) $confirmationsByCurrentUser[$recordId];
                    }

                    if (isset($confirmationsCounts[$recordId])) {
                        $record['milestone_confirmation']['confirmations_count'] = count($confirmationsCounts[$recordId]);
                    }

                    $pedimentosList = $referencesByRecord['pedimentos'][$recordId] ?? [];
                    $manifestsList = $referencesByRecord['manifests'][$recordId] ?? [];
                    $ciplsList = $referencesByRecord['cipls'][$recordId] ?? [];

                    $pedimentoHeaderPackagesForRecord = $pedimentoHeaderPackages[$recordId] ?? [];
                    $normalizedPackages = [];

                    foreach ($pedimentoHeaderPackagesForRecord as $package) {
                        $number = isset($package['num_pedimento']) ? $package['num_pedimento'] : null;
                        $references = isset($package['pedimentos']) && is_array($package['pedimentos'])
                            ? array_values($package['pedimentos'])
                            : [];

                        if ($number === null && $references === []) {
                            continue;
                        }

                        $normalizedPackages[] = [
                            'id' => isset($package['id']) ? (int) $package['id'] : 0,
                            'num_pedimento' => $number,
                            'cve_pedimento' => isset($package['cve_pedimento']) ? $package['cve_pedimento'] : null,
                            'razon_social' => isset($package['razon_social']) ? $package['razon_social'] : null,
                            'fecha_entrada' => isset($package['fecha_entrada']) ? $package['fecha_entrada'] : null,
                            'fecha_pago' => isset($package['fecha_pago']) ? $package['fecha_pago'] : null,
                            'pedimentos' => $references,
                        ];
                    }

                    $record['pedimento_header_numbers'] = $pedimentoHeaderNumbers[$recordId] ?? [];
                    $record['pedimento_header_references'] = $pedimentoHeaderReferences[$recordId] ?? [];
                    $record['pedimento_headers'] = $normalizedPackages;

                    $record['pedimentos'] = $pedimentosList;
                    $record['manifests'] = $manifestsList;
                    $record['cipls'] = $ciplsList;

                    if (! isset($record['pedimento']) || trim((string) $record['pedimento']) === '') {
                        $record['pedimento'] = $pedimentosList !== [] ? $pedimentosList[0] : '';
                    }
                } else {
                    $record['pedimentos'] = [];
                    $record['manifests'] = [];
                    $record['cipls'] = [];
                    $record['pedimento_header_numbers'] = [];
                    $record['pedimento_header_references'] = [];
                    $record['pedimento_headers'] = [];
                    if (! isset($record['pedimento'])) {
                        $record['pedimento'] = '';
                    }
                }
            }
            unset($record);
        }
    }
} catch (Throwable $exception) {
    respondWithError(500, translate('reports.error.unexpected', ['error' => $exception->getMessage()], $currentLanguage));
}

$targetSlaHours = 72;
$warningSlaHours = 96;

try {
    $analyticsConfig = getAnalyticsConfig();

    if (isset($analyticsConfig['sla']['target_hours'])) {
        $targetSlaHours = (int) $analyticsConfig['sla']['target_hours'];
    }

    if (isset($analyticsConfig['sla']['warning_hours'])) {
        $warningSlaHours = (int) $analyticsConfig['sla']['warning_hours'];
    }
} catch (Throwable $exception) {
    $analyticsConfig = [];
}

$targetSlaHours = max($targetSlaHours, 0);
$warningSlaHours = max($warningSlaHours, 0);

if ($targetSlaHours === 0 && $warningSlaHours > 0) {
    $targetSlaHours = $warningSlaHours;
}

if ($warningSlaHours === 0 && $targetSlaHours > 0) {
    $warningSlaHours = $targetSlaHours;
}

if ($targetSlaHours === 0 && $warningSlaHours === 0) {
    $targetSlaHours = 72;
    $warningSlaHours = 96;
}

$cautionThresholdHours = min($targetSlaHours, $warningSlaHours);
$severeThresholdHours = max($targetSlaHours, $warningSlaHours);

if ($cautionThresholdHours <= 0) {
    $cautionThresholdHours = $severeThresholdHours;
}

if ($severeThresholdHours <= 0) {
    $severeThresholdHours = $cautionThresholdHours;
}

if ($cautionThresholdHours <= 0) {
    $cautionThresholdHours = 72;

    if ($severeThresholdHours <= 0) {
        $severeThresholdHours = 96;
    }
}

$unknownClientLabel = translate('reports.charts.unknown_client', [], $currentLanguage);

$dashboardData = [
    'status' => [],
    'clients' => [],
    'periods' => [],
    'sla' => [
        'warning_threshold_hours' => $cautionThresholdHours,
        'breach_threshold_hours' => $severeThresholdHours,
        'warning_count' => 0,
        'breach_count' => 0,
        'at_risk' => [],
    ],
    'recent_changes' => [],
];

if ($records !== []) {
    $statusTotals = [];
    $clientTotals = [];
    $periodTotals = [];
    $atRiskRecords = [];
    $recordsMeta = [];
    $slaWarningCount = 0;
    $slaBreachCount = 0;

    foreach ($records as $record) {
        $recordId = isset($record['id']) ? (int) $record['id'] : 0;

        if ($recordId <= 0) {
            continue;
        }

        $statusSlug = (string) ($record['status_slug'] ?? '');

        if ($statusSlug === '') {
            $statusSlug = 'unknown';
        }

        $statusLabel = (string) ($record['status_label'] ?? $statusSlug);
        $statusIdValue = isset($record['status_id']) ? (int) $record['status_id'] : null;

        if (! isset($statusTotals[$statusSlug])) {
            $statusTotals[$statusSlug] = [
                'slug' => $statusSlug,
                'label' => $statusLabel,
                'count' => 0,
                'total_dias_transcurridos' => 0,
                'total_dias_fuera' => 0,
                'status_id' => $statusIdValue,
            ];
        }

        $statusTotals[$statusSlug]['count'] += 1;
        $statusTotals[$statusSlug]['total_dias_transcurridos'] += (int) ($record['dias_transcurridos'] ?? 0);
        $statusTotals[$statusSlug]['total_dias_fuera'] += (int) ($record['dias_fuera'] ?? 0);

        $clientName = trim((string) ($record['client_name'] ?? ''));
        $clientEmail = trim((string) ($record['client_email'] ?? ''));
        $fallbackClient = trim((string) ($record['cliente'] ?? ''));

        if ($clientName === '') {
            $clientName = $fallbackClient;
        }

        $clientDisplay = $clientName !== '' ? $clientName : $clientEmail;

        if ($clientDisplay === '') {
            $clientDisplay = $unknownClientLabel;
        }

        $clientKey = dashboardClientKey($clientName !== '' ? $clientName : $fallbackClient, $clientEmail);

        if ($clientKey === '|') {
            $clientKey = dashboardClientKey($unknownClientLabel, '');
        }

        if (! isset($clientTotals[$clientKey])) {
            $clientTotals[$clientKey] = [
                'name' => $clientName,
                'email' => $clientEmail,
                'display' => $clientDisplay,
                'count' => 0,
            ];
        }

        $clientTotals[$clientKey]['count'] += 1;

        $landingDate = isset($record['fecha_desembarque']) ? (string) $record['fecha_desembarque'] : '';

        if ($landingDate !== '') {
            $periodKey = substr($landingDate, 0, 7);

            if (! isset($periodTotals[$periodKey])) {
                $periodTotals[$periodKey] = [
                    'period' => $periodKey,
                    'count' => 0,
                    'total_dias_transcurridos' => 0,
                ];
            }

            $periodTotals[$periodKey]['count'] += 1;
            $periodTotals[$periodKey]['total_dias_transcurridos'] += (int) ($record['dias_transcurridos'] ?? 0);
        }

        $diasTranscurridosValue = (int) ($record['dias_transcurridos'] ?? 0);
        $hoursElapsed = $diasTranscurridosValue * 24;
        $riskLevel = null;

        if ($severeThresholdHours > 0 && $hoursElapsed >= $severeThresholdHours) {
            $riskLevel = 'breach';
            $slaBreachCount += 1;
        } elseif ($cautionThresholdHours > 0 && $hoursElapsed >= $cautionThresholdHours) {
            $riskLevel = 'warning';
            $slaWarningCount += 1;
        }

        if ($riskLevel !== null) {
            $atRiskRecords[] = [
                'id' => $recordId,
                'reference' => (string) ($record['referencia'] ?? ''),
                'client' => $clientDisplay,
                'dias_transcurridos' => $diasTranscurridosValue,
                'status_label' => $statusLabel,
                'risk_level' => $riskLevel,
            ];
        }

        $recordsMeta[$recordId] = [
            'reference' => (string) ($record['referencia'] ?? ''),
            'client' => $clientDisplay,
            'status_label' => $statusLabel,
            'status_slug' => $statusSlug,
            'dias_transcurridos' => $diasTranscurridosValue,
            'risk_level' => $riskLevel,
        ];
    }

    $statusSummary = array_values($statusTotals);

    usort(
        $statusSummary,
        static function (array $left, array $right): int {
            $countComparison = ($right['count'] ?? 0) <=> ($left['count'] ?? 0);

            if ($countComparison !== 0) {
                return $countComparison;
            }

            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        }
    );

    foreach ($statusSummary as &$statusEntry) {
        $countValue = isset($statusEntry['count']) ? (int) $statusEntry['count'] : 0;
        $totalDias = isset($statusEntry['total_dias_transcurridos'])
            ? (int) $statusEntry['total_dias_transcurridos']
            : 0;

        $statusEntry['percentage'] = $totalCount > 0
            ? round(($countValue / $totalCount) * 100, 1)
            : 0.0;

        $statusEntry['average_dias_transcurridos'] = $countValue > 0
            ? round($totalDias / $countValue, 2)
            : 0.0;

        unset($statusEntry['total_dias_transcurridos'], $statusEntry['total_dias_fuera']);
    }
    unset($statusEntry);

    $clientSummary = array_values($clientTotals);

    usort(
        $clientSummary,
        static function (array $left, array $right): int {
            return ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
        }
    );

    $clientSummary = array_slice($clientSummary, 0, 6);

    foreach ($clientSummary as &$clientEntry) {
        $clientEntry['percentage'] = $totalCount > 0
            ? round(((int) ($clientEntry['count'] ?? 0) / $totalCount) * 100, 1)
            : 0.0;

        if (trim((string) ($clientEntry['display'] ?? '')) === '') {
            $clientEntry['display'] = $unknownClientLabel;
        }
    }
    unset($clientEntry);

    $periodSummary = array_values($periodTotals);

    usort(
        $periodSummary,
        static function (array $left, array $right): int {
            return strcmp((string) ($right['period'] ?? ''), (string) ($left['period'] ?? ''));
        }
    );

    $periodSummary = array_slice($periodSummary, 0, 6);
    $periodSummary = array_reverse($periodSummary);

    foreach ($periodSummary as &$periodEntry) {
        $countValue = isset($periodEntry['count']) ? (int) $periodEntry['count'] : 0;
        $totalDias = isset($periodEntry['total_dias_transcurridos'])
            ? (int) $periodEntry['total_dias_transcurridos']
            : 0;

        $periodKey = (string) ($periodEntry['period'] ?? '');
        $periodEntry['label'] = dashboardPeriodLabel($periodKey, $currentLanguage);
        $periodEntry['average_dias_transcurridos'] = $countValue > 0
            ? round($totalDias / $countValue, 2)
            : 0.0;

        unset($periodEntry['total_dias_transcurridos']);
    }
    unset($periodEntry);

    usort(
        $atRiskRecords,
        static function (array $left, array $right): int {
            return ($right['dias_transcurridos'] ?? 0) <=> ($left['dias_transcurridos'] ?? 0);
        }
    );

    $atRiskRecords = array_slice($atRiskRecords, 0, 5);

    $recordIdsForDashboard = array_map(
        static function (int $key): int {
            return $key;
        },
        array_keys($recordsMeta)
    );

    $recentChanges = [];

    if ($recordIdsForDashboard !== []) {
        $placeholders = implode(',', array_fill(0, count($recordIdsForDashboard), '?'));

        $auditQuery = sprintf(
            'SELECT logs.id, logs.action, logs.entity_id, logs.payload, logs.created_at, logs.user_id, '
            . 'users.name AS user_name, users.email AS user_email, '
            . 'd.id AS record_id, d.referencia, d.cliente, d.dias_transcurridos AS record_dias_transcurridos, '
            . 'status.slug AS status_slug, status.name_es AS status_name_es, status.name_en AS status_name_en '
            . 'FROM audit_logs logs '
            . 'INNER JOIN desembarques d ON d.id = CAST(logs.entity_id AS UNSIGNED) '
            . 'LEFT JOIN users ON users.id = logs.user_id '
            . 'LEFT JOIN desembarque_statuses status ON status.id = d.status_id '
            . 'WHERE logs.entity_type = ? AND d.deleted_at IS NULL AND d.id IN (%s) '
            . 'ORDER BY logs.created_at DESC '
            . 'LIMIT 10',
            $placeholders
        );

        $auditStatement = $connection->prepare($auditQuery);

        if ($auditStatement instanceof mysqli_stmt) {
            $types = 's' . str_repeat('i', count($recordIdsForDashboard));
            $bindParams = [$types];
            $entityType = 'desembarque';
            $bindParams[] = &$entityType;

            foreach ($recordIdsForDashboard as $index => &$recordIdValue) {
                $bindParams[] =& $recordIdsForDashboard[$index];
            }

            $auditStatement->bind_param(...$bindParams);
            $auditStatement->execute();

            $auditResult = $auditStatement->get_result();

            if ($auditResult instanceof mysqli_result) {
                while ($auditRow = $auditResult->fetch_assoc()) {
                    $recordId = isset($auditRow['record_id']) ? (int) $auditRow['record_id'] : 0;

                    if ($recordId <= 0) {
                        continue;
                    }

                    $meta = $recordsMeta[$recordId] ?? [
                        'reference' => (string) ($auditRow['referencia'] ?? ''),
                        'client' => (string) ($auditRow['cliente'] ?? ''),
                        'status_label' => '',
                        'status_slug' => (string) ($auditRow['status_slug'] ?? ''),
                        'dias_transcurridos' => isset($auditRow['record_dias_transcurridos'])
                            ? (int) $auditRow['record_dias_transcurridos']
                            : 0,
                        'risk_level' => null,
                    ];

                    $payloadRaw = isset($auditRow['payload']) ? (string) $auditRow['payload'] : '';
                    $payload = [];

                    if ($payloadRaw !== '') {
                        $decodedPayload = json_decode($payloadRaw, true);

                        if (is_array($decodedPayload)) {
                            $payload = $decodedPayload;
                        }
                    }

                    $changes = [];

                    if (isset($payload['changes']) && is_array($payload['changes'])) {
                        $changes = $payload['changes'];
                    }

                    $attachmentsChanges = [];

                    if (isset($payload['attachments']) && is_array($payload['attachments'])) {
                        $attachmentsChanges = $payload['attachments'];
                    }

                    $fieldLabels = [];

                    foreach ($changes as $fieldName => $changeValue) {
                        $fieldLabels[] = dashboardFieldLabel((string) $fieldName, $currentLanguage);
                    }

                    $fieldLabels = array_values(array_unique(array_filter(
                        $fieldLabels,
                        static function ($value): bool {
                            return trim((string) $value) !== '';
                        }
                    )));

                    $statusNameEs = isset($auditRow['status_name_es']) ? (string) $auditRow['status_name_es'] : '';
                    $statusNameEn = isset($auditRow['status_name_en']) ? (string) $auditRow['status_name_en'] : '';
                    $statusSlug = isset($auditRow['status_slug']) ? (string) $auditRow['status_slug'] : '';

                    $currentStatusLabel = (string) ($meta['status_label'] ?? '');

                    if ($currentStatusLabel === '') {
                        $currentStatusLabel = $currentLanguage === 'en' && $statusNameEn !== ''
                            ? $statusNameEn
                            : $statusNameEs;

                        if ($currentStatusLabel === '' && $statusNameEn !== '') {
                            $currentStatusLabel = $statusNameEn;
                        }

                        if ($currentStatusLabel === '' && $statusSlug !== '') {
                            $currentStatusLabel = $statusSlug;
                        }
                    }

                    $action = (string) ($auditRow['action'] ?? '');

                    $summary = '';

                    if ($action === 'create') {
                        $summary = translate('reports.dashboard.recent_created', [], $currentLanguage);
                    } elseif ($action === 'delete') {
                        $summary = translate('reports.dashboard.recent_deleted', [], $currentLanguage);
                    } elseif (isset($changes['status_id'])) {
                        $summary = translate(
                            'reports.dashboard.recent_status_change',
                            ['status' => $currentStatusLabel],
                            $currentLanguage
                        );
                    } elseif ($fieldLabels !== []) {
                        $summary = translate(
                            'reports.dashboard.recent_generic_update',
                            ['fields' => implode(', ', $fieldLabels)],
                            $currentLanguage
                        );
                    } elseif (
                        (isset($attachmentsChanges['added']) && is_array($attachmentsChanges['added']) && $attachmentsChanges['added'] !== [])
                        || (isset($attachmentsChanges['removed']) && is_array($attachmentsChanges['removed']) && $attachmentsChanges['removed'] !== [])
                    ) {
                        $attachmentsLabel = translate('reports.attachments.title', [], $currentLanguage);
                        $summary = translate(
                            'reports.dashboard.recent_generic_update',
                            ['fields' => $attachmentsLabel],
                            $currentLanguage
                        );
                    } else {
                        $summary = translate('reports.dashboard.recent_generic_activity', [], $currentLanguage);
                    }

                    $userIdValue = isset($auditRow['user_id']) ? (int) $auditRow['user_id'] : null;

                    if ($userIdValue !== null && $userIdValue <= 0) {
                        $userIdValue = null;
                    }

                    $userName = trim((string) ($auditRow['user_name'] ?? ''));
                    $userEmail = trim((string) ($auditRow['user_email'] ?? ''));
                    $userDisplay = $userName;

                    if ($userDisplay === '') {
                        $userDisplay = $userEmail;
                    } elseif ($userEmail !== '') {
                        $userDisplay .= ' · ' . $userEmail;
                    }

                    $timestampRaw = isset($auditRow['created_at']) ? (string) $auditRow['created_at'] : '';
                    $timestampDisplay = formatDateDisplay($timestampRaw, 'd/m/Y H:i');
                    $timestampIso = dashboardFormatIso($timestampRaw);

                    $riskLevel = $meta['risk_level'] ?? null;

                    if ($riskLevel === null) {
                        $recordDias = isset($auditRow['record_dias_transcurridos'])
                            ? (int) $auditRow['record_dias_transcurridos']
                            : 0;
                        $recordHours = $recordDias * 24;

                        if ($severeThresholdHours > 0 && $recordHours >= $severeThresholdHours) {
                            $riskLevel = 'breach';
                        } elseif ($cautionThresholdHours > 0 && $recordHours >= $cautionThresholdHours) {
                            $riskLevel = 'warning';
                        }
                    }

                    $pendingReview = false;
                    $statusForPending = (string) ($meta['status_slug'] ?? $statusSlug);

                    if ($statusForPending !== '') {
                        $pendingReview = in_array($statusForPending, ['pending', 'on_hold'], true);
                    }

                    if ($riskLevel === 'warning' || $riskLevel === 'breach') {
                        $pendingReview = true;
                    }

                    $recentChanges[] = [
                        'id' => isset($auditRow['id']) ? (int) $auditRow['id'] : 0,
                        'record_id' => $recordId,
                        'reference' => $meta['reference'] !== ''
                            ? $meta['reference']
                            : (string) ($auditRow['referencia'] ?? ''),
                        'client' => $meta['client'] !== ''
                            ? $meta['client']
                            : (string) ($auditRow['cliente'] ?? ''),
                        'status_label' => $currentStatusLabel,
                        'status_slug' => $statusForPending,
                        'summary' => $summary,
                        'fields' => $fieldLabels,
                        'action' => $action,
                        'timestamp' => $timestampIso,
                        'timestamp_display' => $timestampDisplay,
                        'user' => [
                            'id' => $userIdValue,
                            'name' => $userName,
                            'email' => $userEmail,
                            'display' => $userDisplay,
                        ],
                        'pending_review' => $pendingReview,
                        'risk_level' => $riskLevel,
                    ];
                }

                $auditResult->free();
            }

            $auditStatement->close();
        }

        unset($recordIdValue);
    }

    $dashboardData['status'] = $statusSummary;
    $dashboardData['clients'] = $clientSummary;
    $dashboardData['periods'] = $periodSummary;
    $dashboardData['sla'] = [
        'warning_threshold_hours' => $cautionThresholdHours,
        'breach_threshold_hours' => $severeThresholdHours,
        'warning_count' => $slaWarningCount,
        'breach_count' => $slaBreachCount,
        'at_risk' => $atRiskRecords,
    ];
    $dashboardData['recent_changes'] = $recentChanges;
}

http_response_code(200);

echo json_encode([
    'success' => true,
    'data' => $records,
    'summary' => [
        'count' => $totalCount,
        'total_dias_transcurridos' => $totalDiasTranscurridos,
        'total_dias_fuera' => $totalDiasFuera,
    ],
    'filters' => [
        'fecha_inicio' => $startDate,
        'fecha_fin' => $endDate,
        'cliente_id' => $userRole === 'cliente' ? $userId : $clientFilterId,
    ],
    'dashboard' => $dashboardData,
]);
