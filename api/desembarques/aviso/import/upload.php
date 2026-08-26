<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_import_require_internal();
aviso_import_validate_csrf();

$clientIdRaw = trim((string) ($_POST['client_id'] ?? ''));
if ($clientIdRaw === '' || ! ctype_digit($clientIdRaw) || (int) $clientIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'Selecciona el cliente al que pertenecen estos avisos históricos.']);
}
$clientId = (int) $clientIdRaw;
$defaultAvisoStatus = aviso_status_normalize((string) ($_POST['default_aviso_status'] ?? 'presented'));
if (! in_array($defaultAvisoStatus, ['issued', 'presented', 'replaced', 'cancelled'], true)) {
    $defaultAvisoStatus = 'presented';
}

$operationalStatusRaw = trim((string) ($_POST['operational_status_id'] ?? ''));
$operationalStatusId = null;
if ($operationalStatusRaw !== '') {
    if (! ctype_digit($operationalStatusRaw) || (int) $operationalStatusRaw <= 0) {
        aviso_json(422, ['success' => false, 'message' => 'El estado operativo seleccionado no es válido.']);
    }
    $operationalStatusId = (int) $operationalStatusRaw;
}

$files = normalize_uploaded_files_array($_FILES['files'] ?? []);
if ($files === []) {
    aviso_json(422, ['success' => false, 'message' => 'Selecciona al menos un PDF o Word (.doc/.docx).']);
}
$uploadConfig = historical_import_upload_config();
$maxFiles = (int) ($uploadConfig['max_files'] ?? 20);
$maxFileSizeMb = (int) ($uploadConfig['max_file_size_mb'] ?? 250);
$maxBatchSizeMb = (int) ($uploadConfig['max_batch_size_mb'] ?? 300);

if (count($files) > $maxFiles) {
    aviso_json(422, ['success' => false, 'message' => sprintf('Puedes analizar hasta %d archivos por lote.', $maxFiles)]);
}

$connection = getDatabaseConnection();
$clientResult = $connection->execute_query('SELECT id, name, email FROM clients WHERE id = ? LIMIT 1', [$clientId]);
$client = $clientResult instanceof mysqli_result ? $clientResult->fetch_assoc() : null;
if (! $client) {
    aviso_json(422, ['success' => false, 'message' => 'El cliente seleccionado no existe.']);
}

if ($operationalStatusId !== null) {
    $statusResult = $connection->execute_query(
        'SELECT id FROM desembarque_statuses WHERE id = ? AND is_active = 1 LIMIT 1',
        [$operationalStatusId]
    );
    if (! ($statusResult instanceof mysqli_result) || ! $statusResult->fetch_assoc()) {
        aviso_json(422, ['success' => false, 'message' => 'El estado operativo seleccionado no está disponible.']);
    }
} else {
    $statusResult = $connection->query(
        "SELECT id FROM desembarque_statuses WHERE is_active = 1 ORDER BY (slug = 'completed') DESC, is_default DESC, id ASC LIMIT 1"
    );
    $statusRow = $statusResult instanceof mysqli_result ? $statusResult->fetch_assoc() : null;
    $operationalStatusId = $statusRow ? (int) $statusRow['id'] : null;
}
if ($operationalStatusId === null) {
    aviso_json(422, ['success' => false, 'message' => 'No existe un estado operativo activo para registrar los avisos históricos.']);
}

$maxSize = $maxFileSizeMb * 1024 * 1024;
$maxBatchSize = $maxBatchSizeMb * 1024 * 1024;
$totalBatchSize = array_reduce(
    $files,
    static fn (int $total, array $file): int => $total + max(0, (int) ($file['size'] ?? 0)),
    0
);

if ($totalBatchSize > $maxBatchSize) {
    aviso_json(422, [
        'success' => false,
        'message' => sprintf(
            'El lote completo pesa %.2f MB. El máximo permitido es %d MB por lote.',
            $totalBatchSize / 1024 / 1024,
            $maxBatchSizeMb
        ),
    ]);
}

