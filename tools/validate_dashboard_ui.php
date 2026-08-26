<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/DashboardAvisoMetrics.php';
require_once __DIR__ . '/../src/DashboardAvisoApi.php';

$options = getopt('', ['scope::', 'origin::', 'status::', 'json']);
$scope = isset($options['scope']) ? (string) $options['scope'] : 'production';
$origin = isset($options['origin']) ? (string) $options['origin'] : 'all';
$status = isset($options['status']) ? (string) $options['status'] : 'all';

try {
    $db = getDatabaseConnection();
    $metrics = new DashboardAvisoMetrics($db);
    $api = new DashboardAvisoApi($metrics);
    $payload = $api->build([
        'record_scope' => $scope,
        'origin' => $origin,
        'aviso_status' => $status,
    ], [
        'id' => 0,
        'name' => 'CLI Dashboard UI Validator',
        'email' => '',
        'role' => 'admin',
    ]);
} catch (Throwable $exception) {
    fwrite(STDERR, "FASE 6C · ERROR\n" . $exception->getMessage() . PHP_EOL);
    exit(2);
}

$checks = [];
$check = static function (string $id, bool $pass, string $message) use (&$checks): void {
    $checks[] = ['id' => $id, 'pass' => $pass, 'message' => $message];
};

$requiredKpis = [
    'avisos_total', 'avisos_presented', 'avisos_draft', 'avisos_issued',
    'pieces_original', 'pieces_exported', 'pieces_stored', 'rows_partial',
    'pdf_versions', 'pedimentos_unique', 'clients_active', 'rigs_active', 'merchandise_rows',
];
$kpis = is_array($payload['kpis'] ?? null) ? $payload['kpis'] : [];
foreach ($requiredKpis as $key) {
    $check('kpi_' . $key, array_key_exists($key, $kpis), 'KPI UI disponible: ' . $key);
}

$series = is_array($payload['series'] ?? null) ? $payload['series'] : [];
$breakdowns = is_array($payload['breakdowns'] ?? null) ? $payload['breakdowns'] : [];
$rankings = is_array($payload['rankings'] ?? null) ? $payload['rankings'] : [];
$catalog = is_array($payload['filter_catalog'] ?? null) ? $payload['filter_catalog'] : [];
$recent = is_array($payload['recent_avisos'] ?? null) ? $payload['recent_avisos'] : [];

$check('series_monthly', is_array($series['monthly'] ?? null), 'Serie mensual disponible');
$check('series_aging', is_array($series['aging'] ?? null), 'Serie de antigüedad disponible');
$check('breakdown_status', is_array($breakdowns['status'] ?? null), 'Breakdown de estados disponible');
$check('breakdown_pieces', is_array($breakdowns['pieces'] ?? null), 'Breakdown de piezas disponible');
$check('ranking_clients', is_array($rankings['clients'] ?? null), 'Ranking de clientes disponible');
$check('ranking_rigs', is_array($rankings['rigs'] ?? null), 'Ranking de Rigs disponible');
$check('catalog_years', is_array($catalog['years'] ?? null), 'Filtro de años disponible');
$check('catalog_clients', is_array($catalog['clients'] ?? null), 'Filtro de clientes disponible');
$check('catalog_rigs', is_array($catalog['rigs'] ?? null), 'Filtro de Rigs disponible');
$check('integrity', (bool) (($payload['integrity']['pass'] ?? false)), 'Integridad 6A permanece PASS');

foreach ($recent as $index => $row) {
    $valid = is_array($row)
        && (int) ($row['id'] ?? 0) > 0
        && array_key_exists('notice_number', $row)
        && array_key_exists('aviso_status', $row)
        && array_key_exists('origin', $row);
    $check('recent_' . $index, $valid, 'Aviso reciente #' . ($index + 1) . ' tiene contrato de drill-down');
}

$failed = array_values(array_filter($checks, static fn(array $row): bool => ! $row['pass']));
$result = [
    'pass' => $failed === [],
    'failed_count' => count($failed),
    'checks' => $checks,
    'summary' => [
        'avisos' => (int) ($kpis['avisos_total'] ?? 0),
        'pieces_original' => $kpis['pieces_original'] ?? 0,
        'pieces_exported' => $kpis['pieces_exported'] ?? 0,
        'pieces_stored' => $kpis['pieces_stored'] ?? 0,
        'recent' => count($recent),
    ],
];

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['pass'] ? 0 : 1);
}

echo "=== FASE 6C · DASHBOARD VISUAL CONTRACT ===\n";
echo 'Scope: ' . ($payload['filters']['record_scope'] ?? 'n/a') . PHP_EOL;
echo 'Avisos: ' . number_format((int) ($kpis['avisos_total'] ?? 0)) . PHP_EOL;
echo 'Piezas: ' . number_format((float) ($kpis['pieces_original'] ?? 0), 3, '.', ',') . PHP_EOL;
echo 'Recientes: ' . count($recent) . PHP_EOL . PHP_EOL;

foreach ($checks as $row) {
    echo ($row['pass'] ? 'PASS' : 'FAIL') . ' · ' . $row['message'] . PHP_EOL;
}

echo PHP_EOL . 'RESULTADO GLOBAL: ' . ($result['pass'] ? 'PASS' : 'FAIL') . PHP_EOL;
exit($result['pass'] ? 0 : 1);
