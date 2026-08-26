<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/_documents.php';

$currentLanguage = getAppLanguage();
header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(405, ['success' => false, 'message' => translate('common.method_not_allowed', [], $currentLanguage)]);
}
if (! isset($_SESSION['user']['id'])) {
    $respond(401, ['success' => false, 'message' => translate('common.session_missing_user', [], $currentLanguage)]);
}

$fileIdRaw = trim((string) ($_GET['id'] ?? ''));
if ($fileIdRaw === '' || ! ctype_digit($fileIdRaw) || (int) $fileIdRaw <= 0) {
    $respond(422, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Invalid document.' : 'Documento no válido.']);
}
$fileId = (int) $fileIdRaw;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
    $file = expediente_document_load_file($connection, $fileId);
    if (! $file || ! expediente_document_is_supported_file($file)) {
        $respond(404, ['success' => false, 'message' => $currentLanguage === 'en' ? 'The document was not found.' : 'No se encontró el documento.']);
    }

    $allowed = expediente_document_user_can_access(
        $file,
        (string) ($_SESSION['user']['role'] ?? ''),
        (int) ($_SESSION['user']['id'] ?? 0),
        (string) ($_SESSION['user']['name'] ?? ''),
        (string) ($_SESSION['user']['email'] ?? '')
    );
    if (! $allowed) {
        $respond(403, ['success' => false, 'message' => translate('desembarques.permission_denied', [], $currentLanguage)]);
    }

    $currentHash = expediente_document_hash_file((string) ($file['stored_name'] ?? ''));
    $expectedHash = strtolower(trim((string) ($file['sha256'] ?? '')));
    $hasExpected = preg_match('/^[a-f0-9]{64}$/', $expectedHash) === 1;
    $verified = $hasExpected && hash_equals($expectedHash, $currentHash);

    $respond(200, [
        'success' => true,
        'verified' => $verified,
        'has_baseline' => $hasExpected,
        'expected_sha256' => $expectedHash,
        'current_sha256' => $currentHash,
        'message' => $verified
            ? ($currentLanguage === 'en' ? 'Document integrity verified.' : 'Integridad del documento verificada.')
            : ($hasExpected
                ? ($currentLanguage === 'en' ? 'Integrity mismatch detected.' : 'Se detectó una diferencia de integridad.')
                : ($currentLanguage === 'en' ? 'This legacy document has no integrity baseline.' : 'Este documento legacy todavía no tiene una huella de integridad registrada.')),
    ]);
} catch (Throwable $exception) {
    error_log('[document-verify] ' . $exception->getMessage());
    $respond(500, ['success' => false, 'message' => $currentLanguage === 'en' ? 'Unable to verify document integrity.' : 'No fue posible verificar la integridad del documento.']);
}
