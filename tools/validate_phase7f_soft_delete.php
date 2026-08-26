<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$passes = 0;
$warnings = [];

function phase7fCheck(bool $condition, string $label, array &$failures, int &$passes): void
{
    if ($condition) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }

    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
}

function phase7fContents(string $root, string $relative): string
{
    $contents = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($contents) ? $contents : '';
}

function phase7fHasAll(string $contents, array $needles): bool
{
    foreach ($needles as $needle) {
        if (! str_contains($contents, $needle)) {
            return false;
        }
    }
    return true;
}

echo "=== FASE 7F · ADMINISTRATIVE SOFT DELETE ===\n";

$migration = phase7fContents($root, 'database/migrations/20260824_administrative_soft_delete_phase7f.sql');
phase7fCheck(phase7fHasAll($migration, [
    'deleted_at DATETIME NULL',
    'deleted_by INT UNSIGNED NULL',
    'delete_reason VARCHAR(500) NULL',
    'idx_desembarques_deleted_at',
    'fk_desembarques_deleted_by',
    'ON DELETE SET NULL',
]), 'Migración aditiva con columnas, índice y FK nullable', $failures, $passes);
phase7fCheck(
    ! str_contains(strtolower($migration), 'from information_schema')
        && str_contains($migration, 'SHOW COLUMNS FROM desembarques')
        && str_contains($migration, 'SHOW INDEX FROM desembarques'),
    'Migración compatible con usuario restringido sin information_schema',
    $failures,
    $passes
);

$deleteEndpoint = phase7fContents($root, 'api/desembarques/admin/soft_delete.php');
$restoreEndpoint = phase7fContents($root, 'api/desembarques/admin/restore.php');
$adminBootstrap = phase7fContents($root, 'api/desembarques/admin/_bootstrap.php');
phase7fCheck(phase7fHasAll($deleteEndpoint . $adminBootstrap, [
    "aviso_admin_require_admin()",
    'aviso_admin_validate_csrf',
    "getAppLanguage() === 'en' ? 'DELETE ' : 'ELIMINAR '",
    "'soft_delete'",
    'deleted_at = ?',
    'deleted_at IS NULL',
]), 'Baja: admin + CSRF + confirmación exacta + auditoría + condición activa', $failures, $passes);
phase7fCheck(phase7fHasAll($restoreEndpoint . $adminBootstrap, [
    "aviso_admin_require_admin()",
    'aviso_admin_validate_csrf',
    "getAppLanguage() === 'en' ? 'RESTORE ' : 'RESTAURAR '",
    "'restore'",
    'deleted_at = NULL',
    'deleted_at IS NOT NULL',
]), 'Restauración: admin + CSRF + confirmación + auditoría', $failures, $passes);
phase7fCheck(phase7fHasAll($adminBootstrap, [
    'ad.id AS aviso_detail_id',
    'ad.manifiesto AS aviso_manifiesto',
    "\$record['notice_number']",
    "\$record['aviso_manifiesto']",
    "\$record['referencia']",
]), 'Confirmación administrativa usa el mismo identificador visible del expediente', $failures, $passes);
phase7fCheck(! str_contains($deleteEndpoint . $restoreEndpoint . $migration, 'record_scope ='), 'La baja no reutiliza record_scope', $failures, $passes);

$scanDirectories = ['api', 'public', 'src'];
$parentHardDeleteFound = [];
foreach ($scanDirectories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . $directory));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), ['php', 'js'], true)) {
            continue;
        }
        $contents = @file_get_contents($file->getPathname());
        if (is_string($contents) && preg_match('/DELETE\s+FROM\s+desembarques\b/i', $contents)) {
            $parentHardDeleteFound[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }
}
phase7fCheck($parentHardDeleteFound === [], 'No existe hard delete de la tabla padre en la aplicación', $failures, $passes);

$coverage = [
    'Dashboard/KPIs' => ['src/DashboardAvisoMetrics.php', 'd.deleted_at IS NULL'],
    'Reportes/listados/exportaciones/Portal' => ['api/desembarques/list.php', 'WHERE d.deleted_at IS NULL'],
    'Control Tower' => ['api/desembarques/board.php', 'd.deleted_at IS NULL'],
    'Analytics' => ['api/desembarques/analytics.php', 'd.deleted_at IS NULL'],
    'API v1' => ['api/v1/desembarques/index.php', 'WHERE d.deleted_at IS NULL'],
    'Búsqueda de observaciones' => ['api/desembarques/unread_observaciones_helpers.php', 'd.deleted_at IS NULL'],
    'Acceso de expediente/API' => ['api/desembarques/aviso/_bootstrap.php', 'd.deleted_at IS NULL'],
];
foreach ($coverage as $label => [$file, $needle]) {
    phase7fCheck(str_contains(phase7fContents($root, $file), $needle), $label . ' excluye eliminados', $failures, $passes);
}

