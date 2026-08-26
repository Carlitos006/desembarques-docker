<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/historical_import.php';
require_once __DIR__ . '/../config/database.php';

function bytes_from_ini(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    return match ($unit) {
        'g' => (int) round($number * 1024 * 1024 * 1024),
        'm' => (int) round($number * 1024 * 1024),
        'k' => (int) round($number * 1024),
        default => (int) round($number),
    };
}

$config = historical_import_upload_config();
$maxFile = (int) $config['max_file_size_mb'] * 1024 * 1024;
$maxBatch = (int) $config['max_batch_size_mb'] * 1024 * 1024;
$phpUpload = bytes_from_ini((string) ini_get('upload_max_filesize'));
$phpPost = bytes_from_ini((string) ini_get('post_max_size'));
$phpMaxFiles = (int) ini_get('max_file_uploads');

$checks = [];
$checks[] = ['Aplicación · archivo individual', $maxFile > 0, $config['max_file_size_mb'] . ' MB'];
$checks[] = ['Aplicación · lote', $maxBatch > $maxFile, $config['max_batch_size_mb'] . ' MB'];
$checks[] = ['PHP · upload_max_filesize', $phpUpload >= $maxFile, (string) ini_get('upload_max_filesize')];
$checks[] = ['PHP · post_max_size', $phpPost > $maxBatch, (string) ini_get('post_max_size')];
$checks[] = ['PHP · max_file_uploads', $phpMaxFiles >= (int) $config['max_files'], (string) $phpMaxFiles];

try {
    $db = getDatabaseConnection();
    foreach ([
        'desembarque_files',
        'desembarque_aviso_images',
        'desembarque_aviso_items',
    ] as $table) {
        $result = $db->execute_query(
            'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $exists = (int) ($row['total'] ?? 0) === 1;
        $checks[] = ['BD · ' . $table, $exists, $exists ? 'disponible' : 'faltante'];
    }

    $result = $db->query(
        "SELECT COUNT(*) AS total FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'desembarque_files' AND column_name = 'purpose'"
    );
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $checks[] = ['BD · desembarque_files.purpose', (int) ($row['total'] ?? 0) === 1, 'requerido para purpose=photo'];
} catch (Throwable $exception) {
    $checks[] = ['BD · conexión', false, $exception->getMessage()];
}

$pass = true;
echo "=== HISTÓRICOS · LÍMITES + FOTOS ===\n";
foreach ($checks as [$label, $ok, $detail]) {
    $pass = $pass && $ok;
    echo ($ok ? 'PASS' : 'FAIL') . ' · ' . $label . ' · ' . $detail . PHP_EOL;
}
echo PHP_EOL . 'RESULTADO GLOBAL: ' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;
exit($pass ? 0 : 1);
