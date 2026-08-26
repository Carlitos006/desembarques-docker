<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/files.php';

function format_file_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $index = 0;

    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    $decimals = $index === 0 || $size >= 10 ? 0 : 2;
    $formatted = number_format($size, $decimals, '.', '');

    if (strpos($formatted, '.') !== false) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted . ' ' . $units[$index];
}

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

$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$roleLabelEscaped = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
$userNameEscaped = htmlspecialchars($userName !== '' ? $userName : translate('dashboard.nav.profile', [], $currentLanguage), ENT_QUOTES, 'UTF-8');

$fileStorageConfig = file_storage_config();
$allowedExtensions = array_map('strtolower', $fileStorageConfig['allowed_extensions'] ?? []);
$allowedExtensions = array_values(array_unique($allowedExtensions));
$attachmentsAccept = $allowedExtensions !== []
    ? implode(',', array_map(
        static function (string $extension): string {
            return '.' . $extension;
        },
        $allowedExtensions
    ))
    : '';
$allowedExtensionsDisplay = $allowedExtensions !== []
    ? implode(', ', array_map(
        static function (string $extension): string {
            return '.' . strtoupper($extension);
        },
        $allowedExtensions
    ))
    : '';
$maxAttachmentSize = isset($fileStorageConfig['max_size']) ? (int) $fileStorageConfig['max_size'] : 0;
$maxAttachmentSizeFormatted = $maxAttachmentSize > 0 ? format_file_size($maxAttachmentSize) : '';
$maxAttachmentsPerRequest = isset($fileStorageConfig['max_files_per_request'])
    ? (int) $fileStorageConfig['max_files_per_request']
    : 0;
$fileUploadClientConfig = [
    'maxSize' => $maxAttachmentSize,
    'maxFilesPerRequest' => $maxAttachmentsPerRequest,
    'allowedExtensions' => $allowedExtensions,
    'allowedMimeTypes' => $fileStorageConfig['allowed_mime_types'] ?? [],
    'accept' => $attachmentsAccept,
    'uploadUrl' => '../api/desembarques/files/upload.php',
    'deleteUrl' => '../api/desembarques/files/delete.php',
    'downloadUrl' => '../api/desembarques/files/download.php',
];
$attachmentsAcceptAttribute = $attachmentsAccept !== ''
    ? ' accept="' . htmlspecialchars($attachmentsAccept, ENT_QUOTES, 'UTF-8') . '"'
    : '';

$reportsTranslations = getClientTranslations([
    'reports.alert.load_error',
    'reports.alert.no_results',
    'reports.alert.update_error',
    'reports.alert.update_success',
    'reports.alert.update_validation',
    'reports.alert.observaciones_update_error',
    'reports.alert.observaciones_update_success',
    'reports.alert.observaciones_update_validation',
    'reports.alert.observaciones_unread',
    'reports.alert.observaciones_unread_with_list',
    'reports.alert.observaciones_new_received',
    'reports.alert.observaciones_new_received_with_list',
    'reports.actions.edit',
    'reports.actions.observaciones',
    'reports.actions.aviso',
    'reports.attachments.title',
    'reports.attachments.empty',
    'reports.attachments.upload_label',
    'reports.attachments.upload_button',
    'reports.attachments.upload_help_types',
    'reports.attachments.upload_help_max',
    'reports.attachments.delete',
    'reports.attachments.download',
    'reports.attachments.delete_confirm',
    'reports.attachments.delete_confirm_button',
    'reports.attachments.delete_cancel_button',
    'reports.table.loading',
    'reports.table.empty',
    'reports.table.active_empty',
    'reports.table.not_available',
    'reports.table.canceled.empty',
    'reports.table.headers.reference',
    'reports.table.headers.client',
    'reports.table.headers.vessel',
    'reports.table.headers.destination',
    'reports.table.headers.status',
    'reports.table.headers.description',
    'reports.table.headers.pedimentos',
    'reports.table.headers.partidas',
    'reports.table.headers.observaciones',
    'reports.table.headers.attachments',
    'reports.table.headers.aviso',
    'reports.table.headers.notice_number',
    'reports.table.headers.landing_date',
    'reports.table.headers.departure_date',
    'reports.table.headers.days_elapsed',
    'reports.table.headers.days_out',
    'reports.summary.records',
    'reports.summary.days_elapsed',
    'reports.summary.days_out',
    'reports.export.analytics_csv',
    'reports.export.analytics_empty',
    'reports.export.analytics_trend_period',
    'reports.export.analytics_trend_records',
    'reports.export.analytics_status',
    'reports.export.analytics_status_label',
    'reports.export.analytics_status_average',
    'reports.export.analytics_status_totals',
    'reports.export.analytics_status_records',
    'reports.export.analytics_clients',
    'reports.export.analytics_client_label',
    'reports.export.analytics_client_average',
    'reports.export.analytics_vessels',
    'reports.export.analytics_vessel_label',
    'reports.export.analytics_vessel_records',
    'reports.export.error_no_data',
    'reports.export.error_library',
    'reports.export.file_name',
    'reports.charts.title',
    'reports.charts.records_by_client',
    'reports.charts.days_distribution',
    'reports.charts.records_by_status',
    'reports.charts.records_by_vessel',
    'reports.charts.records_trend',
    'reports.charts.average_by_status',
    'reports.charts.client_performance',
    'reports.charts.average_days',
    'reports.charts.average_days_out',
    'reports.charts.unknown_client',
    'reports.charts.unknown_vessel',
    'reports.charts.other',
    'reports.charts.no_data',
    'reports.header',
    'common.session_expired',
    'reports.observations_modal.title',
    'reports.observations_modal.description',
    'reports.observations_modal.label',
    'reports.observations_modal.placeholder',
    'reports.observations_modal.read_only_notice',
    'reports.observations_modal.history_title',
    'reports.observations_modal.empty',
    'reports.observations_modal.loading',
    'reports.observations_modal.load_error',
    'reports.observations_modal.count_format',
    'reports.observations_modal.meta',
    'reports.observations_modal.meta_no_date',
    'reports.observations_modal.you',
    'reports.observations_modal.legacy_author',
    'reports.observations_modal.cancel',
    'reports.observations_modal.save',
    'reports.table.view_details',
    'reports.modal.pedimentos.title',
    'reports.modal.pedimentos.empty',
    'reports.modal.partidas.title',
    'reports.modal.partidas.empty',
    'reports.modal.close',
    'pedimentos.modal.title',
    'pedimentos.modal.upload_label',
    'pedimentos.modal.upload_help',
    'pedimentos.modal.process_button',
    'pedimentos.modal.processing',
    'pedimentos.modal.filter_server_button',
    'pedimentos.modal.filtering',
    'pedimentos.modal.feedback.no_file',
    'pedimentos.modal.feedback.server_error',
    'pedimentos.modal.feedback.parse_error',
    'pedimentos.modal.feedback.network_error',
    'pedimentos.modal.selected_summary_none',
    'pedimentos.modal.selected_summary_some',
    'pedimentos.modal.secs_empty',
    'pedimentos.modal.secs_title',
    'pedimentos.modal.secs_hint',
    'pedimentos.modal.sec_label',
    'pedimentos.modal.search_label',
    'pedimentos.modal.search_placeholder',
    'pedimentos.modal.select_all',
    'pedimentos.modal.select_none',
    'pedimentos.modal.select_invert',
    'pedimentos.modal.results_count',
    'pedimentos.modal.results_title',
    'pedimentos.modal.results_empty',
    'pedimentos.modal.export_json',
    'pedimentos.modal.confirm_button_empty',
    'pedimentos.modal.confirm_button_single',
    'pedimentos.modal.confirm_button',
    'pedimentos.modal.confirm_no_selection',
    'pedimentos.modal.confirm_error',
    'pedimentos.modal.debug_title',
    'pedimentos.modal.debug_empty',
    'pedimentos.modal.header.title',
    'pedimentos.modal.header.num',
    'pedimentos.modal.header.cve',
    'pedimentos.modal.header.razon',
    'pedimentos.modal.header.fecha_entrada',
    'pedimentos.modal.header.fecha_pago',
    'pedimentos.modal.header.placeholder',
    'pedimentos.bridge.success',
    'pedimentos.bridge.empty',
    'pedimentos.bridge.error',
    'pedimentos.bridge.window_blocked',
    'pedimentos.bridge.opened',
    'pedimentos.bridge.already_open',
    'pedimentos.bridge.header.title',
    'pedimentos.bridge.header.clear',
    'pedimentos.bridge.header.empty',
    'pedimentos.bridge.header.remove',
    'pedimentos.bridge.header.pedimentos_label',
    'pedimentos.bridge.header_only',
    'pedimentos.bridge.removed',
    'pedimentos.bridge.cleared',
    'reports.aviso_modal.title',
    'reports.aviso_modal.description',
    'reports.aviso_modal.document_title',
    'reports.aviso_modal.notice_number',
    'reports.aviso_modal.location_date',
    'reports.aviso_modal.recipient',
    'reports.aviso_modal.introduction',
    'reports.aviso_modal.body',
    'reports.aviso_modal.operations',
    'reports.aviso_modal.items',
    'reports.aviso_modal.items_help',
    'reports.aviso_modal.documentation',
    'reports.aviso_modal.documentation_help',
    'reports.aviso_modal.closing',
    'reports.aviso_modal.signer_name',
    'reports.aviso_modal.signer_title',
    'reports.aviso_modal.footer',
    'reports.aviso_modal.watermark',
    'reports.aviso_modal.generate',
    'reports.aviso_modal.cancel',
    'reports.aviso_modal.summary_reference',
    'reports.aviso_modal.summary_vessel',
    'reports.aviso_modal.summary_destination',
    'reports.aviso_modal.summary_dates',
    'reports.aviso_modal.error_missing_js',
    'reports.aviso_modal.error_no_record',
    'desembarques.files.error_missing',
    'desembarques.files.error_too_many',
    'desembarques.files.validation_error',
    'desembarques.files.upload_success',
    'desembarques.files.upload_error',
    'desembarques.files.delete_success',
    'desembarques.files.delete_error',
    'desembarques.files.delete_not_found',
    'desembarques.files.load_error',
    'desembarques.files.download_error',
    'dashboard.form.attachments_error_type',
    'dashboard.form.attachments_error_size',
    'common.csrf_token_invalid',
    'reports.dashboard.pending_review',
    'reports.dashboard.sla_warning',
    'reports.dashboard.sla_breach',
    'reports.dashboard.status_empty',
    'reports.dashboard.clients_empty',
    'reports.dashboard.period_empty',
    'reports.dashboard.recent_empty',
    'reports.dashboard.risk_empty',
    'reports.dashboard.unknown_client',
    'reports.dashboard.recent_generic_update',
    'reports.dashboard.recent_status_change',
    'reports.dashboard.recent_generic_activity',
    'reports.dashboard.recent_created',
    'reports.dashboard.recent_deleted',
    'reports.dashboard.field.referencia',
    'reports.dashboard.field.status_id',
    'reports.dashboard.field.destino',
    'reports.dashboard.field.fecha_desembarque',
    'reports.dashboard.field.fecha_embarque',
    'reports.dashboard.field.dias_transcurridos',
    'reports.dashboard.field.dias_fuera',
    'reports.dashboard.field.folio_aviso',
    'reports.dashboard.field.cliente',
    'reports.dashboard.field.observaciones',
    'reports.dashboard.count_label',
    'reports.dashboard.average_label',
    'reports.dashboard.days_suffix',
], $currentLanguage);

