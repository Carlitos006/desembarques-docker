<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    dashboard_api_json(405, [
        'success' => false,
        'message' => translateText('Método no permitido.', 'Method not allowed.'),
    ]);
}

$user = dashboard_api_require_internal_user();
$filters = dashboard_api_filters($user);
$requestId = bin2hex(random_bytes(8));

try {
    $connection = getDatabaseConnection();
    $metrics = new DashboardAvisoMetrics($connection);
    $api = new DashboardAvisoApi($metrics);
    $data = $api->build($filters, $user);

    $integrity = is_array($data['integrity'] ?? null) ? $data['integrity'] : [];
    if (! ($integrity['pass'] ?? false)) {
        error_log('[dashboard-6b][' . $requestId . '] KPI integrity failed: ' . json_encode($integrity));
        dashboard_api_json(409, [
            'success' => false,
            'message' => translateText('Los indicadores no superaron la validación de integridad. El Dashboard no mostrará cifras potencialmente inconsistentes.', 'The indicators did not pass integrity validation. The Dashboard will not display potentially inconsistent figures.'),
            'request_id' => $requestId,
            'integrity' => $integrity,
        ]);
    }

    dashboard_api_json(200, [
        'success' => true,
        'request_id' => $requestId,
        'data' => $data,
    ]);
} catch (Throwable $exception) {
    error_log('[dashboard-6b][' . $requestId . '] ' . $exception->getMessage());
    dashboard_api_json(500, [
        'success' => false,
        'message' => translateText('No fue posible obtener el resumen ejecutivo del Dashboard.', 'The Dashboard executive summary could not be retrieved.'),
        'request_id' => $requestId,
    ]);
}
