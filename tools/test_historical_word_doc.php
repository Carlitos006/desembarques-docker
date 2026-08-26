<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/HistoricalAvisoExtractor.php';

if ($argc < 2) {
    fwrite(STDERR, "Uso: php tools/test_historical_word_doc.php /ruta/archivo.doc\n");
    exit(2);
}

$path = $argv[1];
if (! is_file($path)) {
    fwrite(STDERR, "No existe: {$path}\n");
    exit(2);
}

$extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
if (! in_array($extension, ['doc', 'docx'], true)) {
    fwrite(STDERR, "El archivo debe ser .doc o .docx\n");
    exit(2);
}

$result = HistoricalAvisoExtractor::extractWord($path, basename($path), $extension);
$parser = is_array($result['parser'] ?? null) ? $result['parser'] : [];
$data = is_array($parser['data'] ?? null) ? $parser['data'] : [];
$items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
$pieces = 0.0;
foreach ($items as $item) {
    if (is_array($item) && is_numeric($item['quantity'] ?? null)) {
        $pieces += (float) $item['quantity'];
    }
}

echo json_encode([
    'driver' => $result['driver'] ?? null,
    'error' => $result['error'] ?? null,
    'tables' => $result['tables'] ?? [],
    'notice_number' => $data['notice_number'] ?? null,
    'document_code' => $data['document_code'] ?? null,
    'office_date' => $data['office_date'] ?? null,
    'rig_name' => $data['rig_name'] ?? null,
    'rig_imo' => $data['rig_imo'] ?? null,
    'rig_field' => $data['rig_field'] ?? null,
    'rig_area' => $data['rig_area'] ?? null,
    'transport_name' => $data['transport_name'] ?? null,
    'transport_imo' => $data['transport_imo'] ?? null,
    'shipping_date' => $data['shipping_date'] ?? null,
    'landing_datetime' => $data['landing_datetime'] ?? null,
    'pedimentos' => $data['pedimentos'] ?? [],
    'item_count' => count($items),
    'piece_count' => $pieces,
    'items' => $items,
    'critical_score' => $parser['critical_score'] ?? null,
    'overall_confidence' => $parser['overall_confidence'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
