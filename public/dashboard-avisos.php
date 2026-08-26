<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';

if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

$user = is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$userRole = trim((string) ($user['role'] ?? ''));

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    header('Location: index.php');
    exit;
}

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();
$isEnglish = $currentLanguage === 'en';

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

$userName = trim((string) ($user['name'] ?? ''));
$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;

$navLinks = [
    [
        'href' => 'dashboard-avisos.php',
        'label' => translate('dashboard.nav.dashboard', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
    ],
    [
        'href' => 'desembarque-nuevo.php',
        'label' => $isEnglish ? 'New unloading record' : 'Nuevo desembarque',
        'class' => 'btn btn-outline-primary btn-sm',
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
        'href' => 'aviso-importar.php',
        'label' => $isEnglish ? 'Import history' : 'Importar históricos',
        'class' => 'btn btn-outline-secondary btn-sm',
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

$pageLabels = [
    'pageTitle' => $isEnglish ? 'Executive Unloading Notices Dashboard' : 'Dashboard Ejecutivo de Avisos de Desembarque',
    'eyebrow' => $isEnglish ? 'Executive control' : 'Control ejecutivo',
    'title' => $isEnglish ? 'Unloading Notices Dashboard' : 'Dashboard de Avisos de Desembarque',
    'subtitle' => $isEnglish
        ? 'Operational and documentary overview from validated production data.'
        : 'Visión operativa y documental construida exclusivamente con datos de producción validados.',
    'filters' => $isEnglish ? 'Executive filters' : 'Filtros ejecutivos',
    'year' => $isEnglish ? 'Year' : 'Año',
    'allYears' => $isEnglish ? 'All history' : 'Todo el histórico',
    'from' => $isEnglish ? 'From' : 'Desde',
    'to' => $isEnglish ? 'To' : 'Hasta',
    'client' => $isEnglish ? 'Client' : 'Cliente',
    'allClients' => $isEnglish ? 'All clients' : 'Todos los clientes',
    'rig' => $isEnglish ? 'Rig / Project' : 'Rig / Proyecto',
    'allRigs' => $isEnglish ? 'All rigs' : 'Todos los Rigs',
    'status' => $isEnglish ? 'Document status' : 'Estado documental',
    'allStatuses' => $isEnglish ? 'All statuses' : 'Todos los estados',
    'origin' => $isEnglish ? 'Origin' : 'Origen',
    'allOrigins' => $isEnglish ? 'All origins' : 'Todos los orígenes',
    'system' => $isEnglish ? 'System' : 'Sistema',
    'historical' => $isEnglish ? 'Historical' : 'Histórico',
    'scope' => $isEnglish ? 'Data scope' : 'Alcance de datos',
    'production' => $isEnglish ? 'Production' : 'Producción',
    'qa' => 'QA',
    'allScope' => $isEnglish ? 'Production + QA' : 'Producción + QA',
    'apply' => $isEnglish ? 'Apply filters' : 'Aplicar filtros',
    'reset' => $isEnglish ? 'Clear' : 'Limpiar',
    'refresh' => $isEnglish ? 'Refresh' : 'Actualizar',
    'exportPdf' => $isEnglish ? 'Executive PDF' : 'PDF ejecutivo',
    'exportExcel' => $isEnglish ? 'Excel' : 'Excel',
    'printReport' => $isEnglish ? 'Print' : 'Imprimir',
    'exporting' => $isEnglish ? 'Preparing export…' : 'Preparando exportación…',
    'exportUnavailable' => $isEnglish ? 'Load a valid dashboard before exporting.' : 'Carga un Dashboard válido antes de exportar.',
    'reportTitle' => $isEnglish ? 'Executive Unloading Notices Report' : 'Reporte Ejecutivo de Avisos de Desembarque',
    'reportFilters' => $isEnglish ? 'Applied filters' : 'Filtros aplicados',
    'newLanding' => $isEnglish ? 'New unloading record' : 'Nuevo desembarque',
    'loading' => $isEnglish ? 'Loading executive indicators…' : 'Cargando indicadores ejecutivos…',
    'integrity' => $isEnglish ? 'KPI integrity validated' : 'Integridad KPI validada',
    'updated' => $isEnglish ? 'Updated' : 'Actualizado',
    'avisosTotal' => $isEnglish ? 'Total notices' : 'Avisos totales',
    'avisosHelp' => $isEnglish ? 'One notice = one case file' : 'Un aviso = un expediente',
    'presented' => $isEnglish ? 'Presented' : 'Presentados',
    'presentedHelp' => $isEnglish ? 'Documentary status' : 'Estado documental',
    'storedPieces' => $isEnglish ? 'Pieces in storage' : 'Piezas en almacén',
    'storedHelp' => $isEnglish ? 'Current balance' : 'Saldo actual',
    'exportedPieces' => $isEnglish ? 'Exported pieces' : 'Piezas exportadas',
    'exportedHelp' => $isEnglish ? 'Active outbound movements' : 'Movimientos vigentes',
    'partialRows' => $isEnglish ? 'Partially released merchandise' : 'Mercancías parciales',
    'partialHelp' => $isEnglish ? 'Merchandise lines with a remaining balance' : 'Renglones con saldo pendiente',
    'pdfVersions' => $isEnglish ? 'PDF versions' : 'Versiones PDF',
    'pdfHelp' => $isEnglish ? 'Issued + historical originals' : 'Emitidas + originales históricos',
    'activityTitle' => $isEnglish ? 'Notice activity' : 'Actividad de avisos',
    'activitySubtitle' => $isEnglish ? 'Monthly evolution by notice date.' : 'Evolución mensual según la fecha contractual del aviso.',
    'statusTitle' => $isEnglish ? 'Document status' : 'Estado documental',
    'statusSubtitle' => $isEnglish ? 'Current state of each case file.' : 'Situación actual de cada expediente.',
    'piecesTitle' => $isEnglish ? 'Merchandise balance' : 'Balance de mercancía',
    'piecesSubtitle' => $isEnglish ? 'Original pieces vs. exported and currently stored.' : 'Piezas originales frente a exportadas y saldo almacenado.',
    'agingTitle' => $isEnglish ? 'Storage aging' : 'Antigüedad en almacén',
    'agingSubtitle' => $isEnglish ? 'Current stored pieces by elapsed time.' : 'Piezas actualmente almacenadas por antigüedad.',
    'clientsTitle' => $isEnglish ? 'Client activity' : 'Actividad por cliente',
    'rigsTitle' => $isEnglish ? 'Rig / Project activity' : 'Actividad por Rig / Proyecto',
    'recentTitle' => $isEnglish ? 'Recent notices' : 'Avisos recientes',
    'recentSubtitle' => $isEnglish ? 'Most recent case files matching the active filters.' : 'Últimos expedientes dentro del filtro activo.',
    'attentionTitle' => $isEnglish ? 'Executive attention' : 'Atención ejecutiva',
    'draft' => $isEnglish ? 'Draft' : 'Borrador',
    'issued' => $isEnglish ? 'Issued' : 'Emitido',
    'replaced' => $isEnglish ? 'Replaced' : 'Reemplazado',
    'cancelled' => $isEnglish ? 'Cancelled' : 'Cancelado',
    'over90' => $isEnglish ? 'Pieces stored for 90+ days' : 'Piezas +90 días',
    'cycleTitle' => $isEnglish ? 'Cycle times' : 'Tiempos de ciclo',
    'landingNotice' => $isEnglish ? 'Unloading → Notice' : 'Desembarque → Aviso',
    'noticePresented' => $isEnglish ? 'Notice → Presented' : 'Aviso → Presentado',
    'days' => $isEnglish ? 'days' : 'días',
    'noData' => $isEnglish ? 'No data for the selected filters.' : 'No hay datos para los filtros seleccionados.',
    'errorTitle' => $isEnglish ? 'Dashboard unavailable' : 'Dashboard no disponible',
    'errorGeneric' => $isEnglish ? 'The executive summary could not be loaded.' : 'No fue posible cargar el resumen ejecutivo.',
    'openCase' => $isEnglish ? 'Open case file' : 'Abrir expediente',
    'notice' => $isEnglish ? 'Notice' : 'Aviso',
    'date' => $isEnglish ? 'Date' : 'Fecha',
    'clientCol' => $isEnglish ? 'Client' : 'Cliente',
    'rigCol' => $isEnglish ? 'Rig / Project' : 'Rig / Proyecto',
    'originCol' => $isEnglish ? 'Origin' : 'Origen',
    'statusCol' => $isEnglish ? 'Status' : 'Estado',
    'versionCol' => $isEnglish ? 'Last PDF' : 'Último PDF',
    'actionsCol' => $isEnglish ? 'Action' : 'Acción',
    'avisos' => $isEnglish ? 'Notices' : 'Avisos',
    'pieces' => $isEnglish ? 'Pieces' : 'Piezas',
    'stored' => $isEnglish ? 'Stored' : 'Almacén',
    'exported' => $isEnglish ? 'Exported' : 'Exportadas',
    'rows' => $isEnglish ? 'Merchandise lines' : 'Renglones',
    'pedimentos' => $isEnglish ? 'Customs entries' : 'Pedimentos',
    'clients' => $isEnglish ? 'Clients' : 'Clientes',
    'rigs' => $isEnglish ? 'Rigs' : 'Rigs',
];

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars($pageLabels['pageTitle'], ENT_QUOTES, 'UTF-8') ?></title>
    <?php require __DIR__ . '/partials/styles.php'; ?>
    <link rel="stylesheet" href="assets/css/dashboard-avisos.css">
</head>
<body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
    <?php require __DIR__ . '/partials/nav.php'; ?>

    <main class="dashboard-executive-shell" id="dashboard-executive-app">
        <div class="container-fluid dashboard-executive-container">
            <header class="dashboard-hero">
                <div>
                    <div class="dashboard-eyebrow"><?= htmlspecialchars($pageLabels['eyebrow'], ENT_QUOTES, 'UTF-8') ?></div>
                    <h1><?= htmlspecialchars($pageLabels['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <p><?= htmlspecialchars($pageLabels['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="dashboard-hero-meta">
                    <span class="dashboard-integrity-badge" data-dashboard-integrity>
                        <span class="dashboard-integrity-dot" aria-hidden="true"></span>
                        <?= htmlspecialchars($pageLabels['integrity'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="dashboard-updated" data-dashboard-updated>—</span>
                    <div class="dashboard-export-actions" data-dashboard-export-actions>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-dashboard-export="pdf" disabled>
                            <?= htmlspecialchars($pageLabels['exportPdf'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-dashboard-export="excel" disabled>
                            <?= htmlspecialchars($pageLabels['exportExcel'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-dashboard-export="print" disabled>
                            <?= htmlspecialchars($pageLabels['printReport'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                    <a href="desembarque-nuevo.php" class="btn btn-primary btn-sm">
                        + <?= htmlspecialchars($pageLabels['newLanding'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <button type="button" class="btn btn-outline-secondary btn-sm dashboard-refresh-button" data-dashboard-refresh>
                        <?= htmlspecialchars($pageLabels['refresh'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </header>

            <section class="dashboard-print-header" aria-hidden="true">
                <div class="dashboard-print-brand">GRUPO GEREZ</div>
                <h1><?= htmlspecialchars($pageLabels['reportTitle'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p data-dashboard-print-filters><?= htmlspecialchars($pageLabels['reportFilters'], ENT_QUOTES, 'UTF-8') ?>: —</p>
                <p data-dashboard-print-generated>—</p>
            </section>

            <section class="dashboard-filter-panel" aria-labelledby="dashboard-filters-title">
                <div class="dashboard-filter-heading">
                    <div>
                        <span class="dashboard-section-kicker">01</span>
                        <h2 id="dashboard-filters-title"><?= htmlspecialchars($pageLabels['filters'], ENT_QUOTES, 'UTF-8') ?></h2>
                    </div>
                    <button type="button" class="btn btn-link dashboard-filter-reset" data-dashboard-reset>
                        <?= htmlspecialchars($pageLabels['reset'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <form class="dashboard-filter-grid" data-dashboard-filters autocomplete="off">
                    <div class="dashboard-filter-field">
                        <label for="dashboard-filter-year"><?= htmlspecialchars($pageLabels['year'], ENT_QUOTES, 'UTF-8') ?></label>
                        <select id="dashboard-filter-year" class="form-select" data-filter="year">
                            <option value=""><?= htmlspecialchars($pageLabels['allYears'], ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <div class="dashboard-filter-field">
                        <label for="dashboard-filter-from"><?= htmlspecialchars($pageLabels['from'], ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="date" id="dashboard-filter-from" class="form-control" data-filter="date_from">
                    </div>
                    <div class="dashboard-filter-field">
                        <label for="dashboard-filter-to"><?= htmlspecialchars($pageLabels['to'], ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="date" id="dashboard-filter-to" class="form-control" data-filter="date_to">
                    </div>
                    <div class="dashboard-filter-field">
                        <label for="dashboard-filter-client"><?= htmlspecialchars($pageLabels['client'], ENT_QUOTES, 'UTF-8') ?></label>
                        <select id="dashboard-filter-client" class="form-select" data-filter="client_id">
                            <option value=""><?= htmlspecialchars($pageLabels['allClients'], ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <div class="dashboard-filter-field">
                        <label for="dashboard-filter-rig"><?= htmlspecialchars($pageLabels['rig'], ENT_QUOTES, 'UTF-8') ?></label>
                        <select id="dashboard-filter-rig" class="form-select" data-filter="rig_name">
                            <option value=""><?= htmlspecialchars($pageLabels['allRigs'], ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <div class="dashboard-filter-field">
                        <label for="dashboard-filter-status"><?= htmlspecialchars($pageLabels['status'], ENT_QUOTES, 'UTF-8') ?></label>
                        <select id="dashboard-filter-status" class="form-select" data-filter="aviso_status">
                            <option value="all"><?= htmlspecialchars($pageLabels['allStatuses'], ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="draft"><?= htmlspecialchars($pageLabels['draft'], ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="issued"><?= htmlspecialchars($pageLabels['issued'], ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="presented"><?= htmlspecialchars($pageLabels['presented'], ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="replaced"><?= htmlspecialchars($pageLabels['replaced'], ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="cancelled"><?= htmlspecialchars($pageLabels['cancelled'], ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <div class="dashboard-filter-field">
                        <label for="dashboard-filter-origin"><?= htmlspecialchars($pageLabels['origin'], ENT_QUOTES, 'UTF-8') ?></label>
                        <select id="dashboard-filter-origin" class="form-select" data-filter="origin">
                            <option value="all"><?= htmlspecialchars($pageLabels['allOrigins'], ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="system"><?= htmlspecialchars($pageLabels['system'], ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="historical"><?= htmlspecialchars($pageLabels['historical'], ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <?php if ($userRole === 'admin'): ?>
                        <div class="dashboard-filter-field">
                            <label for="dashboard-filter-scope"><?= htmlspecialchars($pageLabels['scope'], ENT_QUOTES, 'UTF-8') ?></label>
                            <select id="dashboard-filter-scope" class="form-select" data-filter="record_scope">
                                <option value="production"><?= htmlspecialchars($pageLabels['production'], ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="qa"><?= htmlspecialchars($pageLabels['qa'], ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="all"><?= htmlspecialchars($pageLabels['allScope'], ENT_QUOTES, 'UTF-8') ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="dashboard-filter-actions">
                        <button type="submit" class="btn btn-primary dashboard-apply-button">
                            <?= htmlspecialchars($pageLabels['apply'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </form>
            </section>

            <div class="dashboard-loading-state" data-dashboard-loading>
                <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                <span><?= htmlspecialchars($pageLabels['loading'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <section class="dashboard-error-panel d-none" data-dashboard-error role="alert">
                <strong><?= htmlspecialchars($pageLabels['errorTitle'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span data-dashboard-error-message><?= htmlspecialchars($pageLabels['errorGeneric'], ENT_QUOTES, 'UTF-8') ?></span>
            </section>

            <div class="dashboard-content d-none" data-dashboard-content>
                <section class="dashboard-kpi-grid" aria-label="KPI">
                    <article class="dashboard-kpi-card dashboard-kpi-card--primary dashboard-kpi-card--interactive" data-dashboard-drill="all" tabindex="0" role="button">
                        <div class="dashboard-kpi-icon" aria-hidden="true">A</div>
                        <div class="dashboard-kpi-body">
                            <span><?= htmlspecialchars($pageLabels['avisosTotal'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong data-kpi="avisos_total">0</strong>
                            <small><?= htmlspecialchars($pageLabels['avisosHelp'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                    <article class="dashboard-kpi-card dashboard-kpi-card--interactive" data-dashboard-drill="presented" tabindex="0" role="button">
                        <div class="dashboard-kpi-icon" aria-hidden="true">✓</div>
                        <div class="dashboard-kpi-body">
                            <span><?= htmlspecialchars($pageLabels['presented'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong data-kpi="avisos_presented">0</strong>
                            <small><?= htmlspecialchars($pageLabels['presentedHelp'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                    <article class="dashboard-kpi-card dashboard-kpi-card--stored">
                        <div class="dashboard-kpi-icon" aria-hidden="true">▦</div>
                        <div class="dashboard-kpi-body">
                            <span><?= htmlspecialchars($pageLabels['storedPieces'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong data-kpi="pieces_stored">0</strong>
                            <small><?= htmlspecialchars($pageLabels['storedHelp'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                    <article class="dashboard-kpi-card dashboard-kpi-card--exported">
                        <div class="dashboard-kpi-icon" aria-hidden="true">↗</div>
                        <div class="dashboard-kpi-body">
                            <span><?= htmlspecialchars($pageLabels['exportedPieces'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong data-kpi="pieces_exported">0</strong>
                            <small><?= htmlspecialchars($pageLabels['exportedHelp'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                    <article class="dashboard-kpi-card dashboard-kpi-card--warning">
                        <div class="dashboard-kpi-icon" aria-hidden="true">◐</div>
                        <div class="dashboard-kpi-body">
                            <span><?= htmlspecialchars($pageLabels['partialRows'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong data-kpi="rows_partial">0</strong>
                            <small><?= htmlspecialchars($pageLabels['partialHelp'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                    <article class="dashboard-kpi-card">
                        <div class="dashboard-kpi-icon" aria-hidden="true">PDF</div>
                        <div class="dashboard-kpi-body">
                            <span><?= htmlspecialchars($pageLabels['pdfVersions'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong data-kpi="pdf_versions">0</strong>
                            <small><?= htmlspecialchars($pageLabels['pdfHelp'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                </section>

                <section class="dashboard-secondary-metrics">
                    <article><span><?= htmlspecialchars($pageLabels['pedimentos'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="pedimentos_unique">0</strong></article>
                    <article><span><?= htmlspecialchars($pageLabels['clients'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="clients_active">0</strong></article>
                    <article><span><?= htmlspecialchars($pageLabels['rigs'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="rigs_active">0</strong></article>
                    <article><span><?= htmlspecialchars($pageLabels['rows'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="merchandise_rows">0</strong></article>
                    <article><span><?= htmlspecialchars($pageLabels['pieces'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="pieces_original">0</strong></article>
                </section>

                <section class="dashboard-panel-grid dashboard-panel-grid--primary">
                    <article class="dashboard-panel dashboard-panel--wide">
                        <div class="dashboard-panel-header">
                            <div>
                                <span class="dashboard-section-kicker">02</span>
                                <h2><?= htmlspecialchars($pageLabels['activityTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <p><?= htmlspecialchars($pageLabels['activitySubtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                        <div class="dashboard-chart-frame dashboard-chart-frame--large">
                            <canvas id="dashboard-chart-monthly" role="img" aria-label="<?= htmlspecialchars($pageLabels['activityTitle'], ENT_QUOTES, 'UTF-8') ?>"></canvas>
                            <div class="dashboard-empty-chart d-none" data-chart-empty="monthly"><?= htmlspecialchars($pageLabels['noData'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </article>

                    <article class="dashboard-panel">
                        <div class="dashboard-panel-header">
                            <div>
                                <span class="dashboard-section-kicker">03</span>
                                <h2><?= htmlspecialchars($pageLabels['statusTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <p><?= htmlspecialchars($pageLabels['statusSubtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                        <div class="dashboard-chart-frame dashboard-chart-frame--donut">
                            <canvas id="dashboard-chart-status" role="img" aria-label="<?= htmlspecialchars($pageLabels['statusTitle'], ENT_QUOTES, 'UTF-8') ?>"></canvas>
                            <div class="dashboard-empty-chart d-none" data-chart-empty="status"><?= htmlspecialchars($pageLabels['noData'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </article>
                </section>

                <section class="dashboard-panel-grid">
                    <article class="dashboard-panel">
                        <div class="dashboard-panel-header">
                            <div>
                                <span class="dashboard-section-kicker">04</span>
                                <h2><?= htmlspecialchars($pageLabels['piecesTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <p><?= htmlspecialchars($pageLabels['piecesSubtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                        <div class="dashboard-chart-frame dashboard-chart-frame--donut">
                            <canvas id="dashboard-chart-pieces" role="img" aria-label="<?= htmlspecialchars($pageLabels['piecesTitle'], ENT_QUOTES, 'UTF-8') ?>"></canvas>
                            <div class="dashboard-empty-chart d-none" data-chart-empty="pieces"><?= htmlspecialchars($pageLabels['noData'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="dashboard-balance-strip">
                            <div><span><?= htmlspecialchars($pageLabels['pieces'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="pieces_original">0</strong></div>
                            <div><span><?= htmlspecialchars($pageLabels['exported'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="pieces_exported">0</strong></div>
                            <div><span><?= htmlspecialchars($pageLabels['stored'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="pieces_stored">0</strong></div>
                        </div>
                    </article>

                    <article class="dashboard-panel">
                        <div class="dashboard-panel-header">
                            <div>
                                <span class="dashboard-section-kicker">05</span>
                                <h2><?= htmlspecialchars($pageLabels['agingTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <p><?= htmlspecialchars($pageLabels['agingSubtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                        <div class="dashboard-chart-frame dashboard-chart-frame--bar">
                            <canvas id="dashboard-chart-aging" role="img" aria-label="<?= htmlspecialchars($pageLabels['agingTitle'], ENT_QUOTES, 'UTF-8') ?>"></canvas>
                            <div class="dashboard-empty-chart d-none" data-chart-empty="aging"><?= htmlspecialchars($pageLabels['noData'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </article>
                </section>

                <section class="dashboard-panel-grid dashboard-panel-grid--rankings">
                    <article class="dashboard-panel">
                        <div class="dashboard-panel-header">
                            <div>
                                <span class="dashboard-section-kicker">06</span>
                                <h2><?= htmlspecialchars($pageLabels['clientsTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                        </div>
                        <div class="dashboard-ranking-list" data-ranking="clients"></div>
                    </article>
                    <article class="dashboard-panel">
                        <div class="dashboard-panel-header">
                            <div>
                                <span class="dashboard-section-kicker">07</span>
                                <h2><?= htmlspecialchars($pageLabels['rigsTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                        </div>
                        <div class="dashboard-ranking-list" data-ranking="rigs"></div>
                    </article>
                </section>

                <section class="dashboard-attention-grid">
                    <article class="dashboard-attention-panel">
                        <div class="dashboard-panel-header">
                            <div>
                                <span class="dashboard-section-kicker">08</span>
                                <h2><?= htmlspecialchars($pageLabels['attentionTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                        </div>
                        <div class="dashboard-attention-list">
                            <div class="dashboard-attention-item dashboard-attention-item--interactive" data-dashboard-drill="draft" tabindex="0" role="button"><span><?= htmlspecialchars($pageLabels['draft'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="avisos_draft">0</strong></div>
                            <div class="dashboard-attention-item dashboard-attention-item--interactive" data-dashboard-drill="issued" tabindex="0" role="button"><span><?= htmlspecialchars($pageLabels['issued'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="avisos_issued">0</strong></div>
                            <div class="dashboard-attention-item"><span><?= htmlspecialchars($pageLabels['partialRows'], ENT_QUOTES, 'UTF-8') ?></span><strong data-kpi="rows_partial">0</strong></div>
                            <div class="dashboard-attention-item dashboard-attention-item--critical"><span><?= htmlspecialchars($pageLabels['over90'], ENT_QUOTES, 'UTF-8') ?></span><strong data-dashboard-aging-90>0</strong></div>
                        </div>
                    </article>
                    <article class="dashboard-attention-panel dashboard-cycle-panel">
                        <div class="dashboard-panel-header">
                            <div>
                                <span class="dashboard-section-kicker">09</span>
                                <h2><?= htmlspecialchars($pageLabels['cycleTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                        </div>
                        <div class="dashboard-cycle-grid">
                            <div>
                                <span><?= htmlspecialchars($pageLabels['landingNotice'], ENT_QUOTES, 'UTF-8') ?></span>
                                <strong data-cycle="landing_to_notice_days">—</strong>
                                <small data-cycle-samples="landing_to_notice_samples">—</small>
                            </div>
                            <div>
                                <span><?= htmlspecialchars($pageLabels['noticePresented'], ENT_QUOTES, 'UTF-8') ?></span>
                                <strong data-cycle="notice_to_presented_days">—</strong>
                                <small data-cycle-samples="notice_to_presented_samples">—</small>
                            </div>
                        </div>
                    </article>
                </section>

                <section class="dashboard-panel dashboard-recent-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <span class="dashboard-section-kicker">10</span>
                            <h2><?= htmlspecialchars($pageLabels['recentTitle'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= htmlspecialchars($pageLabels['recentSubtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table dashboard-recent-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= htmlspecialchars($pageLabels['notice'], ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($pageLabels['date'], ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($pageLabels['clientCol'], ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($pageLabels['rigCol'], ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($pageLabels['originCol'], ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($pageLabels['statusCol'], ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($pageLabels['versionCol'], ENT_QUOTES, 'UTF-8') ?></th>
                                    <th class="text-end"><?= htmlspecialchars($pageLabels['actionsCol'], ENT_QUOTES, 'UTF-8') ?></th>
                                </tr>
                            </thead>
                            <tbody data-dashboard-recent></tbody>
                        </table>
                        <div class="dashboard-empty-table d-none" data-dashboard-recent-empty><?= htmlspecialchars($pageLabels['noData'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <script>
        window.DashboardAvisosConfig = <?= json_encode([
            'apiUrl' => '../api/desembarques/dashboard/summary.php',
            'language' => $currentLanguage,
            'isAdmin' => $userRole === 'admin',
            'labels' => $pageLabels,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php
        $pageScripts = [
            [
                'src' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
                'integrity' => 'sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4',
                'crossorigin' => 'anonymous',
            ],
            [
                'src' => 'https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js',
            ],
            [
                'src' => 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
            ],
            ['src' => 'assets/js/theme.js'],
            ['src' => 'assets/js/dashboard-avisos.js'],
            ['src' => 'assets/js/dashboard-avisos-export.js'],
        ];
        require __DIR__ . '/partials/scripts.php';
    ?>
</body>
</html>
