<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/analytics.php';

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

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();

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

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);

$navLinks = [
    [
        'href' => 'index.php',
        'label' => translate('dashboard.nav.dashboard', [], $currentLanguage),
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
        'class' => 'btn btn-outline-primary btn-sm',
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
        'visible' => $userRole === 'admin',
    ],
    [
        'href' => 'statuses.php',
        'label' => translate('dashboard.nav.manage_statuses', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $userRole === 'admin',
    ],
    [
        'href' => 'users.php',
        'label' => translate('dashboard.nav.manage_users', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $userRole === 'admin',
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
        'label' => translate('dashboard.nav.logout', [], $currentLanguage),
        'class' => 'btn btn-outline-danger btn-sm',
    ],
];

try {
    $analyticsConfig = getAnalyticsConfig();
} catch (Throwable $exception) {
    $analyticsConfig = [
        'range_options' => [30, 60, 90],
        'default_range_days' => 60,
        'widgets' => [],
    ];
}

$rangeOptions = $analyticsConfig['range_options'] ?? [30, 60, 90];
$defaultRangeDays = (int) ($analyticsConfig['default_range_days'] ?? 60);
$widgetDefinitions = [];

foreach ($analyticsConfig['widgets'] ?? [] as $widget) {
    $widgetId = (string) ($widget['id'] ?? '');

    if ($widgetId === '') {
        continue;
    }

    $widgetDefinitions[] = [
        'id' => $widgetId,
        'label' => translate('analytics.widgets.' . $widgetId . '.title', [], $currentLanguage),
        'description' => translate('analytics.widgets.' . $widgetId . '.description', [], $currentLanguage),
        'default_enabled' => (bool) ($widget['default_enabled'] ?? false),
    ];
}

$analyticsTranslations = getClientTranslations([
    'analytics.loading',
    'analytics.alert.error',
    'analytics.alert.empty',
    'analytics.summary.range',
    'analytics.summary.compliance',
    'analytics.summary.target',
    'analytics.summary.avg_cycle_time',
    'analytics.summary.avg_cycle_help',
    'analytics.summary.avg_days_out',
    'analytics.summary.avg_days_out_help',
    'analytics.summary.expected_week',
    'analytics.summary.trend.up',
    'analytics.summary.trend.down',
    'analytics.summary.trend.stable',
    'analytics.widgets_selector.title',
    'analytics.widgets_selector.help',
    'analytics.widgets_selector.empty',
    'analytics.widgets.sla-overview.title',
    'analytics.widgets.sla-overview.description',
    'analytics.widgets.sla-overview.chart_compliance',
    'analytics.widgets.sla-overview.chart_trend',
    'analytics.widgets.sla-overview.breaches_title',
    'analytics.widgets.sla-overview.no_breaches',
    'analytics.widgets.sla-overview.table.reference',
    'analytics.widgets.sla-overview.table.days_transcurridos',
    'analytics.widgets.sla-overview.table.days_fuera',
    'analytics.widgets.sla-overview.table.status',
    'analytics.widgets.sla-overview.by_status',
    'analytics.widgets.dock-capacity.title',
    'analytics.widgets.dock-capacity.description',
    'analytics.widgets.dock-capacity.chart_title',
    'analytics.widgets.dock-capacity.table.dock',
    'analytics.widgets.dock-capacity.table.capacity',
    'analytics.widgets.dock-capacity.table.active',
    'analytics.widgets.dock-capacity.table.utilization',
    'analytics.widgets.dock-capacity.no_data',
    'analytics.widgets.weather-forecast.title',
    'analytics.widgets.weather-forecast.description',
    'analytics.widgets.weather-forecast.updated',
    'analytics.widgets.weather-forecast.no_data',
    'analytics.widgets.weather-forecast.table.date',
    'analytics.widgets.weather-forecast.table.condition',
    'analytics.widgets.weather-forecast.table.temperature',
    'analytics.widgets.weather-forecast.table.wind',
    'analytics.widgets.weather-forecast.table.rain',
    'analytics.weather.condition.sunny',
    'analytics.weather.condition.cloudy',
    'analytics.weather.condition.rain',
    'analytics.weather.condition.storm',
    'analytics.weather.condition.windy',
], $currentLanguage);

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>" data-theme="<?= htmlspecialchars($currentTheme, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars(translate('analytics.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="theme-<?= htmlspecialchars($currentTheme, ENT_QUOTES, 'UTF-8') ?>">
        <?php
            require __DIR__ . '/partials/nav.php';
        ?>
        <main class="container py-4 analytics-page">
            <header class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <h1 class="h3 mb-1"><?= htmlspecialchars(translate('analytics.header', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="text-muted mb-0"><?= htmlspecialchars(translate('analytics.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <form class="d-flex flex-column flex-sm-row align-items-sm-end gap-2" id="analytics-filters">
                    <div>
                        <label for="analytics-range" class="form-label mb-1"><?= htmlspecialchars(translate('analytics.filters.range_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                        <select class="form-select" id="analytics-range" name="range">
                            <?php foreach ($rangeOptions as $option): ?>
                                <?php $optionDays = (int) $option; ?>
                                <option value="<?= $optionDays ?>" <?= $optionDays === $defaultRangeDays ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(translate('analytics.filters.range_option', ['days' => (string) $optionDays], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= htmlspecialchars(translate('analytics.filters.range_help', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </form>
            </header>
            <section class="card mt-4">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted mb-2"><?= htmlspecialchars(translate('analytics.widgets_selector.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="text-muted small mb-3"><?= htmlspecialchars(translate('analytics.widgets_selector.help', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="row g-3" id="analytics-widget-selector">
                        <?php if ($widgetDefinitions === []): ?>
                            <div class="col-12">
                                <div class="alert alert-info mb-0" role="alert">
                                    <?= htmlspecialchars(translate('analytics.widgets_selector.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($widgetDefinitions as $widget): ?>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            value="<?= htmlspecialchars((string) $widget['id'], ENT_QUOTES, 'UTF-8') ?>"
                                            id="widget-toggle-<?= htmlspecialchars((string) $widget['id'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-widget-toggle
                                            <?= $widget['default_enabled'] ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label" for="widget-toggle-<?= htmlspecialchars((string) $widget['id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <strong><?= htmlspecialchars((string) $widget['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </label>
                                        <div class="form-text"><?= htmlspecialchars((string) $widget['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <div id="analytics-alert" class="alert d-none mt-4" role="alert"></div>
            <div id="analytics-loading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                <p class="text-muted mt-3 mb-0" data-analytics="loading-text"><?= htmlspecialchars(translate('analytics.loading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div id="analytics-content" class="d-none">
                <p class="text-muted small" id="analytics-range-summary"></p>
                <section class="row g-3" id="analytics-summary">
                    <div class="col-md-3">
                        <div class="card h-100 border-0 shadow-sm summary-card">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted mb-2"><?= htmlspecialchars(translate('analytics.summary.compliance', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                <p class="display-6 mb-1" data-analytics="sla-compliance">0%</p>
                                <p class="text-muted small mb-0" data-analytics="sla-target"></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card h-100 border-0 shadow-sm summary-card">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted mb-2"><?= htmlspecialchars(translate('analytics.summary.avg_cycle_time', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                <p class="display-6 mb-1" data-analytics="avg-cycle-time">0</p>
                                <p class="text-muted small mb-0" data-analytics="avg-cycle-help"></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card h-100 border-0 shadow-sm summary-card">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted mb-2"><?= htmlspecialchars(translate('analytics.summary.avg_days_out', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                <p class="display-6 mb-1" data-analytics="avg-days-out">0</p>
                                <p class="text-muted small mb-0" data-analytics="avg-days-out-help"></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card h-100 border-0 shadow-sm summary-card">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted mb-2"><?= htmlspecialchars(translate('analytics.summary.expected_week', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                <p class="display-6 mb-1" data-analytics="projected-total">0</p>
                                <p class="text-muted small mb-0" data-analytics="trend-label"></p>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="analytics-widgets mt-4">
                    <div class="analytics-widget" data-widget="sla-overview">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex flex-column flex-xl-row gap-4">
                                    <div class="flex-fill">
                                        <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.chart_compliance', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                        <div class="ratio ratio-1x1">
                                            <canvas id="analytics-chart-sla-compliance" aria-label="<?= htmlspecialchars(translate('analytics.widgets.sla-overview.chart_compliance', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                        </div>
                                        <p class="text-muted small mt-2 d-none" data-chart-empty="sla-compliance"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.no_breaches', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="flex-fill">
                                        <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.chart_trend', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                        <div class="ratio ratio-4x3">
                                            <canvas id="analytics-chart-sla-trend" aria-label="<?= htmlspecialchars(translate('analytics.widgets.sla-overview.chart_trend', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                        </div>
                                        <p class="text-muted small mt-2 d-none" data-chart-empty="sla-trend"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.no_breaches', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </div>
                                <hr class="my-4">
                                <h3 class="h6 mb-3"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.breaches_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th scope="col"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.table.reference', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.table.days_transcurridos', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.table.days_fuera', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.table.status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="analytics-sla-breaches">
                                            <tr data-empty>
                                                <td colspan="4" class="text-muted"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.no_breaches', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3">
                                    <h4 class="h6 text-muted mb-2"><?= htmlspecialchars(translate('analytics.widgets.sla-overview.by_status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h4>
                                    <ul class="list-inline mb-0" id="analytics-sla-status-breakdown"></ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="analytics-widget mt-4" data-widget="dock-capacity">
                        <div class="card h-100">
                            <div class="card-body">
                                <h3 class="h6 mb-3"><?= htmlspecialchars(translate('analytics.widgets.dock-capacity.chart_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="ratio ratio-4x3">
                                    <canvas id="analytics-chart-dock-capacity" aria-label="<?= htmlspecialchars(translate('analytics.widgets.dock-capacity.chart_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                </div>
                                <p class="text-muted small mt-2 d-none" data-chart-empty="dock-capacity"><?= htmlspecialchars(translate('analytics.widgets.dock-capacity.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="table-responsive mt-4">
                                    <table class="table table-sm align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th scope="col"><?= htmlspecialchars(translate('analytics.widgets.dock-capacity.table.dock', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col" class="text-end"><?= htmlspecialchars(translate('analytics.widgets.dock-capacity.table.capacity', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col" class="text-end"><?= htmlspecialchars(translate('analytics.widgets.dock-capacity.table.active', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col" class="text-end"><?= htmlspecialchars(translate('analytics.widgets.dock-capacity.table.utilization', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="analytics-dock-capacity">
                                            <tr data-empty>
                                                <td colspan="4" class="text-muted"><?= htmlspecialchars(translate('analytics.widgets.dock-capacity.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="analytics-widget mt-4" data-widget="weather-forecast">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between flex-column flex-sm-row gap-2 align-items-sm-center">
                                    <div>
                                        <h3 class="h6 mb-1"><?= htmlspecialchars(translate('analytics.widgets.weather-forecast.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                        <p class="text-muted small mb-0"><?= htmlspecialchars(translate('analytics.widgets.weather-forecast.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="text-muted small" id="analytics-weather-updated"></div>
                                </div>
                                <div class="mt-3" id="analytics-weather-container"></div>
                                <p class="text-muted small mt-3 d-none" data-weather-empty><?= htmlspecialchars(translate('analytics.widgets.weather-forecast.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </main>
        <?php require __DIR__ . '/partials/footer.php'; ?>
        <script>
            window.AnalyticsThemeConfig = {
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
            window.AppConfig = Object.assign({}, window.AnalyticsThemeConfig);
            window.AnalyticsConfig = Object.assign({}, window.AnalyticsThemeConfig, {
                language: <?= json_encode($currentLanguage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                rangeOptions: <?= json_encode(array_values(array_map('intval', $rangeOptions)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                defaultRange: <?= json_encode($defaultRangeDays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                widgets: <?= json_encode($widgetDefinitions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                translations: <?= json_encode($analyticsTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            });
        </script>
        <?php
            $pageScripts = [
                [
                    'src' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
                    'integrity' => 'sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4',
                    'crossorigin' => 'anonymous',
                ],
                'assets/js/theme.js',
                'assets/js/analytics.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
