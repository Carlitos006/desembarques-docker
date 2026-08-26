<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/unread_observaciones_helpers.php';

$currentLanguage = getAppLanguage();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

if (! isset($_SESSION['user']['id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => translate('reports.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

$observacionesLastSeen = isset($_SESSION['observaciones_last_seen']) && is_array($_SESSION['observaciones_last_seen'])
    ? $_SESSION['observaciones_last_seen']
    : [];

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
    exit;
}

session_write_close();

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

if (function_exists('header_remove')) {
    @header_remove('Pragma');
    @header_remove('Content-Length');
}

echo "retry: 5000\n\n";
@ob_flush();
@flush();

ignore_user_abort(true);
set_time_limit(0);

$pollIntervalSeconds = 5;
$heartbeatIntervalSeconds = 20;
$maxStreamDurationSeconds = 300;
$startedAt = time();
$lastHash = null;
$lastHeartbeat = 0;

while (true) {
    if (connection_aborted()) {
        break;
    }

    try {
        $items = fetchUnreadObservacionesItems($connection, $user, $observacionesLastSeen, $currentLanguage);
    } catch (Throwable $exception) {
        $errorPayload = [
            'success' => false,
            'message' => translate('reports.alert.load_error', [], $currentLanguage),
        ];
        $encodedError = json_encode($errorPayload);

        if ($encodedError !== false) {
            echo "event: error\n";
            echo 'data: ' . $encodedError . "\n\n";
            @ob_flush();
            @flush();
        }

        break;
    }

    $payload = [
        'items' => $items,
        'has_unread' => $items !== [],
        'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ];

    $encodedPayload = json_encode($payload);

    if ($encodedPayload === false) {
        $encodedPayload = json_encode([
            'items' => [],
            'has_unread' => false,
        ]);
    }

    if ($encodedPayload !== false) {
        $currentHash = md5($encodedPayload);

        if ($currentHash !== $lastHash) {
            echo "event: unread\n";
            echo 'data: ' . $encodedPayload . "\n\n";
            @ob_flush();
            @flush();
            $lastHash = $currentHash;
        }
    }

    $now = time();

    if ($now - $lastHeartbeat >= $heartbeatIntervalSeconds) {
        echo "event: heartbeat\n";
        echo 'data: {"ts":' . $now . "}\n\n";
        @ob_flush();
        @flush();
        $lastHeartbeat = $now;
    }

    if (($now - $startedAt) >= $maxStreamDurationSeconds) {
        break;
    }

    if ($pollIntervalSeconds > 0) {
        sleep($pollIntervalSeconds);
    } else {
        usleep(200000);
    }
}

exit;
