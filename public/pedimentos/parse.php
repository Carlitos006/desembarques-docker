<?php

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

session_start();

require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/files.php';
require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/pedimentos.php';

header('Content-Type: application/json; charset=utf-8');

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

/**
 * Determine the JSON encoding flags to use for responses.
 */
function pedimentos_json_flags(): int
{
    static $flags = null;

    if ($flags !== null) {
        return $flags;
    }

    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return $flags;
}

/**
 * @param array<string, mixed> $payload
 */
function pedimentos_output_json(array $payload, int $status = 200): void
{
    http_response_code($status);

    $flags = pedimentos_json_flags();
    $json = json_encode($payload, $flags);

    if ($json === false) {
        $fallback = ['ok' => false, 'error' => 'Failed to encode JSON response.'];
        http_response_code(500);

        $fallbackFlags = $flags;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $fallbackFlags &= ~JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json = json_encode($fallback, $fallbackFlags);

        if ($json === false) {
            $json = '{"ok":false,"error":"Failed to encode JSON response."}';
        }
    }

    echo $json;
    exit;
}

/**
 * @param array<string, mixed> $data
 */
function pedimentos_json_ok(array $data = []): void
{
    pedimentos_output_json(['ok' => true] + $data, 200);
}

/**
 * @param array<string, mixed> $data
 */
function pedimentos_json_error(string $message, array $data = [], int $status = 400): void
{
    pedimentos_output_json(['ok' => false, 'error' => $message] + $data, $status);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pedimentos_json_error(translateText('Método no permitido.', 'Method not allowed.'), [], 405);
}

if (! isset($_SESSION['user'])) {
    pedimentos_json_error(translateText('No autorizado.', 'Unauthorized.'), [], 401);
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (! validate_csrf_token(is_string($csrfToken) ? $csrfToken : null)) {
    pedimentos_json_error(translateText('El token de seguridad no es válido.', 'Invalid security token.'), [], 419);
}

if (empty($_FILES['pdf']['tmp_name']) || ! is_uploaded_file((string) ($_FILES['pdf']['tmp_name'] ?? ''))) {
    pedimentos_json_error(translateText('No se recibió ningún archivo PDF.', 'No PDF file received.'), ['_files' => $_FILES]);
}

$pdfTmpName = (string) ($_FILES['pdf']['tmp_name'] ?? '');
$pdfRealPath = realpath($pdfTmpName) ?: $pdfTmpName;
$pdfMimeType = detect_uploaded_mime_type($pdfTmpName);

if (stripos($pdfMimeType, 'pdf') === false) {
    pedimentos_json_error(translateText('El archivo cargado no es un PDF.', 'The uploaded file is not a PDF.'), ['mime' => $pdfMimeType]);
}

$config = pedimentos_parser_config();
$pageFrom = $config['page_from'];
$pageTo = $config['page_to'];
$extraction = [];

try {
    $extraction = pedimentos_extract_text_from_pdf($pdfRealPath, $pageFrom, $pageTo, $config);
    $text = $extraction['text'];
} catch (PedimentosTextExtractionException $exception) {
    pedimentos_json_error($exception->getMessage(), $exception->getContext());
} catch (Throwable $exception) {
    pedimentos_json_error(translateText('Ocurrió un error inesperado al extraer el texto del PDF.', 'Unexpected error while extracting text from PDF.'), [
        'error' => $exception->getMessage(),
    ]);
}

$textDriverMetadata = pedimentos_normalize_text_driver_metadata($extraction ?? []);
$textDriver = $textDriverMetadata['driver'];
$textDriverDetails = $textDriverMetadata['details'];

/**
 * Case-insensitive version of str_contains.
 */
function pedimentos_str_contains_ci(string $haystack, string $needle): bool
{
    return stripos($haystack, $needle) !== false;
}

/**
 * @return array{0: string, 1: string}
 */
function pedimentos_split_header_and_body(string $text): array
{
    $candidates = [
        '/\\bMARCA\\s+MODELO\\s+C[ÓO]DIGO\\s+PRODUCTO\\b/i',
        '/\\bMARCA\\b.*\\bMODELO\\b.*\\bC[ÓO]DIGO\\b.*\\bPRODUCTO\\b/i',
    ];

    foreach ($candidates as $pattern) {
        if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = (int) ($matches[0][1] ?? 0);
            if ($offset > 0) {
                return [substr($text, 0, $offset), substr($text, $offset)];
            }

            return ['', $text];
        }
    }

    return [$text, $text];
}

