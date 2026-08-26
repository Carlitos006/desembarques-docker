<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/aviso_status.php';
require_once __DIR__ . '/../api/desembarques/files/_documents.php';

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();

if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

$user = $_SESSION['user'];
$userId = (int) ($user['id'] ?? 0);
$userName = trim((string) ($user['name'] ?? ''));
$userEmail = trim((string) ($user['email'] ?? ''));
$userRole = trim((string) ($user['role'] ?? ''));
$csrfToken = csrf_token();
$deletedViewRequested = $userRole === 'admin' && (string) ($_GET['deleted'] ?? '') === '1';
$isDeletedView = false;

if (! in_array($userRole, ['admin', 'usuario', 'cliente'], true)) {
    header('Location: index.php');
    exit;
}

$desembarqueIdRaw = trim((string) ($_GET['id'] ?? ''));
if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw) || (int) $desembarqueIdRaw <= 0) {
    http_response_code(422);
    $pageError = $currentLanguage === 'en' ? 'The requested notice case file is not valid.' : 'El expediente solicitado no es válido.';
    $desembarqueId = 0;
} else {
    $pageError = '';
    $desembarqueId = (int) $desembarqueIdRaw;
}

$allowedTabs = ['resumen', 'documentos', 'mercancias', 'fotos', 'alcances', 'pdfs', 'historial'];
$requestedTab = strtolower(trim((string) ($_GET['tab'] ?? 'resumen')));
if (! in_array($requestedTab, $allowedTabs, true)) {
    $requestedTab = 'resumen';
}

function expediente_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function expediente_normalize_identity(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function expediente_format_date(?string $value, bool $withTime = false): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function expediente_format_quantity(float $value): string
{
    $value = round($value, 3);
    $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

/** @return list<array{key:string,number:string,importer_name:string}> */
function expediente_item_pedimentos(array $item): array
{
    $relations = isset($item['pedimentos']) && is_array($item['pedimentos']) ? $item['pedimentos'] : [];
    $result = [];
    foreach ($relations as $relation) {
        if (! is_array($relation)) {
            continue;
        }
        $number = trim((string) ($relation['number'] ?? $relation['num_pedimento'] ?? ''));
        if ($number === '') {
            continue;
        }
        $result[] = [
            'key' => strtoupper(trim((string) ($relation['key'] ?? $relation['clave'] ?? ''))),
            'number' => $number,
            'importer_name' => trim((string) ($relation['importer_name'] ?? '')),
        ];
    }
    if ($result === [] && trim((string) ($item['num_pedimento'] ?? '')) !== '') {
        $result[] = [
            'key' => strtoupper(trim((string) ($item['clave'] ?? ''))),
            'number' => trim((string) ($item['num_pedimento'] ?? '')),
            'importer_name' => trim((string) ($item['importer_name'] ?? '')),
        ];
    }
    return $result;
}

function expediente_render_item_pedimentos(array $item): string
{
    $relations = expediente_item_pedimentos($item);
    if ($relations === []) {
        return '<span class="text-muted">—</span>';
    }
    $html = '<div class="d-grid gap-1">';
    foreach ($relations as $relation) {
        $key = expediente_h($relation['key']);
        $number = expediente_h($relation['number']);
        $html .= '<div class="small"><span class="badge text-bg-light border text-dark">' . ($key !== '' ? $key : '—') . '</span><div class="mt-1">' . $number . '</div></div>';
    }
    return $html . '</div>';
}

function expediente_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $index = 0;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    return number_format($size, $size >= 10 || $index === 0 ? 0 : 1) . ' ' . $units[$index];
}

/** @return array<string,mixed> */
function expediente_json_array(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $exception) {
        return [];
    }
}

$record = null;
$detail = null;
$profile = null;
$items = [];
$photos = [];
$files = [];
$versions = [];
$pedimentoHeaders = [];
$timeline = [];
$statusMap = [];
$noticeStatusHistory = [];
$itemFinalizedQty = [];
$itemMovementCount = [];
$itemFinalizations = [];
$alcances = [];
$nextAlcanceNo = 1;

if ($pageError === '' && $desembarqueId > 0) {
    try {
        /** @var mysqli $connection */
        $connection = getDatabaseConnection();

        $recordVisibility = $deletedViewRequested ? 'd.deleted_at IS NOT NULL' : 'd.deleted_at IS NULL';
        $recordResult = $connection->execute_query(
            'SELECT d.*, s.slug AS status_slug, s.name_es AS status_name_es, s.name_en AS status_name_en, '
            . 'c.name AS client_name, c.email AS client_email, c.user_id AS client_user_id, u.name AS created_by_name '
            . 'FROM desembarques d '
            . 'LEFT JOIN desembarque_statuses s ON s.id = d.status_id '
            . 'LEFT JOIN clients c ON c.id = d.client_id '
            . 'LEFT JOIN users u ON u.id = d.created_by '
            . 'WHERE d.id = ? AND ' . $recordVisibility . ' LIMIT 1',
            [$desembarqueId]
        );
        $record = $recordResult instanceof mysqli_result ? $recordResult->fetch_assoc() : null;
        $isDeletedView = is_array($record) && ! empty($record['deleted_at']);

        if (! $record) {
            http_response_code(404);
            $pageError = $currentLanguage === 'en' ? 'The unloading record was not found.' : 'No se encontró el desembarque.';
        } elseif ($userRole === 'cliente') {
            $clientMatches = false;
            if ((int) ($record['client_user_id'] ?? 0) === $userId && $userId > 0) {
                $clientMatches = true;
            }

            $sessionName = expediente_normalize_identity($userName);
            $sessionEmail = expediente_normalize_identity($userEmail);
            $clientName = expediente_normalize_identity((string) ($record['client_name'] ?? ''));
            $clientEmail = expediente_normalize_identity((string) ($record['client_email'] ?? ''));
            $legacyClient = expediente_normalize_identity((string) ($record['cliente'] ?? ''));

            if (! $clientMatches && $sessionEmail !== '' && ($sessionEmail === $clientEmail || $sessionEmail === $legacyClient)) {
                $clientMatches = true;
            }
            if (! $clientMatches && $sessionName !== '' && ($sessionName === $clientName || $sessionName === $legacyClient)) {
                $clientMatches = true;
            }

            if (! $clientMatches) {
                http_response_code(403);
                $pageError = $currentLanguage === 'en' ? 'You do not have access to this notice case file.' : 'No tienes acceso a este expediente.';
            }
        }

        if ($pageError === '') {
            $detailResult = $connection->execute_query(
                'SELECT d.*, p.name AS profile_name, p.rig_name AS profile_rig_name, p.rig_imo AS profile_rig_imo, '
                . 'p.rig_field AS profile_rig_field, p.rig_area AS profile_rig_area, p.comitente AS profile_comitente, '
                . 'su.name AS aviso_status_changed_by_name '
                . 'FROM desembarque_aviso_details d '
                . 'LEFT JOIN aviso_profiles p ON p.id = d.aviso_profile_id '
                . 'LEFT JOIN users su ON su.id = d.aviso_status_changed_by '
                . 'WHERE d.desembarque_id = ? LIMIT 1',
                [$desembarqueId]
            );
            $detail = $detailResult instanceof mysqli_result ? $detailResult->fetch_assoc() : null;
            if ($detail && ! empty($detail['aviso_profile_id'])) {
                $profile = [
                    'id' => (int) $detail['aviso_profile_id'],
                    'name' => (string) ($detail['profile_name'] ?? ''),
                    'rig_name' => (string) ($detail['profile_rig_name'] ?? ''),
                    'rig_imo' => (string) ($detail['profile_rig_imo'] ?? ''),
                    'rig_field' => (string) ($detail['profile_rig_field'] ?? ''),
                    'rig_area' => (string) ($detail['profile_rig_area'] ?? ''),
                    'comitente' => (string) ($detail['profile_comitente'] ?? ''),
                ];
            }

            $itemsResult = $connection->execute_query(
                'SELECT * FROM desembarque_aviso_items WHERE desembarque_id = ? ORDER BY sort_order ASC, id ASC',
                [$desembarqueId]
            );
            if ($itemsResult instanceof mysqli_result) {
                while ($row = $itemsResult->fetch_assoc()) {
                    $items[] = $row;
                }
            }

            $itemPedimentoMap = [];
            $itemPedimentoResult = $connection->execute_query(
                'SELECT ip.aviso_item_id, ip.clave, ip.num_pedimento, ip.importer_name, ip.sort_order '
                . 'FROM desembarque_aviso_item_pedimentos ip '
                . 'INNER JOIN desembarque_aviso_items i ON i.id = ip.aviso_item_id '
                . 'WHERE i.desembarque_id = ? ORDER BY ip.aviso_item_id ASC, ip.sort_order ASC, ip.id ASC',
                [$desembarqueId]
            );
            if ($itemPedimentoResult instanceof mysqli_result) {
                while ($relation = $itemPedimentoResult->fetch_assoc()) {
                    $itemPedimentoMap[(int) ($relation['aviso_item_id'] ?? 0)][] = [
                        'key' => (string) ($relation['clave'] ?? ''),
                        'number' => (string) ($relation['num_pedimento'] ?? ''),
                        'importer_name' => (string) ($relation['importer_name'] ?? ''),
                    ];
                }
            }
            foreach ($items as &$item) {
                $item['pedimentos'] = $itemPedimentoMap[(int) ($item['id'] ?? 0)] ?? [];
                if ($item['pedimentos'] === [] && trim((string) ($item['num_pedimento'] ?? '')) !== '') {
                    $item['pedimentos'][] = [
                        'key' => (string) ($item['clave'] ?? ''),
                        'number' => (string) ($item['num_pedimento'] ?? ''),
                        'importer_name' => (string) ($item['importer_name'] ?? ''),
                    ];
                }
            }
            unset($item);

            $finalizedQtyResult = $connection->execute_query(
                'SELECT l.aviso_item_id, COALESCE(SUM(l.quantity_exported), 0) AS finalized_quantity '
                . 'FROM desembarque_item_finalization_lines l '
                . 'INNER JOIN desembarque_item_finalizations f ON f.id = l.finalization_id '
                . 'WHERE f.desembarque_id = ? AND f.voided_at IS NULL GROUP BY l.aviso_item_id',
                [$desembarqueId]
            );
            if ($finalizedQtyResult instanceof mysqli_result) {
                while ($row = $finalizedQtyResult->fetch_assoc()) {
                    $itemFinalizedQty[(int) ($row['aviso_item_id'] ?? 0)] = round((float) ($row['finalized_quantity'] ?? 0), 3);
                }
            }

            $finalizationsResult = $connection->execute_query(
                'SELECT f.*, u.name AS created_by_name, vu.name AS voided_by_name, '
                . 'cu.name AS closure_completed_by_name '
                . 'FROM desembarque_item_finalizations f '
                . 'LEFT JOIN users u ON u.id = f.created_by '
                . 'LEFT JOIN users vu ON vu.id = f.voided_by '
                . 'LEFT JOIN users cu ON cu.id = f.closure_completed_by '
                . 'WHERE f.desembarque_id = ? ORDER BY f.export_date DESC, f.id DESC',
                [$desembarqueId]
            );
            if ($finalizationsResult instanceof mysqli_result) {
                while ($row = $finalizationsResult->fetch_assoc()) {
                    $row['lines'] = [];
                    $itemFinalizations[(int) ($row['id'] ?? 0)] = $row;
                }
            }

            if ($itemFinalizations !== []) {
                $finalizationLinesResult = $connection->execute_query(
                    'SELECT l.id AS finalization_line_id, l.finalization_id, l.aviso_item_id, l.quantity_exported, '
                    . 'l.pedimento_r1_agregar, l.pedimento_retorno_parcial_h1, l.mercancia_pedimento_h1, '
                    . 'l.pedimento_r1_desagregado, l.pedimento_a3, '
                    . 'i.descripcion, i.serial_number, i.cantidad AS original_quantity, i.num_pedimento '
                    . 'FROM desembarque_item_finalization_lines l '
                    . 'INNER JOIN desembarque_item_finalizations f ON f.id = l.finalization_id '
                    . 'INNER JOIN desembarque_aviso_items i ON i.id = l.aviso_item_id '
                    . 'WHERE f.desembarque_id = ? ORDER BY f.export_date DESC, f.id DESC, i.sort_order ASC, i.id ASC',
                    [$desembarqueId]
                );
                if ($finalizationLinesResult instanceof mysqli_result) {
                    while ($line = $finalizationLinesResult->fetch_assoc()) {
                        $finalizationId = (int) ($line['finalization_id'] ?? 0);
                        if (isset($itemFinalizations[$finalizationId])) {
                            $itemFinalizations[$finalizationId]['lines'][] = $line;
                            if (empty($itemFinalizations[$finalizationId]['voided_at'])) {
                                $lineItemId = (int) ($line['aviso_item_id'] ?? 0);
                                if ($lineItemId > 0) {
                                    $itemMovementCount[$lineItemId] = (int) ($itemMovementCount[$lineItemId] ?? 0) + 1;
                                }
                            }
                        }
                    }
                }
                $itemFinalizations = array_values($itemFinalizations);
            }

            $photosResult = $connection->execute_query(
                'SELECT ai.id, ai.file_id, ai.aviso_item_id, ai.caption, ai.sort_order, '
                . 'i.descripcion AS item_description, i.source_row AS item_source_row, '
                . 'f.original_name, f.mime_type, f.extension, f.size, f.created_at, u.name AS uploaded_by_name '
                . 'FROM desembarque_aviso_images ai '
                . 'INNER JOIN desembarque_files f ON f.id = ai.file_id AND f.desembarque_id = ai.desembarque_id '
                . 'LEFT JOIN desembarque_aviso_items i ON i.id = ai.aviso_item_id AND i.desembarque_id = ai.desembarque_id '
                . 'LEFT JOIN users u ON u.id = f.uploaded_by '
                . 'WHERE ai.desembarque_id = ? ORDER BY ai.sort_order ASC, ai.id ASC',
                [$desembarqueId]
            );
            $photoFileIds = [];
            if ($photosResult instanceof mysqli_result) {
                while ($row = $photosResult->fetch_assoc()) {
                    $photos[] = $row;
                    $photoFileIds[(int) ($row['file_id'] ?? 0)] = true;
                }
            }

            $filesResult = $connection->execute_query(
                'SELECT f.*, u.name AS uploaded_by_name FROM desembarque_files f '
                . 'LEFT JOIN users u ON u.id = f.uploaded_by '
                . 'WHERE f.desembarque_id = ? ORDER BY f.created_at DESC, f.id DESC',
                [$desembarqueId]
            );
            if ($filesResult instanceof mysqli_result) {
                while ($row = $filesResult->fetch_assoc()) {
                    $fileId = (int) ($row['id'] ?? 0);
                    $extension = strtolower((string) ($row['extension'] ?? ''));
                    $sourceExcelName = trim((string) ($detail['source_excel_name'] ?? ''));
                    $isSourceExcel = (string) ($row['purpose'] ?? '') === 'source_excel'
                        || ($sourceExcelName !== '' && trim((string) ($row['original_name'] ?? '')) === $sourceExcelName);
                    if (! $isSourceExcel && in_array($extension, ['xlsx', 'xls'], true) && $sourceExcelName === '') {
                        $isSourceExcel = true;
                    }
                    $row['is_photo'] = isset($photoFileIds[$fileId]);
                    $row['is_source_excel'] = $isSourceExcel;
                    $files[] = $row;
                }
            }

            $versionsResult = $connection->execute_query(
                'SELECT v.*, u.name AS generated_by_name FROM desembarque_aviso_versions v '
                . 'LEFT JOIN users u ON u.id = v.generated_by '
                . 'WHERE v.desembarque_id = ? ORDER BY v.version_no DESC, v.id DESC',
                [$desembarqueId]
            );
            if ($versionsResult instanceof mysqli_result) {
                while ($row = $versionsResult->fetch_assoc()) {
                    $versions[] = $row;
                }
            }

            $alcancesResult = $connection->execute_query(
                'SELECT a.*, u.name AS created_by_name FROM desembarque_aviso_alcances a '
                . 'LEFT JOIN users u ON u.id = a.created_by WHERE a.desembarque_id = ? '
                . 'ORDER BY a.alcance_no DESC, a.id DESC',
                [$desembarqueId]
            );
            $alcanceMap = [];
            if ($alcancesResult instanceof mysqli_result) {
                while ($row = $alcancesResult->fetch_assoc()) {
                    $alcanceId = (int) ($row['id'] ?? 0);
                    $row['items'] = [];
                    $row['item_ids'] = [];
                    $row['versions'] = [];
                    $row['latest_version'] = null;
                    $row['latest_receipt'] = null;
                    $alcanceMap[$alcanceId] = $row;
                    $nextAlcanceNo = max($nextAlcanceNo, (int) ($row['alcance_no'] ?? 0) + 1);
                }
            }

            if ($alcanceMap !== []) {
                $alcanceItemsResult = $connection->execute_query(
                    'SELECT ai.alcance_id, ai.sort_order AS alcance_sort_order, i.* '
                    . 'FROM desembarque_aviso_alcance_items ai '
                    . 'INNER JOIN desembarque_aviso_items i ON i.id = ai.aviso_item_id '
                    . 'INNER JOIN desembarque_aviso_alcances a ON a.id = ai.alcance_id '
                    . 'WHERE a.desembarque_id = ? ORDER BY a.alcance_no DESC, ai.sort_order ASC, ai.id ASC',
                    [$desembarqueId]
                );
                if ($alcanceItemsResult instanceof mysqli_result) {
                    while ($row = $alcanceItemsResult->fetch_assoc()) {
                        $alcanceId = (int) ($row['alcance_id'] ?? 0);
                        if (isset($alcanceMap[$alcanceId])) {
                            $alcanceMap[$alcanceId]['items'][] = $row;
                            $alcanceMap[$alcanceId]['item_ids'][] = (int) ($row['id'] ?? 0);
                        }
                    }
                }

                $alcanceVersionsResult = $connection->execute_query(
                    'SELECT v.*, a.alcance_no, u.name AS generated_by_name, '
                    . 'r.id AS receipt_id, r.folio AS receipt_folio, r.received_at AS receipt_received_at, '
                    . 'r.notes AS receipt_notes, r.evidence_file_id AS receipt_evidence_file_id, '
                    . 'ru.name AS receipt_recorded_by_name '
                    . 'FROM desembarque_aviso_alcance_versions v '
                    . 'INNER JOIN desembarque_aviso_alcances a ON a.id = v.alcance_id '
                    . 'LEFT JOIN users u ON u.id = v.generated_by '
                    . 'LEFT JOIN desembarque_aviso_alcance_receipts r ON r.alcance_version_id = v.id '
                    . 'LEFT JOIN users ru ON ru.id = r.recorded_by '
                    . 'WHERE a.desembarque_id = ? ORDER BY a.alcance_no DESC, v.version_no DESC, v.id DESC',
                    [$desembarqueId]
                );
                if ($alcanceVersionsResult instanceof mysqli_result) {
                    while ($row = $alcanceVersionsResult->fetch_assoc()) {
                        $alcanceId = (int) ($row['alcance_id'] ?? 0);
                        if (! isset($alcanceMap[$alcanceId])) {
                            continue;
                        }
                        $alcanceMap[$alcanceId]['versions'][] = $row;
                        if ($alcanceMap[$alcanceId]['latest_version'] === null) {
                            $alcanceMap[$alcanceId]['latest_version'] = $row;
                            if (! empty($row['receipt_id'])) {
                                $alcanceMap[$alcanceId]['latest_receipt'] = [
                                    'id' => (int) $row['receipt_id'],
                                    'folio' => (string) ($row['receipt_folio'] ?? ''),
                                    'received_at' => (string) ($row['receipt_received_at'] ?? ''),
                                    'notes' => (string) ($row['receipt_notes'] ?? ''),
                                    'evidence_file_id' => isset($row['receipt_evidence_file_id']) ? (int) $row['receipt_evidence_file_id'] : null,
                                    'recorded_by_name' => (string) ($row['receipt_recorded_by_name'] ?? ''),
                                ];
                            }
                        }
                    }
                }
            }
            $alcances = array_values($alcanceMap);

            $headersResult = $connection->execute_query(
                'SELECT id, num_pedimento, cve_pedimento, razon_social, fecha_entrada, fecha_pago '
                . 'FROM desembarque_pedimento_headers WHERE desembarque_id = ? ORDER BY id ASC',
                [$desembarqueId]
            );
            if ($headersResult instanceof mysqli_result) {
                while ($row = $headersResult->fetch_assoc()) {
                    $pedimentoHeaders[] = $row;
                }
            }

            $statusResult = $connection->query('SELECT id, slug, name_es, name_en FROM desembarque_statuses');
            if ($statusResult instanceof mysqli_result) {
                while ($row = $statusResult->fetch_assoc()) {
                    $statusMap[(int) $row['id']] = $currentLanguage === 'en'
                        ? ((string) ($row['name_en'] ?? '') ?: (string) ($row['name_es'] ?? ''))
                        : ((string) ($row['name_es'] ?? '') ?: (string) ($row['name_en'] ?? ''));
                }
            }

            $timelineResult = $connection->execute_query(
                'SELECT l.id, l.user_id, l.action, l.entity_type, l.entity_id, l.payload, l.created_at, u.name AS user_name '
                . 'FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id '
                . 'WHERE ((l.entity_type IN (\'desembarque\', \'desembarque_aviso\', \'desembarques.status\', \'historical_aviso_import\') AND l.entity_id = ?) '
                . 'OR (l.entity_type = \'aviso_version\' AND JSON_UNQUOTE(JSON_EXTRACT(l.payload, \'$.desembarque_id\')) = ?) '
                . 'OR (l.entity_type = \'desembarque_document\' AND JSON_UNQUOTE(JSON_EXTRACT(l.payload, \'$.desembarque_id\')) = ?) '
                . 'OR (l.entity_type = \'aviso_merchandise_finalization\' AND JSON_UNQUOTE(JSON_EXTRACT(l.payload, \'$.desembarque_id\')) = ?) '
                . 'OR (l.entity_type = \'desembarque_aviso_photos\' AND JSON_UNQUOTE(JSON_EXTRACT(l.payload, \'$.desembarque_id\')) = ?) '
                . 'OR (l.entity_type IN (\'aviso_alcance\', \'aviso_alcance_version\', \'aviso_alcance_receipt\') AND JSON_UNQUOTE(JSON_EXTRACT(l.payload, \'$.desembarque_id\')) = ?)) '
                . 'ORDER BY l.created_at DESC, l.id DESC LIMIT 150',
                [(string) $desembarqueId, (string) $desembarqueId, (string) $desembarqueId, (string) $desembarqueId, (string) $desembarqueId, (string) $desembarqueId]
            );
            if ($timelineResult instanceof mysqli_result) {
                while ($row = $timelineResult->fetch_assoc()) {
                    $payload = expediente_json_array($row['payload'] ?? null);
                    $label = '';
                    $detailText = '';
                    $entityType = (string) ($row['entity_type'] ?? '');
                    $action = (string) ($row['action'] ?? '');

                    if ($entityType === 'aviso_version') {
                        $versionNo = (int) ($payload['version_no'] ?? 0);
                        $label = $currentLanguage === 'en' ? 'Notice PDF issued' : 'PDF del aviso emitido';
                        $detailText = $versionNo > 0 ? 'v' . str_pad((string) $versionNo, 3, '0', STR_PAD_LEFT) : '';
                    } elseif ($entityType === 'desembarque_document') {
                        $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];
                        $beforeDocument = is_array($payload['before'] ?? null) ? $payload['before'] : [];
                        $afterDocument = is_array($payload['after'] ?? null) ? $payload['after'] : [];
                        if ($action === 'replace') {
                            $label = $currentLanguage === 'en' ? 'Case file document replaced' : 'Documento del expediente reemplazado';
                            $beforeName = trim((string) ($beforeDocument['original_name'] ?? ''));
                            $afterName = trim((string) ($afterDocument['original_name'] ?? ''));
                            $detailText = trim($beforeName . ($beforeName !== '' && $afterName !== '' ? ' → ' : '') . $afterName);
                        } else {
                            $label = $currentLanguage === 'en' ? 'Case file document added' : 'Documento añadido al expediente';
                            $detailText = (string) ($document['original_name'] ?? '');
                        }
                    } elseif ($entityType === 'aviso_merchandise_finalization') {
                        $isVoidMovement = trim((string) ($payload['action'] ?? '')) === 'void';
                        $isDocumentaryCompletion = trim((string) ($payload['action'] ?? '')) === 'complete_documentary_closure';
                        if ($isVoidMovement) {
                            $label = $currentLanguage === 'en' ? 'Merchandise departure voided' : 'Salida de mercancía anulada';
                        } elseif ($isDocumentaryCompletion) {
                            $label = $currentLanguage === 'en' ? 'Customs documentation completed' : 'Pedimentos de la salida completados';
                        } else {
                            $label = $currentLanguage === 'en' ? 'Merchandise departure recorded' : 'Salida de mercancía registrada';
                        }
                        $parts = [];
                        $lineCount = (int) ($payload['line_count'] ?? 0);
                        $pieceCountEvent = (float) ($payload['piece_count'] ?? 0);
                        $exportDateEvent = trim((string) ($payload['export_date'] ?? ''));
                        if ($lineCount > 0) {
                            $parts[] = $lineCount . ($currentLanguage === 'en' ? ($lineCount === 1 ? ' merchandise line' : ' merchandise lines') : ' renglones');
                        }
                        if ($pieceCountEvent > 0) {
                            $parts[] = expediente_format_quantity($pieceCountEvent) . ($currentLanguage === 'en' ? ' pieces' : ' piezas');
                        }
                        if ($exportDateEvent !== '') {
                            $parts[] = expediente_format_date($exportDateEvent, false);
                        }
                        if ($isVoidMovement && trim((string) ($payload['reason'] ?? '')) !== '') {
                            $parts[] = trim((string) $payload['reason']);
                        }
                        if (! $isVoidMovement && ! $isDocumentaryCompletion && (string) ($payload['closure_status'] ?? '') === 'partial') {
                            $parts[] = $currentLanguage === 'en' ? 'partial closure' : 'cierre parcial';
                        }
                        $detailText = implode(' · ', $parts);
                    } elseif ($entityType === 'desembarque_aviso_photos') {
                        $label = $currentLanguage === 'en' ? 'Photos added to the notice' : 'Fotografías añadidas al aviso';
                        $count = (int) ($payload['photos_count'] ?? 0);
                        $detailText = $count > 0
                            ? $count . ($currentLanguage === 'en' ? ($count === 1 ? ' photo' : ' photos') : ($count === 1 ? ' fotografía' : ' fotografías'))
                            : '';
                    } elseif ($entityType === 'aviso_alcance') {
                        $label = $currentLanguage === 'en' ? 'Notice addendum issued' : 'Alcance del aviso emitido';
                        $parts = [];
                        if ((int) ($payload['alcance_no'] ?? 0) > 0) {
                            $parts[] = ($currentLanguage === 'en' ? 'Addendum #' : 'Alcance #') . (int) $payload['alcance_no'];
                        }
                        if ((int) ($payload['item_count'] ?? 0) > 0) {
                            $payloadItemCount = (int) $payload['item_count'];
                            $parts[] = $payloadItemCount . ($currentLanguage === 'en' ? ($payloadItemCount === 1 ? ' merchandise line' : ' merchandise lines') : ' mercancías');
                        }
                        $detailText = implode(' · ', $parts);
                    } elseif ($entityType === 'aviso_alcance_version') {
                        $label = $currentLanguage === 'en' ? 'Addendum PDF version issued' : 'Nueva versión PDF del Alcance';
                        $parts = [];
                        if ((int) ($payload['alcance_no'] ?? 0) > 0) {
                            $parts[] = ($currentLanguage === 'en' ? 'Addendum #' : 'Alcance #') . (int) $payload['alcance_no'];
                        }
                        if ((int) ($payload['version_no'] ?? 0) > 0) {
                            $parts[] = 'v' . str_pad((string) (int) $payload['version_no'], 3, '0', STR_PAD_LEFT);
                        }
                        $detailText = implode(' · ', $parts);
                    } elseif ($entityType === 'aviso_alcance_receipt') {
                        $label = $action === 'update'
                            ? ($currentLanguage === 'en' ? 'Addendum acknowledgment corrected' : 'Acuse del Alcance corregido')
                            : ($currentLanguage === 'en' ? 'Addendum acknowledgment recorded' : 'Acuse del Alcance registrado');
                        $parts = [];
                        if ((int) ($payload['alcance_no'] ?? 0) > 0) {
                            $parts[] = ($currentLanguage === 'en' ? 'Addendum #' : 'Alcance #') . (int) $payload['alcance_no'];
                        }
                        if (trim((string) ($payload['folio'] ?? '')) !== '') {
                            $parts[] = ($currentLanguage === 'en' ? 'Acknowledgment reference number ' : 'Folio ') . trim((string) $payload['folio']);
                        }
                        if (trim((string) ($payload['received_at'] ?? '')) !== '') {
                            $parts[] = expediente_format_date((string) $payload['received_at'], true);
                        }
                        $detailText = implode(' · ', $parts);
                    } elseif ($entityType === 'historical_aviso_import') {
                        $label = $currentLanguage === 'en' ? 'Historical notice imported' : 'Aviso histórico importado';
                        $notice = trim((string) ($payload['notice_number'] ?? ''));
                        $sourcePdf = trim((string) ($payload['source_pdf_name'] ?? ''));
                        $parts = [];
                        if ($notice !== '') {
                            $parts[] = $notice;
                        }
                        if ($sourcePdf !== '') {
                            $parts[] = $sourcePdf;
                        }
                        $detailText = implode(' · ', $parts);
                    } elseif ($entityType === 'desembarque_aviso') {
                        $after = is_array($payload['after'] ?? null) ? $payload['after'] : [];
                        $label = $currentLanguage === 'en' ? 'Notice data updated' : 'Datos del aviso actualizados';
                        $parts = [];
                        if (isset($after['items_count'])) {
                            $updatedItemCount = (int) $after['items_count'];
                            $parts[] = $updatedItemCount . ($currentLanguage === 'en' ? ($updatedItemCount === 1 ? ' merchandise line' : ' merchandise lines') : ' mercancías');
                        }
                        if (isset($after['photos_count'])) {
                            $parts[] = (int) $after['photos_count'] . ($currentLanguage === 'en' ? ' photos' : ' fotos');
                        }
                        $detailText = implode(' · ', $parts);
                    } elseif ($entityType === 'desembarques.status') {
                        $label = $currentLanguage === 'en' ? 'Operational status changed' : 'Estado operativo actualizado';
                        $previousId = (int) ($payload['previous_status_id'] ?? 0);
                        $newId = (int) ($payload['new_status_id'] ?? 0);
                        $previousLabel = $statusMap[$previousId] ?? ($previousId > 0 ? '#' . $previousId : '—');
                        $newLabel = $statusMap[$newId] ?? ($newId > 0 ? '#' . $newId : '—');
                        $detailText = $previousLabel . ' → ' . $newLabel;
                    } elseif ($entityType === 'desembarque') {
                        $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
                        $added = is_array($attachments['added'] ?? null) ? $attachments['added'] : [];
                        $removed = is_array($attachments['removed'] ?? null) ? $attachments['removed'] : [];
                        if ($action === 'soft_delete') {
                            $label = $currentLanguage === 'en' ? 'Notice administratively deleted' : 'Aviso eliminado administrativamente';
                            $detailText = trim((string) ($payload['reason'] ?? ''));
                        } elseif ($action === 'restore') {
                            $label = $currentLanguage === 'en' ? 'Notice restored' : 'Aviso restaurado';
                            $detailText = trim((string) ($payload['restore_reason'] ?? ''));
                        } elseif ($added !== [] || $removed !== []) {
                            $label = $currentLanguage === 'en' ? 'Case file documents changed' : 'Documentos del expediente actualizados';
                            $parts = [];
                            if ($added !== []) {
                                $parts[] = '+' . count($added);
                            }
                            if ($removed !== []) {
                                $parts[] = '-' . count($removed);
                            }
                            $detailText = implode(' · ', $parts);
                        } elseif ($action === 'create') {
                            $label = $currentLanguage === 'en' ? 'Unloading record created' : 'Desembarque registrado';
                        } else {
                            $label = $currentLanguage === 'en' ? 'Unloading record updated' : 'Desembarque actualizado';
                        }
                    } else {
                        $label = ucfirst(str_replace(['_', '.'], ' ', $entityType));
                    }

                    $row['timeline_label'] = $label;
                    $row['timeline_detail'] = $detailText;
                    $timeline[] = $row;
                }
            }

            $noticeStatusHistoryResult = $connection->execute_query(
                'SELECT h.*, u.name AS changed_by_name, v.version_no '
                . 'FROM desembarque_aviso_status_history h '
                . 'LEFT JOIN users u ON u.id = h.changed_by '
                . 'LEFT JOIN desembarque_aviso_versions v ON v.id = h.aviso_version_id '
                . 'WHERE h.desembarque_id = ? ORDER BY h.changed_at DESC, h.id DESC LIMIT 150',
                [$desembarqueId]
            );
            if ($noticeStatusHistoryResult instanceof mysqli_result) {
                while ($row = $noticeStatusHistoryResult->fetch_assoc()) {
                    $noticeStatusHistory[] = $row;
                    $fromMeta = aviso_status_meta((string) ($row['from_status'] ?? 'draft'), $currentLanguage);
                    $toMeta = aviso_status_meta((string) ($row['to_status'] ?? 'draft'), $currentLanguage);
                    $parts = [$fromMeta['label'] . ' → ' . $toMeta['label']];
                    $versionNo = (int) ($row['version_no'] ?? 0);
                    if ($versionNo > 0) {
                        $parts[] = 'v' . str_pad((string) $versionNo, 3, '0', STR_PAD_LEFT);
                    }
                    $reason = trim((string) ($row['reason'] ?? ''));
                    if ($reason !== '' && (string) ($row['source'] ?? '') !== 'migration') {
                        $parts[] = $reason;
                    }
                    $timeline[] = [
                        'id' => 'status-' . (int) ($row['id'] ?? 0),
                        'user_id' => $row['changed_by'] ?? null,
                        'action' => 'status',
                        'entity_type' => 'aviso_status_history',
                        'entity_id' => (string) $desembarqueId,
                        'payload' => null,
                        'created_at' => (string) ($row['changed_at'] ?? ''),
                        'user_name' => (string) ($row['changed_by_name'] ?? ''),
                        'timeline_label' => $currentLanguage === 'en' ? 'Notice status changed' : 'Estado documental del aviso actualizado',
                        'timeline_detail' => implode(' · ', $parts),
                    ];
                }
            }

            usort($timeline, static function (array $left, array $right): int {
                $leftTime = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
                $rightTime = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
                return $rightTime <=> $leftTime;
            });
            if (count($timeline) > 150) {
                $timeline = array_slice($timeline, 0, 150);
            }
        }
    } catch (Throwable $exception) {
        error_log('[aviso-expediente] ' . $exception->getMessage());
        http_response_code(500);
        $pageError = $currentLanguage === 'en'
            ? 'The notice case file could not be loaded.'
            : 'No fue posible cargar el expediente del aviso.';
    }
}

