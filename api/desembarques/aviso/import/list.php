<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

aviso_import_require_internal();
$publicId = trim((string) ($_GET['batch'] ?? ''));
if (! preg_match('/^[a-f0-9]{32}$/', $publicId)) {
    aviso_json(422, ['success' => false, 'message' => 'El lote indicado no es válido.']);
}

$connection = getDatabaseConnection();
$batchResult = $connection->execute_query(
    'SELECT b.*, c.name AS client_name, c.email AS client_email, u.name AS created_by_name, '
    . 's.slug AS operational_status_slug, s.name_es AS operational_status_name_es, s.name_en AS operational_status_name_en '
    . 'FROM aviso_import_batches b '
    . 'INNER JOIN clients c ON c.id = b.client_id '
    . 'INNER JOIN users u ON u.id = b.created_by '
    . 'LEFT JOIN desembarque_statuses s ON s.id = b.default_operational_status_id '
    . 'WHERE b.public_id = ? LIMIT 1',
    [$publicId]
);
$batch = $batchResult instanceof mysqli_result ? $batchResult->fetch_assoc() : null;
if (! $batch) {
    aviso_json(404, ['success' => false, 'message' => 'No se encontró el lote de importación.']);
}

$counts = aviso_import_recalculate_batch($connection, (int) $batch['id']);
$rowsResult = $connection->execute_query('SELECT * FROM aviso_import_rows WHERE batch_id = ? ORDER BY id ASC', [(int) $batch['id']]);
$rows = [];
if ($rowsResult instanceof mysqli_result) {
    while ($row = $rowsResult->fetch_assoc()) {
        $rows[] = aviso_import_row_payload($connection, $row);
    }
}

aviso_json(200, [
    'success' => true,
    'batch' => [
        'public_id' => $publicId,
        'client_id' => (int) $batch['client_id'],
        'client_name' => (string) $batch['client_name'],
        'default_aviso_status' => (string) $batch['default_aviso_status'],
        'default_operational_status_id' => isset($batch['default_operational_status_id']) && $batch['default_operational_status_id'] !== null ? (int) $batch['default_operational_status_id'] : null,
        'operational_status_slug' => (string) ($batch['operational_status_slug'] ?? ''),
        'operational_status_name' => (string) (($batch['operational_status_name_es'] ?? '') ?: ($batch['operational_status_slug'] ?? '')),
        'status' => (string) ($counts['status'] ?? $batch['status']),
        'total_rows' => (int) ($counts['total_rows'] ?? 0),
        'ready_rows' => (int) ($counts['ready_rows'] ?? 0),
        'review_rows' => (int) ($counts['review_rows'] ?? 0),
        'duplicate_rows' => (int) ($counts['duplicate_rows'] ?? 0),
        'imported_rows' => (int) ($counts['imported_rows'] ?? 0),
        'skipped_rows' => (int) ($counts['skipped_rows'] ?? 0),
        'failed_rows' => (int) ($counts['failed_rows'] ?? 0),
        'created_by_name' => (string) $batch['created_by_name'],
        'created_at' => (string) $batch['created_at'],
        'committed_at' => (string) ($batch['committed_at'] ?? ''),
    ],
    'rows' => $rows,
]);
