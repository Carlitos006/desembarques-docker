<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_require_user();
aviso_require_internal_user($user);

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody !== false ? $rawBody : '', true);

if (! is_array($payload)) {
    aviso_json(400, ['success' => false, 'message' => 'La solicitud no contiene JSON válido.']);
}

if (! validate_csrf_token(isset($payload['csrf_token']) ? (string) $payload['csrf_token'] : null)) {
    aviso_json(419, ['success' => false, 'message' => 'El token de seguridad no es válido. Recarga la página e inténtalo de nuevo.']);
}

$desembarqueIdRaw = trim((string) ($payload['desembarque_id'] ?? ''));
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw)) {
    aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
}

$desembarqueId = (int) $desembarqueIdRaw;
$connection = getDatabaseConnection();
$record = aviso_require_record_access($connection, $desembarqueId, $user);

$statusState = aviso_get_document_status($connection, $desembarqueId, false);
if ($statusState['exists'] && aviso_status_normalize($statusState['slug']) === 'cancelled') {
    aviso_json(409, [
        'success' => false,
        'message' => 'El aviso está cancelado. Reábrelo a Borrador antes de modificar sus datos o emitir una nueva versión.',
    ]);
}

$detailsInput = isset($payload['details']) && is_array($payload['details']) ? $payload['details'] : [];
$documentInput = isset($payload['document']) && is_array($payload['document']) ? $payload['document'] : [];
$itemsInput = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
$photosInput = isset($payload['photos']) && is_array($payload['photos']) ? $payload['photos'] : [];

if ($itemsInput === [] || count($itemsInput) > 500) {
    aviso_json(422, [
        'success' => false,
        'message' => 'El Excel debe contener al menos una mercancía y no más de 500 renglones.',
    ]);
}

if (count($photosInput) > 100) {
    aviso_json(422, [
        'success' => false,
        'message' => 'El anexo fotográfico no puede contener más de 100 imágenes.',
    ]);
}

$details = [
    'manifiesto' => aviso_clean_text($detailsInput['manifiesto'] ?? null, 100),
    'medio_transporte' => aviso_clean_text($detailsInput['medio_transporte'] ?? null, 255),
    'imo_transporte' => aviso_clean_text($detailsInput['imo_transporte'] ?? null, 40),
    'consignataria' => aviso_clean_text($detailsInput['consignataria'] ?? null, 255),
    'fecha_embarque' => aviso_clean_datetime($detailsInput['fecha_embarque'] ?? null),
    'lugar_desembarque' => aviso_clean_text($detailsInput['lugar_desembarque'] ?? null, 5000),
    'fecha_desembarque_eta' => aviso_clean_datetime($detailsInput['fecha_desembarque_eta'] ?? null),
    'domicilio_almacenamiento' => aviso_clean_text($detailsInput['domicilio_almacenamiento'] ?? null, 5000),
    'domicilio_reparacion' => aviso_clean_text($detailsInput['domicilio_reparacion'] ?? null, 5000),
    'source_excel_name' => aviso_clean_text($detailsInput['source_excel_name'] ?? null, 255),
    'source_excel_sha256' => aviso_clean_text($detailsInput['source_excel_sha256'] ?? null, 64),
];

if ($details['source_excel_sha256'] !== null && ! preg_match('/^[a-f0-9]{64}$/i', $details['source_excel_sha256'])) {
    $details['source_excel_sha256'] = null;
}

$profileId = null;
$profileIdRaw = trim((string) ($documentInput['profile_id'] ?? ''));
if ($profileIdRaw !== '') {
    if (! ctype_digit($profileIdRaw)) {
        aviso_json(422, ['success' => false, 'message' => 'El perfil seleccionado no es válido.']);
    }

    $candidateProfileId = (int) $profileIdRaw;
    $recordClientId = isset($record['client_id']) && $record['client_id'] !== null ? (int) $record['client_id'] : 0;
    $profileCheck = $connection->execute_query(
        'SELECT id FROM aviso_profiles WHERE id = ? AND is_active = 1 AND (client_id IS NULL OR client_id = ?) LIMIT 1',
        [$candidateProfileId, $recordClientId]
    );
    if (! ($profileCheck instanceof mysqli_result) || ! $profileCheck->fetch_assoc()) {
        aviso_json(422, ['success' => false, 'message' => 'El perfil seleccionado no está disponible para este desembarque.']);
    }
    $profileId = $candidateProfileId;
}

