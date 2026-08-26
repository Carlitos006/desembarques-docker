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
$folio = aviso_clean_text($_POST['folio'] ?? null, 100);
if ($folio === null) {
    aviso_json(422, ['success' => false, 'message' => 'Captura el folio del acuse.']);
}
$receivedAt = alcance_clean_received_at($_POST['received_at'] ?? null);
if ($receivedAt === null) {
    aviso_json(422, ['success' => false, 'message' => 'Captura una fecha/hora de recibido válida.']);
}
$notes = aviso_clean_text($_POST['notes'] ?? null, 1000);
$correctionReason = aviso_clean_text($_POST['correction_reason'] ?? null, 1000);

$evidenceStored = null;
$evidenceConfig = file_storage_config();
$evidenceConfig['max_size'] = 20 * 1024 * 1024;
$evidenceConfig['max_files_per_request'] = 1;
$evidenceConfig['allowed_extensions'] = ['pdf', 'jpg', 'jpeg', 'png'];
$evidenceConfig['allowed_mime_types'] = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
];

if (isset($_FILES['evidence']) && is_array($_FILES['evidence']) && (int) ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $metadata = validate_uploaded_file($_FILES['evidence'], $evidenceConfig);
        $evidenceStored = store_uploaded_file($_FILES['evidence'], $metadata, $evidenceConfig);
    } catch (Throwable $exception) {
        aviso_json(422, ['success' => false, 'message' => 'La evidencia del acuse no pudo procesarse: ' . $exception->getMessage()]);
    }
}

$connection = getDatabaseConnection();
$newEvidenceFileId = null;

try {
    $connection->begin_transaction();
    $alcance = alcance_load($connection, $alcanceId, true);
    if (! $alcance) {
        throw new RuntimeException('No se encontró el Alcance.');
    }
    $desembarqueId = (int) ($alcance['desembarque_id'] ?? 0);
    aviso_require_record_access($connection, $desembarqueId, $user);

    $versionResult = $connection->execute_query(
        'SELECT * FROM desembarque_aviso_alcance_versions WHERE alcance_id = ? ORDER BY version_no DESC, id DESC LIMIT 1 FOR UPDATE',
        [$alcanceId]
    );
    $latestVersion = $versionResult instanceof mysqli_result ? $versionResult->fetch_assoc() : null;
    if (! $latestVersion) {
        throw new RuntimeException('El Alcance todavía no tiene una versión PDF emitida.');
    }
    $versionId = (int) $latestVersion['id'];

    $receiptResult = $connection->execute_query(
        'SELECT * FROM desembarque_aviso_alcance_receipts WHERE alcance_version_id = ? LIMIT 1 FOR UPDATE',
        [$versionId]
    );
    $existing = $receiptResult instanceof mysqli_result ? $receiptResult->fetch_assoc() : null;
    if ($existing && $correctionReason === null) {
        throw new RuntimeException('Indica el motivo de la corrección del acuse existente.');
    }

    if (is_array($evidenceStored)) {
        $sha256 = hash_file('sha256', (string) $evidenceStored['storage_path']);
        if (! is_string($sha256) || $sha256 === '') {
            throw new RuntimeException('No fue posible calcular la huella de la evidencia.');
        }
        $description = 'Acuse de autoridad del Alcance #' . (int) ($alcance['alcance_no'] ?? 0);
        $documentDate = substr($receivedAt, 0, 10);
        $connection->execute_query(
            'INSERT INTO desembarque_files '
            . '(desembarque_id, uploaded_by, original_name, stored_name, mime_type, extension, size, purpose, document_type, description, document_date, sha256, is_active) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)',
            [
                $desembarqueId,
                (int) $user['id'],
                (string) $evidenceStored['original_name'],
                (string) $evidenceStored['stored_name'],
                (string) $evidenceStored['mime_type'],
                (string) $evidenceStored['extension'],
                (int) $evidenceStored['size'],
                'alcance_receipt',
                'acuse',
                $description,
                $documentDate,
                strtolower($sha256),
            ]
        );
        $newEvidenceFileId = (int) $connection->insert_id;
    }

    if ($existing) {
        $evidenceFileId = $newEvidenceFileId ?: (isset($existing['evidence_file_id']) ? (int) $existing['evidence_file_id'] : null);
        $connection->execute_query(
            'UPDATE desembarque_aviso_alcance_receipts SET folio = ?, received_at = ?, notes = ?, evidence_file_id = ?, updated_by = ? WHERE id = ?',
            [$folio, $receivedAt, $notes, $evidenceFileId, (int) $user['id'], (int) $existing['id']]
        );
        $receiptId = (int) $existing['id'];
        $action = 'update';
    } else {
        $connection->execute_query(
            'INSERT INTO desembarque_aviso_alcance_receipts '
            . '(alcance_id, alcance_version_id, folio, received_at, notes, evidence_file_id, recorded_by, updated_by) '
            . 'VALUES (?,?,?,?,?,?,?,?)',
            [$alcanceId, $versionId, $folio, $receivedAt, $notes, $newEvidenceFileId, (int) $user['id'], (int) $user['id']]
        );
        $receiptId = (int) $connection->insert_id;
        $action = 'create';
    }

    $connection->execute_query('UPDATE desembarque_aviso_alcances SET status = ? WHERE id = ?', ['presented', $alcanceId]);

    record_audit_log(
        $action,
        'aviso_alcance_receipt',
        (string) $receiptId,
        [
            'desembarque_id' => $desembarqueId,
            'alcance_id' => $alcanceId,
            'alcance_no' => (int) ($alcance['alcance_no'] ?? 0),
            'alcance_version_id' => $versionId,
            'version_no' => (int) ($latestVersion['version_no'] ?? 0),
            'folio' => $folio,
            'received_at' => $receivedAt,
            'notes' => $notes,
            'evidence_file_id' => $newEvidenceFileId ?: ($existing['evidence_file_id'] ?? null),
            'correction_reason' => $correctionReason,
            'before' => $existing ?: null,
        ],
        (int) $user['id'],
        $connection
    );

    $connection->commit();
    aviso_json(200, [
        'success' => true,
        'message' => $existing ? 'El acuse del Alcance se corrigió correctamente.' : 'El acuse del Alcance se registró correctamente.',
        'receipt' => [
            'id' => $receiptId,
            'alcance_id' => $alcanceId,
            'version_id' => $versionId,
            'folio' => $folio,
            'received_at' => $receivedAt,
            'evidence_file_id' => $newEvidenceFileId ?: ($existing['evidence_file_id'] ?? null),
        ],
        'status' => 'presented',
    ]);
} catch (Throwable $exception) {
    $connection->rollback();
    if (is_array($evidenceStored)) {
        delete_stored_file((string) ($evidenceStored['stored_name'] ?? ''), $evidenceConfig);
    }
    error_log('[alcance-receipt] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible registrar el acuse del Alcance.']);
}
