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

/**
 * @param array<string, string> $errors
 */
function respond_with_error(int $statusCode, string $message, array $errors = []): void
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
 * @param array<string, mixed> $record
 * @param array<string, mixed> $user
 */
function client_has_access(array $record, array $user): bool
{
    $userId = (int) ($user['id'] ?? 0);
    $userName = mb_strtolower(trim((string) ($user['name'] ?? '')));
    $userEmail = mb_strtolower(trim((string) ($user['email'] ?? '')));

    if (isset($record['client_user_id']) && $record['client_user_id'] !== null) {
        if ((int) $record['client_user_id'] === $userId) {
            return true;
        }
    }

    $clientEmail = mb_strtolower(trim((string) ($record['client_email'] ?? '')));
    $clientName = mb_strtolower(trim((string) ($record['client_name'] ?? '')));
    $legacyClient = mb_strtolower(trim((string) ($record['legacy_client'] ?? '')));

    if ($clientEmail !== '' && $clientEmail === $userEmail) {
        return true;
    }

    if ($clientName !== '' && ($clientName === $userName || $clientName === $userEmail)) {
        return true;
    }

    if ($legacyClient !== '' && ($legacyClient === $userName || $legacyClient === $userEmail)) {
        return true;
    }

    return false;
}

/**
 * @param array<string, mixed> $row
 */
function build_observation_meta(array $row, string $language, bool $isAuthor, bool $isLegacy): string
{
    $authorLabel = '';

    if ($isAuthor) {
        $authorLabel = translate('reports.observations_modal.you', [], $language);
    }

    if ($authorLabel === '') {
        $authorName = trim((string) ($row['author_name'] ?? ''));
        $authorEmail = trim((string) ($row['author_email'] ?? ''));
        $role = trim((string) ($row['author_role'] ?? ''));
        $roleLabel = $role !== '' ? translateRoleLabel($role, $language) : '';

        if ($authorName !== '') {
            $authorLabel = $authorName;
        } elseif ($authorEmail !== '') {
            $authorLabel = $authorEmail;
        }

        if ($roleLabel !== '') {
            if ($authorLabel !== '') {
                $authorLabel .= ' (' . $roleLabel . ')';
            } else {
                $authorLabel = $roleLabel;
            }
        }
    }

    if ($authorLabel === '' && $isLegacy) {
        $authorLabel = translate('reports.observations_modal.legacy_author', [], $language);
    }

    $createdAtDisplay = trim((string) ($row['created_at_display'] ?? ''));

    if ($authorLabel !== '' && $createdAtDisplay !== '') {
        return translate('reports.observations_modal.meta', [
            'author' => $authorLabel,
            'date' => $createdAtDisplay,
        ], $language);
    }

    if ($authorLabel !== '') {
        return translate('reports.observations_modal.meta_no_date', [
            'author' => $authorLabel,
        ], $language);
    }

    return $createdAtDisplay;
}

/**
 * @param array<string, mixed> $row
 */
function normalize_observation_payload(array $row, string $language, int $currentUserId): array
{
    $createdBy = isset($row['created_by']) ? (int) $row['created_by'] : 0;
    $isAuthor = $createdBy > 0 && $createdBy === $currentUserId;
    $isLegacy = (bool) ($row['is_legacy'] ?? false);
    $message = trim((string) ($row['message'] ?? ''));

    $createdAt = isset($row['created_at']) ? (string) $row['created_at'] : '';
    $createdAtDisplay = isset($row['created_at_display'])
        ? (string) $row['created_at_display']
        : formatDateDisplay($createdAt !== '' ? $createdAt : null, 'd/m/Y H:i', '');

    $meta = isset($row['meta']) && $row['meta'] !== ''
        ? (string) $row['meta']
        : build_observation_meta([
            'author_name' => $row['author_name'] ?? '',
            'author_email' => $row['author_email'] ?? '',
            'author_role' => $row['author_role'] ?? '',
            'created_at_display' => $createdAtDisplay,
        ], $language, $isAuthor, $isLegacy);

    return [
        'id' => isset($row['id']) ? (int) $row['id'] : null,
        'message' => $message,
        'created_at' => $createdAt,
        'created_at_display' => $createdAtDisplay,
        'is_author' => $isAuthor,
        'is_legacy' => $isLegacy,
        'author_id' => $createdBy > 0 ? $createdBy : null,
        'author_name' => isset($row['author_name']) ? (string) $row['author_name'] : '',
        'author_email' => isset($row['author_email']) ? (string) $row['author_email'] : '',
        'author_role' => isset($row['author_role']) ? (string) $row['author_role'] : '',
        'meta' => $meta,
    ];
}

/**
 * @param ?string $value
 */
function formatDateDisplay(?string $value, string $format = 'd/m/Y H:i', ?string $fallback = null): string
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

$user = $_SESSION['user'];
$userRole = (string) ($user['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario', 'cliente'], true)) {
    respond_with_error(403, translate('reports.permission_denied', [], $currentLanguage));
}

$userId = (int) $user['id'];
$userName = (string) ($user['name'] ?? '');
$userEmail = (string) ($user['email'] ?? '');

$idValue = trim((string) ($_GET['id'] ?? ''));

