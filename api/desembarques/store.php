<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/csrf.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

if (! isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/files.php';


function respondWithError(int $statusCode, string $message, array $errors = []): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'errors' => $errors,
    ]);
    exit;
}

function cleanString(?string $value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);
    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

function parseDate(string $value, string $field, array &$errors, string $language, bool $required = true): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        if ($required) {
            $errors[$field] = translate('validation.field_required', [], $language);
        }
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (! $date || $date->format('Y-m-d') !== $value) {
        $errors[$field] = translate('validation.invalid_date', [], $language);
        return null;
    }

    return $date;
}

/**
 * @param mixed $input
 * @param array<string, string> $errors
 * @return list<string>
 */
function normalizeReferenceInput($input, string $field, string $errorKey, array &$errors, string $language, int $maxLength = 191): array
{
    if ($input === null) {
        return [];
    }

    if (! is_array($input)) {
        $input = [$input];
    }

    $normalized = [];

    foreach ($input as $value) {
        if (is_array($value) || is_object($value)) {
            $errors[$field] = translate($errorKey, [], $language);

            return [];
        }

        $reference = cleanString((string) $value, $maxLength);

        if ($reference === '') {
            continue;
        }

        $normalized[] = $reference;
    }

    if ($normalized === []) {
        return [];
    }

    $unique = [];
    $seen = [];

    foreach ($normalized as $reference) {
        $key = mb_strtolower($reference);

        if (isset($seen[$key])) {
            continue;
        }

        $unique[] = $reference;
        $seen[$key] = true;
    }

    return $unique;
}

/**
 * @param list<string> $references
 */
function insertDesembarqueReferences(mysqli $connection, string $table, int $desembarqueId, array $references): void
{
    if ($references === []) {
        return;
    }

    $allowedTables = [
        'desembarque_pedimentos',
        'desembarque_manifests',
        'desembarque_cipls',
    ];

    if (! in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Invalid reference table.');
    }

    $query = sprintf('INSERT INTO %s (desembarque_id, reference) VALUES (?, ?)', $table);
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException('Unable to prepare the reference insert statement.');
    }

    $referenceValue = '';
    $statement->bind_param('is', $desembarqueId, $referenceValue);

    foreach ($references as $reference) {
        $referenceValue = $reference;
        $statement->execute();
    }

    $statement->close();
}

