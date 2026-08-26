<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/../../../config/csrf.php';
require_once __DIR__ . '/../../../config/api_tokens.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/audit.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_with_json(405, [
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
}

if (! isset($_SESSION['user']['id'])) {
    respond_with_json(401, [
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
}

$userId = (int) $_SESSION['user']['id'];

$csrfToken = $_POST['csrf_token'] ?? '';

if (! validate_csrf_token(is_string($csrfToken) ? $csrfToken : null)) {
    respond_with_json(419, [
        'success' => false,
        'message' => translate('common.csrf_token_invalid', [], $currentLanguage),
    ]);
}

$tokenIdInput = isset($_POST['token_id']) ? trim((string) $_POST['token_id']) : '';

if ($tokenIdInput === '' || ! ctype_digit($tokenIdInput)) {
    $message = translate('api.tokens.validation.token_required', [], $currentLanguage);

    respond_with_json(422, [
        'success' => false,
        'message' => $message,
        'errors' => [
            'token_id' => $message,
        ],
        'csrf_token' => csrf_token(),
    ]);
}

$tokenId = (int) $tokenIdInput;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
    $tokenRecord = get_api_token_record($tokenId, $connection);

    if (! $tokenRecord || (int) ($tokenRecord['id'] ?? 0) !== $tokenId) {
        respond_with_json(404, [
            'success' => false,
            'message' => translate('api.tokens.error.not_found', [], $currentLanguage),
            'csrf_token' => csrf_token(),
        ]);
    }

    if ((int) ($tokenRecord['user_id'] ?? 0) !== $userId) {
        respond_with_json(404, [
            'success' => false,
            'message' => translate('api.tokens.error.not_found', [], $currentLanguage),
            'csrf_token' => csrf_token(),
        ]);
    }

    $deleted = delete_api_token($tokenId, $userId, $connection);

    if (! $deleted) {
        respond_with_json(404, [
            'success' => false,
            'message' => translate('api.tokens.error.not_found', [], $currentLanguage),
            'csrf_token' => csrf_token(),
        ]);
    }

    record_audit_log(
        'api_token.revoked',
        'api_tokens',
        (string) $tokenId,
        [
            'name' => $tokenRecord['name'] ?? null,
            'scopes' => $tokenRecord['scopes'] ?? [],
        ],
        $userId,
        $connection
    );
} catch (ApiTokenException $exception) {
    respond_with_json($exception->getStatusCode(), [
        'success' => false,
        'message' => $exception->getMessage(),
        'errors' => $exception->getErrors(),
        'csrf_token' => csrf_token(),
    ]);
} catch (Throwable $exception) {
    respond_with_json(500, [
        'success' => false,
        'message' => translate('api.tokens.error.unexpected', ['error' => $exception->getMessage()], $currentLanguage),
        'csrf_token' => csrf_token(),
    ]);
}

respond_with_json(200, [
    'success' => true,
    'message' => translate('api.tokens.revoked', [], $currentLanguage),
    'csrf_token' => csrf_token(),
]);

/**
 * @param array<string, mixed> $payload
 */
function respond_with_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
