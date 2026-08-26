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
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../config/files.php';

/**
 * @param array<string, string> $errors
 */
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

/**
 * @param array<string, string> $errors
 */
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
 * @return list<string>
 */
function fetchDesembarqueReferences(mysqli $connection, string $table, int $desembarqueId): array
{
    $allowedTables = [
        'desembarque_pedimentos',
        'desembarque_manifests',
        'desembarque_cipls',
    ];

    if (! in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Invalid reference table.');
    }

    $query = sprintf('SELECT reference FROM %s WHERE desembarque_id = ? ORDER BY id ASC', $table);
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException('Unable to prepare the reference lookup statement.');
    }

    $statement->bind_param('i', $desembarqueId);
    $statement->execute();

    $result = $statement->get_result();
    $references = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $reference = isset($row['reference']) ? cleanString((string) $row['reference'], 191) : '';

            if ($reference === '') {
                continue;
            }

            $references[] = $reference;
        }

        $result->free();
    }

    $statement->close();

    if ($references === []) {
        return [];
    }

    $unique = [];
    $seen = [];

    foreach ($references as $reference) {
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
function syncDesembarqueReferences(mysqli $connection, string $table, int $desembarqueId, array $references): void
{
    $allowedTables = [
        'desembarque_pedimentos',
        'desembarque_manifests',
        'desembarque_cipls',
    ];

    if (! in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Invalid reference table.');
    }

    $deleteQuery = sprintf('DELETE FROM %s WHERE desembarque_id = ?', $table);
    $deleteStatement = $connection->prepare($deleteQuery);

    if (! $deleteStatement) {
        throw new RuntimeException('Unable to prepare the reference delete statement.');
    }

    $deleteStatement->bind_param('i', $desembarqueId);
    $deleteStatement->execute();
    $deleteStatement->close();

    if ($references === []) {
        return;
    }

    $insertQuery = sprintf('INSERT INTO %s (desembarque_id, reference) VALUES (?, ?)', $table);
    $insertStatement = $connection->prepare($insertQuery);

    if (! $insertStatement) {
        throw new RuntimeException('Unable to prepare the reference insert statement.');
    }

    $referenceValue = '';
    $insertStatement->bind_param('is', $desembarqueId, $referenceValue);

    foreach ($references as $reference) {
        $referenceValue = $reference;
        $insertStatement->execute();
    }

    $insertStatement->close();
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
 * @return list<array{id: int, num_pedimento: ?string, cve_pedimento: ?string, razon_social: ?string, fecha_entrada: ?string, fecha_pago: ?string, pedimentos: list<string>}>
 */
function fetchDesembarquePedimentoHeaders(mysqli $connection, int $desembarqueId): array
{
    $headersStatement = $connection->prepare('SELECT id, num_pedimento, cve_pedimento, razon_social, fecha_entrada, fecha_pago FROM desembarque_pedimento_headers WHERE desembarque_id = ? ORDER BY id ASC');

    if (! $headersStatement) {
        throw new RuntimeException('Unable to prepare the pedimento header lookup statement.');
    }

    $headersStatement->bind_param('i', $desembarqueId);
    $headersStatement->execute();

    $result = $headersStatement->get_result();
    $headers = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $headerId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($headerId <= 0) {
                continue;
            }

            $headers[$headerId] = [
                'id' => $headerId,
                'num_pedimento' => isset($row['num_pedimento']) && $row['num_pedimento'] !== null
                    ? cleanString((string) $row['num_pedimento'], 100)
                    : null,
                'cve_pedimento' => isset($row['cve_pedimento']) && $row['cve_pedimento'] !== null
                    ? cleanString((string) $row['cve_pedimento'], 100)
                    : null,
                'razon_social' => isset($row['razon_social']) && $row['razon_social'] !== null
                    ? cleanString((string) $row['razon_social'], 255)
                    : null,
                'fecha_entrada' => isset($row['fecha_entrada']) && $row['fecha_entrada'] !== null
                    ? cleanString((string) $row['fecha_entrada'], 50)
                    : null,
                'fecha_pago' => isset($row['fecha_pago']) && $row['fecha_pago'] !== null
                    ? cleanString((string) $row['fecha_pago'], 50)
                    : null,
                'pedimentos' => [],
            ];
        }

        $result->free();
    }

    $headersStatement->close();

    if ($headers === []) {
        return [];
    }

    $linksStatement = $connection->prepare('SELECT reference FROM desembarque_pedimento_header_links WHERE header_id = ? ORDER BY id ASC');

    if (! $linksStatement) {
        throw new RuntimeException('Unable to prepare the pedimento header links lookup statement.');
    }

    foreach ($headers as $headerId => &$header) {
        $linksStatement->bind_param('i', $headerId);
        $linksStatement->execute();

        $linksResult = $linksStatement->get_result();
        $references = [];

        if ($linksResult instanceof mysqli_result) {
            while ($linkRow = $linksResult->fetch_assoc()) {
                $reference = isset($linkRow['reference']) ? cleanString((string) $linkRow['reference'], 191) : '';

                if ($reference === '') {
                    continue;
                }

                $references[] = $reference;
            }

            $linksResult->free();
        }

        if ($references !== []) {
            $unique = [];
            $seen = [];

            foreach ($references as $reference) {
                $key = mb_strtolower($reference);

                if (isset($seen[$key])) {
                    continue;
                }

                $unique[] = $reference;
                $seen[$key] = true;
            }

            $header['pedimentos'] = $unique;
        } else {
            $header['pedimentos'] = [];
        }
    }

    $linksStatement->close();

    return array_values($headers);
}

