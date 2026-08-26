<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = aviso_require_user();
$connection = getDatabaseConnection();

/** @return array<string,mixed> */
function aviso_profile_row(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'client_id' => isset($row['client_id']) && $row['client_id'] !== null ? (int) $row['client_id'] : null,
        'rig_name' => (string) ($row['rig_name'] ?? ''),
        'rig_imo' => (string) ($row['rig_imo'] ?? ''),
        'rig_field' => (string) ($row['rig_field'] ?? ''),
        'rig_area' => (string) ($row['rig_area'] ?? ''),
        'comitente' => (string) ($row['comitente'] ?? ''),
        'document_prefix' => (string) (($row['document_prefix'] ?? '') ?: 'MADE'),
        'is_default' => (bool) ($row['is_default'] ?? false),
        'is_active' => (bool) ($row['is_active'] ?? false),
        'scope' => isset($row['client_id']) && $row['client_id'] !== null ? 'client' : 'global',
        'recipient' => [
            'id' => isset($row['recipient_contact_id']) && $row['recipient_contact_id'] !== null ? (int) $row['recipient_contact_id'] : null,
            'name_es' => (string) ($row['recipient_name_es'] ?? ''),
            'name_en' => (string) ($row['recipient_name_en'] ?? ''),
            'title_es' => (string) ($row['recipient_title_es'] ?? ''),
            'title_en' => (string) ($row['recipient_title_en'] ?? ''),
        ],
        'signer' => [
            'id' => isset($row['signer_contact_id']) && $row['signer_contact_id'] !== null ? (int) $row['signer_contact_id'] : null,
            'name_es' => (string) ($row['signer_name_es'] ?? ''),
            'name_en' => (string) ($row['signer_name_en'] ?? ''),
            'title_es' => (string) ($row['signer_title_es'] ?? ''),
            'title_en' => (string) ($row['signer_title_en'] ?? ''),
        ],
    ];
}

