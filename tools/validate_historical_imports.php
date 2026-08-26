<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este diagnóstico sólo puede ejecutarse por CLI.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/api/desembarques/aviso/import/_bootstrap.php';

$options = getopt('', ['batch::', 'all', 'json']);
$batchPublicId = isset($options['batch']) ? trim((string) $options['batch']) : '';
$all = array_key_exists('all', $options);
$json = array_key_exists('json', $options);

if ($batchPublicId === '' && ! $all) {
    fwrite(STDERR, "Uso:\n  php tools/validate_historical_imports.php --batch=<public_id> [--json]\n  php tools/validate_historical_imports.php --all [--json]\n");
    exit(2);
}

$connection = getDatabaseConnection();
$reports = [];

if ($batchPublicId !== '') {
    if (! preg_match('/^[a-f0-9]{32}$/', $batchPublicId)) {
        fwrite(STDERR, "public_id de lote inválido.\n");
        exit(2);
    }
    $result = $connection->execute_query('SELECT * FROM aviso_import_batches WHERE public_id = ? LIMIT 1', [$batchPublicId]);
    $batch = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if (! $batch) {
        fwrite(STDERR, "No se encontró el lote.\n");
        exit(2);
    }
    $reports[] = aviso_import_validate_batch($connection, $batch);
} else {
    $result = $connection->query("SELECT DISTINCT b.* FROM aviso_import_batches b INNER JOIN aviso_import_rows r ON r.batch_id = b.id WHERE r.commit_status = 'imported' ORDER BY b.id ASC");
    if ($result instanceof mysqli_result) {
        while ($batch = $result->fetch_assoc()) {
            $reports[] = aviso_import_validate_batch($connection, $batch);
        }
    }
}

$globalPass = true;
foreach ($reports as $report) {
    if (! ($report['pass'] ?? false)) {
        $globalPass = false;
        break;
    }
}

if ($json) {
    echo json_encode(['pass' => $globalPass, 'reports' => $reports], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($globalPass ? 0 : 1);
}

foreach ($reports as $report) {
    $publicId = (string) ($report['batch']['public_id'] ?? '');
    $summary = $report['summary'] ?? [];
    echo "\n=== LOTE {$publicId} ===\n";
    echo ($report['pass'] ? 'PASS' : 'FAIL')
        . ' · avisos=' . (int) ($summary['rows'] ?? 0)
        . ' · pass=' . (int) ($summary['pass'] ?? 0)
        . ' · warn=' . (int) ($summary['warn'] ?? 0)
        . ' · fail=' . (int) ($summary['fail'] ?? 0)
        . ' · checks=' . (int) ($summary['checks'] ?? 0)
        . PHP_EOL;
    foreach (($report['rows'] ?? []) as $row) {
        $status = strtoupper((string) ($row['status'] ?? 'N/D'));
        echo sprintf("  %-5s %-10s expediente=%s versión=%s\n",
            $status,
            (string) ($row['notice_number'] ?? ('#' . ($row['row_id'] ?? '?'))),
            (string) ($row['desembarque_id'] ?? '-'),
            (string) ($row['version_id'] ?? '-')
        );
        foreach (($row['checks'] ?? []) as $check) {
            if ((string) ($check['status'] ?? '') === 'pass') {
                continue;
            }
            echo '        ' . strtoupper((string) ($check['status'] ?? '')) . ' · ' . (string) ($check['label'] ?? $check['code'] ?? '') . ': ' . (string) ($check['detail'] ?? '') . PHP_EOL;
        }
    }
}

echo "\nRESULTADO GLOBAL: " . ($globalPass ? 'PASS' : 'FAIL') . PHP_EOL;
exit($globalPass ? 0 : 1);
