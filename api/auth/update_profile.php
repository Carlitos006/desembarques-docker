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

/**
 * @param mixed $value
 */
function sanitizeText($value, int $maxLength): string
{
    $value = trim((string) $value);

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
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

$userId = (int) $_SESSION['user']['id'];
$name = sanitizeText($_POST['name'] ?? '', 100);
$email = sanitizeText($_POST['email'] ?? '', 150);
$password = (string) ($_POST['password'] ?? '');
$passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

$errors = [];

if ($name === '') {
    $errors['name'] = translate('profile.validation.name_required', [], $currentLanguage);
}

if ($email === '') {
    $errors['email'] = translate('profile.validation.email_required', [], $currentLanguage);
} elseif (mb_strlen($email) > 150) {
    $errors['email'] = translate('profile.validation.email_max', [], $currentLanguage);
} elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = translate('profile.validation.email_invalid', [], $currentLanguage);
}

if ($password !== '') {
    if (mb_strlen($password) < 8) {
        $errors['password'] = translate('profile.validation.password_min', [], $currentLanguage);
    }

    if ($password !== $passwordConfirmation) {
        $errors['password_confirmation'] = translate('profile.validation.password_confirmation', [], $currentLanguage);
    }
} elseif ($passwordConfirmation !== '') {
    $errors['password_confirmation'] = translate('profile.validation.password_confirmation', [], $currentLanguage);
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

$updatedUser = null;

try {
    $lookupQuery = 'SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1';
    $lookupStatement = $connection->prepare($lookupQuery);

    if (! $lookupStatement) {
        throw new RuntimeException('Unable to prepare the profile lookup statement.');
    }

    $lookupStatement->bind_param('i', $userId);
    $lookupStatement->execute();

    $lookupResult = $lookupStatement->get_result();
    $existingUser = $lookupResult ? $lookupResult->fetch_assoc() : null;
    $lookupStatement->close();

    if (! $existingUser) {
        respondWithError(404, translate('profile.error.not_found', [], $currentLanguage));
    }

    $duplicateQuery = 'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1';
    $duplicateStatement = $connection->prepare($duplicateQuery);

    if (! $duplicateStatement) {
        throw new RuntimeException('Unable to prepare the duplicate email lookup.');
    }

    $duplicateStatement->bind_param('si', $email, $userId);
    $duplicateStatement->execute();

    $duplicateResult = $duplicateStatement->get_result();
    $duplicateUser = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
    $duplicateStatement->close();

    if ($duplicateUser) {
        $duplicateMessage = translate('profile.validation.email_unique', [], $currentLanguage);

        respondWithError(409, $duplicateMessage, [
            'email' => $duplicateMessage,
        ]);
    }

    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new RuntimeException('Unable to process the provided password.');
        }

        $updateQuery = 'UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?';
        $updateStatement = $connection->prepare($updateQuery);

        if (! $updateStatement) {
            throw new RuntimeException('Unable to prepare the profile update statement.');
        }

        $updateStatement->bind_param('sssi', $name, $email, $passwordHash, $userId);
    } else {
        $updateQuery = 'UPDATE users SET name = ?, email = ? WHERE id = ?';
        $updateStatement = $connection->prepare($updateQuery);

        if (! $updateStatement) {
            throw new RuntimeException('Unable to prepare the profile update statement.');
        }

        $updateStatement->bind_param('ssi', $name, $email, $userId);
    }

    $updateStatement->execute();
    $updateStatement->close();

    $refreshQuery = 'SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1';
    $refreshStatement = $connection->prepare($refreshQuery);

    if (! $refreshStatement) {
        throw new RuntimeException('Unable to prepare the updated profile retrieval statement.');
    }

    $refreshStatement->bind_param('i', $userId);
    $refreshStatement->execute();

    $refreshResult = $refreshStatement->get_result();
    $updatedUser = $refreshResult ? $refreshResult->fetch_assoc() : null;
    $refreshStatement->close();

    if (! $updatedUser) {
        throw new RuntimeException('Unable to retrieve the updated profile data.');
    }

    $_SESSION['user']['name'] = (string) ($updatedUser['name'] ?? $name);
    $_SESSION['user']['email'] = (string) ($updatedUser['email'] ?? $email);
} catch (Throwable $exception) {
    respondWithError(500, translate('profile.update.error.generic', ['error' => $exception->getMessage()], $currentLanguage));
}

http_response_code(200);

echo json_encode([
    'success' => true,
    'message' => translate('profile.update.success', [], $currentLanguage),
    'user' => [
        'id' => (int) $updatedUser['id'],
        'name' => (string) $updatedUser['name'],
        'email' => (string) $updatedUser['email'],
        'role' => (string) $updatedUser['role'],
    ],
]);
