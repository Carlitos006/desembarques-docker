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

$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';

if (mb_strlen($name) > 100) {
    respond_with_json(422, [
        'success' => false,
        'message' => translate('api.tokens.validation.name_max', [], $currentLanguage),
        'errors' => [
            'name' => translate('api.tokens.validation.name_max', [], $currentLanguage),
        ],
    ]);
}

$rawScopes = $_POST['scopes'] ?? [];

if (is_string($rawScopes)) {
    $rawScopes = [$rawScopes];
}

if (! is_array($rawScopes)) {
    $rawScopes = [];
}

$scopes = [];

foreach ($rawScopes as $scope) {
    if (is_string($scope)) {
        $scopes[] = $scope;
    }
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
    $token = create_api_token($userId, $scopes, $name, $connection, $currentLanguage);
    $plainToken = $token['token'];
    $tokenRecord = $token;
    $tokenRecord['token'] = '';

    record_audit_log(
        'api_token.created',
        'api_tokens',
        (string) $tokenRecord['id'],
        [
            'name' => $tokenRecord['name'],
            'scopes' => $tokenRecord['scopes'],
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

respond_with_json(201, [
    'success' => true,
    'message' => translate('api.tokens.created', [], $currentLanguage),
    'token' => $plainToken,
    'token_prefix' => $tokenRecord['token_prefix'],
    'record' => $tokenRecord,
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
