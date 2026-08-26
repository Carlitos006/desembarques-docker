<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../config/files.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_require_user();
aviso_require_internal_user($user);

if (! validate_csrf_token(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
    aviso_json(419, [
        'success' => false,
        'message' => 'El token de seguridad no es válido. Recarga la página e inténtalo de nuevo.',
    ]);
}

$desembarqueIdRaw = trim((string) ($_POST['desembarque_id'] ?? ''));
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw) || (int) $desembarqueIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'El expediente indicado no es válido.']);
}
$desembarqueId = (int) $desembarqueIdRaw;

$uploads = isset($_FILES['photos']) && is_array($_FILES['photos'])
    ? normalize_uploaded_files_array($_FILES['photos'])
    : [];
$uploads = array_values(array_filter(
    $uploads,
    static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
));

if ($uploads === []) {
    aviso_json(422, ['success' => false, 'message' => 'Selecciona al menos una fotografía.']);
}
if (count($uploads) > 20) {
    aviso_json(422, ['success' => false, 'message' => 'Puedes cargar hasta 20 fotografías por operación.']);
}

$metadata = json_decode((string) ($_POST['photo_metadata'] ?? '[]'), true);
$metadata = is_array($metadata) ? array_values($metadata) : [];

$photoConfig = file_storage_config();
$photoConfig['max_size'] = 25 * 1024 * 1024;
$photoConfig['max_files_per_request'] = 20;
$photoConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$photoConfig['allowed_mime_types'] = [
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'gif' => ['image/gif'],
    'webp' => ['image/webp'],
];

$validated = [];
foreach ($uploads as $index => $upload) {
    try {
        $fileMetadata = validate_uploaded_file($upload, $photoConfig);
    } catch (RuntimeException $exception) {
        aviso_json(422, [
            'success' => false,
            'message' => sprintf(
                '%s: %s',
                trim((string) ($upload['name'] ?? 'Fotografía')) ?: 'Fotografía',
                $exception->getMessage()
            ),
        ]);
    }

    $entry = isset($metadata[$index]) && is_array($metadata[$index]) ? $metadata[$index] : [];
    $itemId = isset($entry['aviso_item_id']) && is_numeric($entry['aviso_item_id'])
        ? max(0, (int) $entry['aviso_item_id'])
        : 0;

    $validated[] = [
        'file' => $upload,
        'metadata' => $fileMetadata,
        'aviso_item_id' => $itemId > 0 ? $itemId : null,
        'caption' => aviso_clean_text($entry['caption'] ?? null, 255),
    ];
}

$connection = getDatabaseConnection();
aviso_require_record_access($connection, $desembarqueId, $user);

$itemResult = $connection->execute_query(
    'SELECT id FROM desembarque_aviso_items WHERE desembarque_id = ?',
    [$desembarqueId]
);
$allowedItems = [];
if ($itemResult instanceof mysqli_result) {
    while ($row = $itemResult->fetch_assoc()) {
        $allowedItems[(int) ($row['id'] ?? 0)] = true;
    }
}

foreach ($validated as $entry) {
    $itemId = $entry['aviso_item_id'];
    if ($itemId !== null && ! isset($allowedItems[$itemId])) {
        aviso_json(422, [
            'success' => false,
            'message' => 'Una de las fotografías está asociada a una mercancía que no pertenece a este expediente.',
        ]);
    }
}

$storedFiles = [];
$createdPhotos = [];

