<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../config/files.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_require_user();
$fileIdRaw = trim((string) ($_GET['file_id'] ?? ''));

if ($fileIdRaw === '' || ! ctype_digit($fileIdRaw)) {
    aviso_json(422, ['success' => false, 'message' => 'La fotografía indicada no es válida.']);
}

$fileId = (int) $fileIdRaw;
$connection = getDatabaseConnection();
$fileResult = $connection->execute_query(
    'SELECT id, desembarque_id, original_name, stored_name, mime_type, extension, size '
    . 'FROM desembarque_files WHERE id = ? LIMIT 1',
    [$fileId]
);
$file = $fileResult instanceof mysqli_result ? $fileResult->fetch_assoc() : null;

if (! $file) {
    aviso_json(404, ['success' => false, 'message' => 'No se encontró la fotografía.']);
}

$desembarqueId = (int) ($file['desembarque_id'] ?? 0);
aviso_require_record_access($connection, $desembarqueId, $user);

$mimeType = strtolower(trim((string) ($file['mime_type'] ?? '')));
$extension = strtolower(trim((string) ($file['extension'] ?? '')));
$allowedImageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (strpos($mimeType, 'image/') !== 0 || ! in_array($extension, $allowedImageExtensions, true)) {
    aviso_json(415, ['success' => false, 'message' => 'El archivo seleccionado no es una fotografía compatible con el anexo.']);
}

$fileConfig = file_storage_config();
$filePath = get_stored_file_path((string) ($file['stored_name'] ?? ''), $fileConfig);
if (! is_file($filePath) || ! is_readable($filePath)) {
    aviso_json(404, ['success' => false, 'message' => 'La fotografía ya no está disponible en el almacenamiento.']);
}

$size = isset($file['size']) ? (int) $file['size'] : (int) filesize($filePath);
$filename = basename((string) ($file['original_name'] ?? 'fotografia'));
if ($extension !== '' && strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== $extension) {
    $filename .= '.' . $extension;
}

session_write_close();
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) $size);
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

fpassthru($handle);
fclose($handle);
exit;
