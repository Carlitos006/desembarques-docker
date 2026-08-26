<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_import_require_internal();
$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (! is_array($payload)) {
    aviso_json(400, ['success' => false, 'message' => 'La solicitud no contiene JSON válido.']);
}
aviso_import_validate_csrf(isset($payload['csrf_token']) ? (string) $payload['csrf_token'] : null);

$publicId = trim((string) ($payload['batch'] ?? ''));
$rowIdsInput = isset($payload['row_ids']) && is_array($payload['row_ids']) ? $payload['row_ids'] : [];
$rowIds = [];
foreach ($rowIdsInput as $value) {
    $text = trim((string) $value);
    if (ctype_digit($text) && (int) $text > 0) {
        $rowIds[(int) $text] = (int) $text;
    }
}
$rowIds = array_values($rowIds);
if (! preg_match('/^[a-f0-9]{32}$/', $publicId) || $rowIds === [] || count($rowIds) > 50) {
    aviso_json(422, ['success' => false, 'message' => 'Selecciona uno o más avisos revisados para importar.']);
}

$connection = getDatabaseConnection();
$batchResult = $connection->execute_query(
    'SELECT b.*, c.name AS client_name, c.email AS client_email FROM aviso_import_batches b '
    . 'INNER JOIN clients c ON c.id = b.client_id WHERE b.public_id = ? LIMIT 1',
    [$publicId]
);
$batch = $batchResult instanceof mysqli_result ? $batchResult->fetch_assoc() : null;
if (! $batch) {
    aviso_json(404, ['success' => false, 'message' => 'No se encontró el lote histórico.']);
}

/** @return string */
function historical_import_original_name(mixed $value, string $notice): string
{
    $name = basename(trim((string) $value));
    $name = str_replace(["\0", '/', '\\'], ['', '-', '-'], $name);
    if ($name === '' || strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
        $name = 'AVISO-DESEMBARQUE-' . ($notice !== '' ? $notice : 'historico') . '.pdf';
    }
    return function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255);
}

/** @return array<int,array<string,mixed>> */
function historical_import_pedimentos(array $data): array
{
    $pedimentos = isset($data['pedimentos']) && is_array($data['pedimentos']) ? $data['pedimentos'] : [];
    $result = [];
    foreach ($pedimentos as $pedimento) {
        if (! is_array($pedimento)) {
            continue;
        }
        $result[] = [
            'key' => aviso_import_text($pedimento['key'] ?? null, 30),
            'number' => aviso_import_pedimento_number($pedimento['number'] ?? null),
            'importer_name' => aviso_import_text($pedimento['importer_name'] ?? null, 255),
        ];
    }
    return $result;
}

function historical_import_item_pedimentos(array $item): array
{
    return aviso_import_normalize_item_pedimentos(
        $item['pedimentos'] ?? [],
        aviso_import_text($item['key'] ?? null, 30),
        aviso_import_pedimento_number($item['pedimento'] ?? null),
        aviso_import_text($item['importer_name'] ?? null, 255)
    );
}

function historical_import_importer_for_item(array $item, array $pedimentos): ?string
{
    $explicit = aviso_import_text($item['importer_name'] ?? null, 255);
    if ($explicit !== null) {
        return $explicit;
    }
    $relations = historical_import_item_pedimentos($item);
    foreach ($relations as $relation) {
        $key = aviso_import_upper((string) ($relation['key'] ?? ''));
        $digits = aviso_import_pedimento_digits((string) ($relation['number'] ?? ''));
        foreach ($pedimentos as $pedimento) {
            if (! is_array($pedimento)) {
                continue;
            }
            if (aviso_import_upper((string) ($pedimento['key'] ?? '')) === $key
                && aviso_import_pedimento_digits((string) ($pedimento['number'] ?? '')) === $digits) {
                $name = aviso_import_text($pedimento['importer_name'] ?? null, 255);
                if ($name !== null) {
                    return $name;
                }
            }
        }
    }
    return count($pedimentos) === 1 ? aviso_import_text($pedimentos[0]['importer_name'] ?? null, 255) : null;
}

