<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../config/files.php';

/** @return array<string,mixed> */
function aviso_version_response_row(array $row): array
{
    $id = (int) ($row['id'] ?? 0);

    return [
        'id' => $id,
        'desembarque_id' => (int) ($row['desembarque_id'] ?? 0),
        'version_no' => (int) ($row['version_no'] ?? 0),
        'profile_id' => isset($row['aviso_profile_id']) && $row['aviso_profile_id'] !== null ? (int) $row['aviso_profile_id'] : null,
        'document_code' => (string) ($row['document_code'] ?? ''),
        'notice_number' => (string) ($row['notice_number'] ?? ''),
        'source_excel_sha256' => (string) ($row['source_excel_sha256'] ?? ''),
        'item_count' => (int) ($row['item_count'] ?? 0),
        'piece_count' => isset($row['piece_count']) ? (float) $row['piece_count'] : 0.0,
        'photo_count' => (int) ($row['photo_count'] ?? 0),
        'document_date' => (string) ($row['document_date'] ?? ''),
        'source_type' => (string) ($row['source_type'] ?? 'system_generated'),
        'is_historical' => (string) ($row['source_type'] ?? '') === 'historical_import',
        'page_count' => isset($row['page_count']) && $row['page_count'] !== null ? (int) $row['page_count'] : null,
        'original_name' => (string) ($row['original_name'] ?? ''),
        'size' => (int) ($row['size'] ?? 0),
        'pdf_sha256' => (string) ($row['pdf_sha256'] ?? ''),
        'snapshot_sha256' => (string) ($row['snapshot_sha256'] ?? ''),
        'generated_by' => isset($row['generated_by']) && $row['generated_by'] !== null ? (int) $row['generated_by'] : null,
        'generated_by_name' => (string) ($row['generated_by_name'] ?? ''),
        'generated_at' => (string) ($row['generated_at'] ?? ''),
        'download_url' => '../api/desembarques/aviso/version_download.php?id=' . $id,
    ];
}

function aviso_version_safe_component(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'documento';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }

    $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? '';
    $value = trim($value, '-_');

    return $value !== '' ? substr($value, 0, 120) : 'documento';
}

/** @return array<string,mixed> */
function aviso_version_build_snapshot(mysqli $connection, int $desembarqueId): array
{
    $recordResult = $connection->execute_query(
        'SELECT d.*, c.name AS client_name, c.email AS client_email '
        . 'FROM desembarques d LEFT JOIN clients c ON c.id = d.client_id WHERE d.id = ? AND d.deleted_at IS NULL LIMIT 1',
        [$desembarqueId]
    );
    $record = $recordResult instanceof mysqli_result ? $recordResult->fetch_assoc() : null;
    if (! $record) {
        throw new RuntimeException('No se encontró el desembarque al crear el snapshot del aviso.');
    }

    $detailResult = $connection->execute_query(
        'SELECT * FROM desembarque_aviso_details WHERE desembarque_id = ? LIMIT 1',
        [$desembarqueId]
    );
    $detail = $detailResult instanceof mysqli_result ? $detailResult->fetch_assoc() : null;
    if (! $detail) {
        throw new RuntimeException('El aviso no tiene datos estructurados para versionar.');
    }

    $itemsResult = $connection->execute_query(
        'SELECT id, source_row, sort_order, item_no, unidad, descripcion, serial_number, marca, clave, num_pedimento, partida, cantidad, importer_name '
        . 'FROM desembarque_aviso_items WHERE desembarque_id = ? ORDER BY sort_order ASC, id ASC',
        [$desembarqueId]
    );
    $items = [];
    if ($itemsResult instanceof mysqli_result) {
        while ($row = $itemsResult->fetch_assoc()) {
            $items[] = $row;
        }
    }

    $photosResult = $connection->execute_query(
        'SELECT ai.id, ai.file_id, ai.aviso_item_id, ai.caption, ai.sort_order, '
        . 'i.source_row AS item_source_row, i.descripcion AS item_description, '
        . 'f.original_name, f.stored_name, f.mime_type, f.extension, f.size '
        . 'FROM desembarque_aviso_images ai '
        . 'INNER JOIN desembarque_files f ON f.id = ai.file_id AND f.desembarque_id = ai.desembarque_id '
        . 'LEFT JOIN desembarque_aviso_items i ON i.id = ai.aviso_item_id AND i.desembarque_id = ai.desembarque_id '
        . 'WHERE ai.desembarque_id = ? ORDER BY ai.sort_order ASC, ai.id ASC',
        [$desembarqueId]
    );
    $photos = [];
    if ($photosResult instanceof mysqli_result) {
        while ($row = $photosResult->fetch_assoc()) {
            $photos[] = $row;
        }
    }

    $profile = null;
    $profileId = isset($detail['aviso_profile_id']) && $detail['aviso_profile_id'] !== null
        ? (int) $detail['aviso_profile_id']
        : 0;
    if ($profileId > 0) {
        $profileResult = $connection->execute_query(
            'SELECT * FROM aviso_profiles WHERE id = ? LIMIT 1',
            [$profileId]
        );
        $profile = $profileResult instanceof mysqli_result ? $profileResult->fetch_assoc() : null;
    }

    return [
        'schema_version' => 1,
        'desembarque' => $record,
        'aviso' => $detail,
        'profile' => $profile,
        'items' => $items,
        'photos' => $photos,
    ];
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$user = aviso_require_user();
$connection = getDatabaseConnection();

if ($method === 'GET') {
    $desembarqueIdRaw = trim((string) ($_GET['desembarque_id'] ?? ''));
    if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw)) {
        aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
    }

    $desembarqueId = (int) $desembarqueIdRaw;
    aviso_require_record_access($connection, $desembarqueId, $user);

    $result = $connection->execute_query(
        'SELECT v.*, u.name AS generated_by_name '
        . 'FROM desembarque_aviso_versions v '
        . 'LEFT JOIN users u ON u.id = v.generated_by '
        . 'WHERE v.desembarque_id = ? ORDER BY v.version_no DESC, v.id DESC',
        [$desembarqueId]
    );

    $versions = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $versions[] = aviso_version_response_row($row);
        }
    }

    aviso_json(200, [
        'success' => true,
        'versions' => $versions,
    ]);
}

