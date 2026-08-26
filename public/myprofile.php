<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/api_tokens.php';

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();

if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

$user = $_SESSION['user'];
$userRole = (string) ($user['role'] ?? 'usuario');

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

$userNameRaw = (string) ($user['name'] ?? '');
$userNameFallback = translate('dashboard.nav.profile', [], $currentLanguage);
$userNameDisplay = $userNameRaw !== '' ? $userNameRaw : $userNameFallback;
$userEmail = (string) ($user['email'] ?? '');
$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$roleLabelEscaped = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
$userNameEscaped = htmlspecialchars($userNameDisplay, ENT_QUOTES, 'UTF-8');
$userEmailEscaped = htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8');

$greetingMessage = translate('profile.greeting', ['name' => '<strong data-profile-name-display>' . $userNameEscaped . '</strong>'], $currentLanguage);
$descriptionText = translate('profile.description', [], $currentLanguage);

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;

$canManageClients = in_array($userRole, ['admin', 'usuario'], true);
$canManageUsers = $userRole === 'admin';
$canViewReports = in_array($userRole, ['admin', 'usuario', 'cliente'], true);

$navLinks = [
    [
        'href' => 'index.php',
        'label' => translate('profile.nav.dashboard', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'client-portal.php',
        'label' => translate('dashboard.nav.client_portal', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $userRole === 'cliente',
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
        'label' => $navProfileLabel,
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'logout.php',
        'label' => translate('dashboard.nav.logout', [], $currentLanguage),
        'class' => 'btn btn-outline-danger btn-sm',
    ],
];

$csrfToken = csrf_token();

$profileTranslations = getClientTranslations([
    'profile.alert.success',
    'profile.alert.validation',
    'profile.alert.error',
    'common.csrf_token_invalid',
    'common.session_expired',
    'dashboard.nav.profile',
    'profile.tokens.heading',
    'profile.tokens.description',
    'profile.tokens.form.name_label',
    'profile.tokens.form.name_help',
    'profile.tokens.form.scopes_label',
    'profile.tokens.form.no_scopes',
    'profile.tokens.form.submit',
    'profile.tokens.active_tokens',
    'profile.tokens.table.token',
    'profile.tokens.table.scopes',
    'profile.tokens.table.created',
    'profile.tokens.table.last_used',
    'profile.tokens.table.actions',
    'profile.tokens.table.empty',
    'profile.tokens.copy_warning',
    'profile.tokens.created_copy',
    'profile.tokens.alert.created',
    'profile.tokens.alert.revoked',
    'profile.tokens.alert.error',
    'profile.tokens.alert.validation',
    'profile.tokens.confirm_revoke',
    'profile.tokens.last_used.never',
    'profile.tokens.action.revoke',
], $currentLanguage);

$availableApiScopes = available_api_token_scopes($currentLanguage);

$passwordHelpText = translate('profile.form.password_help', [], $currentLanguage);

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('profile.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-8 col-xl-6">
                    <div class="card shadow-sm form-card">
                        <div class="card-header bg-primary text-white">
                            <h1 class="h3 mb-0"><?= htmlspecialchars(translate('profile.header', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4">
                                <p class="lead mb-2 mb-lg-0" id="profile-greeting"><?= $greetingMessage ?></p>
                            </div>
                            <p class="text-muted mb-4">
                                <?= htmlspecialchars($descriptionText, ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <div id="profile-alert" class="alert d-none" role="alert"></div>
                            <form id="profile-form" autocomplete="off" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="mb-3">
                                    <label for="profile-name" class="form-label"><?= htmlspecialchars(translate('profile.form.name', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="text" class="form-control" id="profile-name" name="name" maxlength="100" value="<?= $userNameEscaped ?>" required autocomplete="name">
                                </div>
                                <div class="mb-3">
                                    <label for="profile-email" class="form-label"><?= htmlspecialchars(translate('profile.form.email', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="email" class="form-control" id="profile-email" name="email" maxlength="150" value="<?= $userEmailEscaped ?>" required autocomplete="email">
                                </div>
                                <div class="mb-3">
                                    <label for="profile-password" class="form-label"><?= htmlspecialchars(translate('profile.form.password', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="password" class="form-control" id="profile-password" name="password" autocomplete="new-password">
                                    <?php if ($passwordHelpText !== ''): ?>
                                        <div class="form-text"><?= htmlspecialchars($passwordHelpText, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-4">
                                    <label for="profile-password-confirmation" class="form-label"><?= htmlspecialchars(translate('profile.form.password_confirmation', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="password" class="form-control" id="profile-password-confirmation" name="password_confirmation" autocomplete="new-password">
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary">
                                        <?= htmlspecialchars(translate('profile.form.submit', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row justify-content-center mt-4">
                <div class="col-lg-8 col-xl-6">
                    <div class="card shadow-sm form-card" id="api-token-section">
                        <div class="card-header bg-secondary text-white">
                            <h2 class="h5 mb-0" data-translation="profile.tokens.heading">
                                <?= htmlspecialchars($profileTranslations['profile.tokens.heading'], ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="text-muted" data-translation="profile.tokens.description">
                                <?= htmlspecialchars($profileTranslations['profile.tokens.description'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <div id="api-token-alert" class="alert d-none" role="alert"></div>
                            <form id="api-token-form" class="mb-4" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="mb-3">
                                    <label for="api-token-name" class="form-label" data-translation="profile.tokens.form.name_label">
                                        <?= htmlspecialchars($profileTranslations['profile.tokens.form.name_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <input type="text" class="form-control" id="api-token-name" name="name" maxlength="100">
                                    <?php if ($profileTranslations['profile.tokens.form.name_help'] !== ''): ?>
                                        <div class="form-text" data-translation="profile.tokens.form.name_help">
                                            <?= htmlspecialchars($profileTranslations['profile.tokens.form.name_help'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" data-translation="profile.tokens.form.scopes_label">
                                        <?= htmlspecialchars($profileTranslations['profile.tokens.form.scopes_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <div id="api-token-scopes" class="row g-2"></div>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-secondary" data-translation="profile.tokens.form.submit">
                                        <?= htmlspecialchars($profileTranslations['profile.tokens.form.submit'], ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </div>
                            </form>
                            <div id="api-token-created" class="alert alert-info d-none" role="alert">
                                <p class="mb-1" data-translation="profile.tokens.created_copy">
                                    <?= htmlspecialchars($profileTranslations['profile.tokens.created_copy'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <pre class="mb-2"><code id="api-token-created-value"></code></pre>
                                <p class="mb-0 small text-muted" data-translation="profile.tokens.copy_warning">
                                    <?= htmlspecialchars($profileTranslations['profile.tokens.copy_warning'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <h3 class="h5 mt-4" data-translation="profile.tokens.active_tokens">
                                <?= htmlspecialchars($profileTranslations['profile.tokens.active_tokens'], ENT_QUOTES, 'UTF-8') ?>
                            </h3>
                            <div class="table-responsive">
                                <table class="table table-striped align-middle" id="api-token-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" data-translation="profile.tokens.table.token">
                                                <?= htmlspecialchars($profileTranslations['profile.tokens.table.token'], ENT_QUOTES, 'UTF-8') ?>
                                            </th>
                                            <th scope="col" data-translation="profile.tokens.table.scopes">
                                                <?= htmlspecialchars($profileTranslations['profile.tokens.table.scopes'], ENT_QUOTES, 'UTF-8') ?>
                                            </th>
                                            <th scope="col" data-translation="profile.tokens.table.created">
                                                <?= htmlspecialchars($profileTranslations['profile.tokens.table.created'], ENT_QUOTES, 'UTF-8') ?>
                                            </th>
                                            <th scope="col" data-translation="profile.tokens.table.last_used">
                                                <?= htmlspecialchars($profileTranslations['profile.tokens.table.last_used'], ENT_QUOTES, 'UTF-8') ?>
                                            </th>
                                            <th scope="col" class="text-end" data-translation="profile.tokens.table.actions">
                                                <?= htmlspecialchars($profileTranslations['profile.tokens.table.actions'], ENT_QUOTES, 'UTF-8') ?>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr id="api-token-empty" class="d-none">
                                            <td colspan="5" class="text-muted" data-translation="profile.tokens.table.empty">
                                                <?= htmlspecialchars($profileTranslations['profile.tokens.table.empty'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/partials/footer.php'; ?>
        <script>
            window.AppConfig = {
                language: '<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>',
                translations: <?= json_encode($profileTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                apiTokenScopes: <?= json_encode($availableApiScopes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
        </script>
        <?php
            $includeSweetAlert = false;
            $pageScripts = [
                'assets/js/theme.js',
                'assets/js/profile.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