$document = [
    'rig_name' => aviso_clean_text($documentInput['rig_name'] ?? null, 180),
    'rig_imo' => aviso_clean_text($documentInput['rig_imo'] ?? null, 40),
    'rig_field' => aviso_clean_text($documentInput['rig_field'] ?? null, 120),
    'rig_area' => aviso_clean_text($documentInput['rig_area'] ?? null, 120),
    'comitente' => aviso_clean_text($documentInput['comitente'] ?? null, 255),
    'document_code' => aviso_clean_text($documentInput['document_code'] ?? null, 100),
    'document_title' => aviso_clean_text($documentInput['document_title'] ?? null, 4000),
    'notice_number' => aviso_clean_text($documentInput['notice_number'] ?? null, 100),
    'location_date_text' => aviso_clean_text($documentInput['location_date_text'] ?? null, 200),
    'recipient_text' => aviso_clean_text($documentInput['recipient_text'] ?? null, 4000),
    'introduction' => aviso_clean_text($documentInput['introduction'] ?? null, 40000),
    'body' => aviso_clean_text($documentInput['body'] ?? null, 40000),
    'operations' => aviso_clean_text($documentInput['operations'] ?? null, 40000),
    'documentation' => aviso_clean_text($documentInput['documentation'] ?? null, 10000),
    'closing_text' => aviso_clean_text($documentInput['closing_text'] ?? null, 10000),
    'signer_name' => aviso_clean_text($documentInput['signer_name'] ?? null, 200),
    'signer_title' => aviso_clean_text($documentInput['signer_title'] ?? null, 200),
    'footer_text' => aviso_clean_text($documentInput['footer_text'] ?? null, 5000),
];

$headersResult = $connection->execute_query(
    'SELECT num_pedimento, cve_pedimento, razon_social FROM desembarque_pedimento_headers WHERE desembarque_id = ?',
    [$desembarqueId]
);
$headerImporters = [];
if ($headersResult instanceof mysqli_result) {
    while ($header = $headersResult->fetch_assoc()) {
        $numberKey = aviso_normalize_pedimento((string) ($header['num_pedimento'] ?? ''));
        $claveKey = aviso_normalize_key((string) ($header['cve_pedimento'] ?? ''));
        $importer = aviso_clean_text($header['razon_social'] ?? null, 255);
        if ($numberKey !== '' && $importer !== null) {
            $headerImporters[$claveKey . '|' . $numberKey] = $importer;
            if (! isset($headerImporters['|' . $numberKey])) {
                $headerImporters['|' . $numberKey] = $importer;
            }
        }
    }
}

