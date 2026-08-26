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

$token = trim((string) ($_POST['token'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

$errors = [];

if ($token === '') {
    $errors['token'] = translate('auth.password_reset.reset.validation.token_required', [], $currentLanguage);
}

if ($password === '') {
    $errors['password'] = translate('auth.password_reset.reset.validation.password_required', [], $currentLanguage);
} elseif (mb_strlen($password) < 8) {
    $errors['password'] = translate('auth.password_reset.reset.validation.password_min', [], $currentLanguage);
}

if ($password !== $passwordConfirmation) {
    $errors['password_confirmation'] = translate('auth.password_reset.reset.validation.password_confirmation', [], $currentLanguage);
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

$tokenHash = hash('sha256', $token);

try {
    $query = 'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.email, u.name FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? LIMIT 1';
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException(translate('auth.password_reset.reset.error.prepare_statement', [], $currentLanguage));
    }

    $statement->bind_param('s', $tokenHash);
    $statement->execute();

    $result = $statement->get_result();
    $resetRow = $result ? $result->fetch_assoc() : null;
    $statement->close();

    if (! $resetRow) {
        $message = translate('auth.password_reset.reset.error.invalid_token', [], $currentLanguage);
        respondWithError(422, $message, ['token' => $message]);
    }

    $usedAtValue = (string) ($resetRow['used_at'] ?? '');

    if ($usedAtValue !== '' && $usedAtValue !== '0000-00-00 00:00:00') {
        $message = translate('auth.password_reset.reset.error.used', [], $currentLanguage);
        respondWithError(422, $message, ['token' => $message]);
    }

    $expiresAtValue = (string) ($resetRow['expires_at'] ?? '');
    $expiresAt = null;

    try {
        if ($expiresAtValue !== '') {
            $expiresAt = new DateTimeImmutable($expiresAtValue, new DateTimeZone('UTC-6'));
        }
    } catch (Throwable $exception) {
        $expiresAt = null;
    }

    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC-6'));
    } catch (Throwable $exception) {
        $now = new DateTimeImmutable();
    }

    if ($expiresAt instanceof DateTimeImmutable && $expiresAt < $now) {
        $message = translate('auth.password_reset.reset.error.expired', [], $currentLanguage);
        respondWithError(422, $message, ['token' => $message]);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if (! is_string($passwordHash) || $passwordHash === '') {
        respondWithError(500, translate('auth.password_reset.reset.error.hash', [], $currentLanguage));
    }

    $resetId = (int) $resetRow['id'];
    $userId = (int) $resetRow['user_id'];

    $transactionStarted = $connection->begin_transaction();

    if (! $transactionStarted) {
        throw new RuntimeException(translate('auth.password_reset.reset.error.begin_transaction', [], $currentLanguage));
    }

    try {
        $updateUserQuery = 'UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1';
        $updateUserStatement = $connection->prepare($updateUserQuery);

        if (! $updateUserStatement) {
            throw new RuntimeException(translate('auth.password_reset.reset.error.prepare_update', [], $currentLanguage));
        }

        $updateUserStatement->bind_param('si', $passwordHash, $userId);
        $updateUserStatement->execute();
        $updateUserStatement->close();

        $usedAt = $now->format('Y-m-d H:i:s');
        $updateResetQuery = 'UPDATE password_resets SET used_at = ?, updated_at = ? WHERE id = ? LIMIT 1';
        $updateResetStatement = $connection->prepare($updateResetQuery);

        if (! $updateResetStatement) {
            throw new RuntimeException(translate('auth.password_reset.reset.error.prepare_update', [], $currentLanguage));
        }

        $updateResetStatement->bind_param('ssi', $usedAt, $usedAt, $resetId);
        $updateResetStatement->execute();
        $updateResetStatement->close();

        $cleanupQuery = 'DELETE FROM password_resets WHERE user_id = ? AND id <> ?';
        $cleanupStatement = $connection->prepare($cleanupQuery);

        if ($cleanupStatement) {
            $cleanupStatement->bind_param('ii', $userId, $resetId);
            $cleanupStatement->execute();
            $cleanupStatement->close();
        }

        $connection->commit();
    } catch (Throwable $innerException) {
        $connection->rollback();
        throw $innerException;
    }

    respondWithSuccess(
        translate('auth.password_reset.reset.success', [], $currentLanguage),
        ['redirect' => 'login.php?status=password_reset_success']
    );
} catch (Throwable $exception) {
    respondWithError(
        500,
        translate('auth.password_reset.reset.error.unexpected', ['error' => $exception->getMessage()], $currentLanguage)
    );
}