$clientAssignSelectedTemplate = translate('dashboard.form.customer_assign_selected', ['name' => '{{name}}', 'email' => '{{email}}'], $currentLanguage);
$pedimentosLabelText = translate('dashboard.form.pedimentos', [], $currentLanguage);
$pedimentoPlaceholder = translate('dashboard.form.pedimento_placeholder', [], $currentLanguage);
$addPedimentoLabel = translate('dashboard.form.add_pedimento', [], $currentLanguage);
$pedimentosHeaderTitle = translate('pedimentos.bridge.header.title', [], $currentLanguage);
$pedimentosHeaderClear = translate('pedimentos.bridge.header.clear', [], $currentLanguage);
$pedimentosHeaderPlaceholder = translate('pedimentos.modal.header.placeholder', [], $currentLanguage);
$pedimentosHeaderEmpty = translate('pedimentos.bridge.header.empty', [], $currentLanguage);
$pedimentosParseUrl = 'pedimentos/parse.php';
$pedimentosTableUrl = '../table/index.html?lang=' . rawurlencode($currentLanguage);
$manifestsLabelText = translate('dashboard.form.manifests', [], $currentLanguage);
$manifestPlaceholder = translate('dashboard.form.manifest_placeholder', [], $currentLanguage);
$addManifestLabel = translate('dashboard.form.add_manifest', [], $currentLanguage);
$ciplsLabelText = translate('dashboard.form.cipls', [], $currentLanguage);
$ciplPlaceholder = translate('dashboard.form.cipl_placeholder', [], $currentLanguage);
$addCiplLabel = translate('dashboard.form.add_cipl', [], $currentLanguage);
$removeFieldLabel = translate('dashboard.form.remove_field', [], $currentLanguage);

$pedimentosRemoveAria = trim($removeFieldLabel) !== ''
    ? $removeFieldLabel . ' ' . trim(mb_strtolower($pedimentosLabelText, 'UTF-8'))
    : '';
$manifestsRemoveAria = trim($removeFieldLabel) !== ''
    ? $removeFieldLabel . ' ' . trim(mb_strtolower($manifestsLabelText, 'UTF-8'))
    : '';
$ciplsRemoveAria = trim($removeFieldLabel) !== ''
    ? $removeFieldLabel . ' ' . trim(mb_strtolower($ciplsLabelText, 'UTF-8'))
    : '';

$canceledReportsTitle = translate('reports.table.canceled.title', [], $currentLanguage);
if ($canceledReportsTitle === '') {
    $canceledReportsTitle = $currentLanguage === 'en'
        ? 'Canceled reports'
        : 'Reportes cancelados';
}

$csrfToken = csrf_token();

$canManageUsers = $userRole === 'admin';
$canRegister = in_array($userRole, ['admin', 'usuario'], true);
$canFilterClients = $userRole !== 'cliente';
$canAddObservaciones = in_array($userRole, ['admin', 'usuario', 'cliente'], true);

$clientFilters = [];
$clientFiltersError = '';
$statusOptions = [];
$statusOptionsError = '';

if ($canFilterClients) {
    try {
        /** @var mysqli $connection */
        $connection = getDatabaseConnection();

        $query = 'SELECT id, name, email FROM clients ORDER BY name ASC';
        $result = $connection->query($query);

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $clientName = (string) ($row['name'] ?? '');
                $clientEmail = (string) ($row['email'] ?? '');
                $clientFilters[] = [
                    'id' => (int) $row['id'],
                    'name' => $clientName,
                    'email' => $clientEmail,
                    'display' => trim($clientName) !== ''
                        ? sprintf('%s (%s)', $clientName, $clientEmail)
                        : $clientEmail,
                ];
            }

            $result->free();
        }
    } catch (Throwable $exception) {
        $clientFiltersError = $exception->getMessage();
    }
}

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();

    $statusQuery = 'SELECT id, slug, name_es, name_en, is_default FROM desembarque_statuses WHERE is_active = 1 ORDER BY is_default DESC, name_es ASC';
    $statusResult = $connection->query($statusQuery);

    if ($statusResult instanceof mysqli_result) {
        while ($row = $statusResult->fetch_assoc()) {
            $statusId = (int) ($row['id'] ?? 0);
            $nameEs = (string) ($row['name_es'] ?? '');
            $nameEn = (string) ($row['name_en'] ?? '');
            $slug = (string) ($row['slug'] ?? '');

            $display = $currentLanguage === 'en' ? $nameEn : $nameEs;

            if ($display === '') {
                $display = $currentLanguage === 'en' && $nameEs !== '' ? $nameEs : $nameEn;
            }

            if ($display === '') {
                $display = $slug;
            }

            $statusOptions[] = [
                'id' => $statusId,
                'display' => $display,
            ];
        }

        $statusResult->free();
    }
} catch (Throwable $exception) {
    $statusOptionsError = $exception->getMessage();
}

$avisoContactsRaw = [
    'recipient' => [
        'name_es' => '',
        'name_en' => '',
        'title_es' => '',
        'title_en' => '',
    ],
    'signer' => [
        'name_es' => '',
        'name_en' => '',
        'title_es' => '',
        'title_en' => '',
    ],
];

try {
    /** @var mysqli $connection */
    $connection = getDatabaseConnection();

    $contactsQuery = 'SELECT contact_type, name_es, name_en, title_es, title_en FROM aviso_contacts '
        . 'ORDER BY contact_type ASC, is_default DESC, id ASC';
    $contactsResult = $connection->query($contactsQuery);

    if ($contactsResult instanceof mysqli_result) {
        while ($row = $contactsResult->fetch_assoc()) {
            $type = (string) ($row['contact_type'] ?? '');

            if (! isset($avisoContactsRaw[$type])) {
                continue;
            }

            if (
                $avisoContactsRaw[$type]['name_es'] !== ''
                || $avisoContactsRaw[$type]['name_en'] !== ''
                || $avisoContactsRaw[$type]['title_es'] !== ''
                || $avisoContactsRaw[$type]['title_en'] !== ''
            ) {
                continue;
            }

            $avisoContactsRaw[$type]['name_es'] = (string) ($row['name_es'] ?? '');
            $avisoContactsRaw[$type]['name_en'] = (string) ($row['name_en'] ?? '');
            $avisoContactsRaw[$type]['title_es'] = (string) ($row['title_es'] ?? '');
            $avisoContactsRaw[$type]['title_en'] = (string) ($row['title_en'] ?? '');
        }

        $contactsResult->free();
    }
} catch (Throwable $exception) {
    // Ignore contact loading errors to avoid breaking the reports page.
}

