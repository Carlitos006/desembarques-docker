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

if ($userRole !== 'admin') {
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

$userName = (string) ($user['name'] ?? 'Administrador');
$userNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$roleLabelEscaped = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');

$statusesTranslations = getClientTranslations([
    'statuses.alert.success',
    'statuses.alert.update_success',
    'statuses.alert.validation',
    'statuses.alert.error',
    'statuses.alert.toggle_error',
    'statuses.alert.toggle_default_guard',
    'statuses.alert.toggle_default_requires_active',
    'statuses.form.submit',
    'statuses.form.update',
    'statuses.table.actions.edit',
    'statuses.table.toggle_active',
    'statuses.table.toggle_default',
    'common.session_expired',
    'common.csrf_token_invalid',
], $currentLanguage);

$csrfToken = csrf_token();

$greetingMessage = translate('statuses.greeting', ['name' => '<strong>' . $userNameEscaped . '</strong>'], $currentLanguage);
$currentRoleMessage = translate('statuses.current_role', ['role' => '<strong>' . $roleLabelEscaped . '</strong>'], $currentLanguage);

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;
$navLinks = [
    [
        'href' => 'index.php',
        'label' => translate('statuses.nav.back', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
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
        'label' => translate('statuses.nav.manage_clients', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'users.php',
        'label' => translate('statuses.nav.manage_users', [], $currentLanguage),
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
        'label' => translate('statuses.nav.logout', [], $currentLanguage),
        'class' => 'btn btn-outline-danger btn-sm',
    ],
];

$statuses = [];
$loadError = '';

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    $loadError = $exception->getMessage();
    $connection = null;
}

if ($loadError === '' && isset($connection) && $connection instanceof mysqli) {
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
                    'created_at_display' => $createdAtDisplay,
                ];
            }

            $result->free();
        }
    } catch (Throwable $exception) {
        $loadError = translate('statuses.load.error', ['error' => $exception->getMessage()], $currentLanguage);
    }
}

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('statuses.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card shadow-sm form-card">
                        <div class="card-header bg-primary text-white">
                            <h1 class="h3 mb-0"><?= htmlspecialchars(translate('statuses.header', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4">
                                <p class="lead mb-2 mb-lg-0"><?= $greetingMessage ?></p>
                                <span class="text-muted small"><?= $currentRoleMessage ?></span>
                            </div>
                            <p class="text-muted mb-4">
                                <?= htmlspecialchars(translate('statuses.intro', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <div id="statuses-alert" class="alert d-none" role="alert"></div>
                            <form id="status-form" autocomplete="off" class="mb-4">
                                <input type="hidden" id="status-id" name="id" value="">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="status-slug" class="form-label"><?= htmlspecialchars(translate('statuses.form.slug', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="status-slug"
                                            name="slug"
                                            maxlength="50"
                                            required
                                            aria-describedby="status-slug-help"
                                        >
                                        <div id="status-slug-help" class="form-text">
                                            <?= htmlspecialchars(translate('statuses.form.slug_help', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div class="invalid-feedback" data-feedback-for="slug"></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="status-name-es" class="form-label"><?= htmlspecialchars(translate('statuses.form.name_es', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="status-name-es"
                                            name="name_es"
                                            maxlength="100"
                                            required
                                        >
                                        <div class="invalid-feedback" data-feedback-for="name_es"></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="status-name-en" class="form-label"><?= htmlspecialchars(translate('statuses.form.name_en', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="status-name-en"
                                            name="name_en"
                                            maxlength="100"
                                            required
                                        >
                                        <div class="invalid-feedback" data-feedback-for="name_en"></div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end mt-4">
                                    <button type="reset" class="btn btn-outline-secondary me-2"><?= htmlspecialchars(translate('statuses.form.reset', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(translate('statuses.form.submit', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                </div>
                            </form>
                            <h2 class="h5 mb-3"><?= htmlspecialchars(translate('statuses.table.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                            <?php if ($loadError !== ''): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?= htmlspecialchars(translate('statuses.table.headers.id', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('statuses.table.headers.slug', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('statuses.table.headers.name_es', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('statuses.table.headers.name_en', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col" class="text-center"><?= htmlspecialchars(translate('statuses.table.headers.active', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col" class="text-center"><?= htmlspecialchars(translate('statuses.table.headers.default', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('statuses.table.headers.created', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col" class="text-end"><?= htmlspecialchars(translate('statuses.table.headers.actions', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="statuses-table-body">
                                            <?php if ($statuses === []): ?>
                                                <tr class="statuses-empty-row">
                                                    <td colspan="8" class="text-center text-muted py-4">
                                                        <?= htmlspecialchars(translate('statuses.table.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($statuses as $status): ?>
                                                    <tr
                                                        data-status-id="<?= (int) $status['id'] ?>"
                                                        data-status-slug="<?= htmlspecialchars($status['slug'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-status-name-es="<?= htmlspecialchars($status['name_es'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-status-name-en="<?= htmlspecialchars($status['name_en'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-status-active="<?= ! empty($status['is_active']) ? '1' : '0' ?>"
                                                        data-status-default="<?= ! empty($status['is_default']) ? '1' : '0' ?>"
                                                    >
                                                        <td><?= (int) $status['id'] ?></td>
                                                        <td><?= htmlspecialchars($status['slug'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($status['name_es'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($status['name_en'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td class="text-center">
                                                            <div class="form-check form-switch justify-content-center d-flex">
                                                                <input
                                                                    class="form-check-input status-active-toggle"
                                                                    type="checkbox"
                                                                    role="switch"
                                                                    data-status-id="<?= (int) $status['id'] ?>"
                                                                    aria-label="<?= htmlspecialchars(translate('statuses.table.toggle_active', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"
                                                                    title="<?= htmlspecialchars(translate('statuses.table.toggle_active', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"
                                                                    <?= ! empty($status['is_active']) ? 'checked' : '' ?>
                                                                >
                                                            </div>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="form-check form-switch justify-content-center d-flex">
                                                                <input
                                                                    class="form-check-input status-default-toggle"
                                                                    type="checkbox"
                                                                    role="switch"
                                                                    data-status-id="<?= (int) $status['id'] ?>"
                                                                    aria-label="<?= htmlspecialchars(translate('statuses.table.toggle_default', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"
                                                                    title="<?= htmlspecialchars(translate('statuses.table.toggle_default', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"
                                                                    <?= ! empty($status['is_default']) ? 'checked' : '' ?>
                                                                    <?= empty($status['is_active']) ? 'disabled' : '' ?>
                                                                >
                                                            </div>
                                                        </td>
                                                        <td><?= htmlspecialchars((string) $status['created_at_display'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td class="text-end">
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-primary btn-sm js-edit-status"
                                                                data-status-id="<?= (int) $status['id'] ?>"
                                                                data-status-slug="<?= htmlspecialchars($status['slug'], ENT_QUOTES, 'UTF-8') ?>"
                                                                data-status-name-es="<?= htmlspecialchars($status['name_es'], ENT_QUOTES, 'UTF-8') ?>"
                                                                data-status-name-en="<?= htmlspecialchars($status['name_en'], ENT_QUOTES, 'UTF-8') ?>"
                                                            >
                                                                <?= htmlspecialchars(translate('statuses.table.actions.edit', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                            </button>
                                                        </td>
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
                language: <?= json_encode($currentLanguage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                translations: <?= json_encode($statusesTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                routes: {
                    store: '../api/statuses/store.php',
                    update: '../api/statuses/update.php',
                    toggle: '../api/statuses/toggle.php'
                }
            };
        </script>
        <?php
            $includeSweetAlert = false;
            $pageScripts = [
                'assets/js/theme.js',
                'assets/js/statuses.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