if ($method !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

aviso_require_internal_user($user);

if (! validate_csrf_token(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
    aviso_json(419, ['success' => false, 'message' => 'El token de seguridad no es válido. Recarga la página e inténtalo de nuevo.']);
}

$desembarqueIdRaw = trim((string) ($_POST['desembarque_id'] ?? ''));
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw)) {
    aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
}
$desembarqueId = (int) $desembarqueIdRaw;
$record = aviso_require_record_access($connection, $desembarqueId, $user);

$preStatus = aviso_get_document_status($connection, $desembarqueId, false);
if ($preStatus['exists'] && ! aviso_status_can_generate((string) $preStatus['slug'])) {
    aviso_json(409, [
        'success' => false,
        'message' => 'El aviso está cancelado. Reábrelo a Borrador antes de emitir una nueva versión.',
    ]);
}

$pageCount = null;
$pageCountRaw = trim((string) ($_POST['page_count'] ?? ''));
if ($pageCountRaw !== '') {
    if (! ctype_digit($pageCountRaw)) {
        aviso_json(422, ['success' => false, 'message' => 'El número de páginas del PDF no es válido.']);
    }
    $pageCount = max(1, min(500, (int) $pageCountRaw));
}

if (! isset($_FILES['pdf']) || ! is_array($_FILES['pdf'])) {
    aviso_json(422, ['success' => false, 'message' => 'No se recibió el PDF generado.']);
}

$pdfConfig = file_storage_config();
$pdfConfig['max_size'] = 12 * 1024 * 1024;
$pdfConfig['max_files_per_request'] = 1;
$pdfConfig['allowed_extensions'] = ['pdf'];
$pdfConfig['allowed_mime_types'] = ['pdf' => ['application/pdf']];

$storedFile = null;

