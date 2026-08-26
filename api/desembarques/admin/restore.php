<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aviso_json(405, ['success' => false, 'message' => translateText('Método no permitido.', 'Method not allowed.')]);
}

$user = aviso_admin_require_admin();
$payload = aviso_admin_request_payload();
aviso_admin_validate_csrf($payload);

$idRaw = trim((string) ($payload['desembarque_id'] ?? ''));
if ($idRaw === '' || ! ctype_digit($idRaw) || (int) $idRaw <= 0) {
    aviso_json(422, ['success' => false, 'message' => translateText('El expediente indicado no es válido.', 'The selected case file is not valid.')]);
}

$desembarqueId = (int) $idRaw;
$reason = aviso_admin_required_text($payload, 'reason', 500);
$connection = getDatabaseConnection();
$connection->begin_transaction();

try {
    $record = aviso_admin_lock_record($connection, $desembarqueId);
    if (empty($record['deleted_at'])) {
        throw new DomainException(translateText('El aviso ya está activo.', 'The notice is already active.'));
    }

    $noticeNumber = aviso_admin_notice_number($record);
    aviso_admin_require_confirmation($payload, (getAppLanguage() === 'en' ? 'RESTORE ' : 'RESTAURAR ') . $noticeNumber);

    $connection->execute_query(
        'UPDATE desembarques SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL '
        . 'WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1',
        [$desembarqueId]
    );
    if ($connection->affected_rows !== 1) {
        throw new RuntimeException(translateText('El aviso cambió mientras se procesaba la solicitud.', 'The notice changed while the request was being processed.'));
    }

    aviso_admin_record_audit_or_fail(
        $connection,
        'restore',
        $desembarqueId,
        [
            'notice_number' => $noticeNumber,
            'reference' => (string) ($record['referencia'] ?? ''),
            'restore_reason' => $reason,
            'previous_deleted_at' => (string) ($record['deleted_at'] ?? ''),
            'previous_deleted_by' => isset($record['deleted_by']) ? (int) $record['deleted_by'] : null,
            'previous_delete_reason' => (string) ($record['delete_reason'] ?? ''),
        ],
        (int) $user['id']
    );

    $connection->commit();
} catch (InvalidArgumentException $exception) {
    $connection->rollback();
    aviso_json(422, [
        'success' => false,
        'message' => translateText('La confirmación escrita no coincide.', 'The written confirmation does not match.'),
        'errors' => ['confirmation' => $exception->getMessage()],
    ]);
} catch (DomainException $exception) {
    $connection->rollback();
    aviso_json(409, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    $connection->rollback();
    error_log('[phase7f-restore] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => translateText('No fue posible restaurar el aviso.', 'The notice could not be restored.')]);
}

aviso_json(200, [
    'success' => true,
    'message' => translateText('El aviso fue restaurado y vuelve a estar disponible en la operación.', 'The notice was restored and is available in operations again.'),
    'desembarque_id' => $desembarqueId,
    'notice_number' => $noticeNumber,
    'redirect_url' => 'aviso-expediente.php?id=' . $desembarqueId,
]);
