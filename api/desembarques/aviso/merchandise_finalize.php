<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$user = aviso_require_user();
$connection = getDatabaseConnection();

if ($method !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

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
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw) || (int) $desembarqueIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
}
$desembarqueId = (int) $desembarqueIdRaw;
aviso_require_record_access($connection, $desembarqueId, $user);

$exportDateRaw = trim((string) ($payload['export_date'] ?? ''));
$exportDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $exportDateRaw);
if (!($exportDateObject instanceof DateTimeImmutable) || $exportDateObject->format('Y-m-d') !== $exportDateRaw) {
    aviso_json(422, ['success' => false, 'message' => 'Debes indicar una fecha de salida válida.']);
}
$exportDate = $exportDateObject->format('Y-m-d');
$notes = aviso_clean_text($payload['notes'] ?? null, 1000);
$closureStatus = strtolower(trim((string) ($payload['closure_status'] ?? 'complete')));
if (! in_array($closureStatus, ['partial', 'complete'], true)) {
    aviso_json(422, ['success' => false, 'message' => 'El tipo de cierre documental no es válido.']);
}
$isPartialClosure = $closureStatus === 'partial';
if ($isPartialClosure && ($notes === null || mb_strlen($notes) < 5)) {
    aviso_json(422, [
        'success' => false,
        'message' => 'Para un cierre parcial, indica qué pedimentos o documentos están pendientes (mínimo 5 caracteres).',
    ]);
}

$requestedLines = is_array($payload['items'] ?? null) ? $payload['items'] : [];
$normalizedLines = [];
foreach ($requestedLines as $line) {
    if (! is_array($line)) {
        continue;
    }

    $itemIdRaw = trim((string) ($line['item_id'] ?? ''));
    if ($itemIdRaw === '' || ! ctype_digit($itemIdRaw) || (int) $itemIdRaw <= 0) {
        continue;
    }
    $itemId = (int) $itemIdRaw;

    if (isset($normalizedLines[$itemId])) {
        aviso_json(422, ['success' => false, 'message' => 'Una mercancía no puede repetirse dentro de la misma salida.']);
    }

    $quantityRaw = str_replace(',', '.', trim((string) ($line['quantity'] ?? '')));
    if ($quantityRaw === '' || ! is_numeric($quantityRaw)) {
        continue;
    }
    $quantity = round((float) $quantityRaw, 3);
    if ($quantity <= 0) {
        continue;
    }

    $pedimentoR1Agregar = aviso_clean_text($line['pedimento_r1_agregar'] ?? null, 150);
    $pedimentoRetornoParcialH1 = aviso_clean_text($line['pedimento_retorno_parcial_h1'] ?? null, 150);
    $mercanciaPedimentoH1 = aviso_clean_text($line['mercancia_pedimento_h1'] ?? null, 4000);
    $pedimentoR1Desagregado = aviso_clean_text($line['pedimento_r1_desagregado'] ?? null, 150);
    $pedimentoA3 = aviso_clean_text($line['pedimento_a3'] ?? null, 150);

    if (! $isPartialClosure
        && $pedimentoR1Agregar === null
        && $pedimentoRetornoParcialH1 === null
        && $mercanciaPedimentoH1 === null
        && $pedimentoR1Desagregado === null
        && $pedimentoA3 === null) {
        $description = trim((string) ($line['description'] ?? ''));
        aviso_json(422, [
            'success' => false,
            'message' => 'Captura al menos uno de los datos aduanales para la mercancía'
                . ($description !== '' ? ' "' . mb_substr($description, 0, 120) . '"' : '') . '.',
        ]);
    }

    $normalizedLines[$itemId] = [
        'item_id' => $itemId,
        'quantity' => $quantity,
        'pedimento_r1_agregar' => $pedimentoR1Agregar,
        'pedimento_retorno_parcial_h1' => $pedimentoRetornoParcialH1,
        'mercancia_pedimento_h1' => $mercanciaPedimentoH1,
        'pedimento_r1_desagregado' => $pedimentoR1Desagregado,
        'pedimento_a3' => $pedimentoA3,
    ];
}