/** @return array<string,mixed> */
function historical_import_snapshot(
    array $batch,
    array $row,
    array $data,
    int $desembarqueId,
    string $reference,
    int $operationalStatusId,
    ?int $profileId,
    string $avisoStatus,
    array $counts
): array {
    return [
        'schema_version' => 2,
        'source_type' => 'historical_import',
        'import' => [
            'batch_public_id' => (string) $batch['public_id'],
            'row_id' => (int) $row['id'],
            'source_pdf_name' => (string) ($row['source_pdf_name'] ?? ''),
            'source_pdf_sha256' => (string) ($row['source_pdf_sha256'] ?? ''),
            'source_docx_name' => (string) ($row['source_docx_name'] ?? ''),
            'extraction_source' => (string) ($row['extraction_source'] ?? ''),
            'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
        ],
        'desembarque' => [
            'id' => $desembarqueId,
            'referencia' => $reference,
            'client_id' => (int) $batch['client_id'],
            'client_name' => (string) $batch['client_name'],
            'status_id' => $operationalStatusId,
        ],
        'aviso' => array_merge($data, [
            'profile_id' => $profileId,
            'aviso_status' => $avisoStatus,
        ]),
        'summary' => $counts,
    ];
}

/** @return array{history_ids:list<int>} */
function historical_import_apply_status(
    mysqli $connection,
    int $desembarqueId,
    string $targetStatus,
    ?string $effectiveAt,
    ?string $reason,
    string $officeDate,
    int $versionId,
    array $user
): array {
    $targetStatus = aviso_status_normalize($targetStatus);
    if (! in_array($targetStatus, ['issued', 'presented', 'replaced', 'cancelled'], true)) {
        $targetStatus = 'issued';
    }
    $actorId = (int) $user['id'];
    $issuedAt = $officeDate . ' 00:00:00';
    $effectiveAt = aviso_import_datetime($effectiveAt) ?? $issuedAt;
    $reason = aviso_import_text($reason, 1000);
    $transitions = [[
        'from' => 'draft',
        'to' => 'issued',
        'reason' => 'Importación histórica: el PDF original fue archivado como versión v001.',
        'effective_at' => $issuedAt,
        'version_id' => $versionId,
    ]];
    if ($targetStatus !== 'issued') {
        $transitionReason = $reason;
        if ($targetStatus === 'presented' && $transitionReason === null) {
            $transitionReason = 'Importación histórica: aviso registrado como presentado.';
        }
        if ($transitionReason === null) {
            $transitionReason = 'Importación histórica: estado documental confirmado durante la revisión.';
        }
        $transitions[] = [
            'from' => 'issued',
            'to' => $targetStatus,
            'reason' => $transitionReason,
            'effective_at' => $effectiveAt,
            'version_id' => null,
        ];
    }

    $historyIds = [];
    foreach ($transitions as $transition) {
        $connection->execute_query(
            'INSERT INTO desembarque_aviso_status_history '
            . '(desembarque_id, from_status, to_status, reason, effective_at, source, aviso_version_id, changed_by) '
            . 'VALUES (?,?,?,?,?,?,?,?)',
            [
                $desembarqueId,
                $transition['from'],
                $transition['to'],
                $transition['reason'],
                $transition['effective_at'],
                'historical_import',
                $transition['version_id'],
                $actorId,
            ]
        );
        $historyIds[] = (int) $connection->insert_id;
        record_audit_log(
            'update',
            'aviso_status',
            (string) $desembarqueId,
            [
                'desembarque_id' => $desembarqueId,
                'from_status' => $transition['from'],
                'to_status' => $transition['to'],
                'reason' => $transition['reason'],
                'effective_at' => $transition['effective_at'],
                'source' => 'historical_import',
                'aviso_version_id' => $transition['version_id'],
            ],
            $actorId,
            $connection
        );
    }

    $finalEffectiveAt = $targetStatus === 'issued' ? $issuedAt : $effectiveAt;
    $connection->execute_query(
        'UPDATE desembarque_aviso_details SET aviso_status = ?, aviso_status_effective_at = ?, '
        . 'aviso_status_changed_at = ?, aviso_status_changed_by = ? WHERE desembarque_id = ? LIMIT 1',
        [$targetStatus, $finalEffectiveAt, date('Y-m-d H:i:s'), $actorId, $desembarqueId]
    );
    return ['history_ids' => $historyIds];
}