try {
    $metadata = validate_uploaded_file($_FILES['pdf'], $pdfConfig);
    if (($metadata['extension'] ?? '') !== 'pdf' || strtolower((string) ($metadata['mime_type'] ?? '')) !== 'application/pdf') {
        throw new RuntimeException('El archivo recibido no es un PDF válido.');
    }

    $storedFile = store_uploaded_file($_FILES['pdf'], $metadata, $pdfConfig);
    $pdfHash = hash_file('sha256', (string) $storedFile['storage_path']);
    if (! is_string($pdfHash) || ! preg_match('/^[a-f0-9]{64}$/', $pdfHash)) {
        throw new RuntimeException('No fue posible calcular la huella SHA-256 del PDF.');
    }

    $connection->begin_transaction();

    // Bloquea el expediente padre para serializar el número de versión incluso con dos emisiones simultáneas.
    $lockResult = $connection->execute_query(
        'SELECT id FROM desembarques WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
        [$desembarqueId]
    );
    if (! ($lockResult instanceof mysqli_result) || ! $lockResult->fetch_assoc()) {
        throw new RuntimeException('El desembarque dejó de estar disponible.');
    }

    $snapshot = aviso_version_build_snapshot($connection, $desembarqueId);
    $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $snapshotHash = hash('sha256', $snapshotJson);

    $versionResult = $connection->execute_query(
        'SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version FROM desembarque_aviso_versions WHERE desembarque_id = ?',
        [$desembarqueId]
    );
    $versionRow = $versionResult instanceof mysqli_result ? $versionResult->fetch_assoc() : null;
    $versionNo = max(1, (int) ($versionRow['next_version'] ?? 1));

    $detail = isset($snapshot['aviso']) && is_array($snapshot['aviso']) ? $snapshot['aviso'] : [];
    $profileId = isset($detail['aviso_profile_id']) && $detail['aviso_profile_id'] !== null ? (int) $detail['aviso_profile_id'] : null;
    $documentCode = aviso_clean_text($detail['document_code'] ?? null, 100);
    $noticeNumber = aviso_clean_text($detail['notice_number'] ?? null, 100);
    $sourceExcelHash = aviso_clean_text($detail['source_excel_sha256'] ?? null, 64);
    if ($sourceExcelHash !== null && ! preg_match('/^[a-f0-9]{64}$/i', $sourceExcelHash)) {
        $sourceExcelHash = null;
    }

    $items = isset($snapshot['items']) && is_array($snapshot['items']) ? $snapshot['items'] : [];
    $photos = isset($snapshot['photos']) && is_array($snapshot['photos']) ? $snapshot['photos'] : [];
    $itemCount = count($items);
    $pieceCount = 0.0;
    foreach ($items as $item) {
        if (is_array($item) && is_numeric($item['cantidad'] ?? null)) {
            $pieceCount += (float) $item['cantidad'];
        }
    }
    $pieceCount = round($pieceCount, 3);
    $photoCount = count($photos);
    $documentDate = aviso_clean_text($detail['office_date'] ?? null, 10);

    $nameSource = $noticeNumber ?: ($documentCode ?: (string) ($record['id'] ?? $desembarqueId));
    $versionFilename = 'aviso-desembarque-' . aviso_version_safe_component($nameSource)
        . '-v' . str_pad((string) $versionNo, 3, '0', STR_PAD_LEFT) . '.pdf';

    $connection->execute_query(
        'INSERT INTO desembarque_aviso_versions ('
        . 'desembarque_id, version_no, aviso_profile_id, document_code, notice_number, document_date, source_type, source_import_row_id, source_excel_sha256, '
        . 'item_count, piece_count, photo_count, page_count, original_name, stored_name, mime_type, size, pdf_sha256, '
        . 'snapshot_json, snapshot_sha256, generated_by'
        . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $desembarqueId,
            $versionNo,
            $profileId,
            $documentCode,
            $noticeNumber,
            $documentDate,
            'system_generated',
            null,
            $sourceExcelHash,
            $itemCount,
            $pieceCount,
            $photoCount,
            $pageCount,
            $versionFilename,
            $storedFile['stored_name'],
            'application/pdf',
            (int) $storedFile['size'],
            $pdfHash,
            $snapshotJson,
            $snapshotHash,
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
            'document_code' => $documentCode,
            'notice_number' => $noticeNumber,
            'item_count' => $itemCount,
            'piece_count' => $pieceCount,
            'photo_count' => $photoCount,
            'source_type' => 'system_generated',
            'page_count' => $pageCount,
            'pdf_sha256' => $pdfHash,
            'snapshot_sha256' => $snapshotHash,
            'source_excel_sha256' => $sourceExcelHash,
        ],
        (int) $user['id'],
        $connection
    );

    $statusTransition = aviso_apply_status_transition(
        $connection,
        $desembarqueId,
        'issued',
        $user,
        'Emisión automática del PDF v' . str_pad((string) $versionNo, 3, '0', STR_PAD_LEFT) . '.',
        date('Y-m-d H:i:s'),
        'version',
        $versionId
    );

    $connection->commit();

    $versionResult = $connection->execute_query(
        'SELECT v.*, u.name AS generated_by_name FROM desembarque_aviso_versions v '
        . 'LEFT JOIN users u ON u.id = v.generated_by WHERE v.id = ? LIMIT 1',
        [$versionId]
    );
    $version = $versionResult instanceof mysqli_result ? $versionResult->fetch_assoc() : null;

    aviso_json(201, [
        'success' => true,
        'message' => 'El PDF se archivó como una nueva versión inmutable del aviso.',
        'version' => $version ? aviso_version_response_row($version) : [
            'id' => $versionId,
            'desembarque_id' => $desembarqueId,
            'version_no' => $versionNo,
            'download_url' => '../api/desembarques/aviso/version_download.php?id=' . $versionId,
        ],
        'status' => aviso_status_response_payload($connection, $desembarqueId, $user),
        'status_transition' => $statusTransition,
    ]);
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        try {
            $connection->rollback();
        } catch (Throwable $rollbackException) {
            // No-op: el error original tiene prioridad.
        }
    }

    if (is_array($storedFile) && ! empty($storedFile['stored_name'])) {
        delete_stored_file((string) $storedFile['stored_name'], $pdfConfig);
    }

    error_log('[aviso-version] ' . $exception->getMessage());
    aviso_json(500, [
        'success' => false,
        'message' => 'El PDF se generó, pero no fue posible archivarlo en el historial de versiones.',
    ]);
}