if ($idValue === '' || ! ctype_digit($idValue)) {
    respond_with_error(422, translate('validation.errors', [], $currentLanguage), [
        'id' => translate('desembarques.validation.id_invalid', [], $currentLanguage),
    ]);
}

$desembarqueId = (int) $idValue;

if ($desembarqueId <= 0) {
    respond_with_error(422, translate('validation.errors', [], $currentLanguage), [
        'id' => translate('desembarques.validation.id_invalid', [], $currentLanguage),
    ]);
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respond_with_error(500, $exception->getMessage());
}

try {
    $lookup = $connection->prepare(
        'SELECT d.id, d.observaciones, d.created_at, d.cliente AS legacy_client, '
        . 'client.user_id AS client_user_id, client.email AS client_email, client.name AS client_name '
        . 'FROM desembarques d '
        . 'LEFT JOIN clients client ON client.id = d.client_id '
        . 'WHERE d.id = ? AND d.deleted_at IS NULL LIMIT 1'
    );

    if (! $lookup) {
        throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
    }

    $lookup->bind_param('i', $desembarqueId);
    $lookup->execute();

    $result = $lookup->get_result();
    $record = $result ? $result->fetch_assoc() : null;

    $lookup->close();

    if (! $record) {
        respond_with_error(404, translate('desembarques.update.not_found', [], $currentLanguage));
    }

    if ($userRole === 'cliente' && ! client_has_access($record, [
        'id' => $userId,
        'name' => $userName,
        'email' => $userEmail,
    ])) {
        respond_with_error(403, translate('reports.permission_denied', [], $currentLanguage));
    }

    $legacyObservaciones = trim((string) ($record['observaciones'] ?? ''));
    $legacyCreatedAt = isset($record['created_at']) ? (string) $record['created_at'] : '';
} catch (Throwable $exception) {
    respond_with_error(500, translate('reports.alert.load_error', [], $currentLanguage));
}

$observations = [];
$latestCreatedAt = '';

try {
    $statement = $connection->prepare(
        'SELECT obs.id, obs.message, obs.created_at, obs.created_by, '
        . 'users.name AS author_name, users.email AS author_email, users.role AS author_role '
        . 'FROM desembarque_observaciones obs '
        . 'LEFT JOIN users ON users.id = obs.created_by '
        . 'WHERE obs.desembarque_id = ? '
        . 'ORDER BY obs.created_at ASC, obs.id ASC'
    );

    if (! $statement) {
        throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
    }

    $statement->bind_param('i', $desembarqueId);
    $statement->execute();

    $result = $statement->get_result();

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['created_at_display'] = formatDateDisplay($row['created_at'] ?? null, 'd/m/Y H:i', '');
            $observations[] = normalize_observation_payload($row, $currentLanguage, $userId);

            if (isset($row['created_at']) && $row['created_at'] !== null && $row['created_at'] !== '') {
                $createdAtValue = (string) $row['created_at'];

                if ($latestCreatedAt === '' || $createdAtValue > $latestCreatedAt) {
                    $latestCreatedAt = $createdAtValue;
                }
            }
        }

        $result->free();
    }

    $statement->close();
} catch (Throwable $exception) {
    respond_with_error(500, translate('reports.alert.load_error', [], $currentLanguage));
}

if ($legacyObservaciones !== '') {
    $legacyCreatedAtDisplay = formatDateDisplay($legacyCreatedAt !== '' ? $legacyCreatedAt : null, 'd/m/Y H:i', '');
    $legacyAuthor = translate('reports.observations_modal.legacy_author', [], $currentLanguage);
    $legacyMeta = $legacyCreatedAtDisplay !== ''
        ? translate('reports.observations_modal.meta', [
            'author' => $legacyAuthor,
            'date' => $legacyCreatedAtDisplay,
        ], $currentLanguage)
        : translate('reports.observations_modal.meta_no_date', [
            'author' => $legacyAuthor,
        ], $currentLanguage);

    $legacyPayload = normalize_observation_payload([
        'id' => null,
        'message' => $legacyObservaciones,
        'created_at' => $legacyCreatedAt,
        'created_at_display' => $legacyCreatedAtDisplay,
        'author_name' => '',
        'author_email' => '',
        'author_role' => '',
        'is_legacy' => true,
        'meta' => $legacyMeta,
    ], $currentLanguage, $userId);

    array_unshift($observations, $legacyPayload);
}

$latestCreatedAtDisplay = $latestCreatedAt !== ''
    ? formatDateDisplay($latestCreatedAt, 'd/m/Y H:i', '')
    : '';

if (! isset($_SESSION['observaciones_last_seen']) || ! is_array($_SESSION['observaciones_last_seen'])) {
    $_SESSION['observaciones_last_seen'] = [];
}

if ($latestCreatedAt !== '') {
    $_SESSION['observaciones_last_seen'][(string) $desembarqueId] = $latestCreatedAt;
}

$totalCount = count($observations);

http_response_code(200);

echo json_encode([
    'success' => true,
    'data' => $observations,
    'count' => $totalCount,
    'last_created_at' => $latestCreatedAt,
    'last_created_at_display' => $latestCreatedAtDisplay,
]);