/** @return array{desembarque_id:int,version_id:int,reference:string} */
function historical_import_new_record(
    mysqli $connection,
    array $batch,
    array $row,
    array $data,
    array $user,
    int $operationalStatusId,
    array &$copiedFiles
): array {
    $notice = (string) ($data['notice_number'] ?? '');
    $officeDate = (string) ($data['office_date'] ?? '');
    $landingDateTime = (string) ($data['landing_datetime'] ?? '');
    $landingDate = substr($landingDateTime, 0, 10);
    if (aviso_import_date($landingDate) === null) {
        $landingDate = $officeDate;
    }
    if (aviso_import_date($landingDate) === null) {
        throw new RuntimeException('El aviso ' . $notice . ' no tiene una fecha válida para crear el desembarque.');
    }

    $duplicate = aviso_import_detect_duplicate(
        $connection,
        (int) $batch['client_id'],
        $notice,
        (string) ($row['source_pdf_sha256'] ?? '')
    );
    if ($duplicate['desembarque_id'] !== null) {
        $overrideDeletedId = isset($row['duplicate_desembarque_id']) && $row['duplicate_desembarque_id'] !== null
            ? (int) $row['duplicate_desembarque_id']
            : 0;
        $allowDeletedOverride = (string) ($row['review_action'] ?? '') === 'import_new_override_deleted'
            && in_array(strtolower((string) ($user['role'] ?? '')), ['admin', 'usuario'], true)
            && aviso_import_text($row['review_reason'] ?? null, 1000) !== null
            && ($duplicate['is_deleted'] ?? false) === true
            && ($duplicate['kind'] ?? null) === 'notice'
            && (int) ($duplicate['desembarque_id'] ?? 0) === $overrideDeletedId;
        if (! $allowDeletedOverride) {
            throw new RuntimeException('El aviso ' . $notice . ' se convirtió en duplicado antes del commit. Revisa nuevamente el lote.');
        }

        $activeDuplicateResult = $connection->execute_query(
            'SELECT d.desembarque_id FROM desembarque_aviso_details d '
            . 'INNER JOIN desembarques e ON e.id = d.desembarque_id '
            . 'WHERE e.client_id = ? AND d.notice_number = ? AND e.deleted_at IS NULL LIMIT 1 FOR UPDATE',
            [(int) $batch['client_id'], $notice]
        );
        if ($activeDuplicateResult instanceof mysqli_result && $activeDuplicateResult->fetch_assoc()) {
            throw new RuntimeException('Ya existe un aviso activo con el mismo número para este cliente.');
        }
    }

    $reference = aviso_import_next_reference(
        $connection,
        (int) $batch['client_id'],
        (string) $batch['client_name'],
        (string) $batch['client_email'],
        $officeDate
    );
    $counts = aviso_import_item_counts($data);
    $pedimentos = historical_import_pedimentos($data);
    $firstPedimento = $pedimentos[0]['number'] ?? null;
    $manifest = aviso_import_text($data['manifest'] ?? $notice, 100) ?? $notice;
    $description = sprintf(
        'Aviso histórico %s · %d %s de mercancía · %s %s',
        $notice,
        $counts['item_count'],
        $counts['item_count'] === 1 ? 'renglón' : 'renglones',
        rtrim(rtrim(number_format((float) $counts['piece_count'], 3, '.', ''), '0'), '.'),
        (float) $counts['piece_count'] === 1.0 ? 'pieza' : 'piezas'
    );
    $destination = aviso_import_text($data['storage_address'] ?? ($data['landing_place'] ?? null), 150) ?? 'Expediente histórico';
    $documentCode = aviso_import_text($data['document_code'] ?? null, 100) ?? ('MADE-' . $notice);
    $transport = aviso_import_text($data['transport_name'] ?? null, 150) ?? 'N/D';
    $clientName = aviso_import_text($batch['client_name'] ?? null, 150) ?? 'Cliente';

    $connection->execute_query(
        'INSERT INTO desembarques '
        . '(referencia, fecha_desembarque, descripcion, destino, folio_aviso, pedimento, cipl, manifiesto, fecha_embarque, barco, cliente, status_id, dias_transcurridos, dias_fuera, client_id, created_by) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $reference,
            $landingDate,
            $description,
            $destination,
            $documentCode,
            $firstPedimento,
            null,
            $manifest,
            null,
            $transport,
            $clientName,
            $operationalStatusId,
            0,
            0,
            (int) $batch['client_id'],
            (int) $user['id'],
        ]
    );
    $desembarqueId = (int) $connection->insert_id;
    $profileId = aviso_import_match_profile($connection, (int) $batch['client_id'], $data['rig_name'] ?? null, $data['rig_imo'] ?? null);
    $recipientTextParts = array_filter([
        aviso_import_text($data['recipient_name'] ?? null, 200),
        aviso_import_text($data['recipient_title'] ?? null, 255),
    ]);
    $recipientText = $recipientTextParts !== [] ? implode("\n", $recipientTextParts) : null;

    $connection->execute_query(
        'INSERT INTO desembarque_aviso_details ('
        . 'desembarque_id, aviso_profile_id, aviso_status, office_date, manifiesto, medio_transporte, imo_transporte, consignataria, '
        . 'fecha_embarque, lugar_desembarque, fecha_desembarque_eta, domicilio_almacenamiento, domicilio_reparacion, '
        . 'rig_name, rig_imo, rig_field, rig_area, campo, comitente, document_code, notice_number, recipient_text, signer_name, signer_title, '
        . 'source_type, historical_import_row_id, imported_by'
        . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $desembarqueId,
            $profileId,
            'draft',
            $officeDate,
            $manifest,
            aviso_import_text($data['transport_name'] ?? null, 255),
            aviso_import_text($data['transport_imo'] ?? null, 40),
            aviso_import_text($data['consignataria'] ?? null, 255),
            aviso_import_date($data['shipping_date'] ?? null),
            aviso_import_text($data['landing_place'] ?? null, 1200),
            aviso_import_datetime($data['landing_datetime'] ?? null),
            aviso_import_text($data['storage_address'] ?? null, 1200),
            aviso_import_text($data['repair_address'] ?? null, 1200),
            aviso_import_text($data['rig_name'] ?? null, 180),
            aviso_import_text($data['rig_imo'] ?? null, 40),
            aviso_import_text($data['rig_field'] ?? null, 120),
            aviso_import_text($data['rig_area'] ?? null, 120),
            aviso_import_text($data['rig_field'] ?? null, 100),
            aviso_import_text($data['comitente'] ?? null, 255),
            $documentCode,
            $notice,
            $recipientText,
            aviso_import_text($data['signer_name'] ?? null, 200),
            aviso_import_text($data['signer_title'] ?? null, 200),
            'historical_import',
            (int) $row['id'],
            (int) $user['id'],
        ]
    );

    $itemInsert = 'INSERT INTO desembarque_aviso_items '
        . '(desembarque_id, source_row, sort_order, item_no, unidad, descripcion, serial_number, marca, clave, num_pedimento, partida, cantidad, importer_name) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
    $itemPedimentoInsert = 'INSERT INTO desembarque_aviso_item_pedimentos '
        . '(aviso_item_id, clave, num_pedimento, importer_name, sort_order) VALUES (?,?,?,?,?)';
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    foreach ($items as $index => $item) {
        if (! is_array($item)) {
            continue;
        }
        $relations = historical_import_item_pedimentos($item);
        $legacy = count($relations) === 1 ? $relations[0] : null;
        $itemImporter = historical_import_importer_for_item($item, $pedimentos);
        $connection->execute_query($itemInsert, [
            $desembarqueId,
            $index + 1,
            $index + 1,
            (string) ($index + 1),
            null,
            aviso_import_text($item['description'] ?? null, 2000),
            aviso_import_text($item['serial_number'] ?? null, 1000),
            aviso_import_text($item['marca'] ?? null, 180),
            aviso_import_text($legacy['key'] ?? null, 30),
            aviso_import_pedimento_number($legacy['number'] ?? null),
            aviso_import_text($item['partida'] ?? null, 100),
            (float) ($item['quantity'] ?? 0),
            $itemImporter,
        ]);
        $savedItemId = (int) $connection->insert_id;
        foreach ($relations as $relationIndex => $relation) {
            $connection->execute_query($itemPedimentoInsert, [
                $savedItemId,
                aviso_import_text($relation['key'] ?? null, 30),
                aviso_import_pedimento_number($relation['number'] ?? null),
                aviso_import_text($relation['importer_name'] ?? null, 255) ?? $itemImporter,
                $relationIndex + 1,
            ]);
        }
    }

    foreach ($pedimentos as $pedimento) {
        $connection->execute_query(
            'INSERT INTO desembarque_pedimento_headers (desembarque_id, num_pedimento, cve_pedimento, razon_social, fecha_entrada, fecha_pago) VALUES (?,?,?,?,?,?)',
            [$desembarqueId, $pedimento['number'], $pedimento['key'], $pedimento['importer_name'], null, null]
        );
        $referenceValue = trim((string) ($pedimento['key'] ?? '') . ' · ' . (string) ($pedimento['number'] ?? ''), " ·");
        if ($referenceValue !== '') {
            $connection->execute_query('INSERT INTO desembarque_pedimentos (desembarque_id, reference) VALUES (?,?)', [$desembarqueId, $referenceValue]);
        }
    }
    if ($manifest !== '') {
        $connection->execute_query('INSERT INTO desembarque_manifests (desembarque_id, reference) VALUES (?,?)', [$desembarqueId, $manifest]);
    }

    $batchDir = aviso_import_batch_dir((string) $batch['public_id']);
    $storedTemp = basename((string) ($row['source_pdf_stored_name'] ?? ''));
    if ($storedTemp === '') {
        throw new RuntimeException('El aviso ' . $notice . ' no tiene PDF final para archivar.');
    }
    $copied = aviso_import_copy_historical_pdf(
        $batchDir . DIRECTORY_SEPARATOR . $storedTemp,
        (string) ($row['source_pdf_sha256'] ?? '')
    );
    $copiedFiles[] = $copied;

    $snapshot = historical_import_snapshot(
        $batch,
        $row,
        $data,
        $desembarqueId,
        $reference,
        $operationalStatusId,
        $profileId,
        (string) ($row['review_aviso_status'] ?? $batch['default_aviso_status'] ?? 'presented'),
        $counts
    );
    $snapshotJson = aviso_snapshot_canonical_json($snapshot);
    $snapshotSha = aviso_snapshot_hash($snapshot);
    $originalName = historical_import_original_name($row['source_pdf_name'] ?? null, $notice);

    $connection->execute_query(
        'INSERT INTO desembarque_aviso_versions ('
        . 'desembarque_id, version_no, aviso_profile_id, document_code, notice_number, document_date, source_type, source_import_row_id, '
        . 'source_excel_sha256, item_count, piece_count, photo_count, page_count, original_name, stored_name, mime_type, size, pdf_sha256, '
        . 'snapshot_json, snapshot_sha256, snapshot_hash_version, generated_by'
        . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $desembarqueId,
            1,
            $profileId,
            $documentCode,
            $notice,
            $officeDate,
            'historical_import',
            (int) $row['id'],
            null,
            (int) $counts['item_count'],
            (float) $counts['piece_count'],
            0,
            isset($row['source_pdf_page_count']) && $row['source_pdf_page_count'] !== null ? (int) $row['source_pdf_page_count'] : null,
            $originalName,
            $copied['stored_name'],
            'application/pdf',
            $copied['size'],
            $copied['sha256'],
            $snapshotJson,
            $snapshotSha,
            1,
            (int) $user['id'],
        ]
    );
    $versionId = (int) $connection->insert_id;

    historical_import_apply_status(
        $connection,
        $desembarqueId,
        (string) ($row['review_aviso_status'] ?? $batch['default_aviso_status'] ?? 'presented'),
        isset($row['review_status_effective_at']) ? (string) $row['review_status_effective_at'] : null,
        isset($row['review_reason']) ? (string) $row['review_reason'] : null,
        $officeDate,
        $versionId,
        $user
    );

    record_audit_log(
        'create',
        'historical_aviso_import',
        (string) $desembarqueId,
        [
            'batch_public_id' => (string) $batch['public_id'],
            'import_row_id' => (int) $row['id'],
            'desembarque_id' => $desembarqueId,
            'version_id' => $versionId,
            'notice_number' => $notice,
            'document_code' => $documentCode,
            'source_pdf_name' => $originalName,
            'pdf_sha256' => $copied['sha256'],
            'item_count' => (int) $counts['item_count'],
            'piece_count' => (float) $counts['piece_count'],
            'source_type' => 'historical_import',
            'deleted_duplicate_override' => (string) ($row['review_action'] ?? '') === 'import_new_override_deleted'
                ? [
                    'deleted_desembarque_id' => isset($row['duplicate_desembarque_id']) ? (int) $row['duplicate_desembarque_id'] : null,
                    'reason' => aviso_import_text($row['review_reason'] ?? null, 1000),
                ]
                : null,
        ],
        (int) $user['id'],
        $connection
    );

    return ['desembarque_id' => $desembarqueId, 'version_id' => $versionId, 'reference' => $reference];
}

