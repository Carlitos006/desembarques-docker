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
$rowIdRaw = trim((string) ($payload['row_id'] ?? ''));
if (! preg_match('/^[a-f0-9]{32}$/', $publicId) || ! ctype_digit($rowIdRaw) || (int) $rowIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'La fila de revisión indicada no es válida.']);
}
$rowId = (int) $rowIdRaw;

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

$rowResult = $connection->execute_query(
    'SELECT * FROM aviso_import_rows WHERE id = ? AND batch_id = ? LIMIT 1',
    [$rowId, (int) $batch['id']]
);
$row = $rowResult instanceof mysqli_result ? $rowResult->fetch_assoc() : null;
if (! $row) {
    aviso_json(404, ['success' => false, 'message' => 'No se encontró el aviso dentro del lote.']);
}
if ((string) ($row['commit_status'] ?? 'pending') !== 'pending') {
    aviso_json(409, ['success' => false, 'message' => 'Este aviso ya fue procesado y no puede volver a revisarse.']);
}

$dataInput = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : aviso_import_seed_review_data($row);
$data = aviso_import_normalize_review_data($dataInput);
$avisoStatus = aviso_status_normalize((string) ($payload['aviso_status'] ?? ($row['review_aviso_status'] ?? $batch['default_aviso_status'] ?? 'presented')));
if (! in_array($avisoStatus, ['issued', 'presented', 'replaced', 'cancelled'], true)) {
    $avisoStatus = 'presented';
}
$effectiveAt = aviso_import_datetime($payload['effective_at'] ?? ($row['review_status_effective_at'] ?? null));
if ($avisoStatus === 'presented' && $effectiveAt === null && ($data['office_date'] ?? null) !== null) {
    $effectiveAt = (string) $data['office_date'] . ' 00:00:00';
}
$reason = aviso_import_text($payload['reason'] ?? ($row['review_reason'] ?? null), 1000);
$action = strtolower(trim((string) ($payload['action'] ?? ($row['review_action'] ?? 'import_new'))));
if (! in_array($action, ['import_new', 'import_new_override_deleted', 'skip', 'link_version'], true)) {
    $action = 'import_new';
}
if ($action === 'import_new_override_deleted' && ! in_array(strtolower((string) ($user['role'] ?? '')), ['admin', 'usuario'], true)) {
    aviso_json(403, ['success' => false, 'message' => 'Sólo un usuario interno puede autorizar un aviso nuevo cuando existe otro eliminado con el mismo número.']);
}

$duplicate = aviso_import_detect_duplicate(
    $connection,
    (int) $batch['client_id'],
    aviso_import_text($data['notice_number'] ?? null, 100),
    aviso_import_text($row['source_pdf_sha256'] ?? null, 64)
);

if ($duplicate['desembarque_id'] !== null && $action === 'import_new') {
    $action = 'skip';
}
if (($duplicate['is_deleted'] ?? false) === true && $action !== 'import_new_override_deleted') {
    $action = 'skip';
}
if ($action === 'import_new_override_deleted' && (
    ($duplicate['is_deleted'] ?? false) !== true
    || ($duplicate['kind'] ?? null) !== 'notice'
    || ($duplicate['desembarque_id'] ?? null) === null
)) {
    aviso_json(409, ['success' => false, 'message' => 'La excepción administrativa ya no es válida. Recarga el lote y revisa nuevamente el duplicado.']);
}
if ($duplicate['desembarque_id'] === null && $action === 'link_version') {
    $action = 'import_new';
}
if ($duplicate['kind'] === 'pdf_sha' && $action === 'link_version') {
    $action = 'skip';
}

$errors = aviso_import_review_errors(
    $data,
    $avisoStatus,
    $effectiveAt,
    $reason,
    $action,
    $duplicate['kind'],
    $duplicate['desembarque_id']
);
$reviewStatus = $errors === [] ? 'ready' : 'invalid';
$reviewJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$reviewedAt = date('Y-m-d H:i:s');

$connection->begin_transaction();
try {
    $connection->execute_query(
        'UPDATE aviso_import_rows SET review_data = ?, review_status = ?, review_aviso_status = ?, '
        . 'review_status_effective_at = ?, review_reason = ?, review_action = ?, reviewed_by = ?, reviewed_at = ?, '
        . 'detected_notice_number = ?, detected_document_code = ?, duplicate_desembarque_id = ?, duplicate_reason = ?, duplicate_kind = ?, import_error = NULL '
        . 'WHERE id = ? AND batch_id = ? LIMIT 1',
        [
            $reviewJson,
            $reviewStatus,
            $avisoStatus,
            $effectiveAt,
            $reason,
            $action,
            (int) $user['id'],
            $reviewedAt,
            $data['notice_number'],
            $data['document_code'],
            $duplicate['desembarque_id'],
            $duplicate['reason'],
            $duplicate['kind'],
            $rowId,
            (int) $batch['id'],
        ]
    );

    record_audit_log(
        'update',
        'aviso_import_row',
        (string) $rowId,
        [
            'batch_public_id' => $publicId,
            'notice_number' => $data['notice_number'],
            'review_status' => $reviewStatus,
            'review_action' => $action,
            'review_reason' => $reason,
            'deleted_duplicate_override' => $action === 'import_new_override_deleted',
            'aviso_status' => $avisoStatus,
            'duplicate_desembarque_id' => $duplicate['desembarque_id'],
            'validation_errors' => $errors,
            'item_counts' => aviso_import_item_counts($data),
        ],
        (int) $user['id'],
        $connection
    );

    $counts = aviso_import_recalculate_batch($connection, (int) $batch['id']);
    $connection->commit();
} catch (Throwable $exception) {
    try {
        $connection->rollback();
    } catch (Throwable) {
    }
    error_log('[historical-import-review] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible guardar la revisión del aviso.']);
}

$updatedResult = $connection->execute_query('SELECT * FROM aviso_import_rows WHERE id = ? LIMIT 1', [$rowId]);
$updated = $updatedResult instanceof mysqli_result ? $updatedResult->fetch_assoc() : null;

aviso_json($reviewStatus === 'ready' ? 200 : 422, [
    'success' => $reviewStatus === 'ready',
    'message' => $reviewStatus === 'ready'
        ? 'Revisión guardada. El aviso está listo para importarse.'
        : 'La revisión se guardó, pero todavía faltan datos antes de importar.',
    'errors' => $errors,
    'row' => $updated ? aviso_import_row_payload($connection, $updated) : null,
    'batch_counts' => $counts ?? [],
]);