$validated = [];
foreach ($files as $file) {
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        aviso_json(422, ['success' => false, 'message' => 'Uno de los archivos no pudo subirse correctamente.']);
    }
    $name = trim((string) $file['name']);
    $ext = aviso_import_safe_extension($name);
    if (! in_array($ext, ['pdf', 'doc', 'docx'], true)) {
        aviso_json(422, ['success' => false, 'message' => 'La importación histórica acepta únicamente PDF, DOC y DOCX.']);
    }
    $size = (int) $file['size'];
    if ($size <= 0 || $size > $maxSize) {
        aviso_json(422, [
            'success' => false,
            'message' => sprintf(
                '%s pesa %.2f MB. Cada archivo debe pesar entre 1 byte y %d MB.',
                $name,
                $size / 1024 / 1024,
                $maxFileSizeMb
            ),
        ]);
    }
    $tmp = (string) $file['tmp_name'];
    if ($tmp === '' || ! is_uploaded_file($tmp)) {
        aviso_json(422, ['success' => false, 'message' => 'No fue posible validar uno de los archivos cargados.']);
    }
    $head = @file_get_contents($tmp, false, null, 0, 8);
    if ($ext === 'pdf' && (! is_string($head) || ! str_starts_with($head, '%PDF-'))) {
        aviso_json(422, ['success' => false, 'message' => sprintf('%s no parece ser un PDF válido.', $name)]);
    }
    if ($ext === 'docx' && (! is_string($head) || ! str_starts_with($head, 'PK'))) {
        aviso_json(422, ['success' => false, 'message' => sprintf('%s no parece ser un DOCX válido.', $name)]);
    }
    if ($ext === 'doc') {
        $oleHeader = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        if (! is_string($head) || $head !== $oleHeader) {
            aviso_json(422, ['success' => false, 'message' => sprintf('%s no parece ser un Word .doc válido.', $name)]);
        }
    }
    $validated[] = array_merge($file, ['extension' => $ext]);
}

$publicId = bin2hex(random_bytes(16));
$batchDir = aviso_import_batch_dir($publicId);
$saved = [];