function aviso_profile_base_select(): string
{
    return <<<'SQL'
SELECT
    p.id, p.name, p.client_id, p.rig_name, p.rig_imo, p.rig_field, p.rig_area,
    p.comitente, p.document_prefix, p.is_default, p.is_active,
    p.recipient_contact_id, p.signer_contact_id,
    rc.name_es AS recipient_name_es, rc.name_en AS recipient_name_en,
    rc.title_es AS recipient_title_es, rc.title_en AS recipient_title_en,
    sc.name_es AS signer_name_es, sc.name_en AS signer_name_en,
    sc.title_es AS signer_title_es, sc.title_en AS signer_title_en
FROM aviso_profiles p
LEFT JOIN aviso_contacts rc ON rc.id = p.recipient_contact_id
LEFT JOIN aviso_contacts sc ON sc.id = p.signer_contact_id
SQL;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $desembarqueIdRaw = trim((string) ($_GET['desembarque_id'] ?? ''));
    if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw)) {
        aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
    }

    $desembarqueId = (int) $desembarqueIdRaw;
    $record = aviso_require_record_access($connection, $desembarqueId, $user);
    $clientId = isset($record['client_id']) && $record['client_id'] !== null ? (int) $record['client_id'] : 0;

    $sql = aviso_profile_base_select()
        . ' WHERE p.is_active = 1 AND (p.client_id IS NULL OR p.client_id = ?) '
        . ' ORDER BY (p.client_id IS NOT NULL) DESC, p.is_default DESC, p.name ASC, p.id ASC';
    $result = $connection->execute_query($sql, [$clientId]);

    $profiles = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $profiles[] = aviso_profile_row($row);
        }
    }

    $savedResult = $connection->execute_query(
        'SELECT aviso_profile_id AS profile_id FROM desembarque_aviso_details WHERE desembarque_id = ? LIMIT 1',
        [$desembarqueId]
    );
    $savedRow = $savedResult instanceof mysqli_result ? $savedResult->fetch_assoc() : null;
    $savedProfileId = isset($savedRow['profile_id']) && $savedRow['profile_id'] !== null
        ? (int) $savedRow['profile_id']
        : null;

    $defaultProfileId = null;
    foreach ($profiles as $profile) {
        if ($profile['is_default'] && ($profile['client_id'] === $clientId || $profile['client_id'] === null)) {
            $defaultProfileId = (int) $profile['id'];
            if ($profile['client_id'] === $clientId) {
                break;
            }
        }
    }

    aviso_json(200, [
        'success' => true,
        'profiles' => $profiles,
        'saved_profile_id' => $savedProfileId,
        'default_profile_id' => $defaultProfileId,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
$record = aviso_require_record_access($connection, $desembarqueId, $user);
$recordClientId = isset($record['client_id']) && $record['client_id'] !== null ? (int) $record['client_id'] : null;

$name = aviso_clean_text($payload['name'] ?? null, 180);
$rigName = aviso_clean_text($payload['rig_name'] ?? null, 180);
$rigImo = aviso_clean_text($payload['rig_imo'] ?? null, 40);
$rigField = aviso_clean_text($payload['rig_field'] ?? null, 120);
$rigArea = aviso_clean_text($payload['rig_area'] ?? null, 120);
$comitente = aviso_clean_text($payload['comitente'] ?? null, 255);
$documentPrefix = aviso_clean_text($payload['document_prefix'] ?? null, 30) ?? 'MADE';
$scope = trim((string) ($payload['scope'] ?? 'client'));
$isDefault = filter_var($payload['is_default'] ?? false, FILTER_VALIDATE_BOOL);

if ($name === null || $rigName === null) {
    aviso_json(422, ['success' => false, 'message' => 'El perfil requiere un nombre y el nombre del Rig.']);
}

$clientId = $scope === 'global' ? null : $recordClientId;
if ($scope === 'global' && $user['role'] !== 'admin') {
    aviso_json(403, ['success' => false, 'message' => 'Sólo un administrador puede crear perfiles globales.']);
}
if ($scope !== 'global' && $clientId === null) {
    aviso_json(422, ['success' => false, 'message' => 'El desembarque no tiene un cliente asociado para guardar un perfil por cliente.']);
}

$recipientContactId = null;
$signerContactId = null;
foreach (['recipient' => 'recipient_contact_id', 'signer' => 'signer_contact_id'] as $contactType => $fieldName) {
    $rawId = trim((string) ($payload[$fieldName] ?? ''));
    if ($rawId !== '' && ctype_digit($rawId)) {
        $candidate = (int) $rawId;
        $check = $connection->execute_query(
            'SELECT id FROM aviso_contacts WHERE id = ? AND contact_type = ? LIMIT 1',
            [$candidate, $contactType]
        );
        if ($check instanceof mysqli_result && $check->fetch_assoc()) {
            if ($contactType === 'recipient') {
                $recipientContactId = $candidate;
            } else {
                $signerContactId = $candidate;
            }
        }
    }
}

if ($recipientContactId === null) {
    $defaultRecipient = $connection->query("SELECT id FROM aviso_contacts WHERE contact_type = 'recipient' ORDER BY is_default DESC, id ASC LIMIT 1");
    $row = $defaultRecipient instanceof mysqli_result ? $defaultRecipient->fetch_assoc() : null;
    $recipientContactId = $row ? (int) $row['id'] : null;
}
if ($signerContactId === null) {
    $defaultSigner = $connection->query("SELECT id FROM aviso_contacts WHERE contact_type = 'signer' ORDER BY is_default DESC, id ASC LIMIT 1");
    $row = $defaultSigner instanceof mysqli_result ? $defaultSigner->fetch_assoc() : null;
    $signerContactId = $row ? (int) $row['id'] : null;
}

try {
    $connection->begin_transaction();

    if ($isDefault) {
        if ($clientId === null) {
            $connection->query('UPDATE aviso_profiles SET is_default = 0 WHERE client_id IS NULL');
        } else {
            $connection->execute_query('UPDATE aviso_profiles SET is_default = 0 WHERE client_id = ?', [$clientId]);
        }
    }

    $connection->execute_query(
        'INSERT INTO aviso_profiles '
        . '(name, client_id, rig_name, rig_imo, rig_field, rig_area, comitente, recipient_contact_id, signer_contact_id, document_prefix, is_default, is_active, created_by) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $name, $clientId, $rigName, $rigImo, $rigField, $rigArea, $comitente,
            $recipientContactId, $signerContactId, $documentPrefix,
            $isDefault ? 1 : 0, 1, $user['id'],
        ]
    );
    $profileId = (int) $connection->insert_id;

    record_audit_log(
        'create',
        'aviso_profile',
        (string) $profileId,
        [
            'after' => [
                'name' => $name,
                'client_id' => $clientId,
                'rig_name' => $rigName,
                'rig_imo' => $rigImo,
                'rig_field' => $rigField,
                'rig_area' => $rigArea,
                'comitente' => $comitente,
                'is_default' => $isDefault,
            ],
        ],
        $user['id'],
        $connection
    );

    $connection->commit();
} catch (Throwable $exception) {
    $connection->rollback();
    error_log('[aviso-profile] ' . $exception->getMessage());
    aviso_json(500, ['success' => false, 'message' => 'No fue posible guardar el perfil.']);
}

$result = $connection->execute_query(aviso_profile_base_select() . ' WHERE p.id = ? LIMIT 1', [$profileId]);
$row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

aviso_json(201, [
    'success' => true,
    'message' => 'Perfil guardado correctamente.',
    'profile' => $row ? aviso_profile_row($row) : ['id' => $profileId, 'name' => $name],
]);
