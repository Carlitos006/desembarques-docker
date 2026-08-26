<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/i18n.php';

/**
 * Exception used for API token operations.
 */
class ApiTokenException extends RuntimeException
{
    /** @var array<string, string> */
    private array $errors;

    private int $statusCode;

    /**
     * @param array<string, string> $errors
     */
    public function __construct(string $message, int $statusCode = 400, array $errors = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->statusCode = $statusCode;
        $this->errors = $errors;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

/**
 * @return array<string, array{label_key: string, description_key: string}>
 */
function api_token_scope_definitions(): array
{
    return [
        'desembarques:read' => [
            'label_key' => 'api_tokens.scope.desembarques_read.label',
            'description_key' => 'api_tokens.scope.desembarques_read.description',
        ],
        'desembarques:write' => [
            'label_key' => 'api_tokens.scope.desembarques_write.label',
            'description_key' => 'api_tokens.scope.desembarques_write.description',
        ],
    ];
}

/**
 * @return array<int, array{key: string, label: string, description: string}>
 */
function available_api_token_scopes(?string $language = null): array
{
    $definitions = api_token_scope_definitions();
    $language = $language !== null ? normalizeLanguage($language) : null;
    $available = [];

    foreach ($definitions as $key => $definition) {
        $available[] = [
            'key' => $key,
            'label' => translate($definition['label_key'], [], $language),
            'description' => translate($definition['description_key'], [], $language),
        ];
    }

    return $available;
}

/**
 * Normalize and validate the requested scopes.
 *
 * @param array<int|string, string> $scopes
 *
 * @return array<int, string>
 */
function normalize_api_token_scopes(array $scopes): array
{
    $definitions = api_token_scope_definitions();
    $normalized = [];

    foreach ($scopes as $scope) {
        $scope = trim((string) $scope);

        if ($scope === '' || ! isset($definitions[$scope])) {
            continue;
        }

        if (! in_array($scope, $normalized, true)) {
            $normalized[] = $scope;
        }
    }

    sort($normalized);

    return $normalized;
}

function generate_api_token_value(int $bytes = 32): string
{
    try {
        return bin2hex(random_bytes($bytes));
    } catch (Throwable $exception) {
        return hash('sha256', uniqid('api_token_', true) . microtime(true));
    }
}

function hash_api_token(string $token): string
{
    return hash('sha256', $token);
}

/**
 * @param array<int, string> $scopes
 *
 * @return array{
 *     id: int,
 *     user_id: int,
 *     token: string,
 *     token_prefix: string,
 *     name: ?string,
 *     scopes: array<int, string>,
 *     last_used: ?string,
 *     created_at: ?string
 * }
 */
function create_api_token(
    int $userId,
    array $scopes,
    ?string $name = null,
    ?mysqli $connection = null,
    ?string $language = null
): array {
    $language = $language !== null ? normalizeLanguage($language) : null;
    $scopes = normalize_api_token_scopes($scopes);

    if ($scopes === []) {
        $message = translate('api.tokens.validation.scopes_required', [], $language);
        throw new ApiTokenException($message, 422, ['scopes' => $message]);
    }

    $plainToken = generate_api_token_value();
    $hashedToken = hash_api_token($plainToken);
    $tokenPrefix = mb_substr($plainToken, 0, 12);
    $nameValue = $name !== null ? trim($name) : null;

    if ($nameValue === '') {
        $nameValue = null;
    }

    try {
        $scopesJson = json_encode($scopes, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new ApiTokenException('Unable to encode token scopes.', 500, [], $exception);
    }

    $database = $connection instanceof mysqli ? $connection : getDatabaseConnection();

    try {
        $statement = $database->prepare(
            'INSERT INTO api_tokens (user_id, token, token_prefix, name, scopes) VALUES (?, ?, ?, ?, ?)'
        );

        if (! $statement instanceof mysqli_stmt) {
            throw new RuntimeException('Unable to prepare API token statement.');
        }

        $statement->bind_param('issss', $userId, $hashedToken, $tokenPrefix, $nameValue, $scopesJson);
        $statement->execute();
        $tokenId = (int) $database->insert_id;
        $statement->close();
    } catch (Throwable $exception) {
        throw new ApiTokenException('Unable to store the API token.', 500, [], $exception);
    }

    $tokenRecord = get_api_token_record($tokenId, $database);

    if ($tokenRecord === null) {
        throw new ApiTokenException('Failed to retrieve the stored token.', 500);
    }

    $tokenRecord['token'] = $plainToken;

    return $tokenRecord;
}

/**
 * @return array<int, array{
 *     id: int,
 *     token_prefix: string,
 *     name: ?string,
 *     scopes: array<int, string>,
 *     last_used: ?string,
 *     created_at: ?string
 * }>
 */
function list_api_tokens_for_user(int $userId, ?mysqli $connection = null): array
{
    $database = $connection instanceof mysqli ? $connection : getDatabaseConnection();

    try {
        $statement = $database->prepare(
            'SELECT id, token_prefix, name, scopes, last_used, created_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC'
        );

        if (! $statement instanceof mysqli_stmt) {
            throw new RuntimeException('Unable to prepare token listing.');
        }

        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();
        $tokens = [];

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $tokens[] = [
                    'id' => (int) $row['id'],
                    'token_prefix' => (string) $row['token_prefix'],
                    'name' => isset($row['name']) ? (string) $row['name'] : null,
                    'scopes' => decode_token_scopes($row['scopes'] ?? '[]'),
                    'last_used' => isset($row['last_used']) ? (string) $row['last_used'] : null,
                    'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                ];
            }
        }

        $statement->close();

        return $tokens;
    } catch (Throwable $exception) {
        throw new ApiTokenException('Unable to list tokens.', 500, [], $exception);
    }
}

function delete_api_token(int $tokenId, int $userId, ?mysqli $connection = null): bool
{
    $database = $connection instanceof mysqli ? $connection : getDatabaseConnection();

    try {
        $statement = $database->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ? LIMIT 1');

        if (! $statement instanceof mysqli_stmt) {
            throw new RuntimeException('Unable to prepare token deletion statement.');
        }

        $statement->bind_param('ii', $tokenId, $userId);
        $statement->execute();
        $affected = $statement->affected_rows > 0;
        $statement->close();

        return $affected;
    } catch (Throwable $exception) {
        throw new ApiTokenException('Unable to delete the API token.', 500, [], $exception);
    }
}

/**
 * @param array<int, string> $requiredScopes
 *
 * @return array{
 *     token: array{
 *         id: int,
 *         user_id: int,
 *         token_prefix: string,
 *         name: ?string,
 *         scopes: array<int, string>,
 *         last_used: ?string,
 *         created_at: ?string
 *     },
 *     user: array{
 *         id: int,
 *         name: string,
 *         email: string,
 *         role: string
 *     },
 *     scopes: array<int, string>
 * }
 */
function validate_api_token(
    string $plainToken,
    array $requiredScopes = [],
    ?mysqli $connection = null,
    ?string $language = null
): array {
    $language = $language !== null ? normalizeLanguage($language) : null;
    $plainToken = trim($plainToken);

    if ($plainToken === '') {
        throw new ApiTokenException(translate('api.auth.token_missing', [], $language), 401);
    }

    $hashedToken = hash_api_token($plainToken);
    $database = $connection instanceof mysqli ? $connection : getDatabaseConnection();

    try {
        $query = 'SELECT t.id, t.user_id, t.token_prefix, t.name, t.scopes, t.last_used, t.created_at, '
            . 'u.name AS user_name, u.email AS user_email, u.role AS user_role '
            . 'FROM api_tokens t '
            . 'INNER JOIN users u ON u.id = t.user_id '
            . 'WHERE t.token = ? LIMIT 1';

        $statement = $database->prepare($query);

        if (! $statement instanceof mysqli_stmt) {
            throw new RuntimeException('Unable to prepare token validation query.');
        }

        $statement->bind_param('s', $hashedToken);
        $statement->execute();
        $result = $statement->get_result();
        $record = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $statement->close();
    } catch (Throwable $exception) {
        throw new ApiTokenException('Unable to validate the token.', 500, [], $exception);
    }

    if (! $record) {
        throw new ApiTokenException(translate('api.auth.token_invalid', [], $language), 401);
    }

    $tokenScopes = decode_token_scopes($record['scopes'] ?? '[]');
    $requiredScopes = normalize_api_token_scopes($requiredScopes);

    foreach ($requiredScopes as $scope) {
        if (! in_array($scope, $tokenScopes, true)) {
            $message = translate('api.auth.token_missing_scope', ['scope' => $scope], $language);
            throw new ApiTokenException($message, 403);
        }
    }

    $tokenId = (int) $record['id'];

    try {
        $update = $database->prepare('UPDATE api_tokens SET last_used = NOW() WHERE id = ?');

        if ($update instanceof mysqli_stmt) {
            $update->bind_param('i', $tokenId);
            $update->execute();
            $update->close();
        }
    } catch (Throwable $exception) {
        // Log but do not fail authentication when last_used cannot be updated.
        error_log('[api_tokens] Unable to update last_used: ' . $exception->getMessage());
    }

    return [
        'token' => [
            'id' => $tokenId,
            'user_id' => (int) $record['user_id'],
            'token_prefix' => (string) $record['token_prefix'],
            'name' => isset($record['name']) ? (string) $record['name'] : null,
            'scopes' => $tokenScopes,
            'last_used' => isset($record['last_used']) ? (string) $record['last_used'] : null,
            'created_at' => isset($record['created_at']) ? (string) $record['created_at'] : null,
        ],
        'user' => [
            'id' => (int) $record['user_id'],
            'name' => isset($record['user_name']) ? (string) $record['user_name'] : '',
            'email' => isset($record['user_email']) ? (string) $record['user_email'] : '',
            'role' => isset($record['user_role']) ? (string) $record['user_role'] : '',
        ],
        'scopes' => $tokenScopes,
    ];
}

/**
 * @return array{
 *     id: int,
 *     token: string,
 *     token_prefix: string,
 *     name: ?string,
 *     scopes: array<int, string>,
 *     last_used: ?string,
 *     created_at: ?string
 * }|null
 */
function get_api_token_record(int $tokenId, ?mysqli $connection = null): ?array
{
    $database = $connection instanceof mysqli ? $connection : getDatabaseConnection();

    try {
        $statement = $database->prepare('SELECT id, user_id, token_prefix, name, scopes, last_used, created_at FROM api_tokens WHERE id = ? LIMIT 1');

        if (! $statement instanceof mysqli_stmt) {
            throw new RuntimeException('Unable to prepare token lookup query.');
        }

        $statement->bind_param('i', $tokenId);
        $statement->execute();
        $result = $statement->get_result();
        $record = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $statement->close();
    } catch (Throwable $exception) {
        throw new ApiTokenException('Unable to load token information.', 500, [], $exception);
    }

    if (! $record) {
        return null;
    }

    return [
        'id' => (int) $record['id'],
        'user_id' => (int) $record['user_id'],
        'token' => '',
        'token_prefix' => (string) $record['token_prefix'],
        'name' => isset($record['name']) ? (string) $record['name'] : null,
        'scopes' => decode_token_scopes($record['scopes'] ?? '[]'),
        'last_used' => isset($record['last_used']) ? (string) $record['last_used'] : null,
        'created_at' => isset($record['created_at']) ? (string) $record['created_at'] : null,
    ];
}

/**
 * @return array<int, string>
 */
function decode_token_scopes(string $json): array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('[api_tokens] Failed to decode scopes: ' . $exception->getMessage());
        return [];
    }

    if (! is_array($decoded)) {
        return [];
    }

    $scopes = [];

    foreach ($decoded as $scope) {
        if (is_string($scope)) {
            $scopes[] = $scope;
        }
    }

    return normalize_api_token_scopes($scopes);
}
