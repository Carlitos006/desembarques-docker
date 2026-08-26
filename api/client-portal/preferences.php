<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/database.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if (! isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$allowedWidgets = ['summary', 'milestones', 'documents', 'messages'];

/**
 * @return array<int, string>
 */
function default_widgets(): array
{
    return ['summary', 'milestones', 'documents', 'messages'];
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function sanitize_widgets($value, array $allowedWidgets): array
{
    if (! is_array($value)) {
        return default_widgets();
    }

    $normalized = [];

    foreach ($value as $item) {
        if (! is_string($item)) {
            continue;
        }

        $slug = trim($item);

        if ($slug === '' || ! in_array($slug, $allowedWidgets, true)) {
            continue;
        }

        if (! in_array($slug, $normalized, true)) {
            $normalized[] = $slug;
        }
    }

    if ($normalized === []) {
        return default_widgets();
    }

    return $normalized;
}

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

$userId = (int) $_SESSION['user']['id'];

if ($method === 'GET') {
    $query = 'SELECT widgets FROM client_portal_preferences WHERE user_id = ? LIMIT 1';
    $statement = $connection->prepare($query);

    if (! $statement) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => translate('client_portal.error.load_failed', [], $currentLanguage),
        ]);
        exit;
    }

    $statement->bind_param('i', $userId);
    $statement->execute();

    $result = $statement->get_result();
    $widgets = default_widgets();

    if ($result instanceof mysqli_result) {
        $row = $result->fetch_assoc();

        if ($row && isset($row['widgets'])) {
            $decoded = json_decode((string) $row['widgets'], true);

            $widgets = sanitize_widgets($decoded, $allowedWidgets);
        }

        $result->free();
    }

    $statement->close();

    echo json_encode([
        'success' => true,
        'data' => [
            'widgets' => $widgets,
        ],
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => translate('common.method_not_allowed', [], $currentLanguage),
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = $rawInput !== '' ? json_decode($rawInput, true) : null;

if ($payload === null && $_POST !== []) {
    $payload = $_POST;
}

$widgets = default_widgets();

if (is_array($payload) && array_key_exists('widgets', $payload)) {
    $widgets = sanitize_widgets($payload['widgets'], $allowedWidgets);
}

$encodedWidgets = json_encode($widgets, JSON_UNESCAPED_UNICODE);

$query = 'INSERT INTO client_portal_preferences (user_id, widgets) VALUES (?, ?) ON DUPLICATE KEY UPDATE widgets = VALUES(widgets), updated_at = CURRENT_TIMESTAMP';
$statement = $connection->prepare($query);

if (! $statement) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.preferences.error.save', [], $currentLanguage),
    ]);
    exit;
}

$statement->bind_param('is', $userId, $encodedWidgets);

try {
    $statement->execute();
} catch (mysqli_sql_exception $exception) {
    $statement->close();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => translate('client_portal.preferences.error.save', [], $currentLanguage),
    ]);
    exit;
}

$statement->close();

echo json_encode([
    'success' => true,
    'data' => [
        'widgets' => $widgets,
    ],
]);
