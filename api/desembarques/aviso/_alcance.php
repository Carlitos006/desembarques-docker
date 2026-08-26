<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../config/files.php';

function alcance_safe_component(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'alcance';
    }

    $normalized = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    if (! is_string($normalized) || $normalized === '') {
        $normalized = $value;
    }

    $normalized = strtolower($normalized);
    $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?? '';
    $normalized = trim($normalized, '-_');

    return $normalized !== '' ? $normalized : 'alcance';
}

function alcance_public_id(): string
{
    return bin2hex(random_bytes(16));
}

function alcance_clean_date(mixed $value): ?string
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    if (! ($date instanceof DateTimeImmutable) || $date->format('Y-m-d') !== $text) {
        return null;
    }

    return $date->format('Y-m-d');
}

function alcance_clean_received_at(mixed $value): ?string
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $text = str_replace('T', ' ', $text);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $text);
        if ($date instanceof DateTimeImmutable && $date->format($format) === $text) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    return null;
}

/** @return array<string,mixed>|null */
function alcance_load(mysqli $connection, int $alcanceId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT a.*, d.client_id, d.cliente, ad.manifiesto, ad.rig_name, ad.rig_imo, ad.rig_field, ad.rig_area, ad.comitente '
        . 'FROM desembarque_aviso_alcances a '
        . 'INNER JOIN desembarques d ON d.id = a.desembarque_id '
        . 'LEFT JOIN desembarque_aviso_details ad ON ad.desembarque_id = a.desembarque_id '
        . 'WHERE a.id = ? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $result = $connection->execute_query($sql, [$alcanceId]);
    return $result instanceof mysqli_result ? ($result->fetch_assoc() ?: null) : null;
}

/** @return array<int,array<string,mixed>> */
function alcance_load_items(mysqli $connection, int $alcanceId): array
{
    $rows = [];
    $result = $connection->execute_query(
        'SELECT ai.sort_order AS alcance_sort_order, i.* '
        . 'FROM desembarque_aviso_alcance_items ai '
        . 'INNER JOIN desembarque_aviso_items i ON i.id = ai.aviso_item_id '
        . 'WHERE ai.alcance_id = ? ORDER BY ai.sort_order ASC, ai.id ASC',
        [$alcanceId]
    );
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['pedimentos'] = [];
            $relationResult = $connection->execute_query(
                'SELECT clave, num_pedimento, importer_name, sort_order FROM desembarque_aviso_item_pedimentos '
                . 'WHERE aviso_item_id = ? ORDER BY sort_order ASC, id ASC',
                [(int) ($row['id'] ?? 0)]
            );
            if ($relationResult instanceof mysqli_result) {
                while ($relation = $relationResult->fetch_assoc()) {
                    $row['pedimentos'][] = [
                        'key' => (string) ($relation['clave'] ?? ''),
                        'number' => (string) ($relation['num_pedimento'] ?? ''),
                        'importer_name' => (string) ($relation['importer_name'] ?? ''),
                    ];
                }
            }
            if ($row['pedimentos'] === [] && trim((string) ($row['num_pedimento'] ?? '')) !== '') {
                $row['pedimentos'][] = [
                    'key' => (string) ($row['clave'] ?? ''),
                    'number' => (string) ($row['num_pedimento'] ?? ''),
                    'importer_name' => (string) ($row['importer_name'] ?? ''),
                ];
            }
            $rows[] = $row;
        }
    }
    return $rows;
}

/** @return array<int,array<string,mixed>> */
function alcance_load_photos_for_items(mysqli $connection, int $desembarqueId, array $itemIds): array
{
    $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
    if ($itemIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $params = array_merge([$desembarqueId], $itemIds);
    $result = $connection->execute_query(
        'SELECT ai.id, ai.file_id, ai.aviso_item_id, ai.caption, ai.sort_order, '
        . 'f.original_name, f.mime_type, f.extension, f.size, i.descripcion AS item_description '
        . 'FROM desembarque_aviso_images ai '
        . 'INNER JOIN desembarque_files f ON f.id = ai.file_id '
        . 'LEFT JOIN desembarque_aviso_items i ON i.id = ai.aviso_item_id '
        . 'WHERE ai.desembarque_id = ? AND (ai.aviso_item_id IN (' . $placeholders . ') OR ai.aviso_item_id IS NULL) '
        . 'ORDER BY ai.sort_order ASC, ai.id ASC',
        $params
    );

    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** @param mixed $value */
function alcance_canonicalize_json_value($value)
{
    if (! is_array($value)) {
        return $value;
    }

    $isList = array_keys($value) === range(0, count($value) - 1);
    if ($isList) {
        return array_map('alcance_canonicalize_json_value', $value);
    }

    ksort($value, SORT_STRING);
    foreach ($value as $key => $nested) {
        $value[$key] = alcance_canonicalize_json_value($nested);
    }
    return $value;
}

/** @return array<string,mixed> */
function alcance_build_snapshot(mysqli $connection, int $alcanceId): array
{
    $alcance = alcance_load($connection, $alcanceId, false);
    if (! $alcance) {
        throw new RuntimeException('No se encontró el alcance para construir su snapshot.');
    }

    $desembarqueId = (int) ($alcance['desembarque_id'] ?? 0);
    $recordResult = $connection->execute_query(
        'SELECT d.*, c.name AS client_name FROM desembarques d LEFT JOIN clients c ON c.id = d.client_id WHERE d.id = ? AND d.deleted_at IS NULL LIMIT 1',
        [$desembarqueId]
    );
    $record = $recordResult instanceof mysqli_result ? ($recordResult->fetch_assoc() ?: []) : [];

    $detailResult = $connection->execute_query(
        'SELECT * FROM desembarque_aviso_details WHERE desembarque_id = ? LIMIT 1',
        [$desembarqueId]
    );
    $detail = $detailResult instanceof mysqli_result ? ($detailResult->fetch_assoc() ?: []) : [];

    $items = alcance_load_items($connection, $alcanceId);
    $itemIds = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $items);
    $photos = alcance_load_photos_for_items($connection, $desembarqueId, $itemIds);

    return [
        'schema' => 'alcance.v1',
        'captured_at' => gmdate('c'),
        'alcance' => $alcance,
        'desembarque' => $record,
        'aviso' => $detail,
        'items' => $items,
        'photos' => $photos,
    ];
}

/** @return array{json:string,sha256:string} */
function alcance_encode_snapshot(array $snapshot): array
{
    $canonical = alcance_canonicalize_json_value($snapshot);
    $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return ['json' => $json, 'sha256' => hash('sha256', $json)];
}

/** @return array<string,mixed> */
function alcance_version_response(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'alcance_id' => (int) ($row['alcance_id'] ?? 0),
        'version_no' => (int) ($row['version_no'] ?? 0),
        'item_count' => (int) ($row['item_count'] ?? 0),
        'photo_count' => (int) ($row['photo_count'] ?? 0),
        'page_count' => (int) ($row['page_count'] ?? 0),
        'size' => (int) ($row['size'] ?? 0),
        'pdf_sha256' => (string) ($row['pdf_sha256'] ?? ''),
        'generated_by_name' => (string) ($row['generated_by_name'] ?? ''),
        'generated_at' => (string) ($row['generated_at'] ?? ''),
        'download_url' => '../api/desembarques/aviso/alcance_version_download.php?id=' . (int) ($row['id'] ?? 0),
    ];
}
