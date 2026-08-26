<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

if (! isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
    exit;
}

$userRole = (string) ($_SESSION['user']['role'] ?? '');
if (! in_array($userRole, ['admin', 'usuario'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

function respondError(int $statusCode, string $message, array $payload = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => false,
        'message' => $message,
    ], $payload));
    exit;
}

function respondSuccess(array $payload = []): void
{
    http_response_code(200);
    echo json_encode(array_merge([
        'success' => true,
    ], $payload));
    exit;
}

function removeDiacritics(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (class_exists('Normalizer')) {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
        if ($normalized !== false) {
            $stripped = preg_replace('/\p{Mn}+/u', '', $normalized);
            if ($stripped !== null) {
                $value = $stripped;
            } else {
                $value = $normalized;
            }
        }
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    return $value;
}

function buildClientPrefix(string $clientName, string $clientEmail): string
{
    $source = trim($clientName);

    if ($source === '') {
        $emailLocal = trim((string) explode('@', $clientEmail)[0]);
        $source = $emailLocal;
    }

    $source = removeDiacritics($source);
    $source = strtoupper($source);
    $filtered = preg_replace('/[^A-Z]/', '', $source);
    if ($filtered !== null) {
        $source = $filtered;
    }

    if ($source === '') {
        $source = 'CLI';
    }

    if (strlen($source) >= 3) {
        return substr($source, 0, 3);
    }

    return str_pad($source, 3, 'X');
}

$clientIdInput = trim((string) ($_GET['client_id'] ?? ''));
if ($clientIdInput === '' || ! ctype_digit($clientIdInput)) {
    respondError(422, translate('desembarques.validation.client_invalid', [], $currentLanguage));
}

$clientId = (int) $clientIdInput;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondError(500, $exception->getMessage());
}

try {
    $clientStatement = $connection->prepare('SELECT id, name, email FROM clients WHERE id = ? LIMIT 1');
} catch (Throwable $exception) {
    respondError(500, translate('desembarques.validation.client_lookup_failed', [], $currentLanguage));
}

if (! $clientStatement) {
    respondError(500, translate('desembarques.validation.client_lookup_failed', [], $currentLanguage));
}

try {
    $clientStatement->bind_param('i', $clientId);
    $clientStatement->execute();

    $clientResult = $clientStatement->get_result();
    $client = $clientResult ? $clientResult->fetch_assoc() : null;

    if ($clientResult instanceof mysqli_result) {
        $clientResult->free();
    }
} catch (Throwable $exception) {
    $clientStatement->close();
    respondError(500, translate('desembarques.validation.client_lookup_error', ['error' => $exception->getMessage()], $currentLanguage));
}

$clientStatement->close();

if (! $client) {
    respondError(404, translate('desembarques.validation.client_not_found', [], $currentLanguage));
}

$clientName = (string) ($client['name'] ?? '');
$clientEmail = (string) ($client['email'] ?? '');
$prefix = buildClientPrefix($clientName, $clientEmail);

$lastReference = '';
$sequenceLength = 3;
$nextSequence = 1;

try {
    // Las referencias de expedientes eliminados siguen reservadas: una baja lógica
    // nunca habilita la reutilización de identidades documentales.
    $referenceStatement = $connection->prepare('SELECT referencia FROM desembarques WHERE client_id = ? ORDER BY id DESC LIMIT 1');
} catch (Throwable $exception) {
    respondError(500, translate('desembarques.alert.error_generic', [], $currentLanguage), [
        'error' => $exception->getMessage(),
    ]);
}

if (! $referenceStatement) {
    respondError(500, translate('desembarques.alert.error_generic', [], $currentLanguage));
}

try {
    $referenceStatement->bind_param('i', $clientId);
    $referenceStatement->execute();

    $referenceResult = $referenceStatement->get_result();
    if ($referenceResult instanceof mysqli_result) {
        $row = $referenceResult->fetch_assoc();
        if ($row && isset($row['referencia'])) {
            $lastReference = (string) $row['referencia'];
        }
        $referenceResult->free();
    }
} catch (Throwable $exception) {
    $referenceStatement->close();
    respondError(500, translate('desembarques.alert.error_generic', [], $currentLanguage), [
        'error' => $exception->getMessage(),
    ]);
}

$referenceStatement->close();

if ($lastReference !== '') {
    if (preg_match('/^[A-Z]{3}(\d+)-(\d{6})$/i', $lastReference, $matches)) {
        $sequence = (int) $matches[1];
        $sequenceLength = max(strlen($matches[1]), 1);
        if ($sequence >= 0) {
            $nextSequence = $sequence + 1;
        }
    }
}

$date = new DateTimeImmutable('now');
$datePart = $date->format('dmy');
$sequencePart = str_pad((string) $nextSequence, $sequenceLength, '0', STR_PAD_LEFT);

$reference = sprintf('%s%s-%s', $prefix, $sequencePart, $datePart);

respondSuccess([
    'reference' => $reference,
    'last_reference' => $lastReference,
]);
