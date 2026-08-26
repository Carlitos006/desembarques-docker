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

$reason = aviso_clean_text($payload['reason'] ?? null, 1000);
if ($reason === null || mb_strlen($reason) < 5) {
    aviso_json(422, ['success' => false, 'message' => 'Indica el motivo de la anulación (mínimo 5 caracteres).']);
}

try {
    $connection->begin_transaction();

    $result = $connection->execute_query(
        'SELECT f.id, f.desembarque_id, f.export_date, f.notes, f.voided_at, u.name AS created_by_name '
        . 'FROM desembarque_item_finalizations f '
        . 'LEFT JOIN users u ON u.id = f.created_by '
        . 'WHERE f.id = ? AND f.desembarque_id = ? LIMIT 1 FOR UPDATE',
        [$finalizationId, $desembarqueId]
    );
    $finalization = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if (! $finalization) {
        throw new RuntimeException('El movimiento de salida no existe en este expediente.');
    }
    if (! empty($finalization['voided_at'])) {
        throw new RuntimeException('Este movimiento ya fue anulado anteriormente.');
    }

    $linesResult = $connection->execute_query(
        'SELECT l.aviso_item_id, l.quantity_exported, i.descripcion '
        . 'FROM desembarque_item_finalization_lines l '
        . 'INNER JOIN desembarque_aviso_items i ON i.id = l.aviso_item_id '
        . 'WHERE l.finalization_id = ? ORDER BY l.id ASC',
        [$finalizationId]
    );
    $lines = [];
    $pieceCount = 0.0;
    if ($linesResult instanceof mysqli_result) {
        while ($line = $linesResult->fetch_assoc()) {
            $pieceCount += (float) ($line['quantity_exported'] ?? 0);
            $lines[] = $line;
        }
    }

    // Serializa la anulación contra nuevas salidas de las mismas mercancías.
    $itemIds = array_values(array_unique(array_map(
        static fn(array $line): int => (int) ($line['aviso_item_id'] ?? 0),
        $lines
    )));
    sort($itemIds, SORT_NUMERIC);
    foreach ($itemIds as $itemId) {
        if ($itemId <= 0) {
            continue;
        }
        $connection->execute_query(
            'SELECT id FROM desembarque_aviso_items WHERE id = ? AND desembarque_id = ? LIMIT 1 FOR UPDATE',
            [$itemId, $desembarqueId]
        );
    }

    $connection->execute_query(
        'UPDATE desembarque_item_finalizations SET voided_at = NOW(), void_reason = ?, voided_by = ? '
        . 'WHERE id = ? AND desembarque_id = ? AND voided_at IS NULL',
        [$reason, (int) $user['id'], $finalizationId, $desembarqueId]
    );
    if ($connection->affected_rows !== 1) {
        throw new RuntimeException('El movimiento cambió mientras se procesaba la anulación. Recarga el expediente.');
    }

    record_audit_log(
        'update',
        'aviso_merchandise_finalization',
        (string) $finalizationId,
        [
            'desembarque_id' => $desembarqueId,
            'finalization_id' => $finalizationId,
            'action' => 'void',
            'reason' => $reason,
            'piece_count' => round($pieceCount, 3),
            'line_count' => count($lines),
            'items' => $lines,
        ],
        (int) $user['id'],
        $connection
    );

    $connection->commit();

    aviso_json(200, [
        'success' => true,
        'message' => 'El movimiento fue anulado. Las cantidades regresaron al saldo disponible.',
        'finalization_id' => $finalizationId,
    ]);
} catch (RuntimeException $exception) {
    try { $connection->rollback(); } catch (Throwable $ignored) {}
    aviso_json(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    try { $connection->rollback(); } catch (Throwable $ignored) {}
    error_log('[aviso-merchandise-finalization-void] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible anular el movimiento.']);
}