function is_list_array(array $array): bool
{
    if ($array === []) {
        return true;
    }

    $expectedKey = 0;

    foreach ($array as $key => $_) {
        if ($key !== $expectedKey) {
            return false;
        }

        $expectedKey++;
    }

    return true;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function sanitizePedimentoHeader(array $data): array
{
    $fields = [
        'num_pedimento' => 100,
        'cve_pedimento' => 100,
        'razon_social' => 255,
        'fecha_entrada' => 50,
        'fecha_pago' => 50,
    ];

    $sanitized = [];

    foreach ($fields as $field => $length) {
        if (! array_key_exists($field, $data)) {
            continue;
        }

        $value = cleanString(isset($data[$field]) ? (string) $data[$field] : '', $length);

        if ($value === '') {
            continue;
        }

        $sanitized[$field] = $value;
    }

    return $sanitized;
}

/**
 * @param array<string, mixed> $entry
 * @param array<string, string> $errors
 * @return array{header: array<string, string>, pedimentos: list<string>}|null
 */
function sanitizePedimentoPackageEntry(array $entry, array &$errors, string $language): ?array
{
    $header = [];

    if (isset($entry['header']) && is_array($entry['header'])) {
        $header = sanitizePedimentoHeader($entry['header']);
    }

    $pedimentos = [];

    if (array_key_exists('pedimentos', $entry)) {
        $pedimentos = normalizeReferenceInput(
            $entry['pedimentos'],
            'pedimentos_packages',
            'desembarques.validation.pedimentos_packages_invalid',
            $errors,
            $language
        );

        if (isset($errors['pedimentos_packages'])) {
            return null;
        }
    }

    if ($header === [] && $pedimentos === []) {
        return null;
    }

    return [
        'header' => $header,
        'pedimentos' => $pedimentos,
    ];
}

/**
 * @return list<array<string, string>>
 */
function parseLegacyPedimentoHeaders(?string $legacyJson): array
{
    $raw = trim((string) $legacyJson);

    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
        return [];
    }

    if (is_list_array($decoded)) {
        $headers = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $header = sanitizePedimentoHeader($entry);

            if ($header !== []) {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    $header = sanitizePedimentoHeader($decoded);

    return $header === [] ? [] : [$header];
}

/**
 * @param list<string> $fallbackPedimentos
 * @param array<string, string> $errors
 * @return list<array{header: array<string, string>, pedimentos: list<string>}>
 */
function parsePedimentoPackages(
    ?string $packagesJson,
    ?string $legacyHeadersJson,
    array $fallbackPedimentos,
    array &$errors,
    string $language
): array {
    $packages = [];
    $rawPackages = trim((string) $packagesJson);

    if ($rawPackages !== '') {
        $decoded = json_decode($rawPackages, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || ! is_list_array($decoded)) {
            $errors['pedimentos_packages'] = translate('desembarques.validation.pedimentos_packages_invalid', [], $language);

            return [];
        }

        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $package = sanitizePedimentoPackageEntry($entry, $errors, $language);

            if ($package !== null) {
                $packages[] = $package;
            }

            if (isset($errors['pedimentos_packages'])) {
                return [];
            }
        }
    }

    if ($packages === []) {
        $legacyHeaders = parseLegacyPedimentoHeaders($legacyHeadersJson);

        if ($legacyHeaders !== []) {
            $firstHeader = $legacyHeaders[0];

            if ($firstHeader !== []) {
                $packages[] = [
                    'header' => $firstHeader,
                    'pedimentos' => $fallbackPedimentos,
                ];
            }
        }
    }

    return $packages;
}

/**
 * @param list<string> $pedimentos
 */
function insertPedimentoHeaderLinks(mysqli $connection, int $headerId, array $pedimentos): void
{
    if ($pedimentos === []) {
        return;
    }

    $statement = $connection->prepare('INSERT INTO desembarque_pedimento_header_links (header_id, reference) VALUES (?, ?)');

    if (! $statement) {
        throw new RuntimeException('Unable to prepare the pedimento header link insert statement.');
    }

    $referenceValue = '';
    $statement->bind_param('is', $headerId, $referenceValue);

    foreach ($pedimentos as $reference) {
        $referenceValue = $reference;
        $statement->execute();
    }

    $statement->close();
}

/**
 * @param list<array{header: array<string, string>, pedimentos: list<string>}> $packages
 * @return list<array{id: int, num_pedimento: ?string, cve_pedimento: ?string, razon_social: ?string, fecha_entrada: ?string, fecha_pago: ?string, pedimentos: list<string>}>
 */
function insertDesembarquePedimentoHeaders(mysqli $connection, int $desembarqueId, array $packages): array
{
    $entriesWithHeader = [];

    foreach ($packages as $package) {
        if (isset($package['header']) && is_array($package['header']) && $package['header'] !== []) {
            $entriesWithHeader[] = $package;
        }
    }

    if ($entriesWithHeader === []) {
        return [];
    }

    $query = 'INSERT INTO desembarque_pedimento_headers (desembarque_id, num_pedimento, cve_pedimento, razon_social, fecha_entrada, fecha_pago) VALUES (?, ?, ?, ?, ?, ?)';
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException('Unable to prepare the pedimento header insert statement.');
    }

    $numPedimento = null;
    $cvePedimento = null;
    $razonSocial = null;
    $fechaEntrada = null;
    $fechaPago = null;

    $statement->bind_param(
        'isssss',
        $desembarqueId,
        $numPedimento,
        $cvePedimento,
        $razonSocial,
        $fechaEntrada,
        $fechaPago
    );

    $inserted = [];

    foreach ($entriesWithHeader as $package) {
        $header = $package['header'];
        $numPedimento = $header['num_pedimento'] ?? null;
        $cvePedimento = $header['cve_pedimento'] ?? null;
        $razonSocial = $header['razon_social'] ?? null;
        $fechaEntrada = $header['fecha_entrada'] ?? null;
        $fechaPago = $header['fecha_pago'] ?? null;

        $statement->execute();

        $headerId = (int) $connection->insert_id;

        insertPedimentoHeaderLinks($connection, $headerId, $package['pedimentos']);

        $inserted[] = [
            'id' => $headerId,
            'num_pedimento' => $numPedimento,
            'cve_pedimento' => $cvePedimento,
            'razon_social' => $razonSocial,
            'fecha_entrada' => $fechaEntrada,
            'fecha_pago' => $fechaPago,
            'pedimentos' => $package['pedimentos'],
        ];
    }

    $statement->close();

    return $inserted;
}


/**
 * @param array<string, string> $errors
 * @return array{details: array<string, ?string>, items: list<array<string,mixed>>, pedimentos: list<string>, packages: list<array{header:array<string,string>,pedimentos:list<string>}>}|null
 */