if ($normalizedLines === []) {
    aviso_json(422, ['success' => false, 'message' => 'Selecciona al menos una mercancía e indica la cantidad que sale.']);
}

try {
    $connection->begin_transaction();

    $validatedLines = [];
    $totalExported = 0.0;

    foreach ($normalizedLines as $itemId => $requestedLine) {
        $requestedQuantity = (float) $requestedLine['quantity'];

        $itemResult = $connection->execute_query(
            'SELECT id, descripcion, cantidad, unidad, serial_number, clave, num_pedimento, partida, importer_name '
            . 'FROM desembarque_aviso_items WHERE id = ? AND desembarque_id = ? LIMIT 1 FOR UPDATE',
            [$itemId, $desembarqueId]
        );
        $item = $itemResult instanceof mysqli_result ? $itemResult->fetch_assoc() : null;
        if (! $item) {
            throw new RuntimeException('Una de las mercancías seleccionadas ya no está disponible en este expediente.');
        }

        $originalQuantity = round((float) ($item['cantidad'] ?? 0), 3);
        if ($originalQuantity <= 0) {
            throw new RuntimeException('La mercancía "' . (string) ($item['descripcion'] ?? '#' . $itemId) . '" no tiene una cantidad válida para finalizar.');
        }

        $exportedResult = $connection->execute_query(
            'SELECT COALESCE(SUM(l.quantity_exported), 0) AS exported_quantity '
            . 'FROM desembarque_item_finalization_lines l '
            . 'INNER JOIN desembarque_item_finalizations f ON f.id = l.finalization_id '
            . 'WHERE f.desembarque_id = ? AND l.aviso_item_id = ? AND f.voided_at IS NULL',
            [$desembarqueId, $itemId]
        );
        $exportedRow = $exportedResult instanceof mysqli_result ? $exportedResult->fetch_assoc() : null;
        $alreadyExported = round((float) ($exportedRow['exported_quantity'] ?? 0), 3);
        $remaining = round(max(0, $originalQuantity - $alreadyExported), 3);

        if ($remaining <= 0.0005) {
            throw new RuntimeException('La mercancía "' . (string) ($item['descripcion'] ?? '#' . $itemId) . '" ya está completamente finalizada.');
        }
        if ($requestedQuantity - $remaining > 0.0005) {
            throw new RuntimeException(
                'La cantidad indicada para "' . (string) ($item['descripcion'] ?? '#' . $itemId)
                . '" supera lo disponible en almacén (' . rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.') . ').'
            );
        }

        $originalPedimentos = [];
        $itemPedimentoResult = $connection->execute_query(
            'SELECT clave, num_pedimento, importer_name FROM desembarque_aviso_item_pedimentos '
            . 'WHERE aviso_item_id = ? ORDER BY sort_order ASC, id ASC',
            [$itemId]
        );
        if ($itemPedimentoResult instanceof mysqli_result) {
            while ($relation = $itemPedimentoResult->fetch_assoc()) {
                $originalPedimentos[] = [
                    'key' => (string) ($relation['clave'] ?? ''),
                    'number' => (string) ($relation['num_pedimento'] ?? ''),
                    'importer_name' => (string) ($relation['importer_name'] ?? ''),
                ];
            }
        }
        if ($originalPedimentos === [] && trim((string) ($item['num_pedimento'] ?? '')) !== '') {
            $originalPedimentos[] = [
                'key' => (string) ($item['clave'] ?? ''),
                'number' => (string) ($item['num_pedimento'] ?? ''),
                'importer_name' => (string) ($item['importer_name'] ?? ''),
            ];
        }

        $validatedLines[] = [
            'item_id' => (int) $item['id'],
            'description' => (string) ($item['descripcion'] ?? ''),
            'serial_number' => (string) ($item['serial_number'] ?? ''),
            'customs_key' => (string) ($item['clave'] ?? ''),
            'customs_entry' => (string) ($item['num_pedimento'] ?? ''),
            'customs_entries' => $originalPedimentos,
            'partida' => (string) ($item['partida'] ?? ''),
            'importer_name' => (string) ($item['importer_name'] ?? ''),
            'original_quantity' => $originalQuantity,
            'previously_exported' => $alreadyExported,
            'quantity_exported' => $requestedQuantity,
            'remaining_after' => round(max(0, $remaining - $requestedQuantity), 3),
            'pedimento_r1_agregar' => $requestedLine['pedimento_r1_agregar'],
            'pedimento_retorno_parcial_h1' => $requestedLine['pedimento_retorno_parcial_h1'],
            'mercancia_pedimento_h1' => $requestedLine['mercancia_pedimento_h1'],
            'pedimento_r1_desagregado' => $requestedLine['pedimento_r1_desagregado'],
            'pedimento_a3' => $requestedLine['pedimento_a3'],
        ];
        $totalExported += $requestedQuantity;
    }

    // En V2, fecha/notas pertenecen al evento; los 5 datos aduanales pertenecen a cada línea.
    // Las columnas aduanales V1 se conservan en la tabla para compatibilidad, pero ya no se escriben.
    $closureCompletedAt = $isPartialClosure ? null : date('Y-m-d H:i:s');
    $closureCompletedBy = $isPartialClosure ? null : (int) $user['id'];
    $connection->execute_query(
        'INSERT INTO desembarque_item_finalizations '
        . '(desembarque_id, export_date, notes, closure_status, closure_completed_at, closure_completed_by, created_by) '
        . 'VALUES (?,?,?,?,?,?,?)',
        [$desembarqueId, $exportDate, $notes, $closureStatus, $closureCompletedAt, $closureCompletedBy, (int) $user['id']]
    );
    $finalizationId = (int) $connection->insert_id;

    foreach ($validatedLines as $line) {
        $connection->execute_query(
            'INSERT INTO desembarque_item_finalization_lines '
            . '(finalization_id, aviso_item_id, quantity_exported, pedimento_r1_agregar, pedimento_retorno_parcial_h1, '
            . 'mercancia_pedimento_h1, pedimento_r1_desagregado, pedimento_a3) VALUES (?,?,?,?,?,?,?,?)',
            [
                $finalizationId,
                $line['item_id'],
                $line['quantity_exported'],
                $line['pedimento_r1_agregar'],
                $line['pedimento_retorno_parcial_h1'],
                $line['mercancia_pedimento_h1'],
                $line['pedimento_r1_desagregado'],
                $line['pedimento_a3'],
            ]
        );
    }

    record_audit_log(
        'create',
        'aviso_merchandise_finalization',
        (string) $finalizationId,
        [
            'desembarque_id' => $desembarqueId,
            'finalization_id' => $finalizationId,
            'export_date' => $exportDate,
            'notes' => $notes,
            'closure_status' => $closureStatus,
            'closure_completed_at' => $closureCompletedAt,
            'closure_completed_by' => $closureCompletedBy,
            'line_count' => count($validatedLines),
            'piece_count' => round($totalExported, 3),
            'customs_scope' => 'per_item',
            'items' => $validatedLines,
        ],
        (int) $user['id'],
        $connection
    );

    $connection->commit();

    aviso_json(200, [
        'success' => true,
        'message' => $isPartialClosure
            ? 'La salida se registró con cierre documental parcial. Podrás completar los pedimentos desde el historial.'
            : 'La salida de mercancía se registró correctamente.',
        'finalization' => [
            'id' => $finalizationId,
            'export_date' => $exportDate,
            'closure_status' => $closureStatus,
            'line_count' => count($validatedLines),
            'piece_count' => round($totalExported, 3),
            'items' => $validatedLines,
        ],
    ]);
} catch (RuntimeException $exception) {
    try {
        $connection->rollback();
    } catch (Throwable $rollbackException) {
        // No-op.
    }
    aviso_json(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    try {
        $connection->rollback();
    } catch (Throwable $rollbackException) {
        // No-op.
    }
    error_log('[aviso-merchandise-finalize-v2] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible registrar la salida de mercancía.']);
}
