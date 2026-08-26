<?php

declare(strict_types=1);

require_once __DIR__ . '/_alcance.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_require_user();
aviso_require_internal_user($user);

if (! validate_csrf_token(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
    aviso_json(419, ['success' => false, 'message' => 'El token de seguridad no es válido. Recarga la página e inténtalo de nuevo.']);
}

$desembarqueIdRaw = trim((string) ($_POST['desembarque_id'] ?? ''));
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw) || (int) $desembarqueIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
}
$desembarqueId = (int) $desembarqueIdRaw;

$alcanceDate = alcance_clean_date($_POST['alcance_date'] ?? null);
if ($alcanceDate === null) {
    aviso_json(422, ['success' => false, 'message' => 'Indica una fecha válida para el Alcance.']);
}
$notes = aviso_clean_text($_POST['notes'] ?? null, 2000);

$itemIdsDecoded = json_decode((string) ($_POST['item_ids'] ?? '[]'), true);
$itemIds = [];
if (is_array($itemIdsDecoded)) {
    foreach ($itemIdsDecoded as $itemId) {
        $value = (int) $itemId;
        if ($value > 0) {
            $itemIds[$value] = $value;
        }
    }
}
$itemIds = array_values($itemIds);
if ($itemIds === []) {
    aviso_json(422, ['success' => false, 'message' => 'Selecciona al menos una mercancía para el Alcance.']);
}

$pageCount = max(1, min(500, (int) ($_POST['page_count'] ?? 1)));
if (! isset($_FILES['pdf']) || ! is_array($_FILES['pdf'])) {
    aviso_json(422, ['success' => false, 'message' => 'No se recibió el PDF del Alcance.']);
}

$pdfConfig = file_storage_config();
$pdfConfig['max_size'] = 25 * 1024 * 1024;
$pdfConfig['max_files_per_request'] = 1;
$pdfConfig['allowed_extensions'] = ['pdf'];
$pdfConfig['allowed_mime_types'] = ['pdf' => ['application/pdf']];

try {
    $metadata = validate_uploaded_file($_FILES['pdf'], $pdfConfig);
    if (($metadata['extension'] ?? '') !== 'pdf') {
        throw new RuntimeException('El documento generado no es un PDF válido.');
    }
    $storedFile = store_uploaded_file($_FILES['pdf'], $metadata, $pdfConfig);
    $pdfHash = hash_file('sha256', (string) $storedFile['storage_path']);
    if (! is_string($pdfHash) || $pdfHash === '') {
        throw new RuntimeException('No fue posible calcular la huella del PDF del Alcance.');
    }
} catch (Throwable $exception) {
    aviso_json(422, ['success' => false, 'message' => $exception->getMessage()]);
}

$connection = getDatabaseConnection();
aviso_require_record_access($connection, $desembarqueId, $user);