function parseExcelFirstPayload(?string $json, array &$errors, string $language): ?array
{
    $raw = trim((string) $json);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
        $errors['source_excel'] = $language === 'en'
            ? 'The Excel data could not be validated.'
            : 'No fue posible validar la información del Excel.';
        return null;
    }

    $detailsInput = isset($decoded['details']) && is_array($decoded['details']) ? $decoded['details'] : [];
    $itemsInput = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : [];

    $cleanDateTime = static function (mixed $value): ?string {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $text);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $text) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        return null;
    };

    $details = [
        'manifiesto' => cleanString(isset($detailsInput['manifiesto']) ? (string) $detailsInput['manifiesto'] : '', 100),
        'medio_transporte' => cleanString(isset($detailsInput['medio_transporte']) ? (string) $detailsInput['medio_transporte'] : '', 255),
        'imo_transporte' => cleanString(isset($detailsInput['imo_transporte']) ? (string) $detailsInput['imo_transporte'] : '', 40),
        'consignataria' => cleanString(isset($detailsInput['consignataria']) ? (string) $detailsInput['consignataria'] : '', 255),
        'fecha_embarque' => $cleanDateTime($detailsInput['fecha_embarque'] ?? null),
        'lugar_desembarque' => cleanString(isset($detailsInput['lugar_desembarque']) ? (string) $detailsInput['lugar_desembarque'] : '', 5000),
        'fecha_desembarque_eta' => $cleanDateTime($detailsInput['fecha_desembarque_eta'] ?? null),
        'domicilio_almacenamiento' => cleanString(isset($detailsInput['domicilio_almacenamiento']) ? (string) $detailsInput['domicilio_almacenamiento'] : '', 5000),
        'domicilio_reparacion' => cleanString(isset($detailsInput['domicilio_reparacion']) ? (string) $detailsInput['domicilio_reparacion'] : '', 5000),
        'source_excel_name' => cleanString(isset($detailsInput['source_excel_name']) ? (string) $detailsInput['source_excel_name'] : '', 255),
        'source_excel_sha256' => cleanString(isset($detailsInput['source_excel_sha256']) ? (string) $detailsInput['source_excel_sha256'] : '', 64),
    ];

    if ($details['manifiesto'] === '') {
        $errors['source_excel'] = $language === 'en' ? 'The Excel does not contain a manifest.' : 'El Excel no contiene manifiesto.';
    } elseif ($details['medio_transporte'] === '') {
        $errors['source_excel'] = $language === 'en' ? 'The Excel does not contain a transport medium.' : 'El Excel no contiene medio de transporte.';
    } elseif ($details['fecha_desembarque_eta'] === null) {
        $errors['source_excel'] = $language === 'en' ? 'The Excel file does not contain a valid unloading date.' : 'El Excel no contiene una fecha de desembarque válida.';
    }

    if ($details['source_excel_sha256'] !== '' && ! preg_match('/^[a-f0-9]{64}$/i', $details['source_excel_sha256'])) {
        $details['source_excel_sha256'] = '';
    }

    if ($itemsInput === [] || count($itemsInput) > 500) {
        $errors['source_excel'] = $language === 'en'
            ? 'The Excel must contain between 1 and 500 merchandise rows.'
            : 'El Excel debe contener entre 1 y 500 renglones de mercancía.';
        return null;
    }

    $items = [];
    $groups = [];
    $pedimentos = [];

    foreach ($itemsInput as $index => $itemInput) {
        if (! is_array($itemInput)) {
            continue;
        }

        $description = cleanString(isset($itemInput['descripcion']) ? (string) $itemInput['descripcion'] : '', 15000);
        if ($description === '') {
            continue;
        }

        $clave = strtoupper((string) preg_replace('/\s+/', '', cleanString(isset($itemInput['clave']) ? (string) $itemInput['clave'] : '', 30)));
        $numPedimento = cleanString(isset($itemInput['num_pedimento']) ? (string) $itemInput['num_pedimento'] : '', 120);
        $importerName = cleanString(isset($itemInput['importer_name']) ? (string) $itemInput['importer_name'] : '', 255);
        $quantity = null;
        if (isset($itemInput['cantidad']) && $itemInput['cantidad'] !== '' && is_numeric($itemInput['cantidad'])) {
            $quantity = round((float) $itemInput['cantidad'], 3);
        }

        $sourceRow = isset($itemInput['source_row']) && is_numeric($itemInput['source_row'])
            ? max(1, (int) $itemInput['source_row'])
            : null;

        $items[] = [
            'source_row' => $sourceRow,
            'sort_order' => count($items) + 1,
            'item_no' => cleanString(isset($itemInput['item_no']) ? (string) $itemInput['item_no'] : '', 50),
            'unidad' => cleanString(isset($itemInput['unidad']) ? (string) $itemInput['unidad'] : '', 40),
            'descripcion' => $description,
            'serial_number' => cleanString(isset($itemInput['serial_number']) ? (string) $itemInput['serial_number'] : '', 5000),
            'marca' => cleanString(isset($itemInput['marca']) ? (string) $itemInput['marca'] : '', 180),
            'clave' => $clave,
            'num_pedimento' => $numPedimento,
            'partida' => cleanString(isset($itemInput['partida']) ? (string) $itemInput['partida'] : '', 50),
            'cantidad' => $quantity,
            'importer_name' => $importerName,
        ];

        if ($numPedimento !== '') {
            $normalizedNumber = strtolower((string) preg_replace('/[^0-9a-z]+/i', '', $numPedimento));
            $groupKey = $clave . '|' . $normalizedNumber;
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'header' => [
                        'num_pedimento' => $numPedimento,
                        'cve_pedimento' => $clave,
                        'razon_social' => $importerName,
                    ],
                    'pedimentos' => [$numPedimento],
                ];
            } elseif (($groups[$groupKey]['header']['razon_social'] ?? '') === '' && $importerName !== '') {
                $groups[$groupKey]['header']['razon_social'] = $importerName;
            }

            if (! in_array($numPedimento, $pedimentos, true)) {
                $pedimentos[] = $numPedimento;
            }
        }
    }

    if ($items === []) {
        $errors['source_excel'] = $language === 'en' ? 'No valid merchandise rows were found.' : 'No se encontraron mercancías válidas en el Excel.';
        return null;
    }

    foreach ($groups as $group) {
        if (trim((string) ($group['header']['razon_social'] ?? '')) === '') {
            $errors['source_excel_importers'] = $language === 'en'
                ? 'Complete the importer’s legal business name for each customs entry.'
                : 'Completa la razón social del importador para cada pedimento.';
            break;
        }
    }

    return [
        'details' => $details,
        'items' => $items,
        'pedimentos' => $pedimentos,
        'packages' => array_values($groups),
    ];
}

