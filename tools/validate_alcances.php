<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/files.php';

$connection = getDatabaseConnection();
$checks = [];
$failures = 0;

$requiredTables = [
    'desembarque_aviso_alcances',
    'desembarque_aviso_alcance_items',
    'desembarque_aviso_alcance_versions',
    'desembarque_aviso_alcance_receipts',
];

foreach ($requiredTables as $table) {
    $result = $connection->query("SHOW TABLES LIKE '" . $connection->real_escape_string($table) . "'");
    $ok = $result instanceof mysqli_result && $result->num_rows === 1;
    $checks[] = [$ok, 'Tabla ' . $table];
    if (! $ok) {
        $failures++;
    }
}

if ($failures === 0) {
    $result = $connection->query(
        'SELECT a.id, a.alcance_no, a.status, '
        . 'COUNT(DISTINCT ai.id) AS item_count, COUNT(DISTINCT v.id) AS version_count '
        . 'FROM desembarque_aviso_alcances a '
        . 'LEFT JOIN desembarque_aviso_alcance_items ai ON ai.alcance_id = a.id '
        . 'LEFT JOIN desembarque_aviso_alcance_versions v ON v.alcance_id = a.id '
        . 'GROUP BY a.id, a.alcance_no, a.status ORDER BY a.id'
    );
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $okItems = (int) ($row['item_count'] ?? 0) > 0;
            $okVersions = (int) ($row['version_count'] ?? 0) > 0;
            $checks[] = [$okItems, 'Alcance #' . (int) $row['alcance_no'] . ' tiene mercancías'];
            $checks[] = [$okVersions, 'Alcance #' . (int) $row['alcance_no'] . ' tiene PDF emitido'];
            if (! $okItems) $failures++;
            if (! $okVersions) $failures++;
        }
    }

    $overReceipt = $connection->query(
        "SELECT COUNT(*) AS invalid_count FROM desembarque_aviso_alcances a "
        . "WHERE a.status = 'presented' AND NOT EXISTS ("
        . "SELECT 1 FROM desembarque_aviso_alcance_versions v "
        . "INNER JOIN desembarque_aviso_alcance_receipts r ON r.alcance_version_id = v.id "
        . "WHERE v.alcance_id = a.id AND v.version_no = (SELECT MAX(v2.version_no) FROM desembarque_aviso_alcance_versions v2 WHERE v2.alcance_id = a.id))"
    );
    $row = $overReceipt instanceof mysqli_result ? $overReceipt->fetch_assoc() : null;
    $ok = (int) ($row['invalid_count'] ?? 0) === 0;
    $checks[] = [$ok, 'Todo Alcance PRESENTADO tiene acuse en su versión vigente'];
    if (! $ok) $failures++;
}

echo "=== FASE 7 · ALCANCES DEL AVISO ===\n";
foreach ($checks as [$ok, $label]) {
    echo ($ok ? 'PASS' : 'FAIL') . ' · ' . $label . "\n";
}
echo "\nRESULTADO GLOBAL: " . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
exit($failures === 0 ? 0 : 1);
