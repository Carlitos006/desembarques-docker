<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/csrf.php';

$requestedLanguage = $_POST['language'] ?? ($_SESSION['language'] ?? null);

if ($requestedLanguage !== null && $requestedLanguage !== '') {
    setAppLanguage((string) $requestedLanguage);
}

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

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$timezoneIdentifier = trim((string) ($_POST['timezone'] ?? ''));
$userTimezone = null;

if ($timezoneIdentifier !== '') {
    try {
        $userTimezone = new DateTimeZone($timezoneIdentifier);
    } catch (Throwable $exception) {
        $userTimezone = null;
    }
}

$defaultTimezoneName = (string) date_default_timezone_get();

if ($defaultTimezoneName === '') {
    $defaultTimezoneName = 'UTC-6';
}

$errors = [];

if ($email === '') {
    $errors['email'] = translate('auth.validation.email_required', [], $currentLanguage);
} elseif (mb_strlen($email) > 150) {
    $errors['email'] = translate('auth.validation.email_max', [], $currentLanguage);
} elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = translate('auth.validation.email_invalid', [], $currentLanguage);
}

if ($password === '') {
    $errors['password'] = translate('auth.validation.password_required', [], $currentLanguage);
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
    $query = 'SELECT id, name, email, password_hash, role FROM users WHERE email = ? LIMIT 1';
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException(translate('auth.login.error.prepare_statement', [], $currentLanguage));
    }

    $statement->bind_param('s', $email);
    $statement->execute();

    $result = $statement->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $statement->close();

    if (! $user) {
        respondWithError(401, translate('auth.login.error.invalid_credentials', [], $currentLanguage));
    }

    if (! password_verify($password, (string) $user['password_hash'])) {
        respondWithError(401, translate('auth.login.error.invalid_credentials', [], $currentLanguage));
    }

    $userId = (int) $user['id'];

    $timezoneForNow = $userTimezone;

    if (! $timezoneForNow) {
        try {
            $timezoneForNow = new DateTimeZone($defaultTimezoneName);
        } catch (Throwable $exception) {
            $timezoneForNow = null;
        }
    }

    try {
        // Using the user's timezone when available keeps the stored timestamp aligned with their local time.
        $now = $timezoneForNow instanceof DateTimeZone
            ? new DateTimeImmutable('now', $timezoneForNow)
            : new DateTimeImmutable('now');
    } catch (Throwable $exception) {
        $now = new DateTimeImmutable('now');

        if ($timezoneForNow instanceof DateTimeZone) {
            $now = $now->setTimezone($timezoneForNow);
        }
    }

    try {
        $utcTimezone = new DateTimeZone('UTC-6');
    } catch (Throwable $exception) {
        $utcTimezone = null;
    }

    $lastLoginAtDisplay = $now->format('Y-m-d H:i:s');
    $lastLoginAt = $utcTimezone instanceof DateTimeZone
        ? $now->setTimezone($utcTimezone)->format('Y-m-d H:i:s')
        : $lastLoginAtDisplay;
    $lastLoginTimezone = $timezoneForNow instanceof DateTimeZone ? $timezoneForNow->getName() : $defaultTimezoneName;

    $updateQuery = 'UPDATE users SET last_login_at = ? WHERE id = ? LIMIT 1';
    $updateStatement = $connection->prepare($updateQuery);

    if (! $updateStatement) {
        throw new RuntimeException(translate('auth.login.error.update_last_login', [], $currentLanguage));
    }

    $updateStatement->bind_param('si', $lastLoginAt, $userId);

    if (! $updateStatement->execute()) {
        $updateStatement->close();
        throw new RuntimeException(translate('auth.login.error.update_last_login', [], $currentLanguage));
    }

    $updateStatement->close();

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => $userId,
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'last_login_at' => $lastLoginAtDisplay,
        'last_login_at_utc' => $lastLoginAt,
        'timezone' => $lastLoginTimezone,
    ];
} catch (Throwable $exception) {
    respondWithError(500, translate('auth.login.error.unexpected', ['error' => $exception->getMessage()], $currentLanguage));
}

http_response_code(200);

echo json_encode([
    'success' => true,
    'message' => translate('auth.login.success', [], $currentLanguage),
    'user' => [
        'id' => (int) $_SESSION['user']['id'],
        'name' => (string) $_SESSION['user']['name'],
        'email' => (string) $_SESSION['user']['email'],
        'role' => (string) $_SESSION['user']['role'],
        'last_login_at' => (string) $_SESSION['user']['last_login_at'],
        'last_login_at_utc' => (string) $_SESSION['user']['last_login_at_utc'],
        'timezone' => (string) $_SESSION['user']['timezone'],
    ],
]);
