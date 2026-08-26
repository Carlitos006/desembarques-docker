<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/audit.php';
require_once dirname(__DIR__, 2) . '/config/files.php';

$language = api_v1_detect_language();

try {
    api_v1_require_method(['POST'], $language);
    $auth = authenticate_api_request(['desembarques:write'], $language);
    $user = $auth['user'];
    $actorId = (int) ($user['id'] ?? 0);

    $payload = api_v1_parse_body();

    $referencia = clean_api_string($payload['referencia'] ?? '', 100);
    $folioAviso = clean_api_string($payload['folio_aviso'] ?? '', 100);
    $descripcion = clean_api_string($payload['descripcion'] ?? '', 1000);
    $destino = clean_api_string($payload['destino'] ?? '', 150);
    $barco = clean_api_string($payload['barco'] ?? '', 150);
    $cliente = clean_api_string($payload['cliente'] ?? '', 150);
    $clienteIdInput = isset($payload['cliente_id']) ? trim((string) $payload['cliente_id']) : '';
    $statusIdInput = isset($payload['status_id']) ? trim((string) $payload['status_id']) : '';
    $fechaDesembarqueInput = isset($payload['fecha_desembarque']) ? (string) $payload['fecha_desembarque'] : '';
    $fechaEmbarqueInput = isset($payload['fecha_embarque']) ? (string) $payload['fecha_embarque'] : '';

    $errors = [];

    if ($referencia === '') {
        $errors['referencia'] = translate('desembarques.validation.reference_required', [], $language);
    }

    if ($descripcion === '') {
        $errors['descripcion'] = translate('desembarques.validation.description_required', [], $language);
    }

    if ($destino === '') {
        $errors['destino'] = translate('desembarques.validation.destination_required', [], $language);
    }

    if ($barco === '') {
        $errors['barco'] = translate('desembarques.validation.vessel_required', [], $language);
    }

    $clienteId = null;

    if ($cliente === '' && $clienteIdInput === '') {
        $errors['cliente'] = translate('desembarques.validation.customer_required', [], $language);
    }

    if ($clienteIdInput !== '') {
        if (! ctype_digit($clienteIdInput)) {
            $errors['cliente_id'] = translate('desembarques.validation.client_invalid', [], $language);
        } else {
            $clienteId = (int) $clienteIdInput;
        }
    }

    if ($statusIdInput === '') {
        $errors['status_id'] = translate('desembarques.validation.status_required', [], $language);
    } elseif (! ctype_digit($statusIdInput)) {
        $errors['status_id'] = translate('desembarques.validation.status_invalid', [], $language);
    }

    $statusId = $statusIdInput !== '' && ctype_digit($statusIdInput) ? (int) $statusIdInput : null;

    $fechaDesembarque = parse_api_date($fechaDesembarqueInput, 'fecha_desembarque', $errors, $language, true);
    $fechaEmbarque = parse_api_date($fechaEmbarqueInput, 'fecha_embarque', $errors, $language, false);

    if ($fechaDesembarque instanceof DateTimeImmutable && $fechaEmbarque instanceof DateTimeImmutable && $fechaEmbarque < $fechaDesembarque) {
        $errors['fecha_embarque'] = translate('desembarques.validation.departure_before_landing', [], $language);
    }

    $fileConfig = file_storage_config();
    $pendingAttachments = [];

    if (isset($_FILES['attachments']) && is_array($_FILES['attachments'])) {
        $normalizedFiles = normalize_uploaded_files_array($_FILES['attachments']);
        $actualUploads = [];

        foreach ($normalizedFiles as $upload) {
            if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $actualUploads[] = $upload;
        }

        $maxFilesPerRequest = isset($fileConfig['max_files_per_request']) ? (int) $fileConfig['max_files_per_request'] : 0;

        if ($maxFilesPerRequest > 0 && count($actualUploads) > $maxFilesPerRequest) {
            $errors['attachments'] = translate('desembarques.files.error_too_many', ['max' => $maxFilesPerRequest], $language);
        } else {
            foreach ($actualUploads as $upload) {
                try {
                    $metadata = validate_uploaded_file($upload, $fileConfig);
                    $pendingAttachments[] = [
                        'file' => $upload,
                        'metadata' => $metadata,
                    ];
                } catch (Throwable $exception) {
                    $errors['attachments'] = translate('desembarques.files.validation_error', ['error' => $exception->getMessage()], $language);
                    break;
                }
            }
        }
    }

    if ($errors !== []) {
        api_v1_error(422, translate('validation.errors', [], $language), $errors);
    }

    $fechaDesembarqueValue = $fechaDesembarque instanceof DateTimeImmutable ? $fechaDesembarque->format('Y-m-d') : null;
    $fechaEmbarqueValue = $fechaEmbarque instanceof DateTimeImmutable ? $fechaEmbarque->format('Y-m-d') : null;

    $today = new DateTimeImmutable('today');
    $diasTranscurridos = $fechaDesembarque instanceof DateTimeImmutable
        ? max(0, (int) $fechaDesembarque->diff($today)->format('%r%a'))
        : 0;
    $diasFuera = 0;

    if ($fechaDesembarque instanceof DateTimeImmutable && $fechaEmbarque instanceof DateTimeImmutable) {
        $diasFuera = max(0, (int) $fechaDesembarque->diff($fechaEmbarque)->format('%r%a'));
    }

    /** @var mysqli $connection */
    $connection = getDatabaseConnection();

    if ($clienteId !== null) {
        $clientLookup = $connection->prepare('SELECT id, name, email FROM clients WHERE id = ? LIMIT 1');

        if (! $clientLookup instanceof mysqli_stmt) {
            api_v1_error(500, translate('desembarques.validation.client_lookup_failed', [], $language));
        }

        $clientLookup->bind_param('i', $clienteId);
        $clientLookup->execute();
        $clientResult = $clientLookup->get_result();
        $clientRecord = $clientResult ? $clientResult->fetch_assoc() : null;
        $clientLookup->close();

        if (! $clientRecord) {
            api_v1_error(422, translate('desembarques.validation.client_not_found', [], $language), [
                'cliente_id' => translate('desembarques.validation.client_not_found', [], $language),
            ]);
        }

        if ($cliente === '' && isset($clientRecord['name'])) {
            $cliente = clean_api_string((string) $clientRecord['name'], 150);
        }

        if ($cliente === '' && isset($clientRecord['email'])) {
            $cliente = clean_api_string((string) $clientRecord['email'], 150);
        }
    }

    if ($statusId === null) {
        api_v1_error(422, translate('desembarques.validation.status_invalid', [], $language), [
            'status_id' => translate('desembarques.validation.status_invalid', [], $language),
        ]);
    }

    $statusLookup = $connection->prepare('SELECT id FROM desembarque_statuses WHERE id = ? AND is_active = 1 LIMIT 1');

    if (! $statusLookup instanceof mysqli_stmt) {
        api_v1_error(500, translate('desembarques.validation.status_lookup_failed', [], $language));
    }

    $statusLookup->bind_param('i', $statusId);
    $statusLookup->execute();
    $statusResult = $statusLookup->get_result();
    $statusRecord = $statusResult ? $statusResult->fetch_assoc() : null;
    $statusLookup->close();

    if (! $statusRecord) {
        api_v1_error(422, translate('desembarques.validation.status_not_found', [], $language), [
            'status_id' => translate('desembarques.validation.status_not_found', [], $language),
        ]);
    }

    $newDesembarqueId = null;
    $storedAttachments = [];

    try {
        $connection->begin_transaction();

        $insertQuery = 'INSERT INTO desembarques (referencia, fecha_desembarque, descripcion, destino, folio_aviso, fecha_embarque, barco, cliente, status_id, dias_transcurridos, dias_fuera, client_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $statement = $connection->prepare($insertQuery);

        if (! $statement instanceof mysqli_stmt) {
            throw new RuntimeException('Unable to prepare the desembarque statement.');
        }

        $statement->bind_param(
            'ssssssssiiiii',
            $referencia,
            $fechaDesembarqueValue,
            $descripcion,
            $destino,
            $folioAviso,
            $fechaEmbarqueValue,
            $barco,
            $cliente,
            $statusId,
            $diasTranscurridos,
            $diasFuera,
            $clienteId,
            $actorId
        );

        $statement->execute();
        $newDesembarqueId = (int) $connection->insert_id;
        $statement->close();

        if ($pendingAttachments !== []) {
            foreach ($pendingAttachments as $attachment) {
                $storedFile = store_uploaded_file($attachment['file'], $attachment['metadata'], $fileConfig);

                try {
                    $fileSize = (int) $storedFile['size'];
                    $attachmentExtension = strtolower((string) $storedFile['extension']);
                    $isCaseDocument = in_array($attachmentExtension, ['pdf', 'doc', 'docx'], true);
                    $purpose = $isCaseDocument ? 'case_document' : 'attachment';
                    $documentType = $isCaseDocument ? 'other' : null;
                    $computedHash = hash_file('sha256', (string) $storedFile['storage_path']);
                    $attachmentSha256 = is_string($computedHash) ? strtolower($computedHash) : null;

                    $fileStatement = $connection->prepare('INSERT INTO desembarque_files (desembarque_id, uploaded_by, original_name, stored_name, mime_type, extension, size, purpose, document_type, sha256, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');

                    if (! $fileStatement instanceof mysqli_stmt) {
                        delete_stored_file($storedFile['stored_name'], $fileConfig);
                        throw new RuntimeException('Unable to prepare attachment statement.');
                    }

                    $fileStatement->bind_param(
                        'iissssisss',
                        $newDesembarqueId,
                        $actorId,
                        $storedFile['original_name'],
                        $storedFile['stored_name'],
                        $storedFile['mime_type'],
                        $storedFile['extension'],
                        $fileSize,
                        $purpose,
                        $documentType,
                        $attachmentSha256
                    );

                    $fileStatement->execute();
                    $storedFile['id'] = (int) $connection->insert_id;
                    $storedFile['purpose'] = $purpose;
                    $storedFile['document_type'] = $documentType;
                    $storedFile['sha256'] = $attachmentSha256;
                    $storedFile['is_active'] = 1;
                    $fileStatement->close();
                    $storedAttachments[] = $storedFile;
                } catch (Throwable $fileException) {
                    if (isset($fileStatement) && $fileStatement instanceof mysqli_stmt) {
                        $fileStatement->close();
                    }

                    delete_stored_file($storedFile['stored_name'], $fileConfig);

                    throw $fileException;
                }
            }
        }

        $auditAfter = [
            'referencia' => $referencia,
            'fecha_desembarque' => $fechaDesembarqueValue,
            'descripcion' => $descripcion,
            'destino' => $destino,
            'folio_aviso' => $folioAviso,
            'fecha_embarque' => $fechaEmbarqueValue,
            'barco' => $barco,
            'cliente' => $cliente,
            'status_id' => $statusId,
            'dias_transcurridos' => $diasTranscurridos,
            'dias_fuera' => $diasFuera,
            'client_id' => $clienteId,
            'created_by' => $actorId,
            'attachments' => array_map(
                static function (array $attachment): array {
                    return [
                        'id' => (int) ($attachment['id'] ?? 0),
                        'original_name' => (string) $attachment['original_name'],
                        'mime_type' => (string) $attachment['mime_type'],
                        'extension' => (string) $attachment['extension'],
                        'size' => (int) $attachment['size'],
                    ];
                },
                $storedAttachments
            ),
        ];

        $changes = compute_audit_changes([], $auditAfter);

        record_audit_log(
            'create',
            'desembarque',
            (string) $newDesembarqueId,
            [
                'before' => null,
                'after' => $auditAfter,
                'changes' => $changes,
            ],
            $actorId,
            $connection
        );

        $connection->commit();
    } catch (Throwable $exception) {
        if (isset($connection) && $connection instanceof mysqli) {
            $connection->rollback();
        }

        foreach ($storedAttachments as $stored) {
            if (isset($stored['stored_name'])) {
                delete_stored_file((string) $stored['stored_name'], $fileConfig);
            }
        }

        throw $exception;
    }

    api_v1_success(
        [
            'id' => $newDesembarqueId,
            'referencia' => $referencia,
            'fecha_desembarque' => $fechaDesembarqueValue,
            'fecha_embarque' => $fechaEmbarqueValue,
            'descripcion' => $descripcion,
            'destino' => $destino,
            'folio_aviso' => $folioAviso,
            'barco' => $barco,
            'cliente' => $cliente,
            'client_id' => $clienteId,
            'status_id' => $statusId,
            'dias_transcurridos' => $diasTranscurridos,
            'dias_fuera' => $diasFuera,
            'attachments' => array_map(
                static function (array $attachment): array {
                    return [
                        'id' => (int) ($attachment['id'] ?? 0),
                        'original_name' => (string) $attachment['original_name'],
                        'mime_type' => (string) $attachment['mime_type'],
                        'extension' => (string) $attachment['extension'],
                        'size' => (int) $attachment['size'],
                    ];
                },
                $storedAttachments
            ),
        ],
        [
            'rate_limit' => $auth['rate_limit'],
        ],
        201
    );
} catch (Throwable $exception) {
    api_v1_handle_exception($exception, $language);
}

function clean_api_string($value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

/**
 * @param array<string, string> $errors
 */
function parse_api_date(string $value, string $field, array &$errors, string $language, bool $required = true): ?DateTimeImmutable
{
    $value = trim($value);

    if ($value === '') {
        if ($required) {
            $errors[$field] = translate('validation.field_required', [], $language);
        }

        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (! $date || $date->format('Y-m-d') !== $value) {
        $errors[$field] = translate('validation.invalid_date', [], $language);
        return null;
    }

    return $date;
}
