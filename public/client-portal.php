<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();

if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

$user = $_SESSION['user'];
$userName = (string) ($user['name'] ?? '');
$userRole = (string) ($user['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario', 'cliente'], true)) {
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

$userNameDisplay = $userName !== ''
    ? $userName
    : translate('dashboard.nav.profile', [], $currentLanguage);
$userNameEscaped = htmlspecialchars($userNameDisplay, ENT_QUOTES, 'UTF-8');
$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$roleLabelEscaped = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');

$greetingMessage = translate('client_portal.greeting', ['name' => '<strong>' . $userNameEscaped . '</strong>'], $currentLanguage);
$currentRoleMessage = translate('dashboard.current_role', ['role' => '<strong>' . $roleLabelEscaped . '</strong>'], $currentLanguage);

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;
$canViewReports = in_array($userRole, ['admin', 'usuario', 'cliente'], true);
$canManageUsers = $userRole === 'admin';
$canManageClients = in_array($userRole, ['admin', 'usuario'], true);

$navLinks = [
    [
        'href' => 'client-portal.php',
        'label' => translate('dashboard.nav.client_portal', [], $currentLanguage),
        'class' => 'btn btn-outline-primary btn-sm',
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
        'href' => 'clients.php',
        'label' => translate('dashboard.nav.manage_clients', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canManageClients,
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

$clientPortalTranslations = getClientTranslations([
    'client_portal.greeting',
    'client_portal.hero.heading',
    'client_portal.hero.subheading',
    'client_portal.summary.title',
    'client_portal.summary.subtitle',
    'client_portal.summary.total_shipments',
    'client_portal.summary.pending',
    'client_portal.summary.in_progress',
    'client_portal.summary.completed',
    'client_portal.summary.on_hold',
    'client_portal.summary.updated_at',
    'client_portal.milestones.title',
    'client_portal.milestones.subtitle',
    'client_portal.milestones.empty',
    'client_portal.milestones.reference',
    'client_portal.milestones.status',
    'client_portal.milestones.landing_date',
    'client_portal.milestones.departure_date',
    'client_portal.milestones.destination',
    'client_portal.milestones.notice_number',
    'client_portal.milestones.days_elapsed',
    'client_portal.milestones.days_out',
    'client_portal.milestones.view_details',
    'client_portal.documents.title',
    'client_portal.documents.subtitle',
    'client_portal.documents.empty',
    'client_portal.documents.download',
    'client_portal.documents.size',
    'client_portal.documents.shipment_label',
    'client_portal.error.load_failed',
    'client_portal.loading',
    'client_portal.customize.button',
    'client_portal.customize.title',
    'client_portal.customize.description',
    'client_portal.customize.summary',
    'client_portal.customize.milestones',
    'client_portal.customize.documents',
    'client_portal.customize.messages',
    'client_portal.customize.save',
    'client_portal.customize.cancel',
    'client_portal.messages.title',
    'client_portal.messages.subtitle',
    'client_portal.messages.empty',
    'client_portal.messages.placeholder',
    'client_portal.messages.send',
    'client_portal.messages.error.load',
    'client_portal.messages.error.send',
    'client_portal.messages.success',
    'client_portal.preferences.error.save',
    'client_portal.milestones.confirm_cta',
    'client_portal.milestones.confirmed_badge',
    'client_portal.milestones.confirm_success',
    'client_portal.milestones.confirm_error',
    'client_portal.milestones.not_found',
    'validation.required',
], $currentLanguage);

$clientPortalClientUserId = null;

if ($userRole === 'admin') {
    $clientPortalClientUserParam = trim((string) ($_GET['client_user_id'] ?? ''));

    if ($clientPortalClientUserParam !== '' && ctype_digit($clientPortalClientUserParam)) {
        $clientPortalClientUserId = (int) $clientPortalClientUserParam;

        if ($clientPortalClientUserId <= 0) {
            $clientPortalClientUserId = null;
        }
    }
}

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('client_portal.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5 client-portal-container">
            <div class="client-portal-hero card border-0 shadow-sm text-white mb-5">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-4">
                        <div class="flex-grow-1">
                            <h1 class="display-6 fw-semibold mb-3">
                                <?= htmlspecialchars(translate('client_portal.hero.heading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </h1>
                            <p class="lead mb-0">
                                <?= htmlspecialchars(translate('client_portal.hero.subheading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                        <div class="client-portal-hero-meta text-lg-end">
                            <p class="mb-1 fw-semibold">
                                <?= $greetingMessage ?>
                            </p>
                            <p class="mb-0 text-opacity-75">
                                <?= $currentRoleMessage ?>
                            </p>
                            <div class="mt-3">
                                <button id="client-portal-open-customize" type="button" class="btn btn-outline-light btn-sm">
                                    <span class="me-2" aria-hidden="true">⚙️</span>
                                    <?= htmlspecialchars(translate('client_portal.customize.button', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="client-portal-error" class="alert alert-danger d-none" role="alert"></div>
            <div id="client-portal-loading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                <p class="mt-3 text-muted mb-0" data-loading-text>
                    <?= htmlspecialchars(translate('client_portal.loading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
            <div id="client-portal-content" class="d-none">
                <section class="client-portal-section mb-5" data-client-portal-widget="summary">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="h4 mb-1">
                                <?= htmlspecialchars(translate('client_portal.summary.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                            <p class="text-muted mb-0">
                                <?= htmlspecialchars(translate('client_portal.summary.subtitle', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                        <div class="text-muted small" data-portal-updated-at-container>
                            <span class="me-2" aria-hidden="true">⏱️</span>
                            <span data-portal-updated-at></span>
                        </div>
                    </div>
                    <div id="client-portal-summary" class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4 row-cols-xxl-5 g-3"></div>
                </section>
                <section class="client-portal-section mb-5" data-client-portal-widget="milestones">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="h4 mb-1">
                                <?= htmlspecialchars(translate('client_portal.milestones.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                            <p class="text-muted mb-0">
                                <?= htmlspecialchars(translate('client_portal.milestones.subtitle', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </div>
                    <div id="client-portal-milestones" class="client-portal-milestones list-unstyled mb-0"></div>
                </section>
                <section class="client-portal-section mb-5" data-client-portal-widget="documents">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="h4 mb-1">
                                <?= htmlspecialchars(translate('client_portal.documents.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                            <p class="text-muted mb-0">
                                <?= htmlspecialchars(translate('client_portal.documents.subtitle', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </div>
                    <div id="client-portal-documents" class="client-portal-documents"></div>
                </section>
                <section class="client-portal-section mb-5" data-client-portal-widget="messages">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="h4 mb-1">
                                <?= htmlspecialchars(translate('client_portal.messages.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                            <p class="text-muted mb-0">
                                <?= htmlspecialchars(translate('client_portal.messages.subtitle', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </div>
                    <div id="client-portal-messages-feedback" class="small text-danger d-none" role="status"></div>
                    <div id="client-portal-messages-wrapper" class="bg-body-secondary rounded-3 p-3 mb-3">
                        <ul id="client-portal-messages" class="list-unstyled d-flex flex-column gap-3 mb-0"></ul>
                        <p id="client-portal-messages-empty" class="text-muted mb-0">
                            <?= htmlspecialchars(translate('client_portal.messages.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                    <form id="client-portal-message-form" class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label visually-hidden" for="client-portal-message-input">
                                    <?= htmlspecialchars(translate('client_portal.messages.send', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </label>
                                <textarea
                                    class="form-control"
                                    id="client-portal-message-input"
                                    name="message"
                                    rows="3"
                                    placeholder="<?= htmlspecialchars(translate('client_portal.messages.placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"
                                ></textarea>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">
                                    <span class="me-2" aria-hidden="true">💬</span>
                                    <?= htmlspecialchars(translate('client_portal.messages.send', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </section>
            </div>
        </div>
        <div class="modal fade" id="clientPortalCustomizeModal" tabindex="-1" aria-labelledby="clientPortalCustomizeModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form id="client-portal-customize-form">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="clientPortalCustomizeModalLabel">
                                <?= htmlspecialchars(translate('client_portal.customize.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(translate('client_portal.customize.cancel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small">
                                <?= htmlspecialchars(translate('client_portal.customize.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="widgets[]" value="summary" id="client-portal-widget-summary">
                                <label class="form-check-label" for="client-portal-widget-summary">
                                    <?= htmlspecialchars(translate('client_portal.customize.summary', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="widgets[]" value="milestones" id="client-portal-widget-milestones">
                                <label class="form-check-label" for="client-portal-widget-milestones">
                                    <?= htmlspecialchars(translate('client_portal.customize.milestones', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="widgets[]" value="documents" id="client-portal-widget-documents">
                                <label class="form-check-label" for="client-portal-widget-documents">
                                    <?= htmlspecialchars(translate('client_portal.customize.documents', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="widgets[]" value="messages" id="client-portal-widget-messages">
                                <label class="form-check-label" for="client-portal-widget-messages">
                                    <?= htmlspecialchars(translate('client_portal.customize.messages', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </label>
                            </div>
                            <p id="client-portal-customize-feedback" class="small mt-3 mb-0 text-danger d-none" role="alert"></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">
                                <?= htmlspecialchars(translate('client_portal.customize.cancel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <?= htmlspecialchars(translate('client_portal.customize.save', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </div>
                    </form>
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
            window.ClientPortalConfig = {
                language: <?= json_encode($currentLanguage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                translations: <?= json_encode($clientPortalTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                clientUserId: <?= json_encode($clientPortalClientUserId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
        </script>
        <?php
            $pageScripts = [
                'assets/js/theme.js',
                'assets/js/client-portal.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
