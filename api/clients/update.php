<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/csrf.php';

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

$userRole = (string) ($_SESSION['user']['role'] ?? '');
if (! in_array($userRole, ['admin', 'usuario'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('clients.error.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

$csrfTokenValue = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

if (! validate_csrf_token($csrfTokenValue)) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => translate('common.csrf_token_invalid', [], $currentLanguage),
    ]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit.php';

$actorId = (int) $_SESSION['user']['id'];

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

function sanitizeText(string $value, int $maxLength): string
{
    $value = trim(strip_tags($value));

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

$idValue = (string) ($_POST['id'] ?? '');
if ($idValue === '' || ! ctype_digit($idValue)) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), [
        'id' => translate('clients.validation.id_invalid', [], $currentLanguage),
    ]);
}

$clientId = (int) $idValue;
if ($clientId <= 0) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), [
        'id' => translate('clients.validation.id_invalid', [], $currentLanguage),
    ]);
}

$name = sanitizeText((string) ($_POST['name'] ?? ''), 150);
$email = sanitizeText((string) ($_POST['email'] ?? ''), 150);

$errors = [];

if ($name === '') {
    $errors['name'] = translate('clients.validation.name_required', [], $currentLanguage);
}

if ($email === '') {
    $errors['email'] = translate('clients.validation.email_required', [], $currentLanguage);
} elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = translate('clients.validation.email_invalid', [], $currentLanguage);
}

if ($errors !== []) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), $errors);
}

$existingClientData = null;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

try {
    $lookupStatement = $connection->prepare('SELECT id, name, email, user_id FROM clients WHERE id = ? LIMIT 1');

    if (! $lookupStatement) {
        throw new RuntimeException('Unable to prepare client lookup statement.');
    }

    $lookupStatement->bind_param('i', $clientId);
    $lookupStatement->execute();

    $lookupResult = $lookupStatement->get_result();
    $existingClient = $lookupResult ? $lookupResult->fetch_assoc() : null;

    if ($lookupResult instanceof mysqli_result) {
        $lookupResult->free();
    }

    $lookupStatement->close();

    if (! $existingClient) {
        respondWithError(404, translate('clients.update.not_found', [], $currentLanguage));
    }

    $existingClientData = [
        'id' => (int) $existingClient['id'],
        'name' => (string) ($existingClient['name'] ?? ''),
        'email' => (string) ($existingClient['email'] ?? ''),
        'user_id' => isset($existingClient['user_id']) && $existingClient['user_id'] !== null
            ? (int) $existingClient['user_id']
            : null,
    ];

    $duplicateStatement = $connection->prepare('SELECT id FROM clients WHERE email = ? AND id <> ? LIMIT 1');

    if (! $duplicateStatement) {
        throw new RuntimeException('Unable to prepare duplicate client lookup statement.');
    }

    $duplicateStatement->bind_param('si', $email, $clientId);
    $duplicateStatement->execute();

    $duplicateResult = $duplicateStatement->get_result();
    $duplicateClient = $duplicateResult ? $duplicateResult->fetch_assoc() : null;

    if ($duplicateResult instanceof mysqli_result) {
        $duplicateResult->free();
    }

    $duplicateStatement->close();

    if ($duplicateClient) {
        respondWithError(409, translate('clients.error.duplicate_email', [], $currentLanguage));
    }

    $updateStatement = $connection->prepare('UPDATE clients SET name = ?, email = ? WHERE id = ?');

    if (! $updateStatement) {
        throw new RuntimeException('Unable to prepare client update statement.');
    }

    $updateStatement->bind_param('ssi', $name, $email, $clientId);
    $updateStatement->execute();
    $updateStatement->close();

    $selectStatement = $connection->prepare('SELECT id, name, email, user_id, created_at FROM clients WHERE id = ? LIMIT 1');

    if (! $selectStatement) {
        throw new RuntimeException('Unable to prepare client retrieval statement.');
    }

    $selectStatement->bind_param('i', $clientId);
    $selectStatement->execute();

    $selectResult = $selectStatement->get_result();
    $client = $selectResult ? $selectResult->fetch_assoc() : null;

    if ($selectResult instanceof mysqli_result) {
        $selectResult->free();
    }

    $selectStatement->close();

    if (! $client) {
        throw new RuntimeException('Unable to retrieve the updated client.');
    }

    $createdAtDisplay = '';

    if (! empty($client['created_at'])) {
        try {
            $createdAt = new DateTimeImmutable((string) $client['created_at']);
            $createdAtDisplay = $createdAt->format('d/m/Y H:i');
        } catch (Throwable $exception) {
            $createdAtDisplay = (string) $client['created_at'];
        }
    }
} catch (Throwable $exception) {
    respondWithError(500, translate('clients.update.error.generic', ['error' => $exception->getMessage()], $currentLanguage));
}

if (isset($connection) && $connection instanceof mysqli && isset($client) && isset($client['id'])) {
    $afterState = [
        'id' => (int) $client['id'],
        'name' => (string) $client['name'],
        'email' => (string) $client['email'],
        'user_id' => isset($client['user_id']) && $client['user_id'] !== null
            ? (int) $client['user_id']
            : null,
    ];

    $beforeState = is_array($existingClientData) ? $existingClientData : [];
    $changes = compute_audit_changes($beforeState, $afterState);

    if ($changes !== []) {
        record_audit_log(
            'update',
            'client',
            (string) $client['id'],
            [
                'before' => $existingClientData,
                'after' => $afterState,
                'changes' => $changes,
            ],
            $actorId,
            $connection
        );
    }
}

http_response_code(200);

echo json_encode([
    'success' => true,
    'message' => translate('clients.update.success', [], $currentLanguage),
    'client' => [
        'id' => (int) $client['id'],
        'name' => (string) $client['name'],
        'email' => (string) $client['email'],
        'created_at' => (string) ($client['created_at'] ?? ''),
        'created_at_display' => $createdAtDisplay,
    ],
]);
