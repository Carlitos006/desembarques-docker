<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/DashboardAvisoMetrics.php';
require_once __DIR__ . '/../src/DashboardAvisoApi.php';

$options = getopt('', [
    'scope::',
    'from::',
    'to::',
    'client::',
    'origin::',
    'status::',
    'rig::',
    'role::',
    'json',
]);

$role = isset($options['role']) ? strtolower(trim((string) $options['role'])) : 'admin';
if (! in_array($role, ['admin', 'usuario'], true)) {
    fwrite(STDERR, "FASE 6B · ERROR\nRol CLI inválido. Usa admin o usuario.\n");
    exit(2);
}

$scope = isset($options['scope']) ? (string) $options['scope'] : 'production';
if ($role !== 'admin') {
    $scope = 'production';
}

$filters = [
    'record_scope' => $scope,
    'date_from' => isset($options['from']) ? (string) $options['from'] : null,
    'date_to' => isset($options['to']) ? (string) $options['to'] : null,
    'client_id' => isset($options['client']) ? (int) $options['client'] : null,
    'origin' => isset($options['origin']) ? (string) $options['origin'] : 'all',
    'aviso_status' => isset($options['status']) ? (string) $options['status'] : 'all',
    'rig_name' => isset($options['rig']) ? (string) $options['rig'] : null,
];

$user = [
    'id' => 0,
    'name' => 'CLI Validator',
    'email' => '',
    'role' => $role,
];

try {
    $db = getDatabaseConnection();
    $metrics = new DashboardAvisoMetrics($db);
    $api = new DashboardAvisoApi($metrics);
    $payload = $api->build($filters, $user);
} catch (Throwable $exception) {
    fwrite(STDERR, "FASE 6B · ERROR\n" . $exception->getMessage() . PHP_EOL);
    exit(2);
}

/** @var list<array{id:string,pass:bool,message:string}> $checks */
$checks = [];
$check = static function (string $id, bool $pass, string $message) use (&$checks): void {
    $checks[] = ['id' => $id, 'pass' => $pass, 'message' => $message];
};

$requiredTop = [
    'api_contract_version', 'metric_contract_version', 'generated_at', 'filters', 'permissions',
    'filter_catalog', 'kpis', 'breakdowns', 'cycle_times', 'series', 'rankings', 'recent_avisos', 'integrity',
];
foreach ($requiredTop as $key) {
    $check('key_' . $key, array_key_exists($key, $payload), 'El payload contiene ' . $key);
}

$check(
    'api_contract',
    ($payload['api_contract_version'] ?? '') === '6B.1',
    'Contrato API = 6B.1'
);
$check(
    'metric_contract',
    str_starts_with((string) ($payload['metric_contract_version'] ?? ''), '6A.'),
    'El API conserva el contrato métrico aprobado de Fase 6A'
);
$check(
    'integrity',
    (bool) (($payload['integrity']['pass'] ?? false)),
    'La integridad KPI de Fase 6A permanece PASS'
);
$check(
    'recent_limit',
    is_array($payload['recent_avisos'] ?? null) && count($payload['recent_avisos']) <= 12,
    'La lista reciente respeta el límite de 12 avisos'
);

$kpis = is_array($payload['kpis'] ?? null) ? $payload['kpis'] : [];
$statusBreakdown = is_array($payload['breakdowns']['status'] ?? null) ? $payload['breakdowns']['status'] : [];
$originBreakdown = is_array($payload['breakdowns']['origin'] ?? null) ? $payload['breakdowns']['origin'] : [];
$pieceBreakdown = is_array($payload['breakdowns']['pieces'] ?? null) ? $payload['breakdowns']['pieces'] : [];

$statusSum = array_sum(array_map(static fn(array $row): int => (int) ($row['value'] ?? 0), $statusBreakdown));
$originSum = array_sum(array_map(static fn(array $row): int => (int) ($row['value'] ?? 0), $originBreakdown));
$pieceSum = array_sum(array_map(static fn(array $row): float => (float) ($row['value'] ?? 0), $pieceBreakdown));

$check(
    'status_breakdown',
    $statusSum === (int) ($kpis['avisos_total'] ?? -1),
    'Breakdown de estados = avisos totales'
);
$check(
    'origin_breakdown',
    $originSum === (int) ($kpis['avisos_total'] ?? -1),
    'Breakdown de origen = avisos totales'
);
$check(
    'piece_breakdown',
    abs($pieceSum - (float) ($kpis['pieces_original'] ?? -1)) < 0.001,
    'Breakdown de piezas = piezas desembarcadas'
);

$catalog = is_array($payload['filter_catalog'] ?? null) ? $payload['filter_catalog'] : [];
$check('catalog_clients', is_array($catalog['clients'] ?? null), 'Catálogo de clientes disponible');
$check('catalog_rigs', is_array($catalog['rigs'] ?? null), 'Catálogo de Rigs disponible');
$check('catalog_years', is_array($catalog['years'] ?? null), 'Catálogo de años disponible');
$check('catalog_dates', is_array($catalog['date_bounds'] ?? null), 'Rango de fechas disponible');

$failed = array_values(array_filter($checks, static fn(array $row): bool => ! $row['pass']));
$result = [
    'pass' => $failed === [],
    'checks' => $checks,
    'failed_count' => count($failed),
    'payload' => $payload,
];

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['pass'] ? 0 : 1);
}

echo "=== FASE 6B · DASHBOARD AGGREGATE API ===\n";
echo 'API: ' . ($payload['api_contract_version'] ?? 'n/a') . PHP_EOL;
echo 'Contrato KPI: ' . ($payload['metric_contract_version'] ?? 'n/a') . PHP_EOL;
echo 'Scope: ' . ($payload['filters']['record_scope'] ?? 'n/a') . PHP_EOL;
echo 'Avisos: ' . number_format((int) ($kpis['avisos_total'] ?? 0)) . PHP_EOL;
echo 'Recientes: ' . count($payload['recent_avisos'] ?? []) . PHP_EOL;
echo 'Clientes filtro: ' . count($catalog['clients'] ?? []) . PHP_EOL;
echo 'Rigs filtro: ' . count($catalog['rigs'] ?? []) . PHP_EOL;
echo PHP_EOL;

foreach ($checks as $row) {
    echo ($row['pass'] ? 'PASS' : 'FAIL') . ' · ' . $row['message'] . PHP_EOL;
}

echo PHP_EOL . 'RESULTADO GLOBAL: ' . ($result['pass'] ? 'PASS' : 'FAIL') . PHP_EOL;
exit($result['pass'] ? 0 : 1);
