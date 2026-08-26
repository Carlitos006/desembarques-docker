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

$desembarqueIdRaw = trim((string) ($_POST['desembarque_id'] ?? ''));
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw) || (int) $desembarqueIdRaw <= 0) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Invalid unloading record.' : 'El desembarque no es válido.']);
}
$desembarqueId = (int) $desembarqueIdRaw;

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

if (! isset($_FILES['documents']) || ! is_array($_FILES['documents'])) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Select at least one PDF or Word document.' : 'Selecciona al menos un documento PDF o Word.']);
}

$uploads = array_values(array_filter(
    normalize_uploaded_files_array($_FILES['documents']),
    static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
));
if ($uploads === []) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Select at least one PDF or Word document.' : 'Selecciona al menos un documento PDF o Word.']);
}

$fileConfig = file_storage_config();
$maxFiles = (int) ($fileConfig['max_files_per_request'] ?? 10);
if ($maxFiles > 0 && count($uploads) > $maxFiles) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? "You can upload up to {$maxFiles} documents at once." : "Puedes subir hasta {$maxFiles} documentos por operación."]);
}

$pending = [];
foreach ($uploads as $upload) {
    try {
        $metadata = validate_uploaded_file($upload, $fileConfig);
    } catch (Throwable $exception) {
        $respond(422, ['success' => false, 'message' => $exception->getMessage()]);
    }

    if (! in_array(strtolower((string) ($metadata['extension'] ?? '')), ['pdf', 'doc', 'docx'], true)) {
        $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Only PDF, DOC and DOCX documents are accepted.' : 'Sólo se aceptan documentos PDF, DOC y DOCX.']);
    }

    $pending[] = ['file' => $upload, 'metadata' => $metadata];
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
    $record = expediente_document_load_desembarque($connection, $desembarqueId);
    if (! $record) {
        $respond(404, ['success' => false, 'message' => $currentLanguage === 'en' ? 'The unloading record was not found.' : 'No se encontró el desembarque.']);
    }
} catch (Throwable $exception) {
    $respond(500, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Unable to load the unloading record.' : 'No fue posible consultar el desembarque.']);
}

$actorId = (int) $_SESSION['user']['id'];
$storedDocuments = [];

try {
    $connection->begin_transaction();

    foreach ($pending as $entry) {
        $stored = store_uploaded_file($entry['file'], $entry['metadata'], $fileConfig);
        try {
            $sha256 = expediente_document_hash_file((string) $stored['stored_name'], $fileConfig);
            $purpose = 'case_document';
            $descriptionValue = $description !== '' ? $description : null;
            $documentDateValue = $documentDate;
            $size = (int) $stored['size'];

            $statement = $connection->prepare(
                'INSERT INTO desembarque_files '
                . '(desembarque_id, uploaded_by, original_name, stored_name, mime_type, extension, size, purpose, document_type, description, document_date, sha256, is_active) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            if (! $statement) {
                throw new RuntimeException('Unable to prepare document insert.');
            }

            $statement->bind_param(
                'iissssisssss',
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
                $sha256
            );
            $statement->execute();
            $fileId = (int) $connection->insert_id;
            $statement->close();

            $document = [
                'id' => $fileId,
                'desembarque_id' => $desembarqueId,
                'original_name' => (string) $stored['original_name'],
                'stored_name' => (string) $stored['stored_name'],
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
            ];
            $storedDocuments[] = $document;

            record_audit_log(
                'create',
                'desembarque_document',
                (string) $fileId,
                [
                    'desembarque_id' => $desembarqueId,
                    'document' => $document,
                ],
                $actorId,
                $connection
            );
        } catch (Throwable $innerException) {
            delete_stored_file((string) $stored['stored_name'], $fileConfig);
            throw $innerException;
        }
    }

    $connection->commit();
} catch (Throwable $exception) {
    $connection->rollback();
    foreach ($storedDocuments as $document) {
        delete_stored_file((string) ($document['stored_name'] ?? ''), $fileConfig);
    }
    error_log('[document-upload] ' . $exception->getMessage());
    $respond(500, ['success' => false, 'message' => $currentLanguage === 'en' ? 'The documents could not be saved.' : 'No fue posible guardar los documentos.']);
}

$storedDocumentCount = count($storedDocuments);
$respond(201, [
    'success' => true,
    'message' => $currentLanguage === 'en'
        ? $storedDocumentCount . ($storedDocumentCount === 1 ? ' document added to the case file.' : ' documents added to the case file.')
        : count($storedDocuments) . ' documento(s) añadido(s) al expediente.',
    'documents' => array_map(static function (array $document): array {
        unset($document['stored_name']);
        return $document;
    }, $storedDocuments),
]);
