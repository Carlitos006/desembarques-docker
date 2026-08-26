<?php

declare(strict_types=1);

require_once __DIR__ . '/_alcance.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
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
    'SELECT v.*, a.desembarque_id, a.alcance_no FROM desembarque_aviso_alcance_versions v '
    . 'INNER JOIN desembarque_aviso_alcances a ON a.id = v.alcance_id WHERE v.id = ? LIMIT 1',
    [$versionId]
);
$version = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
if (! $version) {
    aviso_json(404, ['success' => false, 'message' => 'No se encontró la versión del Alcance.']);
}

aviso_require_record_access($connection, (int) ($version['desembarque_id'] ?? 0), $user);
$fileConfig = file_storage_config();
$filePath = get_stored_file_path((string) ($version['stored_name'] ?? ''), $fileConfig);
if (! is_file($filePath) || ! is_readable($filePath)) {
    aviso_json(404, ['success' => false, 'message' => 'El PDF del Alcance ya no está disponible.']);
}

$expectedHash = strtolower(trim((string) ($version['pdf_sha256'] ?? '')));
if ($expectedHash !== '') {
    $actualHash = hash_file('sha256', $filePath);
    if (! is_string($actualHash) || ! hash_equals($expectedHash, strtolower($actualHash))) {
        error_log('[alcance-version] Integrity mismatch for version ' . $versionId);
        aviso_json(409, ['success' => false, 'message' => 'La verificación de integridad del PDF del Alcance falló.']);
    }
}

$filename = basename((string) ($version['original_name'] ?? 'alcance.pdf')) ?: 'alcance.pdf';
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
readfile($filePath);
exit;
