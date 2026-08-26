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

$user = $_SESSION['user'];
$userId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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

/**
 * @param array<string, mixed> $userData
 */
function resolveClientPortalClientUserId(mysqli $connection, array $userData, string $language, ?string $rawClientUserId, string $errorKey): int
{
    $userId = isset($userData['id']) ? (int) $userData['id'] : 0;
    $userRole = (string) ($userData['role'] ?? '');
    $value = $rawClientUserId !== null ? trim($rawClientUserId) : '';

    if ($value === '') {
        return $userId;
    }

    if ($userRole !== 'admin') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => translate($errorKey, [], $language),
        ]);
        exit;
    }

    $validationMessage = translate('validation.errors', [], $language) ?: 'Validation errors occurred.';
    $numericErrorMessage = translate('validation.numeric', [], $language) ?: 'The value must be numeric.';

    if (! ctype_digit($value)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $validationMessage,
            'errors' => [
                'client_user_id' => $numericErrorMessage,
            ],
        ]);
        exit;
    }

    $requestedId = (int) $value;

    if ($requestedId <= 0) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $validationMessage,
            'errors' => [
                'client_user_id' => $numericErrorMessage,
            ],
        ]);
        exit;
    }

    if ($requestedId === $userId) {
        return $requestedId;
    }

    $statement = $connection->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');

    if (! $statement) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => translate($errorKey, [], $language),
        ]);
        exit;
    }

    $statement->bind_param('i', $requestedId);
    $statement->execute();

    $result = $statement->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

    if ($result instanceof mysqli_result) {
        $result->free();
    }

    $statement->close();

    if (! $row) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => translate($errorKey, [], $language),
        ]);
        exit;
    }

    $role = (string) ($row['role'] ?? '');

    if ($role !== 'cliente') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => translate($errorKey, [], $language),
        ]);
        exit;
    }

    return $requestedId;
}

if ($method === 'GET') {
    $clientUserId = resolveClientPortalClientUserId(
        $connection,
        $user,
        $currentLanguage,
        isset($_GET['client_user_id']) ? (string) $_GET['client_user_id'] : null,
        'client_portal.messages.error.load'
    );

    $query = 'SELECT m.id, m.message, m.created_at, m.sender_id, u.name AS sender_name, u.email AS sender_email, u.role AS sender_role'
        . ' FROM client_portal_messages m'
        . ' INNER JOIN users u ON m.sender_id = u.id'
        . ' WHERE m.client_user_id = ?'
        . ' ORDER BY m.created_at ASC, m.id ASC';

    $statement = $connection->prepare($query);

    if (! $statement) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => translate('client_portal.messages.error.load', [], $currentLanguage),
        ]);
        exit;
    }

    $statement->bind_param('i', $clientUserId);
    $statement->execute();

    $result = $statement->get_result();
    $messages = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $messages[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'message' => (string) ($row['message'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'sender_id' => isset($row['sender_id']) ? (int) $row['sender_id'] : null,
                'sender_name' => (string) ($row['sender_name'] ?? ''),
                'sender_email' => (string) ($row['sender_email'] ?? ''),
                'sender_role' => (string) ($row['sender_role'] ?? ''),
                'is_author' => isset($row['sender_id']) && (int) $row['sender_id'] === $userId,
            ];
        }

        $result->free();
    }

    $statement->close();

    echo json_encode([
        'success' => true,
        'data' => $messages,
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = $rawInput !== '' ? json_decode($rawInput, true) : null;

if ($payload === null && $_POST !== []) {
    $payload = $_POST;
}

$message = '';
$postClientUserIdRaw = null;

if (is_array($payload) && array_key_exists('client_user_id', $payload)) {
    $postClientUserIdRaw = (string) $payload['client_user_id'];
} elseif (isset($_GET['client_user_id'])) {
    $postClientUserIdRaw = (string) $_GET['client_user_id'];
}

if (is_array($payload) && array_key_exists('message', $payload)) {
    $message = trim((string) $payload['message']);
}

if ($message === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.required', [], $currentLanguage) ?: 'Message is required.',
        'errors' => [
            'message' => translate('validation.required', [], $currentLanguage) ?: 'Message is required.',
        ],
    ]);
    exit;
}

$clientUserId = resolveClientPortalClientUserId(
    $connection,
    $user,
    $currentLanguage,
    $postClientUserIdRaw,
    'client_portal.messages.error.send'
);

$query = 'INSERT INTO client_portal_messages (client_user_id, sender_id, message) VALUES (?, ?, ?)';
$statement = $connection->prepare($query);

if (! $statement) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.messages.error.send', [], $currentLanguage),
    ]);
    exit;
}

    $statement->bind_param('iis', $clientUserId, $userId, $message);

try {
    $statement->execute();
} catch (mysqli_sql_exception $exception) {
    $statement->close();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.messages.error.send', [], $currentLanguage),
    ]);
    exit;
}

$newId = (int) $statement->insert_id;
$statement->close();

$createdAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

$createdAtQuery = 'SELECT created_at FROM client_portal_messages WHERE id = ? LIMIT 1';
$createdAtStatement = $connection->prepare($createdAtQuery);

if ($createdAtStatement) {
    $createdAtStatement->bind_param('i', $newId);
    $createdAtStatement->execute();
    $createdAtResult = $createdAtStatement->get_result();

    if ($createdAtResult instanceof mysqli_result) {
        $createdAtRow = $createdAtResult->fetch_assoc();

        if ($createdAtRow && isset($createdAtRow['created_at'])) {
            $createdAt = (string) $createdAtRow['created_at'];
        }

        $createdAtResult->free();
    }

    $createdAtStatement->close();
}

echo json_encode([
    'success' => true,
    'data' => [
        'id' => $newId,
        'message' => $message,
        'created_at' => $createdAt,
        'sender_id' => $userId,
        'sender_name' => (string) ($user['name'] ?? ''),
        'sender_email' => (string) ($user['email'] ?? ''),
        'sender_role' => (string) ($user['role'] ?? ''),
        'is_author' => true,
    ],
]);
