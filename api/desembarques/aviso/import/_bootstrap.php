<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once __DIR__ . '/../../../../config/files.php';
require_once __DIR__ . '/../../../../config/historical_import.php';
require_once __DIR__ . '/../../../../src/HistoricalAvisoExtractor.php';

/** @return array{id:int,name:string,email:string,role:string} */
function aviso_import_require_internal(): array
{
    $user = aviso_require_user();
    aviso_require_internal_user($user);
    return $user;
}

function aviso_import_validate_csrf(?string $provided = null): void
{
    $token = $provided ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
    if (! validate_csrf_token(is_string($token) ? $token : null)) {
        aviso_json(419, ['success' => false, 'message' => 'La sesión de seguridad expiró. Recarga la página e intenta nuevamente.']);
    }
}

function aviso_import_storage_root(): string
{
    $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'aviso-imports';
    if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
        throw new RuntimeException('No fue posible preparar el almacenamiento temporal de importaciones.');
    }
    if (! is_writable($root)) {
        throw new RuntimeException('El almacenamiento temporal de importaciones no tiene permisos de escritura.');
    }
    return $root;
}

function aviso_import_batch_dir(string $publicId): string
{
    if (! preg_match('/^[a-f0-9]{32}$/', $publicId)) {
        throw new RuntimeException('Identificador de lote inválido.');
    }
    $dir = aviso_import_storage_root() . DIRECTORY_SEPARATOR . $publicId;
    if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
        throw new RuntimeException('No fue posible crear el directorio del lote.');
    }
    return $dir;
}

function aviso_import_safe_extension(string $name): string
{
    return strtolower(trim((string) pathinfo($name, PATHINFO_EXTENSION)));
}

function aviso_import_notice_from_filename(string $name): ?string
{
    $upper = function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
    if (preg_match('/\b(\d{2,4})\s*[-_ ]\s*(\d{2,4})\b/u', $upper, $m)) {
        return $m[1] . '-' . $m[2];
    }
    return null;
}

