<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/aviso/_bootstrap.php';

/** @return array{id:int,name:string,email:string,role:string} */
function aviso_admin_require_admin(): array
{
    $user = aviso_require_user();
    if (($user['role'] ?? '') !== 'admin') {
        aviso_json(403, [
            'success' => false,
            'message' => translateText('Esta acción está reservada a administradores.', 'This action is restricted to administrators.'),
        ]);
    }

    return $user;
}

/** @return array<string,mixed> */
function aviso_admin_request_payload(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return is_array($_POST) ? $_POST : [];
}

function aviso_admin_validate_csrf(array $payload): void
{
    $token = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (! validate_csrf_token(is_string($token) ? $token : null)) {
        aviso_json(419, [
            'success' => false,
            'message' => translateText('La sesión de seguridad expiró. Recarga la página e intenta nuevamente.', 'The security session has expired. Reload the page and try again.'),
        ]);
    }
}

/** @return array<string,mixed> */
function aviso_admin_lock_record(mysqli $connection, int $desembarqueId): array
{
    $result = $connection->execute_query(
        'SELECT d.id, d.referencia, d.folio_aviso, d.manifiesto, d.cliente, d.deleted_at, d.deleted_by, d.delete_reason, '
        . 'ad.id AS aviso_detail_id, ad.notice_number, ad.manifiesto AS aviso_manifiesto, ad.document_code '
        . 'FROM desembarques d '
        . 'LEFT JOIN desembarque_aviso_details ad ON ad.desembarque_id = d.id '
        . 'WHERE d.id = ? LIMIT 1 FOR UPDATE',
        [$desembarqueId]
    );
    $record = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if (! is_array($record)) {
        aviso_json(404, [
            'success' => false,
            'message' => translateText('Expediente no disponible.', 'Case file unavailable.'),
        ]);
    }

    return $record;
}

function aviso_admin_notice_number(array $record): string
{
    $noticeNumber = trim((string) ($record['notice_number'] ?? ''));
    if ($noticeNumber !== '') {
        return $noticeNumber;
    }

    // Debe seguir exactamente la misma regla que aviso-expediente.php.
    // Cuando existe detalle, la interfaz usa su manifiesto; sólo si no existe
    // detalle utiliza el manifiesto legado de la tabla padre.
    $manifest = array_key_exists('aviso_detail_id', $record) && $record['aviso_detail_id'] !== null
        ? trim((string) ($record['aviso_manifiesto'] ?? ''))
        : trim((string) ($record['manifiesto'] ?? ''));
    if ($manifest !== '') {
        return $manifest;
    }

    $reference = trim((string) ($record['referencia'] ?? ''));
    if ($reference !== '') {
        return $reference;
    }

    $folio = trim((string) ($record['folio_aviso'] ?? ''));
    if ($folio !== '') {
        return $folio;
    }

    return '#' . (int) ($record['id'] ?? 0);
}

/** @return array<string,int> */
function aviso_admin_preserved_counts(mysqli $connection, int $desembarqueId): array
{
    $queries = [
        'pdf_versions' => 'SELECT COUNT(*) AS total FROM desembarque_aviso_versions WHERE desembarque_id = ?',
        'items' => 'SELECT COUNT(*) AS total FROM desembarque_aviso_items WHERE desembarque_id = ?',
        'files' => 'SELECT COUNT(*) AS total FROM desembarque_files WHERE desembarque_id = ?',
        'alcances' => 'SELECT COUNT(*) AS total FROM desembarque_aviso_alcances WHERE desembarque_id = ?',
        'finalizations' => 'SELECT COUNT(*) AS total FROM desembarque_item_finalizations WHERE desembarque_id = ?',
    ];
    $counts = [];

    foreach ($queries as $key => $sql) {
        try {
            $result = $connection->execute_query($sql, [$desembarqueId]);
            $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            $counts[$key] = (int) ($row['total'] ?? 0);
        } catch (Throwable) {
            // Algunas instalaciones antiguas pueden no tener aún todos los módulos opcionales.
            $counts[$key] = 0;
        }
    }

    return $counts;
}

function aviso_admin_required_text(array $payload, string $key, int $maxLength): string
{
    $value = trim((string) ($payload[$key] ?? ''));
    if ($value === '') {
        aviso_json(422, [
            'success' => false,
            'message' => translateText('El motivo es obligatorio.', 'A reason is required.'),
            'errors' => [$key => translateText('El motivo es obligatorio.', 'A reason is required.')],
        ]);
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function aviso_admin_require_confirmation(array $payload, string $expected): void
{
    $provided = trim((string) ($payload['confirmation'] ?? ''));
    if ($provided === '' || ! hash_equals($expected, $provided)) {
        throw new InvalidArgumentException(translateText('Escribe exactamente: ', 'Type exactly: ') . $expected);
    }
}

/** @param array<string,mixed> $payload */
function aviso_admin_record_audit_or_fail(
    mysqli $connection,
    string $action,
    int $desembarqueId,
    array $payload,
    int $userId
): void {
    $payloadJson = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $connection->execute_query(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, payload) VALUES (?, ?, ?, ?, ?)',
        [$userId, $action, 'desembarque', (string) $desembarqueId, $payloadJson]
    );
    if ($connection->affected_rows !== 1) {
        throw new RuntimeException(translateText('No fue posible registrar la auditoría administrativa.', 'The administrative audit event could not be recorded.'));
    }
}