function pedimentos_cut_from_header(string $text): string
{
    $sections = pedimentos_split_header_and_body($text);

    return $sections[1];
}

function pedimentos_clean_header_capture(string $value): string
{
    $cleaned = trim(preg_replace('/\s{2,}/u', ' ', $value) ?? '');
    $cleaned = preg_replace('/\s*(?:CVE\.?\s+PEDIMENTO|RAZ[ÓO]N\s+SOCIAL|FECHA\s+DE\s+ENTRADA|FECHA\s+DE\s+PAGO)\b.*$/iu', '', $cleaned);
    if (! is_string($cleaned)) {
        $cleaned = '';
    }

    return trim($cleaned);
}

/**
 * @return array{num_pedimento: ?string, cve_pedimento: ?string, razon_social: ?string, fecha_entrada: ?string, fecha_pago: ?string}
 */
function pedimentos_extract_header_fields(string $text): array
{
    $result = [
        'num_pedimento' => null,
        'cve_pedimento' => null,
        'razon_social' => null,
        'fecha_entrada' => null,
        'fecha_pago' => null,
    ];

    $patterns = [
        'num_pedimento' => '/NUM\.?\s+PEDIMENTO\s*[:\-]?\s*([^\r\n]+)/iu',
        'cve_pedimento' => '/CVE\.?\s+PEDIMENTO\s*[:\-]?\s*([^\r\n]+)/iu',
        'razon_social' => '/RAZ[ÓO]N\s+SOCIAL\s*[:\-]?\s*([^\r\n]+)/iu',
        'fecha_entrada' => '/FECHA\s+DE\s+ENTRADA\s*[:\-]?\s*([^\r\n]+)/iu',
        'fecha_pago' => '/FECHA\s+DE\s+PAGO\s*[:\-]?\s*([^\r\n]+)/iu',
    ];

    foreach ($patterns as $field => $pattern) {
        if (! preg_match($pattern, $text, $matches)) {
            continue;
        }

        $rawValue = isset($matches[1]) ? (string) $matches[1] : '';
        $cleanValue = pedimentos_clean_header_capture($rawValue);

        if ($cleanValue === '') {
            continue;
        }

        if ($field === 'fecha_entrada' || $field === 'fecha_pago') {
            if (preg_match('/(\d{1,2}[\/\.\-]\d{1,2}[\/\.\-]\d{2,4})/', $cleanValue, $dateMatch)) {
                $cleanValue = $dateMatch[1];
            }
        }

        $result[$field] = $cleanValue;
    }

    return $result;
}

function pedimentos_split_blocks(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $parts = preg_split('/(?=^\s*\d{8}\s+\d{2}\b)/m', $text);
    if ($parts === false) {
        return [];
    }

    return array_values(array_filter($parts, static function ($part) {
        return is_string($part) && preg_match('/^\s*\d{8}\s+\d{2}\b/m', $part);
    }));
}

function pedimentos_first_number_here_or_next(array $lines, int $index, int $lookahead = 3): ?string
{
    if (isset($lines[$index]) && preg_match('/\b\d{1,3}(?:[.,]\d{3})*(?:\.\d+)?\b/u', $lines[$index], $match)) {
        return str_replace(',', '', $match[0]);
    }

    for ($offset = 1; $offset <= $lookahead; $offset++) {
        $nextIndex = $index + $offset;
        if (! isset($lines[$nextIndex])) {
            break;
        }

        if (preg_match('/\b\d{1,3}(?:[.,]\d{3})*(?:\.\d+)?\b/u', $lines[$nextIndex], $match)) {
            return str_replace(',', '', $match[0]);
        }
    }

    return null;
}

