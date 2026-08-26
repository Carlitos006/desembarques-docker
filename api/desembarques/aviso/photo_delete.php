<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../config/files.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_require_user();
aviso_require_internal_user($user);

$csrfToken = isset($_POST['csrf_token'])
    ? (string) $_POST['csrf_token']
    : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : null);
if (! validate_csrf_token($csrfToken)) {
    aviso_json(419, [
        'success' => false,
        'message' => 'El token de seguridad no es válido. Recarga la página e inténtalo de nuevo.',
    ]);
}

$desembarqueIdRaw = trim((string) ($_POST['desembarque_id'] ?? ''));
$photoIdRaw = trim((string) ($_POST['photo_id'] ?? ''));
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw) || (int) $desembarqueIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'El expediente indicado no es válido.']);
}
if ($photoIdRaw === '' || ! ctype_digit($photoIdRaw) || (int) $photoIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'La fotografía indicada no es válida.']);
}

$desembarqueId = (int) $desembarqueIdRaw;
$photoId = (int) $photoIdRaw;
$connection = getDatabaseConnection();
aviso_require_record_access($connection, $desembarqueId, $user);

$storedName = '';
$fileDeleted = false;

try {
    $connection->begin_transaction();

    $recordLock = $connection->execute_query(
        'SELECT id FROM desembarques WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE',
        [$desembarqueId]
    );
    if (! ($recordLock instanceof mysqli_result) || ! $recordLock->fetch_assoc()) {
        throw new DomainException('El expediente ya no está disponible para edición.');
    }

    $photoResult = $connection->execute_query(
        'SELECT ai.id, ai.file_id, ai.aviso_item_id, ai.caption, ai.sort_order, '
        . 'f.original_name, f.stored_name, f.mime_type, f.extension, f.size, f.sha256, f.uploaded_by, f.created_at '
        . 'FROM desembarque_aviso_images ai '
        . 'INNER JOIN desembarque_files f ON f.id = ai.file_id AND f.desembarque_id = ai.desembarque_id '
        . 'WHERE ai.id = ? AND ai.desembarque_id = ? LIMIT 1 FOR UPDATE',
        [$photoId, $desembarqueId]
    );
    $photo = $photoResult instanceof mysqli_result ? $photoResult->fetch_assoc() : null;
    if (! is_array($photo)) {
        throw new DomainException('La fotografía no existe o ya fue eliminada.');
    }

    $fileId = (int) ($photo['file_id'] ?? 0);
    $storedName = (string) ($photo['stored_name'] ?? '');

    $connection->execute_query(
        'DELETE FROM desembarque_aviso_images WHERE id = ? AND desembarque_id = ? LIMIT 1',
        [$photoId, $desembarqueId]
    );
    if ($connection->affected_rows !== 1) {
        throw new RuntimeException('La fotografía cambió mientras se procesaba la solicitud.');
    }

    // Conserva el archivo si una instalación legacy lo comparte con otra asociación.
    $usageResult = $connection->execute_query(
        'SELECT COUNT(*) AS total FROM desembarque_aviso_images WHERE file_id = ?',
        [$fileId]
    );
    $usageRow = $usageResult instanceof mysqli_result ? $usageResult->fetch_assoc() : null;
    $remainingUses = (int) ($usageRow['total'] ?? 0);
    if ($remainingUses === 0) {
        $connection->execute_query(
            'DELETE FROM desembarque_files WHERE id = ? AND desembarque_id = ? LIMIT 1',
            [$fileId, $desembarqueId]
        );
        if ($connection->affected_rows !== 1) {
            throw new RuntimeException('No fue posible retirar el archivo de la fotografía.');
        }
        $fileDeleted = true;
    }

    $auditPayload = json_encode([
        'desembarque_id' => $desembarqueId,
        'photo_id' => $photoId,
        'file_id' => $fileId,
        'aviso_item_id' => isset($photo['aviso_item_id']) ? (int) $photo['aviso_item_id'] : null,
        'caption' => (string) ($photo['caption'] ?? ''),
        'sort_order' => (int) ($photo['sort_order'] ?? 0),
        'original_name' => (string) ($photo['original_name'] ?? ''),
        'mime_type' => (string) ($photo['mime_type'] ?? ''),
        'extension' => (string) ($photo['extension'] ?? ''),
        'size' => (int) ($photo['size'] ?? 0),
        'sha256' => (string) ($photo['sha256'] ?? ''),
        'uploaded_by' => isset($photo['uploaded_by']) ? (int) $photo['uploaded_by'] : null,
        'uploaded_at' => (string) ($photo['created_at'] ?? ''),
        'file_deleted' => $fileDeleted,
        'remaining_file_uses' => $remainingUses,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $connection->execute_query(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, payload) VALUES (?, ?, ?, ?, ?)',
        [(int) $user['id'], 'delete', 'desembarque_aviso_photo', (string) $photoId, $auditPayload]
    );
    if ($connection->affected_rows !== 1) {
        throw new RuntimeException('No fue posible registrar la auditoría de la fotografía.');
    }

    $connection->commit();
} catch (DomainException $exception) {
    $connection->rollback();
    aviso_json(404, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    $connection->rollback();
    error_log('[aviso-photo-delete] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible eliminar la fotografía del expediente.']);
}

if ($fileDeleted && $storedName !== '') {
    $deletedFromStorage = delete_stored_file($storedName, file_storage_config());
    if (! $deletedFromStorage) {
        error_log('[aviso-photo-delete] Archivo físico pendiente de limpieza: ' . $storedName);
    }
}

aviso_json(200, [
    'success' => true,
    'message' => 'La fotografía fue eliminada del expediente.',
    'photo_id' => $photoId,
    'file_deleted' => $fileDeleted,
]);
