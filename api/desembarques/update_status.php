<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit.php';

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

$user = $_SESSION['user'];
$userId = isset($user['id']) ? (int) $user['id'] : 0;
$userRole = (string) ($user['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('reports.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = [];

if (is_string($rawInput) && $rawInput !== '') {
    $decoded = json_decode($rawInput, true);

    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if ($payload === [] && $_POST !== []) {
    $payload = $_POST;
}

if (! is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
    ]);
    exit;
}

$csrfTokenValue = isset($payload['csrf_token']) ? (string) $payload['csrf_token'] : null;

if (! validate_csrf_token($csrfTokenValue)) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => translate('common.csrf_token_invalid', [], $currentLanguage),
    ]);
    exit;
}

$recordIdRaw = isset($payload['id']) ? (string) $payload['id'] : '';
$statusIdRaw = isset($payload['status_id']) ? (string) $payload['status_id'] : '';
$errors = [];

if ($recordIdRaw === '' || ! ctype_digit($recordIdRaw)) {
    $errors['id'] = translate('desembarques.validation.id_invalid', [], $currentLanguage);
}

if ($statusIdRaw === '' || ! ctype_digit($statusIdRaw)) {
    $errors['status_id'] = translate('desembarques.validation.status_invalid', [], $currentLanguage);
}

if ($errors !== []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
        'errors' => $errors,
    ]);
    exit;
}

$recordId = (int) $recordIdRaw;
$statusId = (int) $statusIdRaw;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
    exit;
}

try {
    $lookupStatement = $connection->prepare('SELECT id, referencia, status_id FROM desembarques WHERE id = ? AND deleted_at IS NULL LIMIT 1');

    if (! $lookupStatement) {
        throw new RuntimeException('Unable to prepare desembarque lookup statement.');
    }

    $lookupStatement->bind_param('i', $recordId);
    $lookupStatement->execute();

    $result = $lookupStatement->get_result();
    $record = $result ? $result->fetch_assoc() : null;

    $lookupStatement->close();

    if (! $record) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => translate('desembarques.validation.record_not_found', [], $currentLanguage),
        ]);
        exit;
    }

    $previousStatusId = isset($record['status_id']) ? (int) $record['status_id'] : null;

    if ($previousStatusId === $statusId) {
        echo json_encode([
            'success' => true,
            'message' => translate('control_tower.update.no_change', [], $currentLanguage),
            'record' => [
                'id' => $recordId,
                'status_id' => $statusId,
            ],
        ]);
        exit;
    }

    $statusLookup = $connection->prepare('SELECT id, slug, name_es, name_en FROM desembarque_statuses WHERE id = ? AND is_active = 1 LIMIT 1');

    if (! $statusLookup) {
        throw new RuntimeException('Unable to prepare status lookup statement.');
    }

    $statusLookup->bind_param('i', $statusId);
    $statusLookup->execute();

    $statusResult = $statusLookup->get_result();
    $statusRecord = $statusResult ? $statusResult->fetch_assoc() : null;

    $statusLookup->close();

    if (! $statusRecord) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => translate('validation.errors', [], $currentLanguage),
            'errors' => [
                'status_id' => translate('desembarques.validation.status_not_found', [], $currentLanguage),
            ],
        ]);
        exit;
    }

    $updateStatement = $connection->prepare('UPDATE desembarques SET status_id = ? WHERE id = ? AND deleted_at IS NULL');

    if (! $updateStatement) {
        throw new RuntimeException('Unable to prepare status update statement.');
    }

    $updateStatement->bind_param('ii', $statusId, $recordId);
    $updateStatement->execute();
    $updateStatement->close();

    $statusSlug = isset($statusRecord['slug']) ? (string) $statusRecord['slug'] : '';
    $statusNameEs = isset($statusRecord['name_es']) ? (string) $statusRecord['name_es'] : '';
    $statusNameEn = isset($statusRecord['name_en']) ? (string) $statusRecord['name_en'] : '';

    $statusLabel = $currentLanguage === 'en' && $statusNameEn !== '' ? $statusNameEn : $statusNameEs;

    if ($statusLabel === '' && $statusNameEn !== '') {
        $statusLabel = $statusNameEn;
    }

    if ($statusLabel === '' && $statusSlug !== '') {
        $statusLabel = $statusSlug;
    }

    record_audit_log(
        'update',
        'desembarques.status',
        (string) $recordId,
        [
            'referencia' => isset($record['referencia']) ? (string) $record['referencia'] : '',
            'previous_status_id' => $previousStatusId,
            'new_status_id' => $statusId,
        ],
        $userId,
        $connection
    );

    echo json_encode([
        'success' => true,
        'message' => translate('control_tower.update.success', ['status' => $statusLabel], $currentLanguage),
        'record' => [
            'id' => $recordId,
            'status_id' => $statusId,
            'status_label' => $statusLabel,
            'status_slug' => $statusSlug,
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('control_tower.update.error', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
}
