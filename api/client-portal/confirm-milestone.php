<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/database.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

$user = $_SESSION['user'];
$userId = (int) $user['id'];
$userRole = (string) ($user['role'] ?? '');

$rawInput = file_get_contents('php://input');
$payload = $rawInput !== '' ? json_decode($rawInput, true) : null;

if ($payload === null && $_POST !== []) {
    $payload = $_POST;
}

$desembarqueId = 0;
$comment = '';

if (is_array($payload)) {
    if (array_key_exists('desembarque_id', $payload)) {
        $desembarqueId = (int) $payload['desembarque_id'];
    }

    if (array_key_exists('comment', $payload)) {
        $comment = trim((string) $payload['comment']);
    }
}

if ($desembarqueId <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.numeric', [], $currentLanguage) ?: 'Invalid shipment.',
        'errors' => [
            'desembarque_id' => translate('validation.numeric', [], $currentLanguage) ?: 'Invalid shipment.',
        ],
    ]);
    exit;
}

if (mb_strlen($comment) > 255) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.max.string', ['max' => '255'], $currentLanguage) ?: 'Comment too long.',
        'errors' => [
            'comment' => translate('validation.max.string', ['max' => '255'], $currentLanguage) ?: 'Comment too long.',
        ],
    ]);
    exit;
}

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

$query = 'SELECT d.id, d.cliente AS legacy_client, d.client_id, c.email AS client_email, c.name AS client_name, c.user_id AS client_user_id, u.email AS linked_user_email, u.name AS linked_user_name'
    . ' FROM desembarques d'
    . ' LEFT JOIN clients c ON d.client_id = c.id'
    . ' LEFT JOIN users u ON c.user_id = u.id'
    . ' WHERE d.id = ? AND d.deleted_at IS NULL'
    . ' LIMIT 1';

$statement = $connection->prepare($query);

if (! $statement) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.milestones.confirm_error', [], $currentLanguage),
    ]);
    exit;
}

$statement->bind_param('i', $desembarqueId);
$statement->execute();

$result = $statement->get_result();
$record = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

if ($result instanceof mysqli_result) {
    $result->free();
}

$statement->close();

if (! $record) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.milestones.not_found', [], $currentLanguage) ?: 'Shipment not found.',
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
    $linkedUserEmail = mb_strtolower(trim((string) ($record['linked_user_email'] ?? '')));
    $linkedUserName = mb_strtolower(trim((string) ($record['linked_user_name'] ?? '')));

    if ($clientEmail !== '' && $clientEmail === $userEmail) {
        return true;
    }

    if ($clientName !== '' && ($clientName === $userName || $clientName === $userEmail)) {
        return true;
    }

    if ($legacyClient !== '' && ($legacyClient === $userName || $legacyClient === $userEmail)) {
        return true;
    }

    if ($linkedUserEmail !== '' && $linkedUserEmail === $userEmail) {
        return true;
    }

    if ($linkedUserName !== '' && $linkedUserName === $userName) {
        return true;
    }

    return false;
}

if ($userRole === 'cliente' && ! client_has_access($record, $user)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('reports.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

$query = 'INSERT INTO desembarque_milestone_confirmations (desembarque_id, confirmed_by, comment) VALUES (?, ?, ?)' .
    ' ON DUPLICATE KEY UPDATE comment = VALUES(comment), confirmed_at = CURRENT_TIMESTAMP';

$statement = $connection->prepare($query);

if (! $statement) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.milestones.confirm_error', [], $currentLanguage),
    ]);
    exit;
}

$commentParam = $comment;
$statement->bind_param('iis', $desembarqueId, $userId, $commentParam);

try {
    $statement->execute();
} catch (mysqli_sql_exception $exception) {
    $statement->close();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.milestones.confirm_error', [], $currentLanguage),
    ]);
    exit;
}

$statement->close();

$query = 'SELECT confirmed_at, comment FROM desembarque_milestone_confirmations WHERE desembarque_id = ? AND confirmed_by = ? LIMIT 1';
$statement = $connection->prepare($query);

if (! $statement) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.milestones.confirm_error', [], $currentLanguage),
    ]);
    exit;
}

$statement->bind_param('ii', $desembarqueId, $userId);
$statement->execute();

$result = $statement->get_result();
$confirmation = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

if ($result instanceof mysqli_result) {
    $result->free();
}

$statement->close();

$confirmedAt = '';
$storedComment = '';

if ($confirmation) {
    $confirmedAt = (string) ($confirmation['confirmed_at'] ?? '');
    $storedComment = (string) ($confirmation['comment'] ?? '');
}

echo json_encode([
    'success' => true,
    'message' => translate('client_portal.milestones.confirm_success', [], $currentLanguage),
    'data' => [
        'desembarque_id' => $desembarqueId,
        'confirmed_at' => $confirmedAt,
        'comment' => $storedComment,
    ],
]);
