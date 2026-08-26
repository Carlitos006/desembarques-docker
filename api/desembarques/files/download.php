<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../../config/i18n.php';

$currentLanguage = getAppLanguage();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

if (! isset($_SESSION['user']['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
    exit;
}

$userRole = (string) ($_SESSION['user']['role'] ?? '');
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$userName = (string) ($_SESSION['user']['name'] ?? '');
$userEmail = (string) ($_SESSION['user']['email'] ?? '');

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/files.php';

$fileIdValue = trim((string) ($_GET['id'] ?? ''));

if ($fileIdValue === '' || ! ctype_digit($fileIdValue)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.delete_invalid', [], $currentLanguage),
    ]);
    exit;
}

$fileId = (int) $fileIdValue;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
    exit;
}

try {
    $query = 'SELECT f.id, f.desembarque_id, f.original_name, f.stored_name, f.mime_type, f.extension, f.size, f.purpose, f.sha256, '
        . 'd.cliente, d.client_id, d.deleted_at, c.user_id AS client_user_id, c.name AS client_name, c.email AS client_email, '
        . 'ad.source_excel_name '
        . 'FROM desembarque_files f '
        . 'INNER JOIN desembarques d ON d.id = f.desembarque_id '
        . 'LEFT JOIN clients c ON c.id = d.client_id '
        . 'LEFT JOIN desembarque_aviso_details ad ON ad.desembarque_id = f.desembarque_id '
        . 'WHERE f.id = ? LIMIT 1';
    $statement = $connection->prepare($query);

    if (! $statement) {
        throw new RuntimeException('Unable to prepare the attachment lookup statement.');
    }

    $statement->bind_param('i', $fileId);
    $statement->execute();

    $result = $statement->get_result();
    $fileRecord = $result ? $result->fetch_assoc() : null;

    $statement->close();

    if (! $fileRecord) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => translate('desembarques.files.delete_not_found', [], $currentLanguage),
        ]);
        exit;
    }
} catch (Throwable $exception) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.load_error', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
    exit;
}

$deletedAdminView = $userRole === 'admin' && (string) ($_GET['deleted'] ?? '') === '1';
if (! empty($fileRecord['deleted_at']) && ! $deletedAdminView) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.delete_not_found', [], $currentLanguage),
    ]);
    exit;
}

// El Excel fuente es un insumo privado de procesamiento. No forma parte del expediente
// documental y no se ofrece mediante el endpoint genérico de descarga, a ningún rol.
$sourceExcelName = trim((string) ($fileRecord['source_excel_name'] ?? ''));
$fileOriginalName = trim((string) ($fileRecord['original_name'] ?? ''));
$isPrivateSourceExcel = (string) ($fileRecord['purpose'] ?? '') === 'source_excel'
    || ($sourceExcelName !== '' && $fileOriginalName === $sourceExcelName);

if ($isPrivateSourceExcel) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.delete_not_found', [], $currentLanguage),
    ]);
    exit;
}

$desembarqueId = isset($fileRecord['desembarque_id']) ? (int) $fileRecord['desembarque_id'] : 0;

if ($desembarqueId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.delete_not_found', [], $currentLanguage),
    ]);
    exit;
}

if ($userRole === 'cliente') {
    $clientMatches = false;

    $clientUserId = isset($fileRecord['client_user_id']) ? (int) $fileRecord['client_user_id'] : null;
    $clientName = mb_strtolower(trim((string) ($fileRecord['client_name'] ?? $fileRecord['cliente'] ?? '')));
    $clientEmail = mb_strtolower(trim((string) ($fileRecord['client_email'] ?? $fileRecord['cliente'] ?? '')));
    $sessionName = mb_strtolower(trim($userName));
    $sessionEmail = mb_strtolower(trim($userEmail));

    if ($clientUserId !== null && $clientUserId > 0 && $clientUserId === $userId) {
        $clientMatches = true;
    }

    if (! $clientMatches && $clientEmail !== '') {
        if ($sessionEmail !== '' && $sessionEmail === $clientEmail) {
            $clientMatches = true;
        }
    }

    if (! $clientMatches && $clientName !== '') {
        if ($sessionName !== '' && $sessionName === $clientName) {
            $clientMatches = true;
        }
    }

    if (! $clientMatches) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => translate('desembarques.permission_denied', [], $currentLanguage),
        ]);
        exit;
    }
}

$storedName = (string) ($fileRecord['stored_name'] ?? '');
$originalName = (string) ($fileRecord['original_name'] ?? 'archivo');
$mimeType = (string) ($fileRecord['mime_type'] ?? 'application/octet-stream');
$fileConfig = file_storage_config();
$filePath = get_stored_file_path($storedName, $fileConfig);

if (! is_file($filePath) || ! is_readable($filePath)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.delete_not_found', [], $currentLanguage),
    ]);
    exit;
}

$filename = basename($originalName);
if ($filename === '') {
    $filename = 'archivo';
}

$extension = strtolower((string) ($fileRecord['extension'] ?? ''));
$currentExtension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
if ($extension !== '' && $currentExtension !== $extension) {
    $filename .= '.' . $extension;
}

$expectedSha256 = strtolower(trim((string) ($fileRecord['sha256'] ?? '')));
if (preg_match('/^[a-f0-9]{64}$/', $expectedSha256) === 1) {
    $currentSha256 = hash_file('sha256', $filePath);
    if (! is_string($currentSha256) || ! hash_equals($expectedSha256, strtolower($currentSha256))) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => $currentLanguage === 'en'
                ? 'Document integrity verification failed. The file was not delivered.'
                : 'Falló la verificación de integridad del documento. El archivo no fue entregado.',
        ]);
        exit;
    }
}

$filesize = isset($fileRecord['size']) ? (int) $fileRecord['size'] : filesize($filePath);

session_write_close();

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) $filesize);
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('X-Content-Type-Options: nosniff');

$handle = fopen($filePath, 'rb');

if ($handle === false) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('desembarques.files.download_error', [], $currentLanguage),
    ]);
    exit;
}

fpassthru($handle);
fclose($handle);

exit;
