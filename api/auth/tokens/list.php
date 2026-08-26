<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/../../../config/csrf.php';
require_once __DIR__ . '/../../../config/api_tokens.php';
require_once __DIR__ . '/../../../config/database.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
    $tokens = list_api_tokens_for_user($userId, $connection);
} catch (ApiTokenException $exception) {
    respond_with_json(500, [
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    respond_with_json(500, [
        'success' => false,
        'message' => translate('api.tokens.error.unexpected', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
}

respond_with_json(200, [
    'success' => true,
    'tokens' => $tokens,
    'available_scopes' => available_api_token_scopes($currentLanguage),
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
