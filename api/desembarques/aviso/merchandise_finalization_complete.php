<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = aviso_require_user();
$connection = getDatabaseConnection();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
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
$finalizationIdRaw = trim((string) ($payload['finalization_id'] ?? ''));
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw) || (int) $desembarqueIdRaw <= 0
    || $finalizationIdRaw === '' || ! ctype_digit($finalizationIdRaw) || (int) $finalizationIdRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => 'El movimiento indicado no es válido.']);
}

$desembarqueId = (int) $desembarqueIdRaw;
$finalizationId = (int) $finalizationIdRaw;
aviso_require_record_access($connection, $desembarqueId, $user);

$requestedItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
$normalizedItems = [];
foreach ($requestedItems as $item) {
    if (! is_array($item)) {
        continue;
    }

    $lineIdRaw = trim((string) ($item['line_id'] ?? ''));
    if ($lineIdRaw === '' || ! ctype_digit($lineIdRaw) || (int) $lineIdRaw <= 0) {
        continue;
    }
    $lineId = (int) $lineIdRaw;
    if (isset($normalizedItems[$lineId])) {
        aviso_json(422, ['success' => false, 'message' => 'Una mercancía no puede repetirse al completar el cierre.']);
    }

    $customs = [
        'pedimento_r1_agregar' => aviso_clean_text($item['pedimento_r1_agregar'] ?? null, 150),
        'pedimento_retorno_parcial_h1' => aviso_clean_text($item['pedimento_retorno_parcial_h1'] ?? null, 150),
        'mercancia_pedimento_h1' => aviso_clean_text($item['mercancia_pedimento_h1'] ?? null, 4000),
        'pedimento_r1_desagregado' => aviso_clean_text($item['pedimento_r1_desagregado'] ?? null, 150),
        'pedimento_a3' => aviso_clean_text($item['pedimento_a3'] ?? null, 150),
    ];
    if (count(array_filter($customs, static fn(?string $value): bool => $value !== null)) === 0) {
        aviso_json(422, [
            'success' => false,
            'message' => 'Cada mercancía debe conservar al menos un dato aduanal antes de completar el cierre.',
        ]);
    }

    $normalizedItems[$lineId] = $customs;
}

if ($normalizedItems === []) {
    aviso_json(422, ['success' => false, 'message' => 'No se recibieron datos aduanales para completar el cierre.']);
}

