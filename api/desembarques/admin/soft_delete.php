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
    if (! empty($record['deleted_at'])) {
        throw new DomainException(translateText('El aviso ya se encuentra en la Papelera administrativa.', 'The notice is already in the Deleted Notices section.'));
    }

    $noticeNumber = aviso_admin_notice_number($record);
    aviso_admin_require_confirmation($payload, (getAppLanguage() === 'en' ? 'DELETE ' : 'ELIMINAR ') . $noticeNumber);
    $preserved = aviso_admin_preserved_counts($connection, $desembarqueId);
    $deletedAt = date('Y-m-d H:i:s');

    $connection->execute_query(
        'UPDATE desembarques SET deleted_at = ?, deleted_by = ?, delete_reason = ? '
        . 'WHERE id = ? AND deleted_at IS NULL LIMIT 1',
        [$deletedAt, (int) $user['id'], $reason, $desembarqueId]
    );
    if ($connection->affected_rows !== 1) {
        throw new RuntimeException(translateText('El aviso cambió mientras se procesaba la solicitud.', 'The notice changed while the request was being processed.'));
    }

    aviso_admin_record_audit_or_fail(
        $connection,
        'soft_delete',
        $desembarqueId,
        [
            'notice_number' => $noticeNumber,
            'reference' => (string) ($record['referencia'] ?? ''),
            'reason' => $reason,
            'deleted_at' => $deletedAt,
            'preserved_children' => $preserved,
            'hard_delete' => false,
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
    error_log('[phase7f-soft-delete] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => translateText('No fue posible enviar el aviso a la Papelera administrativa.', 'The notice could not be moved to the Deleted Notices section.')]);
}

aviso_json(200, [
    'success' => true,
    'message' => translateText('El aviso fue retirado de la operación sin eliminar sus documentos ni historial.', 'The notice was removed from operations without deleting its documents or history.'),
    'desembarque_id' => $desembarqueId,
    'notice_number' => $noticeNumber,
    'deleted_at' => $deletedAt,
    'redirect_url' => 'aviso-papelera.php?focus=' . $desembarqueId,
]);
