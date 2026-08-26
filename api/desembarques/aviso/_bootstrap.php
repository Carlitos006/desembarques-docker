<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/../../../config/operational_i18n.php';
require_once __DIR__ . '/../../../config/csrf.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../config/aviso_status.php';
require_once __DIR__ . '/../../../src/SnapshotIntegrity.php';

$currentLanguage = getAppLanguage();

/** @param array<string,mixed> $payload */
function aviso_json(int $status, array $payload): never
{
    $payload = translateOperationalPayload($payload, getAppLanguage());
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array{id:int,name:string,email:string,role:string} */
function aviso_require_user(): array
{
    if (! isset($_SESSION['user']['id'])) {
        aviso_json(401, [
            'success' => false,
            'message' => translateText('La sesión ha expirado.', 'Your session has expired.'),
        ]);
    }

    return [
        'id' => (int) ($_SESSION['user']['id'] ?? 0),
        'name' => trim((string) ($_SESSION['user']['name'] ?? '')),
        'email' => trim((string) ($_SESSION['user']['email'] ?? '')),
        'role' => trim((string) ($_SESSION['user']['role'] ?? '')),
    ];
}

function aviso_require_internal_user(array $user): void
{
    if (! in_array($user['role'], ['admin', 'usuario'], true)) {
        aviso_json(403, [
            'success' => false,
            'message' => translateText('No tienes permisos para modificar el aviso de desembarque.', 'You do not have permission to modify the unloading notice.'),
        ]);
    }
}

function aviso_normalize_identity(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function aviso_deleted_admin_view_requested(array $user): bool
{
    return ($user['role'] ?? '') === 'admin'
        && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
        && (string) ($_GET['deleted'] ?? '') === '1';
}

/** @return array<string,mixed> */
function aviso_require_record_access(mysqli $connection, int $desembarqueId, array $user): array
{
    if ($desembarqueId <= 0) {
        aviso_json(422, [
            'success' => false,
            'message' => translateText('El desembarque indicado no es válido.', 'The selected unloading record is not valid.'),
        ]);
    }

    $allowDeleted = aviso_deleted_admin_view_requested($user) ? 1 : 0;
    $result = $connection->execute_query(
        'SELECT d.id, d.cliente, d.client_id, d.deleted_at, d.deleted_by, d.delete_reason, '
        . 'c.user_id AS client_user_id, c.name AS client_name, c.email AS client_email '
        . 'FROM desembarques d LEFT JOIN clients c ON c.id = d.client_id '
        . 'WHERE d.id = ? AND (d.deleted_at IS NULL OR (? = 1 AND d.deleted_at IS NOT NULL)) LIMIT 1',
        [$desembarqueId, $allowDeleted]
    );
    $record = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

    if (! $record) {
        aviso_json(404, [
            'success' => false,
            'message' => translateText('Expediente no disponible.', 'Case file unavailable.'),
        ]);
    }

    if ($user['role'] !== 'cliente') {
        return $record;
    }

    $userId = (int) $user['id'];
    if ((int) ($record['client_user_id'] ?? 0) === $userId) {
        return $record;
    }

    $userName = aviso_normalize_identity((string) $user['name']);
    $userEmail = aviso_normalize_identity((string) $user['email']);
    $clientName = aviso_normalize_identity((string) ($record['client_name'] ?? ''));
    $clientEmail = aviso_normalize_identity((string) ($record['client_email'] ?? ''));
    $legacyClient = aviso_normalize_identity((string) ($record['cliente'] ?? ''));

    $allowed = ($userEmail !== '' && ($userEmail === $clientEmail || $userEmail === $legacyClient))
        || ($userName !== '' && ($userName === $clientName || $userName === $legacyClient));

    if (! $allowed) {
        aviso_json(403, [
            'success' => false,
            'message' => translateText('No tienes acceso a este desembarque.', 'You do not have access to this unloading record.'),
        ]);
    }

    return $record;
}

function aviso_clean_text(mixed $value, int $maxLength = 0): ?string
{
    if ($value === null) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $text = str_replace("\0", '', $text);

    if ($maxLength > 0) {
        $text = function_exists('mb_substr')
            ? mb_substr($text, 0, $maxLength, 'UTF-8')
            : substr($text, 0, $maxLength);
    }

    return $text;
}

function aviso_clean_datetime(mixed $value): ?string
{
    $text = aviso_clean_text($value, 40);
    if ($text === null) {
        return null;
    }

    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $text);
        if ($date instanceof DateTimeImmutable && $date->format($format) === $text) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function aviso_normalize_pedimento(string $value): string
{
    return strtolower((string) preg_replace('/[^0-9a-z]+/i', '', trim($value)));
}

function aviso_normalize_key(string $value): string
{
    return strtoupper((string) preg_replace('/\s+/', '', trim($value)));
}


/** @return array<string,mixed> */
function aviso_get_document_status(mysqli $connection, int $desembarqueId, bool $forUpdate = false): array
{
    $sql = 'SELECT aviso_status, aviso_status_effective_at, aviso_status_changed_at, aviso_status_changed_by '
        . 'FROM desembarque_aviso_details WHERE desembarque_id = ? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $result = $connection->execute_query($sql, [$desembarqueId]);
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $status = aviso_status_normalize($row['aviso_status'] ?? 'draft');

    return [
        'slug' => $status,
        'effective_at' => $row['aviso_status_effective_at'] ?? null,
        'changed_at' => $row['aviso_status_changed_at'] ?? null,
        'changed_by' => isset($row['aviso_status_changed_by']) && $row['aviso_status_changed_by'] !== null
            ? (int) $row['aviso_status_changed_by']
            : null,
        'exists' => is_array($row),
    ];
}

/** @return array<string,mixed> */
function aviso_status_response_payload(mysqli $connection, int $desembarqueId, array $user): array
{
    global $currentLanguage;

    $state = aviso_get_document_status($connection, $desembarqueId, false);
    $meta = aviso_status_meta($state['slug'], (string) ($currentLanguage ?? 'es'));
    $transitions = [];

    if (in_array((string) ($user['role'] ?? ''), ['admin', 'usuario'], true)) {
        foreach (aviso_status_manual_transitions($state['slug'], (string) $user['role']) as $target) {
            $targetMeta = aviso_status_meta($target, (string) ($currentLanguage ?? 'es'));
            $transitions[] = [
                'slug' => $target,
                'label' => $targetMeta['label'],
                'badge' => $targetMeta['badge'],
                'requires_reason' => aviso_status_requires_reason($state['slug'], $target),
                'requires_effective_at' => aviso_status_requires_effective_at($target),
            ];
        }
    }

    return array_merge($state, $meta, [
        'can_generate' => aviso_status_can_generate($state['slug']),
        'transitions' => $transitions,
    ]);
}

/**
 * Aplica una transición dentro de la transacción activa del caller.
 *
 * @return array{changed:bool,from_status:string,to_status:string,history_id:?int}
 */
function aviso_apply_status_transition(
    mysqli $connection,
    int $desembarqueId,
    string $toStatus,
    array $user,
    ?string $reason = null,
    ?string $effectiveAt = null,
    string $source = 'manual',
    ?int $versionId = null
): array {
    $state = aviso_get_document_status($connection, $desembarqueId, true);
    if (! $state['exists']) {
        throw new RuntimeException(translateText('El aviso no tiene información estructurada para cambiar de estado.', 'The notice does not have structured information for a status change.'));
    }

    $fromStatus = aviso_status_normalize($state['slug']);
    $toStatus = aviso_status_normalize($toStatus);
    $role = (string) ($user['role'] ?? '');

    if ($fromStatus === $toStatus) {
        return [
            'changed' => false,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'history_id' => null,
        ];
    }

    if ($source === 'version') {
        if ($fromStatus === 'cancelled') {
            throw new RuntimeException(translateText('El aviso está cancelado. Reábrelo a Borrador antes de emitir una nueva versión.', 'The notice is cancelled. Reopen it as Draft before issuing a new version.'));
        }
        if ($toStatus !== 'issued' || ! in_array($fromStatus, ['draft', 'presented', 'replaced'], true)) {
            throw new RuntimeException(translateText('La transición automática de emisión no es válida.', 'The automatic issuance transition is not valid.'));
        }
    } else {
        $allowed = aviso_status_manual_transitions($fromStatus, $role);
        if (! in_array($toStatus, $allowed, true)) {
            throw new RuntimeException(translateText('La transición de estado solicitada no está permitida.', 'The requested status transition is not allowed.'));
        }
    }

    $reason = aviso_clean_text($reason, 1000);
    if (aviso_status_requires_reason($fromStatus, $toStatus) && $reason === null) {
        throw new RuntimeException('Debes indicar el motivo de este cambio de estado.');
    }

    $effectiveAt = aviso_clean_datetime($effectiveAt);
    if (aviso_status_requires_effective_at($toStatus) && $effectiveAt === null) {
        throw new RuntimeException(translateText('Debes indicar la fecha y hora de presentación del aviso.', 'You must provide the notice presentation date and time.'));
    }
    if ($effectiveAt === null) {
        $effectiveAt = date('Y-m-d H:i:s');
    }

    $changedAt = date('Y-m-d H:i:s');
    $changedBy = isset($user['id']) && (int) $user['id'] > 0 ? (int) $user['id'] : null;

    $connection->execute_query(
        'UPDATE desembarque_aviso_details SET aviso_status = ?, aviso_status_effective_at = ?, '
        . 'aviso_status_changed_at = ?, aviso_status_changed_by = ? WHERE desembarque_id = ? LIMIT 1',
        [$toStatus, $effectiveAt, $changedAt, $changedBy, $desembarqueId]
    );

    $connection->execute_query(
        'INSERT INTO desembarque_aviso_status_history '
        . '(desembarque_id, from_status, to_status, reason, effective_at, source, aviso_version_id, changed_by) '
        . 'VALUES (?,?,?,?,?,?,?,?)',
        [$desembarqueId, $fromStatus, $toStatus, $reason, $effectiveAt, $source, $versionId, $changedBy]
    );
    $historyId = (int) $connection->insert_id;

    record_audit_log(
        'update',
        'aviso_status',
        (string) $desembarqueId,
        [
            'desembarque_id' => $desembarqueId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'effective_at' => $effectiveAt,
            'source' => $source,
            'aviso_version_id' => $versionId,
        ],
        $changedBy,
        $connection
    );

    return [
        'changed' => true,
        'from_status' => $fromStatus,
        'to_status' => $toStatus,
        'history_id' => $historyId,
    ];
}
