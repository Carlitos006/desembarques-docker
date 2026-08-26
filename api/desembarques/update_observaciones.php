<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/mail.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

function sanitize_observation(?string $value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
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
 * @param ?string $value
 */
function format_date_display(?string $value, string $format = 'd/m/Y H:i', ?string $fallback = null): string
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
        : format_date_display($createdAt !== '' ? $createdAt : null, 'd/m/Y H:i', '');

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
 * Build a human-readable label for the observation author.
 *
 * @param array<string, mixed> $data
 */
function build_observation_author_label(array $data, string $language): string
{
    $authorName = trim((string) ($data['author_name'] ?? ''));
    $authorEmail = trim((string) ($data['author_email'] ?? ''));
    $authorRole = trim((string) ($data['author_role'] ?? ''));

    $label = $authorName;

    if ($label === '' && $authorEmail !== '') {
        $label = $authorEmail;
    }

    if ($authorRole !== '') {
        $roleLabel = translateRoleLabel($authorRole, $language);

        if ($roleLabel !== '') {
            if ($label !== '') {
                $label .= ' (' . $roleLabel . ')';
            } else {
                $label = $roleLabel;
            }
        }
    }

    if ($label === '' && $authorEmail !== '') {
        $label = $authorEmail;
    }

    return $label;
}

$userRole = (string) ($_SESSION['user']['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario', 'cliente'], true)) {
    respond_with_error(403, translate('desembarques.permission_denied', [], $currentLanguage));
}

$csrfTokenValue = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

if (! validate_csrf_token($csrfTokenValue)) {
    respond_with_error(419, translate('common.csrf_token_invalid', [], $currentLanguage));
}

$idValue = trim((string) ($_POST['id'] ?? ''));

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

$actorId = (int) $_SESSION['user']['id'];
$userName = (string) ($_SESSION['user']['name'] ?? '');
$userEmail = (string) ($_SESSION['user']['email'] ?? '');

$message = sanitize_observation($_POST['observaciones'] ?? '', 2000);

if ($message === '') {
    respond_with_error(422, translate('validation.errors', [], $currentLanguage), [
        'observaciones' => translate('reports.alert.observaciones_update_validation', [], $currentLanguage),
    ]);
}

$observationIdValue = trim((string) ($_POST['observation_id'] ?? ''));
$observationId = 0;

if ($observationIdValue !== '') {
    if (! ctype_digit($observationIdValue)) {
        respond_with_error(422, translate('validation.errors', [], $currentLanguage), [
            'observaciones' => translate('reports.alert.observaciones_update_validation', [], $currentLanguage),
        ]);
    }

    $observationId = (int) $observationIdValue;

    if ($observationId <= 0) {
        respond_with_error(422, translate('validation.errors', [], $currentLanguage), [
            'observaciones' => translate('reports.alert.observaciones_update_validation', [], $currentLanguage),
        ]);
    }
}

$isEdit = $observationId > 0;

$landingReference = '';
$landingClientEmail = '';
$landingClientName = '';
$landingLegacyClient = '';
$landingCreatorEmail = '';
$landingCreatorName = '';
$landingCreatorRole = '';
$landingCreatorId = 0;
$existingObservation = null;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respond_with_error(500, $exception->getMessage());
}

try {
    $lookup = $connection->prepare(
        'SELECT d.id, d.referencia, d.created_by, d.observaciones, d.created_at, d.cliente AS legacy_client, '
        . 'client.user_id AS client_user_id, client.email AS client_email, client.name AS client_name, '
        . 'creator.name AS creator_name, creator.email AS creator_email, creator.role AS creator_role '
        . 'FROM desembarques d '
        . 'LEFT JOIN clients client ON client.id = d.client_id '
        . 'LEFT JOIN users creator ON creator.id = d.created_by '
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
        'id' => $actorId,
        'name' => $userName,
        'email' => $userEmail,
    ])) {
        respond_with_error(403, translate('desembarques.permission_denied', [], $currentLanguage));
    }

    $legacyObservaciones = trim((string) ($record['observaciones'] ?? ''));
    $legacyCreatedAt = isset($record['created_at']) ? (string) $record['created_at'] : '';
    $landingReference = isset($record['referencia']) ? trim((string) $record['referencia']) : '';
    $landingClientEmail = isset($record['client_email']) ? trim((string) $record['client_email']) : '';
    $landingClientName = isset($record['client_name']) ? trim((string) $record['client_name']) : '';
    $landingLegacyClient = isset($record['legacy_client']) ? trim((string) $record['legacy_client']) : '';
    $landingCreatorEmail = isset($record['creator_email']) ? trim((string) $record['creator_email']) : '';
    $landingCreatorName = isset($record['creator_name']) ? trim((string) $record['creator_name']) : '';
    $landingCreatorRole = isset($record['creator_role']) ? trim((string) $record['creator_role']) : '';
    $landingCreatorId = isset($record['created_by']) ? (int) $record['created_by'] : 0;
} catch (Throwable $exception) {
    respond_with_error(500, translate('desembarques.update.error_lookup', ['error' => $exception->getMessage()], $currentLanguage));
}

