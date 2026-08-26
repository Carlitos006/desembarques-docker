<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/analytics.php';

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();

if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

$user = $_SESSION['user'];
$userId = (int) ($user['id'] ?? 0);
$userName = (string) ($user['name'] ?? '');
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

$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$roleLabelEscaped = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
$userNameEscaped = htmlspecialchars($userName !== '' ? $userName : translate('dashboard.nav.profile', [], $currentLanguage), ENT_QUOTES, 'UTF-8');

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;

$canManageUsers = $userRole === 'admin';
$canRegister = in_array($userRole, ['admin', 'usuario'], true);

$navLinks = [
    [
        'href' => 'index.php',
        'label' => translate('dashboard.nav.dashboard', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canRegister,
    ],
    [
        'href' => 'reportes.php',
        'label' => translate('dashboard.nav.reports', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => true,
    ],
    [
        'href' => 'analytics.php',
        'label' => translate('dashboard.nav.analytics', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canRegister,
    ],
    [
        'href' => 'control_tower.php',
        'label' => translate('dashboard.nav.control_tower', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canRegister,
    ],
    [
        'href' => 'clients.php',
        'label' => translate('dashboard.nav.manage_clients', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canRegister,
    ],
    [
        'href' => 'statuses.php',
        'label' => translate('dashboard.nav.manage_statuses', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canManageUsers,
    ],
    [
        'href' => 'users.php',
        'label' => translate('dashboard.nav.manage_users', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canManageUsers,
    ],
    [
        'href' => 'audit_logs.php',
        'label' => translate('dashboard.nav.audit_logs', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canManageUsers,
    ],
    [
        'href' => 'myprofile.php',
        'label' => translate('dashboard.nav.profile', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'logout.php',
        'label' => translate('dashboard.nav.logout', [], $currentLanguage),
        'class' => 'btn btn-outline-danger btn-sm',
    ],
];

$csrfToken = csrf_token();

$analyticsConfig = getAnalyticsConfig();
$slaTargetHours = isset($analyticsConfig['sla']['target_hours']) ? (int) $analyticsConfig['sla']['target_hours'] : 72;
$slaWarningHours = isset($analyticsConfig['sla']['warning_hours']) ? (int) $analyticsConfig['sla']['warning_hours'] : max($slaTargetHours, 96);

$controlTowerTranslations = getClientTranslations([
    'control_tower.alert.load_error',
    'control_tower.update.success',
    'control_tower.update.error',
    'control_tower.update.no_change',
    'control_tower.update.unauthorized',
    'control_tower.column.empty',
    'control_tower.sla.ok',
    'control_tower.sla.warning',
    'control_tower.sla.breach',
    'control_tower.card.client',
    'control_tower.card.vessel',
    'control_tower.card.destination',
    'control_tower.card.notice',
    'control_tower.card.days_elapsed',
    'control_tower.card.days_out',
    'control_tower.card.landing',
    'control_tower.card.departure',
    'common.session_expired',
    'common.error_title',
], $currentLanguage);

$statuses = [];

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();

    $query = 'SELECT id, slug, name_es, name_en FROM desembarque_statuses WHERE is_active = 1 ORDER BY is_default DESC, name_es ASC, id ASC';
    $result = $connection->query($query);

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $statusId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($statusId <= 0) {
                continue;
            }

            $statusNameEs = (string) ($row['name_es'] ?? '');
            $statusNameEn = (string) ($row['name_en'] ?? '');
            $statusSlug = (string) ($row['slug'] ?? '');
            $statusLabel = $currentLanguage === 'en' && $statusNameEn !== '' ? $statusNameEn : $statusNameEs;

            if ($statusLabel === '' && $statusNameEn !== '') {
                $statusLabel = $statusNameEn;
            }

            if ($statusLabel === '' && $statusSlug !== '') {
                $statusLabel = $statusSlug;
            }

            $statuses[] = [
                'id' => $statusId,
                'slug' => $statusSlug,
                'label' => $statusLabel,
            ];
        }

        $result->free();
    }
} catch (Throwable $exception) {
    $statuses = [];
}

$pageTitle = translate('control_tower.page_title', [], $currentLanguage);
$pageHeading = translate('control_tower.title', [], $currentLanguage);
$pageSubtitle = translate('control_tower.subtitle', [], $currentLanguage);
$refreshLabel = translate('control_tower.refresh', [], $currentLanguage);
$lastRefreshedLabel = translate('control_tower.last_refreshed', [], $currentLanguage);
$emptyColumnLabel = translate('control_tower.column.empty', [], $currentLanguage);

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5 control-tower-page">
            <div class="row justify-content-center">
                <div class="col-12">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 gap-3">
                        <div>
                            <h1 class="h2 mb-2"><?= htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8') ?></h1>
                            <p class="text-muted mb-0"><?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="d-flex flex-column align-items-lg-end gap-2">
                            <button type="button" class="btn btn-primary align-self-lg-end" data-control-tower-refresh>
                                <?= htmlspecialchars($refreshLabel, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <div class="text-muted small">
                                <span class="fw-semibold"><?= htmlspecialchars($lastRefreshedLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                <span data-control-tower-last-refreshed><?= htmlspecialchars(translate('reports.table.loading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                    </div>
                    <div id="controlTowerAlert" class="alert d-none" role="status"></div>
                    <div
                        class="row g-4 control-tower-board"
                        data-control-tower-board
                        data-empty-label="<?= htmlspecialchars($emptyColumnLabel, ENT_QUOTES, 'UTF-8') ?>"
                        data-sla-target-hours="<?= htmlspecialchars((string) $slaTargetHours, ENT_QUOTES, 'UTF-8') ?>"
                        data-sla-warning-hours="<?= htmlspecialchars((string) $slaWarningHours, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <?php if ($statuses === []): ?>
                            <div class="col-12">
                                <div class="alert alert-warning mb-0" role="alert">
                                    <?= htmlspecialchars(translate('reports.filters.status_error', ['error' => translate('common.error_title', [], $currentLanguage)], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($statuses as $status): ?>
                                <div class="col-12 col-md-6 col-xl">
                                    <div class="card shadow-sm h-100 control-tower-column" data-status-column="<?= (int) $status['id'] ?>">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <span class="fw-semibold"><?= htmlspecialchars((string) $status['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="badge rounded-pill bg-secondary" data-status-count>0</span>
                                        </div>
                                        <div class="card-body">
                                            <div
                                                class="control-tower-dropzone"
                                                data-status-id="<?= (int) $status['id'] ?>"
                                                data-status-label="<?= htmlspecialchars((string) $status['label'], ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                <p class="text-muted small mb-0 control-tower-empty" data-empty-state><?= htmlspecialchars($emptyColumnLabel, ENT_QUOTES, 'UTF-8') ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/partials/footer.php'; ?>
        <script>
            window.AppConfig = {
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
            window.ControlTowerConfig = {
                language: <?= json_encode($currentLanguage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                userId: <?= json_encode($userId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                role: <?= json_encode($userRole, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                statuses: <?= json_encode($statuses, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                translations: <?= json_encode($controlTowerTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                endpoints: {
                    board: '../api/desembarques/board.php',
                    updateStatus: '../api/desembarques/update_status.php'
                }
            };
        </script>
        <?php
            $pageScripts = [
                'assets/js/theme.js',
                'assets/js/control-tower.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
