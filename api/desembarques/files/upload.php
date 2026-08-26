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

$desembarqueIdValue = trim((string) ($_POST['desembarque_id'] ?? ''));

if ($desembarqueIdValue === '' || ! ctype_digit($desembarqueIdValue)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
        'errors' => [
            'desembarque_id' => translate('desembarques.validation.id_invalid', [], $currentLanguage),
        ],
    ]);
    exit;
}

$desembarqueId = (int) $desembarqueIdValue;

if ($desembarqueId <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
        'errors' => [
            'desembarque_id' => translate('desembarques.validation.id_invalid', [], $currentLanguage),
        ],
    ]);
    exit;
}

$actorId = (int) $_SESSION['user']['id'];
$fileConfig = file_storage_config();
$pendingAttachments = [];

if (! isset($_FILES['attachments']) || ! is_array($_FILES['attachments'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
        'errors' => [
            'attachments' => translate('desembarques.files.validation_error', ['error' => translate('desembarques.files.error_missing', [], $currentLanguage)], $currentLanguage),
        ],
    ]);
    exit;
}

$normalizedUploads = normalize_uploaded_files_array($_FILES['attachments']);
$actualUploads = [];

foreach ($normalizedUploads as $upload) {
    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    $actualUploads[] = $upload;
}

if ($actualUploads === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
        'errors' => [
            'attachments' => translate('desembarques.files.validation_error', ['error' => translate('desembarques.files.error_missing', [], $currentLanguage)], $currentLanguage),
        ],
    ]);
    exit;
}

$maxFilesPerRequest = isset($fileConfig['max_files_per_request']) ? (int) $fileConfig['max_files_per_request'] : 0;

if ($maxFilesPerRequest > 0 && count($actualUploads) > $maxFilesPerRequest) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('validation.errors', [], $currentLanguage),
        'errors' => [
            'attachments' => translate('desembarques.files.error_too_many', ['max' => $maxFilesPerRequest], $currentLanguage),
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
    $lookup = $connection->prepare('SELECT id FROM desembarques WHERE id = ? AND deleted_at IS NULL LIMIT 1');

    if (! $lookup) {
        throw new RuntimeException(translate('desembarques.update.error_prepare_statement', [], $currentLanguage));
    }

    $lookup->bind_param('i', $desembarqueId);
    $lookup->execute();

    $result = $lookup->get_result();
    $record = $result ? $result->fetch_assoc() : null;

    $lookup->close();

    if (! $record) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => translate('desembarques.update.not_found', [], $currentLanguage),
        ]);
        exit;
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.update.error_lookup', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
    exit;
}

foreach ($actualUploads as $upload) {
    try {
        $metadata = validate_uploaded_file($upload, $fileConfig);
        $pendingAttachments[] = [
            'file' => $upload,
            'metadata' => $metadata,
        ];
    } catch (RuntimeException $exception) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => translate('validation.errors', [], $currentLanguage),
            'errors' => [
                'attachments' => translate('desembarques.files.validation_error', ['error' => $exception->getMessage()], $currentLanguage),
            ],
        ]);
        exit;
    }
}

$newStoredAttachments = [];