if ($isEdit) {
    try {
        $observationLookup = $connection->prepare(
            'SELECT id, desembarque_id, created_by FROM desembarque_observaciones WHERE id = ? LIMIT 1'
        );

        if (! $observationLookup) {
            throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
        }

        $observationLookup->bind_param('i', $observationId);
        $observationLookup->execute();

        $observationResult = $observationLookup->get_result();
        $existingObservation = $observationResult ? $observationResult->fetch_assoc() : null;

        $observationLookup->close();
    } catch (Throwable $exception) {
        respond_with_error(500, translate('reports.alert.observaciones_update_error', [], $currentLanguage));
    }

    if (! $existingObservation) {
        respond_with_error(404, translate('reports.alert.observaciones_update_error', [], $currentLanguage));
    }

    $existingRecordId = isset($existingObservation['desembarque_id'])
        ? (int) $existingObservation['desembarque_id']
        : 0;

    if ($existingRecordId !== $desembarqueId) {
        respond_with_error(403, translate('desembarques.permission_denied', [], $currentLanguage));
    }

    $existingCreatorId = isset($existingObservation['created_by'])
        ? (int) $existingObservation['created_by']
        : 0;

    if ($existingCreatorId <= 0 || $existingCreatorId !== $actorId) {
        respond_with_error(403, translate('desembarques.permission_denied', [], $currentLanguage));
    }

    try {
        $update = $connection->prepare(
            'UPDATE desembarque_observaciones SET message = ? WHERE id = ? AND desembarque_id = ?'
        );

        if (! $update) {
            throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
        }

        $update->bind_param('sii', $message, $observationId, $desembarqueId);
        $update->execute();
        $update->close();
    } catch (Throwable $exception) {
        respond_with_error(500, translate('reports.alert.observaciones_update_error', [], $currentLanguage), [
            'observaciones' => $exception->getMessage(),
        ]);
    }
} else {
    try {
        $insert = $connection->prepare(
            'INSERT INTO desembarque_observaciones (desembarque_id, created_by, message) VALUES (?, ?, ?)'
        );

        if (! $insert) {
            throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
        }

        $createdByValue = $actorId > 0 ? $actorId : null;
        $insert->bind_param('iis', $desembarqueId, $createdByValue, $message);
        $insert->execute();
        $observationId = (int) $insert->insert_id;
        $insert->close();
    } catch (Throwable $exception) {
        respond_with_error(500, translate('reports.alert.observaciones_update_error', [], $currentLanguage), [
            'observaciones' => $exception->getMessage(),
        ]);
    }
}

$observationData = null;

try {
    $select = $connection->prepare(
        'SELECT obs.id, obs.message, obs.created_at, obs.created_by, '
        . 'users.name AS author_name, users.email AS author_email, users.role AS author_role '
        . 'FROM desembarque_observaciones obs '
        . 'LEFT JOIN users ON users.id = obs.created_by '
        . 'WHERE obs.id = ? LIMIT 1'
    );

    if (! $select) {
        throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
    }

    $select->bind_param('i', $observationId);
    $select->execute();

    $result = $select->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    if ($row) {
        $row['created_at_display'] = format_date_display($row['created_at'] ?? null, 'd/m/Y H:i', '');
        $observationData = normalize_observation_payload($row, $currentLanguage, $actorId);
    }

    $select->close();
} catch (Throwable $exception) {
    respond_with_error(500, translate('reports.alert.observaciones_update_error', [], $currentLanguage));
}

