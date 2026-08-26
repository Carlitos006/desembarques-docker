<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/../../../config/csrf.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

if (! isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
    exit;
}

$userRole = (string) ($_SESSION['user']['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/files.php';

$csrfTokenValue = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

if (! validate_csrf_token($csrfTokenValue)) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => translate('common.csrf_token_invalid', [], $currentLanguage),
    ]);
    exit;
}

$fileIdValue = trim((string) ($_POST['id'] ?? ''));

if ($fileIdValue === '' || ! ctype_digit($fileIdValue)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
        'errors' => [
            'id' => translate('desembarques.files.delete_invalid', [], $currentLanguage),
        ],
    ]);
    exit;
}

$fileId = (int) $fileIdValue;

if ($fileId <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
        'errors' => [
            'id' => translate('desembarques.files.delete_invalid', [], $currentLanguage),
        ],
    ]);
    exit;
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
    exit;
}

try {
    $lookup = $connection->prepare(
        'SELECT f.id, f.desembarque_id, f.original_name, f.stored_name, f.mime_type, f.extension, f.size, f.uploaded_by, f.purpose, f.is_active '
        . 'FROM desembarque_files f INNER JOIN desembarques d ON d.id = f.desembarque_id '
        . 'WHERE f.id = ? AND d.deleted_at IS NULL LIMIT 1'
    );

    if (! $lookup) {
        throw new RuntimeException('Unable to prepare the attachment lookup statement.');
    }

    $lookup->bind_param('i', $fileId);
    $lookup->execute();

    $result = $lookup->get_result();
    $record = $result ? $result->fetch_assoc() : null;

    $lookup->close();

    if (! $record) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => translate('desembarques.files.delete_not_found', [], $currentLanguage),
        ]);
        exit;
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.load_error', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
    exit;
}

$purpose = trim((string) ($record['purpose'] ?? ''));
$extension = strtolower(trim((string) ($record['extension'] ?? '')));
if ($purpose === 'source_excel') {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.delete_not_found', [], $currentLanguage),
    ]);
    exit;
}

if ($purpose === 'case_document' || in_array($extension, ['pdf', 'doc', 'docx'], true)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => $currentLanguage === 'en'
            ? 'Case file documents are not deleted. Use controlled replacement from the notice case file.'
            : 'Los documentos del expediente no se eliminan. Usa el reemplazo controlado desde el expediente del aviso.',
    ]);
    exit;
}

$desembarqueId = isset($record['desembarque_id']) ? (int) $record['desembarque_id'] : 0;
$storedName = (string) ($record['stored_name'] ?? '');
$fileConfig = file_storage_config();
$actorId = (int) $_SESSION['user']['id'];

if ($desembarqueId <= 0 || $storedName === '') {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.delete_not_found', [], $currentLanguage),
    ]);
    exit;
}

try {
    $connection->begin_transaction();

    // El archivo puede estar incluido en el anexo fotográfico del Aviso.
    // Se elimina primero la asociación para mantener compatibilidad incluso en bases legacy sin FK CASCADE.
    $connection->execute_query(
        'DELETE FROM desembarque_aviso_images WHERE desembarque_id = ? AND file_id = ?',
        [$desembarqueId, $fileId]
    );

    $deleteStatement = $connection->prepare('DELETE FROM desembarque_files WHERE id = ? LIMIT 1');

    if (! $deleteStatement) {
        throw new RuntimeException('Unable to prepare the attachment deletion statement.');
    }

    $deleteStatement->bind_param('i', $fileId);
    $deleteStatement->execute();
    $affected = $deleteStatement->affected_rows;
    $deleteStatement->close();

    if ($affected < 1) {
        throw new RuntimeException('The attachment could not be deleted.');
    }

    record_audit_log(
        'update',
        'desembarque',
        (string) $desembarqueId,
        [
            'before' => [
                'attachments' => [[
                    'id' => $fileId,
                    'original_name' => (string) ($record['original_name'] ?? ''),
                    'mime_type' => (string) ($record['mime_type'] ?? ''),
                    'extension' => (string) ($record['extension'] ?? ''),
                    'size' => isset($record['size']) ? (int) $record['size'] : 0,
                    'uploaded_by' => isset($record['uploaded_by']) ? (int) $record['uploaded_by'] : null,
                ]],
            ],
            'after' => [
                'attachments' => [],
            ],
            'changes' => [],
            'attachments' => [
                'added' => [],
                'removed' => [[
                    'id' => $fileId,
                    'original_name' => (string) ($record['original_name'] ?? ''),
                    'mime_type' => (string) ($record['mime_type'] ?? ''),
                    'extension' => (string) ($record['extension'] ?? ''),
                    'size' => isset($record['size']) ? (int) $record['size'] : 0,
                    'uploaded_by' => isset($record['uploaded_by']) ? (int) $record['uploaded_by'] : null,
                ]],
            ],
        ],
        $actorId,
        $connection
    );

    $connection->commit();
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        $connection->rollback();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.delete_error', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
    exit;
}

delete_stored_file($storedName, $fileConfig);

http_response_code(200);

echo json_encode([
    'success' => true,
    'message' => translate('desembarques.files.delete_success', [], $currentLanguage),
    'id' => $fileId,
]);
