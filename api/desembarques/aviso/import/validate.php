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
    'SELECT b.*, c.name AS client_name FROM aviso_import_batches b '
    . 'INNER JOIN clients c ON c.id = b.client_id WHERE b.public_id = ? LIMIT 1',
    [$publicId]
);
$batch = $batchResult instanceof mysqli_result ? $batchResult->fetch_assoc() : null;
if (! $batch) {
    aviso_json(404, ['success' => false, 'message' => 'No se encontró el lote de importación.']);
}

try {
    $validation = aviso_import_validate_batch($connection, $batch);
} catch (Throwable $exception) {
    error_log('[historical-import-validation] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible ejecutar la validación 5E5 del lote.']);
}

$validation['client_name'] = (string) ($batch['client_name'] ?? '');
aviso_json($validation['pass'] ? 200 : 409, [
    'success' => true,
    'message' => $validation['pass']
        ? 'Validación 5E5 completada sin errores de integridad.'
        : 'La validación 5E5 encontró inconsistencias que deben revisarse.',
    'validation' => $validation,
]);
