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

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

try {
    $lookupStatement = $connection->prepare('SELECT id FROM clients WHERE email = ? LIMIT 1');

    if (! $lookupStatement) {
        throw new RuntimeException('Unable to prepare client lookup statement.');
    }

    $lookupStatement->bind_param('s', $email);
    $lookupStatement->execute();

    $lookupResult = $lookupStatement->get_result();
    $existingClient = $lookupResult ? $lookupResult->fetch_assoc() : null;

    if ($lookupResult instanceof mysqli_result) {
        $lookupResult->free();
    }

    $lookupStatement->close();

    if ($existingClient) {
        respondWithError(409, translate('clients.error.duplicate_email', [], $currentLanguage));
    }

    $linkedUserId = null;
    try {
        $userLookup = $connection->prepare('SELECT id FROM users WHERE email = ? AND role = "cliente" LIMIT 1');
    } catch (Throwable $exception) {
        $userLookup = null;
    }

    if ($userLookup) {
        try {
            $userLookup->bind_param('s', $email);
            $userLookup->execute();

            $userResult = $userLookup->get_result();
            $userRow = $userResult ? $userResult->fetch_assoc() : null;

            if ($userResult instanceof mysqli_result) {
                $userResult->free();
            }

            if ($userRow && isset($userRow['id']) && ctype_digit((string) $userRow['id'])) {
                $linkedUserId = (int) $userRow['id'];
            }
        } catch (Throwable $exception) {
            $linkedUserId = null;
        }

        $userLookup->close();
    }

    $insertStatement = $connection->prepare('INSERT INTO clients (name, email, user_id) VALUES (?, ?, ?)');

    if (! $insertStatement) {
        throw new RuntimeException('Unable to prepare client insert statement.');
    }

    $linkedUserIdValue = $linkedUserId;
    $insertStatement->bind_param('ssi', $name, $email, $linkedUserIdValue);
    $insertStatement->execute();

    $newClientId = (int) $insertStatement->insert_id;
    $insertStatement->close();

    $selectStatement = $connection->prepare('SELECT id, name, email, user_id, created_at FROM clients WHERE id = ? LIMIT 1');

    if (! $selectStatement) {
        throw new RuntimeException('Unable to prepare client retrieval statement.');
    }

    $selectStatement->bind_param('i', $newClientId);
    $selectStatement->execute();

    $selectResult = $selectStatement->get_result();
    $client = $selectResult ? $selectResult->fetch_assoc() : null;

    if ($selectResult instanceof mysqli_result) {
        $selectResult->free();
    }

    $selectStatement->close();

    if (! $client) {
        throw new RuntimeException('Unable to retrieve the newly created client.');
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
    respondWithError(500, translate('clients.create.error.generic', ['error' => $exception->getMessage()], $currentLanguage));
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

    $changes = compute_audit_changes([], $afterState);

    record_audit_log(
        'create',
        'client',
        (string) $client['id'],
        [
            'before' => null,
            'after' => $afterState,
            'changes' => $changes,
        ],
        $actorId,
        $connection
    );
}

http_response_code(201);

echo json_encode([
    'success' => true,
    'message' => translate('clients.create.success', [], $currentLanguage),
    'client' => [
        'id' => (int) $client['id'],
        'name' => (string) $client['name'],
        'email' => (string) $client['email'],
        'created_at' => (string) ($client['created_at'] ?? ''),
        'created_at_display' => $createdAtDisplay,
    ],
]);
