<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/database.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

if (! isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
    exit;
}

$userRole = (string) ($_SESSION['user']['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('reports.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

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

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

$statuses = [];

try {
    $statusesQuery = 'SELECT id, slug, name_es, name_en, is_default FROM desembarque_statuses WHERE is_active = 1 ORDER BY is_default DESC, name_es ASC, id ASC';
    $statusesResult = $connection->query($statusesQuery);

    if ($statusesResult instanceof mysqli_result) {
        while ($row = $statusesResult->fetch_assoc()) {
            $statusId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($statusId <= 0) {
                continue;
            }

            $statuses[$statusId] = [
                'id' => $statusId,
                'slug' => (string) ($row['slug'] ?? ''),
                'name_es' => (string) ($row['name_es'] ?? ''),
                'name_en' => (string) ($row['name_en'] ?? ''),
                'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            ];
        }

        $statusesResult->free();
    }
} catch (Throwable $exception) {
    respondWithError(
        500,
        translate('control_tower.alert.load_error', ['error' => $exception->getMessage()], $currentLanguage)
    );
}

$recordsByStatus = [];
$totalRecords = 0;

if ($statuses !== []) {
    try {
        $recordsQuery = 'SELECT d.id, d.referencia, d.fecha_desembarque, d.fecha_embarque, d.descripcion, d.destino, d.folio_aviso, d.barco, d.cliente, d.status_id, d.created_at, s.slug AS status_slug, s.name_es AS status_name_es, s.name_en AS status_name_en FROM desembarques d INNER JOIN desembarque_statuses s ON s.id = d.status_id WHERE d.deleted_at IS NULL AND s.is_active = 1 ORDER BY d.fecha_desembarque DESC, d.id DESC';
        $recordsResult = $connection->query($recordsQuery);

        if ($recordsResult instanceof mysqli_result) {
            $today = new DateTimeImmutable('today');

            while ($row = $recordsResult->fetch_assoc()) {
                $statusId = isset($row['status_id']) ? (int) $row['status_id'] : 0;
                $recordId = isset($row['id']) ? (int) $row['id'] : 0;

                if ($statusId <= 0 || $recordId <= 0) {
                    continue;
                }

                $landingDateRaw = isset($row['fecha_desembarque']) ? trim((string) $row['fecha_desembarque']) : '';
                $departureDateRaw = isset($row['fecha_embarque']) ? trim((string) $row['fecha_embarque']) : '';
                $diasTranscurridos = 0;
                $diasFuera = 0;
                $landingDate = null;

                if ($landingDateRaw !== '') {
                    try {
                        $landingDate = new DateTimeImmutable($landingDateRaw);
                        $diasTranscurridos = max(0, (int) $landingDate->diff($today)->format('%r%a'));
                    } catch (Throwable $exception) {
                        $landingDate = null;
                    }
                }

                if ($landingDate instanceof DateTimeImmutable && $departureDateRaw !== '') {
                    try {
                        $departureDate = new DateTimeImmutable($departureDateRaw);
                        $diasFuera = max(0, (int) $landingDate->diff($departureDate)->format('%r%a'));
                    } catch (Throwable $exception) {
                        $diasFuera = 0;
                    }
                }

                $statusNameEs = (string) ($row['status_name_es'] ?? '');
                $statusNameEn = (string) ($row['status_name_en'] ?? '');
                $statusSlug = (string) ($row['status_slug'] ?? '');
                $statusLabel = $currentLanguage === 'en' && $statusNameEn !== '' ? $statusNameEn : $statusNameEs;

                if ($statusLabel === '' && $statusNameEn !== '') {
                    $statusLabel = $statusNameEn;
                }

                if ($statusLabel === '' && $statusSlug !== '') {
                    $statusLabel = $statusSlug;
                }

                $recordsByStatus[$statusId][] = [
                    'id' => $recordId,
                    'referencia' => (string) ($row['referencia'] ?? ''),
                    'descripcion' => (string) ($row['descripcion'] ?? ''),
                    'destino' => (string) ($row['destino'] ?? ''),
                    'folio_aviso' => (string) ($row['folio_aviso'] ?? ''),
                    'barco' => (string) ($row['barco'] ?? ''),
                    'cliente' => (string) ($row['cliente'] ?? ''),
                    'status_id' => $statusId,
                    'status_label' => $statusLabel,
                    'fecha_desembarque' => $landingDateRaw,
                    'fecha_desembarque_display' => formatDateDisplay($landingDateRaw),
                    'fecha_embarque' => $departureDateRaw,
                    'fecha_embarque_display' => formatDateDisplay($departureDateRaw),
                    'dias_transcurridos' => $diasTranscurridos,
                    'dias_fuera' => $diasFuera,
                    'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
                    'created_at_display' => formatDateDisplay($row['created_at'] ?? null, 'd/m/Y H:i'),
                ];

                $totalRecords++;
            }

            $recordsResult->free();
        }
    } catch (Throwable $exception) {
        respondWithError(
            500,
            translate('control_tower.alert.load_error', ['error' => $exception->getMessage()], $currentLanguage)
        );
    }
}

$statusesPayload = [];

foreach ($statuses as $statusId => $status) {
    $statusNameEs = $status['name_es'] ?? '';
    $statusNameEn = $status['name_en'] ?? '';
    $statusLabel = $currentLanguage === 'en' && $statusNameEn !== '' ? $statusNameEn : $statusNameEs;

    if ($statusLabel === '' && $statusNameEn !== '') {
        $statusLabel = $statusNameEn;
    }

    if ($statusLabel === '' && isset($status['slug']) && $status['slug'] !== '') {
        $statusLabel = (string) $status['slug'];
    }

    $statusesPayload[] = [
        'id' => $statusId,
        'slug' => (string) ($status['slug'] ?? ''),
        'label' => $statusLabel,
        'is_default' => (bool) ($status['is_default'] ?? false),
        'records' => $recordsByStatus[$statusId] ?? [],
    ];
}

usort($statusesPayload, static function (array $first, array $second): int {
    if (($first['is_default'] ?? false) && ! ($second['is_default'] ?? false)) {
        return -1;
    }

    if (! ($first['is_default'] ?? false) && ($second['is_default'] ?? false)) {
        return 1;
    }

    return strcasecmp((string) ($first['label'] ?? ''), (string) ($second['label'] ?? ''));
});

$generatedAt = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

http_response_code(200);

echo json_encode([
    'success' => true,
    'statuses' => $statusesPayload,
    'summary' => [
        'total' => $totalRecords,
        'generated_at' => $generatedAt,
    ],
]);
