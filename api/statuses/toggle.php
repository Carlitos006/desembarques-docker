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

$idValue = (string) ($_POST['id'] ?? '');
$field = (string) ($_POST['field'] ?? '');
$valueInput = $_POST['value'] ?? null;

if ($idValue === '' || ! ctype_digit($idValue)) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), [
        'id' => translate('statuses.validation.id_invalid', [], $currentLanguage),
    ]);
}

$statusId = (int) $idValue;

if ($statusId <= 0) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), [
        'id' => translate('statuses.validation.id_invalid', [], $currentLanguage),
    ]);
}

$field = trim($field);

if (! in_array($field, ['is_active', 'is_default'], true)) {
    respondWithError(422, translate('validation.errors', [], $currentLanguage), [
        'field' => translate('statuses.toggle.invalid_field', [], $currentLanguage),
    ]);
}

$desiredValue = booleanValue($valueInput);

$transactionStarted = false;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    respondWithError(500, $exception->getMessage());
}

try {
    $lookupStatement = $connection->prepare('SELECT id, slug, name_es, name_en, is_active, is_default, created_at FROM desembarque_statuses WHERE id = ? LIMIT 1');

    if (! $lookupStatement) {
        throw new RuntimeException('Unable to prepare status lookup statement.');
    }

    $lookupStatement->bind_param('i', $statusId);
    $lookupStatement->execute();

    $lookupResult = $lookupStatement->get_result();
    $status = $lookupResult ? $lookupResult->fetch_assoc() : null;

    if ($lookupResult instanceof mysqli_result) {
        $lookupResult->free();
    }

    $lookupStatement->close();

    if (! $status) {
        respondWithError(404, translate('statuses.update.not_found', [], $currentLanguage));
    }

    $isCurrentlyDefault = (int) ($status['is_default'] ?? 0) === 1;
    $isCurrentlyActive = (int) ($status['is_active'] ?? 0) === 1;

    if ($field === 'is_active') {
        if (! $desiredValue && $isCurrentlyDefault) {
            respondWithError(422, translate('statuses.toggle.default_requires_active', [], $currentLanguage));
        }

        $updateStatement = $connection->prepare('UPDATE desembarque_statuses SET is_active = ? WHERE id = ?');

        if (! $updateStatement) {
            throw new RuntimeException('Unable to prepare status active toggle statement.');
        }

        $activeValue = $desiredValue ? 1 : 0;
        $updateStatement->bind_param('ii', $activeValue, $statusId);
        $updateStatement->execute();
        $updateStatement->close();
    } else {
        // Toggle default flag.
        if ($desiredValue) {
            if (! $isCurrentlyActive) {
                respondWithError(422, translate('statuses.toggle.default_requires_active', [], $currentLanguage));
            }

            if (! $connection->begin_transaction()) {
                throw new RuntimeException('Unable to start status default transaction.');
            }

            $transactionStarted = true;

            $clearStatement = $connection->prepare('UPDATE desembarque_statuses SET is_default = 0 WHERE id <> ?');

            if (! $clearStatement) {
                throw new RuntimeException('Unable to prepare default clearing statement.');
            }

            $clearStatement->bind_param('i', $statusId);
            $clearStatement->execute();
            $clearStatement->close();

            $setStatement = $connection->prepare('UPDATE desembarque_statuses SET is_default = 1 WHERE id = ?');

            if (! $setStatement) {
                throw new RuntimeException('Unable to prepare default update statement.');
            }

            $setStatement->bind_param('i', $statusId);
            $setStatement->execute();
            $setStatement->close();

            $connection->commit();
            $transactionStarted = false;
        } else {
            if ($isCurrentlyDefault) {
                $countStatement = $connection->prepare('SELECT COUNT(*) AS total FROM desembarque_statuses WHERE is_default = 1 AND id <> ?');

                if (! $countStatement) {
                    throw new RuntimeException('Unable to prepare default count statement.');
                }

                $countStatement->bind_param('i', $statusId);
                $countStatement->execute();

                $countResult = $countStatement->get_result();
                $countRow = $countResult ? $countResult->fetch_assoc() : null;

                if ($countResult instanceof mysqli_result) {
                    $countResult->free();
                }

                $countStatement->close();

                $remainingDefaults = $countRow && isset($countRow['total']) ? (int) $countRow['total'] : 0;

                if ($remainingDefaults === 0) {
                    respondWithError(422, translate('statuses.toggle.default_guard', [], $currentLanguage));
                }
            }

            $updateStatement = $connection->prepare('UPDATE desembarque_statuses SET is_default = 0 WHERE id = ?');

            if (! $updateStatement) {
                throw new RuntimeException('Unable to prepare status default toggle statement.');
            }

            $updateStatement->bind_param('i', $statusId);
            $updateStatement->execute();
            $updateStatement->close();
        }
    }

    $refreshStatement = $connection->prepare('SELECT id, slug, name_es, name_en, is_active, is_default, created_at FROM desembarque_statuses WHERE id = ? LIMIT 1');

    if (! $refreshStatement) {
        throw new RuntimeException('Unable to prepare status refresh statement.');
    }

    $refreshStatement->bind_param('i', $statusId);
    $refreshStatement->execute();

    $refreshResult = $refreshStatement->get_result();
    $updatedStatus = $refreshResult ? $refreshResult->fetch_assoc() : null;

    if ($refreshResult instanceof mysqli_result) {
        $refreshResult->free();
    }

    $refreshStatement->close();

    if (! $updatedStatus) {
        throw new RuntimeException('Unable to retrieve the updated status.');
    }

    $defaultLookup = $connection->prepare('SELECT id FROM desembarque_statuses WHERE is_default = 1 LIMIT 1');

    if (! $defaultLookup) {
        throw new RuntimeException('Unable to prepare default lookup statement.');
    }

    $defaultLookup->execute();

    $defaultResult = $defaultLookup->get_result();
    $defaultRow = $defaultResult ? $defaultResult->fetch_assoc() : null;

    if ($defaultResult instanceof mysqli_result) {
        $defaultResult->free();
    }

    $defaultLookup->close();

    $defaultStatusId = null;

    if ($defaultRow && isset($defaultRow['id']) && ctype_digit((string) $defaultRow['id'])) {
        $defaultStatusId = (int) $defaultRow['id'];
    }

    $createdAtDisplay = '';

    if (! empty($updatedStatus['created_at'])) {
        try {
            $createdAt = new DateTimeImmutable((string) $updatedStatus['created_at']);
            $createdAtDisplay = $createdAt->format('d/m/Y H:i');
        } catch (Throwable $exception) {
            $createdAtDisplay = (string) $updatedStatus['created_at'];
        }
    }

    $messageKey = $field === 'is_active'
        ? 'statuses.toggle.active.success'
        : 'statuses.toggle.default.success';

    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => translate($messageKey, [], $currentLanguage),
        'status' => [
            'id' => (int) $updatedStatus['id'],
            'slug' => (string) $updatedStatus['slug'],
            'name_es' => (string) $updatedStatus['name_es'],
            'name_en' => (string) $updatedStatus['name_en'],
            'is_active' => (int) ($updatedStatus['is_active'] ?? 0) === 1,
            'is_default' => (int) ($updatedStatus['is_default'] ?? 0) === 1,
            'created_at' => (string) ($updatedStatus['created_at'] ?? ''),
            'created_at_display' => $createdAtDisplay,
        ],
        'default_status_id' => $defaultStatusId,
    ]);
} catch (Throwable $exception) {
    if ($transactionStarted) {
        try {
            $connection->rollback();
        } catch (Throwable $rollbackException) {
            // Ignore rollback error.
        }
    }

    respondWithError(500, translate('statuses.toggle.error.generic', ['error' => $exception->getMessage()], $currentLanguage));
}
