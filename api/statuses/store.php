<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/csrf.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$csrfTokenValue = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

if (! validate_csrf_token($csrfTokenValue)) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => translate('common.csrf_token_invalid', [], $currentLanguage),
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

function sanitizeSlug(string $value): string
{
    $value = trim(mb_strtolower($value));

    return $value;
}

function sanitizeName(string $value): string
{
    return trim(strip_tags($value));
}

function booleanValue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_string($value)) {
        $value = strtolower(trim($value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    if (is_int($value)) {
        return $value === 1;
    }

    return false;
}

$rawSlug = (string) ($_POST['slug'] ?? '');
$nameEs = sanitizeName((string) ($_POST['name_es'] ?? ''));
$nameEn = sanitizeName((string) ($_POST['name_en'] ?? ''));
$isActive = booleanValue($_POST['is_active'] ?? '1');
$isDefault = booleanValue($_POST['is_default'] ?? '');

$slug = sanitizeSlug($rawSlug);

$errors = [];

if ($slug === '') {
    $errors['slug'] = translate('statuses.validation.slug_required', [], $currentLanguage);
} elseif (mb_strlen($slug) > 50) {
    $errors['slug'] = translate('statuses.validation.slug_length', [], $currentLanguage);
} elseif (! preg_match('/^[a-z0-9]+(?:[a-z0-9_-]*[a-z0-9])?$/', $slug)) {
    $errors['slug'] = translate('statuses.validation.slug_invalid', [], $currentLanguage);
}

if ($nameEs === '') {
    $errors['name_es'] = translate('statuses.validation.name_es_required', [], $currentLanguage);
}

if ($nameEn === '') {
    $errors['name_en'] = translate('statuses.validation.name_en_required', [], $currentLanguage);
}

if ($nameEs !== '' && mb_strlen($nameEs) > 100) {
    $errors['name_es'] = translate('statuses.validation.name_length', [], $currentLanguage);
}

if ($nameEn !== '' && mb_strlen($nameEn) > 100) {
    $errors['name_en'] = translate('statuses.validation.name_length', [], $currentLanguage);
}

if ($isDefault && ! $isActive) {
    $errors['is_default'] = translate('statuses.validation.default_requires_active', [], $currentLanguage);
}

if ($errors !== []) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), $errors);
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

try {
    $normalizedSlug = $slug;

    $duplicateStatement = $connection->prepare('SELECT id FROM desembarque_statuses WHERE slug = ? LIMIT 1');

    if (! $duplicateStatement) {
        throw new RuntimeException('Unable to prepare duplicate status lookup statement.');
    }

    $duplicateStatement->bind_param('s', $normalizedSlug);
    $duplicateStatement->execute();

    $duplicateResult = $duplicateStatement->get_result();
    $existingStatus = $duplicateResult ? $duplicateResult->fetch_assoc() : null;

    if ($duplicateResult instanceof mysqli_result) {
        $duplicateResult->free();
    }

    $duplicateStatement->close();

    if ($existingStatus) {
        respondWithError(409, translate('statuses.validation.slug_unique', [], $currentLanguage));
    }

    $connection->begin_transaction();

    $insertStatement = $connection->prepare('INSERT INTO desembarque_statuses (slug, name_es, name_en, is_active, is_default) VALUES (?, ?, ?, ?, ?)');

    if (! $insertStatement) {
        throw new RuntimeException('Unable to prepare status insert statement.');
    }

    if ($isDefault) {
        $isActive = true;
    }

    $isActiveValue = $isActive ? 1 : 0;
    $isDefaultValue = $isDefault ? 1 : 0;

    $insertNameEs = mb_substr($nameEs, 0, 100);
    $insertNameEn = mb_substr($nameEn, 0, 100);

    $insertStatement->bind_param('sssii', $normalizedSlug, $insertNameEs, $insertNameEn, $isActiveValue, $isDefaultValue);
    $insertStatement->execute();

    $newStatusId = (int) $insertStatement->insert_id;
    $insertStatement->close();

    if ($isDefault) {
        $clearDefaultStatement = $connection->prepare('UPDATE desembarque_statuses SET is_default = 0 WHERE id <> ?');

        if (! $clearDefaultStatement) {
            throw new RuntimeException('Unable to prepare status default clearing statement.');
        }

        $clearDefaultStatement->bind_param('i', $newStatusId);
        $clearDefaultStatement->execute();
        $clearDefaultStatement->close();
    }

    $selectStatement = $connection->prepare('SELECT id, slug, name_es, name_en, is_active, is_default, created_at FROM desembarque_statuses WHERE id = ? LIMIT 1');

    if (! $selectStatement) {
        throw new RuntimeException('Unable to prepare status retrieval statement.');
    }

    $selectStatement->bind_param('i', $newStatusId);
    $selectStatement->execute();

    $selectResult = $selectStatement->get_result();
    $status = $selectResult ? $selectResult->fetch_assoc() : null;

    if ($selectResult instanceof mysqli_result) {
        $selectResult->free();
    }

    $selectStatement->close();

    if (! $status) {
        throw new RuntimeException('Unable to retrieve the newly created status.');
    }

    $connection->commit();
} catch (Throwable $exception) {
    try {
        $connection->rollback();
    } catch (Throwable $rollbackException) {
        // Ignore rollback exception.
    }

    respondWithError(500, translate('statuses.create.error.generic', ['error' => $exception->getMessage()], $currentLanguage));
}

$createdAtDisplay = '';

if (! empty($status['created_at'])) {
    try {
        $createdAt = new DateTimeImmutable((string) $status['created_at']);
        $createdAtDisplay = $createdAt->format('d/m/Y H:i');
    } catch (Throwable $exception) {
        $createdAtDisplay = (string) $status['created_at'];
    }
}

http_response_code(201);

echo json_encode([
    'success' => true,
    'message' => translate('statuses.create.success', [], $currentLanguage),
    'status' => [
        'id' => (int) $status['id'],
        'slug' => (string) $status['slug'],
        'name_es' => (string) $status['name_es'],
        'name_en' => (string) $status['name_en'],
        'is_active' => (int) ($status['is_active'] ?? 0) === 1,
        'is_default' => (int) ($status['is_default'] ?? 0) === 1,
        'created_at' => (string) ($status['created_at'] ?? ''),
        'created_at_display' => $createdAtDisplay,
    ],
]);