$expediente = phase7fContents($root, 'public/aviso-expediente.php');
$trash = phase7fContents($root, 'public/aviso-papelera.php');
phase7fCheck(phase7fHasAll($expediente, ['d.deleted_at IS NULL', 'd.deleted_at IS NOT NULL', 'AVISO ELIMINADO ADMINISTRATIVAMENTE', 'data-soft-delete-form="delete"', 'data-soft-delete-form="restore"']), 'URL directa oculta y vista admin de sólo lectura', $failures, $passes);
phase7fCheck(phase7fHasAll($trash, ['WHERE d.deleted_at IS NOT NULL', "!== 'admin'", 'aviso-expediente.php?id=', 'data-soft-delete-restore']), 'Papelera exclusiva de admin con vista y restauración', $failures, $passes);

$importBootstrap = phase7fContents($root, 'api/desembarques/aviso/import/_bootstrap.php');
$importCommit = phase7fContents($root, 'api/desembarques/aviso/import/commit.php');
$importUi = phase7fContents($root, 'public/assets/js/aviso-import.js');
phase7fCheck(phase7fHasAll($importBootstrap . $importCommit . $importUi, [
    'duplicate_is_deleted',
    'deleted_at',
    'Abrir Papelera y restaurar',
    'import_new_override_deleted',
    "['admin', 'usuario']",
    'Sólo un usuario interno puede autorizar',
    'aviso_import_require_internal',
    'canOverrideDeleted',
    'deleted_duplicate_override',
    'Importar como aviso nuevo (conservar eliminado)',
]), 'Importador permite excepción motivada a admin/usuario y mantiene cliente sin acceso', $failures, $passes);

$serviceWorker = phase7fContents($root, 'public/service-worker.js');
phase7fCheck(phase7fHasAll($serviceWorker, ["CACHE_VERSION = 'v43'", "'./assets/js/aviso-soft-delete.js?v=42'", "'./assets/js/aviso-import.js?v=42'"]), 'Service worker actualizado a v43', $failures, $passes);

$requireDb = in_array('--require-db', $argv, true);
try {
    require_once $root . '/config/database.php';
    $db = getDatabaseConnection();
    $columnResult = $db->query(
        "SHOW COLUMNS FROM desembarques WHERE Field IN ('deleted_at','deleted_by','delete_reason')"
    );
    $columnCount = $columnResult instanceof mysqli_result ? $columnResult->num_rows : 0;
    phase7fCheck($columnCount === 3, 'BD local: tres columnas 7F instaladas', $failures, $passes);

    $indexResult = $db->query(
        "SHOW INDEX FROM desembarques WHERE Key_name = 'idx_desembarques_deleted_at'"
    );
    $indexCount = $indexResult instanceof mysqli_result ? $indexResult->num_rows : 0;
    phase7fCheck($indexCount >= 1, 'BD local: índice deleted_at instalado', $failures, $passes);

    $createResult = $db->query('SHOW CREATE TABLE desembarques');
    $createRow = $createResult instanceof mysqli_result ? $createResult->fetch_assoc() : null;
    $createSql = is_array($createRow) ? (string) (array_values($createRow)[1] ?? '') : '';
    phase7fCheck(
        str_contains($createSql, 'fk_desembarques_deleted_by')
            && preg_match('/FOREIGN KEY \\(`deleted_by`\\).*ON DELETE SET NULL/is', $createSql) === 1,
        'BD local: FK deleted_by usa ON DELETE SET NULL',
        $failures,
        $passes
    );
} catch (Throwable $exception) {
    $message = 'BD local no validada: ' . $exception->getMessage();
    if ($requireDb) {
        $failures[] = $message;
        echo '[FAIL] ' . $message . PHP_EOL;
    } else {
        $warnings[] = $message;
        echo '[SKIP] ' . $message . PHP_EOL;
    }
}

echo PHP_EOL . 'Checks PASS: ' . $passes . PHP_EOL;
if ($warnings !== []) {
    echo 'Avisos: ' . count($warnings) . PHP_EOL;
}
if ($failures !== []) {
    echo 'Fallos: ' . count($failures) . PHP_EOL;
    echo 'RESULTADO GLOBAL: FAIL' . PHP_EOL;
    exit(1);
}

echo 'RESULTADO GLOBAL: PASS' . PHP_EOL;
