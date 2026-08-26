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
require_once __DIR__ . '/../../config/mail.php';

/**
 * @param array<string, string> $errors
 */
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

function respondWithSuccess(string $message, array $additional = []): void
{
    http_response_code(200);
    echo json_encode(array_merge([
        'success' => true,
        'message' => $message,
    ], $additional));
    exit;
}

$email = trim((string) ($_POST['email'] ?? ''));

$errors = [];

if ($email === '') {
    $errors['email'] = translate('auth.password_reset.request.validation.email_required', [], $currentLanguage);
} elseif (mb_strlen($email) > 150) {
    $errors['email'] = translate('auth.password_reset.request.validation.email_max', [], $currentLanguage);
} elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = translate('auth.password_reset.request.validation.email_invalid', [], $currentLanguage);
}

if ($errors !== []) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), $errors);
}

$tokenTtlMinutes = (int) (getenv('PASSWORD_RESET_TOKEN_EXPIRATION_MINUTES') ?: 60);

if ($tokenTtlMinutes <= 0) {
    $tokenTtlMinutes = 60;
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

$genericSuccessMessage = translate('auth.password_reset.request.success', [], $currentLanguage);

try {
    $query = 'SELECT id, name, email FROM users WHERE email = ? LIMIT 1';
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException(translate('auth.password_reset.request.error.prepare_statement', [], $currentLanguage));
    }

    $statement->bind_param('s', $email);
    $statement->execute();

    $result = $statement->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $statement->close();

    if (! $user) {
        respondWithSuccess($genericSuccessMessage);
    }

    $userId = (int) $user['id'];

    $deleteQuery = 'DELETE FROM password_resets WHERE user_id = ?';
    $deleteStatement = $connection->prepare($deleteQuery);

    if ($deleteStatement) {
        $deleteStatement->bind_param('i', $userId);
        $deleteStatement->execute();
        $deleteStatement->close();
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        $token = hash('sha256', uniqid('pwd_reset_', true) . microtime(true));
    }

    $tokenHash = hash('sha256', $token);

    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC-6'));
    } catch (Throwable $exception) {
        $now = new DateTimeImmutable();
    }

    $expiresAt = $now->modify(sprintf('+%d minutes', $tokenTtlMinutes));
    $expiresAtFormatted = $expiresAt->format('Y-m-d H:i:s');

    $insertQuery = 'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)';
    $insertStatement = $connection->prepare($insertQuery);

    if (! $insertStatement) {
        throw new RuntimeException(translate('auth.password_reset.request.error.prepare_statement', [], $currentLanguage));
    }

    $insertStatement->bind_param('iss', $userId, $tokenHash, $expiresAtFormatted);
    $insertStatement->execute();
    $insertStatement->close();

    $recipientName = (string) ($user['name'] ?? '');
    $emailSent = sendPasswordResetEmail(
        (string) $user['email'],
        $recipientName,
        $token,
        $tokenTtlMinutes,
        $currentLanguage
    );

    if (! $emailSent) {
        respondWithError(500, translate('auth.password_reset.request.error.mail', [], $currentLanguage));
    }

    respondWithSuccess($genericSuccessMessage, [
        'redirect' => 'login.php?status=password_reset_sent',
    ]);
} catch (Throwable $exception) {
    respondWithError(
        500,
        translate('auth.password_reset.request.error.unexpected', ['error' => $exception->getMessage()], $currentLanguage)
    );
}