try {
    $connection->begin_transaction();

    foreach ($pendingAttachments as $attachment) {
        $storedFile = store_uploaded_file($attachment['file'], $attachment['metadata'], $fileConfig);

        try {
            $fileSize = (int) $storedFile['size'];
            $extension = strtolower((string) $storedFile['extension']);
            $isCaseDocument = in_array($extension, ['pdf', 'doc', 'docx'], true);
            $purpose = $isCaseDocument ? 'case_document' : 'attachment';
            $documentType = $isCaseDocument ? 'other' : null;
            $sha256 = hash_file('sha256', (string) $storedFile['storage_path']);
            $sha256 = is_string($sha256) ? strtolower($sha256) : null;

            $insertFileQuery = 'INSERT INTO desembarque_files (desembarque_id, uploaded_by, original_name, stored_name, mime_type, extension, size, purpose, document_type, sha256, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
            $fileStatement = $connection->prepare($insertFileQuery);

            if (! $fileStatement) {
                delete_stored_file($storedFile['stored_name'], $fileConfig);
                throw new RuntimeException('Unable to prepare the attachment statement.');
            }

            $fileStatement->bind_param(
                'iissssisss',
                $desembarqueId,
                $actorId,
                $storedFile['original_name'],
                $storedFile['stored_name'],
                $storedFile['mime_type'],
                $storedFile['extension'],
                $fileSize,
                $purpose,
                $documentType,
                $sha256
            );

            $fileStatement->execute();
            $fileId = (int) $connection->insert_id;
            $fileStatement->close();

            $storedFile['id'] = $fileId;
            $storedFile['uploaded_by'] = $actorId;
            $storedFile['purpose'] = $purpose;
            $storedFile['document_type'] = $documentType;
            $storedFile['sha256'] = $sha256;
            $storedFile['is_active'] = 1;
            $newStoredAttachments[] = $storedFile;
        } catch (Throwable $attachmentException) {
            if (isset($fileStatement) && $fileStatement instanceof mysqli_stmt) {
                $fileStatement->close();
            }

            delete_stored_file($storedFile['stored_name'], $fileConfig);

            throw $attachmentException;
        }
    }

    if ($newStoredAttachments !== []) {
        $attachmentChanges = [
            'added' => array_map(
                static function (array $attachment): array {
                    return [
                        'id' => (int) ($attachment['id'] ?? 0),
                        'original_name' => (string) ($attachment['original_name'] ?? ''),
                        'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                        'extension' => (string) ($attachment['extension'] ?? ''),
                        'size' => (int) ($attachment['size'] ?? 0),
                        'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
                        'purpose' => (string) ($attachment['purpose'] ?? 'attachment'),
                        'document_type' => $attachment['document_type'] ?? null,
                        'sha256' => $attachment['sha256'] ?? null,
                    ];
                },
                $newStoredAttachments
            ),
            'removed' => [],
        ];

        record_audit_log(
            'update',
            'desembarque',
            (string) $desembarqueId,
            [
                'before' => null,
                'after' => [
                    'attachments' => $attachmentChanges['added'],
                ],
                'changes' => [],
                'attachments' => $attachmentChanges,
            ],
            $actorId,
            $connection
        );
    }

    $connection->commit();
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        $connection->rollback();
    }

    foreach ($newStoredAttachments as $attachment) {
        delete_stored_file($attachment['stored_name'], $fileConfig);
    }

    http_response_code(500);
    $errorMessage = trim((string) $exception->getMessage());
    $errorResponse = translate('desembarques.files.upload_error', [], $currentLanguage);

    if ($errorMessage !== '') {
        $errorResponse .= ': ' . $errorMessage;
    }

    echo json_encode([
        'success' => false,
        'message' => $errorResponse,
    ]);
    exit;
}

$responseAttachments = array_map(
    static function (array $attachment): array {
        $attachmentId = (int) ($attachment['id'] ?? 0);

        return [
            'id' => $attachmentId,
            'original_name' => (string) ($attachment['original_name'] ?? ''),
            'mime_type' => (string) ($attachment['mime_type'] ?? ''),
            'extension' => (string) ($attachment['extension'] ?? ''),
            'size' => (int) ($attachment['size'] ?? 0),
            'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
            'purpose' => (string) ($attachment['purpose'] ?? 'attachment'),
            'document_type' => $attachment['document_type'] ?? null,
            'sha256' => $attachment['sha256'] ?? null,
            'download_url' => '../api/desembarques/files/download.php?id=' . $attachmentId,
        ];
    },
    $newStoredAttachments
);

http_response_code(201);

echo json_encode([
    'success' => true,
    'message' => translate('desembarques.files.upload_success', [], $currentLanguage),
    'attachments' => $responseAttachments,
]);
