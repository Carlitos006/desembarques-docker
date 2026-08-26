<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/HistoricalAvisoExtractor.php';

if ($argc < 2) {
    fwrite(STDERR, "Uso: php tools/test_historical_aviso_parser.php archivo.pdf|archivo.docx\n");
    exit(2);
}

$path = $argv[1];
if (! is_file($path)) {
    fwrite(STDERR, "No existe: {$path}\n");
    exit(2);
}

$extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
if ($extension === 'pdf') {
    $result = HistoricalAvisoExtractor::extractPdf($path, basename($path));
} elseif ($extension === 'docx') {
    $result = HistoricalAvisoExtractor::extractDocx($path, basename($path));
} else {
    fwrite(STDERR, "Formato no soportado.\n");
    exit(2);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
