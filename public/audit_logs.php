<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/database.php';

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();

if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

$user = $_SESSION['user'];
$userRole = (string) ($user['role'] ?? '');

if ($userRole !== 'admin') {
    header('Location: index.php');
    exit;
}

/**
 * @param mixed $value
 */
function sanitizeFilterValue($value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

/**
 * @param mixed $value
 */
function formatAuditValue($value): string
{
    if ($value === null) {
        return '—';
    }

    if ($value === '') {
        return '""';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    try {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '[complex]';
    } catch (Throwable $exception) {
        return '[complex]';
    }
}

/**
 * @param mixed $value
 */
function formatAuditJson($value): ?string
{
    if ($value === null) {
        return null;
    }

    try {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        $encoded = false;
    }

    return is_string($encoded) ? $encoded : null;
}

/**
 * @param mysqli_stmt $statement
 * @param string $types
 * @param array<int, mixed> $parameters
 */
function bindStatementParams(mysqli_stmt $statement, string $types, array &$parameters): void
{
    if ($types === '') {
        return;
    }

    $bindParams = [$types];

    foreach ($parameters as $key => &$parameter) {
        $bindParams[] = &$parameter;
    }

    $statement->bind_param(...$bindParams);
}

$themePreference = getUserThemePreference($user);
$currentTheme = $themePreference['theme'];
$themePreferenceKey = $themePreference['cookie_key'];
$themeLabel = translate('common.theme.label', [], $currentLanguage);
$themeLightLabel = translate('common.theme.light', [], $currentLanguage);
$themeDarkLabel = translate('common.theme.dark', [], $currentLanguage);
$currentThemeLabel = $currentTheme === 'dark' ? $themeDarkLabel : $themeLightLabel;
$themeToggleAnnouncement = trim($themeLabel) !== ''
    ? $themeLabel . ': ' . $currentThemeLabel
    : $currentThemeLabel;

$userName = (string) ($user['name'] ?? 'Administrador');
$userNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$roleLabelEscaped = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;
$navLinks = [
    [
        'href' => 'index.php',
        'label' => translate('audit_logs.nav.dashboard', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'reportes.php',
        'label' => translate('audit_logs.nav.reports', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'analytics.php',
        'label' => translate('dashboard.nav.analytics', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'control_tower.php',
        'label' => translate('dashboard.nav.control_tower', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'clients.php',
        'label' => translate('audit_logs.nav.manage_clients', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'statuses.php',
        'label' => translate('audit_logs.nav.manage_statuses', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'users.php',
        'label' => translate('dashboard.nav.manage_users', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'myprofile.php',
        'label' => translate('dashboard.nav.profile', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'logout.php',
        'label' => translate('audit_logs.nav.logout', [], $currentLanguage),
        'class' => 'btn btn-outline-danger btn-sm',
    ],
];

$actionFilter = sanitizeFilterValue($_GET['action'] ?? '', 50);
$entityTypeFilter = sanitizeFilterValue($_GET['entity_type'] ?? '', 100);
$userFilterRaw = sanitizeFilterValue($_GET['user_id'] ?? '', 10);
$userFilter = null;

if ($userFilterRaw !== '' && ctype_digit($userFilterRaw)) {
    $userFilter = (int) $userFilterRaw;
}

$searchFilter = sanitizeFilterValue($_GET['search'] ?? '', 100);
$pageValue = sanitizeFilterValue($_GET['page'] ?? '', 10);
$page = 1;

if ($pageValue !== '' && ctype_digit($pageValue)) {
    $page = max((int) $pageValue, 1);
}

$perPage = 20;

$auditLogs = [];
$totalLogs = 0;
$actions = [];
$entityTypes = [];
$userOptions = [];
$loadError = '';

$langQueryValue = isset($_GET['lang']) ? normalizeLanguage((string) $_GET['lang']) : null;

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    $loadError = $exception->getMessage();
    $connection = null;
}

if (isset($connection) && $connection instanceof mysqli) {
    try {
        $actionsQuery = 'SELECT DISTINCT action FROM audit_logs ORDER BY action ASC';
        $actionsResult = $connection->query($actionsQuery);

        if ($actionsResult instanceof mysqli_result) {
            while ($row = $actionsResult->fetch_assoc()) {
                $action = sanitizeFilterValue($row['action'] ?? '', 50);

                if ($action !== '') {
                    $actions[] = $action;
                }
            }

            $actionsResult->free();
        }
    } catch (Throwable $exception) {
        // Ignore action loading errors.
    }

    try {
        $entityQuery = 'SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC';
        $entityResult = $connection->query($entityQuery);

        if ($entityResult instanceof mysqli_result) {
            while ($row = $entityResult->fetch_assoc()) {
                $entityType = sanitizeFilterValue($row['entity_type'] ?? '', 100);

                if ($entityType !== '') {
                    $entityTypes[] = $entityType;
                }
            }

            $entityResult->free();
        }
    } catch (Throwable $exception) {
        // Ignore entity loading errors.
    }

    try {
        $userQuery = 'SELECT DISTINCT u.id, u.name, u.email FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id WHERE al.user_id IS NOT NULL ORDER BY u.name ASC, u.email ASC';
        $userResult = $connection->query($userQuery);

        if ($userResult instanceof mysqli_result) {
            while ($row = $userResult->fetch_assoc()) {
                if (! isset($row['id']) || ! ctype_digit((string) $row['id'])) {
                    continue;
                }

                $optionId = (int) $row['id'];
                $optionName = sanitizeFilterValue($row['name'] ?? '', 150);
                $optionEmail = sanitizeFilterValue($row['email'] ?? '', 150);
                $display = trim($optionName) !== ''
                    ? sprintf('%s (%s)', $optionName, $optionEmail)
                    : $optionEmail;

                $userOptions[] = [
                    'id' => $optionId,
                    'display' => $display !== '' ? $display : (string) $optionId,
                ];
            }

            $userResult->free();
        }
    } catch (Throwable $exception) {
        // Ignore user loading errors.
    }

    $filtersQueryBase = [];

    if ($userFilter !== null) {
        $filtersQueryBase['user_id'] = (string) $userFilter;
    }

    if ($actionFilter !== '') {
        $filtersQueryBase['action'] = $actionFilter;
    }

    if ($entityTypeFilter !== '') {
        $filtersQueryBase['entity_type'] = $entityTypeFilter;
    }

    if ($searchFilter !== '') {
        $filtersQueryBase['search'] = $searchFilter;
    }

    if ($langQueryValue !== null) {
        $filtersQueryBase['lang'] = $langQueryValue;
    }

    if ($loadError === '') {
        try {
            $conditions = [];
            $types = '';
            $params = [];

            if ($userFilter !== null) {
                $conditions[] = 'al.user_id = ?';
                $types .= 'i';
                $params[] = $userFilter;
            }

            if ($actionFilter !== '') {
                $conditions[] = 'al.action = ?';
                $types .= 's';
                $params[] = $actionFilter;
            }

            if ($entityTypeFilter !== '') {
                $conditions[] = 'al.entity_type = ?';
                $types .= 's';
                $params[] = $entityTypeFilter;
            }

            if ($searchFilter !== '') {
                $conditions[] = "(\n                    al.entity_id LIKE ? OR\n                    al.payload LIKE ? OR\n                    COALESCE(u.name, '') LIKE ? OR\n                    COALESCE(u.email, '') LIKE ? OR\n                    al.action LIKE ? OR\n                    al.entity_type LIKE ?\n                )";
                $searchTerm = '%' . $searchFilter . '%';

                for ($i = 0; $i < 6; $i++) {
                    $types .= 's';
                    $params[] = $searchTerm;
                }
            }

            $whereClause = $conditions !== []
                ? ' WHERE ' . implode(' AND ', $conditions)
                : '';

            $countQuery = 'SELECT COUNT(*) AS total FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id' . $whereClause;
            $countStatement = $connection->prepare($countQuery);

            if (! $countStatement) {
                throw new RuntimeException('Unable to prepare audit log count query.');
            }

            $countParams = $params;
            $countTypes = $types;

            bindStatementParams($countStatement, $countTypes, $countParams);
            $countStatement->execute();

            $countResult = $countStatement->get_result();
            $countRow = $countResult ? $countResult->fetch_assoc() : null;
            $countStatement->close();

            $totalLogs = $countRow && isset($countRow['total'])
                ? (int) $countRow['total']
                : 0;

            $totalPages = $totalLogs > 0
                ? (int) ceil($totalLogs / $perPage)
                : 1;

            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $offset = ($page - 1) * $perPage;

            $dataQuery = 'SELECT al.id, al.user_id, al.action, al.entity_type, al.entity_id, al.payload, al.created_at, u.name AS user_name, u.email AS user_email FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id'
                . $whereClause
                . ' ORDER BY al.created_at DESC, al.id DESC LIMIT ? OFFSET ?';

            $dataStatement = $connection->prepare($dataQuery);

            if (! $dataStatement) {
                throw new RuntimeException('Unable to prepare audit log query.');
            }

            $dataParams = $params;
            $dataTypes = $types . 'ii';
            $limitValue = $perPage;
            $offsetValue = $offset;
            $dataParams[] = $limitValue;
            $dataParams[] = $offsetValue;

            bindStatementParams($dataStatement, $dataTypes, $dataParams);
            $dataStatement->execute();

            $dataResult = $dataStatement->get_result();

            if ($dataResult instanceof mysqli_result) {
                while ($row = $dataResult->fetch_assoc()) {
                    $payloadRaw = (string) ($row['payload'] ?? '');
                    $payloadData = null;

                    try {
                        if ($payloadRaw !== '') {
                            $decoded = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
                            $payloadData = is_array($decoded) ? $decoded : null;
                        }
                    } catch (Throwable $exception) {
                        $payloadData = null;
                    }

                    $changes = [];
                    $beforeSnapshot = null;
                    $afterSnapshot = null;

                    if (is_array($payloadData)) {
                        if (array_key_exists('changes', $payloadData) && is_array($payloadData['changes'])) {
                            foreach ($payloadData['changes'] as $field => $change) {
                                if (! is_array($change)) {
                                    continue;
                                }

                                $beforeValue = $change['before'] ?? null;
                                $afterValue = $change['after'] ?? null;

                                $changes[(string) $field] = [
                                    'before' => $beforeValue,
                                    'after' => $afterValue,
                                ];
                            }
                        }

                        if (array_key_exists('before', $payloadData)) {
                            $beforeSnapshot = $payloadData['before'];
                        }

                        if (array_key_exists('after', $payloadData)) {
                            $afterSnapshot = $payloadData['after'];
                        }
                    }

                    $createdAtDisplay = '';

                    if (! empty($row['created_at'])) {
                        try {
                            $createdAt = new DateTimeImmutable((string) $row['created_at']);
                            $createdAtDisplay = $createdAt->format('d/m/Y H:i:s');
                        } catch (Throwable $exception) {
                            $createdAtDisplay = (string) $row['created_at'];
                        }
                    }

                    $auditLogs[] = [
                        'id' => isset($row['id']) ? (int) $row['id'] : null,
                        'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                        'user_name' => (string) ($row['user_name'] ?? ''),
                        'user_email' => (string) ($row['user_email'] ?? ''),
                        'action' => (string) ($row['action'] ?? ''),
                        'entity_type' => (string) ($row['entity_type'] ?? ''),
                        'entity_id' => isset($row['entity_id']) ? (string) $row['entity_id'] : null,
                        'payload_raw' => $payloadRaw,
                        'payload' => $payloadData,
                        'changes' => $changes,
                        'before' => $beforeSnapshot,
                        'after' => $afterSnapshot,
                        'created_at' => (string) ($row['created_at'] ?? ''),
                        'created_at_display' => $createdAtDisplay,
                    ];
                }

                $dataResult->free();
            }

            $dataStatement->close();
        } catch (Throwable $exception) {
            $loadError = translate('audit_logs.load.error', ['error' => $exception->getMessage()], $currentLanguage);
        }
    }
} else {
    $filtersQueryBase = [];
}

$greetingMessage = translate('audit_logs.greeting', ['name' => '<strong>' . $userNameEscaped . '</strong>'], $currentLanguage);
$currentRoleMessage = translate('audit_logs.current_role', ['role' => '<strong>' . $roleLabelEscaped . '</strong>'], $currentLanguage);

$filterAnyLabel = translate('audit_logs.filters.any', [], $currentLanguage);
$searchLabel = translate('audit_logs.filters.search', [], $currentLanguage);
$searchPlaceholder = translate('audit_logs.filters.search_placeholder', [], $currentLanguage);
$filterActionLabel = translate('audit_logs.filters.action', [], $currentLanguage);
$filterEntityLabel = translate('audit_logs.filters.entity_type', [], $currentLanguage);
$filterUserLabel = translate('audit_logs.filters.user', [], $currentLanguage);
$filterSubmitLabel = translate('audit_logs.filters.submit', [], $currentLanguage);
$filterResetLabel = translate('audit_logs.filters.reset', [], $currentLanguage);
$filterTitleLabel = translate('audit_logs.filters.title', [], $currentLanguage);
$noChangesLabel = translate('audit_logs.table.no_changes', [], $currentLanguage);
$unknownUserLabel = translate('audit_logs.user.unknown', [], $currentLanguage);
$beforeLabel = translate('audit_logs.table.payload.before', [], $currentLanguage);
$afterLabel = translate('audit_logs.table.payload.after', [], $currentLanguage);
$payloadDetailsLabel = translate('audit_logs.table.payload.raw', [], $currentLanguage);
$viewDetailsLabel = translate('audit_logs.payload.view_json', [], $currentLanguage);

$startRecord = $totalLogs === 0 ? 0 : ($page - 1) * $perPage + 1;
$endRecord = $totalLogs === 0 ? 0 : min($startRecord + $perPage - 1, $totalLogs);
$paginationSummary = translate('audit_logs.pagination.summary', [
    'from' => (string) $startRecord,
    'to' => (string) $endRecord,
    'total' => (string) $totalLogs,
], $currentLanguage);
$paginationPrevious = translate('audit_logs.pagination.previous', [], $currentLanguage);
$paginationNext = translate('audit_logs.pagination.next', [], $currentLanguage);

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('audit_logs.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-11">
                    <div class="card shadow-sm form-card">
                        <div class="card-header bg-primary text-white">
                            <h1 class="h3 mb-0"><?= htmlspecialchars(translate('audit_logs.header', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4">
                                <p class="lead mb-2 mb-lg-0"><?= $greetingMessage ?></p>
                            </div>
                            <p class="text-muted mb-4">
                                <?= htmlspecialchars(translate('audit_logs.intro', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <div class="mb-4">
                                <h2 class="h5 mb-3"><?= htmlspecialchars($filterTitleLabel, ENT_QUOTES, 'UTF-8') ?></h2>
                                <form id="audit-log-filter-form" class="row g-3" method="get" action="audit_logs.php" autocomplete="off">
                                    <?php if ($langQueryValue !== null): ?>
                                        <input type="hidden" name="lang" value="<?= htmlspecialchars($langQueryValue, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endif; ?>
                                    <div class="col-md-3">
                                        <label for="audit-log-user" class="form-label"><?= htmlspecialchars($filterUserLabel, ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="audit-log-user" name="user_id">
                                            <option value=""><?= htmlspecialchars($filterAnyLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php foreach ($userOptions as $option): ?>
                                                <?php
                                                    $optionId = (int) $option['id'];
                                                    $optionDisplay = (string) $option['display'];
                                                    $isSelected = $userFilter !== null && $userFilter === $optionId;
                                                ?>
                                                <option value="<?= htmlspecialchars((string) $optionId, ENT_QUOTES, 'UTF-8') ?>" <?= $isSelected ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($optionDisplay, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="audit-log-action" class="form-label"><?= htmlspecialchars($filterActionLabel, ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="audit-log-action" name="action">
                                            <option value=""><?= htmlspecialchars($filterAnyLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php foreach ($actions as $action): ?>
                                                <?php $isSelected = $actionFilter !== '' && $actionFilter === $action; ?>
                                                <option value="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" <?= $isSelected ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="audit-log-entity" class="form-label"><?= htmlspecialchars($filterEntityLabel, ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="audit-log-entity" name="entity_type">
                                            <option value=""><?= htmlspecialchars($filterAnyLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php foreach ($entityTypes as $entityType): ?>
                                                <?php $isSelected = $entityTypeFilter !== '' && $entityTypeFilter === $entityType; ?>
                                                <option value="<?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?>" <?= $isSelected ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="audit-log-search" class="form-label"><?= htmlspecialchars($searchLabel, ENT_QUOTES, 'UTF-8') ?></label>
                                        <input
                                            type="search"
                                            class="form-control"
                                            id="audit-log-search"
                                            name="search"
                                            value="<?= htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8') ?>"
                                            placeholder="<?= htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                    </div>
                                    <div class="col-12 d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-outline-secondary" data-clear-filters><?= htmlspecialchars($filterResetLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                        <button type="submit" class="btn btn-primary"><?= htmlspecialchars($filterSubmitLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                    </div>
                                </form>
                            </div>
                            <?php if ($loadError !== ''): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php else: ?>
                                <?php if ($auditLogs === []): ?>
                                    <div class="alert alert-info" role="alert">
                                        <?= htmlspecialchars(translate('audit_logs.table.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead>
                                                <tr>
                                                    <th scope="col">#</th>
                                                    <th scope="col"><?= htmlspecialchars(translate('audit_logs.table.headers.timestamp', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                    <th scope="col"><?= htmlspecialchars(translate('audit_logs.table.headers.user', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                    <th scope="col"><?= htmlspecialchars(translate('audit_logs.table.headers.action', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                    <th scope="col"><?= htmlspecialchars(translate('audit_logs.table.headers.entity', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                    <th scope="col"><?= htmlspecialchars(translate('audit_logs.table.headers.changes', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($auditLogs as $log): ?>
                                                    <?php
                                                        $entityLabel = $log['entity_type'] !== ''
                                                            ? $log['entity_type']
                                                            : translate('audit_logs.table.entity_unknown', [], $currentLanguage);
                                                        $entityDisplay = $log['entity_id'] !== null && $log['entity_id'] !== ''
                                                            ? $entityLabel . ' #' . $log['entity_id']
                                                            : $entityLabel;
                                                        $userDisplay = trim($log['user_name']) !== ''
                                                            ? $log['user_name']
                                                            : (trim($log['user_email']) !== '' ? $log['user_email'] : $unknownUserLabel);
                                                        $beforeJson = formatAuditJson($log['before']);
                                                        $afterJson = formatAuditJson($log['after']);
                                                        $payloadJson = formatAuditJson($log['payload']);
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string) ($log['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td>
                                                            <div class="fw-semibold">
                                                                <?= htmlspecialchars($log['created_at_display'], ENT_QUOTES, 'UTF-8') ?>
                                                            </div>
                                                            <?php if ($log['created_at_display'] !== $log['created_at']): ?>
                                                                <div class="text-muted small"><?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="fw-semibold"><?= htmlspecialchars($userDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                                                            <?php if (trim($log['user_email']) !== '' && trim($log['user_email']) !== trim($userDisplay)): ?>
                                                                <div class="text-muted small"><?= htmlspecialchars($log['user_email'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($entityDisplay, ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td>
                                                            <?php if ($log['changes'] !== []): ?>
                                                                <ul class="list-unstyled mb-2 small">
                                                                    <?php foreach ($log['changes'] as $field => $change): ?>
                                                                        <?php
                                                                            $beforeValue = formatAuditValue($change['before'] ?? null);
                                                                            $afterValue = formatAuditValue($change['after'] ?? null);
                                                                        ?>
                                                                        <li>
                                                                            <strong><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?>:</strong>
                                                                            <span class="text-muted"><?= htmlspecialchars($beforeValue, ENT_QUOTES, 'UTF-8') ?></span>
                                                                            <span aria-hidden="true" class="px-1">→</span>
                                                                            <span class="fw-semibold"><?= htmlspecialchars($afterValue, ENT_QUOTES, 'UTF-8') ?></span>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            <?php else: ?>
                                                                <p class="text-muted small mb-2"><?= htmlspecialchars($noChangesLabel, ENT_QUOTES, 'UTF-8') ?></p>
                                                            <?php endif; ?>
                                                            <details class="mt-1">
                                                                <summary class="small text-decoration-underline" role="button"><?= htmlspecialchars($viewDetailsLabel, ENT_QUOTES, 'UTF-8') ?></summary>
                                                                <div class="mt-2">
                                                                    <?php if ($beforeJson !== null): ?>
                                                                        <h6 class="h6 small text-uppercase text-muted mb-1"><?= htmlspecialchars($beforeLabel, ENT_QUOTES, 'UTF-8') ?></h6>
                                                                        <pre class="bg-light rounded p-2 small text-break"><?= htmlspecialchars($beforeJson, ENT_QUOTES, 'UTF-8') ?></pre>
                                                                    <?php endif; ?>
                                                                    <?php if ($afterJson !== null): ?>
                                                                        <h6 class="h6 small text-uppercase text-muted mt-3 mb-1"><?= htmlspecialchars($afterLabel, ENT_QUOTES, 'UTF-8') ?></h6>
                                                                        <pre class="bg-light rounded p-2 small text-break"><?= htmlspecialchars($afterJson, ENT_QUOTES, 'UTF-8') ?></pre>
                                                                    <?php endif; ?>
                                                                    <?php if ($payloadJson !== null): ?>
                                                                        <h6 class="h6 small text-uppercase text-muted mt-3 mb-1"><?= htmlspecialchars($payloadDetailsLabel, ENT_QUOTES, 'UTF-8') ?></h6>
                                                                        <pre class="bg-light rounded p-2 small text-break mb-0"><?= htmlspecialchars($payloadJson, ENT_QUOTES, 'UTF-8') ?></pre>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </details>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mt-3">
                                        <p class="text-muted mb-2 mb-lg-0 small"><?= htmlspecialchars($paginationSummary, ENT_QUOTES, 'UTF-8') ?></p>
                                        <?php
                                            $paginationLinks = [];
                                            if ($totalLogs > 0) {
                                                $totalPages = (int) ceil($totalLogs / $perPage);
                                            } else {
                                                $totalPages = 1;
                                            }
                                        ?>
                                        <?php if ($totalPages > 1): ?>
                                            <nav aria-label="Audit log navigation" id="audit-log-pagination">
                                                <ul class="pagination mb-0">
                                                    <?php
                                                        $previousPage = max($page - 1, 1);
                                                        $previousQuery = $filtersQueryBase;
                                                        $previousQuery['page'] = (string) $previousPage;
                                                        $previousUrl = 'audit_logs.php' . ($previousQuery !== [] ? '?' . http_build_query($previousQuery) : '');
                                                    ?>
                                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= htmlspecialchars($previousUrl, ENT_QUOTES, 'UTF-8') ?>" data-page="<?= htmlspecialchars((string) $previousPage, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($paginationPrevious, ENT_QUOTES, 'UTF-8') ?>">
                                                            <span aria-hidden="true">&laquo;</span>
                                                            <span class="visually-hidden"><?= htmlspecialchars($paginationPrevious, ENT_QUOTES, 'UTF-8') ?></span>
                                                        </a>
                                                    </li>
                                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                        <?php
                                                            $pageQuery = $filtersQueryBase;
                                                            $pageQuery['page'] = (string) $i;
                                                            $pageUrl = 'audit_logs.php' . ($pageQuery !== [] ? '?' . http_build_query($pageQuery) : '');
                                                        ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>" data-page="<?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>">
                                                                <?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8') ?>
                                                            </a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    <?php
                                                        $nextPage = min($page + 1, $totalPages);
                                                        $nextQuery = $filtersQueryBase;
                                                        $nextQuery['page'] = (string) $nextPage;
                                                        $nextUrl = 'audit_logs.php' . ($nextQuery !== [] ? '?' . http_build_query($nextQuery) : '');
                                                    ?>
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>" data-page="<?= htmlspecialchars((string) $nextPage, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($paginationNext, ENT_QUOTES, 'UTF-8') ?>">
                                                            <span aria-hidden="true">&raquo;</span>
                                                            <span class="visually-hidden"><?= htmlspecialchars($paginationNext, ENT_QUOTES, 'UTF-8') ?></span>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </nav>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/partials/footer.php'; ?>
        <script>
            window.AppConfig = {
                language: <?= json_encode($currentLanguage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
        </script>
        <?php
            $includeSweetAlert = false;
            $pageScripts = [
                'assets/js/theme.js',
                'assets/js/audit_logs.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
