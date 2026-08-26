<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/HistoricalAvisoExtractor.php';

if ($argc < 3) {
    fwrite(STDERR, "Uso: php tools/compare_historical_word_pair.php archivo.doc archivo.docx\n");
    exit(2);
}

$doc = $argv[1];
$docx = $argv[2];
foreach ([$doc, $docx] as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "No existe: {$path}\n");
        exit(2);
    }
}

/** @return array<string,mixed> */
function pair_extract(string $path): array
{
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $result = HistoricalAvisoExtractor::extractWord($path, basename($path), $extension);
    $parser = is_array($result['parser'] ?? null) ? $result['parser'] : [];
    $data = is_array($parser['data'] ?? null) ? $parser['data'] : [];
    $items = isset($data['items']) && is_array($data['items']) ? array_values($data['items']) : [];
    $pieces = 0.0;
    foreach ($items as $item) {
        if (is_array($item) && is_numeric($item['quantity'] ?? null)) {
            $pieces += (float) $item['quantity'];
        }
    }
    return [
        'driver' => $result['driver'] ?? null,
        'error' => $result['error'] ?? null,
        'tables' => $result['tables'] ?? [],
        'data' => $data,
        'items' => $items,
        'item_count' => count($items),
        'piece_count' => $pieces,
    ];
}

/** @param array<string,mixed> $item */
function pair_item_key(array $item): string
{
    $descriptionRaw = trim((string) ($item['description'] ?? ''));
    $serialRaw = trim((string) ($item['serial_number'] ?? ''));
    $description = function_exists('mb_strtoupper') ? mb_strtoupper($descriptionRaw, 'UTF-8') : strtoupper($descriptionRaw);
    $serial = function_exists('mb_strtoupper') ? mb_strtoupper($serialRaw, 'UTF-8') : strtoupper($serialRaw);
    $pedimento = preg_replace('/\D+/', '', (string) ($item['pedimento'] ?? '')) ?? '';
    $quantity = number_format((float) ($item['quantity'] ?? 0), 3, '.', '');
    return $description . '|' . $serial . '|' . $pedimento . '|' . $quantity;
}

$left = pair_extract($doc);
$right = pair_extract($docx);

$checks = [];
$critical = ['notice_number', 'document_code', 'office_date', 'rig_name', 'rig_imo', 'rig_field', 'transport_name', 'transport_imo', 'shipping_date', 'landing_datetime'];
foreach ($critical as $field) {
    $a = $left['data'][$field] ?? null;
    $b = $right['data'][$field] ?? null;
    $checks[] = [
        'field' => $field,
        'pass' => $a === $b,
        'doc' => $a,
        'docx' => $b,
    ];
}

$checks[] = ['field' => 'item_count', 'pass' => $left['item_count'] === $right['item_count'], 'doc' => $left['item_count'], 'docx' => $right['item_count']];
$checks[] = ['field' => 'piece_count', 'pass' => abs((float) $left['piece_count'] - (float) $right['piece_count']) < 0.0001, 'doc' => $left['piece_count'], 'docx' => $right['piece_count']];

$leftItems = array_map('pair_item_key', array_filter($left['items'], 'is_array'));
$rightItems = array_map('pair_item_key', array_filter($right['items'], 'is_array'));
$checks[] = ['field' => 'items_equivalent', 'pass' => $leftItems === $rightItems, 'doc' => $leftItems, 'docx' => $rightItems];

$failed = array_values(array_filter($checks, static fn(array $check): bool => ! ($check['pass'] ?? false)));

$output = [
    'result' => $failed === [] ? 'PASS' : 'FAIL',
    'doc' => [
        'driver' => $left['driver'],
        'error' => $left['error'],
        'item_count' => $left['item_count'],
        'piece_count' => $left['piece_count'],
    ],
    'docx' => [
        'driver' => $right['driver'],
        'error' => $right['error'],
        'tables' => $right['tables'],
        'item_count' => $right['item_count'],
        'piece_count' => $right['piece_count'],
    ],
    'checks' => $checks,
];

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 1);