function aviso_import_identity_key(string $name, ?string $notice): string
{
    if ($notice !== null && $notice !== '') {
        return 'notice:' . strtolower($notice);
    }
    $base = strtolower((string) pathinfo($name, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9]+/', '', $base) ?? '';
    return 'name:' . ($base !== '' ? $base : hash('sha256', $name));
}

/** @return array{desembarque_id:?int,reason:?string,kind:?string,is_deleted:bool,deleted_at:?string,delete_reason:?string} */
function aviso_import_detect_duplicate(mysqli $connection, int $clientId, ?string $noticeNumber, ?string $pdfSha): array
{
    if ($pdfSha !== null && $pdfSha !== '') {
        $result = $connection->execute_query(
            'SELECT v.desembarque_id, e.deleted_at, e.delete_reason '
            . 'FROM desembarque_aviso_versions v '
            . 'INNER JOIN desembarques e ON e.id = v.desembarque_id '
            . 'WHERE v.pdf_sha256 = ? LIMIT 1',
            [$pdfSha]
        );
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($row) {
            return [
                'desembarque_id' => (int) $row['desembarque_id'],
                'reason' => ! empty($row['deleted_at'])
                    ? 'El mismo PDF pertenece a un expediente eliminado. Debe restaurarse; no se creará otro.'
                    : 'El mismo PDF ya existe en el historial de emisiones.',
                'kind' => 'pdf_sha',
                'is_deleted' => ! empty($row['deleted_at']),
                'deleted_at' => isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
                'delete_reason' => isset($row['delete_reason']) ? (string) $row['delete_reason'] : null,
            ];
        }
    }

    if ($noticeNumber !== null && $noticeNumber !== '') {
        $result = $connection->execute_query(
            'SELECT d.desembarque_id, e.deleted_at, e.delete_reason FROM desembarque_aviso_details d '
            . 'INNER JOIN desembarques e ON e.id = d.desembarque_id '
            . 'WHERE e.client_id = ? AND d.notice_number = ? LIMIT 1',
            [$clientId, $noticeNumber]
        );
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($row) {
            return [
                'desembarque_id' => (int) $row['desembarque_id'],
                'reason' => ! empty($row['deleted_at'])
                    ? 'Ya existe un aviso eliminado con el mismo número para este cliente. Un administrador puede restaurarlo o un usuario interno puede importarlo como un aviso nuevo.'
                    : 'Ya existe un aviso con el mismo número para este cliente.',
                'kind' => 'notice',
                'is_deleted' => ! empty($row['deleted_at']),
                'deleted_at' => isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
                'delete_reason' => isset($row['delete_reason']) ? (string) $row['delete_reason'] : null,
            ];
        }
    }

    return [
        'desembarque_id' => null,
        'reason' => null,
        'kind' => null,
        'is_deleted' => false,
        'deleted_at' => null,
        'delete_reason' => null,
    ];
}

/** @return array<string,mixed> */
function aviso_import_decode_json_array(mixed $value): array
{
    if (! is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function aviso_import_text(mixed $value, int $max = 0): ?string
{
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }
    $text = trim(str_replace("\0", '', (string) $value));
    if ($text === '') {
        return null;
    }
    if ($max > 0) {
        $text = function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }
    return $text;
}

function aviso_import_date(mixed $value): ?string
{
    $text = aviso_import_text($value, 20);
    if ($text === null) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $text ? $text : null;
}

function aviso_import_datetime(mixed $value): ?string
{
    $text = aviso_import_text($value, 40);
    if ($text === null) {
        return null;
    }
    $text = str_replace('T', ' ', $text);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $text);
        if ($date instanceof DateTimeImmutable && $date->format($format) === $text) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    return null;
}

function aviso_import_upper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function aviso_import_pedimento_digits(string $value): string
{
    return (string) preg_replace('/\D+/', '', $value);
}

function aviso_import_pedimento_number(mixed $value): ?string
{
    $text = aviso_import_text($value, 120);
    if ($text === null) {
        return null;
    }
    $digits = aviso_import_pedimento_digits($text);
    if (strlen($digits) === 15) {
        return substr($digits, 0, 2) . ' ' . substr($digits, 2, 2) . ' ' . substr($digits, 4, 4) . ' ' . substr($digits, 8, 7);
    }
    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

/** @param mixed $value @return list<array{key:?string,number:string,importer_name:?string}> */
function aviso_import_normalize_item_pedimentos(mixed $value, ?string $legacyKey = null, ?string $legacyNumber = null, ?string $fallbackImporter = null): array
{
    $input = is_array($value) ? $value : [];
    if ($input === [] && $legacyNumber !== null) {
        $input[] = ['key' => $legacyKey, 'number' => $legacyNumber, 'importer_name' => $fallbackImporter];
    }
    $result = [];
    $seen = [];
    foreach ($input as $pedimento) {
        if (! is_array($pedimento)) {
            continue;
        }
        $key = aviso_import_text($pedimento['key'] ?? ($pedimento['clave'] ?? null), 30);
        $key = $key !== null ? aviso_import_upper(preg_replace('/\s+/u', '', $key) ?? $key) : null;
        $number = aviso_import_pedimento_number($pedimento['number'] ?? ($pedimento['pedimento'] ?? ($pedimento['num_pedimento'] ?? null)));
        if ($number === null) {
            continue;
        }
        $identity = ($key ?? '') . ':' . aviso_import_pedimento_digits($number);
        if (isset($seen[$identity])) {
            continue;
        }
        $seen[$identity] = true;
        $result[] = [
            'key' => $key,
            'number' => $number,
            'importer_name' => aviso_import_text($pedimento['importer_name'] ?? null, 255) ?? $fallbackImporter,
        ];
    }
    return $result;
}

/** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
function aviso_import_dedupe_items(array $items): array
{
    $deduped = [];
    $seen = [];
    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }
        $description = aviso_import_text($item['description'] ?? ($item['descripcion'] ?? null), 2000);
        if ($description === null) {
            continue;
        }
        $quantityRaw = $item['quantity'] ?? ($item['cantidad'] ?? 0);
        $quantity = is_numeric($quantityRaw) ? (float) $quantityRaw : (float) str_replace(',', '.', (string) $quantityRaw);
        if ($quantity < 0) {
            $quantity = 0;
        }
        $serial = aviso_import_text($item['serial_number'] ?? null, 1000);
        $marca = aviso_import_text($item['marca'] ?? null, 180);
        $legacyKey = aviso_import_text($item['key'] ?? ($item['clave'] ?? null), 30);
        $legacyKey = $legacyKey !== null ? aviso_import_upper(preg_replace('/\s+/u', '', $legacyKey) ?? $legacyKey) : null;
        $legacyNumber = aviso_import_pedimento_number($item['pedimento'] ?? ($item['num_pedimento'] ?? null));
        $importerName = aviso_import_text($item['importer_name'] ?? null, 255);
        $itemPedimentos = aviso_import_normalize_item_pedimentos($item['pedimentos'] ?? [], $legacyKey, $legacyNumber, $importerName);
        $partida = aviso_import_text($item['partida'] ?? null, 100);

        $pedIdentity = array_map(static fn (array $p): string => aviso_import_upper((string) ($p['key'] ?? '')) . ':' . aviso_import_pedimento_digits((string) ($p['number'] ?? '')), $itemPedimentos);
        sort($pedIdentity, SORT_STRING);
        $identity = implode('|', [
            aviso_import_upper((string) preg_replace('/[^\pL\pN]+/u', '', $description)),
            aviso_import_upper((string) preg_replace('/[^\pL\pN]+/u', '', (string) $serial)),
            implode(',', $pedIdentity),
            aviso_import_upper((string) preg_replace('/[^A-Z0-9]+/iu', '', (string) $partida)),
            number_format($quantity, 3, '.', ''),
        ]);
        if (isset($seen[$identity])) {
            continue;
        }
        $seen[$identity] = true;
        $legacy = count($itemPedimentos) === 1 ? $itemPedimentos[0] : null;
        $deduped[] = [
            'sort_order' => count($deduped) + 1,
            'description' => $description,
            'quantity' => $quantity,
            'serial_number' => $serial,
            'marca' => $marca,
            'key' => $legacy['key'] ?? null,
            'pedimento' => $legacy['number'] ?? null,
            'pedimentos' => $itemPedimentos,
            'partida' => $partida,
            'importer_name' => $importerName,
        ];
    }
    return $deduped;
}

/** @return array<string,mixed> */
function aviso_import_normalize_review_data(array $input): array
{
    $notice = aviso_import_text($input['notice_number'] ?? null, 100);
    if ($notice !== null) {
        $notice = preg_replace('/\s+/u', '', str_replace(['–', '—', '_'], '-', $notice)) ?? $notice;
    }
    $documentCode = aviso_import_text($input['document_code'] ?? null, 100);
    if ($documentCode === null && $notice !== null) {
        $documentCode = 'MADE-' . $notice;
    }

    $pedimentosInput = isset($input['pedimentos']) && is_array($input['pedimentos']) ? $input['pedimentos'] : [];
    $pedimentos = [];
    $seenPedimentos = [];
    foreach ($pedimentosInput as $pedimento) {
        if (! is_array($pedimento)) {
            continue;
        }
        $key = aviso_import_text($pedimento['key'] ?? ($pedimento['clave'] ?? null), 30);
        $key = $key !== null ? aviso_import_upper(preg_replace('/\s+/u', '', $key) ?? $key) : null;
        $number = aviso_import_pedimento_number($pedimento['number'] ?? ($pedimento['pedimento'] ?? ($pedimento['num_pedimento'] ?? null)));
        if ($key === null && $number === null) {
            continue;
        }
        $identity = ($key ?? '') . ':' . aviso_import_pedimento_digits((string) $number);
        if (isset($seenPedimentos[$identity])) {
            continue;
        }
        $seenPedimentos[$identity] = true;
        $pedimentos[] = [
            'key' => $key,
            'number' => $number,
            'importer_name' => aviso_import_text($pedimento['importer_name'] ?? ($pedimento['razon_social'] ?? null), 255),
        ];
    }

    $comitente = aviso_import_text($input['comitente'] ?? null, 255);
    if (count($pedimentos) === 1 && $pedimentos[0]['importer_name'] === null && $comitente !== null) {
        // Sugerencia conservadora para avisos con un solo pedimento; el usuario la ve y confirma en 5E3.
        $pedimentos[0]['importer_name'] = $comitente;
    }

    $items = aviso_import_dedupe_items(isset($input['items']) && is_array($input['items']) ? $input['items'] : []);

    // Todo pedimento relacionado a una mercancía también forma parte del catálogo global del aviso.
    foreach ($items as $item) {
        $itemImporter = aviso_import_text($item['importer_name'] ?? null, 255);
        foreach (($item['pedimentos'] ?? []) as $itemPedimento) {
            if (! is_array($itemPedimento)) {
                continue;
            }
            $key = aviso_import_text($itemPedimento['key'] ?? null, 30);
            $key = $key !== null ? aviso_import_upper(preg_replace('/\s+/u', '', $key) ?? $key) : null;
            $number = aviso_import_pedimento_number($itemPedimento['number'] ?? null);
            if ($number === null) {
                continue;
            }
            $identity = ($key ?? '') . ':' . aviso_import_pedimento_digits($number);
            if (isset($seenPedimentos[$identity])) {
                continue;
            }
            $seenPedimentos[$identity] = true;
            $pedimentos[] = [
                'key' => $key,
                'number' => $number,
                'importer_name' => aviso_import_text($itemPedimento['importer_name'] ?? null, 255) ?? $itemImporter,
            ];
        }
    }

    if (count($pedimentos) === 1) {
        foreach ($items as &$item) {
            if ($item['key'] === null) {
                $item['key'] = $pedimentos[0]['key'];
            }
            if ($item['pedimento'] === null) {
                $item['pedimento'] = $pedimentos[0]['number'];
            }
        }
        unset($item);
    }

    return [
        'notice_number' => $notice,
        'document_code' => $documentCode,
        'office_date' => aviso_import_date($input['office_date'] ?? null),
        'recipient_name' => aviso_import_text($input['recipient_name'] ?? null, 200),
        'recipient_title' => aviso_import_text($input['recipient_title'] ?? null, 255),
        'rig_name' => aviso_import_text($input['rig_name'] ?? null, 180),
        'rig_imo' => aviso_import_text($input['rig_imo'] ?? null, 40),
        'rig_field' => aviso_import_text($input['rig_field'] ?? ($input['campo'] ?? null), 120),
        'rig_area' => aviso_import_text($input['rig_area'] ?? null, 120),
        'comitente' => $comitente,
        'manifest' => aviso_import_text($input['manifest'] ?? ($input['manifiesto'] ?? $notice), 100),
        'transport_name' => aviso_import_text($input['transport_name'] ?? null, 255),
        'transport_imo' => aviso_import_text($input['transport_imo'] ?? null, 40),
        'consignataria' => aviso_import_text($input['consignataria'] ?? null, 255),
        'shipping_date' => aviso_import_date($input['shipping_date'] ?? null),
        'landing_datetime' => aviso_import_datetime($input['landing_datetime'] ?? null),
        'landing_place' => aviso_import_text($input['landing_place'] ?? null, 1200),
        'storage_address' => aviso_import_text($input['storage_address'] ?? null, 1200),
        'repair_address' => aviso_import_text($input['repair_address'] ?? null, 1200),
        'signer_name' => aviso_import_text($input['signer_name'] ?? null, 200),
        'signer_title' => aviso_import_text($input['signer_title'] ?? null, 200),
        'pedimentos' => $pedimentos,
        'items' => $items,
    ];
}

/** @return array<string,mixed> */
function aviso_import_seed_review_data(array $row): array
{
    $review = aviso_import_decode_json_array($row['review_data'] ?? null);
    if ($review !== []) {
        return aviso_import_normalize_review_data($review);
    }
    return aviso_import_normalize_review_data(aviso_import_decode_json_array($row['normalized_data'] ?? null));
}

/** @return array{item_count:int,piece_count:float} */
function aviso_import_item_counts(array $data): array
{
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $pieces = 0.0;
    foreach ($items as $item) {
        if (is_array($item) && is_numeric($item['quantity'] ?? null)) {
            $pieces += (float) $item['quantity'];
        }
    }
    return ['item_count' => count($items), 'piece_count' => round($pieces, 3)];
}

/** @return list<string> */
function aviso_import_review_errors(array $data, string $avisoStatus, ?string $effectiveAt, ?string $reason, string $action, ?string $duplicateKind, ?int $duplicateId): array
{
    $errors = [];
    if ($action === 'skip') {
        return [];
    }
    if ($action === 'link_version') {
        if ($duplicateId === null || $duplicateId <= 0) {
            $errors[] = 'No existe un expediente destino para vincular el PDF.';
        }
        if ($duplicateKind === 'pdf_sha') {
            $errors[] = 'Ese PDF exacto ya está archivado; no puede vincularse como otra versión idéntica.';
        }
        return $errors;
    }
    if ($action === 'import_new_override_deleted') {
        if ($duplicateId === null || $duplicateId <= 0 || $duplicateKind !== 'notice') {
            $errors[] = 'La excepción administrativa sólo puede usarse para un aviso eliminado con el mismo número.';
        }
        if (aviso_import_text($reason, 1000) === null) {
            $errors[] = 'Indica el motivo para importar un aviso nuevo conservando el expediente eliminado.';
        }
    }

    $required = [
        'notice_number' => 'Número de aviso/manifiesto',
        'document_code' => 'Código MADE',
        'office_date' => 'Fecha del oficio',
        'rig_name' => 'Rig',
        'rig_imo' => 'IMO del Rig',
        'rig_field' => 'Campo',
        'comitente' => 'Comitente',
        'transport_name' => 'Medio de transporte',
        'transport_imo' => 'IMO del transporte',
        'consignataria' => 'Consignataria',
        'shipping_date' => 'Fecha de embarque',
        'landing_datetime' => 'Fecha de desembarque / ETA',
    ];
    foreach ($required as $key => $label) {
        if (($data[$key] ?? null) === null || trim((string) ($data[$key] ?? '')) === '') {
            $errors[] = 'Falta: ' . $label . '.';
        }
    }

    $pedimentos = isset($data['pedimentos']) && is_array($data['pedimentos']) ? $data['pedimentos'] : [];
    if ($pedimentos === []) {
        $errors[] = 'Debe existir al menos un pedimento.';
    }
    foreach ($pedimentos as $index => $pedimento) {
        if (! is_array($pedimento)) {
            $errors[] = 'Pedimento #' . ($index + 1) . ' inválido.';
            continue;
        }
        if (aviso_import_text($pedimento['key'] ?? null, 30) === null) {
            $errors[] = 'Falta la clave del pedimento #' . ($index + 1) . '.';
        }
        if (aviso_import_pedimento_number($pedimento['number'] ?? null) === null) {
            $errors[] = 'Falta el número del pedimento #' . ($index + 1) . '.';
        }
        if (aviso_import_text($pedimento['importer_name'] ?? null, 255) === null) {
            $errors[] = 'Falta el importador del pedimento #' . ($index + 1) . '.';
        }
    }

    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    if ($items === []) {
        $errors[] = 'Debe existir al menos un renglón de mercancía.';
    }
    $knownPedimentos = [];
    foreach ($pedimentos as $pedimento) {
        if (! is_array($pedimento)) {
            continue;
        }
        $identity = aviso_import_upper((string) ($pedimento['key'] ?? '')) . ':' . aviso_import_pedimento_digits((string) ($pedimento['number'] ?? ''));
        $knownPedimentos[$identity] = true;
    }
    foreach ($items as $index => $item) {
        if (! is_array($item)) {
            $errors[] = 'Mercancía #' . ($index + 1) . ' inválida.';
            continue;
        }
        if (aviso_import_text($item['description'] ?? null, 2000) === null) {
            $errors[] = 'Falta la descripción de la mercancía #' . ($index + 1) . '.';
        }
        if (! is_numeric($item['quantity'] ?? null) || (float) ($item['quantity'] ?? 0) <= 0) {
            $errors[] = 'La cantidad de la mercancía #' . ($index + 1) . ' debe ser mayor a cero.';
        }
        $itemRelations = aviso_import_normalize_item_pedimentos($item['pedimentos'] ?? [], aviso_import_text($item['key'] ?? null, 30), aviso_import_pedimento_number($item['pedimento'] ?? null), aviso_import_text($item['importer_name'] ?? null, 255));
        foreach ($itemRelations as $relation) {
            $itemIdentity = aviso_import_upper((string) ($relation['key'] ?? '')) . ':' . aviso_import_pedimento_digits((string) ($relation['number'] ?? ''));
            if (! isset($knownPedimentos[$itemIdentity])) {
                $errors[] = 'Un pedimento relacionado con la mercancía #' . ($index + 1) . ' no coincide con los pedimentos revisados.';
            }
        }
    }

    $avisoStatus = aviso_status_normalize($avisoStatus);
    if ($avisoStatus === 'presented' && aviso_import_datetime($effectiveAt) === null) {
        $errors[] = 'El estado Presentado requiere fecha/hora efectiva.';
    }
    if (in_array($avisoStatus, ['replaced', 'cancelled'], true) && aviso_import_text($reason, 1000) === null) {
        $errors[] = 'El estado seleccionado requiere un motivo.';
    }

    return array_values(array_unique($errors));
}

/** @return array<string,mixed> */
function aviso_import_row_payload(mysqli $connection, array $row): array
{
    $data = aviso_import_seed_review_data($row);
    $confidence = aviso_import_decode_json_array($row['confidence_json'] ?? null);
    $counts = aviso_import_item_counts($data);
    $reviewStatus = (string) ($row['review_status'] ?? 'pending');
    $commitStatus = (string) ($row['commit_status'] ?? 'pending');
    $duplicateId = isset($row['duplicate_desembarque_id']) && $row['duplicate_desembarque_id'] !== null ? (int) $row['duplicate_desembarque_id'] : null;
    $reviewAction = (string) ($row['review_action'] ?? ($duplicateId ? 'skip' : 'import_new'));
    $duplicateKind = isset($row['duplicate_kind']) && $row['duplicate_kind'] !== null ? (string) $row['duplicate_kind'] : null;
    $duplicateDeletedAt = null;
    $duplicateDeleteReason = null;
    if ($duplicateId !== null && $duplicateId > 0) {
        $duplicateResult = $connection->execute_query(
            'SELECT deleted_at, delete_reason FROM desembarques WHERE id = ? LIMIT 1',
            [$duplicateId]
        );
        $duplicateRecord = $duplicateResult instanceof mysqli_result ? $duplicateResult->fetch_assoc() : null;
        if (is_array($duplicateRecord) && ! empty($duplicateRecord['deleted_at'])) {
            $duplicateDeletedAt = (string) $duplicateRecord['deleted_at'];
            $duplicateDeleteReason = (string) ($duplicateRecord['delete_reason'] ?? '');
            if ($reviewAction !== 'import_new_override_deleted' || $duplicateKind !== 'notice') {
                $reviewAction = 'skip';
            }
        } elseif ($reviewAction === 'import_new_override_deleted') {
            $reviewAction = 'skip';
        }
    }
    $duplicateIsDeleted = $duplicateDeletedAt !== null;

    return [
        'id' => (int) ($row['id'] ?? 0),
        'pdf_name' => $row['source_pdf_name'] ?? null,
        'pdf_sha256' => $row['source_pdf_sha256'] ?? null,
        'pdf_size' => isset($row['source_pdf_size']) && $row['source_pdf_size'] !== null ? (int) $row['source_pdf_size'] : null,
        'pdf_page_count' => isset($row['source_pdf_page_count']) && $row['source_pdf_page_count'] !== null ? (int) $row['source_pdf_page_count'] : null,
        'word_name' => $row['source_docx_name'] ?? null,
        'docx_name' => $row['source_docx_name'] ?? null,
        'notice_number' => $data['notice_number'] ?? ($row['detected_notice_number'] ?? null),
        'document_code' => $data['document_code'] ?? ($row['detected_document_code'] ?? null),
        'extraction_source' => (string) ($row['extraction_source'] ?? 'unknown'),
        'extraction_status' => (string) ($row['extraction_status'] ?? 'review_required'),
        'status' => $reviewStatus === 'ready' ? 'reviewed_ready' : (string) ($row['extraction_status'] ?? 'review_required'),
        'review_status' => $reviewStatus,
        'review_action' => $reviewAction,
        'review_aviso_status' => aviso_status_normalize($row['review_aviso_status'] ?? 'presented'),
        'review_status_effective_at' => $row['review_status_effective_at'] ?? null,
        'review_reason' => $row['review_reason'] ?? null,
        'reviewed_by' => isset($row['reviewed_by']) && $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
        'reviewed_at' => $row['reviewed_at'] ?? null,
        'commit_status' => $commitStatus,
        'is_scanned_pdf' => (bool) ($row['is_scanned_pdf'] ?? false),
        'data' => $data,
        'confidence' => $confidence,
        'critical_score' => isset($row['critical_score']) && $row['critical_score'] !== null ? (float) $row['critical_score'] : null,
        'overall_confidence' => isset($row['overall_confidence']) && $row['overall_confidence'] !== null ? (float) $row['overall_confidence'] : null,
        'item_count' => $counts['item_count'],
        'piece_count' => $counts['piece_count'],
        'duplicate_desembarque_id' => $duplicateId,
        'duplicate_reason' => $row['duplicate_reason'] ?? null,
        'duplicate_kind' => $duplicateKind,
        'duplicate_is_deleted' => $duplicateIsDeleted,
        'duplicate_deleted_at' => $duplicateDeletedAt,
        'duplicate_delete_reason' => $duplicateDeleteReason,
        'compare_url' => $duplicateId && ! $duplicateIsDeleted ? 'aviso-expediente.php?id=' . $duplicateId : null,
        'restore_url' => $duplicateId && $duplicateIsDeleted ? 'aviso-papelera.php?focus=' . $duplicateId : null,
        'imported_desembarque_id' => isset($row['imported_desembarque_id']) && $row['imported_desembarque_id'] !== null ? (int) $row['imported_desembarque_id'] : null,
        'imported_version_id' => isset($row['imported_version_id']) && $row['imported_version_id'] !== null ? (int) $row['imported_version_id'] : null,
        'import_error' => $row['import_error'] ?? null,
        'imported_at' => $row['imported_at'] ?? null,
        'can_import' => (
            ! $duplicateIsDeleted
            || ($reviewAction === 'import_new_override_deleted' && $duplicateKind === 'notice')
        ) && $reviewStatus === 'ready' && $commitStatus === 'pending',
        'error_message' => $row['error_message'] ?? null,
    ];
}

/** @return array<string,int|string|null> */
function aviso_import_recalculate_batch(mysqli $connection, int $batchId): array
{
    $sql = "SELECT COUNT(*) AS total_rows, "
        . "SUM(review_status = 'ready' AND commit_status = 'pending') AS ready_rows, "
        . "SUM(review_status <> 'ready' AND commit_status = 'pending') AS review_rows, "
        . "SUM(duplicate_desembarque_id IS NOT NULL AND commit_status = 'pending') AS duplicate_rows, "
        . "SUM(commit_status = 'imported') AS imported_rows, "
        . "SUM(commit_status = 'skipped') AS skipped_rows, "
        . "SUM(commit_status = 'error') AS failed_rows "
        . "FROM aviso_import_rows WHERE batch_id = ?";
    $result = $connection->execute_query($sql, [$batchId]);
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $counts = [
        'total_rows' => (int) ($row['total_rows'] ?? 0),
        'ready_rows' => (int) ($row['ready_rows'] ?? 0),
        'review_rows' => (int) ($row['review_rows'] ?? 0),
        'duplicate_rows' => (int) ($row['duplicate_rows'] ?? 0),
        'imported_rows' => (int) ($row['imported_rows'] ?? 0),
        'skipped_rows' => (int) ($row['skipped_rows'] ?? 0),
        'failed_rows' => (int) ($row['failed_rows'] ?? 0),
    ];
    $finished = $counts['total_rows'] > 0 && ($counts['imported_rows'] + $counts['skipped_rows']) >= $counts['total_rows'];
    $status = $finished ? 'completed' : (($counts['imported_rows'] + $counts['skipped_rows']) > 0 ? 'partial' : 'review');
    $connection->execute_query(
        'UPDATE aviso_import_batches SET status = ?, total_rows = ?, ready_rows = ?, review_rows = ?, duplicate_rows = ?, imported_rows = ?, skipped_rows = ?, failed_rows = ? WHERE id = ? LIMIT 1',
        [$status, $counts['total_rows'], $counts['ready_rows'], $counts['review_rows'], $counts['duplicate_rows'], $counts['imported_rows'], $counts['skipped_rows'], $counts['failed_rows'], $batchId]
    );
    return array_merge($counts, ['status' => $status]);
}

function aviso_import_remove_diacritics(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (class_exists('Normalizer')) {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
        if ($normalized !== false) {
            $value = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
        }
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }
    return $value;
}

function aviso_import_client_prefix(string $clientName, string $clientEmail): string
{
    $source = trim($clientName) !== '' ? trim($clientName) : trim((string) explode('@', $clientEmail)[0]);
    $source = strtoupper(aviso_import_remove_diacritics($source));
    $source = preg_replace('/[^A-Z]/', '', $source) ?? '';
    if ($source === '') {
        $source = 'CLI';
    }
    return strlen($source) >= 3 ? substr($source, 0, 3) : str_pad($source, 3, 'X');
}

/** Caller debe mantener bloqueado el cliente dentro de la transacción. */
function aviso_import_next_reference(mysqli $connection, int $clientId, string $clientName, string $clientEmail, ?string $historicalDate): string
{
    $prefix = aviso_import_client_prefix($clientName, $clientEmail);
    // Incluye eliminados intencionalmente para no reutilizar referencias históricas.
    $result = $connection->execute_query('SELECT referencia FROM desembarques WHERE client_id = ? ORDER BY id ASC', [$clientId]);
    $maxSequence = 0;
    $sequenceWidth = 3;
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $reference = (string) ($row['referencia'] ?? '');
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)-\d{6}$/i', $reference, $m)) {
                $maxSequence = max($maxSequence, (int) $m[1]);
                $sequenceWidth = max($sequenceWidth, strlen($m[1]));
            }
        }
    }
    $date = aviso_import_date($historicalDate);
    $dateObject = $date !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : new DateTimeImmutable('now');
    if (! $dateObject instanceof DateTimeImmutable) {
        $dateObject = new DateTimeImmutable('now');
    }
    return sprintf('%s%s-%s', $prefix, str_pad((string) ($maxSequence + 1), $sequenceWidth, '0', STR_PAD_LEFT), $dateObject->format('dmy'));
}