/** @return array{desembarque_id:int,version_id:int,reference:string} */
function historical_import_link_version(
    mysqli $connection,
    array $batch,
    array $row,
    array $data,
    array $user,
    array &$copiedFiles
): array {
    $desembarqueId = (int) ($row['duplicate_desembarque_id'] ?? 0);
    if ($desembarqueId <= 0 || (string) ($row['duplicate_kind'] ?? '') === 'pdf_sha') {
        throw new RuntimeException('El PDF no puede vincularse como versión histórica del expediente existente.');
    }
    $existingResult = $connection->execute_query(
        'SELECT d.id, d.referencia, d.client_id FROM desembarques d WHERE d.id = ? AND d.client_id = ? AND d.deleted_at IS NULL LIMIT 1 FOR UPDATE',
        [$desembarqueId, (int) $batch['client_id']]
    );
    $existing = $existingResult instanceof mysqli_result ? $existingResult->fetch_assoc() : null;
    if (! $existing) {
        throw new RuntimeException('El expediente destino del duplicado ya no está disponible.');
    }
    $sha = (string) ($row['source_pdf_sha256'] ?? '');
    $hashResult = $connection->execute_query('SELECT id FROM desembarque_aviso_versions WHERE pdf_sha256 = ? LIMIT 1', [$sha]);
    if ($hashResult instanceof mysqli_result && $hashResult->fetch_assoc()) {
        throw new RuntimeException('El mismo PDF ya se encuentra archivado y no puede duplicarse.');
    }
    $nextResult = $connection->execute_query(
        'SELECT COALESCE(MAX(version_no),0)+1 AS next_version FROM desembarque_aviso_versions WHERE desembarque_id = ?',
        [$desembarqueId]
    );
    $nextRow = $nextResult instanceof mysqli_result ? $nextResult->fetch_assoc() : null;
    $versionNo = max(1, (int) ($nextRow['next_version'] ?? 1));
    $detailResult = $connection->execute_query('SELECT aviso_profile_id, document_code, notice_number FROM desembarque_aviso_details WHERE desembarque_id = ? LIMIT 1', [$desembarqueId]);
    $detail = $detailResult instanceof mysqli_result ? $detailResult->fetch_assoc() : [];
    $notice = aviso_import_text($data['notice_number'] ?? ($detail['notice_number'] ?? null), 100) ?? '';
    $documentCode = aviso_import_text($data['document_code'] ?? ($detail['document_code'] ?? null), 100) ?? ($notice !== '' ? 'MADE-' . $notice : null);
    $officeDate = aviso_import_date($data['office_date'] ?? null);
    $counts = aviso_import_item_counts($data);

    $batchDir = aviso_import_batch_dir((string) $batch['public_id']);
    $storedTemp = basename((string) ($row['source_pdf_stored_name'] ?? ''));
    $copied = aviso_import_copy_historical_pdf($batchDir . DIRECTORY_SEPARATOR . $storedTemp, $sha);
    $copiedFiles[] = $copied;

    $snapshot = [
        'schema_version' => 2,
        'source_type' => 'historical_import_link',
        'import' => [
            'batch_public_id' => (string) $batch['public_id'],
            'row_id' => (int) $row['id'],
            'source_pdf_name' => (string) ($row['source_pdf_name'] ?? ''),
            'source_pdf_sha256' => $sha,
        ],
        'existing_desembarque_id' => $desembarqueId,
        'reviewed_data' => $data,
        'summary' => $counts,
    ];
    $snapshotJson = aviso_snapshot_canonical_json($snapshot);
    $snapshotSha = aviso_snapshot_hash($snapshot);
    $originalName = historical_import_original_name($row['source_pdf_name'] ?? null, $notice);

    $connection->execute_query(
        'INSERT INTO desembarque_aviso_versions ('
        . 'desembarque_id, version_no, aviso_profile_id, document_code, notice_number, document_date, source_type, source_import_row_id, '
        . 'source_excel_sha256, item_count, piece_count, photo_count, page_count, original_name, stored_name, mime_type, size, pdf_sha256, '
        . 'snapshot_json, snapshot_sha256, snapshot_hash_version, generated_by'
        . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $desembarqueId,
            $versionNo,
            isset($detail['aviso_profile_id']) && $detail['aviso_profile_id'] !== null ? (int) $detail['aviso_profile_id'] : null,
            $documentCode,
            $notice,
            $officeDate,
            'historical_import',
            (int) $row['id'],
            null,
            (int) $counts['item_count'],
            (float) $counts['piece_count'],
            0,
            isset($row['source_pdf_page_count']) && $row['source_pdf_page_count'] !== null ? (int) $row['source_pdf_page_count'] : null,
            $originalName,
            $copied['stored_name'],
            'application/pdf',
            $copied['size'],
            $copied['sha256'],
            $snapshotJson,
            $snapshotSha,
            1,
            (int) $user['id'],
        ]
    );
    $versionId = (int) $connection->insert_id;
    record_audit_log(
        'create',
        'aviso_version',
        (string) $versionId,
        [
            'desembarque_id' => $desembarqueId,
            'version_no' => $versionNo,
            'source_type' => 'historical_import',
            'import_row_id' => (int) $row['id'],
            'pdf_sha256' => $copied['sha256'],
        ],
        (int) $user['id'],
        $connection
    );
    return ['desembarque_id' => $desembarqueId, 'version_id' => $versionId, 'reference' => (string) ($existing['referencia'] ?? '')];
}

