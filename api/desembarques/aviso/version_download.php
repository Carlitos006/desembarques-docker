<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../config/files.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_require_user();
$versionIdRaw = trim((string) ($_GET['id'] ?? ''));
if ($versionIdRaw === '' || ! ctype_digit($versionIdRaw)) {
    aviso_json(422, ['success' => false, 'message' => 'La versión indicada no es válida.']);
}
$versionId = (int) $versionIdRaw;

$connection = getDatabaseConnection();
$result = $connection->execute_query(
    'SELECT id, desembarque_id, version_no, original_name, stored_name, mime_type, size, pdf_sha256 '
    . 'FROM desembarque_aviso_versions WHERE id = ? LIMIT 1',
    [$versionId]
);
$version = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
if (! $version) {
    aviso_json(404, ['success' => false, 'message' => 'No se encontró la versión solicitada.']);
}

$desembarqueId = (int) ($version['desembarque_id'] ?? 0);
aviso_require_record_access($connection, $desembarqueId, $user);

$fileConfig = file_storage_config();
$filePath = get_stored_file_path((string) ($version['stored_name'] ?? ''), $fileConfig);
if (! is_file($filePath) || ! is_readable($filePath)) {
    aviso_json(404, ['success' => false, 'message' => 'El PDF histórico ya no está disponible en el almacenamiento privado.']);
}

$expectedHash = strtolower((string) ($version['pdf_sha256'] ?? ''));
if ($expectedHash !== '') {
    $actualHash = hash_file('sha256', $filePath);
    if (! is_string($actualHash) || ! hash_equals($expectedHash, strtolower($actualHash))) {
        error_log('[aviso-version] Integrity mismatch for version ' . $versionId);
        aviso_json(409, ['success' => false, 'message' => 'La verificación de integridad del PDF histórico falló.']);
    }
}

$filename = basename((string) ($version['original_name'] ?? 'aviso-desembarque.pdf'));
if ($filename === '') {
    $filename = 'aviso-desembarque-v' . (int) ($version['version_no'] ?? 0) . '.pdf';
}
$size = (int) ($version['size'] ?? 0);
if ($size <= 0) {
    $size = (int) filesize($filePath);
}

session_write_close();
header('Content-Type: application/pdf');
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Content-SHA256: ' . $expectedHash);

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}
fpassthru($handle);
fclose($handle);
exit;