try {
    $connection->begin_transaction();
    $connection->execute_query(
        'INSERT INTO aviso_import_batches (public_id, client_id, default_aviso_status, default_operational_status_id, status, created_by) VALUES (?,?,?,?,?,?)',
        [$publicId, $clientId, $defaultAvisoStatus, $operationalStatusId, 'processing', (int) $user['id']]
    );
    $batchId = (int) $connection->insert_id;

    foreach ($validated as $file) {
        $ext = (string) $file['extension'];
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = $batchDir . DIRECTORY_SEPARATOR . $stored;
        if (! move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('No fue posible guardar temporalmente ' . (string) $file['name'] . '.');
        }
        $sha = hash_file('sha256', $target);
        if (! is_string($sha) || strlen($sha) !== 64) {
            throw new RuntimeException('No fue posible calcular la huella SHA-256 de ' . (string) $file['name'] . '.');
        }
        $saved[] = [
            'name' => (string) $file['name'],
            'stored' => $stored,
            'path' => $target,
            'extension' => $ext,
            'size' => (int) $file['size'],
            'sha256' => $sha,
        ];
    }

    // Phase 5E5 hardening: reject exact duplicate uploads inside the same batch.
    // This avoids ambiguous review rows and accidental double-counting before any
    // business record is created.
    $seenUploadSha = [];
    foreach ($saved as $entry) {
        $sha = strtolower((string) ($entry['sha256'] ?? ''));
        if ($sha !== '' && isset($seenUploadSha[$sha])) {
            throw new RuntimeException(
                'El lote contiene el mismo archivo más de una vez: '
                . (string) $seenUploadSha[$sha] . ' / ' . (string) $entry['name']
            );
        }
        if ($sha !== '') {
            $seenUploadSha[$sha] = (string) $entry['name'];
        }
    }

    $wordCandidates = [];
    $pdfCandidates = [];
    foreach ($saved as $entry) {
        if (in_array($entry['extension'], ['doc', 'docx'], true)) {
            $extracted = HistoricalAvisoExtractor::extractWord($entry['path'], $entry['name'], $entry['extension']);
            $parser = is_array($extracted['parser'] ?? null) ? $extracted['parser'] : ['data' => []];
            $notice = $parser['data']['notice_number'] ?? aviso_import_notice_from_filename($entry['name']);
            $key = aviso_import_identity_key($entry['name'], is_string($notice) ? $notice : null);
            $entry['extraction'] = $extracted;
            $entry['notice'] = is_string($notice) ? $notice : null;
            $wordCandidates[$key][] = $entry;
        } else {
            $extracted = HistoricalAvisoExtractor::extractPdf($entry['path'], $entry['name']);
            $parser = is_array($extracted['parser'] ?? null) ? $extracted['parser'] : ['data' => []];
            $notice = $parser['data']['notice_number'] ?? aviso_import_notice_from_filename($entry['name']);
            $key = aviso_import_identity_key($entry['name'], is_string($notice) ? $notice : null);
            $entry['extraction'] = $extracted;
            $entry['notice'] = is_string($notice) ? $notice : null;
            $pdfCandidates[$key][] = $entry;
        }
    }

    // Phase 5E5 hardening: one historical notice must have at most one final PDF
    // and one Word companion in a batch. Multiple candidates with the same notice
    // are ambiguous and must be split into separate batches/reviewed explicitly.
    foreach ($pdfCandidates as $key => $candidates) {
        if (count($candidates) > 1) {
            $names = array_map(static fn (array $candidate): string => (string) ($candidate['name'] ?? 'archivo'), $candidates);
            throw new RuntimeException('Hay más de un PDF para el mismo aviso (' . $key . '): ' . implode(', ', $names));
        }
    }
    foreach ($wordCandidates as $key => $candidates) {
        if (count($candidates) > 1) {
            $names = array_map(static fn (array $candidate): string => (string) ($candidate['name'] ?? 'archivo'), $candidates);
            throw new RuntimeException('Hay más de un Word para el mismo aviso (' . $key . '): ' . implode(', ', $names));
        }
    }

    $rowCount = 0;
    $counts = ['ready' => 0, 'review_required' => 0, 'duplicate' => 0];
    $usedDocx = [];

    $insertRow = static function (
        ?array $pdf,
        ?array $docx,
        mysqli $connection,
        int $batchId,
        int $clientId,
        array &$counts,
        int &$rowCount
    ) use ($defaultAvisoStatus): void {
        $pdfExtraction = is_array($pdf['extraction'] ?? null) ? $pdf['extraction'] : null;
        $docxExtraction = is_array($docx['extraction'] ?? null) ? $docx['extraction'] : null;

        $isScanned = (bool) ($pdfExtraction['is_scanned'] ?? false);
        $selectedExtraction = null;
        $source = 'unknown';
        $errorMessage = null;

        if ($pdf !== null && ! $isScanned && trim((string) ($pdfExtraction['text'] ?? '')) !== '') {
            $selectedExtraction = $pdfExtraction;
            $source = 'pdf_text';
        } elseif ($docx !== null && trim((string) ($docxExtraction['text'] ?? '')) !== '') {
            $selectedExtraction = $docxExtraction;
            $source = 'word_companion';
        } elseif ($pdf !== null && $isScanned) {
            $selectedExtraction = $pdfExtraction;
            $source = 'pdf_scan';

            if ($docx !== null) {
                $wordError = trim(
                    (string) ($docxExtraction['error'] ?? '')
                );

                $errorMessage = $wordError !== ''
                    ? 'El Word compañero fue recibido, pero no pudo procesarse: '
                        . $wordError
                    : 'El Word compañero fue recibido, pero no produjo texto utilizable.';
            } else {
                $errorMessage =
                    'El PDF es escaneado y no contiene texto utilizable. '
                    . 'Añade el Word original del mismo aviso.';
            }
        } elseif ($docx !== null) {
            $selectedExtraction = $docxExtraction;
            $source = 'word_only';
            $errorMessage = 'Se encontró el Word, pero falta el PDF final que debe conservarse como documento histórico original.';
        }

        $parser = is_array($selectedExtraction['parser'] ?? null) ? $selectedExtraction['parser'] : HistoricalAvisoParser::parse('', (string) (($pdf['name'] ?? $docx['name'] ?? '')));
        $data = is_array($parser['data'] ?? null) ? $parser['data'] : [];
        $confidence = is_array($parser['confidence'] ?? null) ? $parser['confidence'] : [];
        $notice = is_string($data['notice_number'] ?? null) && trim((string) $data['notice_number']) !== ''
            ? trim((string) $data['notice_number'])
            : ($pdf['notice'] ?? $docx['notice'] ?? null);
        $documentCode = is_string($data['document_code'] ?? null) ? trim((string) $data['document_code']) : null;
        if (($documentCode === null || $documentCode === '') && is_string($notice) && $notice !== '') {
            $documentCode = 'MADE-' . $notice;
        }

        $duplicate = aviso_import_detect_duplicate($connection, $clientId, is_string($notice) ? $notice : null, $pdf['sha256'] ?? null);
        $critical = (float) ($parser['critical_score'] ?? 0.0);
        $overall = (float) ($parser['overall_confidence'] ?? 0.0);

        if ($duplicate['desembarque_id'] !== null) {
            $status = 'duplicate';
        } elseif ($pdf === null) {
            $status = 'needs_pdf';
        } elseif ($isScanned && $docx === null) {
            $status = 'needs_companion';
        } elseif ($errorMessage !== null && $source === 'word_companion') {
            $status = 'review_required';
        } elseif ($critical >= 0.66 && is_string($notice) && $notice !== '') {
            $status = $critical >= 0.84 ? 'ready' : 'review_required';
        } else {
            $status = 'review_required';
        }

        if ($status === 'ready') {
            $counts['ready']++;
        } elseif ($status === 'duplicate') {
            $counts['duplicate']++;
        } else {
            $counts['review_required']++;
        }

        $normalizedJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $confidenceJson = json_encode($confidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rawText = (string) ($selectedExtraction['text'] ?? '');
        if ($rawText === '' && $errorMessage === null) {
            $errorMessage = (string) ($selectedExtraction['error'] ?? 'No se pudo obtener texto utilizable.');
        }

        $connection->execute_query(
            'INSERT INTO aviso_import_rows ('
            . 'batch_id, source_pdf_name, source_pdf_stored_name, source_pdf_sha256, source_pdf_size, source_pdf_page_count, '
            . 'source_docx_name, source_docx_stored_name, source_docx_sha256, source_docx_size, '
            . 'detected_notice_number, detected_document_code, extraction_source, extraction_status, is_scanned_pdf, '
            . 'raw_text, normalized_data, confidence_json, critical_score, overall_confidence, duplicate_desembarque_id, duplicate_reason, duplicate_kind, '
            . 'review_status, review_aviso_status, review_action, error_message'
            . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $batchId,
                $pdf['name'] ?? null,
                $pdf['stored'] ?? null,
                $pdf['sha256'] ?? null,
                $pdf['size'] ?? null,
                $pdfExtraction['page_count'] ?? null,
                $docx['name'] ?? null,
                $docx['stored'] ?? null,
                $docx['sha256'] ?? null,
                $docx['size'] ?? null,
                $notice,
                $documentCode,
                $source,
                $status,
                $isScanned ? 1 : 0,
                $rawText !== '' ? $rawText : null,
                $normalizedJson !== false ? $normalizedJson : null,
                $confidenceJson !== false ? $confidenceJson : null,
                $critical,
                $overall,
                $duplicate['desembarque_id'],
                $duplicate['reason'],
                $duplicate['kind'],
                'pending',
                $defaultAvisoStatus,
                $duplicate['desembarque_id'] !== null ? 'skip' : 'import_new',
                $errorMessage,
            ]
        );
        $rowCount++;
    };

    foreach ($pdfCandidates as $key => $pdfList) {
        foreach ($pdfList as $pdfIndex => $pdf) {
            $docx = null;
            if (isset($wordCandidates[$key][$pdfIndex])) {
                $docx = $wordCandidates[$key][$pdfIndex];
                $usedDocx[$key . ':' . $pdfIndex] = true;
            } elseif (isset($wordCandidates[$key][0]) && ! isset($usedDocx[$key . ':0'])) {
                $docx = $wordCandidates[$key][0];
                $usedDocx[$key . ':0'] = true;
            }
            $insertRow($pdf, $docx, $connection, $batchId, $clientId, $counts, $rowCount);
        }
    }

    foreach ($wordCandidates as $key => $docxList) {
        foreach ($docxList as $index => $docx) {
            if (isset($usedDocx[$key . ':' . $index])) {
                continue;
            }
            $insertRow(null, $docx, $connection, $batchId, $clientId, $counts, $rowCount);
        }
    }

    $batchCounts = aviso_import_recalculate_batch($connection, $batchId);

    record_audit_log('create', 'aviso_import_batch', (string) $batchId, [
        'public_id' => $publicId,
        'client_id' => $clientId,
        'client_name' => (string) ($client['name'] ?? ''),
        'files_received' => count($saved),
        'rows' => $rowCount,
        'ready' => $counts['ready'],
        'review' => $counts['review_required'],
        'duplicates' => $counts['duplicate'],
        'operational_status_id' => $operationalStatusId,
        'phase' => '5E3',
    ], (int) $user['id'], $connection);

    $connection->commit();
} catch (Throwable $exception) {
    try {
        $connection->rollback();
    } catch (Throwable) {
    }
    foreach ($saved as $entry) {
        if (isset($entry['path']) && is_string($entry['path'])) {
            @unlink($entry['path']);
        }
    }
    error_log('[historical-import] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible analizar el lote histórico.', 'detail' => $exception->getMessage()]);
}

$rowsResult = $connection->execute_query('SELECT * FROM aviso_import_rows WHERE batch_id = ? ORDER BY id ASC', [$batchId]);
$rows = [];
if ($rowsResult instanceof mysqli_result) {
    while ($row = $rowsResult->fetch_assoc()) {
        $rows[] = aviso_import_row_payload($connection, $row);
    }
}

aviso_json(201, [
    'success' => true,
    'message' => 'Lote analizado. Revisa los resultados antes de importar registros definitivos.',
    'batch' => [
        'public_id' => $publicId,
        'client_id' => $clientId,
        'client_name' => (string) ($client['name'] ?? ''),
        'default_aviso_status' => $defaultAvisoStatus,
        'default_operational_status_id' => $operationalStatusId,
        'total_rows' => (int) ($batchCounts['total_rows'] ?? $rowCount),
        'ready_rows' => (int) ($batchCounts['ready_rows'] ?? 0),
        'review_rows' => (int) ($batchCounts['review_rows'] ?? $rowCount),
        'duplicate_rows' => (int) ($batchCounts['duplicate_rows'] ?? $counts['duplicate']),
        'imported_rows' => 0,
        'skipped_rows' => 0,
    ],
    'rows' => $rows,
]);
