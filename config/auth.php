<?php

declare(strict_types=1);

require_once __DIR__ . '/api_tokens.php';
require_once __DIR__ . '/audit.php';

/**
 * Retrieve the Authorization header from the current request.
 */
function get_authorization_header(): ?string
{
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    }

    if (isset($_SERVER['Authorization'])) {
        return trim((string) $_SERVER['Authorization']);
    }

    if (function_exists('apache_request_headers')) {
        try {
            $headers = apache_request_headers();

            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    return trim((string) $value);
                }
            }
        } catch (Throwable $exception) {
            // Ignore failures while attempting to read headers.
        }
    }

    return null;
}

/**
 * Extract the bearer token from an Authorization header.
 */
function extract_bearer_token(?string $header): ?string
{
    if ($header === null) {
        return null;
    }

    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }

    return null;
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
 *     scopes: array<int, string>,
 *     rate_limit: array{limit: int, remaining: int, reset: int}
 * }
 */
function authenticate_api_request(
    array $requiredScopes = [],
    ?string $language = null,
    ?mysqli $connection = null
): array {
    $language = $language !== null ? normalizeLanguage($language) : null;
    $authorizationHeader = get_authorization_header();

    if ($authorizationHeader === null) {
        throw new ApiTokenException(translate('api.auth.authorization_missing', [], $language), 401);
    }

    $bearerToken = extract_bearer_token($authorizationHeader);

    if ($bearerToken === null) {
        throw new ApiTokenException(translate('api.auth.authorization_invalid', [], $language), 401);
    }

    $validation = validate_api_token($bearerToken, $requiredScopes, $connection, $language);

    $rateLimit = enforce_api_rate_limit($validation['token']['id'], $validation['user']['id'], $validation['scopes'], $language);

    try {
        record_audit_log(
            'api_request',
            'api_tokens',
            (string) $validation['token']['id'],
            [
                'path' => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'scopes' => $validation['scopes'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'token_prefix' => $validation['token']['token_prefix'],
            ],
            $validation['user']['id']
        );
    } catch (Throwable $exception) {
        error_log('[api_auth] Failed to record audit log: ' . $exception->getMessage());
    }

    $validation['rate_limit'] = $rateLimit;

    return $validation;
}

/**
 * @return array{limit: int, remaining: int, reset: int}
 */
function enforce_api_rate_limit(
    int $tokenId,
    int $userId,
    array $scopes,
    ?string $language = null,
    int $maxRequests = 120,
    int $windowSeconds = 60
): array {
    $language = $language !== null ? normalizeLanguage($language) : null;
    $directory = dirname(__DIR__) . '/storage/api_rate_limits';
    $cacheKey = 'token:' . $tokenId;
    $filename = $directory . '/' . sha1($cacheKey) . '.json';
    $now = time();

    if (! is_dir($directory)) {
        try {
            mkdir($directory, 0755, true);
        } catch (Throwable $exception) {
            error_log('[api_auth] Unable to create rate limit directory: ' . $exception->getMessage());
        }
    }

    $data = [
        'count' => 0,
        'reset' => $now + $windowSeconds,
    ];

    $handle = @fopen($filename, 'c+');

    if ($handle === false) {
        return finalize_rate_limit_headers($maxRequests, $maxRequests - 1, $now + $windowSeconds);
    }

    try {
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            return finalize_rate_limit_headers($maxRequests, $maxRequests - 1, $now + $windowSeconds);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);

        if (is_string($contents) && $contents !== '') {
            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    $data['count'] = isset($decoded['count']) ? (int) $decoded['count'] : 0;
                    $data['reset'] = isset($decoded['reset']) ? (int) $decoded['reset'] : $now + $windowSeconds;
                }
            } catch (Throwable $exception) {
                // Ignore decoding issues and start a new window.
            }
        }

        if ($now >= $data['reset']) {
            $data['count'] = 0;
            $data['reset'] = $now + $windowSeconds;
        }

        if ($data['count'] >= $maxRequests) {
            $retryAfter = max(1, $data['reset'] - $now);
            header('Retry-After: ' . $retryAfter);
            header('X-RateLimit-Limit: ' . $maxRequests);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $data['reset']);

            $message = translate('api.auth.rate_limited', ['seconds' => $retryAfter], $language);
            throw new ApiTokenException($message, 429);
        }

        $data['count']++;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $exception) {
        fclose($handle);
        error_log('[api_auth] Rate limiter failed: ' . $exception->getMessage());

        return finalize_rate_limit_headers($maxRequests, $maxRequests - 1, $now + $windowSeconds);
    }

    return finalize_rate_limit_headers($maxRequests, $maxRequests - $data['count'], $data['reset']);
}

/**
 * @return array{limit: int, remaining: int, reset: int}
 */
function finalize_rate_limit_headers(int $limit, int $remaining, int $resetTimestamp): array
{
    $remaining = max(0, $remaining);

    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . $remaining);
    header('X-RateLimit-Reset: ' . $resetTimestamp);

    return [
        'limit' => $limit,
        'remaining' => $remaining,
        'reset' => $resetTimestamp,
    ];
}