try {
    $connection->begin_transaction();

    $result = $connection->execute_query(
        'SELECT id, desembarque_id, export_date, notes, closure_status, closure_completed_at, '
        . 'closure_completed_by, voided_at FROM desembarque_item_finalizations '
        . 'WHERE id = ? AND desembarque_id = ? LIMIT 1 FOR UPDATE',
        [$finalizationId, $desembarqueId]
    );
    $finalization = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if (! $finalization) {
        throw new RuntimeException('El movimiento de salida no existe en este expediente.');
    }
    if (! empty($finalization['voided_at'])) {
        throw new RuntimeException('No se puede completar un movimiento anulado.');
    }
    if ((string) ($finalization['closure_status'] ?? 'complete') !== 'partial') {
        throw new RuntimeException('Este movimiento ya tiene un cierre documental completo.');
    }

    $linesResult = $connection->execute_query(
        'SELECT l.id, l.aviso_item_id, l.quantity_exported, l.pedimento_r1_agregar, '
        . 'l.pedimento_retorno_parcial_h1, l.mercancia_pedimento_h1, l.pedimento_r1_desagregado, '
        . 'l.pedimento_a3, i.descripcion '
        . 'FROM desembarque_item_finalization_lines l '
        . 'INNER JOIN desembarque_aviso_items i ON i.id = l.aviso_item_id '
        . 'WHERE l.finalization_id = ? ORDER BY l.id ASC FOR UPDATE',
        [$finalizationId]
    );
    $databaseLines = [];
    if ($linesResult instanceof mysqli_result) {
        while ($line = $linesResult->fetch_assoc()) {
            $databaseLines[(int) ($line['id'] ?? 0)] = $line;
        }
    }

    if ($databaseLines === [] || count($databaseLines) !== count($normalizedItems)) {
        throw new RuntimeException('Los renglones del movimiento cambiaron. Recarga el expediente e inténtalo de nuevo.');
    }

    $auditLines = [];
    foreach ($databaseLines as $lineId => $line) {
        if (! isset($normalizedItems[$lineId])) {
            throw new RuntimeException('Faltan datos de una mercancía del movimiento. Recarga el expediente.');
        }
        $customs = $normalizedItems[$lineId];
        $before = [
            'pedimento_r1_agregar' => $line['pedimento_r1_agregar'] ?? null,
            'pedimento_retorno_parcial_h1' => $line['pedimento_retorno_parcial_h1'] ?? null,
            'mercancia_pedimento_h1' => $line['mercancia_pedimento_h1'] ?? null,
            'pedimento_r1_desagregado' => $line['pedimento_r1_desagregado'] ?? null,
            'pedimento_a3' => $line['pedimento_a3'] ?? null,
        ];

        $connection->execute_query(
            'UPDATE desembarque_item_finalization_lines SET pedimento_r1_agregar = ?, '
            . 'pedimento_retorno_parcial_h1 = ?, mercancia_pedimento_h1 = ?, '
            . 'pedimento_r1_desagregado = ?, pedimento_a3 = ? '
            . 'WHERE id = ? AND finalization_id = ?',
            [
                $customs['pedimento_r1_agregar'],
                $customs['pedimento_retorno_parcial_h1'],
                $customs['mercancia_pedimento_h1'],
                $customs['pedimento_r1_desagregado'],
                $customs['pedimento_a3'],
                $lineId,
                $finalizationId,
            ]
        );

        $auditLines[] = [
            'line_id' => $lineId,
            'item_id' => (int) ($line['aviso_item_id'] ?? 0),
            'description' => (string) ($line['descripcion'] ?? ''),
            'quantity_exported' => (float) ($line['quantity_exported'] ?? 0),
            'before' => $before,
            'after' => $customs,
        ];
    }

    $completedAt = date('Y-m-d H:i:s');
    $connection->execute_query(
        "UPDATE desembarque_item_finalizations SET closure_status = 'complete', closure_completed_at = ?, "
        . 'closure_completed_by = ? WHERE id = ? AND desembarque_id = ? '
        . "AND closure_status = 'partial' AND voided_at IS NULL",
        [$completedAt, (int) $user['id'], $finalizationId, $desembarqueId]
    );
    if ($connection->affected_rows !== 1) {
        throw new RuntimeException('El cierre cambió mientras se procesaba. Recarga el expediente.');
    }

    record_audit_log(
        'update',
        'aviso_merchandise_finalization',
        (string) $finalizationId,
        [
            'desembarque_id' => $desembarqueId,
            'finalization_id' => $finalizationId,
            'action' => 'complete_documentary_closure',
            'from_closure_status' => 'partial',
            'to_closure_status' => 'complete',
            'closure_completed_at' => $completedAt,
            'line_count' => count($auditLines),
            'items' => $auditLines,
        ],
        (int) $user['id'],
        $connection
    );

    $connection->commit();

    aviso_json(200, [
        'success' => true,
        'message' => 'Los pedimentos se completaron sin alterar las cantidades de la salida.',
        'finalization_id' => $finalizationId,
        'closure_status' => 'complete',
        'closure_completed_at' => $completedAt,
    ]);
} catch (RuntimeException $exception) {
    try { $connection->rollback(); } catch (Throwable $ignored) {}
    aviso_json(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    try { $connection->rollback(); } catch (Throwable $ignored) {}
    error_log('[aviso-merchandise-finalization-complete] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible completar el cierre documental.']);
}
