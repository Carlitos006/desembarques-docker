<?php

declare(strict_types=1);

/**
 * Exception thrown when a PDF text extraction failure occurs.
 */
class PedimentosTextExtractionException extends RuntimeException
{
    /**
     * @var array<string, mixed>
     */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, array $context = [], int $code = 0, ?Throwable $previous = null)
    {
        $this->context = $context;

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}

/**
 * Normalize a string value coming from configuration or environment variables.
 */
function pedimentos_normalize_config_string(mixed $value): string
{
    if (! is_string($value)) {
        return '';
    }

    $trimmed = trim($value);

    return $trimmed !== '' ? $trimmed : '';
}

/**
 * Determine whether PHP is allowed to execute external commands.
 * Shared hosting providers commonly disable exec/shell_exec.
 */
function pedimentos_function_available(string $functionName): bool
{
    if ($functionName === '' || ! function_exists($functionName) || ! is_callable($functionName)) {
        return false;
    }

    $disabled = array_values(array_filter(array_map(
        'trim',
        explode(',', (string) ini_get('disable_functions'))
    )));

    foreach ($disabled as $disabledFunction) {
        if (strcasecmp($disabledFunction, $functionName) === 0) {
            return false;
        }
    }

    return true;
}

function pedimentos_command_execution_available(): bool
{
    return pedimentos_function_available('exec');
}

/**
 * Locate the pdftotext binary.
 *
 * @return array{binary: string, source: string|null}
 */
function pedimentos_locate_pdftotext_binary(?string $preferred = null, ?string $fallback = null): array
{
    $candidates = [];
    $canExecute = pedimentos_command_execution_available();

    if ($preferred !== null && $preferred !== '') {
        $candidates[] = ['value' => $preferred, 'source' => 'config'];
    }

    if ($fallback !== null && $fallback !== '') {
        $candidates[] = ['value' => $fallback, 'source' => 'default'];
    }

    if ($canExecute && function_exists('shell_exec')) {
        $resolved = @shell_exec('command -v pdftotext 2>/dev/null');
        if (is_string($resolved)) {
            $resolved = trim($resolved);
            if ($resolved !== '') {
                $candidates[] = ['value' => $resolved, 'source' => 'which'];
            }
        }
    }

    $defaultCandidates = [
        ['value' => '/usr/bin/pdftotext', 'source' => 'default'],
        ['value' => '/usr/local/bin/pdftotext', 'source' => 'default'],
    ];

    if ($canExecute) {
        $defaultCandidates[] = ['value' => 'pdftotext', 'source' => 'system'];
    }

    $candidates = array_merge($candidates, $defaultCandidates);

    foreach ($candidates as $candidate) {
        $value = trim((string) ($candidate['value'] ?? ''));

        if ($value === '') {
            continue;
        }

        if (strpos($value, DIRECTORY_SEPARATOR) !== false) {
            if (@is_file($value) && @is_executable($value)) {
                return ['binary' => $value, 'source' => $candidate['source'] ?? null];
            }

            continue;
        }

        return ['binary' => $value, 'source' => $candidate['source'] ?? null];
    }

    return ['binary' => '', 'source' => null];
}

/**
 * Normalize an environment value into a string when possible.
 */
function pedimentos_normalize_env_value(mixed $value): ?string
{
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_object($value) && method_exists($value, '__toString')) {
        return (string) $value;
    }

    return null;
}

/**
 * Retrieve an environment variable from common PHP sources.
 */