/**
 * @param list<array{header: array<string, string>, pedimentos: list<string>}> $packages
 * @return list<array{id: int, num_pedimento: ?string, cve_pedimento: ?string, razon_social: ?string, fecha_entrada: ?string, fecha_pago: ?string, pedimentos: list<string>}>
 */
function syncDesembarquePedimentoHeaders(mysqli $connection, int $desembarqueId, array $packages): array
{
    $deleteStatement = $connection->prepare('DELETE FROM desembarque_pedimento_headers WHERE desembarque_id = ?');

    if (! $deleteStatement) {
        throw new RuntimeException('Unable to prepare the pedimento header delete statement.');
    }

    $deleteStatement->bind_param('i', $desembarqueId);
    $deleteStatement->execute();
    $deleteStatement->close();

    return insertDesembarquePedimentoHeaders($connection, $desembarqueId, $packages);
}


$userEmail = (string) ($_SESSION['user']['email'] ?? '');
$userRole = (string) ($_SESSION['user']['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    respondWithError(403, translate('desembarques.permission_denied', [], $currentLanguage));
}

$csrfTokenValue = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

if (! validate_csrf_token($csrfTokenValue)) {
    respondWithError(419, translate('common.csrf_token_invalid', [], $currentLanguage));
}

$idValue = trim((string) ($_POST['id'] ?? ''));

if ($idValue === '' || ! ctype_digit($idValue)) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), [
        'id' => translate('desembarques.validation.id_invalid', [], $currentLanguage),
    ]);
}

$id = (int) $idValue;

if ($id <= 0) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), [
        'id' => translate('desembarques.validation.id_invalid', [], $currentLanguage),
    ]);
}

$actorId = (int) $_SESSION['user']['id'];
$existingRecord = null;
$previousStatusId = null;

$errors = [];
$referencia = cleanString($_POST['referencia'] ?? '', 100);
$folioAviso = cleanString($_POST['folio_aviso'] ?? '', 100);
$descripcion = cleanString($_POST['descripcion'] ?? '', 1000);
$pedimento = cleanString($_POST['pedimento'] ?? '', 100);
$cipl = cleanString($_POST['cipl'] ?? '', 100);
$manifiesto = cleanString($_POST['manifiesto'] ?? '', 100);
$destino = cleanString($_POST['destino'] ?? '', 150);
$barco = cleanString($_POST['barco'] ?? '', 150);
$cliente = cleanString($_POST['cliente'] ?? '', 150);
$clienteIdInput = trim((string) ($_POST['cliente_id'] ?? ''));
$clienteId = null;
$selectedClient = null;
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

