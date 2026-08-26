<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];

function phase6d_check(bool $pass, string $label, array &$checks): void
{
    $checks[] = ['pass' => $pass, 'label' => $label];
    echo ($pass ? 'PASS' : 'FAIL') . ' · ' . $label . PHP_EOL;
}

$dashboard = $root . '/public/dashboard-avisos.php';
$mainJs = $root . '/public/assets/js/dashboard-avisos.js';
$exportJs = $root . '/public/assets/js/dashboard-avisos-export.js';
$css = $root . '/public/assets/css/dashboard-avisos.css';
$sw = $root . '/public/service-worker.js';

$dashboardText = is_file($dashboard) ? file_get_contents($dashboard) : '';
$mainText = is_file($mainJs) ? file_get_contents($mainJs) : '';
$exportText = is_file($exportJs) ? file_get_contents($exportJs) : '';
$cssText = is_file($css) ? file_get_contents($css) : '';
$swText = is_file($sw) ? file_get_contents($sw) : '';

echo "=== FASE 6D · EXECUTIVE EXPORT & HARDENING ===\n\n";
phase6d_check(str_contains($dashboardText, 'data-dashboard-export="pdf"'), 'Botón PDF ejecutivo disponible', $checks);
phase6d_check(str_contains($dashboardText, 'data-dashboard-export="excel"'), 'Botón Excel disponible', $checks);
phase6d_check(str_contains($dashboardText, 'data-dashboard-export="print"'), 'Impresión ejecutiva disponible', $checks);
phase6d_check(str_contains($dashboardText, 'pdf-lib@1.17.1'), 'PDF-lib cargado para exportación directa', $checks);
phase6d_check(str_contains($dashboardText, 'xlsx@0.18.5'), 'SheetJS cargado para XLSX', $checks);
phase6d_check(str_contains($mainText, 'DashboardAvisosRuntime'), 'Runtime 6C expuesto de forma controlada', $checks);
phase6d_check(str_contains($mainText, 'data-dashboard-ranking'), 'Drill-down de rankings disponible', $checks);
phase6d_check(str_contains($exportText, 'exportPdf') && str_contains($exportText, 'exportExcel'), 'Generadores PDF/XLSX disponibles', $checks);
phase6d_check(str_contains($exportText, 'integrityValid'), 'Exportaciones bloqueadas si integridad KPI no pasa', $checks);
phase6d_check(str_contains($cssText, '@media print') && str_contains($cssText, 'A4 landscape'), 'Print stylesheet ejecutivo disponible', $checks);
phase6d_check(str_contains($swText, "CACHE_VERSION = 'v29'"), 'Service Worker actualizado a v29', $checks);
phase6d_check(str_contains($swText, 'dashboard-avisos-export.js'), 'Export JS incluido en precache', $checks);

$failed = array_filter($checks, static fn(array $row): bool => !$row['pass']);
echo PHP_EOL . 'RESULTADO GLOBAL: ' . ($failed === [] ? 'PASS' : 'FAIL') . PHP_EOL;
exit($failed === [] ? 0 : 1);
