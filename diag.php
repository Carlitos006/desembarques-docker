<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$root = dirname(__DIR__);
$vendorAutoload = $root . '/vendor/autoload.php';
$platformCheck = $root . '/vendor/composer/platform_check.php';

function boolText(bool $value): string
{
    return $value ? 'yes' : 'no';
}

function parseIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

$disabled = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ini_get('disable_functions'))
)));

$tempDir = sys_get_temp_dir();
$platformRequirement = null;
$platformCompatible = null;
$platformText = null;

if (is_file($platformCheck) && is_readable($platformCheck)) {
    $platformText = (string) file_get_contents($platformCheck);

    if (preg_match('/PHP_VERSION_ID\s*>=\s*(\d+)/', $platformText, $match)) {
        $requiredId = (int) $match[1];
        $platformRequirement = sprintf(
            '%d.%d.%d',
            intdiv($requiredId, 10000),
            intdiv($requiredId % 10000, 100),
            $requiredId % 100
        );
        $platformCompatible = PHP_VERSION_ID >= $requiredId;
    }
}

$result = [
    'ok' => true,
    'runtime' => [
        'php_version' => PHP_VERSION,
        'php_version_id' => PHP_VERSION_ID,
        'sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
    ],
    'composer' => [
        'vendor_autoload_exists' => is_file($vendorAutoload),
        'vendor_autoload_readable' => is_readable($vendorAutoload),
        'platform_check_exists' => is_file($platformCheck),
        'required_php_detected' => $platformRequirement,
        'runtime_meets_detected_requirement' => $platformCompatible,
    ],
    'extensions' => [
        'mbstring' => extension_loaded('mbstring'),
        'iconv' => extension_loaded('iconv'),
        'json' => extension_loaded('json'),
        'fileinfo' => extension_loaded('fileinfo'),
        'zlib' => extension_loaded('zlib'),
    ],
    'execution' => [
        'exec_available' => function_exists('exec') && !in_array('exec', $disabled, true),
        'shell_exec_available' => function_exists('shell_exec') && !in_array('shell_exec', $disabled, true),
        'proc_open_available' => function_exists('proc_open') && !in_array('proc_open', $disabled, true),
        'disabled_functions' => $disabled,
        'open_basedir' => (string) ini_get('open_basedir'),
    ],
    'limits' => [
        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
        'post_max_size' => (string) ini_get('post_max_size'),
        'memory_limit' => (string) ini_get('memory_limit'),
        'max_execution_time' => (string) ini_get('max_execution_time'),
        'max_file_uploads' => (string) ini_get('max_file_uploads'),
        'upload_limit_bytes' => parseIniBytes((string) ini_get('upload_max_filesize')),
        'post_limit_bytes' => parseIniBytes((string) ini_get('post_max_size')),
    ],
    'filesystem' => [
        'project_root' => $root,
        'temp_dir' => $tempDir,
        'temp_dir_exists' => is_dir($tempDir),
        'temp_dir_writable' => is_writable($tempDir),
    ],
    'autoload_test' => [
        'attempted' => false,
        'loaded' => false,
        'pdf_parser_class_available' => false,
        'captured_output' => '',
        'error' => null,
    ],
];

if (
    is_file($vendorAutoload)
    && is_readable($vendorAutoload)
    && $platformCompatible !== false
) {
    $result['autoload_test']['attempted'] = true;
    ob_start();

    try {
        set_error_handler(
            static function (
                int $severity,
                string $message,
                string $file,
                int $line
            ): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
        );

        require $vendorAutoload;
        restore_error_handler();

        $result['autoload_test']['loaded'] = true;
        $result['autoload_test']['pdf_parser_class_available'] =
            class_exists(\Smalot\PdfParser\Parser::class);
    } catch (Throwable $exception) {
        restore_error_handler();
        $result['autoload_test']['error'] = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
        ];
    } finally {
        $result['autoload_test']['captured_output'] = trim((string) ob_get_clean());
    }
}

$problems = [];

if (!is_file($vendorAutoload) || !is_readable($vendorAutoload)) {
    $problems[] = 'vendor/autoload.php no existe o no es legible.';
}

if ($platformCompatible === false) {
    $problems[] = sprintf(
        'El vendor exige PHP %s o superior, pero producción ejecuta PHP %s.',
        $platformRequirement ?? 'desconocido',
        PHP_VERSION
    );
}

if (!extension_loaded('mbstring')) {
    $problems[] = 'La extensión mbstring no está habilitada.';
}

if (!is_writable($tempDir)) {
    $problems[] = 'La carpeta temporal de PHP no tiene permisos de escritura.';
}

if (
    $result['autoload_test']['attempted']
    && !$result['autoload_test']['loaded']
) {
    $problems[] = 'Composer no pudo cargar vendor/autoload.php.';
}

if (
    $result['autoload_test']['loaded']
    && !$result['autoload_test']['pdf_parser_class_available']
) {
    $problems[] = 'La clase Smalot\\PdfParser\\Parser no está instalada.';
}

$result['problems'] = $problems;
$result['ok'] = $problems === [];
$result['next_step'] = $problems === []
    ? 'El entorno base es compatible. Revisa el error_log de parse.php durante el POST.'
    : 'Corrige los elementos indicados en problems y repite la prueba.';

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