function aviso_import_match_profile(mysqli $connection, int $clientId, ?string $rigName, ?string $rigImo): ?int
{
    $rigName = aviso_import_text($rigName, 180);
    if ($rigName === null) {
        return null;
    }
    $sql = "SELECT id FROM aviso_profiles WHERE is_active = 1 AND (client_id = ? OR client_id IS NULL) "
        . "AND UPPER(TRIM(rig_name)) = UPPER(TRIM(?)) "
        . "AND (? IS NULL OR ? = '' OR rig_imo IS NULL OR TRIM(rig_imo) = '' OR TRIM(rig_imo) = TRIM(?)) "
        . "ORDER BY (client_id = ?) DESC, is_default DESC, id ASC LIMIT 1";
    $result = $connection->execute_query($sql, [$clientId, $rigName, $rigImo, $rigImo, $rigImo, $clientId]);
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    return $row ? (int) $row['id'] : null;
}

/** @return array{stored_name:string,storage_path:string,size:int,sha256:string} */
function aviso_import_copy_historical_pdf(string $sourcePath, string $expectedSha): array
{
    if (! is_file($sourcePath)) {
        throw new RuntimeException('El PDF histórico temporal ya no está disponible.');
    }
    $head = @file_get_contents($sourcePath, false, null, 0, 5);
    if (! is_string($head) || $head !== '%PDF-') {
        throw new RuntimeException('El archivo histórico temporal ya no es un PDF válido.');
    }
    $actualSha = hash_file('sha256', $sourcePath);
    if (! is_string($actualSha) || ! hash_equals(strtolower($expectedSha), strtolower($actualSha))) {
        throw new RuntimeException('La huella del PDF histórico cambió desde la etapa de revisión.');
    }
    $config = file_storage_config();
    $directory = ensure_file_storage_directory($config);
    $storedName = '';
    $targetPath = '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = bin2hex(random_bytes(16)) . '.pdf';
        $candidatePath = $directory . DIRECTORY_SEPARATOR . $candidate;
        if (! file_exists($candidatePath)) {
            $storedName = $candidate;
            $targetPath = $candidatePath;
            break;
        }
    }
    if ($storedName === '' || ! @copy($sourcePath, $targetPath)) {
        throw new RuntimeException('No fue posible archivar el PDF histórico en el almacenamiento definitivo.');
    }
    @chmod($targetPath, 0640);
    return [
        'stored_name' => $storedName,
        'storage_path' => $targetPath,
        'size' => (int) filesize($targetPath),
        'sha256' => strtolower($actualSha),
    ];
}


