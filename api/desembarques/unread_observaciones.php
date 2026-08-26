<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/unread_observaciones_helpers.php';

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

$user = $_SESSION['user'];
$userRole = (string) ($user['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario', 'cliente'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('reports.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

$observacionesLastSeen = isset($_SESSION['observaciones_last_seen']) && is_array($_SESSION['observaciones_last_seen'])
    ? $_SESSION['observaciones_last_seen']
    : [];

$items = [];

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
    exit;
}

try {
    $items = fetchUnreadObservacionesItems($connection, $user, $observacionesLastSeen, $currentLanguage);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('reports.alert.load_error', [], $currentLanguage),
    ]);
    exit;
}

http_response_code(200);

echo json_encode([
    'success' => true,
    'has_unread' => $items !== [],
    'items' => $items,
]);
