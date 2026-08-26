<?php

declare(strict_types=1);

/**
 * Temporary diagnostic wrapper for table/parse.php.
 * Remove this file after diagnosing production.
 */

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/parse-debug-error.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$requestId = bin2hex(random_bytes(6));
header('X-Parse-Debug-ID: ' . $requestId);

$handled = false;
$startedAt = microtime(true);
$warnings = [];

function parse_debug_clean_output(): void
{
    if (! function_exists('ob_get_level')) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

/**
 * @return array<string, mixed>
 */
function parse_debug_environment(string $requestId, float $startedAt, array $warnings): array
{
    $uploaded = $_FILES['pdf'] ?? null;
    $parseFile = __DIR__ . '/parse.php';
    $configFile = dirname(__DIR__) . '/config/pedimentos.php';

    $disabled = array_values(array_filter(array_map(
        'trim',
        explode(',', (string) ini_get('disable_functions'))
    )));

    return [
        'request_id' => $requestId,
        'elapsed_seconds' => round(microtime(true) - $startedAt, 4),
        'runtime' => [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
        ],
        'uploaded_file' => is_array($uploaded) ? [
            'name' => isset($uploaded['name']) ? basename((string) $uploaded['name']) : null,
            'size' => isset($uploaded['size']) ? (int) $uploaded['size'] : null,
            'type' => isset($uploaded['type']) ? (string) $uploaded['type'] : null,
            'error' => isset($uploaded['error']) ? (int) $uploaded['error'] : null,
            'tmp_exists' => isset($uploaded['tmp_name'])
                ? is_file((string) $uploaded['tmp_name'])
                : false,
            'tmp_readable' => isset($uploaded['tmp_name'])
                ? is_readable((string) $uploaded['tmp_name'])
                : false,
            'is_uploaded_file' => isset($uploaded['tmp_name'])
                ? is_uploaded_file((string) $uploaded['tmp_name'])
                : false,
        ] : null,
        'files' => [
            'parse_exists' => is_file($parseFile),
            'parse_readable' => is_readable($parseFile),
            'parse_sha256' => is_file($parseFile) ? hash_file('sha256', $parseFile) : null,
            'config_exists' => is_file($configFile),
            'config_readable' => is_readable($configFile),
            'config_sha256' => is_file($configFile) ? hash_file('sha256', $configFile) : null,
        ],
        'functions' => [
            'exec' => function_exists('exec') && !in_array('exec', $disabled, true),
            'shell_exec' => function_exists('shell_exec') && !in_array('shell_exec', $disabled, true),
            'proc_open' => function_exists('proc_open') && !in_array('proc_open', $disabled, true),
        ],
        'warnings_before_failure' => $warnings,
        'log_file' => basename(__DIR__ . '/parse-debug-error.log'),
    ];
}

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ) use (&$warnings): bool {
        if (! (error_reporting() & $severity)) {
            return false;
        }

        $warnings[] = [
            'severity' => $severity,
            'message' => $message,
            'file' => basename($file),
            'line' => $line,
        ];

        // Let PHP keep its standard logging behavior.
        return false;
    }
);

register_shutdown_function(
    static function () use (&$handled, $requestId, $startedAt, &$warnings): void {
        if ($handled) {
            return;
        }

        $error = error_get_last();
        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ];

        if (! is_array($error) || ! in_array($error['type'] ?? null, $fatalTypes, true)) {
            return;
        }

        parse_debug_clean_output();

        if (! headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Parse-Debug-ID: ' . $requestId);
        }

        $payload = [
            'ok' => false,
            'diagnostic' => 'fatal_shutdown',
            'fatal' => [
                'type' => $error['type'] ?? null,
                'message' => $error['message'] ?? 'Unknown fatal error',
                'file' => isset($error['file']) ? basename((string) $error['file']) : null,
                'line' => $error['line'] ?? null,
            ],
            'environment' => parse_debug_environment(
                $requestId,
                $startedAt,
                $warnings
            ),
        ];

        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
);

try {
    require __DIR__ . '/parse.php';
    $handled = true;
} catch (Throwable $exception) {
    $handled = true;
    parse_debug_clean_output();

    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Parse-Debug-ID: ' . $requestId);
    }

    $payload = [
        'ok' => false,
        'diagnostic' => 'uncaught_throwable',
        'exception' => [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => array_slice(
                array_map(
                    static fn(array $frame): array => [
                        'file' => isset($frame['file'])
                            ? basename((string) $frame['file'])
                            : null,
                        'line' => $frame['line'] ?? null,
                        'function' => $frame['function'] ?? null,
                        'class' => $frame['class'] ?? null,
                    ],
                    $exception->getTrace()
                ),
                0,
                12
            ),
        ],
        'environment' => parse_debug_environment(
            $requestId,
            $startedAt,
            $warnings
        ),
    ];

    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
}
