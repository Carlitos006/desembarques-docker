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

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('users.error.permission_denied', [], $currentLanguage),
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
$defaultTimezoneName = (string) date_default_timezone_get();

if ($defaultTimezoneName === '') {
    $defaultTimezoneName = 'UTC-6';
}

$viewerTimezoneName = (string) ($_SESSION['user']['timezone'] ?? $defaultTimezoneName);

try {
    $viewerTimezone = new DateTimeZone($viewerTimezoneName);
} catch (Throwable $exception) {
    try {
        $viewerTimezone = new DateTimeZone($defaultTimezoneName);
    } catch (Throwable $innerException) {
        $viewerTimezone = null;
    }
}

try {
    $utcTimezone = new DateTimeZone('UTC-6');
} catch (Throwable $exception) {
    $utcTimezone = null;
}

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
    $value = trim($value);
    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

$allowedRoles = ['admin', 'usuario', 'cliente'];

$name = sanitizeText((string) ($_POST['name'] ?? ''), 100);
$email = sanitizeText((string) ($_POST['email'] ?? ''), 150);
$password = (string) ($_POST['password'] ?? '');
$passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
$role = sanitizeText((string) ($_POST['role'] ?? ''), 20);
$rawClientId = (string) ($_POST['client_id'] ?? '');

$errors = [];

if ($name === '') {
    $errors['name'] = translate('users.validation.name_required', [], $currentLanguage);
}

if ($email === '') {
    $errors['email'] = translate('users.validation.email_required', [], $currentLanguage);
} elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = translate('users.validation.email_invalid', [], $currentLanguage);
}

if ($password === '') {
    $errors['password'] = translate('users.validation.password_required', [], $currentLanguage);
} elseif (mb_strlen($password) < 8) {
    $errors['password'] = translate('users.validation.password_min', [], $currentLanguage);
}

if ($password !== $passwordConfirmation) {
    $errors['password_confirmation'] = translate('users.validation.password_confirmation', [], $currentLanguage);
}

if (! in_array($role, $allowedRoles, true)) {
    $errors['role'] = translate('users.validation.role_invalid', [], $currentLanguage);
}

$clientId = null;