/**
 * Phase 5E5: read-only acceptance validation for one committed historical import.
 *
 * @return array{pass:bool,desembarque_id:int,version_id:?int,action:string,checks:list<array<string,mixed>>,summary:array{pass:int,warn:int,fail:int,total:int}}
 */
function aviso_import_validate_created_result(
    mysqli $connection,
    int $desembarqueId,
    ?int $versionId = null,
    ?int $importRowId = null,
    string $action = 'import_new'
): array {
    $deletedDuplicateOverride = $action === 'import_new_override_deleted';
    if ($deletedDuplicateOverride || ! in_array($action, ['import_new', 'link_version'], true)) {
        $action = 'import_new';
    }
    $checks = [];

    $add = static function (string $code, string $label, bool $ok, string $detail, string $severity = 'fail') use (&$checks): void {
        $checks[] = [
            'code' => $code,
            'label' => $label,
            'status' => $ok ? 'pass' : ($severity === 'warn' ? 'warn' : 'fail'),
            'detail' => $detail,
        ];
    };

    $baseResult = $connection->execute_query(
        'SELECT e.id, e.client_id, e.referencia, e.manifiesto, '
        . 'd.notice_number, d.document_code, d.aviso_status, d.source_type, d.historical_import_row_id '
        . 'FROM desembarques e LEFT JOIN desembarque_aviso_details d ON d.desembarque_id = e.id '
        . 'WHERE e.id = ? AND e.deleted_at IS NULL LIMIT 1',
        [$desembarqueId]
    );
    $base = $baseResult instanceof mysqli_result ? $baseResult->fetch_assoc() : null;
    $add('record_exists', 'Expediente creado', $base !== null, $base ? 'El expediente existe.' : 'No existe el expediente importado.');

    if (! $base) {
        return [
            'pass' => false,
            'desembarque_id' => $desembarqueId,
            'version_id' => $versionId,
            'action' => $action,
            'checks' => $checks,
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1, 'total' => 1],
        ];
    }

    $notice = trim((string) ($base['notice_number'] ?? ''));
    $documentCode = trim((string) ($base['document_code'] ?? ''));
    $add('notice_present', 'Número de aviso', $notice !== '', $notice !== '' ? $notice : 'El expediente no tiene número de aviso.');
    $add('document_code_present', 'Código MADE', $documentCode !== '', $documentCode !== '' ? $documentCode : 'El expediente no tiene código MADE.');

    if ($action === 'import_new') {
        $add(
            'detail_source',
            'Origen histórico del expediente',
            (string) ($base['source_type'] ?? '') === 'historical_import',
            'source_type=' . (string) ($base['source_type'] ?? 'NULL')
        );
        if ($importRowId !== null) {
            $add(
                'detail_import_row',
                'Vínculo con fila de importación',
                (int) ($base['historical_import_row_id'] ?? 0) === $importRowId,
                'historical_import_row_id=' . (string) ($base['historical_import_row_id'] ?? 'NULL')
            );
        }
    }

    $versionSql = 'SELECT * FROM desembarque_aviso_versions WHERE desembarque_id = ?';
    $versionParams = [$desembarqueId];
    if ($versionId !== null && $versionId > 0) {
        $versionSql .= ' AND id = ?';
        $versionParams[] = $versionId;
    } elseif ($importRowId !== null) {
        $versionSql .= ' AND source_import_row_id = ?';
        $versionParams[] = $importRowId;
    } else {
        $versionSql .= " AND source_type = 'historical_import'";
    }
    $versionSql .= ' ORDER BY id DESC LIMIT 1';
    $versionResult = $connection->execute_query($versionSql, $versionParams);
    $version = $versionResult instanceof mysqli_result ? $versionResult->fetch_assoc() : null;
    $resolvedVersionId = $version ? (int) $version['id'] : $versionId;
    $add('historical_version', 'PDF histórico archivado', $version !== null, $version ? 'Versión v' . str_pad((string) ((int) $version['version_no']), 3, '0', STR_PAD_LEFT) . ' encontrada.' : 'No existe la versión histórica esperada.');

    if ($version) {
        $add(
            'version_source',
            'Origen de la versión',
            (string) ($version['source_type'] ?? '') === 'historical_import',
            'source_type=' . (string) ($version['source_type'] ?? 'NULL')
        );
        if ($importRowId !== null) {
            $add(
                'version_import_row',
                'Versión ligada al staging',
                (int) ($version['source_import_row_id'] ?? 0) === $importRowId,
                'source_import_row_id=' . (string) ($version['source_import_row_id'] ?? 'NULL')
            );
        }

        $pdfSha = strtolower(trim((string) ($version['pdf_sha256'] ?? '')));
        $storedName = basename((string) ($version['stored_name'] ?? ''));
        $storage = ensure_file_storage_directory(file_storage_config());
        $pdfPath = $storedName !== '' ? $storage . DIRECTORY_SEPARATOR . $storedName : '';
        $fileExists = $pdfPath !== '' && is_file($pdfPath);
        $add('pdf_file_exists', 'Archivo PDF físico', $fileExists, $fileExists ? $storedName : 'No se encontró el PDF archivado en storage.');
        if ($fileExists) {
            $actualSize = (int) filesize($pdfPath);
            $expectedSize = (int) ($version['size'] ?? 0);
            $add('pdf_size', 'Tamaño del PDF', $actualSize === $expectedSize, sprintf('BD=%d bytes · archivo=%d bytes', $expectedSize, $actualSize));
            $actualSha = strtolower((string) hash_file('sha256', $pdfPath));
            $add('pdf_sha', 'SHA-256 del PDF', $pdfSha !== '' && hash_equals($pdfSha, $actualSha), $actualSha !== '' ? substr($actualSha, 0, 20) . '…' : 'No fue posible calcular SHA-256.');
        }

        if ($pdfSha !== '') {
            $shaResult = $connection->execute_query('SELECT COUNT(*) AS total FROM desembarque_aviso_versions WHERE pdf_sha256 = ?', [$pdfSha]);
            $shaRow = $shaResult instanceof mysqli_result ? $shaResult->fetch_assoc() : null;
            $shaCount = (int) ($shaRow['total'] ?? 0);
            $add('pdf_sha_unique', 'PDF no duplicado', $shaCount === 1, 'Coincidencias SHA-256 en versiones: ' . $shaCount);
        }

        $snapshotJson = (string) ($version['snapshot_json'] ?? '');
        $snapshotSha = strtolower(trim((string) ($version['snapshot_sha256'] ?? '')));
        $snapshotHashVersion = isset($version['snapshot_hash_version']) && $version['snapshot_hash_version'] !== null
            ? (int) $version['snapshot_hash_version']
            : 0;
        $snapshotVerification = aviso_snapshot_verify($snapshotJson, $snapshotSha);

        if ($snapshotHashVersion >= 1) {
            $add(
                'snapshot_contract',
                'Contrato de integridad del snapshot',
                true,
                'snapshot_hash_version=' . $snapshotHashVersion
            );
            $add(
                'snapshot_sha',
                'Integridad del snapshot',
                (bool) ($snapshotVerification['valid_json'] ?? false) && (bool) ($snapshotVerification['hash_ok'] ?? false),
                ($snapshotVerification['hash_ok'] ?? false)
                    ? substr((string) ($snapshotVerification['canonical_sha256'] ?? $snapshotSha), 0, 20) . '…'
                    : ((string) ($snapshotVerification['error'] ?? '') !== ''
                        ? (string) $snapshotVerification['error']
                        : 'El SHA-256 canónico del snapshot no coincide.'),
                'fail'
            );
        } else {
            // Compatibilidad 5E5.1: las versiones previas al contrato canónico almacenaban
            // SHA-256 sobre la cadena JSON antes de que MySQL la normalizara internamente.
            // Esa huella puede no reproducirse al leer el JSON de vuelta. El payload se
            // conserva sin alterarlo y se reporta como legado documentado, nunca como PASS falso.
            $hasLegacyPayload = trim($snapshotJson) !== '' && strtolower(trim($snapshotJson)) !== 'null';
            $add(
                'snapshot_contract',
                'Contrato de integridad del snapshot',
                false,
                'Versión histórica previa a 5E5.1 (snapshot_hash_version=NULL).',
                'warn'
            );
            $add(
                'snapshot_sha',
                'Integridad del snapshot',
                $hasLegacyPayload && (bool) ($snapshotVerification['hash_ok'] ?? false),
                ! $hasLegacyPayload
                    ? 'Snapshot legado ausente; la versión fue creada antes del contrato 5E5.1.'
                    : (($snapshotVerification['hash_ok'] ?? false)
                        ? substr((string) ($snapshotVerification['canonical_sha256'] ?? $snapshotSha), 0, 20) . '…'
                        : 'Snapshot legado presente, pero su SHA original no es reproducible tras la normalización JSON de MySQL.'),
                'warn'
            );
        }

        if ($action === 'import_new') {
            $itemResult = $connection->execute_query(
                'SELECT COUNT(*) AS item_count, COALESCE(SUM(cantidad),0) AS piece_count FROM desembarque_aviso_items WHERE desembarque_id = ?',
                [$desembarqueId]
            );
            $itemRow = $itemResult instanceof mysqli_result ? $itemResult->fetch_assoc() : null;
            $dbItemCount = (int) ($itemRow['item_count'] ?? 0);
            $dbPieceCount = round((float) ($itemRow['piece_count'] ?? 0), 3);
            $versionItemCount = (int) ($version['item_count'] ?? 0);
            $versionPieceCount = round((float) ($version['piece_count'] ?? 0), 3);
            $add('item_count', 'Renglones de mercancía', $dbItemCount === $versionItemCount && $dbItemCount > 0, sprintf('BD=%d · versión=%d', $dbItemCount, $versionItemCount));
            $add('piece_count', 'Cantidad total de piezas', abs($dbPieceCount - $versionPieceCount) < 0.001 && $dbPieceCount > 0, sprintf('BD=%s · versión=%s', $dbPieceCount, $versionPieceCount));

            $pedResult = $connection->execute_query('SELECT COUNT(*) AS total FROM desembarque_pedimento_headers WHERE desembarque_id = ?', [$desembarqueId]);
            $pedRow = $pedResult instanceof mysqli_result ? $pedResult->fetch_assoc() : null;
            $pedCount = (int) ($pedRow['total'] ?? 0);
            $add('pedimentos', 'Pedimentos estructurados', $pedCount > 0, 'Pedimentos: ' . $pedCount);

            $manifestResult = $connection->execute_query('SELECT COUNT(*) AS total FROM desembarque_manifests WHERE desembarque_id = ?', [$desembarqueId]);
            $manifestRow = $manifestResult instanceof mysqli_result ? $manifestResult->fetch_assoc() : null;
            $manifestCount = (int) ($manifestRow['total'] ?? 0);
            $add('manifest', 'Manifiesto estructurado', $manifestCount > 0, 'Registros de manifiesto: ' . $manifestCount);

            if ($snapshotJson !== '') {
                $snapshot = json_decode($snapshotJson, true);
                $summary = is_array($snapshot) && is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
                if ($summary !== []) {
                    $snapItem = (int) ($summary['item_count'] ?? -1);
                    $snapPieces = round((float) ($summary['piece_count'] ?? -1), 3);
                    // El snapshot histórico es inmutable. Si hubo una remediación posterior controlada
                    // (por ejemplo, deduplicación de una mercancía importada antes de 5E3.1), conservamos
                    // el snapshot original y reportamos la diferencia como advertencia, no como corrupción.
                    $add(
                        'snapshot_counts',
                        'Conteos del snapshot',
                        $snapItem === $dbItemCount && abs($snapPieces - $dbPieceCount) < 0.001,
                        sprintf('snapshot=%d/%s · BD=%d/%s', $snapItem, $snapPieces, $dbItemCount, $dbPieceCount),
                        'warn'
                    );
                }
            }

            $statusResult = $connection->execute_query(
                'SELECT from_status, to_status, source FROM desembarque_aviso_status_history WHERE desembarque_id = ? ORDER BY id ASC',
                [$desembarqueId]
            );
            $statusRows = [];
            if ($statusResult instanceof mysqli_result) {
                while ($statusRow = $statusResult->fetch_assoc()) {
                    $statusRows[] = $statusRow;
                }
            }
            $finalHistoryStatus = $statusRows !== [] ? (string) ($statusRows[count($statusRows) - 1]['to_status'] ?? '') : '';
            $currentStatus = (string) ($base['aviso_status'] ?? '');
            $hasHistoricalTransition = false;
            foreach ($statusRows as $statusRow) {
                if ((string) ($statusRow['source'] ?? '') === 'historical_import') {
                    $hasHistoricalTransition = true;
                    break;
                }
            }
            $add('status_history', 'Historial documental', $hasHistoricalTransition && $finalHistoryStatus === $currentStatus, sprintf('Transiciones=%d · final=%s · actual=%s', count($statusRows), $finalHistoryStatus ?: 'N/D', $currentStatus ?: 'N/D'));

            $noticeDupResult = $connection->execute_query(
                'SELECT COUNT(*) AS total FROM desembarque_aviso_details a INNER JOIN desembarques e ON e.id = a.desembarque_id WHERE e.client_id = ? AND a.notice_number = ?'
                . ($deletedDuplicateOverride ? ' AND e.deleted_at IS NULL' : ''),
                [(int) $base['client_id'], $notice]
            );
            $noticeDupRow = $noticeDupResult instanceof mysqli_result ? $noticeDupResult->fetch_assoc() : null;
            $noticeCount = (int) ($noticeDupRow['total'] ?? 0);
            $add(
                'notice_unique',
                $deletedDuplicateOverride ? 'Aviso activo único por cliente' : 'Aviso único por cliente',
                $notice !== '' && $noticeCount === 1,
                ($deletedDuplicateOverride ? 'Coincidencias activas cliente+número: ' : 'Coincidencias cliente+número: ') . $noticeCount
            );
        }
    }

    if ($importRowId !== null) {
        $rowResult = $connection->execute_query(
            'SELECT commit_status, imported_desembarque_id, imported_version_id FROM aviso_import_rows WHERE id = ? LIMIT 1',
            [$importRowId]
        );
        $importRow = $rowResult instanceof mysqli_result ? $rowResult->fetch_assoc() : null;
        // Durante commit.php la fila aún puede seguir en pending; el vínculo final se valida allí por los IDs creados.
        if ($importRow) {
            $linkedRecord = $importRow['imported_desembarque_id'] === null || (int) $importRow['imported_desembarque_id'] === $desembarqueId;
            $linkedVersion = $importRow['imported_version_id'] === null || $resolvedVersionId === null || (int) $importRow['imported_version_id'] === $resolvedVersionId;
            $add('staging_link', 'Coherencia con staging', $linkedRecord && $linkedVersion, 'Fila de importación #' . $importRowId);
        } else {
            $add('staging_link', 'Coherencia con staging', false, 'No existe la fila de importación #' . $importRowId . '.');
        }
    }

    $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => count($checks)];
    foreach ($checks as $check) {
        $status = (string) ($check['status'] ?? 'fail');
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }

    return [
        'pass' => $summary['fail'] === 0,
        'desembarque_id' => $desembarqueId,
        'version_id' => $resolvedVersionId,
        'action' => $action,
        'checks' => $checks,
        'summary' => $summary,
    ];
}

