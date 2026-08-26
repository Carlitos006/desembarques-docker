<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/audit.php';
require_once dirname(__DIR__, 2) . '/config/files.php';
require_once dirname(__DIR__, 2) . '/config/mail.php';

$language = api_v1_detect_language();

try {
    api_v1_require_method(['PUT', 'PATCH', 'POST'], $language);
    $auth = authenticate_api_request(['desembarques:write'], $language);
    $user = $auth['user'];
    $actorId = (int) ($user['id'] ?? 0);
    $userEmail = (string) ($user['email'] ?? '');

    $payload = api_v1_parse_body();
    $idInput = $payload['id'] ?? ($_GET['id'] ?? ($_POST['id'] ?? ''));
    $idValue = is_string($idInput) ? trim($idInput) : (is_numeric($idInput) ? (string) $idInput : '');

    if ($idValue === '' || ! ctype_digit($idValue)) {
        api_v1_error(422, translate('validation.errors', [], $language), [
            'id' => translate('desembarques.validation.id_invalid', [], $language),
        ]);
    }

    $id = (int) $idValue;

    /** @var mysqli $connection */
    $connection = getDatabaseConnection();

    $lookup = $connection->prepare('SELECT id, referencia, fecha_desembarque, descripcion, destino, folio_aviso, fecha_embarque, barco, cliente, status_id, dias_transcurridos, dias_fuera, client_id FROM desembarques WHERE id = ? AND deleted_at IS NULL LIMIT 1');

    if (! $lookup instanceof mysqli_stmt) {
        api_v1_error(500, translate('desembarques.update.error_prepare_statement', [], $language));
    }

    $lookup->bind_param('i', $id);
    $lookup->execute();
    $result = $lookup->get_result();
    $record = $result ? $result->fetch_assoc() : null;
    $lookup->close();

    if (! $record) {
        api_v1_error(404, translate('desembarques.update.not_found', [], $language));
    }

    $existingRecord = [
        'referencia' => (string) ($record['referencia'] ?? ''),
        'fecha_desembarque' => isset($record['fecha_desembarque']) && $record['fecha_desembarque'] !== null
            ? (string) $record['fecha_desembarque']
            : null,
        'descripcion' => (string) ($record['descripcion'] ?? ''),
        'destino' => (string) ($record['destino'] ?? ''),
        'folio_aviso' => (string) ($record['folio_aviso'] ?? ''),
        'fecha_embarque' => isset($record['fecha_embarque']) && $record['fecha_embarque'] !== null
            ? (string) $record['fecha_embarque']
            : null,
        'barco' => (string) ($record['barco'] ?? ''),
        'cliente' => (string) ($record['cliente'] ?? ''),
        'status_id' => isset($record['status_id']) && $record['status_id'] !== null
            ? (int) $record['status_id']
            : null,
        'dias_transcurridos' => isset($record['dias_transcurridos']) && $record['dias_transcurridos'] !== null
            ? (int) $record['dias_transcurridos']
            : null,
        'dias_fuera' => isset($record['dias_fuera']) && $record['dias_fuera'] !== null
            ? (int) $record['dias_fuera']
            : null,
        'client_id' => isset($record['client_id']) && $record['client_id'] !== null
            ? (int) $record['client_id']
            : null,
    ];
    $previousStatusId = isset($existingRecord['status_id']) ? (int) $existingRecord['status_id'] : null;

    $fileConfig = file_storage_config();
    $existingAttachments = [];
    $existingAttachmentsById = [];

    $attachmentsStatement = $connection->prepare('SELECT id, original_name, stored_name, mime_type, extension, size, uploaded_by, purpose FROM desembarque_files WHERE desembarque_id = ? ORDER BY id ASC');

    if (! $attachmentsStatement instanceof mysqli_stmt) {
        api_v1_error(500, translate('desembarques.files.load_error', [], $language));
    }

    $attachmentsStatement->bind_param('i', $id);
    $attachmentsStatement->execute();
    $attachmentsResult = $attachmentsStatement->get_result();

    if ($attachmentsResult instanceof mysqli_result) {
        while ($attachmentRow = $attachmentsResult->fetch_assoc()) {
            $attachmentId = isset($attachmentRow['id']) ? (int) $attachmentRow['id'] : 0;
            $storedName = (string) ($attachmentRow['stored_name'] ?? '');

            if ($attachmentId <= 0 || $storedName === '') {
                continue;
            }

            $attachment = [
                'id' => $attachmentId,
                'original_name' => (string) ($attachmentRow['original_name'] ?? ''),
                'stored_name' => $storedName,
                'mime_type' => (string) ($attachmentRow['mime_type'] ?? ''),
                'extension' => (string) ($attachmentRow['extension'] ?? ''),
                'size' => isset($attachmentRow['size']) ? (int) $attachmentRow['size'] : 0,
                'uploaded_by' => isset($attachmentRow['uploaded_by']) ? (int) $attachmentRow['uploaded_by'] : null,
                'purpose' => (string) ($attachmentRow['purpose'] ?? 'attachment'),
            ];

            $existingAttachments[] = $attachment;
            $existingAttachmentsById[$attachmentId] = $attachment;
        }
    }

    $attachmentsStatement->close();

    $existingRecord['attachments'] = array_map(
        static function (array $attachment): array {
            return [
                'id' => (int) ($attachment['id'] ?? 0),
                'original_name' => (string) ($attachment['original_name'] ?? ''),
                'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                'extension' => (string) ($attachment['extension'] ?? ''),
                'size' => (int) ($attachment['size'] ?? 0),
                'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
            ];
        },
        $existingAttachments
    );

    $referencia = clean_api_string($payload['referencia'] ?? ($existingRecord['referencia'] ?? ''), 100);
    $folioAviso = clean_api_string($payload['folio_aviso'] ?? ($existingRecord['folio_aviso'] ?? ''), 100);
    $descripcion = clean_api_string($payload['descripcion'] ?? ($existingRecord['descripcion'] ?? ''), 1000);
    $destino = clean_api_string($payload['destino'] ?? ($existingRecord['destino'] ?? ''), 150);
    $barco = clean_api_string($payload['barco'] ?? ($existingRecord['barco'] ?? ''), 150);
    $cliente = clean_api_string($payload['cliente'] ?? ($existingRecord['cliente'] ?? ''), 150);
    $clienteIdInput = isset($payload['cliente_id']) ? trim((string) $payload['cliente_id']) : (string) ($existingRecord['client_id'] ?? '');
    $statusIdInput = isset($payload['status_id']) ? trim((string) $payload['status_id']) : (($existingRecord['status_id'] ?? null) !== null ? (string) $existingRecord['status_id'] : '');
    $fechaDesembarqueInput = isset($payload['fecha_desembarque']) ? (string) $payload['fecha_desembarque'] : ($existingRecord['fecha_desembarque'] ?? '');
    $fechaEmbarqueInput = isset($payload['fecha_embarque']) ? (string) $payload['fecha_embarque'] : ($existingRecord['fecha_embarque'] ?? '');

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

    $deleteAttachmentsInput = $payload['delete_attachments'] ?? ($_POST['delete_attachments'] ?? []);

    if ($deleteAttachmentsInput !== null && $deleteAttachmentsInput !== '' && ! is_array($deleteAttachmentsInput)) {
        $deleteAttachmentsInput = [$deleteAttachmentsInput];
    }

    $attachmentsToDelete = [];

    if (is_array($deleteAttachmentsInput)) {
        foreach ($deleteAttachmentsInput as $deleteValue) {
            $value = trim((string) $deleteValue);

            if ($value === '') {
                continue;
            }

            if (! ctype_digit($value)) {
                $errors['delete_attachments'] = translate('desembarques.files.delete_invalid', [], $language);
                break;
            }

            $attachmentId = (int) $value;

            if (! isset($existingAttachmentsById[$attachmentId])) {
                $errors['delete_attachments'] = translate('desembarques.files.delete_not_found', [], $language);
                break;
            }

            $attachmentToDelete = $existingAttachmentsById[$attachmentId];
            $deletePurpose = (string) ($attachmentToDelete['purpose'] ?? '');
            $deleteExtension = strtolower((string) ($attachmentToDelete['extension'] ?? ''));
            if ($deletePurpose === 'source_excel') {
                $errors['delete_attachments'] = translate('desembarques.files.delete_not_found', [], $language);
                break;
            }
            if ($deletePurpose === 'case_document' || in_array($deleteExtension, ['pdf', 'doc', 'docx'], true)) {
                $errors['delete_attachments'] = $language === 'en'
                    ? 'Case file documents are not deleted. Replace them from the notice case file.'
                    : 'Los documentos del expediente no se eliminan. Reemplázalos desde el expediente del aviso.';
                break;
            }

            $attachmentsToDelete[$attachmentId] = $attachmentToDelete;
        }
    }

    $pendingAttachments = [];
    $newUploads = [];

    if (isset($_FILES['attachments']) && is_array($_FILES['attachments'])) {
        $normalizedUploads = normalize_uploaded_files_array($_FILES['attachments']);

        foreach ($normalizedUploads as $upload) {
            if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $newUploads[] = $upload;
        }
    }

    $maxFilesPerRequest = isset($fileConfig['max_files_per_request']) ? (int) $fileConfig['max_files_per_request'] : 0;

    if ($maxFilesPerRequest > 0 && count($newUploads) > $maxFilesPerRequest) {
        $errors['attachments'] = translate('desembarques.files.error_too_many', ['max' => $maxFilesPerRequest], $language);
    } else {
        foreach ($newUploads as $upload) {
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

    if ($errors !== []) {
        api_v1_error(422, translate('validation.errors', [], $language), $errors);
    }

    $fechaDesembarqueValue = $fechaDesembarque instanceof DateTimeImmutable ? $fechaDesembarque->format('Y-m-d') : null;
    $fechaEmbarqueValue = $fechaEmbarque instanceof DateTimeImmutable ? $fechaEmbarque->format('Y-m-d') : null;

    $today = new DateTimeImmutable('today');
    $diasTranscurridos = $fechaDesembarque instanceof DateTimeImmutable
        ? max(0, (int) $fechaDesembarque->diff($today)->format('%r%a'))
        : (int) ($existingRecord['dias_transcurridos'] ?? 0);
    $diasFuera = $fechaDesembarque instanceof DateTimeImmutable && $fechaEmbarque instanceof DateTimeImmutable
        ? max(0, (int) $fechaDesembarque->diff($fechaEmbarque)->format('%r%a'))
        : (int) ($existingRecord['dias_fuera'] ?? 0);

    if ($clienteId === null && isset($existingRecord['client_id']) && $existingRecord['client_id'] !== null) {
        $clienteId = (int) $existingRecord['client_id'];
    }

    $selectedClient = null;

    if ($clienteId !== null) {
        $clientLookup = $connection->prepare('SELECT id, name, email FROM clients WHERE id = ? LIMIT 1');

        if (! $clientLookup instanceof mysqli_stmt) {
            api_v1_error(500, translate('desembarques.validation.client_lookup_failed', [], $language));
        }

        $clientLookup->bind_param('i', $clienteId);
        $clientLookup->execute();
        $clientResult = $clientLookup->get_result();
        $client = $clientResult ? $clientResult->fetch_assoc() : null;
        $clientLookup->close();

        if (! $client) {
            api_v1_error(422, translate('desembarques.validation.client_not_found', [], $language), [
                'cliente_id' => translate('desembarques.validation.client_not_found', [], $language),
            ]);
        }

        $selectedClient = $client;

        if ($cliente === '' && isset($client['name'])) {
            $cliente = clean_api_string((string) $client['name'], 150);
        }

        if ($cliente === '' && isset($client['email'])) {
            $cliente = clean_api_string((string) $client['email'], 150);
        }
    }

    if ($statusId === null) {
        api_v1_error(422, translate('desembarques.validation.status_invalid', [], $language), [
            'status_id' => translate('desembarques.validation.status_invalid', [], $language),
        ]);
    }

    $statusLookup = $connection->prepare('SELECT id, slug, name_es, name_en FROM desembarque_statuses WHERE id = ? AND is_active = 1 LIMIT 1');

    if (! $statusLookup instanceof mysqli_stmt) {
        api_v1_error(500, translate('desembarques.validation.status_lookup_failed', [], $language));
    }

    $statusLookup->bind_param('i', $statusId);
    $statusLookup->execute();
    $statusResult = $statusLookup->get_result();
    $statusRow = $statusResult ? $statusResult->fetch_assoc() : null;
    $statusLookup->close();

    if (! $statusRow) {
        api_v1_error(422, translate('desembarques.validation.status_not_found', [], $language), [
            'status_id' => translate('desembarques.validation.status_not_found', [], $language),
        ]);
    }

    $statusLabel = $language === 'en'
        ? (string) ($statusRow['name_en'] ?? '')
        : (string) ($statusRow['name_es'] ?? '');

    if ($statusLabel === '') {
        $statusLabel = (string) ($statusRow['slug'] ?? '');
    }

    $statusChanged = $previousStatusId !== null && $statusId !== $previousStatusId;

    $attachmentsScheduledForDeletion = array_values($attachmentsToDelete);
    $newStoredAttachments = [];

    try {
        $connection->begin_transaction();

        $updateQuery = 'UPDATE desembarques SET referencia = ?, fecha_desembarque = ?, descripcion = ?, destino = ?, folio_aviso = ?, fecha_embarque = ?, barco = ?, cliente = ?, status_id = ?, dias_transcurridos = ?, dias_fuera = ?, client_id = ? WHERE id = ? AND deleted_at IS NULL';
        $updateStatement = $connection->prepare($updateQuery);

        if (! $updateStatement instanceof mysqli_stmt) {
            throw new RuntimeException('Unable to prepare the desembarque update statement.');
        }

        $clienteIdValue = $clienteId;
        $updateStatement->bind_param(
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
            $clienteIdValue,
            $id
        );

        $updateStatement->execute();
        $updateStatement->close();

        if ($attachmentsToDelete !== []) {
            $deleteStatement = $connection->prepare('DELETE FROM desembarque_files WHERE id = ? AND desembarque_id = ? LIMIT 1');

            if (! $deleteStatement instanceof mysqli_stmt) {
                throw new RuntimeException('Unable to prepare the attachment deletion statement.');
            }

            foreach ($attachmentsToDelete as $attachmentId => $attachmentData) {
                $deleteStatement->bind_param('ii', $attachmentId, $id);
                $deleteStatement->execute();
            }

            $deleteStatement->close();
        }

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
                        throw new RuntimeException('Unable to prepare the attachment statement.');
                    }

                    $fileStatement->bind_param(
                        'iissssisss',
                        $id,
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
                    $storedFile['uploaded_by'] = $actorId;
                    $storedFile['purpose'] = $purpose;
                    $storedFile['document_type'] = $documentType;
                    $storedFile['sha256'] = $attachmentSha256;
                    $storedFile['is_active'] = 1;
                    $fileStatement->close();
                    $newStoredAttachments[] = $storedFile;
                } catch (Throwable $attachmentException) {
                    if (isset($fileStatement) && $fileStatement instanceof mysqli_stmt) {
                        $fileStatement->close();
                    }

                    delete_stored_file($storedFile['stored_name'], $fileConfig);

                    throw $attachmentException;
                }
            }
        }

        $attachmentsAfter = [];

        foreach ($existingAttachments as $attachment) {
            if (isset($attachmentsToDelete[$attachment['id']])) {
                continue;
            }

            $attachmentsAfter[] = [
                'id' => (int) $attachment['id'],
                'original_name' => (string) $attachment['original_name'],
                'mime_type' => (string) $attachment['mime_type'],
                'extension' => (string) $attachment['extension'],
                'size' => (int) $attachment['size'],
                'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
            ];
        }

        foreach ($newStoredAttachments as $attachment) {
            $attachmentsAfter[] = [
                'id' => (int) $attachment['id'],
                'original_name' => (string) $attachment['original_name'],
                'mime_type' => (string) $attachment['mime_type'],
                'extension' => (string) $attachment['extension'],
                'size' => (int) $attachment['size'],
                'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : $actorId,
            ];
        }

        $afterRecord = [
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
            'client_id' => $clienteIdValue,
            'attachments' => $attachmentsAfter,
        ];

        $changes = compute_audit_changes($existingRecord, $afterRecord);
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
                    ];
                },
                $newStoredAttachments
            ),
            'removed' => array_map(
                static function (array $attachment): array {
                    return [
                        'id' => (int) ($attachment['id'] ?? 0),
                        'original_name' => (string) ($attachment['original_name'] ?? ''),
                        'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                        'extension' => (string) ($attachment['extension'] ?? ''),
                        'size' => (int) ($attachment['size'] ?? 0),
                        'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
                    ];
                },
                $attachmentsScheduledForDeletion
            ),
        ];

        if ($changes !== [] || $attachmentChanges['added'] !== [] || $attachmentChanges['removed'] !== []) {
            record_audit_log(
                'update',
                'desembarque',
                (string) $id,
                [
                    'before' => $existingRecord,
                    'after' => $afterRecord,
                    'changes' => $changes,
                    'attachments' => $attachmentChanges,
                ],
                $actorId,
                $connection
            );
        }

        $connection->commit();
    } catch (Throwable $exception) {
        if (isset($connection) && $connection instanceof mysqli) {
            $connection->rollback();
        }

        foreach ($newStoredAttachments as $attachment) {
            if (isset($attachment['stored_name'])) {
                delete_stored_file((string) $attachment['stored_name'], $fileConfig);
            }
        }

        $errorKey = ($pendingAttachments !== [] || $attachmentsToDelete !== [])
            ? 'desembarques.files.update_error'
            : 'desembarques.update.error_failure';

        api_v1_error(500, translate($errorKey, ['error' => $exception->getMessage()], $language));
    }

    foreach ($attachmentsScheduledForDeletion as $attachment) {
        if (isset($attachment['stored_name'])) {
            delete_stored_file((string) $attachment['stored_name'], $fileConfig);
        }
    }

    if ($statusChanged) {
        $finalClientId = $clienteIdValue;

        if ($finalClientId === null && isset($existingRecord['client_id']) && $existingRecord['client_id'] !== null) {
            $finalClientId = (int) $existingRecord['client_id'];
        }

        $finalClientData = null;

        if ($finalClientId !== null && $finalClientId > 0) {
            if (is_array($selectedClient) && isset($selectedClient['id']) && (int) $selectedClient['id'] === $finalClientId) {
                $finalClientData = $selectedClient;
            } else {
                $notifyClientStatement = $connection->prepare('SELECT id, name, email FROM clients WHERE id = ? LIMIT 1');

                if ($notifyClientStatement instanceof mysqli_stmt) {
                    $notifyClientStatement->bind_param('i', $finalClientId);
                    $notifyClientStatement->execute();
                    $notifyResult = $notifyClientStatement->get_result();
                    $notifyRow = $notifyResult ? $notifyResult->fetch_assoc() : null;
                    $notifyClientStatement->close();

                    if ($notifyRow) {
                        $finalClientData = [
                            'id' => isset($notifyRow['id']) ? (int) $notifyRow['id'] : null,
                            'name' => isset($notifyRow['name']) ? (string) $notifyRow['name'] : '',
                            'email' => isset($notifyRow['email']) ? (string) $notifyRow['email'] : '',
                        ];
                    }
                }
            }
        }

        $clientEmail = '';
        $clientNameForEmail = '';

        if (is_array($finalClientData)) {
            $clientEmail = trim((string) ($finalClientData['email'] ?? ''));
            $clientNameForEmail = trim((string) ($finalClientData['name'] ?? ''));
        }

        $legacyClientValue = trim((string) ($cliente ?? ''));

        if ($clientNameForEmail === '' && $legacyClientValue !== '') {
            $clientNameForEmail = $legacyClientValue;
        }

        if ($clientEmail === '' && $legacyClientValue !== '' && filter_var($legacyClientValue, FILTER_VALIDATE_EMAIL)) {
            $clientEmail = $legacyClientValue;
        }

        if ($clientEmail !== '' && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            $normalizedClientEmail = mb_strtolower($clientEmail);
            $normalizedActorEmail = mb_strtolower($userEmail);

            if ($normalizedClientEmail === '' || $normalizedClientEmail !== $normalizedActorEmail) {
                sendDesembarqueStatusChangeEmail(
                    $clientEmail,
                    $clientNameForEmail,
                    $referencia,
                    $statusLabel,
                    $language
                );
            }
        }
    }

    api_v1_success(
        [
            'id' => $id,
            'referencia' => $referencia,
            'fecha_desembarque' => $fechaDesembarqueValue,
            'fecha_embarque' => $fechaEmbarqueValue,
            'descripcion' => $descripcion,
            'destino' => $destino,
            'folio_aviso' => $folioAviso,
            'barco' => $barco,
            'cliente' => $cliente,
            'client_id' => $clienteIdValue,
            'status_id' => $statusId,
            'dias_transcurridos' => $diasTranscurridos,
            'dias_fuera' => $diasFuera,
            'attachments' => array_map(
                static function (array $attachment): array {
                    return [
                        'id' => (int) ($attachment['id'] ?? 0),
                        'original_name' => (string) ($attachment['original_name'] ?? ''),
                        'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                        'extension' => (string) ($attachment['extension'] ?? ''),
                        'size' => (int) ($attachment['size'] ?? 0),
                        'uploaded_by' => isset($attachment['uploaded_by']) ? (int) $attachment['uploaded_by'] : null,
                    ];
                },
                array_merge(
                    array_filter($existingAttachments, static function (array $attachment) use ($attachmentsToDelete): bool {
                        return ! isset($attachmentsToDelete[$attachment['id']]);
                    }),
                    $newStoredAttachments
                )
            ),
        ],
        [
            'rate_limit' => $auth['rate_limit'],
        ]
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
