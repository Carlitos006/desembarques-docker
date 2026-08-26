<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';

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

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    header('Location: index.php');
    exit;
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

$userName = (string) ($user['name'] ?? '');
$userNameEscaped = htmlspecialchars($userName !== '' ? $userName : translate('dashboard.nav.profile', [], $currentLanguage), ENT_QUOTES, 'UTF-8');
$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$roleLabelEscaped = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');

$clientsTranslations = getClientTranslations([
    'clients.alert.success',
    'clients.alert.validation',
    'clients.alert.error',
    'clients.update.success',
    'clients.form.update',
    'clients.table.actions.edit',
    'common.csrf_token_invalid',
], $currentLanguage);

$csrfToken = csrf_token();

$greetingMessage = translate('clients.greeting', ['name' => '<strong>' . $userNameEscaped . '</strong>'], $currentLanguage);
$currentRoleMessage = translate('clients.current_role', ['role' => '<strong>' . $roleLabelEscaped . '</strong>'], $currentLanguage);

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;
$canViewReports = in_array($userRole, ['admin', 'usuario', 'cliente'], true);
$canManageUsers = $userRole === 'admin';
$canEditClients = in_array($userRole, ['admin', 'usuario'], true);
$tableColumnCount = $canEditClients ? 5 : 4;
$editButtonLabel = translate('clients.table.actions.edit', [], $currentLanguage);

$navLinks = [
    [
        'href' => 'index.php',
        'label' => translate('clients.nav.back', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'reportes.php',
        'label' => translate('dashboard.nav.reports', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canViewReports,
    ],
    [
        'href' => 'analytics.php',
        'label' => translate('dashboard.nav.analytics', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => in_array($userRole, ['admin', 'usuario'], true),
    ],
    [
        'href' => 'control_tower.php',
        'label' => translate('dashboard.nav.control_tower', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => in_array($userRole, ['admin', 'usuario'], true),
    ],
    [
        'href' => 'statuses.php',
        'label' => translate('clients.nav.manage_statuses', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canManageUsers,
    ],
    [
        'href' => 'users.php',
        'label' => translate('clients.nav.manage_users', [], $currentLanguage),
        'class' => 'btn btn-outline-primary btn-sm',
        'visible' => $canManageUsers,
    ],
    [
        'href' => 'audit_logs.php',
        'label' => translate('dashboard.nav.audit_logs', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $userRole === 'admin',
    ],
    [
        'href' => 'myprofile.php',
        'label' => translate('dashboard.nav.profile', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'logout.php',
        'label' => translate('clients.nav.logout', [], $currentLanguage),
        'class' => 'btn btn-outline-danger btn-sm',
    ],
];

$clients = [];
$loadError = '';

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    $loadError = $exception->getMessage();
    $connection = null;
}

if ($loadError === '' && $connection instanceof mysqli) {
    try {
        $query = 'SELECT id, name, email, created_at FROM clients ORDER BY created_at DESC, id DESC';
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

                $clients[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'email' => (string) $row['email'],
                    'created_at_display' => $createdAtDisplay,
                ];
            }

            $result->free();
        }
    } catch (Throwable $exception) {
        $loadError = translate('clients.load.error', ['error' => $exception->getMessage()], $currentLanguage);
    }
}

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('clients.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card shadow-sm form-card">
                        <div class="card-header bg-primary text-white">
                            <h1 class="h3 mb-0"><?= htmlspecialchars(translate('clients.header', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4">
                                <p class="lead mb-2 mb-lg-0"><?= $greetingMessage ?></p>
                            </div>
                            <p class="text-muted mb-4">
                                <?= htmlspecialchars(translate('clients.intro', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <div id="clients-alert" class="alert d-none" role="alert"></div>
                            <form id="client-form" autocomplete="off">
                                <input type="hidden" id="client-id" name="id" value="">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="client-name" class="form-label"><?= htmlspecialchars(translate('clients.form.name', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="client-name" name="name" maxlength="150" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="client-email" class="form-label"><?= htmlspecialchars(translate('clients.form.email', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="email" class="form-control" id="client-email" name="email" maxlength="150" required>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end mt-4">
                                    <button type="reset" class="btn btn-outline-secondary me-2"><?= htmlspecialchars(translate('clients.form.reset', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(translate('clients.form.submit', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                </div>
                            </form>
                            <hr class="my-4">
                            <h2 class="h5"><?= htmlspecialchars(translate('clients.table.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                            <?php if ($loadError !== ''): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?= htmlspecialchars(translate('clients.table.headers.id', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('clients.table.headers.name', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('clients.table.headers.email', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('clients.table.headers.created', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <?php if ($canEditClients): ?>
                                                    <th scope="col"><?= htmlspecialchars(translate('clients.table.headers.actions', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody id="clients-table-body">
                                            <?php if ($clients === []): ?>
                                                <tr class="clients-empty-row">
                                                    <td colspan="<?= htmlspecialchars((string) $tableColumnCount, ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-center">
                                                        <?= htmlspecialchars(translate('clients.table.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($clients as $clientRow): ?>
                                                    <tr
                                                        data-client-id="<?= htmlspecialchars((string) $clientRow['id'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-client-name="<?= htmlspecialchars($clientRow['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-client-email="<?= htmlspecialchars($clientRow['email'], ENT_QUOTES, 'UTF-8') ?>"
                                                    >
                                                        <td><?= htmlspecialchars((string) $clientRow['id'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($clientRow['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($clientRow['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($clientRow['created_at_display'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <?php if ($canEditClients): ?>
                                                            <td>
                                                                <button
                                                                    type="button"
                                                                    class="btn btn-outline-primary btn-sm client-edit-button"
                                                                    data-client-id="<?= htmlspecialchars((string) $clientRow['id'], ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-client-name="<?= htmlspecialchars($clientRow['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-client-email="<?= htmlspecialchars($clientRow['email'], ENT_QUOTES, 'UTF-8') ?>"
                                                                >
                                                                    <?= htmlspecialchars($editButtonLabel, ENT_QUOTES, 'UTF-8') ?>
                                                                </button>
                                                            </td>
                                                        <?php endif; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
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
                translations: <?= json_encode($clientsTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                canEditClients: <?= json_encode($canEditClients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
        </script>
        <?php
            $includeSweetAlert = false;
            $pageScripts = [
                'assets/js/theme.js',
                'assets/js/clients.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
