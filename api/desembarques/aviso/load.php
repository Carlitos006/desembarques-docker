<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    aviso_json(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$user = aviso_require_user();
$desembarqueIdRaw = trim((string) ($_GET['desembarque_id'] ?? ''));

if ($desembarqueIdRaw === '' || ! ctype_digit($desembarqueIdRaw)) {
    aviso_json(422, ['success' => false, 'message' => 'El desembarque indicado no es válido.']);
}

$desembarqueId = (int) $desembarqueIdRaw;
$connection = getDatabaseConnection();
aviso_require_record_access($connection, $desembarqueId, $user);

$detailResult = $connection->execute_query(
    'SELECT * FROM desembarque_aviso_details WHERE desembarque_id = ? LIMIT 1',
    [$desembarqueId]
);
$detail = $detailResult instanceof mysqli_result ? $detailResult->fetch_assoc() : null;

// La BD usa aviso_profile_id; el contrato JSON conserva profile_id para no acoplar el frontend al nombre físico.
if (is_array($detail)) {
    $detail['profile_id'] = isset($detail['aviso_profile_id']) && $detail['aviso_profile_id'] !== null
        ? (int) $detail['aviso_profile_id']
        : null;
}

$itemsResult = $connection->execute_query(
    'SELECT id, source_row, sort_order, item_no, unidad, descripcion, serial_number, marca, clave, num_pedimento, partida, cantidad, importer_name '
    . 'FROM desembarque_aviso_items WHERE desembarque_id = ? ORDER BY sort_order ASC, id ASC',
    [$desembarqueId]
);

$items = [];
if ($itemsResult instanceof mysqli_result) {
    while ($row = $itemsResult->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['source_row'] = $row['source_row'] !== null ? (int) $row['source_row'] : null;
        $row['sort_order'] = (int) $row['sort_order'];
        $row['cantidad'] = $row['cantidad'] !== null ? (float) $row['cantidad'] : null;
        $items[] = $row;
    }
}

$itemPedimentos = [];
$itemPedimentosResult = $connection->execute_query(
    'SELECT ip.aviso_item_id, ip.clave, ip.num_pedimento, ip.importer_name, ip.sort_order '
    . 'FROM desembarque_aviso_item_pedimentos ip '
    . 'INNER JOIN desembarque_aviso_items i ON i.id = ip.aviso_item_id '
    . 'WHERE i.desembarque_id = ? ORDER BY ip.aviso_item_id ASC, ip.sort_order ASC, ip.id ASC',
    [$desembarqueId]
);
if ($itemPedimentosResult instanceof mysqli_result) {
    while ($relation = $itemPedimentosResult->fetch_assoc()) {
        $itemId = (int) ($relation['aviso_item_id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $itemPedimentos[$itemId][] = [
            'key' => (string) ($relation['clave'] ?? ''),
            'number' => (string) ($relation['num_pedimento'] ?? ''),
            'importer_name' => (string) ($relation['importer_name'] ?? ''),
            'sort_order' => (int) ($relation['sort_order'] ?? 0),
        ];
    }
}
foreach ($items as &$item) {
    $itemId = (int) ($item['id'] ?? 0);
    $relations = $itemPedimentos[$itemId] ?? [];
    if ($relations === [] && trim((string) ($item['num_pedimento'] ?? '')) !== '') {
        $relations[] = [
            'key' => (string) ($item['clave'] ?? ''),
            'number' => (string) ($item['num_pedimento'] ?? ''),
            'importer_name' => (string) ($item['importer_name'] ?? ''),
            'sort_order' => 1,
        ];
    }
    $item['pedimentos'] = $relations;
}
unset($item);

$photosResult = $connection->execute_query(
    'SELECT ai.id, ai.file_id, ai.aviso_item_id, ai.caption, ai.sort_order, '
    . 'i.source_row AS item_source_row, i.descripcion AS item_description, '
    . 'f.original_name, f.mime_type, f.extension, f.size '
    . 'FROM desembarque_aviso_images ai '
    . 'INNER JOIN desembarque_files f ON f.id = ai.file_id AND f.desembarque_id = ai.desembarque_id '
    . 'LEFT JOIN desembarque_aviso_items i ON i.id = ai.aviso_item_id AND i.desembarque_id = ai.desembarque_id '
    . 'WHERE ai.desembarque_id = ? '
    . 'ORDER BY ai.sort_order ASC, ai.id ASC',
    [$desembarqueId]
);

$photos = [];
if ($photosResult instanceof mysqli_result) {
    while ($row = $photosResult->fetch_assoc()) {
        $fileId = (int) ($row['file_id'] ?? 0);
        if ($fileId <= 0) {
            continue;
        }

        $photos[] = [
            'id' => isset($row['id']) ? (int) $row['id'] : 0,
            'file_id' => $fileId,
            'aviso_item_id' => isset($row['aviso_item_id']) && $row['aviso_item_id'] !== null ? (int) $row['aviso_item_id'] : null,
            'item_source_row' => isset($row['item_source_row']) && $row['item_source_row'] !== null ? (int) $row['item_source_row'] : null,
            'item_description' => (string) ($row['item_description'] ?? ''),
            'caption' => (string) ($row['caption'] ?? ''),
            'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
            'original_name' => (string) ($row['original_name'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'extension' => (string) ($row['extension'] ?? ''),
            'size' => isset($row['size']) ? (int) $row['size'] : 0,
            'preview_url' => '../api/desembarques/aviso/image.php?file_id=' . $fileId,
            'download_url' => '../api/desembarques/files/download.php?id=' . $fileId,
        ];
    }
}

aviso_json(200, [
    'success' => true,
    'aviso' => $detail,
    'items' => $items,
    'photos' => $photos,
    'status' => aviso_status_response_payload($connection, $desembarqueId, $user),
]);
