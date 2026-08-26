<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/../../../config/csrf.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/_documents.php';

$currentLanguage = getAppLanguage();
header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['success' => false, 'message' => translate('common.method_not_allowed', [], $currentLanguage)]);
}
if (! isset($_SESSION['user']['id'])) {
    $respond(401, ['success' => false, 'message' => translate('common.session_missing_user', [], $currentLanguage)]);
}

$userRole = (string) ($_SESSION['user']['role'] ?? '');
if (! expediente_document_user_can_write($userRole)) {
    $respond(403, ['success' => false, 'message' => translate('desembarques.permission_denied', [], $currentLanguage)]);
}
if (! validate_csrf_token(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
    $respond(419, ['success' => false, 'message' => translate('common.csrf_token_invalid', [], $currentLanguage)]);
}

$fileIdRaw = trim((string) ($_POST['file_id'] ?? ''));
if ($fileIdRaw === '' || ! ctype_digit($fileIdRaw) || (int) $fileIdRaw <= 0) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Select a valid document to replace.' : 'Selecciona un documento válido para reemplazar.']);
}
$fileId = (int) $fileIdRaw;

$documentType = strtolower(trim((string) ($_POST['document_type'] ?? 'other')));
if (! expediente_document_type_is_valid($documentType)) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Select a valid document type.' : 'Selecciona un tipo de documento válido.']);
}
$description = expediente_document_normalize_description((string) ($_POST['description'] ?? ''));
$documentDateInput = trim((string) ($_POST['document_date'] ?? ''));
$documentDate = expediente_document_normalize_date($documentDateInput);
if ($documentDateInput !== '' && $documentDate === null) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'The document date is invalid.' : 'La fecha del documento no es válida.']);
}

if (! isset($_FILES['document']) || ! is_array($_FILES['document'])) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Select the replacement PDF or Word file.' : 'Selecciona el PDF o Word de reemplazo.']);
}
$upload = normalize_uploaded_files_array($_FILES['document'])[0] ?? null;
if (! is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Select the replacement PDF or Word file.' : 'Selecciona el PDF o Word de reemplazo.']);
}

$fileConfig = file_storage_config();
try {
    $metadata = validate_uploaded_file($upload, $fileConfig);
} catch (Throwable $exception) {
    $respond(422, ['success' => false, 'message' => $exception->getMessage()]);
}
if (! in_array(strtolower((string) ($metadata['extension'] ?? '')), ['pdf', 'doc', 'docx'], true)) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Only PDF, DOC and DOCX documents are accepted.' : 'Sólo se aceptan documentos PDF, DOC y DOCX.']);
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
    $old = expediente_document_load_file($connection, $fileId);
    if (! $old || ! expediente_document_is_supported_file($old)) {
        $respond(404, ['success' => false, 'message' => $currentLanguage === 'en' ? 'The document was not found.' : 'No se encontró el documento.']);
    }
    if ((int) ($old['is_active'] ?? 1) !== 1) {
        $respond(409, ['success' => false, 'message' => $currentLanguage === 'en' ? 'This document has already been replaced.' : 'Este documento ya fue reemplazado.']);
    }
} catch (Throwable $exception) {
    $respond(500, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Unable to load the document.' : 'No fue posible consultar el documento.']);
}

$actorId = (int) $_SESSION['user']['id'];
$desembarqueId = (int) ($old['desembarque_id'] ?? 0);
$stored = null;

try {
    $stored = store_uploaded_file($upload, $metadata, $fileConfig);
    $sha256 = expediente_document_hash_file((string) $stored['stored_name'], $fileConfig);
    $size = (int) $stored['size'];
    $purpose = 'case_document';
    $descriptionValue = $description !== '' ? $description : null;
    $documentDateValue = $documentDate;

    $connection->begin_transaction();

    // Bloqueo para evitar dos reemplazos simultáneos del mismo documento vigente.
    $lockResult = $connection->execute_query('SELECT id, is_active FROM desembarque_files WHERE id = ? FOR UPDATE', [$fileId]);
    $locked = $lockResult instanceof mysqli_result ? $lockResult->fetch_assoc() : null;
    if (! $locked || (int) ($locked['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Document is no longer active.');
    }

    $statement = $connection->prepare(
        'INSERT INTO desembarque_files '
        . '(desembarque_id, uploaded_by, original_name, stored_name, mime_type, extension, size, purpose, document_type, description, document_date, sha256, is_active, replaces_file_id) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    if (! $statement) {
        throw new RuntimeException('Unable to prepare replacement document insert.');
    }
    $statement->bind_param(
        'iissssisssssi',
        $desembarqueId,
        $actorId,
        $stored['original_name'],
        $stored['stored_name'],
        $stored['mime_type'],
        $stored['extension'],
        $size,
        $purpose,
        $documentType,
        $descriptionValue,
        $documentDateValue,
        $sha256,
        $fileId
    );
    $statement->execute();
    $newFileId = (int) $connection->insert_id;
    $statement->close();

    $update = $connection->prepare('UPDATE desembarque_files SET is_active = 0, replaced_at = NOW(), replaced_by = ? WHERE id = ? AND is_active = 1');
    if (! $update) {
        throw new RuntimeException('Unable to prepare replaced document update.');
    }
    $update->bind_param('ii', $actorId, $fileId);
    $update->execute();
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('Document was replaced concurrently.');
    }
    $update->close();

    $newDocument = [
        'id' => $newFileId,
        'desembarque_id' => $desembarqueId,
        'original_name' => (string) $stored['original_name'],
        'mime_type' => (string) $stored['mime_type'],
        'extension' => (string) $stored['extension'],
        'size' => $size,
        'purpose' => $purpose,
        'document_type' => $documentType,
        'description' => $description,
        'document_date' => $documentDate,
        'sha256' => $sha256,
        'is_active' => 1,
        'uploaded_by' => $actorId,
        'replaces_file_id' => $fileId,
    ];

    record_audit_log(
        'replace',
        'desembarque_document',
        (string) $newFileId,
        [
            'desembarque_id' => $desembarqueId,
            'before' => [
                'id' => $fileId,
                'original_name' => (string) ($old['original_name'] ?? ''),
                'document_type' => (string) ($old['document_type'] ?? 'other'),
                'sha256' => (string) ($old['sha256'] ?? ''),
            ],
            'after' => $newDocument,
            'replaces_file_id' => $fileId,
        ],
        $actorId,
        $connection
    );

    $connection->commit();
} catch (Throwable $exception) {
    if (isset($connection) && $connection instanceof mysqli) {
        $connection->rollback();
    }
    if (is_array($stored) && ! empty($stored['stored_name'])) {
        delete_stored_file((string) $stored['stored_name'], $fileConfig);
    }
    error_log('[document-replace] ' . $exception->getMessage());
    $respond(500, ['success' => false, 'message' => $currentLanguage === 'en' ? 'The document could not be replaced.' : 'No fue posible reemplazar el documento.']);
}

$respond(201, [
    'success' => true,
    'message' => $currentLanguage === 'en' ? 'The new document version was saved without deleting the previous file.' : 'La nueva versión quedó guardada sin eliminar el documento anterior.',
    'document_id' => $newFileId,
    'replaced_file_id' => $fileId,
]);
