<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/i18n.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/config/api_tokens.php';

if (! function_exists('api_v1_detect_language')) {
    function api_v1_detect_language(): string
    {
        $language = $_GET['lang'] ?? null;

        if ($language === null && isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $accept = (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'];
            $segments = explode(',', $accept);

            foreach ($segments as $segment) {
                $code = trim($segment);

                if ($code === '') {
                    continue;
                }

                $code = strtolower(substr($code, 0, 2));
                $language = $code;
                break;
            }
        }

        return normalizeLanguage($language);
    }
}

if (! function_exists('api_v1_json_response')) {
    /**
     * @param array<string, mixed> $payload
     */
    function api_v1_json_response(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (! function_exists('api_v1_success')) {
    /**
     * @param array<string, mixed>|array<int, mixed> $data
     * @param array<string, mixed> $meta
     */
    function api_v1_success(array $data, array $meta = [], int $statusCode = 200): void
    {
        api_v1_json_response($statusCode, [
            'status' => 'ok',
            'data' => $data,
            'meta' => $meta,
        ]);
    }
}

if (! function_exists('api_v1_error')) {
    /**
     * @param array<string, mixed> $errors
     */
    function api_v1_error(int $statusCode, string $message, array $errors = []): void
    {
        api_v1_json_response($statusCode, [
            'status' => 'error',
            'message' => $message,
            'errors' => $errors,
        ]);
    }
}

if (! function_exists('api_v1_parse_body')) {
    /**
     * @return array<string, mixed>
     */
    function api_v1_parse_body(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        if (is_string($contentType) && stripos($contentType, 'application/json') !== false) {
            $rawBody = file_get_contents('php://input');

            if ($rawBody === '' || $rawBody === false) {
                return [];
            }

            try {
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        $result = [];

        foreach ($_POST as $key => $value) {
            if (is_array($value) || is_scalar($value)) {
                $result[(string) $key] = $value;
            }
        }

        return $result;
    }
}

if (! function_exists('api_v1_require_method')) {
    function api_v1_require_method(array $allowedMethods, string $language): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if (! in_array($method, $allowedMethods, true)) {
            api_v1_error(405, translate('common.method_not_allowed', [], $language));
        }
    }
}

if (! function_exists('api_v1_handle_exception')) {
    function api_v1_handle_exception(Throwable $exception, string $language): void
    {
        if ($exception instanceof ApiTokenException) {
            api_v1_error($exception->getStatusCode(), $exception->getMessage(), $exception->getErrors());
        }

        api_v1_error(500, translate('api.v1.error.unexpected', ['error' => $exception->getMessage()], $language));
    }
}