try {
    $connection->begin_transaction();

    // Serializa las altas del anexo para que el orden sea estable incluso con dos cargas simultáneas.
    $recordLock = $connection->execute_query(
        'SELECT id FROM desembarques WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE',
        [$desembarqueId]
    );
    if (! ($recordLock instanceof mysqli_result) || ! $recordLock->fetch_assoc()) {
        throw new RuntimeException('El expediente dejó de estar disponible.');
    }

    $orderResult = $connection->execute_query(
        'SELECT COALESCE(MAX(sort_order), 0) AS max_order FROM desembarque_aviso_images WHERE desembarque_id = ?',
        [$desembarqueId]
    );
    $orderRow = $orderResult instanceof mysqli_result ? $orderResult->fetch_assoc() : null;
    $nextOrder = max(1, ((int) ($orderRow['max_order'] ?? 0)) + 1);

    foreach ($validated as $index => $entry) {
        $stored = store_uploaded_file($entry['file'], $entry['metadata'], $photoConfig);
        $storedFiles[] = $stored;

        $sha256 = hash_file('sha256', (string) ($stored['storage_path'] ?? ''));
        if (! is_string($sha256) || strlen($sha256) !== 64) {
            throw new RuntimeException('No fue posible calcular la huella de una fotografía.');
        }

        $connection->execute_query(
            'INSERT INTO desembarque_files '
            . '(desembarque_id, uploaded_by, original_name, stored_name, mime_type, extension, size, purpose, document_type, sha256, is_active) '
            . "VALUES (?,?,?,?,?,?,?,'photo',NULL,?,1)",
            [
                $desembarqueId,
                (int) $user['id'],
                (string) $stored['original_name'],
                (string) $stored['stored_name'],
                (string) $stored['mime_type'],
                (string) $stored['extension'],
                (int) $stored['size'],
                strtolower($sha256),
            ]
        );
        $fileId = (int) $connection->insert_id;
        $sortOrder = $nextOrder + $index;

        $connection->execute_query(
            'INSERT INTO desembarque_aviso_images '
            . '(desembarque_id, file_id, aviso_item_id, caption, sort_order) VALUES (?,?,?,?,?)',
            [
                $desembarqueId,
                $fileId,
                $entry['aviso_item_id'],
                $entry['caption'],
                $sortOrder,
            ]
        );
        $photoId = (int) $connection->insert_id;

        $createdPhotos[] = [
            'id' => $photoId,
            'file_id' => $fileId,
            'aviso_item_id' => $entry['aviso_item_id'],
            'caption' => $entry['caption'],
            'sort_order' => $sortOrder,
            'original_name' => (string) $stored['original_name'],
            'size' => (int) $stored['size'],
            'preview_url' => '../api/desembarques/aviso/image.php?file_id=' . $fileId,
            'download_url' => '../api/desembarques/files/download.php?id=' . $fileId,
        ];
    }

    record_audit_log(
        'update',
        'desembarque_aviso_photos',
        (string) $desembarqueId,
        [
            'desembarque_id' => $desembarqueId,
            'photos_added' => array_map(
                static fn (array $photo): array => [
                    'photo_id' => (int) $photo['id'],
                    'file_id' => (int) $photo['file_id'],
                    'aviso_item_id' => $photo['aviso_item_id'],
                    'caption' => $photo['caption'],
                    'original_name' => $photo['original_name'],
                ],
                $createdPhotos
            ),
            'photos_count' => count($createdPhotos),
        ],
        (int) $user['id'],
        $connection
    );

    $connection->commit();
} catch (Throwable $exception) {
    $connection->rollback();
    foreach ($storedFiles as $stored) {
        if (isset($stored['stored_name'])) {
            delete_stored_file((string) $stored['stored_name'], $photoConfig);
        }
    }
    error_log('[aviso-photo-upload] ' . $exception->getMessage());
    aviso_json(500, [
        'success' => false,
        'message' => 'No fue posible guardar las fotografías en el expediente.',
        'detail' => $exception->getMessage(),
    ]);
}

aviso_json(201, [
    'success' => true,
    'message' => count($createdPhotos) === 1
        ? 'La fotografía se añadió correctamente al expediente.'
        : count($createdPhotos) . ' fotografías se añadieron correctamente al expediente.',
    'photos' => $createdPhotos,
    'photos_count' => count($createdPhotos),
]);
