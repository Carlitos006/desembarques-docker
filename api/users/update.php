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

$userIdValue = sanitizeText((string) ($_POST['user_id'] ?? ''), 10);
$userId = ctype_digit($userIdValue) ? (int) $userIdValue : 0;
$name = sanitizeText((string) ($_POST['name'] ?? ''), 100);
$email = sanitizeText((string) ($_POST['email'] ?? ''), 150);
$password = (string) ($_POST['password'] ?? '');
$passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
$role = sanitizeText((string) ($_POST['role'] ?? ''), 20);

$passwordUpdated = $password !== '';

$errors = [];

if ($userId <= 0) {
    $errors['id'] = translate('users.validation.id_invalid', [], $currentLanguage);
}

if ($name === '') {
    $errors['name'] = translate('users.validation.name_required', [], $currentLanguage);
}

if ($email === '') {
    $errors['email'] = translate('users.validation.email_required', [], $currentLanguage);
} elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = translate('users.validation.email_invalid', [], $currentLanguage);
}

if ($password !== '') {
    if (mb_strlen($password) < 8) {
        $errors['password'] = translate('users.validation.password_min', [], $currentLanguage);
    }

    if ($password !== $passwordConfirmation) {
        $errors['password_confirmation'] = translate('users.validation.password_confirmation', [], $currentLanguage);
    }
} elseif ($passwordConfirmation !== '') {
    $errors['password_confirmation'] = translate('users.validation.password_confirmation', [], $currentLanguage);
}

if (! in_array($role, $allowedRoles, true)) {
    $errors['role'] = translate('users.validation.role_invalid', [], $currentLanguage);
}

if ($errors !== []) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), $errors);
}

$existingUserData = null;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

try {
    $lookupQuery = 'SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1';
    $lookupStatement = $connection->prepare($lookupQuery);

    if (! $lookupStatement) {
        throw new RuntimeException(translate('users.error.prepare_lookup', [], $currentLanguage));
    }

    $lookupStatement->bind_param('i', $userId);
    $lookupStatement->execute();

    $lookupResult = $lookupStatement->get_result();
    $existingUser = $lookupResult ? $lookupResult->fetch_assoc() : null;
    $lookupStatement->close();

    if (! $existingUser) {
        respondWithError(404, translate('users.update.not_found', [], $currentLanguage));
    }

    $existingUserData = [
        'id' => (int) $existingUser['id'],
        'name' => (string) ($existingUser['name'] ?? ''),
        'email' => (string) ($existingUser['email'] ?? ''),
        'role' => (string) ($existingUser['role'] ?? ''),
        'password_changed' => false,
    ];

    $duplicateQuery = 'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1';
    $duplicateStatement = $connection->prepare($duplicateQuery);

    if (! $duplicateStatement) {
        throw new RuntimeException(translate('users.error.prepare_lookup', [], $currentLanguage));
    }

    $duplicateStatement->bind_param('si', $email, $userId);
    $duplicateStatement->execute();

    $duplicateResult = $duplicateStatement->get_result();
    $duplicateUser = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
    $duplicateStatement->close();

    if ($duplicateUser) {
        respondWithError(409, translate('users.error.duplicate_email', [], $currentLanguage));
    }

    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $updateQuery = 'UPDATE users SET name = ?, email = ?, role = ?, password_hash = ? WHERE id = ?';
        $updateStatement = $connection->prepare($updateQuery);

        if (! $updateStatement) {
            throw new RuntimeException(translate('users.update.error.prepare_update', [], $currentLanguage));
        }

        $updateStatement->bind_param('ssssi', $name, $email, $role, $passwordHash, $userId);
    } else {
        $updateQuery = 'UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?';
        $updateStatement = $connection->prepare($updateQuery);

        if (! $updateStatement) {
            throw new RuntimeException(translate('users.update.error.prepare_update', [], $currentLanguage));
        }

        $updateStatement->bind_param('sssi', $name, $email, $role, $userId);
    }

    $updateStatement->execute();
    $updateStatement->close();

    $selectQuery = 'SELECT id, name, email, role, created_at, last_login_at FROM users WHERE id = ? LIMIT 1';
    $selectStatement = $connection->prepare($selectQuery);

    if (! $selectStatement) {
        throw new RuntimeException(translate('users.error.prepare_retrieve', [], $currentLanguage));
    }

    $selectStatement->bind_param('i', $userId);
    $selectStatement->execute();

    $selectResult = $selectStatement->get_result();
    $user = $selectResult ? $selectResult->fetch_assoc() : null;
    $selectStatement->close();

    if (! $user) {
        throw new RuntimeException(translate('users.update.not_found', [], $currentLanguage));
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
    respondWithError(500, translate('users.update.error.generic', ['error' => $exception->getMessage()], $currentLanguage));
}

if (isset($connection) && $connection instanceof mysqli && isset($user) && isset($user['id'])) {
    $afterState = [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'password_changed' => $passwordUpdated,
    ];

    $beforeState = is_array($existingUserData) ? $existingUserData : [];
    $changes = compute_audit_changes($beforeState, $afterState);

    if ($changes !== []) {
        record_audit_log(
            'update',
            'user',
            (string) $user['id'],
            [
                'before' => $existingUserData,
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
    'message' => translate('users.update.success', [], $currentLanguage),
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
]);
