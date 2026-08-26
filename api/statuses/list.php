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
if ($userRole !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('statuses.error.permission_denied', [], $currentLanguage),
    ]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

/**
 * @param array<string, string> $errors
 */
function respondWithError(int $statusCode, string $message, array $errors = []): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'errors' => $errors,
    ]);
    exit;
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

$statuses = [];

try {
    $query = 'SELECT id, slug, name_es, name_en, is_active, is_default, created_at FROM desembarque_statuses ORDER BY is_default DESC, name_es ASC, id ASC';
    $result = $connection->query($query);

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $createdAtDisplay = '';

            if (! empty($row['created_at'])) {
                try {
                    $createdAt = new DateTimeImmutable((string) $row['created_at']);
                    $createdAtDisplay = $createdAt->format('d/m/Y H:i');
                } catch (Throwable $exception) {
                    $createdAtDisplay = (string) $row['created_at'];
                }
            }

            $statuses[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'slug' => (string) ($row['slug'] ?? ''),
                'name_es' => (string) ($row['name_es'] ?? ''),
                'name_en' => (string) ($row['name_en'] ?? ''),
                'is_active' => (int) ($row['is_active'] ?? 0) === 1,
                'is_default' => (int) ($row['is_default'] ?? 0) === 1,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'created_at_display' => $createdAtDisplay,
            ];
        }

        $result->free();
    }
} catch (Throwable $exception) {
    respondWithError(
        500,
        translate('statuses.load.error', ['error' => $exception->getMessage()], $currentLanguage)
    );
}

echo json_encode([
    'success' => true,
    'statuses' => $statuses,
]);