if ($errors !== []) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), $errors);
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

try {
    $lookup = $connection->prepare('SELECT id, referencia, fecha_desembarque, descripcion, destino, folio_aviso, pedimento, cipl, manifiesto, fecha_embarque, barco, cliente, status_id, dias_transcurridos, dias_fuera, client_id FROM desembarques WHERE id = ? AND deleted_at IS NULL LIMIT 1');

    if (! $lookup) {
        throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
    }

    $lookup->bind_param('i', $id);
    $lookup->execute();

    $result = $lookup->get_result();
    $record = $result ? $result->fetch_assoc() : null;

    $lookup->close();

    if (! $record) {
        respondWithError(404, translate('desembarques.update.not_found', [], $currentLanguage));
    }

    $existingRecord = [
        'referencia' => (string) ($record['referencia'] ?? ''),
        'fecha_desembarque' => isset($record['fecha_desembarque']) && $record['fecha_desembarque'] !== null
            ? (string) $record['fecha_desembarque']
            : null,
        'descripcion' => (string) ($record['descripcion'] ?? ''),
        'destino' => (string) ($record['destino'] ?? ''),
        'folio_aviso' => (string) ($record['folio_aviso'] ?? ''),
        'pedimento' => isset($record['pedimento']) && $record['pedimento'] !== null
            ? (string) $record['pedimento']
            : null,
        'cipl' => isset($record['cipl']) && $record['cipl'] !== null
            ? (string) $record['cipl']
            : null,
        'manifiesto' => isset($record['manifiesto']) && $record['manifiesto'] !== null
            ? (string) $record['manifiesto']
            : null,
        'fecha_embarque' => isset($record['fecha_embarque']) && $record['fecha_embarque'] !== null
            ? (string) $record['fecha_embarque']
            : null,
        'barco' => (string) ($record['barco'] ?? ''),
        'cliente' => (string) ($record['cliente'] ?? ''),
        'status_id' => isset($record['status_id']) && $record['status_id'] !== null
            ? (int) $record['status_id']
            : null,
        'dias_transcurridos' => isset($record['dias_transcurridos']) && $record['dias_transcurridos'] !== null
            ? (int) $record['dias_transcurridos']
            : null,
        'dias_fuera' => isset($record['dias_fuera']) && $record['dias_fuera'] !== null
            ? (int) $record['dias_fuera']
            : null,
        'client_id' => isset($record['client_id']) && $record['client_id'] !== null
            ? (int) $record['client_id']
            : null,
    ];
    $existingRecord['pedimentos'] = fetchDesembarqueReferences($connection, 'desembarque_pedimentos', $id);
    $existingRecord['manifests'] = fetchDesembarqueReferences($connection, 'desembarque_manifests', $id);
    $existingRecord['cipls'] = fetchDesembarqueReferences($connection, 'desembarque_cipls', $id);
    $existingRecord['pedimento_headers'] = fetchDesembarquePedimentoHeaders($connection, $id);
    $previousStatusId = isset($existingRecord['status_id']) ? (int) $existingRecord['status_id'] : null;
} catch (Throwable $exception) {
    respondWithError(500, translate('desembarques.update.error_lookup', ['error' => $exception->getMessage()], $currentLanguage));
}

$fileConfig = file_storage_config();
$existingAttachments = [];
$existingAttachmentsById = [];