$items = [];
foreach ($itemsInput as $index => $itemInput) {
    if (! is_array($itemInput)) {
        continue;
    }

    $description = aviso_clean_text($itemInput['descripcion'] ?? null, 15000);
    if ($description === null) {
        continue;
    }

    $clave = aviso_clean_text($itemInput['clave'] ?? null, 30);
    $clave = $clave !== null ? aviso_normalize_key($clave) : null;
    $pedimento = aviso_clean_text($itemInput['num_pedimento'] ?? null, 120);
    $importer = aviso_clean_text($itemInput['importer_name'] ?? null, 255);

    $itemPedimentos = [];
    $seenItemPedimentos = [];
    $rawItemPedimentos = isset($itemInput['pedimentos']) && is_array($itemInput['pedimentos']) ? $itemInput['pedimentos'] : [];
    if ($rawItemPedimentos === [] && $pedimento !== null) {
        $rawItemPedimentos[] = ['key' => $clave, 'number' => $pedimento, 'importer_name' => $importer];
    }
    foreach ($rawItemPedimentos as $relation) {
        if (! is_array($relation)) {
            continue;
        }
        $relationKey = aviso_clean_text($relation['key'] ?? $relation['clave'] ?? null, 30);
        $relationKey = $relationKey !== null ? aviso_normalize_key($relationKey) : null;
        $relationNumber = aviso_clean_text($relation['number'] ?? $relation['num_pedimento'] ?? $relation['pedimento'] ?? null, 120);
        if ($relationNumber === null) {
            continue;
        }
        $identity = ($relationKey ?? '') . '|' . aviso_normalize_pedimento($relationNumber);
        if (isset($seenItemPedimentos[$identity])) {
            continue;
        }
        $seenItemPedimentos[$identity] = true;
        $relationImporter = aviso_clean_text($relation['importer_name'] ?? null, 255);
        if ($relationImporter === null) {
            $numberKey = aviso_normalize_pedimento($relationNumber);
            $relationImporter = $headerImporters[($relationKey ?? '') . '|' . $numberKey] ?? $headerImporters['|' . $numberKey] ?? $importer;
        }
        $itemPedimentos[] = [
            'key' => $relationKey,
            'number' => $relationNumber,
            'importer_name' => $relationImporter,
        ];
    }
    if (count($itemPedimentos) === 1) {
        $clave = $itemPedimentos[0]['key'];
        $pedimento = $itemPedimentos[0]['number'];
    } elseif (count($itemPedimentos) > 1) {
        $clave = null;
        $pedimento = null;
    }

    if ($importer === null && $pedimento !== null) {
        $numberKey = aviso_normalize_pedimento($pedimento);
        $key = ($clave ?? '') . '|' . $numberKey;
        $importer = $headerImporters[$key] ?? $headerImporters['|' . $numberKey] ?? null;
    }

    $quantity = null;
    if (isset($itemInput['cantidad']) && $itemInput['cantidad'] !== '' && is_numeric($itemInput['cantidad'])) {
        $quantity = round((float) $itemInput['cantidad'], 3);
    }

    $sourceRow = isset($itemInput['source_row']) && is_numeric($itemInput['source_row'])
        ? max(1, (int) $itemInput['source_row'])
        : null;

    $itemId = isset($itemInput['id']) && is_numeric($itemInput['id'])
        ? max(1, (int) $itemInput['id'])
        : null;

    $items[] = [
        'id' => $itemId,
        'source_row' => $sourceRow,
        'sort_order' => $index + 1,
        'item_no' => aviso_clean_text($itemInput['item_no'] ?? null, 50),
        'unidad' => aviso_clean_text($itemInput['unidad'] ?? null, 40),
        'descripcion' => $description,
        'serial_number' => aviso_clean_text($itemInput['serial_number'] ?? null, 5000),
        'marca' => aviso_clean_text($itemInput['marca'] ?? null, 180),
        'clave' => $clave,
        'num_pedimento' => $pedimento,
        'partida' => aviso_clean_text($itemInput['partida'] ?? null, 50),
        'cantidad' => $quantity,
        'importer_name' => $importer,
        'pedimentos' => $itemPedimentos,
    ];
}

if ($items === []) {
    aviso_json(422, ['success' => false, 'message' => 'No se encontraron mercancías válidas para guardar.']);
}

$photos = [];
$photoFileIds = [];
foreach ($photosInput as $index => $photoInput) {
    if (! is_array($photoInput)) {
        continue;
    }

    $fileIdRaw = trim((string) ($photoInput['file_id'] ?? ''));
    if ($fileIdRaw === '' || ! ctype_digit($fileIdRaw) || (int) $fileIdRaw <= 0) {
        aviso_json(422, ['success' => false, 'message' => 'Una de las fotografías del anexo no es válida.']);
    }

    $fileId = (int) $fileIdRaw;
    $itemSourceRow = isset($photoInput['item_source_row']) && is_numeric($photoInput['item_source_row'])
        ? max(1, (int) $photoInput['item_source_row'])
        : null;
    $legacyItemId = isset($photoInput['aviso_item_id']) && is_numeric($photoInput['aviso_item_id'])
        ? max(1, (int) $photoInput['aviso_item_id'])
        : null;

    $photos[] = [
        'file_id' => $fileId,
        'item_source_row' => $itemSourceRow,
        'aviso_item_id' => $legacyItemId,
        'caption' => aviso_clean_text($photoInput['caption'] ?? null, 255),
        'sort_order' => $index + 1,
    ];
    $photoFileIds[$fileId] = $fileId;
}

$photoFiles = [];
if ($photoFileIds !== []) {
    $ids = array_values($photoFileIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$desembarqueId], $ids);
    $fileResult = $connection->execute_query(
        'SELECT id, original_name, mime_type, extension, size FROM desembarque_files '
        . 'WHERE desembarque_id = ? AND id IN (' . $placeholders . ')',
        $params
    );

    if ($fileResult instanceof mysqli_result) {
        while ($file = $fileResult->fetch_assoc()) {
            $id = (int) ($file['id'] ?? 0);
            $mime = strtolower(trim((string) ($file['mime_type'] ?? '')));
            $extension = strtolower(trim((string) ($file['extension'] ?? '')));
            if ($id <= 0 || strpos($mime, 'image/') !== 0 || ! in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                continue;
            }
            $photoFiles[$id] = $file;
        }
    }

    foreach ($ids as $id) {
        if (! isset($photoFiles[$id])) {
            aviso_json(422, [
                'success' => false,
                'message' => 'Una fotografía seleccionada ya no existe o no pertenece a este desembarque.',
            ]);
        }
    }
}