function pedimentos_clean_description(string $line, ?string &$secReference = null): string
{
    $value = trim($line);

    if (preg_match('/^(\d{1,3})\s+(.*)$/u', $value, $matches)) {
        if ($secReference === null) {
            $secReference = $matches[1];
        }
        $value = $matches[2];
    }

    $value = preg_split('/\b(IGI|IVA)\b/u', $value)[0];
    $value = preg_replace('/\s+\d+(?:\.\d+)?(?:\s+\d+){0,6}\s*$/u', '', $value);
    $value = trim(preg_replace('/\s{2,}/', ' ', $value));

    return $value;
}

/**
 * @return array<string, mixed>
 */
function pedimentos_parse_block(string $block): array
{
    $result = [
        'sec' => null,
        'descripcion' => null,
        'fraccion8' => null,
        'fraccion2' => null,
        'modelo_cols' => null,
        'umc_inicial' => null,
        'umc_cant' => null,
        'umc_unidad' => null,
        'umt_pu' => null,
        'umt_unidad' => null,
        'pais' => null,
        'marca' => null,
        'igi_tasa' => null,
        'iva_tasa' => null,
        'id_1' => null,
        'id_2' => null,
        'valor_ref' => null,
        'identif' => null,
        'comp1' => null,
        'comp2' => null,
        'comp3' => null,
    ];

    $lines = array_values(array_filter(array_map(static function ($line) {
        if (! is_string($line)) {
            return '';
        }
        $normalized = preg_replace('/[ \t]+/', ' ', trim($line));
        return is_string($normalized) ? $normalized : '';
    }, preg_split("/\n+/", $block) ?: []), static function ($line) {
        return $line !== '';
    }));

    if ($lines === []) {
        return $result;
    }

    if (preg_match('/^(\d{8})\s+(\d{2})\s+(\d)\s+(\d)\s+(\d)\s+(\d+(?:\.\d+)?)\s+(\d)\s+(\d+(?:\.\d+)?)\s+([A-Z]{3})\s+([A-Z]{2,4})(.*)$/', $lines[0], $matches)) {
        $result['fraccion8'] = $matches[1];
        $result['fraccion2'] = $matches[2];
        $result['modelo_cols'] = $matches[3] . ' ' . $matches[4] . ' ' . $matches[5];
        $result['umc_inicial'] = $matches[5];
        $result['umc_cant'] = $matches[6];
        $result['umc_unidad'] = $matches[7];
        $result['umt_pu'] = trim((string) ($matches[8] ?? ''));
        $result['pais'] = $matches[9];
        $result['marca'] = $matches[10];

        $tail = strtoupper((string) ($matches[11] ?? ''));
        if (pedimentos_str_contains_ci($tail, 'IGI') && preg_match('/IGI[^0-9]*([\d\.]+)/i', $tail, $igiMatch)) {
            $result['igi_tasa'] = $igiMatch[1];
        }
        if (pedimentos_str_contains_ci($tail, 'IVA') && preg_match('/IVA[^0-9]*([\d\.]+)/i', $tail, $ivaMatch)) {
            $result['iva_tasa'] = $ivaMatch[1];
        }
    }

    foreach ($lines as $index => $line) {
        if (preg_match('/^[A-ZÁÉÍÓÚÑ0-9][A-ZÁÉÍÓÚÑ0-9 ,\.\-\/\"]+$/u', $line) && ! preg_match('/^\d+\s+\d+\s+\d+(?:\.\d+)?$/', $line)) {
            $description = pedimentos_clean_description($line, $result['sec']);
            $result['descripcion'] = $description;

            if ($result['sec'] === null && $index > 0 && preg_match('/^\d{1,3}$/', $lines[$index - 1])) {
                $result['sec'] = $lines[$index - 1];
            } elseif ($result['sec'] === null && isset($lines[$index + 1]) && preg_match('/^\d{1,3}$/', $lines[$index + 1])) {
                $result['sec'] = $lines[$index + 1];
            }
            break;
        }
    }

    foreach ($lines as $line) {
        if (preg_match('/^(\d+)\s+(\d+)\s+(\d+(?:\.\d+)?)/', $line, $matches)) {
            $result['id_1'] = $matches[1];
            $result['id_2'] = $matches[2];
            $result['valor_ref'] = $matches[3];
            break;
        }
    }

    foreach ($lines as $line) {
        if ($result['identif'] === null && preg_match('/^IDENTIF\.\s+([A-Z0-9\-]+)/i', $line, $matches)) {
            $result['identif'] = $matches[1];
        }
        if ($result['comp1'] === null && preg_match('/^COMPLEMENTO\s*1\s+([A-Z0-9\-]+)/i', $line, $matches)) {
            $result['comp1'] = $matches[1];
        }
        if ($result['comp2'] === null && preg_match('/^COMPLEMENTO\s*2\s+([A-Z0-9\-]+)/i', $line, $matches)) {
            $result['comp2'] = $matches[1];
        }
        if ($result['comp3'] === null && preg_match('/^COMPLEMENTO\s*3\s+([A-Z0-9\-]+)/i', $line, $matches)) {
            $result['comp3'] = $matches[1];
        }
    }

    foreach ($lines as $index => $line) {
        $upper = strtoupper($line);
        if ($result['igi_tasa'] === null && pedimentos_str_contains_ci($upper, 'IGI')) {
            $number = pedimentos_first_number_here_or_next($lines, $index, 3);
            if ($number !== null) {
                $result['igi_tasa'] = $number;
            }
        }
        if ($result['iva_tasa'] === null && pedimentos_str_contains_ci($upper, 'IVA')) {
            $number = pedimentos_first_number_here_or_next($lines, $index, 3);
            if ($number !== null) {
                $result['iva_tasa'] = $number;
            }
        }
        if ($result['igi_tasa'] !== null && $result['iva_tasa'] !== null) {
            break;
        }
    }

    foreach ($lines as $line) {
        if ($result['umt_unidad'] === null && preg_match('/\bUMT\s+(\d+)\b/i', $line, $matches)) {
            $result['umt_unidad'] = $matches[1];
            break;
        }
    }

    return $result;
}

