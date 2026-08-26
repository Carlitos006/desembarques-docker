<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/dashboard.php';
require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/../../../src/DashboardAvisoMetrics.php';
require_once __DIR__ . '/../../../src/DashboardAvisoApi.php';

/** @param array<string,mixed> $payload */
function dashboard_api_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Vary: Cookie');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array{id:int,name:string,email:string,role:string} */
function dashboard_api_require_internal_user(): array
{
    if (! isset($_SESSION['user']['id'])) {
        dashboard_api_json(401, [
            'success' => false,
            'message' => translateText('La sesión ha expirado.', 'Your session has expired.'),
        ]);
    }

    $user = [
        'id' => (int) ($_SESSION['user']['id'] ?? 0),
        'name' => trim((string) ($_SESSION['user']['name'] ?? '')),
        'email' => trim((string) ($_SESSION['user']['email'] ?? '')),
        'role' => trim((string) ($_SESSION['user']['role'] ?? '')),
    ];

    if (! in_array($user['role'], ['admin', 'usuario'], true)) {
        dashboard_api_json(403, [
            'success' => false,
            'message' => translateText('No tienes permisos para consultar el Dashboard Ejecutivo.', 'You do not have permission to view the Executive Dashboard.'),
        ]);
    }

    return $user;
}

function dashboard_api_date(?string $value, string $field, array &$errors): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
        $errors[$field] = translateText('Usa el formato YYYY-MM-DD.', 'Use the YYYY-MM-DD format.');
        return null;
    }

    return $value;
}

/**
 * @param array{id:int,name:string,email:string,role:string} $user
 * @return array<string,mixed>
 */
function dashboard_api_filters(array $user): array
{
    $config = getAvisoDashboardConfig();
    $errors = [];

    $dateFrom = dashboard_api_date(
        isset($_GET['date_from']) ? (string) $_GET['date_from'] : (isset($_GET['from']) ? (string) $_GET['from'] : null),
        'date_from',
        $errors
    );
    $dateTo = dashboard_api_date(
        isset($_GET['date_to']) ? (string) $_GET['date_to'] : (isset($_GET['to']) ? (string) $_GET['to'] : null),
        'date_to',
        $errors
    );

    if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
        $errors['date_to'] = translateText('La fecha final no puede ser anterior a la fecha inicial.', 'The end date cannot be earlier than the start date.');
    }

    $rawClient = trim((string) ($_GET['client_id'] ?? ''));
    $clientId = null;
    if ($rawClient !== '') {
        if (! ctype_digit($rawClient) || (int) $rawClient <= 0) {
            $errors['client_id'] = translateText('El cliente solicitado no es válido.', 'The requested client is not valid.');
        } else {
            $clientId = (int) $rawClient;
        }
    }

    $rigName = trim((string) ($_GET['rig_name'] ?? ($_GET['rig'] ?? '')));
    if (function_exists('mb_strlen') ? mb_strlen($rigName, 'UTF-8') > 180 : strlen($rigName) > 180) {
        $errors['rig_name'] = translateText('El nombre del Rig es demasiado largo.', 'The Rig name is too long.');
    }

    $origin = strtolower(trim((string) ($_GET['origin'] ?? 'all')));
    if (! in_array($origin, $config['allowed_origins'], true)) {
        $errors['origin'] = translateText('El origen solicitado no es válido.', 'The requested origin is not valid.');
    }

    $status = strtolower(trim((string) ($_GET['aviso_status'] ?? ($_GET['status'] ?? 'all'))));
    if (! in_array($status, $config['allowed_aviso_statuses'], true)) {
        $errors['aviso_status'] = translateText('El estado documental solicitado no es válido.', 'The requested document status is not valid.');
    }

    $scopeWasExplicit = array_key_exists('record_scope', $_GET) || array_key_exists('scope', $_GET);
    $scope = strtolower(trim((string) ($_GET['record_scope'] ?? ($_GET['scope'] ?? $config['default_scope']))));
    if (! in_array($scope, $config['allowed_scopes'], true)) {
        $errors['record_scope'] = translateText('El alcance de registros solicitado no es válido.', 'The requested record scope is not valid.');
    }

    if ($user['role'] !== 'admin') {
        if ($scopeWasExplicit && $scope !== 'production') {
            dashboard_api_json(403, [
                'success' => false,
                'message' => translateText('Sólo un administrador puede consultar registros QA o todos los alcances.', 'Only an administrator can view QA records or all data scopes.'),
            ]);
        }
        $scope = 'production';
    }

    if ($errors !== []) {
        dashboard_api_json(422, [
            'success' => false,
            'message' => translateText('Revisa los filtros del Dashboard.', 'Review the Dashboard filters.'),
            'errors' => $errors,
        ]);
    }

    return [
        'record_scope' => $scope,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'client_id' => $clientId,
        'rig_name' => $rigName !== '' ? $rigName : null,
        'origin' => $origin,
        'aviso_status' => $status,
    ];
}