function pedimentos_get_env(string $name): ?string
{
    if ($name === '') {
        return null;
    }

    $keys = [$name];
    $lower = strtolower($name);
    $upper = strtoupper($name);

    if ($lower !== $name) {
        $keys[] = $lower;
    }

    if ($upper !== $name && $upper !== $lower) {
        $keys[] = $upper;
    }

    $superglobals = [];

    if (isset($_ENV) && is_array($_ENV)) {
        $superglobals[] = $_ENV;
    }

    if (isset($_SERVER) && is_array($_SERVER)) {
        $superglobals[] = $_SERVER;
    }

    foreach ($superglobals as $bucket) {
        foreach ($keys as $candidate) {
            if (! array_key_exists($candidate, $bucket)) {
                continue;
            }

            $normalized = pedimentos_normalize_env_value($bucket[$candidate]);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        foreach ($bucket as $bucketKey => $bucketValue) {
            if (! is_string($bucketKey)) {
                continue;
            }

            foreach ($keys as $candidate) {
                if (strcasecmp($bucketKey, $candidate) !== 0) {
                    continue;
                }

                $normalized = pedimentos_normalize_env_value($bucketValue);

                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }
    }

    $environment = getenv();

    if (is_array($environment)) {
        foreach ($environment as $envKey => $envValue) {
            if (! is_string($envKey)) {
                continue;
            }

            foreach ($keys as $candidate) {
                if (strcasecmp($envKey, $candidate) !== 0) {
                    continue;
                }

                $normalized = pedimentos_normalize_env_value($envValue);

                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }
    }

    foreach ($keys as $candidate) {
        $value = getenv($candidate);

        if ($value !== false && $value !== null) {
            return (string) $value;
        }

        $value = getenv($candidate, true);

        if ($value !== false && $value !== null) {
            return (string) $value;
        }
    }

    return null;
}

/**
 * Resolve the temporary directory to be used by auxiliary tools.
 */
function pedimentos_resolve_tmp_dir(?string $preferred = null): string
{
    $candidate = pedimentos_normalize_config_string($preferred);

    if ($candidate !== '' && @is_dir($candidate) && @is_writable($candidate)) {
        return rtrim($candidate, DIRECTORY_SEPARATOR);
    }

    $systemTmp = sys_get_temp_dir();

    return is_string($systemTmp) ? rtrim($systemTmp, DIRECTORY_SEPARATOR) : '/tmp';
}

/**
 * Retrieve the pedimentos parser configuration.
 *
 * @return array{
 *     page_from: int|null,
 *     page_to: int|null,
 *     text_driver: string,
 *     text_driver_source: string|null,
 *     pdftotext_path: string|null,
 *     pdftotext_default: string|null,
 *     pdftotext_source: string|null,
 *     tmp_dir: string|null
 * }
 */
function pedimentos_parser_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $pageFrom = pedimentos_get_env('PEDIMENTOS_PDF_PAGE_FROM');
    $pageTo = pedimentos_get_env('PEDIMENTOS_PDF_PAGE_TO');
    $pdftotextEnv = pedimentos_get_env('PEDIMENTOS_PDFTOTEXT_PATH');
    $tmpEnv = pedimentos_get_env('PEDIMENTOS_TMP_DIR');

    $pageFromValue = is_string($pageFrom) && $pageFrom !== '' ? (int) $pageFrom : null;
    $pageToValue = is_string($pageTo) && $pageTo !== '' ? (int) $pageTo : null;

    $pdftotextPath = pedimentos_normalize_config_string($pdftotextEnv);
    $tmpDir = pedimentos_resolve_tmp_dir(is_string($tmpEnv) ? $tmpEnv : null);

    $pdftotextInfo = pedimentos_locate_pdftotext_binary($pdftotextPath !== '' ? $pdftotextPath : null, null);

    $config = [
        'page_from' => $pageFromValue,
        'page_to' => $pageToValue,
        'text_driver' => 'smalot',
        'text_driver_source' => 'config',
        'pdftotext_path' => $pdftotextPath !== '' ? $pdftotextPath : null,
        'pdftotext_default' => $pdftotextInfo['binary'] !== '' ? $pdftotextInfo['binary'] : null,
        'pdftotext_source' => $pdftotextInfo['source'],
        'tmp_dir' => $tmpDir,
    ];

    return $config;
}

/**
 * Determine whether the smalot/pdfparser library is available.
 */
function pedimentos_pdfparser_available(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    $autoloadPaths = [
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];

    foreach ($autoloadPaths as $autoloadPath) {
        if (is_string($autoloadPath) && @is_file($autoloadPath)) {
            require_once $autoloadPath;
        }
    }

    $available = class_exists('\\Smalot\\PdfParser\\Parser');

    return $available;
}

/**
 * @return array{text: string, driver: string}
 *
 * @throws PedimentosTextExtractionException
 */
function pedimentos_extract_text_from_pdf(string $pdfPath, ?int $pageFrom, ?int $pageTo, array $config): array
{
    $requested = $config['text_driver'] ?? 'smalot';
    if (! is_string($requested)) {
        $requested = 'smalot';
    }

    $requestedNormalized = strtolower(trim($requested));

    if ($requestedNormalized === 'pdftotext') {
        try {
            $extraction = pedimentos_extract_text_with_pdftotext($pdfPath, $pageFrom, $pageTo, $config);
            $extraction['requested'] = 'pdftotext';

            return $extraction;
        } catch (Throwable $exception) {
            if (! pedimentos_pdfparser_available()) {
                if ($exception instanceof PedimentosTextExtractionException) {
                    throw $exception;
                }

                throw new PedimentosTextExtractionException(
                    'The pdftotext driver failed and smalot/pdfparser is not available.',
                    [
                        'driver' => 'pdftotext',
                        'error' => $exception->getMessage(),
                        'exception' => get_class($exception),
                    ],
                    0,
                    $exception
                );
            }

            $fallback = pedimentos_extract_text_with_pdfparser($pdfPath, $pageFrom, $pageTo);
            $fallback['requested'] = 'pdftotext';
            $fallback['fallback_from'] = 'pdftotext';
            $fallback['fallback_reason'] = $exception->getMessage();
            $fallback['fallback_exception'] = get_class($exception);

            if ($exception instanceof PedimentosTextExtractionException) {
                $fallback['fallback_context'] = $exception->getContext();
            }

            return $fallback;
        }
    }

    if (! pedimentos_pdfparser_available()) {
        throw new PedimentosTextExtractionException('smalot/pdfparser library is not available.', [
            'driver' => 'smalot',
        ]);
    }

    if ($requestedNormalized !== 'smalot') {
        $requestedNormalized = 'smalot';
    }

    $extraction = pedimentos_extract_text_with_pdfparser($pdfPath, $pageFrom, $pageTo);
    $extraction['requested'] = $requestedNormalized;

    return $extraction;
}

/**
 * @return array{text: string, driver: string}
 *
 * @throws PedimentosTextExtractionException
 */
function pedimentos_extract_text_with_pdfparser(string $pdfPath, ?int $pageFrom, ?int $pageTo): array
{
    if (! pedimentos_pdfparser_available()) {
        throw new PedimentosTextExtractionException('smalot/pdfparser library is not available.', [
            'driver' => 'smalot',
        ]);
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $document = $parser->parseFile($pdfPath);
    } catch (Throwable $exception) {
        throw new PedimentosTextExtractionException('Failed to parse PDF using smalot/pdfparser.', [
            'driver' => 'smalot',
            'error' => $exception->getMessage(),
        ], 0, $exception);
    }

    $text = '';

    if (is_int($pageFrom) || is_int($pageTo)) {
        $pages = $document->getPages();
        $pageCount = count($pages);

        $start = is_int($pageFrom) && $pageFrom > 0 ? $pageFrom : 1;
        $end = is_int($pageTo) && $pageTo > 0 ? $pageTo : $pageCount;

        $start = max(1, $start);
        $end = max($start, min($pageCount, $end));

        $selection = array_slice($pages, $start - 1, $end - $start + 1);
        $chunks = [];

        foreach ($selection as $page) {
            $chunks[] = $page->getText();
        }

        $text = implode("\n", $chunks);
    } else {
        $text = $document->getText();
    }

    if (! is_string($text) || trim($text) === '') {
        throw new PedimentosTextExtractionException('The extracted text is empty.', [
            'driver' => 'smalot',
        ]);
    }

    return [
        'text' => $text,
        'driver' => 'smalot',
    ];
}

/**
 * Normalize and clamp page numbers used for pdftotext extraction.
 */
function pedimentos_normalize_page_number(?int $value): ?int
{
    if (! is_int($value)) {
        return null;
    }

    return $value > 0 ? $value : null;
}

/**
 * Build the base option list for pdftotext invocations.
 *
 * @return array<int, string>
 */
function pedimentos_build_pdftotext_options(?int $pageFrom, ?int $pageTo): array
{
    $options = ['-q', '-enc', 'UTF-8', '-layout', '-nopgbrk'];

    if ($pageFrom !== null) {
        $options[] = '-f';
        $options[] = (string) $pageFrom;
    }

    if ($pageTo !== null) {
        $options[] = '-l';
        $options[] = (string) $pageTo;
    }

    return $options;
}

/**
 * @return array{text: string, driver: string, binary: string, command: string}
 *
 * @throws PedimentosTextExtractionException
 */
function pedimentos_extract_text_with_pdftotext(string $pdfPath, ?int $pageFrom, ?int $pageTo, array $config): array
{
    if (! pedimentos_command_execution_available()) {
        throw new PedimentosTextExtractionException('The pdftotext driver is unavailable because PHP exec() is disabled.', [
            'driver' => 'pdftotext',
            'exec_available' => false,
        ]);
    }

    $preferred = pedimentos_normalize_config_string($config['pdftotext_path'] ?? null);
    $fallback = pedimentos_normalize_config_string($config['pdftotext_default'] ?? null);

    $binaryInfo = pedimentos_locate_pdftotext_binary(
        $preferred !== '' ? $preferred : null,
        $fallback !== '' ? $fallback : null
    );

    $binary = $binaryInfo['binary'];

    if (! is_string($binary) || trim($binary) === '') {
        throw new PedimentosTextExtractionException('The pdftotext binary could not be located.', [
            'driver' => 'pdftotext',
        ]);
    }

    $normalizedFrom = pedimentos_normalize_page_number($pageFrom);
    $normalizedTo = pedimentos_normalize_page_number($pageTo);

    if ($normalizedFrom !== null && $normalizedTo !== null && $normalizedTo < $normalizedFrom) {
        $normalizedTo = $normalizedFrom;
    }

    $options = pedimentos_build_pdftotext_options($normalizedFrom, $normalizedTo);
    $tmpDir = pedimentos_resolve_tmp_dir($config['tmp_dir'] ?? null);
    $tmpFile = @tempnam($tmpDir, 'pdftxt_');

    if (! is_string($tmpFile) || $tmpFile === false) {
        throw new PedimentosTextExtractionException('Failed to create a temporary file for pdftotext output.', [
            'driver' => 'pdftotext',
            'tmp_dir' => $tmpDir,
        ]);
    }

    $commandParts = array_merge([$binary], $options, [$pdfPath, $tmpFile]);
    $escapedParts = array_map(static fn($part) => escapeshellarg((string) $part), $commandParts);
    $command = implode(' ', $escapedParts);

    $output = [];
    $exitCode = 0;
    @exec($command . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        @unlink($tmpFile);

        throw new PedimentosTextExtractionException('Failed to extract text using pdftotext.', [
            'driver' => 'pdftotext',
            'binary' => $binary,
            'command' => $command,
            'output' => implode("\n", $output),
        ]);
    }

    $text = @file_get_contents($tmpFile);
    @unlink($tmpFile);

    if (! is_string($text) || trim($text) === '') {
        throw new PedimentosTextExtractionException('pdftotext returned an empty result.', [
            'driver' => 'pdftotext',
            'binary' => $binary,
            'command' => $command,
        ]);
    }

    $displayParts = array_merge([$binary], $options, ['-']);
    $displayCommand = trim(implode(' ', $displayParts));

    $details = ['binary' => $binary, 'command' => $displayCommand];

    if (is_string($binaryInfo['source'] ?? null) && $binaryInfo['source'] !== '') {
        $details['source'] = (string) $binaryInfo['source'];
    }

    return [
        'text' => $text,
        'driver' => 'pdftotext',
        'binary' => $binary,
        'command' => $displayCommand,
        'details' => $details,
    ];
}

/**
 * @param array<string, mixed> $extraction
 *
 * @return array{driver: ?string, details: array<string, string>}
 */
function pedimentos_normalize_text_driver_metadata(array $extraction): array
{
    $driver = $extraction['driver'] ?? null;
    $driver = is_string($driver) ? trim($driver) : '';

    if ($driver === '') {
        $driver = null;
    }

    $details = [];

    if ($driver !== null) {
        $details['driver'] = $driver;
    }

    $directKeys = ['requested', 'binary', 'command', 'source'];

    foreach ($directKeys as $key) {
        if (! isset($extraction[$key])) {
            continue;
        }

        $value = $extraction[$key];

        if (! is_string($value)) {
            continue;
        }

        $value = trim($value);

        if ($value === '') {
            continue;
        }

        $details[$key] = $value;
    }

    if (isset($extraction['details']) && is_array($extraction['details'])) {
        foreach ($extraction['details'] as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                $normalized = trim((string) $value);

                if ($normalized !== '') {
                    $details[$key] = $normalized;
                }
            }
        }
    }

    return [
        'driver' => $driver,
        'details' => $details,
    ];
}