if ($role === 'cliente') {
    $rawClientId = trim($rawClientId);

    if ($rawClientId !== '') {
        if (! ctype_digit($rawClientId)) {
            $errors['client_id'] = translate('users.validation.client_invalid', [], $currentLanguage);
        } else {
            $clientId = (int) $rawClientId;
        }
    }
} else {
    $rawClientId = '';
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
    $query = 'SELECT id FROM users WHERE email = ? LIMIT 1';
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException(translate('users.error.prepare_lookup', [], $currentLanguage));
    }

    $statement->bind_param('s', $email);
    $statement->execute();

    $result = $statement->get_result();
    $existingUser = $result ? $result->fetch_assoc() : null;
    $statement->close();

    if ($existingUser) {
        respondWithError(409, translate('users.error.duplicate_email', [], $currentLanguage));
    }

    $clientForAssignment = null;

    if ($role === 'cliente' && $clientId !== null) {
        $clientLookupQuery = 'SELECT id, name, email, user_id FROM clients WHERE id = ? LIMIT 1';
        $clientLookupStatement = $connection->prepare($clientLookupQuery);

        if (! $clientLookupStatement) {
            throw new RuntimeException(translate('users.error.prepare_client_lookup', [], $currentLanguage));
        }

        $clientLookupStatement->bind_param('i', $clientId);
        $clientLookupStatement->execute();

        $clientLookupResult = $clientLookupStatement->get_result();
        $clientRow = $clientLookupResult ? $clientLookupResult->fetch_assoc() : null;
        $clientLookupStatement->close();

        if (! $clientRow) {
            respondWithError(422, translate('validation.errors', [], $currentLanguage), [
                'client_id' => translate('users.validation.client_not_found', [], $currentLanguage),
            ]);
        }

        $clientUserId = isset($clientRow['user_id']) ? (int) $clientRow['user_id'] : 0;

        if ($clientUserId > 0) {
            respondWithError(422, translate('validation.errors', [], $currentLanguage), [
                'client_id' => translate('users.validation.client_already_assigned', [], $currentLanguage),
            ]);
        }

        $clientForAssignment = [
            'id' => (int) $clientRow['id'],
            'name' => (string) ($clientRow['name'] ?? ''),
            'email' => (string) ($clientRow['email'] ?? ''),
        ];
    }

    $transactionStarted = false;

    if ($role === 'cliente' && $clientId !== null) {
        if (! $connection->begin_transaction()) {
            throw new RuntimeException(translate('users.error.begin_transaction', [], $currentLanguage));
        }

        $transactionStarted = true;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insertQuery = 'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)';
    $insertStatement = $connection->prepare($insertQuery);

    if (! $insertStatement) {
        throw new RuntimeException(translate('users.error.prepare_insert', [], $currentLanguage));
    }

    $insertStatement->bind_param('ssss', $name, $email, $passwordHash, $role);
    $insertStatement->execute();

    $newUserId = (int) $insertStatement->insert_id;
    $insertStatement->close();

    if ($role === 'cliente' && $clientId !== null) {
        $updateClientQuery = 'UPDATE clients SET user_id = ? WHERE id = ? AND (user_id IS NULL OR user_id = 0)';
        $updateClientStatement = $connection->prepare($updateClientQuery);

        if (! $updateClientStatement) {
            throw new RuntimeException(translate('users.error.prepare_client_update', [], $currentLanguage));
        }

        $updateClientStatement->bind_param('ii', $newUserId, $clientId);
        $updateClientStatement->execute();

        if ($updateClientStatement->affected_rows < 1) {
            $updateClientStatement->close();

            if ($transactionStarted) {
                $connection->rollback();
                $transactionStarted = false;
            }

            respondWithError(422, translate('validation.errors', [], $currentLanguage), [
                'client_id' => translate('users.validation.client_already_assigned', [], $currentLanguage),
            ]);
        }

        $updateClientStatement->close();
    }

    if ($transactionStarted) {
        $connection->commit();
        $transactionStarted = false;
    }

    $selectQuery = 'SELECT id, name, email, role, created_at, last_login_at FROM users WHERE id = ? LIMIT 1';
    $selectStatement = $connection->prepare($selectQuery);

    if (! $selectStatement) {
        throw new RuntimeException(translate('users.error.prepare_retrieve', [], $currentLanguage));
    }

    $selectStatement->bind_param('i', $newUserId);
    $selectStatement->execute();

    $selectResult = $selectStatement->get_result();
    $user = $selectResult ? $selectResult->fetch_assoc() : null;
    $selectStatement->close();

    if (! $user) {
        throw new RuntimeException(translate('users.error.unable_to_fetch_after_create', [], $currentLanguage));
    }

    $createdAtDisplay = '';
    $lastLoginDisplay = '';
    if (! empty($user['created_at'])) {
        try {
            $createdAt = new DateTimeImmutable((string) $user['created_at']);
            $createdAtDisplay = $createdAt->format('d/m/Y H:i');
        } catch (Exception $exception) {
            $createdAtDisplay = (string) $user['created_at'];
        }
    }

    if (! empty($user['last_login_at'])) {
        try {
            $lastLoginAt = $utcTimezone instanceof DateTimeZone
                ? new DateTimeImmutable((string) $user['last_login_at'], $utcTimezone)
                : new DateTimeImmutable((string) $user['last_login_at']);

            if ($viewerTimezone instanceof DateTimeZone) {
                $lastLoginAt = $lastLoginAt->setTimezone($viewerTimezone);
            }

            $lastLoginDisplay = $lastLoginAt->format('d/m/Y H:i');
        } catch (Exception $exception) {
            $lastLoginDisplay = (string) $user['last_login_at'];
        }
    }
} catch (Throwable $exception) {
    if (isset($transactionStarted) && $transactionStarted) {
        $connection->rollback();
    }

    respondWithError(500, translate('users.create.error.generic', ['error' => $exception->getMessage()], $currentLanguage));
}

if (isset($connection) && $connection instanceof mysqli && isset($user) && isset($user['id'])) {
    $afterState = [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'client_id' => isset($clientForAssignment['id'])
            ? (int) $clientForAssignment['id']
            : null,
    ];

    $changes = compute_audit_changes([], $afterState);

    record_audit_log(
        'create',
        'user',
        (string) $user['id'],
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
    'message' => translate('users.create.success', [], $currentLanguage),
    'user' => [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'role_label' => translateRoleLabel((string) $user['role'], $currentLanguage),
        'created_at' => (string) $user['created_at'],
        'created_at_display' => $createdAtDisplay,
        'last_login_at' => (string) ($user['last_login_at'] ?? ''),
        'last_login_at_utc' => (string) ($user['last_login_at'] ?? ''),
        'last_login_at_display' => $lastLoginDisplay,
    ],
    'assigned_client' => $clientForAssignment,
]);