/**
 * Phase 5E5: validate all committed rows of one batch without mutating business data.
 *
 * @return array<string,mixed>
 */
function aviso_import_validate_batch(mysqli $connection, array $batch): array
{
    $batchId = (int) ($batch['id'] ?? 0);
    $rowsResult = $connection->execute_query(
        "SELECT * FROM aviso_import_rows WHERE batch_id = ? AND commit_status IN ('imported','skipped') ORDER BY id ASC",
        [$batchId]
    );
    $rows = [];
    $summary = ['rows' => 0, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skipped' => 0, 'checks' => 0];
    if ($rowsResult instanceof mysqli_result) {
        while ($row = $rowsResult->fetch_assoc()) {
            if ((string) ($row['commit_status'] ?? '') === 'skipped') {
                $summary['skipped']++;
                $rows[] = [
                    'row_id' => (int) $row['id'],
                    'notice_number' => aviso_import_seed_review_data($row)['notice_number'] ?? ($row['detected_notice_number'] ?? null),
                    'status' => 'skipped',
                    'pass' => true,
                    'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => 0],
                    'checks' => [],
                ];
                continue;
            }
            $summary['rows']++;
            $action = (string) ($row['review_action'] ?? 'import_new');
            $validation = aviso_import_validate_created_result(
                $connection,
                (int) ($row['imported_desembarque_id'] ?? 0),
                isset($row['imported_version_id']) && $row['imported_version_id'] !== null ? (int) $row['imported_version_id'] : null,
                (int) $row['id'],
                $action
            );
            $noticeData = aviso_import_seed_review_data($row);
            $rowStatus = $validation['pass'] ? ((int) ($validation['summary']['warn'] ?? 0) > 0 ? 'warn' : 'pass') : 'fail';
            $summary[$rowStatus]++;
            $summary['checks'] += (int) ($validation['summary']['total'] ?? 0);
            $rows[] = array_merge($validation, [
                'row_id' => (int) $row['id'],
                'notice_number' => $noticeData['notice_number'] ?? ($row['detected_notice_number'] ?? null),
                'status' => $rowStatus,
            ]);
        }
    }

    $counts = aviso_import_recalculate_batch($connection, $batchId);
    $pending = (int) ($counts['total_rows'] ?? 0) - (int) ($counts['imported_rows'] ?? 0) - (int) ($counts['skipped_rows'] ?? 0);
    $batchChecks = [
        [
            'code' => 'batch_processed_rows',
            'label' => 'Filas del lote procesadas',
            'status' => $pending === 0 ? 'pass' : 'warn',
            'detail' => $pending === 0 ? 'Todas las filas del lote fueron procesadas.' : $pending . ' fila(s) siguen pendientes de revisión/importación.',
        ],
        [
            'code' => 'batch_failed_rows',
            'label' => 'Sin filas con error',
            'status' => (int) ($counts['failed_rows'] ?? 0) === 0 ? 'pass' : 'fail',
            'detail' => 'Errores registrados: ' . (int) ($counts['failed_rows'] ?? 0),
        ],
    ];

    $pass = $summary['fail'] === 0 && (int) ($counts['failed_rows'] ?? 0) === 0;
    return [
        'pass' => $pass,
        'batch' => [
            'public_id' => (string) ($batch['public_id'] ?? ''),
            'client_id' => (int) ($batch['client_id'] ?? 0),
            'status' => (string) ($counts['status'] ?? ($batch['status'] ?? '')),
            'counts' => $counts,
        ],
        'summary' => $summary,
        'batch_checks' => $batchChecks,
        'rows' => $rows,
        'validated_at' => date('c'),
    ];
}