$themePreference = getUserThemePreference($user);
$currentTheme = $themePreference['theme'];
$themePreferenceKey = $themePreference['cookie_key'];
$themeLabel = translate('common.theme.label', [], $currentLanguage);
$themeLightLabel = translate('common.theme.light', [], $currentLanguage);
$themeDarkLabel = translate('common.theme.dark', [], $currentLanguage);
$currentThemeLabel = $currentTheme === 'dark' ? $themeDarkLabel : $themeLightLabel;
$themeToggleAnnouncement = trim($themeLabel) !== '' ? $themeLabel . ': ' . $currentThemeLabel : $currentThemeLabel;
$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;
$canManageUsers = $userRole === 'admin';
$isInternalUser = in_array($userRole, ['admin', 'usuario'], true);
$canEdit = $isInternalUser && ! $isDeletedView;
$navLinks = [
    ['href' => 'reportes.php', 'label' => $currentLanguage === 'en' ? 'Back to reports' : 'Volver a reportes', 'class' => 'btn btn-outline-primary btn-sm'],
    ['href' => 'index.php', 'label' => translate('reports.nav.dashboard', [], $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm', 'visible' => $isInternalUser],
    ['href' => 'client-portal.php', 'label' => translate('dashboard.nav.client_portal', [], $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm', 'visible' => $userRole === 'cliente'],
    ['href' => 'analytics.php', 'label' => translate('dashboard.nav.analytics', [], $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm', 'visible' => $isInternalUser],
    ['href' => 'control_tower.php', 'label' => translate('dashboard.nav.control_tower', [], $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm', 'visible' => $isInternalUser],
    ['href' => 'audit_logs.php', 'label' => translate('dashboard.nav.audit_logs', [], $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm', 'visible' => $canManageUsers],
    ['href' => 'myprofile.php', 'label' => translate('dashboard.nav.profile', [], $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm'],
    ['href' => 'logout.php', 'label' => translate('reports.nav.logout', [], $currentLanguage), 'class' => 'btn btn-outline-danger btn-sm'],
];

$statusName = '—';
if (is_array($record)) {
    $statusName = $currentLanguage === 'en'
        ? ((string) ($record['status_name_en'] ?? '') ?: (string) ($record['status_name_es'] ?? '') ?: (string) ($record['status_slug'] ?? '—'))
        : ((string) ($record['status_name_es'] ?? '') ?: (string) ($record['status_name_en'] ?? '') ?: (string) ($record['status_slug'] ?? '—'));
}

$noticeStatusSlug = aviso_status_normalize($detail['aviso_status'] ?? 'draft');
$noticeStatusMeta = aviso_status_meta($noticeStatusSlug, $currentLanguage);
$noticeStatusTransitions = $canEdit ? aviso_status_manual_transitions($noticeStatusSlug, $userRole) : [];
$canGenerateNotice = aviso_status_can_generate($noticeStatusSlug);
$noticeStatusEffectiveAt = trim((string) ($detail['aviso_status_effective_at'] ?? ''));
$noticeStatusChangedAt = trim((string) ($detail['aviso_status_changed_at'] ?? ''));
$noticeStatusChangedBy = trim((string) ($detail['aviso_status_changed_by_name'] ?? ''));

$noticeNumber = trim((string) ($detail['notice_number'] ?? ''));
if ($noticeNumber === '') {
    $noticeNumber = trim((string) ($detail['manifiesto'] ?? ($record['manifiesto'] ?? '')));
}
$documentCode = trim((string) ($detail['document_code'] ?? ''));
if ($documentCode === '' && $noticeNumber !== '') {
    $documentCode = 'MADE-' . $noticeNumber;
}
$deletedQueryHtml = $isDeletedView ? '&amp;deleted=1' : '';
$deletedQuerySuffix = $isDeletedView ? '&deleted=1' : '';
$clientLabel = trim((string) ($record['client_name'] ?? '')) ?: trim((string) ($record['cliente'] ?? '')) ?: '—';
$rigName = trim((string) ($detail['rig_name'] ?? '')) ?: trim((string) ($profile['rig_name'] ?? '')) ?: '—';
$rigImo = trim((string) ($detail['rig_imo'] ?? '')) ?: trim((string) ($profile['rig_imo'] ?? ''));
$rigField = trim((string) ($detail['rig_field'] ?? '')) ?: trim((string) ($profile['rig_field'] ?? ''));
$profileName = trim((string) ($profile['name'] ?? ''));

$pedimentoKeys = [];
foreach ($items as $item) {
    foreach (expediente_item_pedimentos($item) as $relation) {
        $key = trim((string) ($relation['key'] ?? '')) . '|' . trim((string) ($relation['number'] ?? ''));
        if ($key !== '|') {
            $pedimentoKeys[$key] = true;
        }
    }
}
$pedimentoCount = $pedimentoHeaders !== [] ? count($pedimentoHeaders) : count($pedimentoKeys);
// La pestaña Documentos es exclusivamente documental: PDF y Word.
// Fotografías viven en Fotos y el Excel fuente es un insumo privado del sistema.
$documentFiles = array_values(array_filter(
    $files,
    static function (array $file): bool {
        $purpose = (string) ($file['purpose'] ?? '');
        if (($file['is_photo'] ?? false) || ($file['is_source_excel'] ?? false) || in_array($purpose, ['source_excel', 'alcance_receipt'], true)) {
            return false;
        }

        $extension = strtolower(trim((string) ($file['extension'] ?? '')));
        return in_array($extension, ['pdf', 'doc', 'docx'], true);
    }
));

usort($documentFiles, static function (array $left, array $right): int {
    $leftActive = (int) ($left['is_active'] ?? 1);
    $rightActive = (int) ($right['is_active'] ?? 1);
    if ($leftActive !== $rightActive) {
        return $rightActive <=> $leftActive;
    }

    $leftDate = (string) ($left['document_date'] ?? $left['created_at'] ?? '');
    $rightDate = (string) ($right['document_date'] ?? $right['created_at'] ?? '');
    if ($leftDate !== $rightDate) {
        return strcmp($rightDate, $leftDate);
    }

    return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
});

$documentTypes = expediente_document_type_catalog($currentLanguage);
$replacementByOldId = [];
foreach ($documentFiles as $documentFile) {
    $replacesId = (int) ($documentFile['replaces_file_id'] ?? 0);
    if ($replacesId > 0) {
        $replacementByOldId[$replacesId] = $documentFile;
    }
}
$documentCount = count(array_filter($documentFiles, static fn (array $file): bool => (int) ($file['is_active'] ?? 1) === 1));
$replacedDocumentCount = count($documentFiles) - $documentCount;
$latestVersion = $versions[0] ?? null;
$isHistoricalNotice = strtolower(trim((string) ($detail['source_type'] ?? ''))) === 'historical_import';
$noticeOriginKey = $isHistoricalNotice ? 'historical' : 'system';
$noticeOriginLabel = $isHistoricalNotice
    ? ($currentLanguage === 'en' ? 'HISTORICAL' : 'HISTÓRICO')
    : ($currentLanguage === 'en' ? 'SYSTEM' : 'SISTEMA');
$noticeOriginDescription = $isHistoricalNotice
    ? ($currentLanguage === 'en'
        ? 'Imported from an original notice created before this system. The original historical PDF is preserved as immutable evidence.'
        : 'Importado desde un aviso original elaborado antes de este sistema. El PDF histórico original se conserva como evidencia inmutable.')
    : ($currentLanguage === 'en'
        ? 'Created and managed directly in Desembarques. Issued PDFs are generated and versioned by the system.'
        : 'Creado y gestionado directamente en Desembarques. Los PDFs emitidos son generados y versionados por el sistema.');
$originalHistoricalVersion = null;
$historicalVersionCount = 0;
$systemGeneratedVersionCount = 0;
foreach ($versions as $candidateVersion) {
    $candidateSource = strtolower(trim((string) ($candidateVersion['source_type'] ?? 'system_generated')));
    if ($candidateSource === 'historical_import') {
        $historicalVersionCount++;
        if ($originalHistoricalVersion === null
            || (int) ($candidateVersion['version_no'] ?? PHP_INT_MAX) < (int) ($originalHistoricalVersion['version_no'] ?? PHP_INT_MAX)) {
            $originalHistoricalVersion = $candidateVersion;
        }
    } else {
        $systemGeneratedVersionCount++;
    }
}
$originReferenceDate = $isHistoricalNotice
    ? (string) ($detail['imported_at'] ?? ($originalHistoricalVersion['generated_at'] ?? ''))
    : (string) ($record['created_at'] ?? '');
$pieceCount = 0.0;
foreach ($items as $item) {
    if (is_numeric($item['cantidad'] ?? null)) {
        $pieceCount += (float) $item['cantidad'];
    }
}
$pieceCount = round($pieceCount, 3);
$pieceCountLabel = rtrim(rtrim(number_format($pieceCount, 3, '.', ','), '0'), '.');
if ($pieceCountLabel === '') {
    $pieceCountLabel = '0';
}

$finalizedPieceCount = 0.0;
$remainingPieceCount = 0.0;
$storedItemCount = 0;
$partialItemCount = 0;
$exportedItemCount = 0;
foreach ($items as &$item) {
    $itemId = (int) ($item['id'] ?? 0);
    $originalQuantity = round(max(0, (float) ($item['cantidad'] ?? 0)), 3);
    $finalizedQuantity = round(max(0, (float) ($itemFinalizedQty[$itemId] ?? 0)), 3);
    $remainingQuantity = round(max(0, $originalQuantity - $finalizedQuantity), 3);

    if ($finalizedQuantity <= 0.0005) {
        $storageStatus = 'stored';
        $storedItemCount++;
    } elseif ($remainingQuantity > 0.0005) {
        $storageStatus = 'partial';
        $partialItemCount++;
    } else {
        $storageStatus = 'exported';
        $exportedItemCount++;
    }

    $item['_finalized_quantity'] = $finalizedQuantity;
    $item['_remaining_quantity'] = $remainingQuantity;
    $item['_storage_status'] = $storageStatus;
    $item['_movement_count'] = (int) ($itemMovementCount[$itemId] ?? 0);
    $finalizedPieceCount += min($finalizedQuantity, $originalQuantity);
    $remainingPieceCount += $remainingQuantity;
}
unset($item);
$finalizedPieceCount = round($finalizedPieceCount, 3);
$remainingPieceCount = round($remainingPieceCount, 3);
$remainingItemCount = $storedItemCount + $partialItemCount;
$canFinalizeMerchandise = $canEdit && $remainingItemCount > 0;
$merchandiseFinalizedFlash = isset($_GET['finalized']) && (string) $_GET['finalized'] === '1';
$merchandiseVoidedFlash = isset($_GET['movement_voided']) && (string) $_GET['movement_voided'] === '1';

?><!DOCTYPE html>
<html lang="<?= expediente_h($currentLanguage) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= expediente_h(($currentLanguage === 'en' ? 'Unloading Notice case file' : 'Expediente del aviso') . ($noticeNumber !== '' ? ' · ' . $noticeNumber : '')) ?></title>
    <?php require __DIR__ . '/partials/styles.php'; ?>
    <link rel="stylesheet" href="assets/css/aviso-expediente.css">
</head>
<body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
    <?php require __DIR__ . '/partials/nav.php'; ?>
    <main class="container py-4 py-lg-5 aviso-expediente-page">
        <?php if ($pageError !== ''): ?>
            <div class="alert alert-danger shadow-sm" role="alert">
                <h1 class="h5 mb-2"><?= expediente_h($currentLanguage === 'en' ? 'Notice case file unavailable' : 'Expediente no disponible') ?></h1>
                <p class="mb-3"><?= expediente_h($pageError) ?></p>
                <a class="btn btn-outline-danger btn-sm" href="reportes.php"><?= expediente_h($currentLanguage === 'en' ? 'Back to reports' : 'Volver a reportes') ?></a>
            </div>
        <?php else: ?>
            <?php if ($isDeletedView): ?>
                <div class="alert alert-danger shadow-sm d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center" role="alert">
                    <div>
                        <strong><?= expediente_h($currentLanguage === 'en' ? 'ADMINISTRATIVELY DELETED NOTICE' : 'AVISO ELIMINADO ADMINISTRATIVAMENTE') ?></strong>
                        <div class="small mt-1"><?= expediente_h(($currentLanguage === 'en' ? 'Deleted: ' : 'Eliminado: ') . expediente_format_date($record['deleted_at'] ?? null, true)) ?> · <?= expediente_h((string) ($record['delete_reason'] ?? '')) ?></div>
                        <div class="small"><?= expediente_h($currentLanguage === 'en' ? 'Read-only view. All child records remain preserved.' : 'Vista de sólo lectura. Todos los registros hijos permanecen conservados.') ?></div>
                    </div>
                    <a class="btn btn-outline-light" href="aviso-papelera.php?focus=<?= (int) $desembarqueId ?>"><?= expediente_h($currentLanguage === 'en' ? 'Back to deleted notices' : 'Volver a Papelera') ?></a>
                </div>
            <?php endif; ?>
            <section class="aviso-expediente-hero mb-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between gap-4 align-items-xl-start">
                    <div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="aviso-kicker"><?= expediente_h($currentLanguage === 'en' ? 'UNLOADING NOTICE CASE FILE' : 'EXPEDIENTE DE AVISO DE DESEMBARQUE') ?></span>
                            <span class="badge <?= expediente_h($noticeStatusMeta['badge']) ?>"><?= expediente_h($noticeStatusMeta['label']) ?></span>
                            <span class="badge <?= $isHistoricalNotice ? 'text-bg-dark' : 'text-bg-primary' ?>"><?= expediente_h($noticeOriginLabel) ?></span>
                            <span class="badge text-bg-light border text-secondary"><?= expediente_h(($currentLanguage === 'en' ? 'Operational: ' : 'Operativo: ') . $statusName) ?></span>
                        </div>
                        <h1 class="display-6 fw-semibold mb-2"><?= expediente_h($noticeNumber !== '' ? $noticeNumber : ($record['referencia'] ?? 'Aviso')) ?></h1>
                        <p class="lead mb-2"><?= expediente_h($clientLabel) ?> · <?= expediente_h($rigName) ?><?= $rigField !== '' ? ' · ' . expediente_h($rigField) : '' ?></p>
                        <div class="d-flex flex-wrap gap-2 text-muted small">
                            <?php if ($documentCode !== ''): ?><span><?= expediente_h($documentCode) ?></span><?php endif; ?>
                            <?php if ($profileName !== ''): ?><span>· <?= expediente_h($profileName) ?></span><?php endif; ?>
                            <span>· <?= expediente_h((string) ($record['referencia'] ?? '')) ?></span>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($latestVersion): ?>
                            <a class="btn btn-primary" href="../api/desembarques/aviso/version_download.php?id=<?= expediente_h((string) $latestVersion['id']) ?><?= $deletedQueryHtml ?>">
                                <?= expediente_h($currentLanguage === 'en' ? 'Download latest PDF' : 'Descargar último PDF') ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($canEdit && $canGenerateNotice): ?>
                            <a class="btn btn-outline-secondary" href="reportes.php?open_aviso=<?= expediente_h((string) $desembarqueId) ?>#report-results">
                                <?= expediente_h($currentLanguage === 'en' ? 'Generate new version' : 'Generar nueva versión') ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($canEdit && $noticeStatusTransitions !== []): ?>
                            <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#avisoStatusModal">
                                <?= expediente_h($currentLanguage === 'en' ? 'Change notice status' : 'Cambiar estado del aviso') ?>
                            </button>
                        <?php endif; ?>
                        <?php if ($userRole === 'admin' && ! $isDeletedView): ?>
                            <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#avisoSoftDeleteModal"><?= expediente_h($currentLanguage === 'en' ? 'Delete notice' : 'Eliminar aviso') ?></button>
                        <?php elseif ($userRole === 'admin' && $isDeletedView): ?>
                            <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#avisoRestoreModal"><?= expediente_h($currentLanguage === 'en' ? 'Restore notice' : 'Restaurar aviso') ?></button>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="row g-3 mb-4" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'File summary' : 'Resumen del expediente') ?>">
                <div class="col-6 col-lg"><div class="aviso-metric"><span><?= count($items) ?></span><small><?= expediente_h($currentLanguage === 'en' ? 'Merchandise lines' : 'Renglones') ?></small></div></div>
                <div class="col-6 col-lg"><div class="aviso-metric"><span><?= expediente_h($pieceCountLabel) ?></span><small><?= expediente_h($currentLanguage === 'en' ? 'Pieces' : 'Piezas') ?></small></div></div>
                <div class="col-6 col-lg"><div class="aviso-metric"><span><?= $pedimentoCount ?></span><small><?= expediente_h($currentLanguage === 'en' ? 'Customs entries' : 'Pedimentos') ?></small></div></div>
                <div class="col-6 col-lg"><div class="aviso-metric"><span><?= expediente_h($isHistoricalNotice && $photos === [] && $originalHistoricalVersion ? 'PDF' : (string) count($photos)) ?></span><small><?= expediente_h($isHistoricalNotice && $photos === [] && $originalHistoricalVersion ? ($currentLanguage === 'en' ? 'Historical photo annex' : 'Anexo fotográfico histórico') : ($currentLanguage === 'en' ? 'Photos' : 'Fotografías')) ?></small></div></div>
                <div class="col-6 col-lg"><div class="aviso-metric"><span><?= $documentCount ?></span><small><?= expediente_h($currentLanguage === 'en' ? 'Documents' : 'Documentos') ?></small></div></div>
                <div class="col-6 col-lg"><div class="aviso-metric"><span><?= count($alcances) ?></span><small><?= expediente_h($currentLanguage === 'en' ? 'Addenda' : 'Alcances') ?></small></div></div>
                <div class="col-6 col-lg"><div class="aviso-metric"><span><?= count($versions) ?></span><small><?= expediente_h($currentLanguage === 'en' ? 'Issued PDFs' : 'PDFs emitidos') ?></small></div></div>
            </section>

            <ul class="nav nav-pills aviso-expediente-tabs mb-4" id="expedienteTabs" role="tablist">
                <?php
                    $tabs = [
                        'resumen' => $currentLanguage === 'en' ? 'Summary' : 'Resumen',
                        'documentos' => $currentLanguage === 'en' ? 'Documents' : 'Documentos',
                        'mercancias' => $currentLanguage === 'en' ? 'Items' : 'Mercancías',
                        'fotos' => $currentLanguage === 'en' ? 'Photos' : 'Fotos',
                        'alcances' => $currentLanguage === 'en' ? 'Addenda' : 'Alcances',
                        'pdfs' => $currentLanguage === 'en' ? 'Issued PDFs' : 'PDFs emitidos',
                        'historial' => $currentLanguage === 'en' ? 'History' : 'Historial',
                    ];
                ?>
                <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?= $tabKey === $requestedTab ? ' active' : '' ?>" id="tab-<?= expediente_h($tabKey) ?>" data-bs-toggle="pill" data-bs-target="#panel-<?= expediente_h($tabKey) ?>" type="button" role="tab" aria-controls="panel-<?= expediente_h($tabKey) ?>" aria-selected="<?= $tabKey === $requestedTab ? 'true' : 'false' ?>">
                            <?= expediente_h($tabLabel) ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="tab-content" id="expedienteTabContent">
                <div class="tab-pane fade<?= $requestedTab === 'resumen' ? ' show active' : '' ?>" id="panel-resumen" role="tabpanel" aria-labelledby="tab-resumen" tabindex="0">
                    <div class="card shadow-sm expediente-card mb-3 aviso-origin-card <?= $isHistoricalNotice ? 'aviso-origin-historical' : 'aviso-origin-system' ?>">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                                <div>
                                    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                                        <h2 class="h5 mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Notice origin' : 'Origen del aviso') ?></h2>
                                        <span class="badge <?= $isHistoricalNotice ? 'text-bg-dark' : 'text-bg-primary' ?>"><?= expediente_h($noticeOriginLabel) ?></span>
                                    </div>
                                    <p class="small text-muted mb-0"><?= expediente_h($noticeOriginDescription) ?></p>
                                </div>
                                <div class="small text-muted text-lg-end flex-shrink-0">
                                    <?php if ($originReferenceDate !== ''): ?><div><?= expediente_h(($isHistoricalNotice ? ($currentLanguage === 'en' ? 'Imported: ' : 'Importado: ') : ($currentLanguage === 'en' ? 'Created: ' : 'Creado: ')) . expediente_format_date($originReferenceDate, true)) ?></div><?php endif; ?>
                                    <?php if ($isHistoricalNotice && $originalHistoricalVersion): ?>
                                        <div class="mt-2"><a class="btn btn-outline-dark btn-sm" href="../api/desembarques/aviso/version_download.php?id=<?= expediente_h((string) $originalHistoricalVersion['id']) ?><?= $deletedQueryHtml ?>"><?= expediente_h($currentLanguage === 'en' ? 'Download historical original' : 'Descargar original histórico') ?></a></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card shadow-sm expediente-card mb-3 aviso-status-card">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                                <div>
                                    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                                        <h2 class="h5 mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Notice document status' : 'Estado documental del aviso') ?></h2>
                                        <span class="badge <?= expediente_h($noticeStatusMeta['badge']) ?>"><?= expediente_h($noticeStatusMeta['label']) ?></span>
                                    </div>
                                    <p class="small text-muted mb-0"><?= expediente_h($currentLanguage === 'en' ? 'This status controls the notice lifecycle. Case file documents remain available in every state.' : 'Este estado controla el ciclo documental del aviso. Los documentos del expediente siguen disponibles en todos los estados.') ?></p>
                                </div>
                                <div class="small text-muted text-lg-end">
                                    <?php if ($noticeStatusEffectiveAt !== ''): ?><div><?= expediente_h(($currentLanguage === 'en' ? 'Effective: ' : 'Vigente desde: ') . expediente_format_date($noticeStatusEffectiveAt, true)) ?></div><?php endif; ?>
                                    <?php if ($noticeStatusChangedAt !== ''): ?><div><?= expediente_h(($currentLanguage === 'en' ? 'Last change: ' : 'Último cambio: ') . expediente_format_date($noticeStatusChangedAt, true)) ?></div><?php endif; ?>
                                    <?php if ($noticeStatusChangedBy !== ''): ?><div><?= expediente_h(($currentLanguage === 'en' ? 'By: ' : 'Por: ') . $noticeStatusChangedBy) ?></div><?php endif; ?>
                                </div>
                            </div>
                            <?php if (! $canGenerateNotice): ?>
                                <div class="alert alert-danger mt-3 mb-0 py-2 small"><?= expediente_h($currentLanguage === 'en' ? 'This notice is cancelled. Reopen it as Draft before issuing another PDF version. Document management remains enabled.' : 'Este aviso está cancelado. Debe reabrirse a Borrador antes de emitir otra versión PDF. La gestión documental permanece habilitada.') ?></div>
                            <?php elseif ($noticeStatusSlug === 'presented' || $noticeStatusSlug === 'replaced'): ?>
                                <div class="alert alert-info mt-3 mb-0 py-2 small"><?= expediente_h($currentLanguage === 'en' ? 'Issuing a new PDF version will automatically return the current notice status to Issued.' : 'Al emitir una nueva versión PDF, el estado actual del aviso volverá automáticamente a Emitido.') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row g-4">
                        <div class="col-xl-7">
                            <div class="card shadow-sm h-100 expediente-card">
                                <div class="card-body p-4">
                                    <h2 class="h5 mb-4"><?= expediente_h($currentLanguage === 'en' ? 'Operational data' : 'Datos operativos') ?></h2>
                                    <div class="row g-3 expediente-detail-grid">
                                        <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Manifest' : 'Manifiesto') ?></small><strong><?= expediente_h((string) ($detail['manifiesto'] ?? $record['manifiesto'] ?? '—')) ?></strong></div>
                                        <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Client' : 'Cliente') ?></small><strong><?= expediente_h($clientLabel) ?></strong></div>
                                        <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Transport' : 'Medio de transporte') ?></small><strong><?= expediente_h((string) ($detail['medio_transporte'] ?? $record['barco'] ?? '—')) ?></strong></div>
                                        <div class="col-md-6"><small>IMO <?= expediente_h($currentLanguage === 'en' ? 'transport' : 'transporte') ?></small><strong><?= expediente_h((string) ($detail['imo_transporte'] ?? '—')) ?></strong></div>
                                        <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Shipping date' : 'Fecha de embarque') ?></small><strong><?= expediente_h(expediente_format_date($detail['fecha_embarque'] ?? null)) ?></strong></div>
                                        <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Unloading / ETA' : 'Desembarque / ETA') ?></small><strong><?= expediente_h(expediente_format_date($detail['fecha_desembarque_eta'] ?? $record['fecha_desembarque'] ?? null, true)) ?></strong></div>
                                        <div class="col-12"><small><?= expediente_h($currentLanguage === 'en' ? 'Unloading location' : 'Lugar de desembarque') ?></small><strong><?= expediente_h((string) ($detail['lugar_desembarque'] ?? '—')) ?></strong></div>
                                        <div class="col-12"><small><?= expediente_h($currentLanguage === 'en' ? 'Storage address' : 'Domicilio de almacenamiento') ?></small><strong><?= expediente_h((string) ($detail['domicilio_almacenamiento'] ?? $record['destino'] ?? '—')) ?></strong></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-5">
                            <div class="card shadow-sm h-100 expediente-card">
                                <div class="card-body p-4">
                                    <h2 class="h5 mb-4"><?= expediente_h($currentLanguage === 'en' ? 'Rig / project' : 'Rig / proyecto') ?></h2>
                                    <div class="expediente-detail-grid d-grid gap-3">
                                        <div><small>Rig</small><strong><?= expediente_h($rigName) ?></strong></div>
                                        <div><small>IMO Rig</small><strong><?= expediente_h($rigImo !== '' ? $rigImo : '—') ?></strong></div>
                                        <div><small><?= expediente_h($currentLanguage === 'en' ? 'Field' : 'Campo') ?></small><strong><?= expediente_h($rigField !== '' ? $rigField : '—') ?></strong></div>
                                        <div><small><?= expediente_h($currentLanguage === 'en' ? 'Rig area' : 'Área del Rig') ?></small><strong><?= expediente_h((string) ($detail['rig_area'] ?? $profile['rig_area'] ?? '—')) ?></strong></div>
                                        <div><small><?= expediente_h($currentLanguage === 'en' ? 'Principal / client' : 'Comitente') ?></small><strong><?= expediente_h((string) ($detail['comitente'] ?? $profile['comitente'] ?? '—')) ?></strong></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade<?= $requestedTab === 'documentos' ? ' show active' : '' ?>" id="panel-documentos" role="tabpanel" aria-labelledby="tab-documentos" tabindex="0">
                    <div class="card shadow-sm expediente-card">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center mb-4">
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <h2 class="h5 mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Case file documents' : 'Documentos del expediente') ?></h2>
                                        <span class="badge text-bg-success"><?= $documentCount ?> <?= expediente_h($currentLanguage === 'en' ? 'current' : 'vigentes') ?></span>
                                        <?php if ($replacedDocumentCount > 0): ?>
                                            <span class="badge text-bg-secondary"><?= $replacedDocumentCount ?> <?= expediente_h($currentLanguage === 'en' ? 'replaced' : 'reemplazados') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted mb-1 small"><?= expediente_h($currentLanguage === 'en' ? 'PDF and Word documents only. Photos are managed separately in Photos.' : 'Solo documentos PDF y Word. Las imágenes se administran por separado en Fotos.') ?></p>
                                    <p class="text-muted mb-0 small fw-semibold"><?= expediente_h($currentLanguage === 'en' ? 'Documents can be added or replaced regardless of the notice status.' : 'Los documentos pueden añadirse o reemplazarse sin importar el estado del aviso.') ?></p>
                                </div>
                                <?php if ($canEdit): ?>
                                    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#documentUploadModal">+ <?= expediente_h($currentLanguage === 'en' ? 'Add documents' : 'Añadir documentos') ?></button>
                                <?php endif; ?>
                            </div>

                            <?php if (($_GET['doc_action'] ?? '') === 'uploaded'): ?>
                                <div class="alert alert-success py-2" role="status"><?= expediente_h($currentLanguage === 'en' ? 'The documents were added to the case file.' : 'Los documentos se añadieron correctamente al expediente.') ?></div>
                            <?php elseif (($_GET['doc_action'] ?? '') === 'replaced'): ?>
                                <div class="alert alert-success py-2" role="status"><?= expediente_h($currentLanguage === 'en' ? 'The document was replaced and the previous version remains in history.' : 'El documento fue reemplazado y la versión anterior permanece en el historial.') ?></div>
                            <?php endif; ?>

                            <?php if ($documentFiles === []): ?>
                                <div class="expediente-empty">
                                    <div class="fw-semibold mb-2"><?= expediente_h($currentLanguage === 'en' ? 'There are no documents in this file yet.' : 'Todavía no hay documentos en este expediente.') ?></div>
                                    <div class="small"><?= expediente_h($currentLanguage === 'en' ? 'Add PDF, DOC or DOCX files when they become available.' : 'Agrega archivos PDF, DOC o DOCX conforme estén disponibles.') ?></div>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 expediente-table expediente-documents-table">
                                        <thead>
                                            <tr>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Type' : 'Tipo') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Document' : 'Documento') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Status' : 'Estado') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Document date' : 'Fecha documento') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Uploaded' : 'Carga') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Integrity' : 'Integridad') ?></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($documentFiles as $file): ?>
                                            <?php
                                                $fileId = (int) ($file['id'] ?? 0);
                                                $extension = strtolower((string) ($file['extension'] ?? ''));
                                                $formatLabel = $extension === 'pdf' ? 'PDF' : 'Word';
                                                $formatBadge = $extension === 'pdf' ? 'text-bg-danger' : 'text-bg-primary';
                                                $documentTypeKey = trim((string) ($file['document_type'] ?? '')) ?: 'other';
                                                $documentTypeLabel = $documentTypes[$documentTypeKey] ?? ($documentTypes['other'] ?? 'Otro');
                                                $isActiveDocument = (int) ($file['is_active'] ?? 1) === 1;
                                                $replacement = $replacementByOldId[$fileId] ?? null;
                                                $sha256 = strtolower(trim((string) ($file['sha256'] ?? '')));
                                                $hasHash = preg_match('/^[a-f0-9]{64}$/', $sha256) === 1;
                                                $documentPayload = [
                                                    'id' => $fileId,
                                                    'name' => (string) ($file['original_name'] ?? 'archivo'),
                                                    'extension' => $extension,
                                                    'format' => $formatLabel,
                                                    'mime_type' => (string) ($file['mime_type'] ?? ''),
                                                    'size' => (int) ($file['size'] ?? 0),
                                                    'size_label' => expediente_format_bytes((int) ($file['size'] ?? 0)),
                                                    'document_type' => $documentTypeKey,
                                                    'document_type_label' => $documentTypeLabel,
                                                    'description' => (string) ($file['description'] ?? ''),
                                                    'document_date' => (string) ($file['document_date'] ?? ''),
                                                    'created_at' => expediente_format_date($file['created_at'] ?? null, true),
                                                    'uploaded_by' => (string) ($file['uploaded_by_name'] ?? '—'),
                                                    'sha256' => $sha256,
                                                    'is_active' => $isActiveDocument,
                                                    'replaces_file_id' => isset($file['replaces_file_id']) ? (int) $file['replaces_file_id'] : null,
                                                    'replacement_name' => is_array($replacement) ? (string) ($replacement['original_name'] ?? '') : '',
                                                    'replaced_at' => expediente_format_date($file['replaced_at'] ?? null, true),
                                                ];
                                                $documentPayloadJson = json_encode($documentPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                            ?>
                                            <tr class="<?= $isActiveDocument ? '' : 'expediente-document-replaced' ?>">
                                                <td>
                                                    <div class="d-flex flex-column align-items-start gap-1">
                                                        <span class="badge text-bg-light border text-dark"><?= expediente_h($documentTypeLabel) ?></span>
                                                        <span class="badge <?= expediente_h($formatBadge) ?>"><?= expediente_h($formatLabel) ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-break"><?= expediente_h((string) ($file['original_name'] ?? 'archivo')) ?></div>
                                                    <?php if (trim((string) ($file['description'] ?? '')) !== ''): ?>
                                                        <div class="small text-muted expediente-document-description"><?= expediente_h((string) $file['description']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (is_array($replacement)): ?>
                                                        <div class="small text-muted mt-1"><?= expediente_h($currentLanguage === 'en' ? 'Replaced by:' : 'Reemplazado por:') ?> <strong><?= expediente_h((string) ($replacement['original_name'] ?? '')) ?></strong></div>
                                                    <?php elseif ((int) ($file['replaces_file_id'] ?? 0) > 0): ?>
                                                        <div class="small text-muted mt-1"><?= expediente_h($currentLanguage === 'en' ? 'Replaces previous document #' : 'Reemplaza documento anterior #') ?><?= (int) $file['replaces_file_id'] ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($isActiveDocument): ?>
                                                        <span class="badge text-bg-success"><?= expediente_h($currentLanguage === 'en' ? 'CURRENT' : 'VIGENTE') ?></span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-secondary"><?= expediente_h($currentLanguage === 'en' ? 'REPLACED' : 'REEMPLAZADO') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= expediente_h(expediente_format_date($file['document_date'] ?? null)) ?></td>
                                                <td>
                                                    <div><?= expediente_h(expediente_format_date($file['created_at'] ?? null, true)) ?></div>
                                                    <div class="small text-muted"><?= expediente_h((string) ($file['uploaded_by_name'] ?? '—')) ?></div>
                                                </td>
                                                <td>
                                                    <?php if ($hasHash): ?>
                                                        <span class="badge text-bg-light border text-success"><?= expediente_h($currentLanguage === 'en' ? 'SHA-256 protected' : 'SHA-256 protegido') ?></span>
                                                        <code class="d-block small mt-1 expediente-hash-short"><?= expediente_h(substr($sha256, 0, 12)) ?>…</code>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-warning"><?= expediente_h($currentLanguage === 'en' ? 'Legacy / no baseline' : 'Legacy / sin huella') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                                        <a class="btn btn-outline-secondary btn-sm" href="../api/desembarques/files/download.php?id=<?= $fileId ?><?= $deletedQueryHtml ?>"><?= expediente_h($currentLanguage === 'en' ? 'Download' : 'Descargar') ?></a>
                                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-document-details="<?= expediente_h((string) $documentPayloadJson) ?>"><?= expediente_h($currentLanguage === 'en' ? 'Details' : 'Detalles') ?></button>
                                                        <?php if ($canEdit && $isActiveDocument): ?>
                                                            <button class="btn btn-outline-primary btn-sm" type="button" data-document-replace="<?= expediente_h((string) $documentPayloadJson) ?>"><?= expediente_h($currentLanguage === 'en' ? 'Replace' : 'Reemplazar') ?></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade<?= $requestedTab === 'mercancias' ? ' show active' : '' ?>" id="panel-mercancias" role="tabpanel" aria-labelledby="tab-mercancias" tabindex="0">
                    <div class="card shadow-sm expediente-card">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <h2 class="h5 mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Structured merchandise' : 'Mercancías estructuradas') ?> <span class="badge text-bg-light border text-dark ms-1"><?= count($items) ?></span></h2>
                                    </div>
                                    <p class="small text-muted mb-2"><?= expediente_h($currentLanguage === 'en' ? 'Merchandise always remains visible in the notice. Departure records only indicate what is still stored, partially released, or already exported.' : 'La mercancía siempre permanece visible en el aviso. Las finalizaciones únicamente indican qué sigue almacenado, qué salió parcialmente y qué ya fue exportado.') ?></p>
                                    <?php if ($items !== []): ?>
                                        <div class="d-flex flex-wrap gap-2 merchandise-status-summary">
                                            <span class="badge text-bg-secondary"><?= $storedItemCount ?> <?= expediente_h($currentLanguage === 'en' ? 'stored' : 'almacenadas') ?></span>
                                            <span class="badge text-bg-warning"><?= $partialItemCount ?> <?= expediente_h($currentLanguage === 'en' ? 'partial' : 'parciales') ?></span>
                                            <span class="badge text-bg-success"><?= $exportedItemCount ?> <?= expediente_h($currentLanguage === 'en' ? 'exported' : 'exportadas') ?></span>
                                            <span class="badge text-bg-light border text-dark"><?= expediente_h(expediente_format_quantity($remainingPieceCount)) ?> <?= expediente_h($currentLanguage === 'en' ? 'pieces in storage' : 'piezas en almacén') ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                                    <?php if ($canEdit && $items !== [] && $noticeStatusSlug !== 'cancelled'): ?>
                                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#alcanceGenerateModal">
                                            <?= expediente_h($currentLanguage === 'en' ? 'Generate addendum' : 'Generar alcance') ?>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canFinalizeMerchandise): ?>
                                        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#merchandiseFinalizeModal">
                                            <?= expediente_h($currentLanguage === 'en' ? 'Finalize' : 'Finalizar') ?>
                                        </button>
                                    <?php elseif ($canEdit && $items !== []): ?>
                                        <span class="badge text-bg-success align-self-center p-2"><?= expediente_h($currentLanguage === 'en' ? 'All merchandise released' : 'Toda la mercancía finalizada') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($merchandiseFinalizedFlash): ?>
                                <div class="alert alert-success py-2 small" role="status"><?= expediente_h($currentLanguage === 'en' ? 'The merchandise departure was recorded successfully.' : 'La salida de mercancía se registró correctamente.') ?></div>
                            <?php elseif ($merchandiseVoidedFlash): ?>
                                <div class="alert alert-warning py-2 small" role="status"><?= expediente_h($currentLanguage === 'en' ? 'The movement was voided and its quantities returned to the available storage balance.' : 'El movimiento fue anulado y sus cantidades regresaron al saldo disponible en almacén.') ?></div>
                            <?php endif; ?>

                            <?php if ($items === []): ?>
                                <div class="expediente-empty"><?= expediente_h($currentLanguage === 'en' ? 'No structured merchandise is available.' : 'No hay mercancías estructuradas disponibles.') ?></div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle expediente-table merchandise-lifecycle-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Description' : 'Descripción') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Serial / brand' : 'Serie / marca') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Customs entry' : 'Pedimento') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Importer' : 'Importador') ?></th>
                                                <th class="text-end"><?= expediente_h($currentLanguage === 'en' ? 'Original' : 'Original') ?></th>
                                                <th class="text-end"><?= expediente_h($currentLanguage === 'en' ? 'Exported' : 'Salida') ?></th>
                                                <th class="text-end"><?= expediente_h($currentLanguage === 'en' ? 'Stored' : 'En almacén') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'Status' : 'Estado') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($items as $index => $item): ?>
                                            <?php
                                                $storageStatus = (string) ($item['_storage_status'] ?? 'stored');
                                                $statusLabel = $storageStatus === 'exported'
                                                    ? ($currentLanguage === 'en' ? 'EXPORTED' : 'EXPORTADA')
                                                    : ($storageStatus === 'partial'
                                                        ? ($currentLanguage === 'en' ? 'PARTIAL' : 'PARCIAL')
                                                        : ($currentLanguage === 'en' ? 'STORED' : 'ALMACENADA'));
                                                $statusBadge = $storageStatus === 'exported' ? 'text-bg-success' : ($storageStatus === 'partial' ? 'text-bg-warning' : 'text-bg-secondary');
                                            ?>
                                            <tr class="<?= $storageStatus === 'exported' ? 'merchandise-row-exported' : ($storageStatus === 'partial' ? 'merchandise-row-partial' : '') ?>">
                                                <td><?= $index + 1 ?></td>
                                                <td><div class="fw-semibold"><?= expediente_h((string) ($item['descripcion'] ?? '')) ?></div><div class="small text-muted"><?= expediente_h((string) ($item['unidad'] ?? '')) ?><?= trim((string) ($item['partida'] ?? '')) !== '' ? ' · ' . expediente_h($currentLanguage === 'en' ? 'Line ' : 'Partida ') . expediente_h((string) $item['partida']) : '' ?></div></td>
                                                <td><div><?= expediente_h((string) ($item['serial_number'] ?? '—')) ?></div><div class="small text-muted"><?= expediente_h((string) ($item['marca'] ?? '')) ?></div></td>
                                                <td><?= expediente_render_item_pedimentos($item) ?></td>
                                                <td><?= expediente_h((string) ($item['importer_name'] ?? '—')) ?></td>
                                                <td class="text-end fw-semibold"><?= expediente_h(expediente_format_quantity((float) ($item['cantidad'] ?? 0))) ?></td>
                                                <td class="text-end"><?= expediente_h(expediente_format_quantity((float) ($item['_finalized_quantity'] ?? 0))) ?></td>
                                                <td class="text-end fw-semibold"><?= expediente_h(expediente_format_quantity((float) ($item['_remaining_quantity'] ?? 0))) ?></td>
                                                <td>
                                                    <span class="badge <?= expediente_h($statusBadge) ?>"><?= expediente_h($statusLabel) ?></span>
                                                    <?php if ((int) ($item['_movement_count'] ?? 0) > 0): ?>
                                                        <div class="small text-muted mt-1"><?= (int) $item['_movement_count'] ?> <?= expediente_h($currentLanguage === 'en' ? 'movements' : 'movimientos') ?></div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if ($itemFinalizations !== []): ?>
                                <section class="merchandise-finalization-history mt-5" aria-labelledby="finalization-history-title">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                        <div>
                                            <h3 class="h6 mb-1" id="finalization-history-title"><?= expediente_h($currentLanguage === 'en' ? 'Merchandise departure history' : 'Historial de finalizaciones') ?></h3>
                                            <p class="small text-muted mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Each departure is preserved as a separate auditable event.' : 'Cada salida se conserva como un evento independiente y auditable.') ?></p>
                                        </div>
                                        <span class="badge text-bg-light border text-dark"><?= count($itemFinalizations) ?> <?= expediente_h($currentLanguage === 'en' ? 'events' : 'eventos') ?></span>
                                    </div>

                                    <div class="d-grid gap-3">
                                        <?php foreach ($itemFinalizations as $finalization): ?>
                                            <?php
                                                $eventLines = is_array($finalization['lines'] ?? null) ? $finalization['lines'] : [];
                                                $eventPieces = 0.0;
                                                foreach ($eventLines as $eventLine) {
                                                    $eventPieces += (float) ($eventLine['quantity_exported'] ?? 0);
                                                }
                                            ?>
                                            <?php
                                                $isVoidedFinalization = trim((string) ($finalization['voided_at'] ?? '')) !== '';
                                                $closureStatus = (string) ($finalization['closure_status'] ?? 'complete');
                                                $isPartialClosure = $closureStatus === 'partial';
                                            ?>
                                            <article class="merchandise-finalization-event<?= $isVoidedFinalization ? ' merchandise-finalization-event-voided' : '' ?>">
                                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                                                    <div>
                                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                                            <div class="fw-semibold"><?= expediente_h(($currentLanguage === 'en' ? 'Departure date: ' : 'Fecha de salida: ') . expediente_format_date((string) ($finalization['export_date'] ?? ''), false)) ?></div>
                                                            <?php if ($isVoidedFinalization): ?>
                                                                <span class="badge text-bg-danger"><?= expediente_h($currentLanguage === 'en' ? 'VOIDED' : 'ANULADA') ?></span>
                                                            <?php endif; ?>
                                                            <span class="badge <?= $isPartialClosure ? 'text-bg-warning' : 'text-bg-primary' ?>">
                                                                <?= expediente_h($isPartialClosure
                                                                    ? ($currentLanguage === 'en' ? 'PARTIAL CLOSURE' : 'CIERRE PARCIAL')
                                                                    : ($currentLanguage === 'en' ? 'COMPLETE CLOSURE' : 'CIERRE COMPLETO')) ?>
                                                            </span>
                                                        </div>
                                                        <div class="small text-muted"><?= expediente_h((string) ($finalization['created_by_name'] ?? '')) ?><?= trim((string) ($finalization['created_at'] ?? '')) !== '' ? ' · ' . expediente_h(expediente_format_date((string) $finalization['created_at'], true)) : '' ?></div>
                                                        <?php if ($isPartialClosure && ! $isVoidedFinalization): ?>
                                                            <div class="small text-warning-emphasis mt-1">
                                                                <?= expediente_h($currentLanguage === 'en'
                                                                    ? 'The quantity has already departed; only the pending customs documentation remains to be completed.'
                                                                    : 'La cantidad ya salió; únicamente falta completar la documentación aduanal pendiente.') ?>
                                                            </div>
                                                        <?php elseif (! $isPartialClosure && trim((string) ($finalization['closure_completed_at'] ?? '')) !== ''): ?>
                                                            <div class="small text-muted mt-1">
                                                                <strong><?= expediente_h($currentLanguage === 'en' ? 'Documentation completed:' : 'Documentación completada:') ?></strong>
                                                                <?= expediente_h(expediente_format_date((string) $finalization['closure_completed_at'], true)) ?>
                                                                <?= trim((string) ($finalization['closure_completed_by_name'] ?? '')) !== '' ? ' · ' . expediente_h((string) $finalization['closure_completed_by_name']) : '' ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($isVoidedFinalization): ?>
                                                            <div class="small text-danger mt-1">
                                                                <strong><?= expediente_h($currentLanguage === 'en' ? 'Voided:' : 'Anulada:') ?></strong>
                                                                <?= expediente_h(expediente_format_date((string) ($finalization['voided_at'] ?? ''), true)) ?>
                                                                <?= trim((string) ($finalization['voided_by_name'] ?? '')) !== '' ? ' · ' . expediente_h((string) $finalization['voided_by_name']) : '' ?>
                                                                <?= trim((string) ($finalization['void_reason'] ?? '')) !== '' ? ' · ' . expediente_h((string) $finalization['void_reason']) : '' ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 align-items-start">
                                                        <?php $eventLineCount = count($eventLines); ?>
                                                        <span class="badge text-bg-light border text-dark"><?= $eventLineCount ?> <?= expediente_h($currentLanguage === 'en' ? ($eventLineCount === 1 ? 'merchandise line' : 'merchandise lines') : 'renglones') ?></span>
                                                        <span class="badge <?= $isVoidedFinalization ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= expediente_h(expediente_format_quantity($eventPieces)) ?> <?= expediente_h($currentLanguage === 'en' ? 'pieces' : 'piezas') ?></span>
                                                        <?php if ($canEdit && ! $isVoidedFinalization): ?>
                                                            <?php if ($isPartialClosure): ?>
                                                                <button
                                                                    class="btn btn-warning btn-sm"
                                                                    type="button"
                                                                    data-finalization-complete="<?= (int) ($finalization['id'] ?? 0) ?>"
                                                                    data-finalization-label="<?= expediente_h(($currentLanguage === 'en' ? 'Departure ' : 'Salida ') . '#' . (int) ($finalization['id'] ?? 0) . ' · ' . expediente_format_date((string) ($finalization['export_date'] ?? ''), false)) ?>"
                                                                ><?= expediente_h($currentLanguage === 'en' ? 'Complete customs documentation' : 'Completar pedimentos') ?></button>
                                                            <?php endif; ?>
                                                            <button
                                                                class="btn btn-outline-danger btn-sm"
                                                                type="button"
                                                                data-finalization-void="<?= (int) ($finalization['id'] ?? 0) ?>"
                                                                data-finalization-label="<?= expediente_h(($currentLanguage === 'en' ? 'Departure ' : 'Salida ') . '#' . (int) ($finalization['id'] ?? 0) . ' · ' . expediente_format_date((string) ($finalization['export_date'] ?? ''), false)) ?>"
                                                            ><?= expediente_h($currentLanguage === 'en' ? 'Void movement' : 'Anular movimiento') ?></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <div class="small fw-semibold mb-2"><?= expediente_h($currentLanguage === 'en' ? 'Merchandise and customs data in this departure' : 'Mercancía y datos aduanales de esta salida') ?></div>
                                                <div class="d-grid gap-3">
                                                    <?php foreach ($eventLines as $eventLine): ?>
                                                        <div class="merchandise-finalization-line-card">
                                                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                                                                <div class="flex-grow-1">
                                                                    <div class="fw-semibold"><?= expediente_h((string) ($eventLine['descripcion'] ?? '')) ?></div>
                                                                    <div class="small text-muted"><?= expediente_h((string) ($eventLine['serial_number'] ?? '')) ?><?= trim((string) ($eventLine['num_pedimento'] ?? '')) !== '' ? ' · ' . expediente_h((string) $eventLine['num_pedimento']) : '' ?></div>
                                                                </div>
                                                                <span class="badge <?= $isVoidedFinalization ? 'text-bg-secondary' : 'text-bg-success' ?> align-self-start"><?= expediente_h(expediente_format_quantity((float) ($eventLine['quantity_exported'] ?? 0))) ?> <?= expediente_h($currentLanguage === 'en' ? 'out' : 'salida') ?></span>
                                                            </div>

                                                            <div class="table-responsive">
                                                                <table class="table table-bordered finalization-customs-table mb-0">
                                                                    <thead>
                                                                        <tr>
                                                                            <th><?= expediente_h($currentLanguage === 'en' ? 'R1 CUSTOMS ENTRY TO ADD' : 'PEDIMENTO R1 PARA AGREGAR') ?></th>
                                                                            <th><?= expediente_h($currentLanguage === 'en' ? 'H1 PARTIAL-RETURN CUSTOMS ENTRY' : 'PEDIMENTO RETORNO PARCIAL H1') ?></th>
                                                <th><?= expediente_h($currentLanguage === 'en' ? 'MERCHANDISE UNDER H1 CUSTOMS ENTRY' : 'MERCANCÍA EN PEDIMENTO H1') ?></th>
                                                                            <th><?= expediente_h($currentLanguage === 'en' ? 'SPLIT R1 CUSTOMS ENTRY' : 'PEDIMENTO R1 DESAGREGADO') ?></th>
                                                                            <th><?= expediente_h($currentLanguage === 'en' ? 'A3 CUSTOMS ENTRY' : 'PEDIMENTO A3') ?></th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr>
                                                                            <td><?= expediente_h((string) ($eventLine['pedimento_r1_agregar'] ?? '') ?: '—') ?></td>
                                                                            <td><?= expediente_h((string) ($eventLine['pedimento_retorno_parcial_h1'] ?? '') ?: '—') ?></td>
                                                                            <td><?= nl2br(expediente_h((string) ($eventLine['mercancia_pedimento_h1'] ?? '') ?: '—')) ?></td>
                                                                            <td><?= expediente_h((string) ($eventLine['pedimento_r1_desagregado'] ?? '') ?: '—') ?></td>
                                                                            <td><?= expediente_h((string) ($eventLine['pedimento_a3'] ?? '') ?: '—') ?></td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php if (trim((string) ($finalization['notes'] ?? '')) !== ''): ?>
                                                    <div class="small text-muted mt-3"><strong><?= expediente_h($currentLanguage === 'en' ? 'Notes:' : 'Observaciones:') ?></strong> <?= expediente_h((string) $finalization['notes']) ?></div>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade<?= $requestedTab === 'fotos' ? ' show active' : '' ?>" id="panel-fotos" role="tabpanel" aria-labelledby="tab-fotos" tabindex="0">
                    <div class="card shadow-sm expediente-card">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                                <h2 class="h5 mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Photo annex' : 'Anexo fotográfico') ?> <span class="badge text-bg-light border text-dark ms-1"><?= count($photos) ?></span></h2>
                                <?php if ($canEdit): ?>
                                    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#photoUploadModal">+ <?= expediente_h($currentLanguage === 'en' ? 'Add photos' : 'Añadir fotos') ?></button>
                                <?php endif; ?>
                            </div>
                            <?php if (isset($_GET['photos_uploaded'])): ?>
                                <?php $uploadedPhotoCount = max(1, (int) $_GET['photos_uploaded']); ?>
                                <div class="alert alert-success py-2 small">
                                    <?= expediente_h($currentLanguage === 'en'
                                        ? ($uploadedPhotoCount === 1 ? 'The photo was added to the case file.' : $uploadedPhotoCount . ' photos were added to the case file.')
                                        : ($uploadedPhotoCount === 1 ? 'La fotografía se añadió al expediente.' : $uploadedPhotoCount . ' fotografías se añadieron al expediente.')) ?>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($_GET['photo_deleted'])): ?>
                                <div class="alert alert-success py-2 small">
                                    <?= expediente_h($currentLanguage === 'en' ? 'The photo was deleted from the case file.' : 'La fotografía se eliminó del expediente.') ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($isHistoricalNotice && $originalHistoricalVersion): ?>
                                <div class="historical-photo-annex mb-4">
                                    <div>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <span class="badge text-bg-dark"><?= expediente_h($currentLanguage === 'en' ? 'HISTORICAL PHOTO ANNEX' : 'ANEXO FOTOGRÁFICO HISTÓRICO') ?></span>
                                            <span class="small text-muted">v<?= expediente_h(str_pad((string) ($originalHistoricalVersion['version_no'] ?? 1), 3, '0', STR_PAD_LEFT)) ?> · <?= (int) ($originalHistoricalVersion['page_count'] ?? 0) ?> <?= expediente_h($currentLanguage === 'en' ? 'pages' : 'páginas') ?></span>
                                        </div>
                                        <p class="mb-1 fw-semibold"><?= expediente_h($currentLanguage === 'en' ? 'The original historical PDF may contain the photographic evidence used when the notice was presented.' : 'El PDF histórico original puede contener la evidencia fotográfica utilizada cuando se presentó el aviso.') ?></p>
                                        <p class="small text-muted mb-0"><?= expediente_h($currentLanguage === 'en' ? 'These embedded images remain preserved inside the original PDF. If you extracted the original Word images separately, you can upload them here and associate each one with its merchandise line.' : 'Estas imágenes incorporadas permanecen preservadas dentro del PDF original. Si extrajiste por separado las imágenes del Word original, puedes cargarlas aquí y asociar cada una con su renglón de mercancía.') ?></p>
                                    </div>
                                    <a class="btn btn-outline-dark flex-shrink-0" href="../api/desembarques/aviso/version_download.php?id=<?= expediente_h((string) $originalHistoricalVersion['id']) ?><?= $deletedQueryHtml ?>" target="_blank" rel="noopener"><?= expediente_h($currentLanguage === 'en' ? 'Open original PDF' : 'Abrir PDF original') ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if ($photos === []): ?>
                                <div class="expediente-empty"><?= expediente_h($isHistoricalNotice ? ($currentLanguage === 'en' ? 'There are no independent native photos attached to this historical notice.' : 'No hay fotografías nativas independientes asociadas a este aviso histórico.') : ($currentLanguage === 'en' ? 'No photos have been assigned to the notice annex.' : 'No hay fotografías asignadas al anexo del aviso.')) ?></div>
                            <?php else: ?>
                                <?php if ($isHistoricalNotice): ?><p class="small text-muted mb-3"><?= expediente_h($currentLanguage === 'en' ? 'Native photos added to this case file after its historical import.' : 'Fotografías nativas añadidas a este expediente después de su importación histórica.') ?></p><?php endif; ?>
                                <div class="row g-3">
                                    <?php foreach ($photos as $photo): ?>
                                        <div class="col-md-6 col-xl-4">
                                            <article class="card h-100 expediente-photo-card">
                                                <a class="expediente-photo-preview" href="../api/desembarques/files/download.php?id=<?= expediente_h((string) $photo['file_id']) ?><?= $deletedQueryHtml ?>" target="_blank" rel="noopener">
                                                    <img src="../api/desembarques/aviso/image.php?file_id=<?= expediente_h((string) $photo['file_id']) ?><?= $deletedQueryHtml ?>" alt="<?= expediente_h((string) ($photo['caption'] ?? $photo['original_name'] ?? 'Foto')) ?>" loading="lazy">
                                                </a>
                                                <div class="card-body">
                                                    <h3 class="h6 mb-1"><?= expediente_h((string) ($photo['caption'] ?? '') ?: (string) ($photo['original_name'] ?? 'Foto')) ?></h3>
                                                    <p class="small text-muted mb-2"><?= expediente_h((string) ($photo['item_description'] ?? '')) ?: expediente_h($currentLanguage === 'en' ? 'General / unassigned' : 'General / sin asignar') ?></p>
                                                    <div class="small text-muted"><?= expediente_h((string) ($photo['original_name'] ?? '')) ?> · <?= expediente_h(expediente_format_bytes((int) ($photo['size'] ?? 0))) ?></div>
                                                </div>
                                                <?php if ($canEdit): ?>
                                                    <?php $photoDeleteLabel = trim((string) ($photo['caption'] ?? '')) ?: trim((string) ($photo['original_name'] ?? 'Foto')); ?>
                                                    <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3 text-end">
                                                        <button
                                                            class="btn btn-sm btn-outline-danger"
                                                            type="button"
                                                            data-photo-delete
                                                            data-photo-id="<?= (int) ($photo['id'] ?? 0) ?>"
                                                            data-photo-name="<?= expediente_h($photoDeleteLabel) ?>"
                                                            data-desembarque-id="<?= (int) $desembarqueId ?>"
                                                            data-csrf-token="<?= expediente_h($csrfToken) ?>"
                                                        ><?= expediente_h($currentLanguage === 'en' ? 'Delete photo' : 'Eliminar foto') ?></button>
                                                    </div>
                                                <?php endif; ?>
                                            </article>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade<?= $requestedTab === 'alcances' ? ' show active' : '' ?>" id="panel-alcances" role="tabpanel" aria-labelledby="tab-alcances" tabindex="0">
                    <div class="card shadow-sm expediente-card">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-start mb-4">
                                <div>
                                    <h2 class="h5 mb-1"><?= expediente_h($currentLanguage === 'en' ? 'Notice addenda' : 'Alcances del aviso') ?> <span class="badge text-bg-light border text-dark ms-1"><?= count($alcances) ?></span></h2>
                                    <p class="small text-muted mb-0"><?= expediente_h($currentLanguage === 'en' ? 'An addendum is a child document of the notice. It selects existing merchandise without changing or removing it from the original notice.' : 'Un Alcance es un documento hijo del Aviso. Selecciona mercancías existentes sin modificarlas ni eliminarlas del Aviso original.') ?></p>
                                </div>
                                <?php if ($canEdit && $items !== [] && $noticeStatusSlug !== 'cancelled'): ?>
                                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#alcanceGenerateModal"><?= expediente_h($currentLanguage === 'en' ? 'Generate addendum' : 'Generar alcance') ?></button>
                                <?php endif; ?>
                            </div>
                            <?php if (isset($_GET['alcance_created'])): ?><div class="alert alert-success py-2 small"><?= expediente_h($currentLanguage === 'en' ? 'The addendum was issued and archived.' : 'El Alcance se emitió y archivó correctamente.') ?></div><?php endif; ?>
                            <?php if (isset($_GET['alcance_versioned'])): ?><div class="alert alert-success py-2 small"><?= expediente_h($currentLanguage === 'en' ? 'A new addendum version was issued.' : 'Se emitió una nueva versión del Alcance.') ?></div><?php endif; ?>
                            <?php if (isset($_GET['alcance_receipt'])): ?><div class="alert alert-success py-2 small"><?= expediente_h($currentLanguage === 'en' ? 'The authority acknowledgment was saved.' : 'El acuse de autoridad se registró correctamente.') ?></div><?php endif; ?>
                            <?php if ($alcances === []): ?>
                                <div class="expediente-empty"><?= expediente_h($currentLanguage === 'en' ? 'No addendum has been issued for this notice yet.' : 'Todavía no se ha emitido ningún Alcance para este Aviso.') ?></div>
                            <?php else: ?>
                                <div class="d-grid gap-3">
                                    <?php foreach ($alcances as $alcance): ?>
                                        <?php
                                            $alcanceStatus = strtolower((string) ($alcance['status'] ?? 'issued'));
                                            $alcanceBadge = $alcanceStatus === 'presented' ? 'text-bg-success' : ($alcanceStatus === 'cancelled' ? 'text-bg-danger' : 'text-bg-primary');
                                            $alcanceStatusLabel = $alcanceStatus === 'presented' ? ($currentLanguage === 'en' ? 'PRESENTED' : 'PRESENTADO') : ($alcanceStatus === 'cancelled' ? ($currentLanguage === 'en' ? 'CANCELLED' : 'CANCELADO') : ($currentLanguage === 'en' ? 'ISSUED' : 'EMITIDO'));
                                            $alcanceLatest = is_array($alcance['latest_version'] ?? null) ? $alcance['latest_version'] : null;
                                            $alcanceReceipt = is_array($alcance['latest_receipt'] ?? null) ? $alcance['latest_receipt'] : null;
                                            $alcanceItemIds = array_values(array_map('intval', is_array($alcance['item_ids'] ?? null) ? $alcance['item_ids'] : []));
                                            $receiptDateLocal = '';
                                            if ($alcanceReceipt && trim((string) ($alcanceReceipt['received_at'] ?? '')) !== '') {
                                                try { $receiptDateLocal = (new DateTimeImmutable((string) $alcanceReceipt['received_at']))->format('Y-m-d\TH:i'); } catch (Throwable $ignored) { $receiptDateLocal = ''; }
                                            }
                                        ?>
                                        <article class="alcance-card">
                                            <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                        <span class="badge text-bg-dark"><?= expediente_h($currentLanguage === 'en' ? 'Addendum' : 'Alcance') ?> #<?= (int) ($alcance['alcance_no'] ?? 0) ?></span>
                                                        <span class="badge <?= expediente_h($alcanceBadge) ?>"><?= expediente_h($alcanceStatusLabel) ?></span>
                                                        <strong><?= expediente_h((string) ($alcance['document_code'] ?? '') ?: (($currentLanguage === 'en' ? 'Notice ' : 'Aviso ') . (string) ($alcance['notice_number'] ?? ''))) ?></strong>
                                                    </div>
                                                    <?php $alcanceItemCount = count((array) ($alcance['items'] ?? [])); ?>
                                                    <div class="small text-muted mb-2"><?= expediente_h(($currentLanguage === 'en' ? 'Addendum date: ' : 'Fecha del Alcance: ') . expediente_format_date((string) ($alcance['alcance_date'] ?? ''), false)) ?> · <?= $alcanceItemCount ?> <?= expediente_h($currentLanguage === 'en' ? ($alcanceItemCount === 1 ? 'merchandise line' : 'merchandise lines') : 'mercancías') ?></div>
                                                    <?php if (trim((string) ($alcance['notes'] ?? '')) !== ''): ?><div class="small mb-2"><?= expediente_h((string) $alcance['notes']) ?></div><?php endif; ?>
                                                    <details class="alcance-items-details">
                                                        <summary><?= expediente_h($currentLanguage === 'en' ? 'View merchandise included' : 'Ver mercancías incluidas') ?></summary>
                                                        <ul class="small mt-2 mb-0">
                                                            <?php foreach ((array) ($alcance['items'] ?? []) as $alcanceItem): ?><li><?= expediente_h((string) ($alcanceItem['descripcion'] ?? '')) ?> · <?= expediente_h(expediente_format_quantity((float) ($alcanceItem['cantidad'] ?? 0))) ?></li><?php endforeach; ?>
                                                        </ul>
                                                    </details>
                                                </div>
                                                <div class="alcance-actions d-flex flex-wrap align-items-start gap-2">
                                                    <?php if ($alcanceLatest): ?><a class="btn btn-outline-dark btn-sm" href="../api/desembarques/aviso/alcance_version_download.php?id=<?= (int) $alcanceLatest['id'] ?><?= $deletedQueryHtml ?>"><?= expediente_h($currentLanguage === 'en' ? 'Download PDF' : 'Descargar PDF') ?></a><?php endif; ?>
                                                    <?php if ($canEdit && $alcanceStatus !== 'cancelled'): ?>
                                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-alcance-new-version data-alcance-id="<?= (int) $alcance['id'] ?>" data-alcance-no="<?= (int) $alcance['alcance_no'] ?>" data-alcance-date="<?= expediente_h((string) $alcance['alcance_date']) ?>" data-item-ids='<?= expediente_h(json_encode($alcanceItemIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'><?= expediente_h($currentLanguage === 'en' ? 'New version' : 'Nueva versión') ?></button>
                                                        <button class="btn <?= $alcanceReceipt ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-sm" type="button" data-alcance-receipt data-alcance-id="<?= (int) $alcance['id'] ?>" data-has-receipt="<?= $alcanceReceipt ? '1' : '0' ?>" data-folio="<?= expediente_h((string) ($alcanceReceipt['folio'] ?? '')) ?>" data-received-at="<?= expediente_h($receiptDateLocal) ?>" data-notes="<?= expediente_h((string) ($alcanceReceipt['notes'] ?? '')) ?>"><?= expediente_h($alcanceReceipt ? ($currentLanguage === 'en' ? 'Correct acknowledgment' : 'Corregir acuse') : ($currentLanguage === 'en' ? 'Record acknowledgment' : 'Registrar acuse')) ?></button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="alcance-version-list mt-3">
                                                <?php foreach ((array) ($alcance['versions'] ?? []) as $alcanceVersion): ?>
                                                    <div class="alcance-version-row d-flex flex-wrap justify-content-between gap-2 align-items-center">
                                                        <div><span class="badge text-bg-light border text-dark">v<?= expediente_h(str_pad((string) ($alcanceVersion['version_no'] ?? 0), 3, '0', STR_PAD_LEFT)) ?></span> <span class="small text-muted"><?= expediente_h(expediente_format_date((string) ($alcanceVersion['generated_at'] ?? ''), true)) ?> · <?= (int) ($alcanceVersion['page_count'] ?? 0) ?> <?= expediente_h($currentLanguage === 'en' ? 'pages' : 'páginas') ?></span></div>
                                                        <a class="btn btn-link btn-sm p-0" href="../api/desembarques/aviso/alcance_version_download.php?id=<?= (int) $alcanceVersion['id'] ?><?= $deletedQueryHtml ?>"><?= expediente_h($currentLanguage === 'en' ? 'Download' : 'Descargar') ?></a>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="alcance-receipt mt-3 <?= $alcanceReceipt ? 'has-receipt' : 'pending-receipt' ?>">
                                                <?php if ($alcanceReceipt): ?>
                                                    <strong><?= expediente_h($currentLanguage === 'en' ? 'Authority acknowledgment' : 'Acuse de autoridad') ?></strong>
                                                    <div class="small mt-1"><?= expediente_h($currentLanguage === 'en' ? 'Acknowledgment reference number' : 'Folio') ?> <?= expediente_h((string) $alcanceReceipt['folio']) ?> · <?= expediente_h(expediente_format_date((string) $alcanceReceipt['received_at'], true)) ?></div>
                                                    <?php if (! empty($alcanceReceipt['evidence_file_id'])): ?><a class="btn btn-link btn-sm p-0 mt-1" href="../api/desembarques/files/download.php?id=<?= (int) $alcanceReceipt['evidence_file_id'] ?><?= $deletedQueryHtml ?>" target="_blank" rel="noopener"><?= expediente_h($currentLanguage === 'en' ? 'Open acknowledgment evidence' : 'Abrir evidencia del acuse') ?></a><?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge text-bg-warning"><?= expediente_h($currentLanguage === 'en' ? 'ACKNOWLEDGMENT PENDING' : 'ACUSE PENDIENTE') ?></span>
                                                    <span class="small text-muted ms-2"><?= expediente_h($currentLanguage === 'en' ? 'The acknowledgment reference number and date received are recorded only after the authority receives the document.' : 'El folio y la fecha de recibido se capturan únicamente después de que la autoridad recibe el documento.') ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade<?= $requestedTab === 'pdfs' ? ' show active' : '' ?>" id="panel-pdfs" role="tabpanel" aria-labelledby="tab-pdfs" tabindex="0">
                    <div class="card shadow-sm expediente-card">
                        <div class="card-body p-4">
                            <h2 class="h5 mb-1"><?= expediente_h($currentLanguage === 'en' ? 'Issued notice PDFs' : 'PDFs emitidos del aviso') ?></h2>
                            <p class="text-muted small mb-4"><?= expediente_h($currentLanguage === 'en' ? 'Each entry is an immutable historical version with integrity fingerprints.' : 'Cada registro es una versión histórica inmutable con huellas de integridad.') ?></p>
                            <?php if ($versions === []): ?>
                                <div class="expediente-empty"><?= expediente_h($currentLanguage === 'en' ? 'No PDF version has been issued yet.' : 'Todavía no se ha emitido ninguna versión PDF.') ?></div>
                            <?php else: ?>
                                <div class="d-grid gap-3">
                                    <?php foreach ($versions as $version): ?>
                                        <article class="expediente-version d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                                            <div>
                                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                    <span class="badge text-bg-primary">v<?= expediente_h(str_pad((string) ($version['version_no'] ?? 0), 3, '0', STR_PAD_LEFT)) ?></span>
                                                    <?php $versionIsHistorical = strtolower(trim((string) ($version['source_type'] ?? ''))) === 'historical_import'; ?>
                                                    <span class="badge <?= $versionIsHistorical ? 'text-bg-dark' : 'text-bg-primary' ?>"><?= expediente_h($versionIsHistorical ? ($currentLanguage === 'en' ? 'HISTORICAL ORIGINAL' : 'ORIGINAL HISTÓRICO') : ($currentLanguage === 'en' ? 'SYSTEM GENERATED' : 'GENERADO POR SISTEMA')) ?></span>
                                                    <strong><?= expediente_h((string) ($version['document_code'] ?? '') ?: (string) ($version['notice_number'] ?? 'Aviso')) ?></strong>
                                                </div>
                                                <div class="small text-muted mb-2"><?php if (! empty($version['document_date'])): ?><?= expediente_h(($currentLanguage === 'en' ? 'Document: ' : 'Documento: ') . expediente_format_date($version['document_date'])) ?> · <?php endif; ?><?= expediente_h(($versionIsHistorical ? ($currentLanguage === 'en' ? 'Imported: ' : 'Importado: ') : ($currentLanguage === 'en' ? 'Issued: ' : 'Emitido: ')) . expediente_format_date($version['generated_at'] ?? null, true)) ?> · <?= expediente_h((string) ($version['generated_by_name'] ?? '—')) ?> · <?= (int) ($version['page_count'] ?? 0) ?> <?= expediente_h($currentLanguage === 'en' ? 'pages' : 'páginas') ?> · <?= expediente_h(expediente_format_bytes((int) ($version['size'] ?? 0))) ?></div>
                                                <code class="expediente-hash d-block">PDF <?= expediente_h((string) ($version['pdf_sha256'] ?? '—')) ?></code>
                                                <code class="expediente-hash d-block mt-1">SNAP <?= expediente_h((string) ($version['snapshot_sha256'] ?? '—')) ?></code>
                                            </div>
                                            <a class="btn btn-outline-primary flex-shrink-0" href="../api/desembarques/aviso/version_download.php?id=<?= expediente_h((string) $version['id']) ?><?= $deletedQueryHtml ?>"><?= expediente_h($currentLanguage === 'en' ? 'Download PDF' : 'Descargar PDF') ?></a>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade<?= $requestedTab === 'historial' ? ' show active' : '' ?>" id="panel-historial" role="tabpanel" aria-labelledby="tab-historial" tabindex="0">
                    <div class="card shadow-sm expediente-card">
                        <div class="card-body p-4">
                            <h2 class="h5 mb-1"><?= expediente_h($currentLanguage === 'en' ? 'File history' : 'Historial del expediente') ?></h2>
                            <p class="text-muted small mb-4"><?= expediente_h($currentLanguage === 'en' ? 'Operational, document and notice events already registered in the audit log.' : 'Eventos operativos, documentales y del aviso ya registrados en la bitácora de auditoría.') ?></p>
                            <?php if ($timeline === []): ?>
                                <div class="expediente-empty"><?= expediente_h($currentLanguage === 'en' ? 'There are no auditable events for this file.' : 'No hay eventos auditables para este expediente.') ?></div>
                            <?php else: ?>
                                <div class="expediente-timeline">
                                    <?php foreach ($timeline as $event): ?>
                                        <article class="expediente-timeline-item">
                                            <div class="expediente-timeline-marker" aria-hidden="true"></div>
                                            <div class="expediente-timeline-content">
                                                <div class="d-flex flex-column flex-md-row justify-content-between gap-1">
                                                    <strong><?= expediente_h((string) ($event['timeline_label'] ?? 'Evento')) ?></strong>
                                                    <time class="small text-muted"><?= expediente_h(expediente_format_date($event['created_at'] ?? null, true)) ?></time>
                                                </div>
                                                <?php if (trim((string) ($event['timeline_detail'] ?? '')) !== ''): ?><div class="small mt-1"><?= expediente_h((string) $event['timeline_detail']) ?></div><?php endif; ?>
                                                <div class="small text-muted mt-1"><?= expediente_h((string) ($event['user_name'] ?? ($currentLanguage === 'en' ? 'System' : 'Sistema'))) ?></div>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($pageError === '' && $desembarqueId > 0): ?>
        <?php if ($canEdit): ?>
            <div class="modal fade" id="photoUploadModal" tabindex="-1" aria-labelledby="photoUploadModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
                    <div class="modal-content">
                        <form id="photo-upload-form" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= expediente_h($csrfToken) ?>">
                            <input type="hidden" name="desembarque_id" value="<?= (int) $desembarqueId ?>">
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title fs-5" id="photoUploadModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Add photos to the notice' : 'Añadir fotos al aviso') ?></h2>
                                    <p class="small text-muted mb-0 mt-1"><?= expediente_h($currentLanguage === 'en' ? 'Upload extracted Word images or other photographic evidence and optionally associate each image with a merchandise line.' : 'Carga imágenes extraídas del Word u otra evidencia fotográfica y, si corresponde, asocia cada imagen con un renglón de mercancía.') ?></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                            </div>
                            <div class="modal-body">
                                <?php if ($isHistoricalNotice): ?>
                                    <div class="alert alert-info small">
                                        <?= expediente_h($currentLanguage === 'en' ? 'The historical PDF remains immutable. These images are stored as independent native photos in the case file without altering the original PDF.' : 'El PDF histórico permanece inmutable. Estas imágenes se guardan como fotografías nativas independientes del expediente sin alterar el PDF original.') ?>
                                    </div>
                                <?php endif; ?>
                                <label class="form-label fw-semibold" for="photo-upload-files"><?= expediente_h($currentLanguage === 'en' ? 'Photos' : 'Fotografías') ?></label>
                                <input class="form-control" id="photo-upload-files" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp" multiple required>
                                <div class="form-text"><?= expediente_h($currentLanguage === 'en' ? 'Up to 20 photos per operation; maximum 25 MB each. Supported: JPG, PNG, GIF and WEBP.' : 'Hasta 20 fotos por operación; máximo 25 MB por foto. Formatos compatibles: JPG, PNG, GIF y WEBP.') ?></div>
                                <div class="mt-3" id="photo-upload-list"></div>
                                <div class="alert d-none" id="photo-upload-feedback" role="status"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                                <button type="submit" class="btn btn-primary" id="photo-upload-submit"><?= expediente_h($currentLanguage === 'en' ? 'Save photos' : 'Guardar fotos') ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="documentUploadModal" tabindex="-1" aria-labelledby="documentUploadModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form id="document-upload-form" enctype="multipart/form-data" novalidate>
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title fs-5" id="documentUploadModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Add case file documents' : 'Añadir documentos al expediente') ?></h2>
                                    <p class="small text-muted mb-0 mt-1"><?= expediente_h($currentLanguage === 'en' ? 'Available regardless of the notice status. PDF, DOC and DOCX only.' : 'Disponible sin importar el estado del aviso. Solo PDF, DOC y DOCX.') ?></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?= expediente_h($csrfToken) ?>">
                                <input type="hidden" name="desembarque_id" value="<?= $desembarqueId ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="document-upload-type"><?= expediente_h($currentLanguage === 'en' ? 'Document type' : 'Tipo de documento') ?></label>
                                        <select class="form-select" id="document-upload-type" name="document_type" required>
                                            <?php foreach ($documentTypes as $typeKey => $typeLabel): ?>
                                                <option value="<?= expediente_h($typeKey) ?>"<?= $typeKey === 'other' ? ' selected' : '' ?>><?= expediente_h($typeLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="document-upload-date"><?= expediente_h($currentLanguage === 'en' ? 'Document date' : 'Fecha del documento') ?></label>
                                        <input class="form-control" type="date" id="document-upload-date" name="document_date">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold" for="document-upload-files"><?= expediente_h($currentLanguage === 'en' ? 'Files' : 'Archivos') ?></label>
                                        <input class="form-control" type="file" id="document-upload-files" name="documents[]" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple required>
                                        <div class="form-text"><?= expediente_h($currentLanguage === 'en' ? 'Up to 10 files per operation; maximum 10 MB each.' : 'Hasta 10 archivos por operación; máximo 10 MB por archivo.') ?></div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold" for="document-upload-description"><?= expediente_h($currentLanguage === 'en' ? 'Description' : 'Descripción') ?></label>
                                        <textarea class="form-control" id="document-upload-description" name="description" rows="3" maxlength="500" placeholder="<?= expediente_h($currentLanguage === 'en' ? 'Optional note about these documents' : 'Nota opcional sobre estos documentos') ?>"></textarea>
                                    </div>
                                </div>
                                <div class="alert d-none mt-3 mb-0" id="document-upload-feedback" role="alert"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                                <button type="submit" class="btn btn-primary" id="document-upload-submit"><?= expediente_h($currentLanguage === 'en' ? 'Save documents' : 'Guardar documentos') ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="documentReplaceModal" tabindex="-1" aria-labelledby="documentReplaceModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form id="document-replace-form" enctype="multipart/form-data" novalidate>
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title fs-5" id="documentReplaceModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Replace document' : 'Reemplazar documento') ?></h2>
                                    <p class="small text-muted mb-0 mt-1" id="document-replace-current"></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info small"><?= expediente_h($currentLanguage === 'en' ? 'The current file will not be deleted. It will remain marked as REPLACED and the new file will become CURRENT.' : 'El archivo actual no se eliminará. Permanecerá marcado como REEMPLAZADO y el nuevo archivo quedará VIGENTE.') ?></div>
                                <input type="hidden" name="csrf_token" value="<?= expediente_h($csrfToken) ?>">
                                <input type="hidden" name="file_id" id="document-replace-id" value="">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="document-replace-type"><?= expediente_h($currentLanguage === 'en' ? 'Document type' : 'Tipo de documento') ?></label>
                                        <select class="form-select" id="document-replace-type" name="document_type" required>
                                            <?php foreach ($documentTypes as $typeKey => $typeLabel): ?>
                                                <option value="<?= expediente_h($typeKey) ?>"><?= expediente_h($typeLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="document-replace-date"><?= expediente_h($currentLanguage === 'en' ? 'Document date' : 'Fecha del documento') ?></label>
                                        <input class="form-control" type="date" id="document-replace-date" name="document_date">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold" for="document-replace-file"><?= expediente_h($currentLanguage === 'en' ? 'Replacement file' : 'Archivo de reemplazo') ?></label>
                                        <input class="form-control" type="file" id="document-replace-file" name="document" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold" for="document-replace-description"><?= expediente_h($currentLanguage === 'en' ? 'Description' : 'Descripción') ?></label>
                                        <textarea class="form-control" id="document-replace-description" name="description" rows="3" maxlength="500"></textarea>
                                    </div>
                                </div>
                                <div class="alert d-none mt-3 mb-0" id="document-replace-feedback" role="alert"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                                <button type="submit" class="btn btn-primary" id="document-replace-submit"><?= expediente_h($currentLanguage === 'en' ? 'Save new version' : 'Guardar nueva versión') ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canEdit && $noticeStatusTransitions !== []): ?>
            <div class="modal fade" id="avisoStatusModal" tabindex="-1" aria-labelledby="avisoStatusModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form id="aviso-status-form" novalidate>
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title fs-5" id="avisoStatusModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Change notice status' : 'Cambiar estado del aviso') ?></h2>
                                    <p class="small text-muted mb-0 mt-1"><?= expediente_h(($currentLanguage === 'en' ? 'Current status: ' : 'Estado actual: ') . $noticeStatusMeta['label']) ?></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info small">
                                    <?= expediente_h($currentLanguage === 'en' ? 'Adding and replacing case file documents remains enabled in every notice status.' : 'Añadir y reemplazar documentos del expediente seguirá habilitado en cualquier estado del aviso.') ?>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold" for="aviso-status-target"><?= expediente_h($currentLanguage === 'en' ? 'New status' : 'Nuevo estado') ?></label>
                                    <select class="form-select" id="aviso-status-target" name="to_status" required>
                                        <option value=""><?= expediente_h($currentLanguage === 'en' ? 'Select a status' : 'Selecciona un estado') ?></option>
                                        <?php foreach ($noticeStatusTransitions as $targetStatus): $targetMeta = aviso_status_meta($targetStatus, $currentLanguage); ?>
                                            <option value="<?= expediente_h($targetStatus) ?>" data-requires-reason="<?= aviso_status_requires_reason($noticeStatusSlug, $targetStatus) ? '1' : '0' ?>" data-requires-effective="<?= aviso_status_requires_effective_at($targetStatus) ? '1' : '0' ?>">
                                                <?= expediente_h($targetMeta['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3 d-none" id="aviso-status-effective-wrap">
                                    <label class="form-label fw-semibold" for="aviso-status-effective"><?= expediente_h($currentLanguage === 'en' ? 'Presentation date and time' : 'Fecha y hora de presentación') ?></label>
                                    <input class="form-control" id="aviso-status-effective" name="effective_at" type="datetime-local">
                                </div>
                                <div class="mb-0" id="aviso-status-reason-wrap">
                                    <label class="form-label fw-semibold" for="aviso-status-reason"><?= expediente_h($currentLanguage === 'en' ? 'Reason / note' : 'Motivo / observación') ?></label>
                                    <textarea class="form-control" id="aviso-status-reason" name="reason" rows="3" maxlength="1000" placeholder="<?= expediente_h($currentLanguage === 'en' ? 'Optional for presentation; required for replacement, cancellation or reopening.' : 'Opcional para presentación; obligatorio para reemplazo, cancelación o reapertura.') ?>"></textarea>
                                    <div class="form-text" id="aviso-status-reason-help"></div>
                                </div>
                                <div class="alert d-none mt-3 mb-0" id="aviso-status-feedback" role="alert"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                                <button type="submit" class="btn btn-primary" id="aviso-status-submit"><?= expediente_h($currentLanguage === 'en' ? 'Apply status' : 'Aplicar estado') ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canEdit && $items !== []): ?>
            <div class="modal fade" id="alcanceGenerateModal" tabindex="-1" aria-labelledby="alcanceGenerateModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
                    <form class="modal-content" id="alcance-generate-form" novalidate>
                        <div class="modal-header">
                            <div>
                                <h2 class="modal-title fs-5" id="alcanceGenerateModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Generate notice addendum' : 'Generar alcance del aviso') ?></h2>
                                <p class="small text-muted mb-0 mt-1"><?= expediente_h($currentLanguage === 'en' ? 'Select one or more existing merchandise lines. The original notice is never modified.' : 'Selecciona una o varias mercancías existentes. El Aviso original nunca se modifica.') ?></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info small">
                                <?= expediente_h($currentLanguage === 'en' ? 'The acknowledgment reference number and date received are NOT entered here. Those values are recorded only after the authority receives this addendum.' : 'El folio y la fecha de recibido NO se capturan aquí. Esos datos se registran únicamente después de que la autoridad recibe este Alcance.') ?>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold" for="alcance-date"><?= expediente_h($currentLanguage === 'en' ? 'Addendum date' : 'Fecha del Alcance') ?></label>
                                    <input class="form-control" type="date" id="alcance-date" value="<?= expediente_h(date('Y-m-d')) ?>" required>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold" for="alcance-notes"><?= expediente_h($currentLanguage === 'en' ? 'Internal notes' : 'Observaciones internas') ?></label>
                                    <input class="form-control" type="text" id="alcance-notes" maxlength="2000" placeholder="<?= expediente_h($currentLanguage === 'en' ? 'Optional note explaining why this addendum is being issued' : 'Nota opcional sobre el motivo de este Alcance') ?>">
                                </div>
                            </div>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <div>
                                    <h3 class="h6 mb-1"><?= expediente_h($currentLanguage === 'en' ? 'Merchandise included in the addendum' : 'Mercancías que integran el Alcance') ?></h3>
                                    <p class="small text-muted mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Only selected merchandise appears in the addendum PDF. It remains unchanged in the original notice.' : 'Sólo la mercancía seleccionada aparecerá en el PDF del Alcance. Permanecerá sin cambios dentro del Aviso original.') ?></p>
                                </div>
                            </div>
                            <div class="table-responsive border rounded alcance-select-table-wrap">
                                <table class="table align-middle mb-0">
                                    <thead><tr><th style="width:48px"></th><th><?= expediente_h($currentLanguage === 'en' ? 'Merchandise' : 'Mercancía') ?></th><th><?= expediente_h($currentLanguage === 'en' ? 'Customs entry' : 'Pedimento') ?></th><th class="text-end"><?= expediente_h($currentLanguage === 'en' ? 'Quantity' : 'Cantidad') ?></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td><input class="form-check-input alcance-item-check" type="checkbox" value="<?= (int) ($item['id'] ?? 0) ?>"></td>
                                            <td><div class="fw-semibold"><?= expediente_h((string) ($item['descripcion'] ?? '')) ?></div><div class="small text-muted"><?= expediente_h((string) ($item['serial_number'] ?? '')) ?></div></td>
                                            <td><?= expediente_render_item_pedimentos($item) ?></td>
                                            <td class="text-end fw-semibold"><?= expediente_h(expediente_format_quantity((float) ($item['cantidad'] ?? 0))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="small text-muted mt-3 mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Photos already linked to selected merchandise will be reused automatically in the photographic annex when available.' : 'Las fotografías ya relacionadas con la mercancía seleccionada se reutilizarán automáticamente en el anexo fotográfico cuando estén disponibles.') ?></p>
                            <div class="alert d-none mt-3 mb-0" id="alcance-generate-feedback" role="alert"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                            <button type="submit" class="btn btn-primary" id="alcance-generate-submit"><?= expediente_h($currentLanguage === 'en' ? 'Generate PDF and archive' : 'Generar PDF y archivar') ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="modal fade" id="alcanceReceiptModal" tabindex="-1" aria-labelledby="alcanceReceiptModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <form class="modal-content" id="alcance-receipt-form" enctype="multipart/form-data" novalidate>
                        <div class="modal-header">
                            <div>
                                <h2 class="modal-title fs-5" id="alcanceReceiptModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Record addendum acknowledgment' : 'Registrar acuse del Alcance') ?></h2>
                                <p class="small text-muted mb-0 mt-1"><?= expediente_h($currentLanguage === 'en' ? 'Enter these values only after the authority stamps or receives the addendum.' : 'Captura estos datos únicamente después de que la autoridad selle/reciba el Alcance.') ?></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="alcance-receipt-id" value="">
                            <div class="row g-3">
                                <div class="col-md-5"><label class="form-label fw-semibold" for="alcance-receipt-folio"><?= expediente_h($currentLanguage === 'en' ? 'Acknowledgment reference number' : 'Folio del acuse') ?></label><input class="form-control" id="alcance-receipt-folio" maxlength="100" required></div>
                                <div class="col-md-7"><label class="form-label fw-semibold" for="alcance-receipt-received-at"><?= expediente_h($currentLanguage === 'en' ? 'Received date/time' : 'Fecha y hora de recibido') ?></label><input class="form-control" type="datetime-local" id="alcance-receipt-received-at" required></div>
                                <div class="col-12"><label class="form-label fw-semibold" for="alcance-receipt-notes"><?= expediente_h($currentLanguage === 'en' ? 'Notes' : 'Observaciones') ?></label><textarea class="form-control" id="alcance-receipt-notes" rows="2" maxlength="1000"></textarea></div>
                                <div class="col-12"><label class="form-label fw-semibold" for="alcance-receipt-evidence"><?= expediente_h($currentLanguage === 'en' ? 'Stamped acknowledgment evidence' : 'Evidencia del acuse sellado') ?></label><input class="form-control" type="file" id="alcance-receipt-evidence" accept="application/pdf,image/jpeg,image/png"><div class="form-text"><?= expediente_h($currentLanguage === 'en' ? 'Optional PDF/JPG/PNG. The issued PDF is never replaced.' : 'PDF/JPG/PNG opcional. El PDF emitido nunca se reemplaza.') ?></div></div>
                                <div class="col-12 d-none" id="alcance-receipt-correction-wrap"><label class="form-label fw-semibold" for="alcance-receipt-correction-reason"><?= expediente_h($currentLanguage === 'en' ? 'Correction reason' : 'Motivo de la corrección') ?></label><textarea class="form-control" id="alcance-receipt-correction-reason" rows="2" maxlength="1000"></textarea></div>
                            </div>
                            <div class="alert d-none mt-3 mb-0" id="alcance-receipt-feedback" role="alert"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                            <button type="submit" class="btn btn-success" id="alcance-receipt-submit"><?= expediente_h($currentLanguage === 'en' ? 'Save acknowledgment' : 'Guardar acuse') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canFinalizeMerchandise): ?>
            <div class="modal fade" id="merchandiseFinalizeModal" tabindex="-1" aria-labelledby="merchandiseFinalizeModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
                    <form class="modal-content" id="merchandise-finalize-form" novalidate>
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title fs-5" id="merchandiseFinalizeModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Record merchandise departure' : 'Finalizar mercancía') ?></h2>
                                    <p class="small text-muted mb-0 mt-1"><?= expediente_h($currentLanguage === 'en' ? 'Select one or more merchandise lines and record the customs data for this departure. The merchandise will remain visible in the notice.' : 'Selecciona una o varias mercancías y registra los datos aduanales de esta salida. La mercancía permanecerá visible dentro del aviso.') ?></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info small">
                                    <?= expediente_h($currentLanguage === 'en' ? 'You may release the full remaining quantity or only part of it. Partial quantities will remain marked as partially stored until the remaining balance is released.' : 'Puedes finalizar toda la cantidad disponible o sólo una parte. Las cantidades parciales quedarán marcadas como PARCIAL hasta que salga el saldo restante.') ?>
                                </div>

                                <fieldset class="mb-4">
                                    <legend class="h6 mb-2"><?= expediente_h($currentLanguage === 'en' ? 'Documentary closure type' : 'Tipo de cierre documental') ?></legend>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-check border rounded p-3 h-100">
                                                <input class="form-check-input ms-0 me-2" type="radio" name="closure_status" id="merchandise-closure-complete" value="complete" checked>
                                                <label class="form-check-label fw-semibold" for="merchandise-closure-complete"><?= expediente_h($currentLanguage === 'en' ? 'Complete closure' : 'Cierre completo') ?></label>
                                                <div class="small text-muted mt-1 ps-4"><?= expediente_h($currentLanguage === 'en' ? 'Use when the customs information for every selected merchandise line is ready.' : 'Úsalo cuando la información aduanal de cada mercancía seleccionada ya está disponible.') ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check border rounded p-3 h-100">
                                                <input class="form-check-input ms-0 me-2" type="radio" name="closure_status" id="merchandise-closure-partial" value="partial">
                                                <label class="form-check-label fw-semibold" for="merchandise-closure-partial"><?= expediente_h($currentLanguage === 'en' ? 'Partial closure — customs entries pending' : 'Cierre parcial — pedimentos pendientes') ?></label>
                                                <div class="small text-muted mt-1 ps-4"><?= expediente_h($currentLanguage === 'en' ? 'Records the physical departure now and allows the customs information to be completed later without duplicating quantities.' : 'Registra la salida física ahora y permite completar los datos aduanales después sin duplicar cantidades.') ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-text mt-2" id="merchandise-closure-help"><?= expediente_h($currentLanguage === 'en' ? 'Complete closure requires at least one customs field for each selected merchandise line.' : 'El cierre completo requiere al menos un dato aduanal por cada mercancía seleccionada.') ?></div>
                                </fieldset>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold" for="merchandise-finalize-date"><?= expediente_h($currentLanguage === 'en' ? 'Departure / export date' : 'Fecha de salida / exportación') ?></label>
                                        <input class="form-control" type="date" id="merchandise-finalize-date" name="export_date" value="<?= expediente_h(date('Y-m-d')) ?>" required>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label fw-semibold" for="merchandise-finalize-notes"><span id="merchandise-finalize-notes-label"><?= expediente_h($currentLanguage === 'en' ? 'Notes' : 'Observaciones') ?></span><span class="text-danger d-none" id="merchandise-finalize-notes-required" aria-hidden="true"> *</span></label>
                                        <input class="form-control" type="text" id="merchandise-finalize-notes" name="notes" maxlength="1000" placeholder="<?= expediente_h($currentLanguage === 'en' ? 'Optional note about this departure' : 'Nota opcional sobre esta salida') ?>">
                                        <div class="form-text d-none" id="merchandise-finalize-notes-help"><?= expediente_h($currentLanguage === 'en' ? 'For a partial closure, specify which customs entries or documents are still pending.' : 'Para un cierre parcial, indica qué pedimentos o documentos siguen pendientes.') ?></div>
                                    </div>
                                </div>

                                <section class="mb-4">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                        <div>
                                            <h3 class="h6 mb-1"><?= expediente_h($currentLanguage === 'en' ? 'Merchandise leaving storage' : 'Mercancía que sale de almacén') ?></h3>
                                            <p class="small text-muted mb-0"><?= expediente_h($currentLanguage === 'en' ? 'Select only the merchandise lines included in this movement. You can register as many separate departures as needed until the balance reaches zero.' : 'Selecciona únicamente los renglones que salen en este movimiento. Puedes registrar tantas salidas independientes como sean necesarias hasta agotar el saldo.') ?></p>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="merchandise-finalize-select-all">
                                            <label class="form-check-label small fw-semibold" for="merchandise-finalize-select-all"><?= expediente_h($currentLanguage === 'en' ? 'Select all available' : 'Seleccionar todas disponibles') ?></label>
                                        </div>
                                    </div>
                                    <div class="table-responsive border rounded merchandise-finalize-items-wrap">
                                        <table class="table align-middle mb-0 merchandise-finalize-items-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:48px"></th>
                                                    <th><?= expediente_h($currentLanguage === 'en' ? 'Merchandise' : 'Mercancía') ?></th>
                                                    <th><?= expediente_h($currentLanguage === 'en' ? 'Customs entry' : 'Pedimento') ?></th>
                                                    <th class="text-end"><?= expediente_h($currentLanguage === 'en' ? 'Original' : 'Original') ?></th>
                                                    <th class="text-end"><?= expediente_h($currentLanguage === 'en' ? 'Already out' : 'Ya salió') ?></th>
                                                    <th class="text-end"><?= expediente_h($currentLanguage === 'en' ? 'Available' : 'Disponible') ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($items as $item): ?>
                                                <?php $remaining = (float) ($item['_remaining_quantity'] ?? 0); if ($remaining <= 0.0005) { continue; } ?>
                                                <tr data-finalize-item-row data-item-id="<?= (int) ($item['id'] ?? 0) ?>">
                                                    <td>
                                                        <input class="form-check-input merchandise-finalize-item-check" type="checkbox" value="<?= (int) ($item['id'] ?? 0) ?>" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Select merchandise' : 'Seleccionar mercancía') ?>">
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold"><?= expediente_h((string) ($item['descripcion'] ?? '')) ?></div>
                                                        <div class="small text-muted"><?= expediente_h((string) ($item['serial_number'] ?? '')) ?></div>
                                                    </td>
                                                    <td><?= expediente_render_item_pedimentos($item) ?></td>
                                                    <td class="text-end"><?= expediente_h(expediente_format_quantity((float) ($item['cantidad'] ?? 0))) ?></td>
                                                    <td class="text-end"><?= expediente_h(expediente_format_quantity((float) ($item['_finalized_quantity'] ?? 0))) ?></td>
                                                    <td class="text-end fw-semibold"><?= expediente_h(expediente_format_quantity($remaining)) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="merchandise-finalize-details-section" id="merchandise-finalize-details-section">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                        <div>
                                            <h3 class="h6 mb-1"><?= expediente_h($currentLanguage === 'en' ? 'Details for each selected merchandise line' : 'Detalle por cada mercancía seleccionada') ?></h3>
                                            <p class="small text-muted mb-0" id="merchandise-finalize-customs-help"><?= expediente_h($currentLanguage === 'en' ? 'Each selected merchandise line has its own departure quantity and customs data. At least one customs field is required per merchandise line for a complete closure.' : 'Cada renglón seleccionado tiene su propia cantidad de salida y sus propios datos aduanales. Para un cierre completo, al menos un dato aduanal es obligatorio por cada mercancía.') ?></p>
                                        </div>
                                        <span class="badge text-bg-light border text-dark" id="merchandise-finalize-selected-count">0 <?= expediente_h($currentLanguage === 'en' ? 'selected' : 'seleccionadas') ?></span>
                                    </div>

                                    <div class="expediente-empty merchandise-finalize-no-selection" id="merchandise-finalize-no-selection">
                                        <?= expediente_h($currentLanguage === 'en' ? 'Select merchandise above to capture its departure data.' : 'Selecciona mercancías arriba para capturar los datos de su salida.') ?>
                                    </div>

                                    <div class="d-grid gap-3" id="merchandise-finalize-detail-list">
                                    <?php foreach ($items as $item): ?>
                                        <?php $remaining = (float) ($item['_remaining_quantity'] ?? 0); if ($remaining <= 0.0005) { continue; } $itemId = (int) ($item['id'] ?? 0); ?>
                                        <details class="merchandise-finalize-detail-card d-none" data-finalize-item-detail data-item-id="<?= $itemId ?>">
                                            <summary>
                                                <div class="flex-grow-1 min-w-0">
                                                    <div class="fw-semibold text-truncate"><?= expediente_h((string) ($item['descripcion'] ?? '')) ?></div>
                                                    <div class="small text-muted"><?= expediente_h((string) ($item['serial_number'] ?? '')) ?><?php $itemPedSummary = expediente_item_pedimentos($item); if ($itemPedSummary !== []): ?> · <?= expediente_h(implode(', ', array_map(static fn(array $p): string => trim(($p['key'] !== '' ? $p['key'] . ' ' : '') . $p['number']), $itemPedSummary))) ?><?php endif; ?></div>
                                                </div>
                                                <div class="d-flex flex-wrap gap-2 justify-content-end">
                                                    <span class="badge text-bg-light border text-dark"><?= expediente_h($currentLanguage === 'en' ? 'Available' : 'Disponible') ?>: <?= expediente_h(expediente_format_quantity($remaining)) ?></span>
                                                    <span class="badge text-bg-primary"><?= expediente_h($currentLanguage === 'en' ? 'Detail' : 'Detallar') ?></span>
                                                </div>
                                            </summary>
                                            <div class="merchandise-finalize-detail-body">
                                                <div class="row g-3 align-items-end mb-3">
                                                    <div class="col-md-4 col-lg-3">
                                                        <label class="form-label fw-semibold" for="merchandise-finalize-quantity-<?= $itemId ?>"><?= expediente_h($currentLanguage === 'en' ? 'Quantity leaving' : 'Cantidad que sale') ?></label>
                                                        <input class="form-control merchandise-finalize-quantity" id="merchandise-finalize-quantity-<?= $itemId ?>" type="number" min="0.001" step="0.001" max="<?= expediente_h(expediente_format_quantity($remaining)) ?>" value="<?= expediente_h(expediente_format_quantity($remaining)) ?>" data-max="<?= expediente_h(expediente_format_quantity($remaining)) ?>">
                                                    </div>
                                                    <div class="col-md-8 col-lg-9">
                                                        <div class="small text-muted">
                                                            <?= expediente_h($currentLanguage === 'en' ? 'Original' : 'Original') ?>: <strong><?= expediente_h(expediente_format_quantity((float) ($item['cantidad'] ?? 0))) ?></strong>
                                                            · <?= expediente_h($currentLanguage === 'en' ? 'Already out' : 'Ya salió') ?>: <strong><?= expediente_h(expediente_format_quantity((float) ($item['_finalized_quantity'] ?? 0))) ?></strong>
                                                            · <?= expediente_h($currentLanguage === 'en' ? 'Available' : 'Disponible') ?>: <strong><?= expediente_h(expediente_format_quantity($remaining)) ?></strong>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="row g-3 merchandise-finalize-customs-grid">
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="finalize-r1-add-<?= $itemId ?>"><?= expediente_h($currentLanguage === 'en' ? 'R1 CUSTOMS ENTRY TO ADD' : 'PEDIMENTO R1 PARA AGREGAR') ?></label>
                                                        <input class="form-control merchandise-customs-field" id="finalize-r1-add-<?= $itemId ?>" maxlength="150" data-customs-key="pedimento_r1_agregar">
                                                    </div>
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="finalize-h1-return-<?= $itemId ?>"><?= expediente_h($currentLanguage === 'en' ? 'H1 PARTIAL-RETURN CUSTOMS ENTRY' : 'PEDIMENTO RETORNO PARCIAL H1') ?></label>
                                                        <input class="form-control merchandise-customs-field" id="finalize-h1-return-<?= $itemId ?>" maxlength="150" data-customs-key="pedimento_retorno_parcial_h1">
                                                    </div>
                                                    <div class="col-md-6 col-xl">
                                                <label class="form-label fw-semibold" for="finalize-h1-merch-<?= $itemId ?>"><?= expediente_h($currentLanguage === 'en' ? 'MERCHANDISE UNDER H1 CUSTOMS ENTRY' : 'MERCANCÍA EN PEDIMENTO H1') ?></label>
                                                        <textarea class="form-control merchandise-customs-field" id="finalize-h1-merch-<?= $itemId ?>" rows="2" maxlength="4000" data-customs-key="mercancia_pedimento_h1"></textarea>
                                                    </div>
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="finalize-r1-split-<?= $itemId ?>"><?= expediente_h($currentLanguage === 'en' ? 'SPLIT R1 CUSTOMS ENTRY' : 'PEDIMENTO R1 DESAGREGADO') ?></label>
                                                        <input class="form-control merchandise-customs-field" id="finalize-r1-split-<?= $itemId ?>" maxlength="150" data-customs-key="pedimento_r1_desagregado">
                                                    </div>
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="finalize-a3-<?= $itemId ?>"><?= expediente_h($currentLanguage === 'en' ? 'A3 CUSTOMS ENTRY' : 'PEDIMENTO A3') ?></label>
                                                        <input class="form-control merchandise-customs-field" id="finalize-a3-<?= $itemId ?>" maxlength="150" data-customs-key="pedimento_a3">
                                                    </div>
                                                </div>

                                                <div class="d-flex justify-content-end mt-3">
                                                    <button class="btn btn-outline-secondary btn-sm merchandise-copy-customs" type="button" data-copy-customs-from="<?= $itemId ?>">
                                                        <?= expediente_h($currentLanguage === 'en' ? 'Copy these customs data to selected merchandise lines' : 'Copiar estos datos a las mercancías seleccionadas') ?>
                                                    </button>
                                                </div>
                                            </div>
                                        </details>
                                    <?php endforeach; ?>
                                    </div>
                                </section>

                                <div class="alert d-none mt-4 mb-0" id="merchandise-finalize-feedback" role="alert"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                                <button type="submit" class="btn btn-primary" id="merchandise-finalize-submit"><?= expediente_h($currentLanguage === 'en' ? 'Save departure' : 'Guardar finalización') ?></button>
                            </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php
            $partialOpenFinalizations = array_values(array_filter(
                $itemFinalizations,
                static fn(array $entry): bool => (string) ($entry['closure_status'] ?? 'complete') === 'partial'
                    && trim((string) ($entry['voided_at'] ?? '')) === ''
            ));
        ?>
        <?php if ($canEdit && $partialOpenFinalizations !== []): ?>
            <div class="modal fade" id="merchandiseFinalizationCompleteModal" tabindex="-1" aria-labelledby="merchandiseFinalizationCompleteModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
                    <form class="modal-content" id="merchandise-finalization-complete-form" novalidate>
                        <div class="modal-header">
                            <div>
                                <h2 class="modal-title fs-5" id="merchandiseFinalizationCompleteModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Complete customs documentation' : 'Completar pedimentos pendientes') ?></h2>
                                <p class="small text-muted mb-0 mt-1" id="merchandise-finalization-complete-label"></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning small">
                                <?= expediente_h($currentLanguage === 'en'
                                    ? 'Only the customs documentation will be completed. The departure date and quantities will not change or be counted again.'
                                    : 'Sólo se completará la documentación aduanal. La fecha y las cantidades de la salida no cambiarán ni volverán a contabilizarse.') ?>
                            </div>
                            <input type="hidden" id="merchandise-finalization-complete-id" value="">

                            <?php foreach ($partialOpenFinalizations as $partialFinalization): ?>
                                <?php $partialId = (int) ($partialFinalization['id'] ?? 0); ?>
                                <section class="d-none" data-completion-finalization="<?= $partialId ?>">
                                    <div class="d-grid gap-3">
                                        <?php foreach ((array) ($partialFinalization['lines'] ?? []) as $partialLine): ?>
                                            <?php $partialLineId = (int) ($partialLine['finalization_line_id'] ?? 0); ?>
                                            <div class="merchandise-finalization-line-card" data-completion-line data-line-id="<?= $partialLineId ?>">
                                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                                                    <div>
                                                        <div class="fw-semibold"><?= expediente_h((string) ($partialLine['descripcion'] ?? '')) ?></div>
                                                        <div class="small text-muted"><?= expediente_h((string) ($partialLine['serial_number'] ?? '')) ?></div>
                                                    </div>
                                                    <span class="badge text-bg-success align-self-start"><?= expediente_h(expediente_format_quantity((float) ($partialLine['quantity_exported'] ?? 0))) ?> <?= expediente_h($currentLanguage === 'en' ? 'already departed' : 'ya salieron') ?></span>
                                                </div>
                                                <div class="row g-3 merchandise-finalization-completion-grid">
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="complete-r1-add-<?= $partialLineId ?>"><?= expediente_h($currentLanguage === 'en' ? 'R1 CUSTOMS ENTRY TO ADD' : 'PEDIMENTO R1 PARA AGREGAR') ?></label>
                                                        <input class="form-control merchandise-completion-customs-field" id="complete-r1-add-<?= $partialLineId ?>" maxlength="150" data-customs-key="pedimento_r1_agregar" value="<?= expediente_h((string) ($partialLine['pedimento_r1_agregar'] ?? '')) ?>">
                                                    </div>
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="complete-h1-return-<?= $partialLineId ?>"><?= expediente_h($currentLanguage === 'en' ? 'H1 PARTIAL-RETURN CUSTOMS ENTRY' : 'PEDIMENTO RETORNO PARCIAL H1') ?></label>
                                                        <input class="form-control merchandise-completion-customs-field" id="complete-h1-return-<?= $partialLineId ?>" maxlength="150" data-customs-key="pedimento_retorno_parcial_h1" value="<?= expediente_h((string) ($partialLine['pedimento_retorno_parcial_h1'] ?? '')) ?>">
                                                    </div>
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="complete-h1-merch-<?= $partialLineId ?>"><?= expediente_h($currentLanguage === 'en' ? 'MERCHANDISE UNDER H1 CUSTOMS ENTRY' : 'MERCANCÍA EN PEDIMENTO H1') ?></label>
                                                        <textarea class="form-control merchandise-completion-customs-field" id="complete-h1-merch-<?= $partialLineId ?>" rows="2" maxlength="4000" data-customs-key="mercancia_pedimento_h1"><?= expediente_h((string) ($partialLine['mercancia_pedimento_h1'] ?? '')) ?></textarea>
                                                    </div>
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="complete-r1-split-<?= $partialLineId ?>"><?= expediente_h($currentLanguage === 'en' ? 'SPLIT R1 CUSTOMS ENTRY' : 'PEDIMENTO R1 DESAGREGADO') ?></label>
                                                        <input class="form-control merchandise-completion-customs-field" id="complete-r1-split-<?= $partialLineId ?>" maxlength="150" data-customs-key="pedimento_r1_desagregado" value="<?= expediente_h((string) ($partialLine['pedimento_r1_desagregado'] ?? '')) ?>">
                                                    </div>
                                                    <div class="col-md-6 col-xl">
                                                        <label class="form-label fw-semibold" for="complete-a3-<?= $partialLineId ?>"><?= expediente_h($currentLanguage === 'en' ? 'A3 CUSTOMS ENTRY' : 'PEDIMENTO A3') ?></label>
                                                        <input class="form-control merchandise-completion-customs-field" id="complete-a3-<?= $partialLineId ?>" maxlength="150" data-customs-key="pedimento_a3" value="<?= expediente_h((string) ($partialLine['pedimento_a3'] ?? '')) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>

                            <div class="alert d-none mt-3 mb-0" id="merchandise-finalization-complete-feedback" role="alert"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                            <button type="submit" class="btn btn-success" id="merchandise-finalization-complete-submit"><?= expediente_h($currentLanguage === 'en' ? 'Complete closure' : 'Completar cierre') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canEdit && $itemFinalizations !== []): ?>
            <div class="modal fade" id="merchandiseFinalizationVoidModal" tabindex="-1" aria-labelledby="merchandiseFinalizationVoidModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <form class="modal-content" id="merchandise-finalization-void-form" novalidate>
                        <div class="modal-header">
                            <div>
                                <h2 class="modal-title fs-5" id="merchandiseFinalizationVoidModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Void merchandise departure' : 'Anular salida de mercancía') ?></h2>
                                <p class="small text-muted mb-0 mt-1" id="merchandise-finalization-void-label"></p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning small">
                                <?= expediente_h($currentLanguage === 'en' ? 'The movement will not be deleted. It will remain in the audit trail and its quantities will return to the available storage balance.' : 'El movimiento no se eliminará. Permanecerá en el historial de auditoría y sus cantidades regresarán al saldo disponible en almacén.') ?>
                            </div>
                            <label class="form-label fw-semibold" for="merchandise-finalization-void-reason"><?= expediente_h($currentLanguage === 'en' ? 'Reason for voiding' : 'Motivo de la anulación') ?></label>
                            <textarea class="form-control" id="merchandise-finalization-void-reason" rows="3" maxlength="1000" required minlength="5"></textarea>
                            <input type="hidden" id="merchandise-finalization-void-id" value="">
                            <div class="alert d-none mt-3 mb-0" id="merchandise-finalization-void-feedback" role="alert"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button>
                            <button type="submit" class="btn btn-danger" id="merchandise-finalization-void-submit"><?= expediente_h($currentLanguage === 'en' ? 'Void movement' : 'Anular movimiento') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="modal fade" id="documentDetailsModal" tabindex="-1" aria-labelledby="documentDetailsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5" id="documentDetailsModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Document details' : 'Detalles del documento') ?></h2>
                            <p class="small text-muted mb-0 mt-1" id="document-details-name"></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 expediente-detail-grid" id="document-details-grid">
                            <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Type' : 'Tipo') ?></small><strong id="document-details-type">—</strong></div>
                            <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Status' : 'Estado') ?></small><strong id="document-details-status">—</strong></div>
                            <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Document date' : 'Fecha documento') ?></small><strong id="document-details-date">—</strong></div>
                            <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Uploaded' : 'Carga') ?></small><strong id="document-details-uploaded">—</strong></div>
                            <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Uploaded by' : 'Subido por') ?></small><strong id="document-details-user">—</strong></div>
                            <div class="col-md-6"><small><?= expediente_h($currentLanguage === 'en' ? 'Size / MIME' : 'Tamaño / MIME') ?></small><strong id="document-details-file">—</strong></div>
                            <div class="col-12"><small><?= expediente_h($currentLanguage === 'en' ? 'Description' : 'Descripción') ?></small><strong id="document-details-description">—</strong></div>
                            <div class="col-12"><small>SHA-256</small><code class="expediente-hash" id="document-details-hash">—</code></div>
                            <div class="col-12 d-none" id="document-details-replacement-wrap"><small><?= expediente_h($currentLanguage === 'en' ? 'Replacement chain' : 'Cadena de reemplazo') ?></small><strong id="document-details-replacement">—</strong></div>
                        </div>
                        <div class="alert d-none mt-3 mb-0" id="document-verify-feedback" role="status"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?></button>
                        <button type="button" class="btn btn-outline-success" id="document-verify-button"><?= expediente_h($currentLanguage === 'en' ? 'Verify integrity' : 'Verificar integridad') ?></button>
                        <a class="btn btn-primary" id="document-details-download" href="#"><?= expediente_h($currentLanguage === 'en' ? 'Download' : 'Descargar') ?></a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($pageError === '' && $userRole === 'admin' && $desembarqueId > 0): ?>
        <?php $administrativeNoticeNumber = $noticeNumber !== '' ? $noticeNumber : (string) ($record['referencia'] ?? ('#' . $desembarqueId)); ?>
        <?php if (! $isDeletedView): ?>
            <div class="modal fade" id="avisoSoftDeleteModal" tabindex="-1" aria-labelledby="avisoSoftDeleteModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form data-soft-delete-form="delete" data-notice-number="<?= expediente_h($administrativeNoticeNumber) ?>" novalidate>
                            <div class="modal-header"><h2 class="modal-title fs-5" id="avisoSoftDeleteModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Delete notice' : 'Eliminar aviso') ?> <?= expediente_h($administrativeNoticeNumber) ?></h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button></div>
                            <div class="modal-body">
                                <input type="hidden" name="desembarque_id" value="<?= (int) $desembarqueId ?>">
                                <input type="hidden" name="csrf_token" value="<?= expediente_h($csrfToken) ?>">
                                <div class="alert alert-warning small"><strong><?= expediente_h($currentLanguage === 'en' ? 'Administrative removal only.' : 'Sólo baja lógica administrativa.') ?></strong> <?= expediente_h($currentLanguage === 'en' ? 'The notice will disappear from operational views, KPIs, reports, exports, the client portal, and operational APIs. Its complete documentary record will remain intact.' : 'El aviso desaparecerá de vistas operativas, KPIs, reportes, exportaciones, portal de clientes y APIs. Todo su árbol documental permanecerá intacto.') ?></div>
                                <label class="form-label fw-semibold" for="soft-delete-reason"><?= expediente_h($currentLanguage === 'en' ? 'Deletion reason' : 'Motivo de eliminación') ?></label>
                                <textarea class="form-control" id="soft-delete-reason" name="reason" maxlength="500" rows="3" required></textarea>
                                <label class="form-label fw-semibold mt-3" for="soft-delete-confirmation"><?= expediente_h($currentLanguage === 'en' ? 'Written confirmation' : 'Confirmación escrita') ?></label>
                                <div class="form-text mb-2"><?= expediente_h($currentLanguage === 'en' ? 'Type exactly:' : 'Escribe exactamente:') ?> <code data-soft-delete-confirmation-example><?= expediente_h($currentLanguage === 'en' ? 'DELETE' : 'ELIMINAR') ?> <?= expediente_h($administrativeNoticeNumber) ?></code></div>
                                <input class="form-control" id="soft-delete-confirmation" name="confirmation" autocomplete="off" required>
                                <div class="alert d-none mt-3 mb-0" data-soft-delete-feedback role="status"></div>
                            </div>
                            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button><button class="btn btn-danger" type="submit"><?= expediente_h($currentLanguage === 'en' ? 'Delete notice' : 'Eliminar aviso') ?></button></div>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="modal fade" id="avisoRestoreModal" tabindex="-1" aria-labelledby="avisoRestoreModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form data-soft-delete-form="restore" data-notice-number="<?= expediente_h($administrativeNoticeNumber) ?>" novalidate>
                            <div class="modal-header"><h2 class="modal-title fs-5" id="avisoRestoreModalLabel"><?= expediente_h($currentLanguage === 'en' ? 'Restore notice' : 'Restaurar aviso') ?> <?= expediente_h($administrativeNoticeNumber) ?></h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="<?= expediente_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button></div>
                            <div class="modal-body">
                                <input type="hidden" name="desembarque_id" value="<?= (int) $desembarqueId ?>">
                                <input type="hidden" name="csrf_token" value="<?= expediente_h($csrfToken) ?>">
                                <div class="alert alert-info small"><?= expediente_h($currentLanguage === 'en' ? 'The same record will return to all operational views. No copy will be created.' : 'El mismo registro volverá a todas las vistas operativas. No se creará ninguna copia.') ?></div>
                                <label class="form-label fw-semibold" for="restore-reason"><?= expediente_h($currentLanguage === 'en' ? 'Restore reason' : 'Motivo de restauración') ?></label>
                                <textarea class="form-control" id="restore-reason" name="reason" maxlength="500" rows="3" required></textarea>
                                <label class="form-label fw-semibold mt-3" for="restore-confirmation"><?= expediente_h($currentLanguage === 'en' ? 'Written confirmation' : 'Confirmación escrita') ?></label>
                                <div class="form-text mb-2"><?= expediente_h($currentLanguage === 'en' ? 'Type exactly:' : 'Escribe exactamente:') ?> <code data-soft-delete-confirmation-example><?= expediente_h($currentLanguage === 'en' ? 'RESTORE' : 'RESTAURAR') ?> <?= expediente_h($administrativeNoticeNumber) ?></code></div>
                                <input class="form-control" id="restore-confirmation" name="confirmation" autocomplete="off" required>
                                <div class="alert d-none mt-3 mb-0" data-soft-delete-feedback role="status"></div>
                            </div>
                            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><?= expediente_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button><button class="btn btn-success" type="submit"><?= expediente_h($currentLanguage === 'en' ? 'Restore notice' : 'Restaurar aviso') ?></button></div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/footer.php'; ?>
    <script>
        window.ReportThemeConfig = {
            theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
        };
        window.AppConfig = Object.assign({}, window.ReportThemeConfig, {
            language: <?= json_encode($currentLanguage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            avisoExpediente: {
                desembarqueId: <?= (int) $desembarqueId ?>,
                csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                canEditDocuments: <?= $canEdit ? 'true' : 'false' ?>,
                viewDeleted: <?= $isDeletedView ? 'true' : 'false' ?>,
                canFinalizeMerchandise: <?= $canFinalizeMerchandise ? 'true' : 'false' ?>,
                currentNoticeStatus: <?= json_encode($noticeStatusSlug, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                statusEndpoint: '../api/desembarques/aviso/status.php',
                merchandiseFinalizeEndpoint: '../api/desembarques/aviso/merchandise_finalize.php',
                merchandiseFinalizationCompleteEndpoint: '../api/desembarques/aviso/merchandise_finalization_complete.php',
                merchandiseFinalizationVoidEndpoint: '../api/desembarques/aviso/merchandise_finalization_void.php',
                alcances: {
                    enabled: <?= ($canEdit && $items !== []) || $alcances !== [] ? 'true' : 'false' ?>,
                    nextAlcanceNo: <?= (int) $nextAlcanceNo ?>,
                    templateUrl: 'assets/pdf/aviso-desembarque-plantilla.pdf',
                    generateEndpoint: '../api/desembarques/aviso/alcance_generate.php',
                    versionEndpoint: '../api/desembarques/aviso/alcance_version.php',
                    receiptEndpoint: '../api/desembarques/aviso/alcance_receipt.php',
                    documentCode: <?= json_encode((string) ($detail['document_code'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    noticeNumber: <?= json_encode((string) ($detail['notice_number'] ?? $detail['manifiesto'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    detail: <?= json_encode([
                        'manifiesto' => (string) ($detail['manifiesto'] ?? ''),
                        'medio_transporte' => (string) ($detail['medio_transporte'] ?? ''),
                        'imo_transporte' => (string) ($detail['imo_transporte'] ?? ''),
                        'consignataria' => (string) ($detail['consignataria'] ?? ''),
                        'fecha_embarque' => (string) ($detail['fecha_embarque'] ?? ''),
                        'lugar_desembarque' => (string) ($detail['lugar_desembarque'] ?? ''),
                        'fecha_desembarque_eta' => (string) ($detail['fecha_desembarque_eta'] ?? ''),
                        'domicilio_almacenamiento' => (string) ($detail['domicilio_almacenamiento'] ?? ''),
                        'domicilio_reparacion' => (string) ($detail['domicilio_reparacion'] ?? ''),
                        'rig_name' => (string) ($detail['rig_name'] ?? ''),
                        'rig_imo' => (string) ($detail['rig_imo'] ?? ''),
                        'rig_field' => (string) ($detail['rig_field'] ?? ''),
                        'rig_area' => (string) ($detail['rig_area'] ?? ''),
                        'comitente' => (string) ($detail['comitente'] ?? ''),
                        'recipient_text' => (string) ($detail['recipient_text'] ?? ''),
                        'introduction' => (string) ($detail['introduction'] ?? ''),
                        'body' => (string) ($detail['body'] ?? ''),
                        'operations' => (string) ($detail['operations'] ?? ''),
                        'closing_text' => (string) ($detail['closing_text'] ?? ''),
                        'signer_name' => (string) ($detail['signer_name'] ?? ''),
                        'signer_title' => (string) ($detail['signer_title'] ?? ''),
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                    items: <?= json_encode(array_map(static function (array $item): array {
                        return [
                            'id' => (int) ($item['id'] ?? 0),
                            'source_row' => $item['source_row'] ?? null,
                            'sort_order' => (int) ($item['sort_order'] ?? 0),
                            'descripcion' => (string) ($item['descripcion'] ?? ''),
                            'serial_number' => (string) ($item['serial_number'] ?? ''),
                            'marca' => (string) ($item['marca'] ?? ''),
                            'clave' => (string) ($item['clave'] ?? ''),
                            'num_pedimento' => (string) ($item['num_pedimento'] ?? ''),
                            'partida' => (string) ($item['partida'] ?? ''),
                            'cantidad' => (float) ($item['cantidad'] ?? 0),
                            'importer_name' => (string) ($item['importer_name'] ?? ''),
                            'pedimentos' => expediente_item_pedimentos($item),
                        ];
                    }, $items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                    photos: <?= json_encode(array_map(static function (array $photo): array {
                        $fileId = (int) ($photo['file_id'] ?? 0);
                        return [
                            'file_id' => $fileId,
                            'aviso_item_id' => isset($photo['aviso_item_id']) ? (int) $photo['aviso_item_id'] : null,
                            'caption' => (string) ($photo['caption'] ?? ''),
                            'original_name' => (string) ($photo['original_name'] ?? ''),
                            'preview_url' => '../api/desembarques/aviso/image.php?file_id=' . $fileId . $deletedQuerySuffix,
                            'download_url' => '../api/desembarques/files/download.php?id=' . $fileId . $deletedQuerySuffix,
                        ];
                    }, $photos), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
                },
                photos: {
                    enabled: <?= $canEdit ? 'true' : 'false' ?>,
                    uploadEndpoint: '../api/desembarques/aviso/photo_upload.php',
                    deleteEndpoint: '../api/desembarques/aviso/photo_delete.php',
                    maxFiles: 20,
                    maxFileSizeMb: 25,
                    items: <?= json_encode(array_map(static function (array $item): array {
                        return [
                            'id' => (int) ($item['id'] ?? 0),
                            'descripcion' => (string) ($item['descripcion'] ?? ''),
                        ];
                    }, $items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
                },
                uploadEndpoint: '../api/desembarques/files/document_upload.php',
                replaceEndpoint: '../api/desembarques/files/document_replace.php',
                verifyEndpoint: '../api/desembarques/files/document_verify.php',
                downloadEndpoint: '../api/desembarques/files/download.php'
            }
        });
        window.AvisoSoftDeleteConfig = {
            csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            deleteEndpoint: '../api/desembarques/admin/soft_delete.php',
            restoreEndpoint: '../api/desembarques/admin/restore.php',
            deleteRedirect: 'aviso-papelera.php?focus=<?= (int) $desembarqueId ?>',
            restoreRedirect: 'aviso-expediente.php?id=<?= (int) $desembarqueId ?>'
        };
    </script>
    <?php
        $includeSweetAlert = false;
        $pageScripts = [
            ['src' => 'https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js'],
            'assets/js/theme.js',
            'assets/js/aviso-pdf.js?v=42',
            'assets/js/alcance-pdf.js?v=42',
            'assets/js/aviso-expediente-documents.js',
            'assets/js/aviso-expediente-photos.js?v=42',
            'assets/js/aviso-expediente-status.js',
            'assets/js/aviso-expediente-merchandise.js?v=43',
            'assets/js/aviso-expediente-alcances.js?v=42'
        ];
        if ($userRole === 'admin') {
            $pageScripts[] = 'assets/js/aviso-soft-delete.js?v=42';
        }
        require __DIR__ . '/partials/scripts.php';
    ?>
</body>
</html>