$headerSegments = pedimentos_split_header_and_body($text);
$headerSection = $headerSegments[0];
$textFromHeader = $headerSegments[1];
$headerFound = (bool) preg_match('/\bMARCA\b.*\bMODELO\b.*\bC[ÓO]DIGO\b.*\bPRODUCTO\b/i', $text);
$headerData = pedimentos_extract_header_fields($headerSection);
$blocks = pedimentos_split_blocks($textFromHeader);

$items = [];
foreach ($blocks as $block) {
    $parsed = pedimentos_parse_block($block);
    if (($parsed['fraccion8'] ?? null) && ($parsed['descripcion'] ?? null)) {
        $items[] = $parsed;
    }
}

$secFilter = [];
if (! empty($_POST['sec_filter'])) {
    $secFilter = array_filter(array_map('intval', explode(',', (string) $_POST['sec_filter'])), static function ($value) {
        return $value > 0;
    });
}

if ($secFilter !== []) {
    $items = array_values(array_filter($items, static function ($item) use ($secFilter) {
        $sec = $item['sec'] ?? null;
        if ($sec === null) {
            return false;
        }

        return in_array((int) $sec, $secFilter, true);
    }));
}

pedimentos_json_ok([
    'count' => count($items),
    'items' => $items,
    'header' => $headerData,
    'text_driver' => $textDriver,
    'text_driver_details' => $textDriverDetails,
    'debug' => [
        'text_driver' => $textDriver,
        'text_driver_details' => $textDriverDetails,
        'header_found' => $headerFound,
        'header_excerpt' => substr($headerSection, 0, 600),
        'header_values' => $headerData,
        'preview' => substr($textFromHeader, 0, 1200),
    ],
]);