try {
    $attachmentsStatement = $connection->prepare('SELECT id, original_name, stored_name, mime_type, extension, size, uploaded_by, purpose FROM desembarque_files WHERE desembarque_id = ? ORDER BY id ASC');

    if (! $attachmentsStatement) {
        throw new RuntimeException('Unable to prepare the attachment lookup statement.');
    }

    $attachmentsStatement->bind_param('i', $id);
    $attachmentsStatement->execute();

    $attachmentsResult = $attachmentsStatement->get_result();

    if ($attachmentsResult instanceof mysqli_result) {
        while ($attachmentRow = $attachmentsResult->fetch_assoc()) {
            $attachmentId = isset($attachmentRow['id']) ? (int) $attachmentRow['id'] : 0;
            $storedName = (string) ($attachmentRow['stored_name'] ?? '');

            if ($attachmentId <= 0 || $storedName === '') {
                continue;
            }

            $attachment = [
                'id' => $attachmentId,
                'original_name' => (string) ($attachmentRow['original_name'] ?? ''),
                'stored_name' => $storedName,
                'mime_type' => (string) ($attachmentRow['mime_type'] ?? ''),
                'extension' => (string) ($attachmentRow['extension'] ?? ''),
                'size' => isset($attachmentRow['size']) ? (int) $attachmentRow['size'] : 0,
                'uploaded_by' => isset($attachmentRow['uploaded_by']) ? (int) $attachmentRow['uploaded_by'] : null,
                'purpose' => (string) ($attachmentRow['purpose'] ?? 'attachment'),
                'storage_path' => get_stored_file_path($storedName, $fileConfig),
            ];

            $existingAttachments[] = $attachment;
            $existingAttachmentsById[$attachmentId] = $attachment;
        }
    }

    $attachmentsStatement->close();
} catch (Throwable $exception) {
    respondWithError(500, translate('desembarques.files.load_error', ['error' => $exception->getMessage()], $currentLanguage));
}

$existingRecord['attachments'] = array_map(
    static function (array $attachment): array {
        return [
            'id' => (int) ($attachment['id'] ?? 0),
            'original_name' => (string) ($attachment['original_name'] ?? ''),
            'mime_type' => (string) ($attachment['mime_type'] ?? ''),
            'extension' => (string) ($attachment['extension'] ?? ''),
            'size' => (int) ($attachment['size'] ?? 0),
            'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
        ];
    },
    $existingAttachments
);

$attachmentsToDelete = [];
$attachmentsScheduledForDeletion = [];
$pendingAttachments = [];

$deleteAttachmentsInput = $_POST['delete_attachments'] ?? [];

if ($deleteAttachmentsInput !== null && $deleteAttachmentsInput !== '') {
    if (! is_array($deleteAttachmentsInput)) {
        $deleteAttachmentsInput = [$deleteAttachmentsInput];
    }

    foreach ($deleteAttachmentsInput as $deleteValue) {
        $value = trim((string) $deleteValue);

        if ($value === '') {
            continue;
        }

        if (! ctype_digit($value)) {
            $errors['delete_attachments'] = translate('desembarques.files.delete_invalid', [], $currentLanguage);
            break;
        }

        $attachmentId = (int) $value;

        if (! isset($existingAttachmentsById[$attachmentId])) {
            $errors['delete_attachments'] = translate('desembarques.files.delete_not_found', [], $currentLanguage);
            break;
        }

        $attachmentToDelete = $existingAttachmentsById[$attachmentId];
        $deletePurpose = (string) ($attachmentToDelete['purpose'] ?? '');
        $deleteExtension = strtolower((string) ($attachmentToDelete['extension'] ?? ''));
        if ($deletePurpose === 'source_excel') {
            $errors['delete_attachments'] = translate('desembarques.files.delete_not_found', [], $currentLanguage);
            break;
        }
        if ($deletePurpose === 'case_document' || in_array($deleteExtension, ['pdf', 'doc', 'docx'], true)) {
            $errors['delete_attachments'] = $currentLanguage === 'en'
                ? 'Case file documents are not deleted. Replace them from the notice case file.'
                : 'Los documentos del expediente no se eliminan. Reemplázalos desde el expediente del aviso.';
            break;
        }

        $attachmentsToDelete[$attachmentId] = $attachmentToDelete;
    }
}

