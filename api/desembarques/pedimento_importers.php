<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function importer_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function importer_normalize_pedimento(string $value): string
{
    return strtolower((string) preg_replace('/[^0-9a-z]+/i', '', trim($value)));
}

function importer_normalize_key(string $value): string
{
    return strtoupper((string) preg_replace('/\s+/', '', trim($value)));
}

function importer_clean_name(mixed $value): string
{
    $name = trim((string) $value);
    if ($name === '') {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    importer_response(405, ['success' => false, 'message' => translateText('Método no permitido.', 'Method not allowed.')]);
}

if (! isset($_SESSION['user']['id'])) {
    importer_response(401, ['success' => false, 'message' => translateText('La sesión ha expirado.', 'Your session has expired.')]);
}

$role = (string) ($_SESSION['user']['role'] ?? '');
if (! in_array($role, ['admin', 'usuario'], true)) {
    importer_response(403, ['success' => false, 'message' => translateText('No tienes permisos para consultar importadores.', 'You do not have permission to view importers.')]);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody !== false ? $rawBody : '', true);
if (! is_array($payload)) {
    importer_response(400, ['success' => false, 'message' => translateText('La solicitud no contiene JSON válido.', 'The request does not contain valid JSON.')]);
}

if (! validate_csrf_token(isset($payload['csrf_token']) ? (string) $payload['csrf_token'] : null)) {
    importer_response(419, ['success' => false, 'message' => translateText('El token de seguridad no es válido.', 'The security token is invalid.')]);
}

$requested = isset($payload['pedimentos']) && is_array($payload['pedimentos']) ? $payload['pedimentos'] : [];
if ($requested === [] || count($requested) > 100) {
    importer_response(200, ['success' => true, 'headers' => []]);
}

$wanted = [];
foreach ($requested as $entry) {
    if (! is_array($entry)) {
        continue;
    }

    $number = trim((string) ($entry['num_pedimento'] ?? ''));
    $numberKey = importer_normalize_pedimento($number);
    if ($numberKey === '') {
        continue;
    }

    $clave = trim((string) ($entry['clave'] ?? ''));
    $claveKey = importer_normalize_key($clave);
    $key = $claveKey . '|' . $numberKey;

    $wanted[$key] = [
        'clave' => $claveKey,
        'num_pedimento' => $number,
        'number_key' => $numberKey,
        'names' => [],
    ];
}

if ($wanted === []) {
    importer_response(200, ['success' => true, 'headers' => []]);
}

try {
    $connection = getDatabaseConnection();

    $result = $connection->query(
        "SELECT cve_pedimento AS clave, num_pedimento, razon_social AS importer_name\n"
        . "FROM desembarque_pedimento_headers\n"
        . "WHERE num_pedimento IS NOT NULL AND TRIM(num_pedimento) <> '' AND razon_social IS NOT NULL AND TRIM(razon_social) <> ''"
    );

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $numberKey = importer_normalize_pedimento((string) ($row['num_pedimento'] ?? ''));
            $claveKey = importer_normalize_key((string) ($row['clave'] ?? ''));
            $name = importer_clean_name($row['importer_name'] ?? '');
            if ($numberKey === '' || $name === '') {
                continue;
            }

            foreach ($wanted as $key => &$entry) {
                if ($entry['number_key'] !== $numberKey) {
                    continue;
                }
                if ($entry['clave'] !== '' && $claveKey !== '' && $entry['clave'] !== $claveKey) {
                    continue;
                }
                $entry['names'][$name] = true;
            }
            unset($entry);
        }
        $result->free();
    }

    // Los avisos ya importados también sirven como memoria histórica del importador.
    $itemsResult = $connection->query(
        "SELECT clave, num_pedimento, importer_name\n"
        . "FROM desembarque_aviso_items\n"
        . "WHERE num_pedimento IS NOT NULL AND TRIM(num_pedimento) <> '' AND importer_name IS NOT NULL AND TRIM(importer_name) <> ''"
    );

    if ($itemsResult instanceof mysqli_result) {
        while ($row = $itemsResult->fetch_assoc()) {
            $numberKey = importer_normalize_pedimento((string) ($row['num_pedimento'] ?? ''));
            $claveKey = importer_normalize_key((string) ($row['clave'] ?? ''));
            $name = importer_clean_name($row['importer_name'] ?? '');
            if ($numberKey === '' || $name === '') {
                continue;
            }

            foreach ($wanted as $key => &$entry) {
                if ($entry['number_key'] !== $numberKey) {
                    continue;
                }
                if ($entry['clave'] !== '' && $claveKey !== '' && $entry['clave'] !== $claveKey) {
                    continue;
                }
                $entry['names'][$name] = true;
            }
            unset($entry);
        }
        $itemsResult->free();
    }
} catch (Throwable $exception) {
    error_log('[pedimento-importers] ' . $exception->getMessage());
    importer_response(500, ['success' => false, 'message' => translateText('No fue posible consultar el historial de importadores.', 'Unable to retrieve importer history.')]);
}

$headers = [];
foreach ($wanted as $entry) {
    $names = array_keys($entry['names']);
    // Si hay más de una razón social histórica para el mismo pedimento, no adivinamos.
    if (count($names) !== 1) {
        continue;
    }

    $headers[] = [
        'cve_pedimento' => $entry['clave'],
        'num_pedimento' => $entry['num_pedimento'],
        'razon_social' => $names[0],
    ];
}

importer_response(200, [
    'success' => true,
    'headers' => $headers,
]);