$totalCount = 0;

try {
    $countStatement = $connection->prepare('SELECT COUNT(*) AS total FROM desembarque_observaciones WHERE desembarque_id = ?');

    if (! $countStatement) {
        throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
    }

    $countStatement->bind_param('i', $desembarqueId);
    $countStatement->execute();

    $countResult = $countStatement->get_result();
    $countRow = $countResult ? $countResult->fetch_assoc() : null;

    if ($countRow) {
        $totalCount = isset($countRow['total']) ? (int) $countRow['total'] : 0;
    }

    $countStatement->close();
} catch (Throwable $exception) {
    respond_with_error(500, translate('reports.alert.observaciones_update_error', [], $currentLanguage));
}

if ($legacyObservaciones !== '') {
    $totalCount += 1;
}

$lastCreatedAt = '';
$lastCreatedAtDisplay = '';

if ($observationData !== null) {
    $lastCreatedAt = isset($observationData['created_at']) ? (string) $observationData['created_at'] : '';
    $lastCreatedAtDisplay = isset($observationData['created_at_display'])
        ? (string) $observationData['created_at_display']
        : '';
}

if ($lastCreatedAt === '') {
    $lastCreatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    if ($lastCreatedAtDisplay === '') {
        $lastCreatedAtDisplay = format_date_display($lastCreatedAt, 'd/m/Y H:i', '');
    }
}

if (! isset($_SESSION['observaciones_last_seen']) || ! is_array($_SESSION['observaciones_last_seen'])) {
    $_SESSION['observaciones_last_seen'] = [];
}

$_SESSION['observaciones_last_seen'][(string) $desembarqueId] = $lastCreatedAt;

$logAction = $isEdit ? 'update_observacion' : 'create_observacion';

record_audit_log(
    $logAction,
    'desembarque',
    (string) $desembarqueId,
    [
        'observation' => [
            'id' => $observationId,
            'message' => $message,
        ],
    ],
    $actorId,
    $connection
);

if (! $isEdit) {
    $authorEmailData = [
        'author_name' => $observationData['author_name'] ?? $userName,
        'author_email' => $observationData['author_email'] ?? $userEmail,
        'author_role' => $observationData['author_role'] ?? $userRole,
    ];

    $authorLabel = build_observation_author_label($authorEmailData, $currentLanguage);

    if ($authorLabel === '') {
        $authorLabel = translate('desembarques.email.observation.unknown_author', [], $currentLanguage);
    }

    if ($userRole === 'cliente') {
        $recipientEmail = $landingCreatorEmail;
        $recipientName = $landingCreatorName;

        if ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $normalizedRecipient = mb_strtolower($recipientEmail);
            $normalizedActor = mb_strtolower($userEmail);

            if ($normalizedRecipient === '' || $normalizedRecipient !== $normalizedActor) {
                sendDesembarqueObservationEmail(
                    $recipientEmail,
                    $recipientName,
                    $landingReference,
                    $authorLabel,
                    $message,
                    $currentLanguage
                );
            }
        }
    } else {
        $recipientEmail = $landingClientEmail;
        $recipientName = $landingClientName;

        if ($recipientEmail === '' && $landingLegacyClient !== '' && filter_var($landingLegacyClient, FILTER_VALIDATE_EMAIL)) {
            $recipientEmail = $landingLegacyClient;
        }

        if ($recipientName === '' && $landingLegacyClient !== '') {
            $recipientName = $landingLegacyClient;
        }

        if ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $normalizedRecipient = mb_strtolower($recipientEmail);
            $normalizedActor = mb_strtolower($userEmail);

            if ($normalizedRecipient === '' || $normalizedRecipient !== $normalizedActor) {
                sendDesembarqueObservationEmail(
                    $recipientEmail,
                    $recipientName,
                    $landingReference,
                    $authorLabel,
                    $message,
                    $currentLanguage
                );
            }
        }
    }
}

http_response_code(200);

echo json_encode([
    'success' => true,
    'message' => translate('reports.alert.observaciones_update_success', [], $currentLanguage),
    'observation' => $observationData,
    'mode' => $isEdit ? 'updated' : 'created',
    'count' => $totalCount,
    'last_created_at' => $lastCreatedAt,
    'last_created_at_display' => $lastCreatedAtDisplay,
]);
