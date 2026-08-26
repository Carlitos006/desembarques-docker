<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/csrf.php';

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();

if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$themePreference = getUserThemePreference($_SESSION['user']);
$currentTheme = $themePreference['theme'];
$themePreferenceKey = $themePreference['cookie_key'];
$themeLabel = translate('common.theme.label', [], $currentLanguage);
$themeLightLabel = translate('common.theme.light', [], $currentLanguage);
$themeDarkLabel = translate('common.theme.dark', [], $currentLanguage);
$currentThemeLabel = $currentTheme === 'dark' ? $themeDarkLabel : $themeLightLabel;
$themeToggleAnnouncement = trim($themeLabel) !== ''
    ? $themeLabel . ': ' . $currentThemeLabel
    : $currentThemeLabel;

$userName = (string) ($_SESSION['user']['name'] ?? 'Administrador');
$adminRoleLabel = translateRoleLabel('admin', $currentLanguage);
$adminRoleLabelEscaped = htmlspecialchars($adminRoleLabel, ENT_QUOTES, 'UTF-8');
$userNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
$usersTranslations = getClientTranslations([
    'users.alert.success',
    'users.alert.validation',
    'users.alert.error',
    'users.alert.update_success',
    'users.alert.update_validation',
    'users.alert.update_error',
    'users.form.submit',
    'users.form.update',
    'users.table.actions.edit',
    'users.form.client_help',
    'users.form.client_empty',
    'common.csrf_token_invalid',
], $currentLanguage);
$csrfToken = csrf_token();
$greetingMessage = translate('users.greeting', ['name' => '<strong>' . $userNameEscaped . '</strong>'], $currentLanguage);
$currentRoleMessage = translate('users.current_role', ['role' => '<strong>' . $adminRoleLabelEscaped . '</strong>'], $currentLanguage);

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $adminRoleLabel;
$navLinks = [
    [
        'href' => 'reportes.php',
        'label' => translate('dashboard.nav.reports', [], $currentLanguage),
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
        'label' => translate('dashboard.nav.manage_clients', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'statuses.php',
        'label' => translate('users.nav.manage_statuses', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'index.php',
        'label' => translate('users.nav.back', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'audit_logs.php',
        'label' => translate('dashboard.nav.audit_logs', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'myprofile.php',
        'label' => translate('dashboard.nav.profile', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'logout.php',
        'label' => translate('users.nav.logout', [], $currentLanguage),
        'class' => 'btn btn-outline-danger btn-sm',
    ],
];

$users = [];
$loadError = '';
$assignableClients = [];
$clientOptionsError = '';

$defaultTimezoneName = (string) date_default_timezone_get();

if ($defaultTimezoneName === '') {
    $defaultTimezoneName = 'UTC-6';
}

$viewerTimezoneName = (string) ($_SESSION['user']['timezone'] ?? $defaultTimezoneName);

try {
    $viewerTimezone = new DateTimeZone($viewerTimezoneName);
} catch (Throwable $exception) {
    try {
        $viewerTimezone = new DateTimeZone($defaultTimezoneName);
    } catch (Throwable $innerException) {
        $viewerTimezone = null;
    }
}

try {
    $utcTimezone = new DateTimeZone('UTC-6');
} catch (Throwable $exception) {
    $utcTimezone = null;
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    $loadError = $exception->getMessage();
    $connection = null;
}

if ($loadError === '' && $connection instanceof mysqli) {
    try {
        $query = 'SELECT id, name, email, role, created_at, last_login_at FROM users ORDER BY created_at DESC';
        $result = $connection->query($query);

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $role = (string) ($row['role'] ?? 'usuario');
                $createdAtDisplay = '';
                $lastLoginDisplay = '';

                if (! empty($row['created_at'])) {
                    try {
                        $createdAt = new DateTimeImmutable((string) $row['created_at']);
                        $createdAtDisplay = $createdAt->format('d/m/Y H:i');
                    } catch (Exception $exception) {
                        $createdAtDisplay = (string) $row['created_at'];
                    }
                }

                if (! empty($row['last_login_at'])) {
                    try {
                        $lastLogin = $utcTimezone instanceof DateTimeZone
                            ? new DateTimeImmutable((string) $row['last_login_at'], $utcTimezone)
                            : new DateTimeImmutable((string) $row['last_login_at']);

                        if ($viewerTimezone instanceof DateTimeZone) {
                            $lastLogin = $lastLogin->setTimezone($viewerTimezone);
                        }

                        $lastLoginDisplay = $lastLogin->format('d/m/Y H:i');
                    } catch (Exception $exception) {
                        $lastLoginDisplay = (string) $row['last_login_at'];
                    }
                }

                $users[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'email' => (string) $row['email'],
                    'role' => $role,
                    'role_label' => translateRoleLabel($role, $currentLanguage),
                    'created_at_display' => $createdAtDisplay,
                    'last_login_at' => (string) ($row['last_login_at'] ?? ''),
                    'last_login_at_utc' => (string) ($row['last_login_at'] ?? ''),
                    'last_login_at_display' => $lastLoginDisplay,
                ];
            }

            $result->free();
        }
    } catch (Throwable $exception) {
        $loadError = translate('users.load.error', ['error' => $exception->getMessage()], $currentLanguage);
    }
}

if ($connection instanceof mysqli) {
    try {
        $clientsQuery = 'SELECT id, name, email FROM clients WHERE user_id IS NULL ORDER BY name ASC, id ASC';
        $clientsResult = $connection->query($clientsQuery);

        if ($clientsResult instanceof mysqli_result) {
            while ($clientRow = $clientsResult->fetch_assoc()) {
                $clientName = (string) ($clientRow['name'] ?? '');
                $clientEmail = (string) ($clientRow['email'] ?? '');
                $display = trim($clientName) !== ''
                    ? sprintf('%s (%s)', $clientName, $clientEmail)
                    : $clientEmail;

                $assignableClients[] = [
                    'id' => (int) $clientRow['id'],
                    'name' => $clientName,
                    'email' => $clientEmail,
                    'display' => $display !== '' ? $display : $clientEmail,
                ];
            }

            $clientsResult->free();
        }
    } catch (Throwable $exception) {
        $clientOptionsError = translate('users.clients.load.error', ['error' => $exception->getMessage()], $currentLanguage);
    }
}

$clientPlaceholder = translate('users.form.client_placeholder', [], $currentLanguage);
$clientHelpText = translate('users.form.client_help', [], $currentLanguage);
$clientEmptyText = translate('users.form.client_empty', [], $currentLanguage);
$clientSelectDisabled = $assignableClients === [] || $clientOptionsError !== '';
$clientSelectLocked = $clientOptionsError !== '';

if ($assignableClients === []) {
    $clientHelpText = $clientEmptyText;
}

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('users.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card shadow-sm form-card">
                        <div class="card-header bg-primary text-white">
                            <h1 class="h3 mb-0"><?= htmlspecialchars(translate('users.header', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4">
                                <p class="lead mb-2 mb-lg-0"><?= $greetingMessage ?></p>
                            </div>
                            <p class="text-muted mb-4">
                                <?= htmlspecialchars(translate('users.intro', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <div id="user-alert" class="alert d-none" role="alert"></div>
                            <form id="user-form" autocomplete="off">
                                <input type="hidden" name="user_id" id="user_id" value="">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="name" class="form-label"><?= htmlspecialchars(translate('users.form.name', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="name" name="name" maxlength="100" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="email" class="form-label"><?= htmlspecialchars(translate('users.form.email', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="email" class="form-control" id="email" name="email" maxlength="150" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="password" class="form-label"><?= htmlspecialchars(translate('users.form.password', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="password" class="form-control" id="password" name="password" minlength="8" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="password_confirmation" class="form-label"><?= htmlspecialchars(translate('users.form.password_confirmation', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="8" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="role" class="form-label"><?= htmlspecialchars(translate('users.form.role', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="role" name="role" required>
                                            <option value="usuario" selected><?= htmlspecialchars(translateRoleLabel('usuario', $currentLanguage), ENT_QUOTES, 'UTF-8') ?></option>
                                            <option value="cliente"><?= htmlspecialchars(translateRoleLabel('cliente', $currentLanguage), ENT_QUOTES, 'UTF-8') ?></option>
                                            <option value="admin"><?= htmlspecialchars($adminRoleLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6 d-none" id="client-assignment-group">
                                        <label for="client_id" class="form-label"><?= htmlspecialchars(translate('users.form.client_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="client_id" name="client_id"<?= $clientSelectDisabled ? ' disabled' : '' ?><?= $clientSelectLocked ? ' data-locked="true"' : '' ?>>
                                            <option value=""><?= htmlspecialchars($clientPlaceholder, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php foreach ($assignableClients as $clientOption): ?>
                                                <option value="<?= htmlspecialchars((string) $clientOption['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($clientOption['display'], ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text text-muted" id="client-assignment-help">
                                            <?= htmlspecialchars($clientHelpText, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <?php if ($clientOptionsError !== ''): ?>
                                            <div class="alert alert-warning mt-2 mb-0" role="alert">
                                                <?= htmlspecialchars($clientOptionsError, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <div class="d-flex justify-content-end mt-4">
                                    <button type="reset" class="btn btn-outline-secondary me-2"><?= htmlspecialchars(translate('users.form.reset', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(translate('users.form.submit', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                </div>
                            </form>
                            <hr class="my-4">
                            <h2 class="h5"><?= htmlspecialchars(translate('users.table.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                            <?php if ($loadError !== ''): ?>
                                <div class="alert alert-danger mb-0" role="alert">
                                    <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php elseif ($users === []): ?>
                                <p class="text-muted mb-0"><?= htmlspecialchars(translate('users.table.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?= htmlspecialchars(translate('users.table.headers.id', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('users.table.headers.name', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('users.table.headers.email', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('users.table.headers.role', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('users.table.headers.created', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('users.table.headers.last_login', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col" class="text-end"><?= htmlspecialchars(translate('users.table.headers.actions', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="users-table-body">
                                            <?php foreach ($users as $userRow): ?>
                                                <?php
                                                    $userIdValue = (string) $userRow['id'];
                                                    $userIdEscaped = htmlspecialchars($userIdValue, ENT_QUOTES, 'UTF-8');
                                                    $userNameEscaped = htmlspecialchars($userRow['name'], ENT_QUOTES, 'UTF-8');
                                                    $userEmailEscaped = htmlspecialchars($userRow['email'], ENT_QUOTES, 'UTF-8');
                                                    $userRoleValue = (string) $userRow['role'];
                                                    $userRoleEscaped = htmlspecialchars($userRoleValue, ENT_QUOTES, 'UTF-8');
                                                    $roleLabelEscaped = htmlspecialchars($userRow['role_label'], ENT_QUOTES, 'UTF-8');
                                                    $createdAtEscaped = htmlspecialchars($userRow['created_at_display'], ENT_QUOTES, 'UTF-8');
                                                    $lastLoginEscaped = htmlspecialchars($userRow['last_login_at_display'], ENT_QUOTES, 'UTF-8');
                                                    $editLabel = htmlspecialchars(translate('users.table.actions.edit', [], $currentLanguage), ENT_QUOTES, 'UTF-8');
                                                ?>
                                                <tr data-user-id="<?= $userIdEscaped ?>">
                                                    <td><?= $userIdEscaped ?></td>
                                                    <td><?= $userNameEscaped ?></td>
                                                    <td><?= $userEmailEscaped ?></td>
                                                    <td><?= $roleLabelEscaped ?></td>
                                                    <td><?= $createdAtEscaped ?></td>
                                                    <td><?= $lastLoginEscaped ?></td>
                                                    <td class="text-end">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-primary js-edit-user"
                                                            data-user-id="<?= $userIdEscaped ?>"
                                                            data-user-name="<?= $userNameEscaped ?>"
                                                            data-user-email="<?= $userEmailEscaped ?>"
                                                            data-user-role="<?= $userRoleEscaped ?>"
                                                        ><?= $editLabel ?></button>
                                                        </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/partials/footer.php'; ?>
        <script>
            window.AppConfig = {
                language: '<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>',
                translations: <?= json_encode($usersTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
        </script>
        <?php
            $includeSweetAlert = false;
            $pageScripts = [
                'assets/js/theme.js',
                'assets/js/users.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>