if ($attachmentsToDelete !== []) {
    $attachmentsScheduledForDeletion = array_values($attachmentsToDelete);
}

$normalizedUploads = [];
if (isset($_FILES['attachments']) && is_array($_FILES['attachments'])) {
    $normalizedUploads = normalize_uploaded_files_array($_FILES['attachments']);
}

if ($normalizedUploads !== []) {
    $actualUploads = [];

    foreach ($normalizedUploads as $upload) {
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

        $selectedClient = [
            'id' => isset($client['id']) ? (int) $client['id'] : null,
            'name' => isset($client['name']) ? (string) $client['name'] : '',
            'email' => isset($client['email']) ? (string) $client['email'] : '',
        ];

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

$statusLabel = '';

try {
    $statusLookup = $connection->prepare('SELECT id, slug, name_es, name_en FROM desembarque_statuses WHERE id = ? AND is_active = 1 LIMIT 1');

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

    $statusSlug = isset($statusRecord['slug']) ? (string) $statusRecord['slug'] : '';
    $statusNameEs = isset($statusRecord['name_es']) ? (string) $statusRecord['name_es'] : '';
    $statusNameEn = isset($statusRecord['name_en']) ? (string) $statusRecord['name_en'] : '';

    $statusLabel = $currentLanguage === 'en' && $statusNameEn !== '' ? $statusNameEn : $statusNameEs;

    if ($statusLabel === '' && $statusNameEn !== '') {
        $statusLabel = $statusNameEn;
    }

    if ($statusLabel === '' && $statusSlug !== '') {
        $statusLabel = $statusSlug;
    }
} catch (Throwable $exception) {
    respondWithError(500, translate('desembarques.validation.status_lookup_error', ['error' => $exception->getMessage()], $currentLanguage));
}

$statusChanged = $previousStatusId !== null && $previousStatusId !== $statusId;

$today = new DateTimeImmutable('today');
$diasTranscurridos = 0;

if ($fechaDesembarque instanceof DateTimeImmutable) {
    $diffToday = (int) $fechaDesembarque->diff($today)->format('%r%a');
    $diasTranscurridos = max($diffToday, 0);
}

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
$clienteIdValue = $clienteId;

$newStoredAttachments = [];
$deletedAttachmentIds = array_keys($attachmentsToDelete);
$pedimentoHeadersAfter = [];

try {
    $connection->begin_transaction();

    $updateQuery = 'UPDATE desembarques SET referencia = ?, fecha_desembarque = ?, descripcion = ?, destino = ?, folio_aviso = ?, pedimento = ?, cipl = ?, manifiesto = ?, fecha_embarque = ?, barco = ?, cliente = ?, status_id = ?, dias_transcurridos = ?, dias_fuera = ?, client_id = ? WHERE id = ? AND deleted_at IS NULL';
    $statement = $connection->prepare($updateQuery);

    if (! $statement) {
        throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
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
        $clienteIdValue,
        $id
    );

    $statement->execute();
    $statement->close();

    if ($attachmentsToDelete !== []) {
        $deleteStatement = $connection->prepare('DELETE FROM desembarque_files WHERE id = ? AND desembarque_id = ?');

        if (! $deleteStatement) {
            throw new RuntimeException('Unable to prepare the attachment deletion statement.');
        }

        foreach ($attachmentsToDelete as $attachmentId => $attachmentData) {
            $deleteStatement->bind_param('ii', $attachmentId, $id);
            $deleteStatement->execute();
        }

        $deleteStatement->close();
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
                    $id,
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
                $storedFile['uploaded_by'] = $uploadedBy;
                $storedFile['purpose'] = $purpose;
                $storedFile['document_type'] = $documentType;
                $storedFile['sha256'] = $attachmentSha256;
                $storedFile['is_active'] = 1;
                $newStoredAttachments[] = $storedFile;
            } catch (Throwable $attachmentException) {
                if (isset($fileStatement) && $fileStatement instanceof mysqli_stmt) {
                    $fileStatement->close();
                }

                delete_stored_file($storedFile['stored_name'], $fileConfig);

                throw $attachmentException;
            }
        }
    }

    syncDesembarqueReferences($connection, 'desembarque_pedimentos', $id, $pedimentos);
    syncDesembarqueReferences($connection, 'desembarque_manifests', $id, $manifests);
    syncDesembarqueReferences($connection, 'desembarque_cipls', $id, $cipls);
    $pedimentoHeadersAfter = syncDesembarquePedimentoHeaders($connection, $id, $pedimentoPackages);

    $attachmentsAfter = [];

    foreach ($existingAttachments as $attachment) {
        if (isset($attachmentsToDelete[$attachment['id']])) {
            continue;
        }

        $attachmentsAfter[] = [
            'id' => (int) $attachment['id'],
            'original_name' => (string) $attachment['original_name'],
            'mime_type' => (string) $attachment['mime_type'],
            'extension' => (string) $attachment['extension'],
            'size' => (int) $attachment['size'],
            'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
        ];
    }

    foreach ($newStoredAttachments as $attachment) {
        $attachmentsAfter[] = [
            'id' => (int) $attachment['id'],
            'original_name' => (string) $attachment['original_name'],
            'mime_type' => (string) $attachment['mime_type'],
            'extension' => (string) $attachment['extension'],
            'size' => (int) $attachment['size'],
            'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : $actorId,
        ];
    }

    $afterRecord = [
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
        'client_id' => $clienteIdValue,
        'attachments' => $attachmentsAfter,
        'pedimentos' => $pedimentos,
        'manifests' => $manifests,
        'cipls' => $cipls,
        'pedimento_headers' => $pedimentoHeadersAfter,
    ];

    $beforeState = is_array($existingRecord) ? $existingRecord : [];
    $changes = compute_audit_changes($beforeState, $afterRecord);

    $attachmentChanges = [
        'added' => array_map(
            static function (array $attachment): array {
                return [
                    'id' => (int) ($attachment['id'] ?? 0),
                    'original_name' => (string) ($attachment['original_name'] ?? ''),
                    'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                    'extension' => (string) ($attachment['extension'] ?? ''),
                    'size' => (int) ($attachment['size'] ?? 0),
                    'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
                ];
            },
            $newStoredAttachments
        ),
        'removed' => array_map(
            static function (array $attachment): array {
                return [
                    'id' => (int) ($attachment['id'] ?? 0),
                    'original_name' => (string) ($attachment['original_name'] ?? ''),
                    'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                    'extension' => (string) ($attachment['extension'] ?? ''),
                    'size' => (int) ($attachment['size'] ?? 0),
                    'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
                ];
            },
            $attachmentsScheduledForDeletion
        ),
    ];

    if ($changes !== [] || $attachmentChanges['added'] !== [] || $attachmentChanges['removed'] !== []) {
        record_audit_log(
            'update',
            'desembarque',
            (string) $id,
            [
                'before' => $existingRecord,
                'after' => $afterRecord,
                'changes' => $changes,
                'attachments' => $attachmentChanges,
            ],
            $actorId,
            $connection
        );
    }

    $connection->commit();
} catch (Throwable $exception) {
    if (isset($connection) && $connection instanceof mysqli) {
        $connection->rollback();
    }

    foreach ($newStoredAttachments as $attachment) {
        delete_stored_file($attachment['stored_name'], $fileConfig);
    }

    $errorKey = ($pendingAttachments !== [] || $attachmentsToDelete !== [])
        ? 'desembarques.files.update_error'
        : 'desembarques.update.error_failure';

    respondWithError(500, translate($errorKey, ['error' => $exception->getMessage()], $currentLanguage));
}

