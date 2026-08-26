<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/dashboard.php';
require_once __DIR__ . '/../src/DashboardAvisoMetrics.php';

$options = getopt('', [
    'scope::',
    'from::',
    'to::',
    'client::',
    'origin::',
    'status::',
    'rig::',
    'json',
]);

$filters = [
    'record_scope' => isset($options['scope']) ? (string) $options['scope'] : 'production',
    'date_from' => isset($options['from']) ? (string) $options['from'] : null,
    'date_to' => isset($options['to']) ? (string) $options['to'] : null,
    'client_id' => isset($options['client']) ? (int) $options['client'] : null,
    'origin' => isset($options['origin']) ? (string) $options['origin'] : 'all',
    'aviso_status' => isset($options['status']) ? (string) $options['status'] : 'all',
    'rig_name' => isset($options['rig']) ? (string) $options['rig'] : null,
];

try {
    $db = getDatabaseConnection();
    $metrics = new DashboardAvisoMetrics($db);
    $snapshot = $metrics->snapshot($filters);
} catch (Throwable $exception) {
    fwrite(STDERR, "FASE 6A · ERROR\n");
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, "Verifica que la migración 20260820_dashboard_phase6a.sql esté aplicada y que Finalización V2 exista.\n");
    exit(2);
}

if (isset($options['json'])) {
    echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($snapshot['integrity']['pass'] ?? false) ? 0 : 1);
}

$kpis = $snapshot['kpis'];
$filtersResolved = $snapshot['filters'];

function dashboardCliNumber(int|float $value): string
{
    if (is_int($value) || abs($value - round($value)) < 0.000001) {
        return number_format((float) $value, 0, '.', ',');
    }

    return number_format((float) $value, 3, '.', ',');
}

function dashboardCliLine(string $label, int|float $value): void
{
    printf("%-34s %12s\n", $label, dashboardCliNumber($value));
}

echo "=== FASE 6A · DASHBOARD KPI CONTRACT ===\n";
echo 'Contrato: ' . ($snapshot['contract_version'] ?? 'n/a') . PHP_EOL;
echo 'Fecha KPI: office_date → fallback fecha_desembarque' . PHP_EOL;
echo 'Scope: ' . ($filtersResolved['record_scope'] ?? 'production') . PHP_EOL;
echo 'Origen: ' . ($filtersResolved['origin'] ?? 'all') . PHP_EOL;
echo 'Estado: ' . ($filtersResolved['aviso_status'] ?? 'all') . PHP_EOL;
if (! empty($filtersResolved['date_from']) || ! empty($filtersResolved['date_to'])) {
    echo 'Periodo: ' . ($filtersResolved['date_from'] ?? 'inicio') . ' → ' . ($filtersResolved['date_to'] ?? 'hoy') . PHP_EOL;
}
echo PHP_EOL;

echo "AVISOS\n";
dashboardCliLine('Avisos totales', (int) $kpis['avisos_total']);
dashboardCliLine('Históricos', (int) $kpis['avisos_historical']);
dashboardCliLine('Sistema', (int) $kpis['avisos_system']);
dashboardCliLine('Presentados', (int) $kpis['avisos_presented']);
dashboardCliLine('Emitidos', (int) $kpis['avisos_issued']);
dashboardCliLine('Borradores', (int) $kpis['avisos_draft']);
dashboardCliLine('Reemplazados', (int) $kpis['avisos_replaced']);
dashboardCliLine('Cancelados', (int) $kpis['avisos_cancelled']);
dashboardCliLine('Versiones PDF', (int) $kpis['pdf_versions']);
echo PHP_EOL;

echo "MERCANCÍA\n";
dashboardCliLine('Renglones', (int) $kpis['merchandise_rows']);
dashboardCliLine('Piezas desembarcadas', $kpis['pieces_original']);
dashboardCliLine('Piezas exportadas', $kpis['pieces_exported']);
dashboardCliLine('Piezas en almacén', $kpis['pieces_stored']);
dashboardCliLine('Renglones almacenados', (int) $kpis['rows_stored']);
dashboardCliLine('Renglones parciales', (int) $kpis['rows_partial']);
dashboardCliLine('Renglones exportados', (int) $kpis['rows_exported']);
echo PHP_EOL;

echo "DIMENSIONES\n";
dashboardCliLine('Pedimentos únicos', (int) $kpis['pedimentos_unique']);
dashboardCliLine('Vínculos aviso/pedimento', (int) $kpis['pedimento_links']);
dashboardCliLine('Clientes activos', (int) $kpis['clients_active']);
dashboardCliLine('Rigs activos', (int) $kpis['rigs_active']);
if ($kpis['avg_days_landing_to_notice'] !== null) {
    dashboardCliLine('Prom. desembarque → aviso (días)', (float) $kpis['avg_days_landing_to_notice']);
}
if ($kpis['avg_days_notice_to_presented'] !== null) {
    dashboardCliLine('Prom. aviso → presentación (días)', (float) $kpis['avg_days_notice_to_presented']);
}
echo PHP_EOL;

echo "ANTIGÜEDAD DE PIEZAS EN ALMACÉN\n";
foreach ($snapshot['aging'] as $bucket) {
    printf(
        "%-20s %8s piezas · %5d renglones\n",
        (string) $bucket['label'],
        dashboardCliNumber($bucket['pieces_stored']),
        (int) $bucket['rows_count']
    );
}
echo PHP_EOL;

echo "INTEGRIDAD\n";
foreach ($snapshot['integrity']['checks'] as $check) {
    echo ($check['pass'] ? 'PASS' : 'FAIL') . ' · ' . $check['message'] . PHP_EOL;
}

echo PHP_EOL;
$pass = (bool) ($snapshot['integrity']['pass'] ?? false);
echo 'RESULTADO GLOBAL: ' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;

exit($pass ? 0 : 1);