$avisoContactsConfig = [
    'recipient' => [
        'name' => [
            'es' => $avisoContactsRaw['recipient']['name_es'],
            'en' => $avisoContactsRaw['recipient']['name_en'],
        ],
        'title' => [
            'es' => $avisoContactsRaw['recipient']['title_es'],
            'en' => $avisoContactsRaw['recipient']['title_en'],
        ],
    ],
    'signer' => [
        'name' => [
            'es' => $avisoContactsRaw['signer']['name_es'],
            'en' => $avisoContactsRaw['signer']['name_en'],
        ],
        'title' => [
            'es' => $avisoContactsRaw['signer']['title_es'],
            'en' => $avisoContactsRaw['signer']['title_en'],
        ],
    ],
];

$avisoFooterLines = [
    'www.grupogerez.com',
    'Saturno No. 100  esq. Calle Luna Col. Anáhuac',
    'C.P. 89180 Tampico, Tam., México.',
    'Tels. (833) 214-0041, 212-5958 Ext. 103',
];
$avisoFooterText = implode("\n", $avisoFooterLines);

$greetingMessage = translate('reports.greeting', ['name' => '<strong>' . $userNameEscaped . '</strong>'], $currentLanguage);
$currentRoleMessage = translate('reports.current_role', ['role' => '<strong>' . $roleLabelEscaped . '</strong>'], $currentLanguage);

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;
$navLinks = [
    [
        'href' => 'index.php',
        'label' => translate('reports.nav.dashboard', [], $currentLanguage),
        'class' => 'btn btn-outline-primary btn-sm',
        'visible' => $canRegister,
    ],
    [
        'href' => 'client-portal.php',
        'label' => translate('dashboard.nav.client_portal', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $userRole === 'cliente',
    ],
    [
        'href' => 'analytics.php',
        'label' => translate('dashboard.nav.analytics', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => in_array($userRole, ['admin', 'usuario'], true),
    ],
    [
        'href' => 'aviso-importar.php',
        'label' => $currentLanguage === 'en' ? 'Import history' : 'Importar históricos',
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
        'label' => translate('reports.nav.manage_clients', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canRegister,
    ],
    [
        'href' => 'statuses.php',
        'label' => translate('reports.nav.manage_statuses', [], $currentLanguage),
        'class' => 'btn btn-outline-secondary btn-sm',
        'visible' => $canManageUsers,
    ],
    [
        'href' => 'users.php',
        'label' => translate('reports.nav.manage_users', [], $currentLanguage),
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
        'label' => translate('reports.nav.logout', [], $currentLanguage),
        'class' => 'btn btn-outline-danger btn-sm',
    ],
];

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars(translate('reports.page_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5 reports-container">
            <div class="row justify-content-center">
                <div class="col-12">
                    <div class="card shadow-sm form-card">
                        <div class="card-header bg-primary text-white">
                            <h1 class="h3 mb-0"><?= htmlspecialchars(translate('reports.header', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4">
                                <p class="lead mb-2 mb-lg-0"><?= $greetingMessage ?></p>
                            </div>
                            <p class="text-muted">
                                <?= htmlspecialchars(translate('reports.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <form id="report-filters" class="row g-3 align-items-end mt-4" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="col-12 d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary" id="report-filters-reset">
                                        <?= htmlspecialchars(translate('reports.filters.reset', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <?= htmlspecialchars(translate('reports.filters.apply', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </div>
                                <div class="col-md-3">
                                    <label for="fecha_inicio" class="form-label"><?= htmlspecialchars(translate('reports.filters.start_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio">
                                </div>
                                <div class="col-md-3">
                                    <label for="fecha_fin" class="form-label"><?= htmlspecialchars(translate('reports.filters.end_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="date" class="form-control" id="fecha_fin" name="fecha_fin">
                                </div>
                                <?php if ($canFilterClients): ?>
                                    <div class="col-md-3">
                                        <label for="cliente_filtro" class="form-label"><?= htmlspecialchars(translate('reports.filters.client', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="cliente_filtro" name="cliente_id">
                                            <option value="">
                                                <?= htmlspecialchars(translate('reports.filters.client_all', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                            <?php foreach ($clientFilters as $client): ?>
                                                <option value="<?= (int) $client['id'] ?>">
                                                    <?= htmlspecialchars($client['display'], ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($clientFiltersError !== ''): ?>
                                            <div class="form-text text-danger">
                                                <?= htmlspecialchars(translate('reports.filters.client_error', ['error' => $clientFiltersError], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="col-md-3">
                                    <label for="status_filtro" class="form-label"><?= htmlspecialchars(translate('reports.filters.status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <select class="form-select" id="status_filtro" name="status_id">
                                        <option value="">
                                            <?= htmlspecialchars(translate('reports.filters.status_all', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                        <?php foreach ($statusOptions as $status): ?>
                                            <option value="<?= (int) $status['id'] ?>">
                                                <?= htmlspecialchars((string) ($status['display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($statusOptionsError !== ''): ?>
                                        <div class="form-text text-danger">
                                            <?= htmlspecialchars(translate('reports.filters.status_error', ['error' => $statusOptionsError], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3">
                                    <label for="origen_filtro" class="form-label"><?= htmlspecialchars(translate('reports.filters.origin', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <select class="form-select" id="origen_filtro" name="origen">
                                        <option value=""><?= htmlspecialchars(translate('reports.filters.origin_all', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></option>
                                        <option value="system"><?= htmlspecialchars(translate('reports.filters.origin_system', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></option>
                                        <option value="historical"><?= htmlspecialchars(translate('reports.filters.origin_historical', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></option>
                                    </select>
                                </div>

                            </form>
                            <div class="d-flex flex-column flex-md-row justify-content-md-end gap-2 mt-3" id="report-export-actions">
                                <?php if (in_array($userRole, ['admin', 'usuario'], true)): ?>
                                    <button type="button" class="btn btn-outline-secondary" id="reports-export-analytics">
                                        <?= htmlspecialchars(translate('reports.export.analytics_csv', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-primary" id="reports-export-pdf">
                                    <?= htmlspecialchars(translate('reports.export.pdf', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <button type="button" class="btn btn-outline-success" id="reports-export-excel">
                                    <?= htmlspecialchars(translate('reports.export.excel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                            <div id="report-alert" class="alert d-none mt-4" role="alert"></div>
                            <div class="row g-3 mt-4" id="report-summary">
                                <div class="col-md-4">
                                    <div class="border rounded-3 p-3 bg-light">
                                        <h2 class="h6 text-uppercase text-muted mb-2"><?= htmlspecialchars(translate('reports.summary.records', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                        <p class="display-6 mb-0" data-summary="count">0</p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded-3 p-3 bg-light">
                                        <h2 class="h6 text-uppercase text-muted mb-2"><?= htmlspecialchars(translate('reports.summary.days_elapsed', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                        <p class="display-6 mb-0" data-summary="dias_transcurridos">0</p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded-3 p-3 bg-light">
                                        <h2 class="h6 text-uppercase text-muted mb-2"><?= htmlspecialchars(translate('reports.summary.days_out', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                        <p class="display-6 mb-0" data-summary="dias_fuera">0</p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5" id="report-dashboard">
                                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                                    <div>
                                        <h2 class="h5 mb-1"><?= htmlspecialchars(translate('reports.dashboard.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                        <p class="text-muted mb-0 small">
                                            <?= htmlspecialchars(translate('reports.dashboard.subtitle', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge text-bg-warning d-none" data-dashboard="sla-warning" aria-live="polite"></span>
                                        <span class="badge text-bg-danger d-none" data-dashboard="sla-breach" aria-live="polite"></span>
                                    </div>
                                </div>
                                <div class="row g-3 mt-3">
                                    <div class="col-lg-4">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.dashboard.status_heading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                <div class="vstack gap-3" data-dashboard="status-list"></div>
                                                <p class="text-muted small mb-0 d-none" data-dashboard-empty="status">
                                                    <?= htmlspecialchars(translate('reports.dashboard.status_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.dashboard.clients_heading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                <ul class="list-group list-group-flush" data-dashboard="clients-list"></ul>
                                                <p class="text-muted small mb-0 d-none" data-dashboard-empty="clients">
                                                    <?= htmlspecialchars(translate('reports.dashboard.clients_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.dashboard.period_heading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                <div class="vstack gap-3" data-dashboard="period-list"></div>
                                                <p class="text-muted small mb-0 d-none" data-dashboard-empty="periods">
                                                    <?= htmlspecialchars(translate('reports.dashboard.period_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-3 mt-3">
                                    <div class="col-lg-4">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.dashboard.risk_heading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                <ul class="list-group list-group-flush" data-dashboard="risk-list"></ul>
                                                <p class="text-muted small mb-0 d-none" data-dashboard-empty="risk">
                                                    <?= htmlspecialchars(translate('reports.dashboard.risk_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-8">
                                        <div class="card h-100">
                                            <div class="card-body d-flex flex-column">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h3 class="h6 text-muted mb-0"><?= htmlspecialchars(translate('reports.dashboard.recent_heading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                </div>
                                                <div class="table-responsive flex-grow-1">
                                                    <table class="table table-sm align-middle mb-0">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th scope="col"><?= htmlspecialchars(translate('reports.dashboard.recent_table.reference', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                                <th scope="col"><?= htmlspecialchars(translate('reports.dashboard.recent_table.change', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                                <th scope="col" class="text-end"><?= htmlspecialchars(translate('reports.dashboard.recent_table.when', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody data-dashboard="recent-body">
                                                            <tr data-dashboard-empty-row>
                                                                <td colspan="3" class="text-center text-muted small py-3" data-dashboard-empty="recent">
                                                                    <?= htmlspecialchars(translate('reports.dashboard.recent_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
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
                            <?php if (in_array($userRole, ['admin', 'usuario'], true)): ?>
                                <div class="mt-5" id="report-charts-section" hidden>
                                    <h2 class="h5 mb-4"><?= htmlspecialchars(translate('reports.charts.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                    <div class="row g-4">
                                        <div class="col-lg-6">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.charts.records_by_client', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                    <div class="ratio ratio-4x3">
                                                        <canvas id="chart-records-by-client" aria-label="<?= htmlspecialchars(translate('reports.charts.records_by_client', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                                    </div>
                                                    <p class="text-muted small mb-0 d-none" data-chart-empty="records-by-client">
                                                        <?= htmlspecialchars(translate('reports.charts.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.charts.days_distribution', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                    <div class="ratio ratio-4x3">
                                                        <canvas id="chart-days-distribution" aria-label="<?= htmlspecialchars(translate('reports.charts.days_distribution', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                                    </div>
                                                    <p class="text-muted small mb-0 d-none" data-chart-empty="days-distribution">
                                                        <?= htmlspecialchars(translate('reports.charts.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row g-4 mt-1">
                                        <div class="col-lg-6">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.charts.records_by_status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                    <div class="ratio ratio-4x3">
                                                        <canvas id="chart-records-by-status" aria-label="<?= htmlspecialchars(translate('reports.charts.records_by_status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                                    </div>
                                                    <p class="text-muted small mb-0 d-none" data-chart-empty="records-by-status">
                                                        <?= htmlspecialchars(translate('reports.charts.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.charts.records_by_vessel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                    <div class="ratio ratio-4x3">
                                                        <canvas id="chart-records-by-vessel" aria-label="<?= htmlspecialchars(translate('reports.charts.records_by_vessel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                                    </div>
                                                    <p class="text-muted small mb-0 d-none" data-chart-empty="records-by-vessel">
                                                        <?= htmlspecialchars(translate('reports.charts.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row g-4 mt-1">
                                        <div class="col-12 col-xl-6">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.charts.records_trend', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                    <div class="ratio ratio-16x9">
                                                        <canvas id="chart-records-trend" aria-label="<?= htmlspecialchars(translate('reports.charts.records_trend', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                                    </div>
                                                    <p class="text-muted small mb-0 d-none" data-chart-empty="records-trend">
                                                        <?= htmlspecialchars(translate('reports.charts.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12 col-xl-6">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.charts.average_by_status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                    <div class="ratio ratio-16x9">
                                                        <canvas id="chart-average-by-status" aria-label="<?= htmlspecialchars(translate('reports.charts.average_by_status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                                    </div>
                                                    <p class="text-muted small mb-0 d-none" data-chart-empty="average-by-status">
                                                        <?= htmlspecialchars(translate('reports.charts.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row g-4 mt-1">
                                        <div class="col-12">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h3 class="h6 text-muted mb-3"><?= htmlspecialchars(translate('reports.charts.client_performance', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                                    <div class="ratio ratio-16x9">
                                                        <canvas id="chart-client-performance" aria-label="<?= htmlspecialchars(translate('reports.charts.client_performance', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" role="img"></canvas>
                                                    </div>
                                                    <p class="text-muted small mb-0 d-none" data-chart-empty="client-performance">
                                                        <?= htmlspecialchars(translate('reports.charts.no_data', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="table-responsive mt-4">
                                <table class="table table-striped table-hover align-middle" id="report-results">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.notice_number', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.client', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.vessel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.destination', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.pedimentos', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.partidas', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.observaciones', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.attachments', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.aviso', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.landing_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.departure_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.days_elapsed', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.days_out', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <?php if ($canRegister): ?>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.actions', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td colspan="<?= $canRegister ? 17 : 16 ?>" class="text-center text-muted py-4">
                                                <?= htmlspecialchars(translate('reports.table.loading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-5 d-none" id="report-canceled-section">
                                <h2 class="h5 mb-3"><?= htmlspecialchars($canceledReportsTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover align-middle" id="report-canceled-results">
                                        <thead class="table-light">
                                            <tr>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.reference', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.client', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.vessel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.destination', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.pedimentos', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.partidas', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.observaciones', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.attachments', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.aviso', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.notice_number', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.landing_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.departure_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.days_elapsed', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.days_out', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <?php if ($canRegister): ?>
                                                    <th scope="col"><?= htmlspecialchars(translate('reports.table.headers.actions', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td colspan="<?= $canRegister ? 17 : 16 ?>" class="text-center text-muted py-4">
                                                    <?= htmlspecialchars(translate('reports.table.loading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
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
        </div>
        <?php if ($canRegister): ?>
            <div class="modal fade" id="report-edit-modal" tabindex="-1" aria-labelledby="report-edit-modal-label" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <form id="report-edit-form" autocomplete="off" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="modal-header">
                                <h2 class="modal-title fs-5" id="report-edit-modal-label"><?= htmlspecialchars(translate('reports.edit_modal.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(translate('reports.edit_modal.cancel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-muted mb-3">
                                    <?= htmlspecialchars(translate('reports.edit_modal.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <div id="report-edit-form-alert" class="alert d-none" role="alert"></div>
                                <input type="hidden" name="id" id="report-edit-id">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="report-edit-cliente-id" class="form-label"><?= htmlspecialchars(translate('dashboard.form.customer_assign_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="report-edit-cliente-id" name="cliente_id">
                                            <option value="">
                                                <?= htmlspecialchars(translate('dashboard.form.customer_assign_placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                            <?php foreach ($clientFilters as $client): ?>
                                                <option value="<?= (int) $client['id'] ?>" data-client-name="<?= htmlspecialchars((string) ($client['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-client-email="<?= htmlspecialchars((string) ($client['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars((string) ($client['display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="report-edit-cliente-info" class="form-text d-none" data-template="<?= htmlspecialchars($clientAssignSelectedTemplate, ENT_QUOTES, 'UTF-8') ?>"></div>
                                        <div class="invalid-feedback" data-feedback-for="cliente_id"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-cliente" class="form-label"><?= htmlspecialchars(translate('dashboard.form.customer', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-edit-cliente" name="cliente" maxlength="150" required>
                                        <div class="invalid-feedback" data-feedback-for="cliente"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-status-id" class="form-label"><?= htmlspecialchars(translate('dashboard.form.status', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="report-edit-status-id" name="status_id" <?= $statusOptions === [] ? 'disabled' : '' ?> required>
                                            <option value="">
                                                <?= htmlspecialchars(translate('dashboard.form.status_placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                            <?php foreach ($statusOptions as $status): ?>
                                                <option value="<?= (int) $status['id'] ?>">
                                                    <?= htmlspecialchars((string) ($status['display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback" data-feedback-for="status_id"></div>
                                        <?php if ($statusOptionsError !== ''): ?>
                                            <div class="form-text text-danger">
                                                <?= htmlspecialchars(translate('dashboard.form.status_error', ['error' => $statusOptionsError], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-referencia" class="form-label"><?= htmlspecialchars(translate('dashboard.form.reference', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-edit-referencia" name="referencia" maxlength="100" required>
                                        <div class="invalid-feedback" data-feedback-for="referencia"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-folio" class="form-label"><?= htmlspecialchars(translate('dashboard.form.notice_number', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-edit-folio" name="folio_aviso" maxlength="100">
                                        <div class="invalid-feedback" data-feedback-for="folio_aviso"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="pedimento" class="form-label"><?= htmlspecialchars(translate('dashboard.form.pedimento', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="pedimento" name="pedimento" maxlength="100">
                                        <div class="invalid-feedback" data-feedback-for="pedimento"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-cipl" class="form-label"><?= htmlspecialchars(translate('dashboard.form.cipl', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-edit-cipl" name="cipl" maxlength="100">
                                        <div class="invalid-feedback" data-feedback-for="cipl"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-manifiesto" class="form-label"><?= htmlspecialchars(translate('dashboard.form.manifiesto', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-edit-manifiesto" name="manifiesto" maxlength="100">
                                        <div class="invalid-feedback" data-feedback-for="manifiesto"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-fecha-desembarque" class="form-label"><?= htmlspecialchars(translate('dashboard.form.landing_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="date" class="form-control" id="report-edit-fecha-desembarque" name="fecha_desembarque" required>
                                        <div class="invalid-feedback" data-feedback-for="fecha_desembarque"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-fecha-embarque" class="form-label"><?= htmlspecialchars(translate('dashboard.form.departure_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="date" class="form-control" id="report-edit-fecha-embarque" name="fecha_embarque">
                                        <div class="invalid-feedback" data-feedback-for="fecha_embarque"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-destino" class="form-label"><?= htmlspecialchars(translate('dashboard.form.destination', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-edit-destino" name="destino" maxlength="150" required>
                                        <div class="invalid-feedback" data-feedback-for="destino"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="report-edit-barco" class="form-label"><?= htmlspecialchars(translate('dashboard.form.vessel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-edit-barco" name="barco" maxlength="150" required>
                                        <div class="invalid-feedback" data-feedback-for="barco"></div>
                                    </div>
                                    <div class="col-12">
                                        <label for="report-edit-descripcion" class="form-label"><?= htmlspecialchars(translate('dashboard.form.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <textarea class="form-control" id="report-edit-descripcion" name="descripcion" rows="4" maxlength="1000" required></textarea>
                                        <div class="invalid-feedback" data-feedback-for="descripcion"></div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 mb-2">
                                            <span class="form-label mb-0" id="pedimentos-label"><?= htmlspecialchars($pedimentosLabelText, ENT_QUOTES, 'UTF-8') ?></span>
                                            <div class="btn-group" role="group" aria-label="<?= htmlspecialchars($pedimentosLabelText, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="button" class="btn btn-outline-primary btn-sm dynamic-field-add" data-field-target="pedimentos-fields" data-dynamic-add-disabled="true" data-pedimentos-open-table data-pedimentos-table-url="<?= htmlspecialchars($pedimentosTableUrl, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($addPedimentoLabel, ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#pedimentosModal" aria-controls="pedimentosModal">
                                                    <?= htmlspecialchars(translate('pedimentos.modal.process_button', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="card border-secondary-subtle mb-3 d-none" data-pedimentos-header-summary data-placeholder="<?= htmlspecialchars($pedimentosHeaderPlaceholder, ENT_QUOTES, 'UTF-8') ?>" data-empty-text="<?= htmlspecialchars($pedimentosHeaderEmpty, ENT_QUOTES, 'UTF-8') ?>" aria-live="polite" aria-hidden="true">
                                            <div class="card-body py-2">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <span class="fw-semibold d-block"><?= htmlspecialchars($pedimentosHeaderTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                                        <span class="text-muted small" data-pedimentos-header-empty><?= htmlspecialchars($pedimentosHeaderEmpty, ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                    <?php if (trim($pedimentosHeaderClear) !== ''): ?>
                                                        <button type="button" class="btn btn-link btn-sm p-0" data-pedimentos-header-clear>
                                                            <?= htmlspecialchars($pedimentosHeaderClear, ENT_QUOTES, 'UTF-8') ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-flex flex-column gap-2 mt-2" data-pedimentos-header-list></div>
                                            </div>
                                        </div>
                                        <div id="pedimentos-fields" class="dynamic-field-group" data-field-name="pedimentos" data-placeholder="<?= htmlspecialchars($pedimentoPlaceholder, ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($pedimentosLabelText, ENT_QUOTES, 'UTF-8') ?>" data-remove-text="<?= htmlspecialchars($removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-remove-aria="<?= htmlspecialchars($pedimentosRemoveAria !== '' ? $pedimentosRemoveAria : $removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-max-length="100" data-label-id="pedimentos-label" data-next-index="0" data-allow-initial-empty="false"></div>
                                        <div class="form-text text-muted" id="pedimentos-bridge-feedback"></div>
                                        <input type="hidden" id="pedimentos-header-json" name="pedimentos_header_json" value="">
                                        <input type="hidden" id="pedimentos-items-json" name="pedimentos_items_json" value="">
                                        <input type="hidden" id="pedimentos-packages-json" name="pedimentos_packages_json" value="">
                                        <div class="invalid-feedback d-block" data-feedback-for="pedimentos"></div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 mb-2">
                                            <span class="form-label mb-0" id="report-edit-manifests-label"><?= htmlspecialchars($manifestsLabelText, ENT_QUOTES, 'UTF-8') ?></span>
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-reference-add="manifests">
                                                <?= htmlspecialchars($addManifestLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </div>
                                        <div id="report-edit-manifests-group" class="reference-field-group" data-reference-group="manifests" data-field-name="manifests" data-placeholder="<?= htmlspecialchars($manifestPlaceholder, ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($manifestsLabelText, ENT_QUOTES, 'UTF-8') ?>" data-remove-text="<?= htmlspecialchars($removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-remove-aria="<?= htmlspecialchars($manifestsRemoveAria !== '' ? $manifestsRemoveAria : $removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-label-id="report-edit-manifests-label" data-max-length="100" data-input-prefix="report-edit-manifests">
                                            <div class="input-group reference-field mb-2" data-index="0">
                                                <input type="text" class="form-control" id="report-edit-manifests-0" name="manifests[]" maxlength="100" placeholder="<?= htmlspecialchars($manifestPlaceholder, ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="report-edit-manifests-label">
                                                <button type="button" class="btn btn-outline-danger" data-action="remove-reference" aria-label="<?= htmlspecialchars($manifestsRemoveAria !== '' ? $manifestsRemoveAria : $removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" disabled>
                                                    <?= htmlspecialchars($removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="invalid-feedback d-block" data-feedback-for="manifests"></div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 mb-2">
                                            <span class="form-label mb-0" id="report-edit-cipls-label"><?= htmlspecialchars($ciplsLabelText, ENT_QUOTES, 'UTF-8') ?></span>
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-reference-add="cipls">
                                                <?= htmlspecialchars($addCiplLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </div>
                                        <div id="report-edit-cipls-group" class="reference-field-group" data-reference-group="cipls" data-field-name="cipls" data-placeholder="<?= htmlspecialchars($ciplPlaceholder, ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($ciplsLabelText, ENT_QUOTES, 'UTF-8') ?>" data-remove-text="<?= htmlspecialchars($removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-remove-aria="<?= htmlspecialchars($ciplsRemoveAria !== '' ? $ciplsRemoveAria : $removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-label-id="report-edit-cipls-label" data-max-length="100" data-input-prefix="report-edit-cipls">
                                            <div class="input-group reference-field mb-2" data-index="0">
                                                <input type="text" class="form-control" id="report-edit-cipls-0" name="cipls[]" maxlength="100" placeholder="<?= htmlspecialchars($ciplPlaceholder, ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="report-edit-cipls-label">
                                                <button type="button" class="btn btn-outline-danger" data-action="remove-reference" aria-label="<?= htmlspecialchars($ciplsRemoveAria !== '' ? $ciplsRemoveAria : $removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" disabled>
                                                    <?= htmlspecialchars($removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="invalid-feedback d-block" data-feedback-for="cipls"></div>
                                    </div>
                                    <div class="col-12">
                                        <label for="report-edit-attachments" class="form-label"><?= htmlspecialchars(translate('reports.attachments.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <div class="d-flex flex-column flex-lg-row align-items-lg-start gap-2">
                                            <input type="file" class="form-control" id="report-edit-attachments" name="attachments[]" multiple<?= $attachmentsAcceptAttribute ?>>
                                            <button type="button" class="btn btn-outline-primary" id="report-edit-attachments-upload">
                                                <?= htmlspecialchars(translate('reports.attachments.upload_button', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            <?= htmlspecialchars(translate('reports.attachments.upload_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <?php if ($allowedExtensionsDisplay !== ''): ?>
                                            <div class="form-text">
                                                <?= htmlspecialchars(translate('reports.attachments.upload_help_types', ['types' => $allowedExtensionsDisplay], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($maxAttachmentSizeFormatted !== ''): ?>
                                            <div class="form-text">
                                                <?= htmlspecialchars(translate('reports.attachments.upload_help_max', ['max' => $maxAttachmentSizeFormatted], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($maxAttachmentsPerRequest > 0): ?>
                                            <div class="form-text">
                                                <?= htmlspecialchars(translate('desembarques.files.error_too_many', ['max' => $maxAttachmentsPerRequest], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="form-text text-danger d-none" id="report-edit-attachments-feedback"></div>
                                        <ul class="list-group list-group-flush mt-3" id="report-edit-attachments-list" aria-live="polite" aria-relevant="additions removals">
                                            <li class="list-group-item text-muted" id="report-edit-attachments-empty">
                                                <?= htmlspecialchars(translate('reports.attachments.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <?= htmlspecialchars(translate('reports.edit_modal.cancel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <button type="submit" class="btn btn-primary" id="report-edit-save">
                                    <?= htmlspecialchars(translate('reports.edit_modal.save', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <div class="spinner-border spinner-border-sm text-primary ms-2 d-none" id="report-edit-spinner" role="status">
                                    <span class="visually-hidden"><?= htmlspecialchars(translate('reports.table.loading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="modal fade" id="report-aviso-modal" tabindex="-1" aria-labelledby="report-aviso-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <form id="report-aviso-form" autocomplete="off">
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="report-aviso-modal-label"><?= htmlspecialchars(translate('reports.aviso_modal.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(translate('reports.aviso_modal.cancel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted mb-3">
                                <?= htmlspecialchars(translate('reports.aviso_modal.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <?php if ($currentLanguage === 'en'): ?>
                                <div class="alert alert-info small" role="note">The application interface is in English. The official Mexican filing retains its authorized Spanish wording in the generated PDF.</div>
                            <?php endif; ?>
                            <div id="report-aviso-alert" class="alert alert-warning d-none" role="alert"></div>
                            <input type="hidden" id="report-aviso-record-id" name="record_id">

                            <div class="border rounded-3 p-3 mb-3 bg-light" id="report-aviso-summary">
                                <div class="row g-2">
                                    <div class="col-lg-6"><p class="small mb-1" data-summary="reference"></p></div>
                                    <div class="col-lg-6"><p class="small mb-1" data-summary="vessel"></p></div>
                                    <div class="col-lg-6"><p class="small mb-1" data-summary="destination"></p></div>
                                    <div class="col-lg-6"><p class="small mb-0" data-summary="dates"></p></div>
                                </div>
                            </div>

                            <section class="border rounded-3 p-3 mb-3" aria-labelledby="report-aviso-source-title">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <h3 class="h6 mb-0" id="report-aviso-source-title"><?= htmlspecialchars($currentLanguage === 'en' ? 'Unloading source data' : 'Datos fuente del desembarque', ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="badge text-bg-secondary" id="report-aviso-excel-status"><?= htmlspecialchars($currentLanguage === 'en' ? 'Checking' : 'Verificando', ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="small text-muted" id="report-aviso-excel-meta"><?= htmlspecialchars($currentLanguage === 'en' ? 'Loading the structured data saved at registration.' : 'Cargando los datos estructurados guardados desde el alta.', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="small text-muted">
                                        <?= htmlspecialchars($currentLanguage === 'en' ? 'The Excel is not requested again when the record already has structured data.' : 'El Excel no se vuelve a solicitar cuando el registro ya tiene datos estructurados.', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <div class="row g-2 mt-2 d-none" id="report-aviso-excel-preview" aria-live="polite">
                                    <div class="col-6 col-xl-3"><div class="small"><strong><?= htmlspecialchars($currentLanguage === 'en' ? 'Manifest:' : 'Manifiesto:', ENT_QUOTES, 'UTF-8') ?></strong> <span data-aviso-preview="manifest"></span></div></div>
                                    <div class="col-6 col-xl-3"><div class="small"><strong><?= htmlspecialchars($currentLanguage === 'en' ? 'Transport:' : 'Transporte:', ENT_QUOTES, 'UTF-8') ?></strong> <span data-aviso-preview="transport"></span></div></div>
                                    <div class="col-6 col-xl-3"><div class="small"><strong>IMO:</strong> <span data-aviso-preview="imo"></span></div></div>
                                    <div class="col-6 col-xl-3"><div class="small"><strong><?= htmlspecialchars($currentLanguage === 'en' ? 'Merchandise lines:' : 'Mercancías:', ENT_QUOTES, 'UTF-8') ?></strong> <span data-aviso-preview="items"></span></div></div>
                                </div>
                            </section>

                            <section class="border rounded-3 p-3 mb-3" aria-labelledby="report-aviso-profile-title">
                                <div class="row g-3 align-items-end">
                                    <div class="col-lg-7">
                                        <label for="report-aviso-profile" class="form-label fw-semibold" id="report-aviso-profile-title"><?= htmlspecialchars($currentLanguage === 'en' ? 'Rig / project profile' : 'Perfil Rig / Proyecto', ENT_QUOTES, 'UTF-8') ?></label>
                                        <select class="form-select" id="report-aviso-profile">
                                            <option value=""><?= htmlspecialchars($currentLanguage === 'en' ? 'Select a profile' : 'Selecciona un perfil', ENT_QUOTES, 'UTF-8') ?></option>
                                        </select>
                                        <div class="form-text"><?= htmlspecialchars($currentLanguage === 'en' ? 'Profiles keep the Rig, IMO, field, area, principal and legal contacts separate from the transport vessel.' : 'Los perfiles mantienen Rig, IMO, campo, área, comitente y contactos legales separados de la embarcación de transporte.', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-lg-5 d-flex gap-2">
                                        <?php if ($canRegister): ?>
                                            <button type="button" class="btn btn-outline-primary flex-grow-1" id="report-aviso-save-profile">
                                                <?= htmlspecialchars($currentLanguage === 'en' ? 'Save current as profile' : 'Guardar actual como perfil', ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="row g-3 mt-1">
                                    <div class="col-md-6 col-xl-3">
                                        <label for="report-aviso-rig-name" class="form-label"><?= htmlspecialchars($currentLanguage === 'en' ? 'Rig / offshore vessel' : 'Rig / buque de perforación', ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-aviso-rig-name" maxlength="180">
                                    </div>
                                    <div class="col-md-6 col-xl-3">
                                        <label for="report-aviso-rig-imo" class="form-label"><?= htmlspecialchars($currentLanguage === 'en' ? 'Rig IMO' : 'IMO del rig', ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-aviso-rig-imo" maxlength="40">
                                    </div>
                                    <div class="col-md-6 col-xl-3">
                                        <label for="report-aviso-rig-field" class="form-label"><?= htmlspecialchars($currentLanguage === 'en' ? 'Field' : 'Campo', ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-aviso-rig-field" maxlength="120">
                                    </div>
                                    <div class="col-md-6 col-xl-3">
                                        <label for="report-aviso-rig-area" class="form-label"><?= htmlspecialchars($currentLanguage === 'en' ? 'Area on rig' : 'Área en el rig', ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-aviso-rig-area" maxlength="120" placeholder="<?= htmlspecialchars($currentLanguage === 'en' ? 'DECK' : 'CUBIERTA', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label for="report-aviso-comitente" class="form-label"><?= htmlspecialchars($currentLanguage === 'en' ? 'Principal represented in the notice' : 'Comitente representado en el aviso', ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-aviso-comitente" maxlength="255">
                                    </div>
                                </div>
                            </section>

                            <section class="border rounded-3 p-3 mb-3" aria-labelledby="report-aviso-document-basic-title">
                                <h3 class="h6 mb-3" id="report-aviso-document-basic-title"><?= htmlspecialchars($currentLanguage === 'en' ? 'Notice identification' : 'Identificación del aviso', ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="report-aviso-document-code" class="form-label"><?= htmlspecialchars($currentLanguage === 'en' ? 'Document code' : 'Código del oficio', ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-aviso-document-code" name="document_code" maxlength="100" placeholder="MADE-060-26">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="report-aviso-notice-number" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.notice_number', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-aviso-notice-number" name="notice_number" maxlength="100">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="report-aviso-location-date" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.location_date', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="report-aviso-location-date" name="location_date" maxlength="200">
                                    </div>
                                </div>
                            </section>

                            <section class="border rounded-3 p-3 mb-3" aria-labelledby="report-aviso-versions-title">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 align-items-lg-center mb-2">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <h3 class="h6 mb-0" id="report-aviso-versions-title"><?= htmlspecialchars($currentLanguage === 'en' ? 'Issued PDF history' : 'Historial de emisiones PDF', ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="badge text-bg-secondary" id="report-aviso-versions-count">0</span>
                                        </div>
                                        <div class="small text-muted"><?= htmlspecialchars($currentLanguage === 'en' ? 'Every generated PDF is archived as an immutable version with its SHA-256 fingerprint and the exact data snapshot used for that issue.' : 'Cada PDF generado se archiva como una versión inmutable con su huella SHA-256 y el snapshot exacto de los datos utilizados en esa emisión.', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <span class="badge text-bg-light border text-secondary" id="report-aviso-versions-status"><?= htmlspecialchars($currentLanguage === 'en' ? 'Loading' : 'Cargando', ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="alert alert-light border small mb-0" id="report-aviso-versions-empty">
                                    <?= htmlspecialchars($currentLanguage === 'en' ? 'No PDF versions have been issued for this unloading record yet.' : 'Todavía no se han emitido versiones PDF para este desembarque.', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="list-group d-none" id="report-aviso-versions-list" aria-live="polite"></div>
                            </section>

                            <section class="border rounded-3 p-3 mb-3" aria-labelledby="report-aviso-photos-title">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 align-items-lg-center mb-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <h3 class="h6 mb-0" id="report-aviso-photos-title"><?= htmlspecialchars($currentLanguage === 'en' ? 'Photo annex' : 'Anexo fotográfico', ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="badge text-bg-secondary" id="report-aviso-photos-count">0</span>
                                        </div>
                                        <div class="small text-muted"><?= htmlspecialchars($currentLanguage === 'en' ? 'Select the images that will appear in the MANIFEST / GOODS / IMAGES annex and optionally link each photo to a merchandise line.' : 'Selecciona las imágenes que aparecerán en el anexo MANIFIESTO / MERCANCIAS / IMAGENES y, si corresponde, relaciónalas con una mercancía.', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>

                                <?php if ($canRegister): ?>
                                    <div class="row g-2 align-items-end mb-3">
                                        <div class="col-lg-9">
                                            <label for="report-aviso-photo-files" class="form-label small fw-semibold mb-1"><?= htmlspecialchars($currentLanguage === 'en' ? 'Upload photographs' : 'Subir fotografías', ENT_QUOTES, 'UTF-8') ?></label>
                                            <input type="file" class="form-control" id="report-aviso-photo-files" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif" multiple>
                                            <div class="form-text"><?= htmlspecialchars($currentLanguage === 'en' ? 'JPG, PNG, or GIF. Uploaded files remain part of the unloading record even if you later remove them from the annex.' : 'JPG, PNG o GIF. Los archivos cargados permanecen en el expediente aunque después los retires del anexo.', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-lg-3 d-grid">
                                            <button type="button" class="btn btn-outline-primary" id="report-aviso-photo-upload"><?= htmlspecialchars($currentLanguage === 'en' ? 'Upload and add' : 'Subir y agregar', ENT_QUOTES, 'UTF-8') ?></button>
                                        </div>
                                    </div>
                                    <div class="small text-danger d-none mb-3" id="report-aviso-photo-feedback" role="alert"></div>
                                <?php endif; ?>

                                <div id="report-aviso-photos-empty" class="alert alert-light border small mb-3">
                                    <?= htmlspecialchars($currentLanguage === 'en' ? 'No photographs have been added to the notice yet. The PDF can still be generated without a photo annex.' : 'Todavía no hay fotografías agregadas al aviso. El PDF puede generarse sin anexo fotográfico.', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="row g-3 mb-3 d-none" id="report-aviso-photos-selected" aria-live="polite"></div>

                                <div class="d-none" id="report-aviso-photos-available-wrap">
                                    <div class="small fw-semibold mb-2"><?= htmlspecialchars($currentLanguage === 'en' ? 'Images already attached to this unloading record' : 'Imágenes ya adjuntas a este desembarque', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="row g-2" id="report-aviso-photos-available"></div>
                                </div>
                            </section>

                            <div class="alert alert-light border mb-3" id="report-aviso-readiness" role="status" aria-live="polite">
                                <div class="fw-semibold mb-1"><?= htmlspecialchars($currentLanguage === 'en' ? 'Pre-generation validation' : 'Validación para generar', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small" data-aviso-readiness-text><?= htmlspecialchars($currentLanguage === 'en' ? 'Checking structured data, importer and Rig profile.' : 'Verificando datos estructurados, importadores y perfil del Rig.', ENT_QUOTES, 'UTF-8') ?></div>
                            </div>

                            <div class="accordion" id="report-aviso-advanced-accordion">
                                <div class="accordion-item">
                                    <h3 class="accordion-header" id="report-aviso-advanced-heading">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#report-aviso-advanced" aria-expanded="false" aria-controls="report-aviso-advanced">
                                            <?= htmlspecialchars($currentLanguage === 'en' ? 'Advanced options / legacy migration' : 'Opciones avanzadas / migración legacy', ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </h3>
                                    <div id="report-aviso-advanced" class="accordion-collapse collapse" aria-labelledby="report-aviso-advanced-heading" data-bs-parent="#report-aviso-advanced-accordion">
                                        <div class="accordion-body">
                                            <section class="mb-4" aria-labelledby="report-aviso-excel-title">
                                                <h4 class="h6" id="report-aviso-excel-title"><?= htmlspecialchars($currentLanguage === 'en' ? 'Replace Excel / migrate legacy record' : 'Reemplazar Excel / migrar registro legacy', ENT_QUOTES, 'UTF-8') ?></h4>
                                                <p class="small text-muted"><?= htmlspecialchars($currentLanguage === 'en' ? 'Only use this field when the record does not yet have structured Excel data or when the source must be intentionally replaced.' : 'Usa este campo sólo cuando el registro aún no tenga datos estructurados del Excel o cuando debas reemplazar deliberadamente la fuente.', ENT_QUOTES, 'UTF-8') ?></p>
                                                <input type="file" class="form-control" id="report-aviso-excel" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">
                                            </section>

                                            <section class="mb-4 d-none" id="report-aviso-importers-wrap">
                                                <h4 class="h6 mb-2"><?= htmlspecialchars($currentLanguage === 'en' ? 'Importer by customs entry' : 'Importador por pedimento', ENT_QUOTES, 'UTF-8') ?></h4>
                                                <p class="small text-muted mb-2"><?= htmlspecialchars($currentLanguage === 'en' ? 'Enter only missing legal business names.' : 'Completa únicamente las razones sociales faltantes.', ENT_QUOTES, 'UTF-8') ?></p>
                                                <div class="row g-2" id="report-aviso-importers"></div>
                                            </section>

                                            <div class="row g-3">
                                                <div class="col-12">
                                                    <label for="report-aviso-document-title" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.document_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-document-title" name="document_title" rows="4" maxlength="4000"></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label for="report-aviso-recipient" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.recipient', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-recipient" name="recipient" rows="3"></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label for="report-aviso-introduction" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.introduction', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-introduction" name="introduction" rows="6"></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label for="report-aviso-body" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.body', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-body" name="body" rows="3"></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label for="report-aviso-operations" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.operations', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-operations" name="operations" rows="3"></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label for="report-aviso-items" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.items', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-items" name="items" rows="7" readonly></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label for="report-aviso-documentation" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.documentation', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-documentation" name="documentation" rows="4"></textarea>
                                                    <div class="form-text"><?= htmlspecialchars($currentLanguage === 'en' ? 'Leave blank to build the manifest and customs-entry list automatically.' : 'Déjalo vacío para construir automáticamente el manifiesto y la relación de pedimentos.', ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-12">
                                                    <label for="report-aviso-closing" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.closing', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-closing" name="closing" rows="3"></textarea>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="report-aviso-signer-name" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.signer_name', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <input type="text" class="form-control" id="report-aviso-signer-name" name="signer_name" maxlength="200">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="report-aviso-signer-title" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.signer_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <input type="text" class="form-control" id="report-aviso-signer-title" name="signer_title" maxlength="200">
                                                </div>
                                                <div class="col-12 d-none">
                                                    <label for="report-aviso-footer" class="form-label"><?= htmlspecialchars(translate('reports.aviso_modal.footer', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                                    <textarea class="form-control" id="report-aviso-footer" name="footer" rows="3"></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <?= htmlspecialchars(translate('reports.aviso_modal.cancel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <button type="submit" class="btn btn-primary" id="report-aviso-generate">
                                <?= htmlspecialchars(translate('reports.aviso_modal.generate', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal fade" id="report-observaciones-modal" tabindex="-1" aria-labelledby="report-observaciones-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form id="report-observaciones-form" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="report-observaciones-modal-label"><?= htmlspecialchars(translate('reports.observations_modal.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(translate('reports.observations_modal.cancel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted mb-3">
                                <?= htmlspecialchars(translate('reports.observations_modal.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <?php if (! $canAddObservaciones): ?>
                                <div class="alert alert-info" role="alert">
                                    <?= htmlspecialchars(translate('reports.observations_modal.read_only_notice', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <div id="report-observaciones-alert" class="alert d-none" role="alert"></div>
                            <input type="hidden" name="id" id="report-observaciones-id">
                            <input type="hidden" name="observation_id" id="report-observaciones-observation-id">
                            <div class="mb-4">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h3 class="h6 mb-0"><?= htmlspecialchars(translate('reports.observations_modal.history_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h3>
                                    <div class="spinner-border spinner-border-sm text-secondary d-none" id="report-observaciones-loading" role="status">
                                        <span class="visually-hidden"><?= htmlspecialchars(translate('reports.observations_modal.loading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                                <div id="report-observaciones-list" class="list-group list-group-flush border rounded-3" aria-live="polite" aria-busy="false">
                                    <div class="list-group-item text-center text-muted py-3" id="report-observaciones-empty">
                                        <?= htmlspecialchars(translate('reports.observations_modal.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($canAddObservaciones): ?>
                                <div class="mb-3">
                                    <label for="report-observaciones-text" class="form-label"><?= htmlspecialchars(translate('reports.observations_modal.label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                    <textarea class="form-control" id="report-observaciones-text" name="observaciones" rows="4" maxlength="2000" placeholder="<?= htmlspecialchars(translate('reports.observations_modal.placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"></textarea>
                                    <div class="invalid-feedback" data-feedback-for="observaciones"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <?= htmlspecialchars(translate('reports.observations_modal.cancel', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <?php if ($canAddObservaciones): ?>
                                <button type="submit" class="btn btn-primary" id="report-observaciones-save">
                                    <?= htmlspecialchars(translate('reports.observations_modal.save', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <div class="spinner-border spinner-border-sm text-primary ms-2 d-none" id="report-observaciones-spinner" role="status">
                                    <span class="visually-hidden"><?= htmlspecialchars(translate('reports.observations_modal.loading', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal fade" id="report-attachments-modal" tabindex="-1" aria-labelledby="report-attachments-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="report-attachments-modal-label">
                            <?= htmlspecialchars(translate('reports.attachments.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(translate('reports.modal.close', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="list-group list-group-flush d-none" id="report-attachments-list" aria-live="polite" aria-busy="false"></ul>
                        <p class="text-muted text-center mb-0 py-3" id="report-attachments-empty">
                            <?= htmlspecialchars(translate('reports.attachments.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <?= htmlspecialchars(translate('reports.modal.close', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="pedimentosModal" tabindex="-1" aria-labelledby="pedimentosModalLabel" aria-hidden="true" data-parse-url="<?= htmlspecialchars($pedimentosParseUrl, ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="pedimentosModalLabel"><?= htmlspecialchars(translate('pedimentos.modal.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(translate('common.close', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <form id="pedimentos-modal-form" data-pedimentos-form enctype="multipart/form-data" autocomplete="off" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="mb-3">
                                        <label for="pedimentos-modal-file" class="form-label"><?= htmlspecialchars(translate('pedimentos.modal.upload_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="file" class="form-control" id="pedimentos-modal-file" name="pdf" accept="application/pdf" data-pedimentos-file required>
                                        <div class="form-text">
                                            <?= htmlspecialchars(translate('pedimentos.modal.upload_help', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary" form="pedimentos-modal-form" data-pedimentos-process>
                                            <?= htmlspecialchars(translate('pedimentos.modal.process_button', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </div>
                                </form>
                                <div class="mt-3" data-pedimentos-feedback></div>
                                <div class="border rounded p-3 mt-4">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h2 class="h6 mb-1">
                                                <?= htmlspecialchars(translate('pedimentos.modal.secs_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                <span class="badge bg-secondary" data-pedimentos-sec-count>0</span>
                                            </h2>
                                            <p class="text-muted small mb-0">
                                                <?= htmlspecialchars(translate('pedimentos.modal.secs_hint', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <label for="pedimentos-modal-search" class="form-label small mb-1"><?= htmlspecialchars(translate('pedimentos.modal.search_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="search" class="form-control form-control-sm" id="pedimentos-modal-search" placeholder="<?= htmlspecialchars(translate('pedimentos.modal.search_placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>" data-pedimentos-sec-search>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-pedimentos-select-all><?= htmlspecialchars(translate('pedimentos.modal.select_all', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-pedimentos-select-none><?= htmlspecialchars(translate('pedimentos.modal.select_none', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-pedimentos-select-invert><?= htmlspecialchars(translate('pedimentos.modal.select_invert', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                    </div>
                                    <div class="small text-muted mb-2" data-pedimentos-selected-summary><?= htmlspecialchars(translate('pedimentos.modal.selected_summary_none', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="border rounded p-2 bg-light-subtle" style="max-height: 16rem; overflow: auto;" data-pedimentos-sec-list></div>
                                    <div class="d-grid gap-2 mt-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" data-pedimentos-filter-server disabled>
                                            <?= htmlspecialchars(translate('pedimentos.modal.filter_server_button', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-8">
                                <div class="card border-secondary-subtle mb-3 d-none" data-pedimentos-header>
                                    <div class="card-body py-3">
                                        <h2 class="h6 mb-2"><?= htmlspecialchars(translate('pedimentos.modal.header.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                        <div class="row gy-2 small">
                                            <div class="col-sm-6 col-xl-4">
                                                <span class="d-block text-muted"><?= htmlspecialchars(translate('pedimentos.modal.header.num', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>:</span>
                                                <span class="fw-semibold" data-pedimentos-header-num><?= htmlspecialchars(translate('pedimentos.modal.header.placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="col-sm-6 col-xl-4">
                                                <span class="d-block text-muted"><?= htmlspecialchars(translate('pedimentos.modal.header.cve', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>:</span>
                                                <span class="fw-semibold" data-pedimentos-header-cve><?= htmlspecialchars(translate('pedimentos.modal.header.placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="col-12">
                                                <span class="d-block text-muted"><?= htmlspecialchars(translate('pedimentos.modal.header.razon', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>:</span>
                                                <span class="fw-semibold" data-pedimentos-header-razon><?= htmlspecialchars(translate('pedimentos.modal.header.placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="col-sm-6 col-xl-4">
                                                <span class="d-block text-muted"><?= htmlspecialchars(translate('pedimentos.modal.header.fecha_entrada', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>:</span>
                                                <span class="fw-semibold" data-pedimentos-header-fecha-entrada><?= htmlspecialchars(translate('pedimentos.modal.header.placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="col-sm-6 col-xl-4">
                                                <span class="d-block text-muted"><?= htmlspecialchars(translate('pedimentos.modal.header.fecha_pago', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>:</span>
                                                <span class="fw-semibold" data-pedimentos-header-fecha-pago><?= htmlspecialchars(translate('pedimentos.modal.header.placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h2 class="h6 mb-0"><?= htmlspecialchars(translate('pedimentos.modal.results_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></h2>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-secondary" data-pedimentos-results-count>0</span>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-pedimentos-export disabled>
                                            <?= htmlspecialchars(translate('pedimentos.modal.export_json', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </div>
                                </div>
                                <div class="table-responsive" style="max-height: 24rem;">
                                    <table class="table table-sm table-striped align-middle mb-0" id="partidasTable">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?= htmlspecialchars(translate('pedimentos.modal.table.headers.sec', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('pedimentos.modal.table.headers.description', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('pedimentos.modal.table.headers.fraction', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('pedimentos.modal.table.headers.umc_initial', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                                <th scope="col"><?= htmlspecialchars(translate('pedimentos.modal.table.headers.umc_quantity', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody data-pedimentos-table-body>
                                            <tr>
                                                <td colspan="5" class="text-muted text-center">
                                                    <?= htmlspecialchars(translate('pedimentos.modal.results_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <details class="mt-3">
                                    <summary><?= htmlspecialchars(translate('pedimentos.modal.debug_title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></summary>
                                    <pre class="small text-muted mb-0" data-pedimentos-debug><?= htmlspecialchars(translate('pedimentos.modal.debug_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></pre>
                                </details>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <?= htmlspecialchars(translate('common.close', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button" class="btn btn-primary" data-pedimentos-confirm disabled>
                            <?= htmlspecialchars(translate('pedimentos.modal.confirm_button_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="pedimento-details-modal" tabindex="-1" aria-labelledby="pedimento-details-modal-label" aria-hidden="true"
            data-pedimentos-title="<?= htmlspecialchars(translate('reports.modal.pedimentos.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"
            data-pedimentos-empty="<?= htmlspecialchars(translate('reports.modal.pedimentos.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"
            data-partidas-title="<?= htmlspecialchars(translate('reports.modal.partidas.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"
            data-partidas-empty="<?= htmlspecialchars(translate('reports.modal.partidas.empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="pedimento-details-modal-label">
                            <?= htmlspecialchars(translate('reports.modal.pedimentos.title', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(translate('reports.modal.close', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-0 d-none" id="pedimento-details-empty" aria-live="polite"></p>
                        <ul class="list-group d-none" id="pedimento-details-list"></ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <?= htmlspecialchars(translate('reports.modal.close', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/partials/footer.php'; ?>
        <script>
            window.ReportThemeConfig = {
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
            window.AppConfig = Object.assign({}, window.ReportThemeConfig, {
                language: <?= json_encode($currentLanguage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                translations: <?= json_encode($reportsTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                fileUpload: <?= json_encode($fileUploadClientConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            });
            window.ReportConfig = {
                language: <?= json_encode($currentLanguage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                role: <?= json_encode($userRole, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                userId: <?= json_encode($userId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                canEdit: <?= json_encode($canRegister, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                canAddObservaciones: <?= json_encode($canAddObservaciones, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                fileUpload: <?= json_encode($fileUploadClientConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                translations: <?= json_encode($reportsTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                avisoContacts: <?= json_encode($avisoContactsConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                avisoFooter: <?= json_encode($avisoFooterText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                avisoTemplateUrl: <?= json_encode('assets/pdf/aviso-desembarque-plantilla.pdf', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                avisoLoadUrl: <?= json_encode('../api/desembarques/aviso/load.php', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                avisoSaveUrl: <?= json_encode('../api/desembarques/aviso/save.php', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                avisoProfilesUrl: <?= json_encode('../api/desembarques/aviso/profiles.php', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                avisoImageUrl: <?= json_encode('../api/desembarques/aviso/image.php', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                avisoVersionsUrl: <?= json_encode('../api/desembarques/aviso/versions.php', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
        </script>
        <?php
            $includeSweetAlert = true;
            $pageScripts = [
                [
                    'src' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
                    'integrity' => 'sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'src' => 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
                    'integrity' => 'sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'src' => 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js',
                    'integrity' => 'sha384-vuyrTV5nkscLp1knFvt+FIHfKKzmROBq5reruhMRslauj54mW+l2B8b6szMN6lCL',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'src' => 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
                    'integrity' => 'sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw',
                    'crossorigin' => 'anonymous',
                ],
                [
                    'src' => 'https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js',
                ],
                'assets/js/aviso-pdf.js',
                'assets/js/theme.js',
                'assets/js/form.js',
                'assets/js/pedimentos-modal.js',
                'assets/js/pedimentos-bridge.js',
                'assets/js/reports.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