foreach ($attachmentsScheduledForDeletion as $attachment) {
    delete_stored_file($attachment['stored_name'], $fileConfig);
}

if ($statusChanged) {
    $finalClientId = $clienteIdValue;

    if ($finalClientId === null && isset($existingRecord['client_id']) && $existingRecord['client_id'] !== null) {
        $finalClientId = (int) $existingRecord['client_id'];
    }

    $finalClientData = null;

    if ($finalClientId !== null && $finalClientId > 0) {
        if (is_array($selectedClient) && isset($selectedClient['id']) && (int) $selectedClient['id'] === $finalClientId) {
            $finalClientData = $selectedClient;
        } elseif (isset($connection) && $connection instanceof mysqli) {
            try {
                $notifyClientStatement = $connection->prepare('SELECT id, name, email FROM clients WHERE id = ? LIMIT 1');

                if ($notifyClientStatement) {
                    $notifyClientStatement->bind_param('i', $finalClientId);
                    $notifyClientStatement->execute();

                    $notifyResult = $notifyClientStatement->get_result();
                    $notifyRow = $notifyResult ? $notifyResult->fetch_assoc() : null;

                    if ($notifyRow) {
                        $finalClientData = [
                            'id' => isset($notifyRow['id']) ? (int) $notifyRow['id'] : null,
                            'name' => isset($notifyRow['name']) ? (string) $notifyRow['name'] : '',
                            'email' => isset($notifyRow['email']) ? (string) $notifyRow['email'] : '',
                        ];
                    }

                    $notifyClientStatement->close();
                }
            } catch (Throwable $exception) {
                // Ignore lookup failures when sending notifications.
            }
        }
    }

    $clientEmail = '';
    $clientNameForEmail = '';

    if (is_array($finalClientData)) {
        $clientEmail = trim((string) ($finalClientData['email'] ?? ''));
        $clientNameForEmail = trim((string) ($finalClientData['name'] ?? ''));
    }

    $legacyClientValue = $cliente !== ''
        ? $cliente
        : (string) ($existingRecord['cliente'] ?? '');
    $legacyClientValue = trim($legacyClientValue);

    if ($clientNameForEmail === '' && $legacyClientValue !== '') {
        $clientNameForEmail = $legacyClientValue;
    }

    if ($clientEmail === '' && $legacyClientValue !== '' && filter_var($legacyClientValue, FILTER_VALIDATE_EMAIL)) {
        $clientEmail = $legacyClientValue;
    }

    if ($clientEmail !== '' && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        $normalizedClientEmail = mb_strtolower($clientEmail);
        $normalizedActorEmail = mb_strtolower($userEmail);

        if ($normalizedClientEmail === '' || $normalizedClientEmail !== $normalizedActorEmail) {
            $statusLabelForEmail = $statusLabel !== ''
                ? $statusLabel
                : translate('desembarques.email.status_change.unknown_status', [], $currentLanguage);

            sendDesembarqueStatusChangeEmail(
                $clientEmail,
                $clientNameForEmail,
                $referencia,
                $statusLabelForEmail,
                $currentLanguage
            );
        }
    }
}

http_response_code(200);

$responseAttachments = array_map(
    static function (array $attachment): array {
        $attachmentId = (int) ($attachment['id'] ?? 0);

        return [
            'id' => $attachmentId,
            'original_name' => (string) ($attachment['original_name'] ?? ''),
            'mime_type' => (string) ($attachment['mime_type'] ?? ''),
            'extension' => (string) ($attachment['extension'] ?? ''),
            'size' => (int) ($attachment['size'] ?? 0),
            'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
            'download_url' => '../api/desembarques/files/download.php?id=' . $attachmentId,
        ];
    },
    isset($afterRecord['attachments']) && is_array($afterRecord['attachments']) ? $afterRecord['attachments'] : []
);

echo json_encode([
    'success' => true,
    'message' => translate('desembarques.update.success', [], $currentLanguage),
    'id' => $id,
    'pedimentos' => $pedimentos,
    'manifests' => $manifests,
    'cipls' => $cipls,
    'pedimento_headers' => $pedimentoHeadersAfter,
    'attachments' => $responseAttachments,
    'removed_attachment_ids' => array_values($deletedAttachmentIds),
]);