$beforeResult = $connection->execute_query(
    'SELECT aviso_profile_id AS profile_id, source_excel_name, source_excel_sha256, updated_at FROM desembarque_aviso_details WHERE desembarque_id = ? LIMIT 1',
    [$desembarqueId]
);
$before = $beforeResult instanceof mysqli_result ? $beforeResult->fetch_assoc() : null;

try {
    $connection->begin_transaction();

    $sql = <<<'SQL'
INSERT INTO desembarque_aviso_details (
    desembarque_id, aviso_profile_id, manifiesto, medio_transporte, imo_transporte, consignataria,
    fecha_embarque, lugar_desembarque, fecha_desembarque_eta, domicilio_almacenamiento,
    domicilio_reparacion, rig_name, rig_imo, rig_field, rig_area, comitente,
    document_code, document_title, notice_number, location_date_text, recipient_text,
    introduction, body, operations, documentation, closing_text, signer_name, signer_title,
    footer_text, source_excel_name, source_excel_sha256, imported_by
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
ON DUPLICATE KEY UPDATE
    aviso_profile_id = VALUES(aviso_profile_id),
    manifiesto = VALUES(manifiesto), medio_transporte = VALUES(medio_transporte),
    imo_transporte = VALUES(imo_transporte), consignataria = VALUES(consignataria),
    fecha_embarque = VALUES(fecha_embarque), lugar_desembarque = VALUES(lugar_desembarque),
    fecha_desembarque_eta = VALUES(fecha_desembarque_eta),
    domicilio_almacenamiento = VALUES(domicilio_almacenamiento),
    domicilio_reparacion = VALUES(domicilio_reparacion), rig_name = VALUES(rig_name),
    rig_imo = VALUES(rig_imo), rig_field = VALUES(rig_field), rig_area = VALUES(rig_area),
    comitente = VALUES(comitente), document_code = VALUES(document_code),
    document_title = VALUES(document_title), notice_number = VALUES(notice_number),
    location_date_text = VALUES(location_date_text), recipient_text = VALUES(recipient_text),
    introduction = VALUES(introduction), body = VALUES(body), operations = VALUES(operations),
    documentation = VALUES(documentation), closing_text = VALUES(closing_text),
    signer_name = VALUES(signer_name), signer_title = VALUES(signer_title),
    footer_text = VALUES(footer_text), source_excel_name = VALUES(source_excel_name),
    source_excel_sha256 = VALUES(source_excel_sha256), imported_by = VALUES(imported_by)
SQL;

    $connection->execute_query($sql, [
        $desembarqueId, $profileId,
        $details['manifiesto'], $details['medio_transporte'], $details['imo_transporte'], $details['consignataria'],
        $details['fecha_embarque'], $details['lugar_desembarque'], $details['fecha_desembarque_eta'],
        $details['domicilio_almacenamiento'], $details['domicilio_reparacion'],
        $document['rig_name'], $document['rig_imo'], $document['rig_field'], $document['rig_area'], $document['comitente'],
        $document['document_code'], $document['document_title'], $document['notice_number'], $document['location_date_text'],
        $document['recipient_text'], $document['introduction'], $document['body'], $document['operations'],
        $document['documentation'], $document['closing_text'], $document['signer_name'], $document['signer_title'],
        $document['footer_text'], $details['source_excel_name'], $details['source_excel_sha256'], $user['id'],
    ]);

    // Conserva IDs de mercancía siempre que sea posible para no romper asociaciones del anexo fotográfico.
    $existingItemsResult = $connection->execute_query(
        'SELECT id, source_row FROM desembarque_aviso_items WHERE desembarque_id = ? ORDER BY id ASC',
        [$desembarqueId]
    );
    $existingById = [];
    $existingBySourceRow = [];
    if ($existingItemsResult instanceof mysqli_result) {
        while ($existing = $existingItemsResult->fetch_assoc()) {
            $existingId = (int) ($existing['id'] ?? 0);
            if ($existingId <= 0) {
                continue;
            }
            $existingById[$existingId] = $existing;
            if ($existing['source_row'] !== null) {
                $sourceKey = (string) (int) $existing['source_row'];
                if (! isset($existingBySourceRow[$sourceKey])) {
                    $existingBySourceRow[$sourceKey] = $existingId;
                }
            }
        }
    }

    // Las salidas registradas forman parte del historial físico de la mercancía.
    // Una mercancía finalizada no puede desaparecer del aviso ni reducirse por debajo de lo ya exportado.
    $finalizedQtyByItem = [];
    $finalizedQtyResult = $connection->execute_query(
        'SELECT l.aviso_item_id, COALESCE(SUM(l.quantity_exported), 0) AS finalized_quantity '
        . 'FROM desembarque_item_finalization_lines l '
        . 'INNER JOIN desembarque_item_finalizations f ON f.id = l.finalization_id '
        . 'WHERE f.desembarque_id = ? AND f.voided_at IS NULL GROUP BY l.aviso_item_id',
        [$desembarqueId]
    );
    if ($finalizedQtyResult instanceof mysqli_result) {
        while ($finalizedRow = $finalizedQtyResult->fetch_assoc()) {
            $finalizedQtyByItem[(int) ($finalizedRow['aviso_item_id'] ?? 0)] = round((float) ($finalizedRow['finalized_quantity'] ?? 0), 3);
        }
    }

    $savedItemIds = [];
    $sourceRowToId = [];
    $savedItems = [];
    $updateItemSql = 'UPDATE desembarque_aviso_items SET '
        . 'source_row=?, sort_order=?, item_no=?, unidad=?, descripcion=?, serial_number=?, marca=?, clave=?, num_pedimento=?, partida=?, cantidad=?, importer_name=? '
        . 'WHERE id=? AND desembarque_id=?';
    $insertItemSql = 'INSERT INTO desembarque_aviso_items '
        . '(desembarque_id, source_row, sort_order, item_no, unidad, descripcion, serial_number, marca, clave, num_pedimento, partida, cantidad, importer_name) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';

    foreach ($items as $item) {
        $candidateId = null;
        if ($item['id'] !== null && isset($existingById[$item['id']])) {
            $candidateId = (int) $item['id'];
        } elseif ($item['source_row'] !== null) {
            $sourceKey = (string) $item['source_row'];
            if (isset($existingBySourceRow[$sourceKey])) {
                $candidateId = (int) $existingBySourceRow[$sourceKey];
            }
        }

        if ($candidateId !== null) {
            $alreadyFinalized = (float) ($finalizedQtyByItem[$candidateId] ?? 0);
            if ($alreadyFinalized > 0 && ((float) ($item['cantidad'] ?? 0) + 0.0005) < $alreadyFinalized) {
                throw new RuntimeException(
                    'No se puede reducir la cantidad de una mercancía por debajo de lo ya finalizado/exportado. '
                    . 'Mercancía #' . $candidateId . ': finalizado ' . rtrim(rtrim(number_format($alreadyFinalized, 3, '.', ''), '0'), '.') . '.'
                );
            }
            $connection->execute_query($updateItemSql, [
                $item['source_row'], $item['sort_order'], $item['item_no'], $item['unidad'], $item['descripcion'],
                $item['serial_number'], $item['marca'], $item['clave'], $item['num_pedimento'], $item['partida'],
                $item['cantidad'], $item['importer_name'], $candidateId, $desembarqueId,
            ]);
            $savedId = $candidateId;
        } else {
            $connection->execute_query($insertItemSql, [
                $desembarqueId,
                $item['source_row'], $item['sort_order'], $item['item_no'], $item['unidad'], $item['descripcion'],
                $item['serial_number'], $item['marca'], $item['clave'], $item['num_pedimento'], $item['partida'],
                $item['cantidad'], $item['importer_name'],
            ]);
            $savedId = (int) $connection->insert_id;
        }

        $connection->execute_query('DELETE FROM desembarque_aviso_item_pedimentos WHERE aviso_item_id = ?', [$savedId]);
        foreach (($item['pedimentos'] ?? []) as $relationIndex => $relation) {
            if (! is_array($relation) || trim((string) ($relation['number'] ?? '')) === '') {
                continue;
            }
            $connection->execute_query(
                'INSERT INTO desembarque_aviso_item_pedimentos (aviso_item_id, clave, num_pedimento, importer_name, sort_order) VALUES (?,?,?,?,?)',
                [
                    $savedId,
                    $relation['key'] ?? null,
                    $relation['number'],
                    $relation['importer_name'] ?? $item['importer_name'],
                    $relationIndex + 1,
                ]
            );
        }

        $savedItemIds[$savedId] = $savedId;
        if ($item['source_row'] !== null) {
            $sourceRowToId[(string) $item['source_row']] = $savedId;
        }
        $savedItem = $item;
        $savedItem['id'] = $savedId;
        $savedItems[] = $savedItem;
    }

    foreach (array_keys($existingById) as $existingId) {
        if (isset($savedItemIds[$existingId])) {
            continue;
        }
        if ((float) ($finalizedQtyByItem[$existingId] ?? 0) > 0) {
            throw new RuntimeException(
                'No se puede eliminar del aviso una mercancía que ya tiene una salida/finalización registrada. '
                . 'Conserva el renglón original para mantener la trazabilidad del expediente.'
            );
        }
        $connection->execute_query(
            'UPDATE desembarque_aviso_images SET aviso_item_id = NULL WHERE desembarque_id = ? AND aviso_item_id = ?',
            [$desembarqueId, $existingId]
        );
        $connection->execute_query(
            'DELETE FROM desembarque_aviso_items WHERE id = ? AND desembarque_id = ? LIMIT 1',
            [$existingId, $desembarqueId]
        );
    }

    // El arreglo enviado por el frontend representa exactamente el anexo deseado.
    $connection->execute_query('DELETE FROM desembarque_aviso_images WHERE desembarque_id = ?', [$desembarqueId]);
    $savedPhotos = [];
    $insertPhotoSql = 'INSERT INTO desembarque_aviso_images '
        . '(desembarque_id, file_id, aviso_item_id, caption, sort_order) VALUES (?,?,?,?,?)';

    foreach ($photos as $photo) {
        $resolvedItemId = null;
        if ($photo['item_source_row'] !== null) {
            $sourceKey = (string) $photo['item_source_row'];
            $resolvedItemId = $sourceRowToId[$sourceKey] ?? null;
        }
        if ($resolvedItemId === null && $photo['aviso_item_id'] !== null && isset($savedItemIds[$photo['aviso_item_id']])) {
            $resolvedItemId = (int) $photo['aviso_item_id'];
        }

        $connection->execute_query($insertPhotoSql, [
            $desembarqueId,
            $photo['file_id'],
            $resolvedItemId,
            $photo['caption'],
            $photo['sort_order'],
        ]);

        $savedPhotoId = (int) $connection->insert_id;
        $connection->execute_query(
            "UPDATE desembarque_files SET purpose = 'photo' WHERE id = ? AND desembarque_id = ? AND COALESCE(purpose, '') <> 'source_excel'",
            [$photo['file_id'], $desembarqueId]
        );
        $file = $photoFiles[$photo['file_id']] ?? [];
        $savedPhotos[] = [
            'id' => $savedPhotoId,
            'file_id' => $photo['file_id'],
            'aviso_item_id' => $resolvedItemId,
            'item_source_row' => $photo['item_source_row'],
            'caption' => $photo['caption'] ?? '',
            'sort_order' => $photo['sort_order'],
            'original_name' => (string) ($file['original_name'] ?? ''),
            'mime_type' => (string) ($file['mime_type'] ?? ''),
            'extension' => (string) ($file['extension'] ?? ''),
            'size' => isset($file['size']) ? (int) $file['size'] : 0,
            'preview_url' => '../api/desembarques/aviso/image.php?file_id=' . $photo['file_id'],
            'download_url' => '../api/desembarques/files/download.php?id=' . $photo['file_id'],
        ];
    }

    record_audit_log(
        'update',
        'desembarque_aviso',
        (string) $desembarqueId,
        [
            'before' => $before,
            'after' => [
                'profile_id' => $profileId,
                'source_excel_name' => $details['source_excel_name'],
                'source_excel_sha256' => $details['source_excel_sha256'],
                'items_count' => count($savedItems),
                'photos_count' => count($savedPhotos),
                'manifiesto' => $details['manifiesto'],
                'notice_number' => $document['notice_number'],
            ],
        ],
        $user['id'],
        $connection
    );

    $connection->commit();
} catch (RuntimeException $exception) {
    $connection->rollback();
    aviso_json(422, [
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    $connection->rollback();
    error_log('[aviso-save] ' . $exception->getMessage());
    aviso_json(500, [
        'success' => false,
        'message' => 'No fue posible guardar la información del aviso.',
    ]);
}

aviso_json(200, [
    'success' => true,
    'message' => 'Información del Excel, aviso y anexo fotográfico guardada correctamente.',
    'items_count' => count($savedItems ?? []),
    'photos_count' => count($savedPhotos ?? []),
    'items' => $savedItems ?? [],
    'photos' => $savedPhotos ?? [],
]);
