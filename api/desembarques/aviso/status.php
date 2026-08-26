<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$user = aviso_require_user();
$connection = getDatabaseConnection();

if ($method === 'GET') {
    $desembarqueIdRaw = trim((string) ($_GET['desembarque_id'] ?? ''));
    if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw)) {
        aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
    }

    $desembarqueId = (int) $desembarqueIdRaw;
    aviso_require_record_access($connection, $desembarqueId, $user);

    $historyResult = $connection->execute_query(
        'SELECT h.*, u.name AS changed_by_name, v.version_no '
        . 'FROM desembarque_aviso_status_history h '
        . 'LEFT JOIN users u ON u.id = h.changed_by '
        . 'LEFT JOIN desembarque_aviso_versions v ON v.id = h.aviso_version_id '
        . 'WHERE h.desembarque_id = ? ORDER BY h.changed_at DESC, h.id DESC LIMIT 100',
        [$desembarqueId]
    );

    $history = [];
    if ($historyResult instanceof mysqli_result) {
        while ($row = $historyResult->fetch_assoc()) {
            $fromMeta = aviso_status_meta((string) ($row['from_status'] ?? 'draft'), $currentLanguage);
            $toMeta = aviso_status_meta((string) ($row['to_status'] ?? 'draft'), $currentLanguage);
            $history[] = [
                'id' => (int) ($row['id'] ?? 0),
                'from_status' => $fromMeta,
                'to_status' => $toMeta,
                'reason' => (string) ($row['reason'] ?? ''),
                'effective_at' => (string) ($row['effective_at'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'version_no' => isset($row['version_no']) && $row['version_no'] !== null ? (int) $row['version_no'] : null,
                'changed_by' => isset($row['changed_by']) && $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                'changed_by_name' => (string) ($row['changed_by_name'] ?? ''),
                'changed_at' => (string) ($row['changed_at'] ?? ''),
            ];
        }
    }

    aviso_json(200, [
        'success' => true,
        'status' => aviso_status_response_payload($connection, $desembarqueId, $user),
        'history' => $history,
    ]);
}

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
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw)) {
    aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
}
$desembarqueId = (int) $desembarqueIdRaw;
aviso_require_record_access($connection, $desembarqueId, $user);

$targetStatusRaw = strtolower(trim((string) ($payload['to_status'] ?? '')));
if ($targetStatusRaw === '' || ! array_key_exists($targetStatusRaw, aviso_status_catalog())) {
    aviso_json(422, ['success' => false, 'message' => 'El nuevo estado indicado no es válido.']);
}
$targetStatus = $targetStatusRaw;
$reason = aviso_clean_text($payload['reason'] ?? null, 1000);
$effectiveAt = aviso_clean_datetime($payload['effective_at'] ?? null);

try {
    $connection->begin_transaction();
    $transition = aviso_apply_status_transition(
        $connection,
        $desembarqueId,
        $targetStatus,
        $user,
        $reason,
        $effectiveAt,
        'manual',
        null
    );
    $connection->commit();

    aviso_json(200, [
        'success' => true,
        'message' => 'El estado documental del aviso se actualizó correctamente.',
        'transition' => $transition,
        'status' => aviso_status_response_payload($connection, $desembarqueId, $user),
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
    error_log('[aviso-status] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible actualizar el estado del aviso.']);
}