$copiedFiles = [];
$results = [];
$connection->begin_transaction();
try {
    $batchLockResult = $connection->execute_query(
        'SELECT b.*, c.name AS client_name, c.email AS client_email FROM aviso_import_batches b '
        . 'INNER JOIN clients c ON c.id = b.client_id WHERE b.id = ? LIMIT 1 FOR UPDATE',
        [(int) $batch['id']]
    );
    $batch = $batchLockResult instanceof mysqli_result ? $batchLockResult->fetch_assoc() : null;
    if (! $batch) {
        throw new RuntimeException('El lote dejó de estar disponible.');
    }
    $clientLock = $connection->execute_query('SELECT id FROM clients WHERE id = ? FOR UPDATE', [(int) $batch['client_id']]);
    if (! ($clientLock instanceof mysqli_result) || ! $clientLock->fetch_assoc()) {
        throw new RuntimeException('El cliente del lote dejó de estar disponible.');
    }

    $operationalStatusId = isset($batch['default_operational_status_id']) && $batch['default_operational_status_id'] !== null
        ? (int) $batch['default_operational_status_id']
        : 0;
    if ($operationalStatusId <= 0) {
        $statusResult = $connection->query("SELECT id FROM desembarque_statuses WHERE is_active = 1 ORDER BY (slug='completed') DESC, is_default DESC, id ASC LIMIT 1");
        $statusRow = $statusResult instanceof mysqli_result ? $statusResult->fetch_assoc() : null;
        $operationalStatusId = $statusRow ? (int) $statusRow['id'] : 0;
    }
    $statusCheck = $connection->execute_query('SELECT id FROM desembarque_statuses WHERE id = ? AND is_active = 1 LIMIT 1', [$operationalStatusId]);
    if ($operationalStatusId <= 0 || ! ($statusCheck instanceof mysqli_result) || ! $statusCheck->fetch_assoc()) {
        throw new RuntimeException('El lote no tiene un estado operativo válido.');
    }

    $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
    $params = array_merge([(int) $batch['id']], $rowIds);
    $rowsResult = $connection->execute_query(
        'SELECT * FROM aviso_import_rows WHERE batch_id = ? AND id IN (' . $placeholders . ') ORDER BY id ASC FOR UPDATE',
        $params
    );
    $rows = [];
    if ($rowsResult instanceof mysqli_result) {
        while ($row = $rowsResult->fetch_assoc()) {
            $rows[(int) $row['id']] = $row;
        }
    }
    if (count($rows) !== count($rowIds)) {
        throw new RuntimeException('Una o más filas seleccionadas ya no pertenecen a este lote.');
    }

    foreach ($rowIds as $rowId) {
        $row = $rows[$rowId];
        if ((string) ($row['commit_status'] ?? 'pending') !== 'pending') {
            throw new RuntimeException('El aviso #' . $rowId . ' ya fue procesado anteriormente.');
        }
        if ((string) ($row['review_status'] ?? 'pending') !== 'ready') {
            throw new RuntimeException('El aviso #' . $rowId . ' todavía no está marcado como listo después de la revisión.');
        }
        if (empty($row['source_pdf_stored_name']) || empty($row['source_pdf_sha256'])) {
            throw new RuntimeException('El aviso #' . $rowId . ' no tiene PDF final disponible.');
        }
        $data = aviso_import_seed_review_data($row);
        $action = (string) ($row['review_action'] ?? 'import_new');
        $duplicateId = isset($row['duplicate_desembarque_id']) && $row['duplicate_desembarque_id'] !== null
            ? (int) $row['duplicate_desembarque_id']
            : 0;
        if ($action === 'import_new_override_deleted') {
            if (! in_array(strtolower((string) ($user['role'] ?? '')), ['admin', 'usuario'], true)) {
                throw new RuntimeException('Sólo un usuario interno puede autorizar la importación sobre un aviso eliminado.');
            }
            if ($duplicateId <= 0 || (string) ($row['duplicate_kind'] ?? '') !== 'notice') {
                throw new RuntimeException('La excepción administrativa no corresponde a un aviso eliminado válido.');
            }
            if (aviso_import_text($row['review_reason'] ?? null, 1000) === null) {
                throw new RuntimeException('La excepción administrativa requiere un motivo.');
            }
        }
        if ($duplicateId > 0 && $action !== 'skip') {
            $duplicateStateResult = $connection->execute_query(
                'SELECT deleted_at FROM desembarques WHERE id = ? LIMIT 1 FOR UPDATE',
                [$duplicateId]
            );
            $duplicateState = $duplicateStateResult instanceof mysqli_result ? $duplicateStateResult->fetch_assoc() : null;
            if (is_array($duplicateState) && ! empty($duplicateState['deleted_at'])) {
                if ($action !== 'import_new_override_deleted') {
                    throw new RuntimeException('Existe un expediente eliminado para este aviso. Debe restaurarse o un usuario interno debe autorizar la importación como un aviso nuevo.');
                }
            } elseif ($action === 'import_new_override_deleted') {
                throw new RuntimeException('El expediente duplicado ya no está eliminado. Recarga el lote antes de continuar.');
            }
        }
        $errors = aviso_import_review_errors(
            $data,
            (string) ($row['review_aviso_status'] ?? $batch['default_aviso_status'] ?? 'presented'),
            isset($row['review_status_effective_at']) ? (string) $row['review_status_effective_at'] : null,
            isset($row['review_reason']) ? (string) $row['review_reason'] : null,
            $action,
            isset($row['duplicate_kind']) ? (string) $row['duplicate_kind'] : null,
            isset($row['duplicate_desembarque_id']) && $row['duplicate_desembarque_id'] !== null ? (int) $row['duplicate_desembarque_id'] : null
        );
        if ($errors !== []) {
            throw new RuntimeException('El aviso ' . ((string) ($data['notice_number'] ?? '#' . $rowId)) . ' requiere revisión: ' . implode(' ', $errors));
        }

        if ($action === 'skip') {
            $connection->execute_query(
                'UPDATE aviso_import_rows SET commit_status = ?, imported_at = ?, import_error = NULL WHERE id = ? LIMIT 1',
                ['skipped', date('Y-m-d H:i:s'), $rowId]
            );
            $results[] = ['row_id' => $rowId, 'action' => 'skipped'];
            continue;
        }

        if ($action === 'link_version') {
            $created = historical_import_link_version($connection, $batch, $row, $data, $user, $copiedFiles);
        } else {
            $created = historical_import_new_record($connection, $batch, $row, $data, $user, $operationalStatusId, $copiedFiles);
        }

        // Phase 5E5: acceptance gate inside the same transaction. A historical import is
        // not committed if the newly created expediente/version fails structural or
        // file-integrity checks. This keeps large batch imports fail-closed.
        $validation = aviso_import_validate_created_result(
            $connection,
            (int) $created['desembarque_id'],
            (int) $created['version_id'],
            $rowId,
            $action
        );
        if (! $validation['pass']) {
            $failedChecks = [];
            foreach ($validation['checks'] as $check) {
                if ((string) ($check['status'] ?? '') === 'fail') {
                    $failedChecks[] = (string) ($check['label'] ?? $check['code'] ?? 'Validación');
                }
            }
            throw new RuntimeException(
                'Validación 5E5 fallida para el aviso '
                . ((string) ($data['notice_number'] ?? '#' . $rowId))
                . ': ' . implode(', ', $failedChecks)
            );
        }

        $connection->execute_query(
            'UPDATE aviso_import_rows SET commit_status = ?, imported_desembarque_id = ?, imported_version_id = ?, imported_at = ?, import_error = NULL WHERE id = ? LIMIT 1',
            ['imported', $created['desembarque_id'], $created['version_id'], date('Y-m-d H:i:s'), $rowId]
        );
        $results[] = [
            'row_id' => $rowId,
            'action' => $action,
            'desembarque_id' => $created['desembarque_id'],
            'version_id' => $created['version_id'],
            'reference' => $created['reference'],
            'expediente_url' => 'aviso-expediente.php?id=' . $created['desembarque_id'],
            'validation' => $validation['summary'],
        ];
    }

    $counts = aviso_import_recalculate_batch($connection, (int) $batch['id']);
    $connection->execute_query(
        'UPDATE aviso_import_batches SET committed_by = ?, committed_at = ? WHERE id = ? LIMIT 1',
        [(int) $user['id'], date('Y-m-d H:i:s'), (int) $batch['id']]
    );
    record_audit_log(
        'create',
        'aviso_import_commit',
        (string) $batch['id'],
        [
            'batch_public_id' => $publicId,
            'rows_requested' => $rowIds,
            'results' => $results,
            'counts' => $counts,
        ],
        (int) $user['id'],
        $connection
    );
    $connection->commit();
} catch (Throwable $exception) {
    try {
        $connection->rollback();
    } catch (Throwable) {
    }
    foreach ($copiedFiles as $copied) {
        if (is_array($copied) && isset($copied['storage_path']) && is_string($copied['storage_path'])) {
            @unlink($copied['storage_path']);
        }
    }
    error_log('[historical-import-commit] ' . $exception->getMessage());
    aviso_json(409, [
        'success' => false,
        'message' => 'No se importó ningún aviso del grupo seleccionado. Corrige la revisión e intenta nuevamente.',
        'detail' => $exception->getMessage(),
    ]);
}

aviso_json(201, [
    'success' => true,
    'message' => 'Importación histórica completada. Los PDFs originales quedaron archivados como versiones inmutables.',
    'results' => $results,
    'batch_counts' => $counts ?? [],
]);