function ensureExcelFirstSchema(mysqli $connection): void
{
    $requirements = [
        'desembarque_aviso_details' => ['manifiesto', 'medio_transporte', 'fecha_desembarque_eta', 'source_excel_sha256'],
        'desembarque_aviso_items' => ['descripcion', 'num_pedimento', 'importer_name'],
    ];

    foreach ($requirements as $table => $columns) {
        $result = $connection->query('SHOW COLUMNS FROM `' . $table . '`');
        if (! $result instanceof mysqli_result) {
            throw new RuntimeException('Falta la migración Excel-first del módulo de desembarques.');
        }

        $available = [];
        while ($row = $result->fetch_assoc()) {
            $available[(string) ($row['Field'] ?? '')] = true;
        }
        $result->free();

        foreach ($columns as $column) {
            if (! isset($available[$column])) {
                throw new RuntimeException('Falta la migración Excel-first: columna ' . $table . '.' . $column . '.');
            }
        }
    }
}

/**
 * @param array{details:array<string,?string>,items:list<array<string,mixed>>} $excelPayload
 */
function persistExcelFirstAviso(mysqli $connection, int $desembarqueId, int $actorId, array $excelPayload): void
{
    $details = $excelPayload['details'];

    $sql = <<<'SQL'
INSERT INTO desembarque_aviso_details (
    desembarque_id, manifiesto, medio_transporte, imo_transporte, consignataria,
    fecha_embarque, lugar_desembarque, fecha_desembarque_eta, domicilio_almacenamiento,
    domicilio_reparacion, source_excel_name, source_excel_sha256, imported_by
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
ON DUPLICATE KEY UPDATE
    manifiesto = VALUES(manifiesto), medio_transporte = VALUES(medio_transporte),
    imo_transporte = VALUES(imo_transporte), consignataria = VALUES(consignataria),
    fecha_embarque = VALUES(fecha_embarque), lugar_desembarque = VALUES(lugar_desembarque),
    fecha_desembarque_eta = VALUES(fecha_desembarque_eta),
    domicilio_almacenamiento = VALUES(domicilio_almacenamiento),
    domicilio_reparacion = VALUES(domicilio_reparacion), source_excel_name = VALUES(source_excel_name),
    source_excel_sha256 = VALUES(source_excel_sha256), imported_by = VALUES(imported_by)
SQL;

    $connection->execute_query($sql, [
        $desembarqueId,
        $details['manifiesto'] ?: null,
        $details['medio_transporte'] ?: null,
        $details['imo_transporte'] ?: null,
        $details['consignataria'] ?: null,
        $details['fecha_embarque'],
        $details['lugar_desembarque'] ?: null,
        $details['fecha_desembarque_eta'],
        $details['domicilio_almacenamiento'] ?: null,
        $details['domicilio_reparacion'] ?: null,
        $details['source_excel_name'] ?: null,
        $details['source_excel_sha256'] ?: null,
        $actorId,
    ]);

    $connection->execute_query('DELETE FROM desembarque_aviso_items WHERE desembarque_id = ?', [$desembarqueId]);
    $itemSql = 'INSERT INTO desembarque_aviso_items '
        . '(desembarque_id, source_row, sort_order, item_no, unidad, descripcion, serial_number, marca, clave, num_pedimento, partida, cantidad, importer_name) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';

    foreach ($excelPayload['items'] as $item) {
        $connection->execute_query($itemSql, [
            $desembarqueId,
            $item['source_row'],
            $item['sort_order'],
            $item['item_no'] !== '' ? $item['item_no'] : null,
            $item['unidad'] !== '' ? $item['unidad'] : null,
            $item['descripcion'],
            $item['serial_number'] !== '' ? $item['serial_number'] : null,
            $item['marca'] !== '' ? $item['marca'] : null,
            $item['clave'] !== '' ? $item['clave'] : null,
            $item['num_pedimento'] !== '' ? $item['num_pedimento'] : null,
            $item['partida'] !== '' ? $item['partida'] : null,
            $item['cantidad'],
            $item['importer_name'] !== '' ? $item['importer_name'] : null,
        ]);
    }
}

$userRole = (string) ($_SESSION['user']['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    respondWithError(403, translate('desembarques.permission_denied', [], $currentLanguage));
}

$csrfTokenValue = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

if (! validate_csrf_token($csrfTokenValue)) {
    respondWithError(419, translate('common.csrf_token_invalid', [], $currentLanguage));
}

$errors = [];
$excelFirst = ((string) ($_POST['excel_first'] ?? '')) === '1';
$excelPayload = $excelFirst
    ? parseExcelFirstPayload(isset($_POST['aviso_excel_json']) ? (string) $_POST['aviso_excel_json'] : null, $errors, $currentLanguage)
    : null;

if ($excelFirst && $excelPayload === null && ! isset($errors['source_excel'])) {
    $errors['source_excel'] = $currentLanguage === 'en'
        ? 'Read and validate the Excel file before registering the unloading operation.'
        : 'Lee y valida el Excel antes de registrar el desembarque.';
}

$referencia = cleanString($_POST['referencia'] ?? '', 100);
$folioAviso = cleanString($_POST['folio_aviso'] ?? '', 100);
$pedimento = cleanString($_POST['pedimento'] ?? '', 100);
$cipl = cleanString($_POST['cipl'] ?? '', 100);
$manifiesto = cleanString($_POST['manifiesto'] ?? '', 100);
$descripcion = cleanString($_POST['descripcion'] ?? '', 1000);
$destino = cleanString($_POST['destino'] ?? '', 150);
$barco = cleanString($_POST['barco'] ?? '', 150);
$cliente = cleanString($_POST['cliente'] ?? '', 150);
$clienteIdInput = trim((string) ($_POST['cliente_id'] ?? ''));
$clienteId = null;
$statusIdInput = trim((string) ($_POST['status_id'] ?? ''));
$statusId = null;
$fechaDesembarqueInput = (string) ($_POST['fecha_desembarque'] ?? '');
$fechaEmbarqueInput = (string) ($_POST['fecha_embarque'] ?? '');
$pedimentos = normalizeReferenceInput(
    $_POST['pedimentos'] ?? null,
    'pedimentos',
    'desembarques.validation.pedimentos_invalid',
    $errors,
    $currentLanguage
);
$manifests = normalizeReferenceInput(
    $_POST['manifests'] ?? null,
    'manifests',
    'desembarques.validation.manifests_invalid',
    $errors,
    $currentLanguage
);
$cipls = normalizeReferenceInput(
    $_POST['cipls'] ?? null,
    'cipls',
    'desembarques.validation.cipls_invalid',
    $errors,
    $currentLanguage
);
$pedimentoPackagesJson = isset($_POST['pedimentos_packages_json']) ? (string) $_POST['pedimentos_packages_json'] : null;
$legacyPedimentoHeadersJson = isset($_POST['pedimentos_header_json']) ? (string) $_POST['pedimentos_header_json'] : null;
$pedimentoPackages = parsePedimentoPackages(
    $pedimentoPackagesJson,
    $legacyPedimentoHeadersJson,
    $pedimentos,
    $errors,
    $currentLanguage
);

if ($excelFirst && $excelPayload !== null) {
    $excelDetails = $excelPayload['details'];
    $manifiesto = (string) ($excelDetails['manifiesto'] ?? '');
    $folioAviso = $manifiesto !== '' ? 'MADE-' . $manifiesto : $folioAviso;
    $pedimentos = $excelPayload['pedimentos'];
    $manifests = $manifiesto !== '' ? [$manifiesto] : [];
    $pedimentoPackages = $excelPayload['packages'];
    $pedimento = $pedimentos[0] ?? null;
    $fechaDesembarqueInput = substr((string) ($excelDetails['fecha_desembarque_eta'] ?? ''), 0, 10);

    // La fecha de embarque del Excel pertenece al aviso/logística marítima. El campo legacy
    // fecha_embarque se conserva para la etapa posterior del expediente y no se sobreescribe.
    $fechaEmbarqueInput = '';
    $barco = cleanString((string) ($excelDetails['medio_transporte'] ?? ''), 150);
    $destino = cleanString(
        (string) (($excelDetails['lugar_desembarque'] ?? '') ?: ($excelDetails['domicilio_almacenamiento'] ?? '')),
        150
    );

    $keys = [];
    foreach ($excelPayload['items'] as $excelItem) {
        $key = trim((string) ($excelItem['clave'] ?? ''));
        if ($key !== '' && ! in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }
    $descripcion = cleanString(
        count($excelPayload['items']) . ' mercancías · Pedimentos ' . ($keys !== [] ? implode('/', $keys) : 'N/A')
        . ($manifiesto !== '' ? ' · Manifiesto ' . $manifiesto : ''),
        1000
    );
    $cipls = $cipl !== '' ? [$cipl] : [];
}

if ($pedimento === '') {
    $pedimento = null;
}

if ($cipl === '') {
    $cipl = null;
}

if ($manifiesto === '') {
    $manifiesto = null;
}
if ($referencia === '') {
    $errors['referencia'] = translate('desembarques.validation.reference_required', [], $currentLanguage);
}

if ($descripcion === '') {
    $errors['descripcion'] = translate('desembarques.validation.description_required', [], $currentLanguage);
}

if ($destino === '') {
    $errors['destino'] = translate('desembarques.validation.destination_required', [], $currentLanguage);
}

if ($barco === '') {
    $errors['barco'] = translate('desembarques.validation.vessel_required', [], $currentLanguage);
}

if ($cliente === '' && $clienteIdInput === '') {
    $errors['cliente'] = translate('desembarques.validation.customer_required', [], $currentLanguage);
}

if ($clienteIdInput !== '') {
    if (! ctype_digit($clienteIdInput)) {
        $errors['cliente_id'] = translate('desembarques.validation.client_invalid', [], $currentLanguage);
    } else {
        $clienteId = (int) $clienteIdInput;
    }
}

if ($statusIdInput === '') {
    $errors['status_id'] = translate('desembarques.validation.status_required', [], $currentLanguage);
} elseif (! ctype_digit($statusIdInput)) {
    $errors['status_id'] = translate('desembarques.validation.status_invalid', [], $currentLanguage);
} else {
    $statusId = (int) $statusIdInput;
    if ($statusId <= 0) {
        $errors['status_id'] = translate('desembarques.validation.status_invalid', [], $currentLanguage);
    }
}

$fechaDesembarque = parseDate($fechaDesembarqueInput, 'fecha_desembarque', $errors, $currentLanguage);
$fechaEmbarque = parseDate($fechaEmbarqueInput, 'fecha_embarque', $errors, $currentLanguage, false);

if ($fechaDesembarque instanceof DateTimeImmutable && $fechaEmbarque instanceof DateTimeImmutable && $fechaEmbarque < $fechaDesembarque) {
    $errors['fecha_embarque'] = translate('desembarques.validation.departure_before_landing', [], $currentLanguage);
}

$fileConfig = file_storage_config();
$pendingAttachments = [];
$pendingSourceExcel = null;

if ($excelFirst) {
    if (! isset($_FILES['source_excel']) || ! is_array($_FILES['source_excel'])) {
        $errors['source_excel'] = $currentLanguage === 'en'
            ? 'Upload the unloading Excel file.'
            : 'Carga el archivo Excel de desembarque.';
    } else {
        $excelUploads = normalize_uploaded_files_array($_FILES['source_excel']);
        $excelUpload = $excelUploads[0] ?? null;

        if (! is_array($excelUpload) || (int) ($excelUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['source_excel'] = $currentLanguage === 'en'
                ? 'Upload the unloading Excel file.'
                : 'Carga el archivo Excel de desembarque.';
        } else {
            try {
                $excelMetadata = validate_uploaded_file($excelUpload, $fileConfig);
                if (! in_array(strtolower((string) ($excelMetadata['extension'] ?? '')), ['xls', 'xlsx'], true)) {
                    throw new RuntimeException($currentLanguage === 'en'
                        ? 'The source file must be an Excel workbook.'
                        : 'El archivo fuente debe ser un libro de Excel.');
                }

                $pendingSourceExcel = [
                    'file' => $excelUpload,
                    'metadata' => $excelMetadata,
                ];

                if ($excelPayload !== null) {
                    $excelPayload['details']['source_excel_name'] = (string) ($excelMetadata['original_name'] ?? '');
                    $tmpPath = (string) ($excelUpload['tmp_name'] ?? '');
                    if ($tmpPath !== '' && is_file($tmpPath)) {
                        $serverHash = hash_file('sha256', $tmpPath);
                        if (is_string($serverHash) && $serverHash !== '') {
                            $excelPayload['details']['source_excel_sha256'] = $serverHash;
                        }
                    }
                }
            } catch (RuntimeException $exception) {
                $errors['source_excel'] = $currentLanguage === 'en'
                    ? 'The Excel file could not be validated: ' . $exception->getMessage()
                    : 'No fue posible validar el Excel: ' . $exception->getMessage();
            }
        }
    }
}

if (isset($_FILES['attachments']) && is_array($_FILES['attachments'])) {
    $normalizedFiles = normalize_uploaded_files_array($_FILES['attachments']);
    $actualUploads = [];

    foreach ($normalizedFiles as $upload) {
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $actualUploads[] = $upload;
    }

    $maxFilesPerRequest = isset($fileConfig['max_files_per_request'])
        ? (int) $fileConfig['max_files_per_request']
        : 0;

    if ($maxFilesPerRequest > 0 && count($actualUploads) > $maxFilesPerRequest) {
        $errors['attachments'] = translate('desembarques.files.error_too_many', ['max' => $maxFilesPerRequest], $currentLanguage);
    } else {
        foreach ($actualUploads as $upload) {
            try {
                $metadata = validate_uploaded_file($upload, $fileConfig);
                $pendingAttachments[] = [
                    'file' => $upload,
                    'metadata' => $metadata,
                ];
            } catch (RuntimeException $exception) {
                $errors['attachments'] = translate('desembarques.files.validation_error', ['error' => $exception->getMessage()], $currentLanguage);
                break;
            }
        }
    }
}

if ($errors !== []) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), $errors);
}

$actorId = (int) $_SESSION['user']['id'];

$today = new DateTimeImmutable('today');
$diffToday = $fechaDesembarque instanceof DateTimeImmutable ? (int) $fechaDesembarque->diff($today)->format('%r%a') : 0;
$diasTranscurridos = max($diffToday, 0);

$diasFuera = 0;
if ($fechaDesembarque instanceof DateTimeImmutable && $fechaEmbarque instanceof DateTimeImmutable) {
    $diff = (int) $fechaDesembarque->diff($fechaEmbarque)->format('%r%a');
    $diasFuera = max($diff, 0);
}

$fechaDesembarqueValue = $fechaDesembarque instanceof DateTimeImmutable
    ? $fechaDesembarque->format('Y-m-d')
    : null;
$fechaEmbarqueValue = $fechaEmbarque instanceof DateTimeImmutable
    ? $fechaEmbarque->format('Y-m-d')
    : null;

$createdBy = $actorId;
$newDesembarqueId = null;
$storedAttachments = [];
$storedPedimentoHeaders = [];

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

if ($excelFirst) {
    try {
        ensureExcelFirstSchema($connection);
    } catch (Throwable $exception) {
        respondWithError(500, $exception->getMessage(), [
            'migration' => 'database/migrations/20260818_excel_first_phase1.sql',
        ]);
    }
}

if ($clienteId !== null) {
    try {
        $clientLookup = $connection->prepare('SELECT id, name, email FROM clients WHERE id = ? LIMIT 1');

        if (! $clientLookup) {
            throw new RuntimeException(translate('desembarques.validation.client_lookup_failed', [], $currentLanguage));
        }

        $clientLookup->bind_param('i', $clienteId);
        $clientLookup->execute();

        $clientResult = $clientLookup->get_result();
        $client = $clientResult ? $clientResult->fetch_assoc() : null;

        $clientLookup->close();

        if (! $client) {
            respondWithError(422, translate('validation.errors', [], $currentLanguage), [
                'cliente_id' => translate('desembarques.validation.client_not_found', [], $currentLanguage),
            ]);
        }

        if ($cliente === '' && isset($client['name'])) {
            $cliente = cleanString((string) $client['name'], 150);
        }

        if ($cliente === '' && isset($client['email'])) {
            $cliente = cleanString((string) $client['email'], 150);
        }
    } catch (Throwable $exception) {
        respondWithError(500, translate('desembarques.validation.client_lookup_error', ['error' => $exception->getMessage()], $currentLanguage));
    }
}

try {
    $statusLookup = $connection->prepare('SELECT id FROM desembarque_statuses WHERE id = ? AND is_active = 1 LIMIT 1');

    if (! $statusLookup) {
        throw new RuntimeException(translate('desembarques.validation.status_lookup_failed', [], $currentLanguage));
    }

    $statusLookup->bind_param('i', $statusId);
    $statusLookup->execute();

    $statusResult = $statusLookup->get_result();
    $statusRecord = $statusResult ? $statusResult->fetch_assoc() : null;

    $statusLookup->close();

    if (! $statusRecord) {
        respondWithError(422, translate('validation.errors', [], $currentLanguage), [
            'status_id' => translate('desembarques.validation.status_not_found', [], $currentLanguage),
        ]);
    }
} catch (Throwable $exception) {
    respondWithError(500, translate('desembarques.validation.status_lookup_error', ['error' => $exception->getMessage()], $currentLanguage));
}

try {
    $connection->begin_transaction();

    $query = 'INSERT INTO desembarques (referencia, fecha_desembarque, descripcion, destino, folio_aviso, pedimento, cipl, manifiesto, fecha_embarque, barco, cliente, status_id, dias_transcurridos, dias_fuera, client_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException(translate('desembarques.save.error_prepare_statement', [], $currentLanguage));
    }

    $statement->bind_param(
        'sssssssssssiiiii',
        $referencia,
        $fechaDesembarqueValue,
        $descripcion,
        $destino,
        $folioAviso,
        $pedimento,
        $cipl,
        $manifiesto,
        $fechaEmbarqueValue,
        $barco,
        $cliente,
        $statusId,
        $diasTranscurridos,
        $diasFuera,
        $clienteId,
        $createdBy
    );

    $statement->execute();
    $newDesembarqueId = (int) $connection->insert_id;
    $statement->close();

    if ($pendingSourceExcel !== null) {
        $storedFile = store_uploaded_file($pendingSourceExcel['file'], $pendingSourceExcel['metadata'], $fileConfig);

        try {
            $uploadedBy = $actorId;
            $fileSize = (int) $storedFile['size'];
            $purpose = 'source_excel';
            $sourceSha256 = strtolower((string) ($excelPayload['details']['source_excel_sha256'] ?? ''));
            if (! preg_match('/^[a-f0-9]{64}$/', $sourceSha256)) {
                $computedHash = hash_file('sha256', (string) $storedFile['storage_path']);
                $sourceSha256 = is_string($computedHash) ? strtolower($computedHash) : null;
            }

            $insertFileQuery = 'INSERT INTO desembarque_files (desembarque_id, uploaded_by, original_name, stored_name, mime_type, extension, size, purpose, sha256, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
            $fileStatement = $connection->prepare($insertFileQuery);

            if (! $fileStatement) {
                delete_stored_file($storedFile['stored_name'], $fileConfig);
                throw new RuntimeException('Unable to prepare the source Excel attachment statement.');
            }

            $fileStatement->bind_param(
                'iissssiss',
                $newDesembarqueId,
                $uploadedBy,
                $storedFile['original_name'],
                $storedFile['stored_name'],
                $storedFile['mime_type'],
                $storedFile['extension'],
                $fileSize,
                $purpose,
                $sourceSha256
            );
            $fileStatement->execute();
            $storedFile['id'] = (int) $connection->insert_id;
            $fileStatement->close();
            $storedFile['source_excel'] = true;
            $storedAttachments[] = $storedFile;
        } catch (Throwable $sourceExcelException) {
            if (isset($fileStatement) && $fileStatement instanceof mysqli_stmt) {
                $fileStatement->close();
            }
            delete_stored_file($storedFile['stored_name'], $fileConfig);
            throw $sourceExcelException;
        }
    }

    if ($pendingAttachments !== []) {
        foreach ($pendingAttachments as $attachment) {
            $storedFile = store_uploaded_file($attachment['file'], $attachment['metadata'], $fileConfig);

            try {
                $uploadedBy = $actorId;
                $fileSize = (int) $storedFile['size'];
                $attachmentExtension = strtolower((string) $storedFile['extension']);
                $isCaseDocument = in_array($attachmentExtension, ['pdf', 'doc', 'docx'], true);
                $purpose = $isCaseDocument ? 'case_document' : 'attachment';
                $documentType = $isCaseDocument ? 'other' : null;
                $computedHash = hash_file('sha256', (string) $storedFile['storage_path']);
                $attachmentSha256 = is_string($computedHash) ? strtolower($computedHash) : null;

                $insertFileQuery = 'INSERT INTO desembarque_files (desembarque_id, uploaded_by, original_name, stored_name, mime_type, extension, size, purpose, document_type, sha256, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
                $fileStatement = $connection->prepare($insertFileQuery);

                if (! $fileStatement) {
                    delete_stored_file($storedFile['stored_name'], $fileConfig);
                    throw new RuntimeException('Unable to prepare the attachment statement.');
                }

                $fileStatement->bind_param(
                    'iissssisss',
                    $newDesembarqueId,
                    $uploadedBy,
                    $storedFile['original_name'],
                    $storedFile['stored_name'],
                    $storedFile['mime_type'],
                    $storedFile['extension'],
                    $fileSize,
                    $purpose,
                    $documentType,
                    $attachmentSha256
                );

                $fileStatement->execute();
                $fileId = (int) $connection->insert_id;
                $fileStatement->close();

                $storedFile['id'] = $fileId;
                $storedAttachments[] = $storedFile;
            } catch (Throwable $attachmentException) {
                if (isset($fileStatement) && $fileStatement instanceof mysqli_stmt) {
                    $fileStatement->close();
                }

                delete_stored_file($storedFile['stored_name'], $fileConfig);

                throw $attachmentException;
            }
        }
    }

    insertDesembarqueReferences($connection, 'desembarque_pedimentos', $newDesembarqueId, $pedimentos);
    insertDesembarqueReferences($connection, 'desembarque_manifests', $newDesembarqueId, $manifests);
    insertDesembarqueReferences($connection, 'desembarque_cipls', $newDesembarqueId, $cipls);
    $storedPedimentoHeaders = insertDesembarquePedimentoHeaders($connection, $newDesembarqueId, $pedimentoPackages);

    if ($excelFirst && $excelPayload !== null) {
        persistExcelFirstAviso($connection, $newDesembarqueId, $actorId, $excelPayload);
    }

    $auditAfter = [
        'referencia' => $referencia,
        'fecha_desembarque' => $fechaDesembarqueValue,
        'descripcion' => $descripcion,
        'destino' => $destino,
        'folio_aviso' => $folioAviso,
        'pedimento' => $pedimento,
        'cipl' => $cipl,
        'manifiesto' => $manifiesto,
        'fecha_embarque' => $fechaEmbarqueValue,
        'barco' => $barco,
        'cliente' => $cliente,
        'status_id' => $statusId,
        'dias_transcurridos' => $diasTranscurridos,
        'dias_fuera' => $diasFuera,
        'client_id' => $clienteId,
        'created_by' => $createdBy,
        'pedimentos' => $pedimentos,
        'manifests' => $manifests,
        'cipls' => $cipls,
        'pedimento_headers' => $storedPedimentoHeaders,
        'excel_first' => $excelFirst,
        'excel_items_count' => $excelFirst && $excelPayload !== null ? count($excelPayload['items']) : 0,
        'source_excel_name' => $excelFirst && $excelPayload !== null ? ($excelPayload['details']['source_excel_name'] ?? null) : null,
        'source_excel_sha256' => $excelFirst && $excelPayload !== null ? ($excelPayload['details']['source_excel_sha256'] ?? null) : null,
        'attachments' => array_map(
            static function (array $attachment): array {
                return [
                    'id' => (int) ($attachment['id'] ?? 0),
                    'original_name' => (string) $attachment['original_name'],
                    'mime_type' => (string) $attachment['mime_type'],
                    'extension' => (string) $attachment['extension'],
                    'size' => (int) $attachment['size'],
                ];
            },
            $storedAttachments
        ),
    ];

    $changes = compute_audit_changes([], $auditAfter);

    record_audit_log(
        'create',
        'desembarque',
        (string) $newDesembarqueId,
        [
            'before' => null,
            'after' => $auditAfter,
            'changes' => $changes,
        ],
        $actorId,
        $connection
    );

    $connection->commit();
} catch (Throwable $exception) {
    if (isset($connection) && $connection instanceof mysqli) {
        $connection->rollback();
    }

    foreach ($storedAttachments as $storedAttachment) {
        if (isset($storedAttachment['storage_path']) && is_file($storedAttachment['storage_path'])) {
            @unlink($storedAttachment['storage_path']);
        }
    }

    $errorKey = $pendingAttachments !== [] ? 'desembarques.files.save_error' : 'desembarques.save.error_failure';
    $errorMessage = trim((string) $exception->getMessage());
    $responseMessage = translate($errorKey, [], $currentLanguage);

    if ($errorMessage !== '') {
        $responseMessage .= ': ' . $errorMessage;
    }

    respondWithError(500, $responseMessage);
}

$responseAttachments = array_map(
    static function (array $attachment): array {
        $attachmentId = (int) ($attachment['id'] ?? 0);

        return [
            'id' => $attachmentId,
            'original_name' => (string) $attachment['original_name'],
            'mime_type' => (string) $attachment['mime_type'],
            'extension' => (string) $attachment['extension'],
            'size' => (int) $attachment['size'],
            'download_url' => '../api/desembarques/files/download.php?id=' . $attachmentId,
        ];
    },
    $storedAttachments
);

http_response_code(201);

echo json_encode([
    'success' => true,
    'message' => translate('desembarques.save.success', [], $currentLanguage),
    'id' => $newDesembarqueId,
    'pedimentos' => $pedimentos,
    'manifests' => $manifests,
    'cipls' => $cipls,
    'pedimento_headers' => $storedPedimentoHeaders,
    'attachments' => $responseAttachments,
]);
