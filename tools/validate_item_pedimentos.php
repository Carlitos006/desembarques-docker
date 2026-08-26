<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

try {
    $db = getDatabaseConnection();

    $table = $db->query("SHOW TABLES LIKE 'desembarque_aviso_item_pedimentos'");
    if (! ($table instanceof mysqli_result) || $table->num_rows === 0) {
        throw new RuntimeException('Falta la migración 20260821_item_multi_pedimentos.sql.');
    }

    $single = static function (mysqli $db, string $sql): int {
        $result = $db->query($sql);
        $row = $result instanceof mysqli_result ? $result->fetch_row() : null;
        return (int) ($row[0] ?? 0);
    };

    $relations = $single($db, 'SELECT COUNT(*) FROM desembarque_aviso_item_pedimentos');
    $multiItems = $single($db, 'SELECT COUNT(*) FROM (SELECT aviso_item_id FROM desembarque_aviso_item_pedimentos GROUP BY aviso_item_id HAVING COUNT(*) > 1) x');
    $orphans = $single($db, 'SELECT COUNT(*) FROM desembarque_aviso_item_pedimentos ip LEFT JOIN desembarque_aviso_items i ON i.id = ip.aviso_item_id WHERE i.id IS NULL');
    $misleadingLegacy = $single($db, "SELECT COUNT(*) FROM desembarque_aviso_items i INNER JOIN (SELECT aviso_item_id, COUNT(*) c FROM desembarque_aviso_item_pedimentos GROUP BY aviso_item_id HAVING COUNT(*) > 1) x ON x.aviso_item_id=i.id WHERE TRIM(COALESCE(i.num_pedimento,'')) <> ''");
    $missingHeaders = $single($db, "SELECT COUNT(*) FROM desembarque_aviso_item_pedimentos ip INNER JOIN desembarque_aviso_items i ON i.id=ip.aviso_item_id LEFT JOIN desembarque_pedimento_headers ph ON ph.desembarque_id=i.desembarque_id AND UPPER(TRIM(COALESCE(ph.cve_pedimento,'')))=UPPER(TRIM(COALESCE(ip.clave,''))) AND REPLACE(TRIM(COALESCE(ph.num_pedimento,'')),' ','')=REPLACE(TRIM(ip.num_pedimento),' ','') WHERE ph.id IS NULL");

    echo "=== MULTI-PEDIMENTO POR MERCANCÍA ===\n";
    echo "Relaciones: {$relations}\n";
    echo "Mercancías con >1 pedimento: {$multiItems}\n\n";

    $checks = [
        ['Huérfanos de mercancía', $orphans],
        ['Legacy singular engañoso en mercancía multi-pedimento', $misleadingLegacy],
        ['Relaciones sin pedimento global/header', $missingHeaders],
    ];
    $failed = false;
    foreach ($checks as [$label, $count]) {
        $ok = $count === 0;
        echo ($ok ? 'PASS' : 'FAIL') . " · {$label}: {$count}\n";
        $failed = $failed || ! $ok;
    }

    echo "\nRESULTADO GLOBAL: " . ($failed ? 'FAIL' : 'PASS') . "\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR · {$e->getMessage()}\n");
    exit(2);
}
