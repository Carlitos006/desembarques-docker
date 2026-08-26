<?php

declare(strict_types=1);

require_once __DIR__ . '/_alcance.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_require_user();
aviso_require_internal_user($user);

if (! validate_csrf_token(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
    aviso_json(419, ['success' => false, 'message' => 'El token de seguridad no es válido.']);
}

$alcanceIdRaw = trim((string) ($_POST['alcance_id'] ?? ''));
if ($alcanceIdRaw === '' || ! ctype_digit($alcanceIdRaw) || (int) $alcanceIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'El Alcance indicado no es válido.']);
}
$alcanceId = (int) $alcanceIdRaw;
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
    $storedFile = store_uploaded_file($_FILES['pdf'], $metadata, $pdfConfig);
    $pdfHash = hash_file('sha256', (string) $storedFile['storage_path']);
    if (! is_string($pdfHash) || $pdfHash === '') {
        throw new RuntimeException('No fue posible calcular la huella del PDF.');
    }
} catch (Throwable $exception) {
    aviso_json(422, ['success' => false, 'message' => $exception->getMessage()]);
}

$connection = getDatabaseConnection();
$storedName = (string) ($storedFile['stored_name'] ?? '');

try {
    $connection->begin_transaction();
    $alcance = alcance_load($connection, $alcanceId, true);
    if (! $alcance) {
        throw new RuntimeException('No se encontró el Alcance.');
    }
    $desembarqueId = (int) ($alcance['desembarque_id'] ?? 0);
    aviso_require_record_access($connection, $desembarqueId, $user);

    if ((string) ($alcance['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('El Alcance está cancelado y no puede emitir una nueva versión.');
    }

    $versionResult = $connection->execute_query(
        'SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version FROM desembarque_aviso_alcance_versions WHERE alcance_id = ?',
        [$alcanceId]
    );
    $versionRow = $versionResult instanceof mysqli_result ? $versionResult->fetch_assoc() : null;
    $versionNo = max(1, (int) ($versionRow['next_version'] ?? 1));

    $snapshot = alcance_build_snapshot($connection, $alcanceId);
    $encoded = alcance_encode_snapshot($snapshot);
    $items = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
    $photos = is_array($snapshot['photos'] ?? null) ? $snapshot['photos'] : [];
    $baseName = (string) (($alcance['notice_number'] ?? '') ?: ($alcance['document_code'] ?? '') ?: $alcanceId);
    $filename = 'alcance-aviso-' . alcance_safe_component($baseName)
        . '-a' . str_pad((string) (int) ($alcance['alcance_no'] ?? 1), 2, '0', STR_PAD_LEFT)
        . '-v' . str_pad((string) $versionNo, 3, '0', STR_PAD_LEFT) . '.pdf';

    $connection->execute_query(
        'INSERT INTO desembarque_aviso_alcance_versions '
        . '(alcance_id, version_no, item_count, photo_count, page_count, original_name, stored_name, mime_type, size, pdf_sha256, '
        . 'snapshot_json, snapshot_sha256, snapshot_hash_version, generated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $alcanceId, $versionNo, count($items), count($photos), $pageCount, $filename, $storedName,
            'application/pdf', (int) $storedFile['size'], strtolower($pdfHash), $encoded['json'], $encoded['sha256'], 1, (int) $user['id'],
        ]
    );
    $versionId = (int) $connection->insert_id;

    // Una nueva versión requiere un nuevo acuse de autoridad; el Alcance vuelve a EMITIDO.
    $connection->execute_query('UPDATE desembarque_aviso_alcances SET status = ? WHERE id = ?', ['issued', $alcanceId]);

    record_audit_log(
        'create',
        'aviso_alcance_version',
        (string) $versionId,
        [
            'desembarque_id' => $desembarqueId,
            'alcance_id' => $alcanceId,
            'alcance_no' => (int) ($alcance['alcance_no'] ?? 0),
            'version_no' => $versionNo,
            'item_count' => count($items),
            'photo_count' => count($photos),
            'page_count' => $pageCount,
            'pdf_sha256' => strtolower($pdfHash),
        ],
        (int) $user['id'],
        $connection
    );

    $connection->commit();
    aviso_json(201, [
        'success' => true,
        'message' => 'La nueva versión del Alcance se archivó correctamente.',
        'version' => [
            'id' => $versionId,
            'version_no' => $versionNo,
            'download_url' => '../api/desembarques/aviso/alcance_version_download.php?id=' . $versionId,
        ],
    ]);
} catch (Throwable $exception) {
    $connection->rollback();
    if ($storedName !== '') {
        delete_stored_file($storedName, $pdfConfig);
    }
    error_log('[alcance-version] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible archivar la nueva versión del Alcance.']);
}