try {
    $connection->begin_transaction();

    $lockResult = $connection->execute_query('SELECT id FROM desembarques WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE', [$desembarqueId]);
    if (! ($lockResult instanceof mysqli_result) || ! $lockResult->fetch_assoc()) {
        throw new RuntimeException('El desembarque dejó de estar disponible.');
    }

    $detailResult = $connection->execute_query(
        'SELECT document_code, notice_number, manifiesto, aviso_status FROM desembarque_aviso_details WHERE desembarque_id = ? LIMIT 1',
        [$desembarqueId]
    );
    $detail = $detailResult instanceof mysqli_result ? ($detailResult->fetch_assoc() ?: []) : [];
    if (strtolower(trim((string) ($detail['aviso_status'] ?? 'draft'))) === 'cancelled') {
        throw new RuntimeException('El Aviso está cancelado. Reábrelo antes de generar un Alcance.');
    }
    $documentCode = aviso_clean_text($detail['document_code'] ?? null, 100);
    $noticeNumber = aviso_clean_text($detail['notice_number'] ?? $detail['manifiesto'] ?? null, 100);

    $validatedItems = [];
    foreach ($itemIds as $itemId) {
        $itemResult = $connection->execute_query(
            'SELECT id, sort_order, descripcion FROM desembarque_aviso_items WHERE id = ? AND desembarque_id = ? LIMIT 1 FOR UPDATE',
            [$itemId, $desembarqueId]
        );
        $item = $itemResult instanceof mysqli_result ? $itemResult->fetch_assoc() : null;
        if (! $item) {
            throw new RuntimeException('Una de las mercancías seleccionadas ya no pertenece a este Aviso.');
        }
        $validatedItems[] = $item;
    }

    $nextResult = $connection->execute_query(
        'SELECT COALESCE(MAX(alcance_no), 0) + 1 AS next_no FROM desembarque_aviso_alcances WHERE desembarque_id = ?',
        [$desembarqueId]
    );
    $nextRow = $nextResult instanceof mysqli_result ? $nextResult->fetch_assoc() : null;
    $alcanceNo = max(1, (int) ($nextRow['next_no'] ?? 1));
    $publicId = alcance_public_id();

    $connection->execute_query(
        'INSERT INTO desembarque_aviso_alcances '
        . '(public_id, desembarque_id, alcance_no, document_code, notice_number, alcance_date, notes, status, created_by) '
        . 'VALUES (?,?,?,?,?,?,?,?,?)',
        [$publicId, $desembarqueId, $alcanceNo, $documentCode, $noticeNumber, $alcanceDate, $notes, 'issued', (int) $user['id']]
    );
    $alcanceId = (int) $connection->insert_id;

    usort($validatedItems, static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));
    foreach ($validatedItems as $index => $item) {
        $connection->execute_query(
            'INSERT INTO desembarque_aviso_alcance_items (alcance_id, aviso_item_id, sort_order) VALUES (?,?,?)',
            [$alcanceId, (int) $item['id'], $index]
        );
    }

    $snapshot = alcance_build_snapshot($connection, $alcanceId);
    $encoded = alcance_encode_snapshot($snapshot);
    $photoCount = count(is_array($snapshot['photos'] ?? null) ? $snapshot['photos'] : []);
    $itemCount = count($validatedItems);
    $baseName = $noticeNumber ?: ($documentCode ?: (string) $desembarqueId);
    $filename = 'alcance-aviso-' . alcance_safe_component($baseName)
        . '-a' . str_pad((string) $alcanceNo, 2, '0', STR_PAD_LEFT)
        . '-v001.pdf';

    $connection->execute_query(
        'INSERT INTO desembarque_aviso_alcance_versions '
        . '(alcance_id, version_no, item_count, photo_count, page_count, original_name, stored_name, mime_type, size, pdf_sha256, '
        . 'snapshot_json, snapshot_sha256, snapshot_hash_version, generated_by) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $alcanceId, 1, $itemCount, $photoCount, $pageCount, $filename,
            (string) $storedFile['stored_name'], 'application/pdf', (int) $storedFile['size'], strtolower($pdfHash),
            $encoded['json'], $encoded['sha256'], 1, (int) $user['id'],
        ]
    );
    $versionId = (int) $connection->insert_id;

    record_audit_log(
        'create',
        'aviso_alcance',
        (string) $alcanceId,
        [
            'desembarque_id' => $desembarqueId,
            'alcance_id' => $alcanceId,
            'alcance_no' => $alcanceNo,
            'document_code' => $documentCode,
            'notice_number' => $noticeNumber,
            'alcance_date' => $alcanceDate,
            'status' => 'issued',
            'item_ids' => array_map(static fn (array $item): int => (int) $item['id'], $validatedItems),
            'item_count' => $itemCount,
            'photo_count' => $photoCount,
            'version_id' => $versionId,
            'pdf_sha256' => strtolower($pdfHash),
        ],
        (int) $user['id'],
        $connection
    );

    $connection->commit();

    aviso_json(201, [
        'success' => true,
        'message' => 'El Alcance se generó y archivó correctamente.',
        'alcance' => [
            'id' => $alcanceId,
            'public_id' => $publicId,
            'alcance_no' => $alcanceNo,
            'status' => 'issued',
            'version_id' => $versionId,
            'download_url' => '../api/desembarques/aviso/alcance_version_download.php?id=' . $versionId,
        ],
    ]);
} catch (Throwable $exception) {
    $connection->rollback();
    delete_stored_file((string) ($storedFile['stored_name'] ?? ''), $pdfConfig);
    error_log('[alcance-generate] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible guardar el Alcance.']);
}
