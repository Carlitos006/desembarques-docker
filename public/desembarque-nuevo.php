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
$userName = (string) ($user['name'] ?? 'Usuario');
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

$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$canManageUsers = $userRole === 'admin';
$canRegister = in_array($userRole, ['admin', 'usuario'], true);
$canViewReports = in_array($userRole, ['admin', 'usuario', 'cliente'], true);

$roleLabelEscaped = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
$roleLabelStrong = '<strong>' . $roleLabelEscaped . '</strong>';
$userNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
$greetingMessage = translate('dashboard.greeting', ['name' => '<strong>' . $userNameEscaped . '</strong>'], $currentLanguage);
$currentRoleMessage = translate('dashboard.current_role', ['role' => $roleLabelStrong], $currentLanguage);

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;
$navLinks = [
    [
        'href' => 'dashboard-avisos.php',
        'label' => $currentLanguage === 'en' ? 'Executive dashboard' : 'Dashboard ejecutivo',
        'class' => 'btn btn-outline-primary btn-sm',
        'visible' => in_array($userRole, ['admin', 'usuario'], true),
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
        'class' => 'btn btn-outline-primary btn-sm',
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

$mobileModeToggleConfig = [
    'label' => translate('dashboard.mobile_mode.toggle_label', [], $currentLanguage),
    'on_text' => translate('dashboard.mobile_mode.toggle_on', [], $currentLanguage),
    'off_text' => translate('dashboard.mobile_mode.toggle_off', [], $currentLanguage),
];

$mobileModeBannerTitle = translate('dashboard.mobile_mode.banner_title', [], $currentLanguage);
$mobileModeBannerDescription = translate('dashboard.mobile_mode.banner_description', [], $currentLanguage);
$mobileModeBadgeActive = translate('dashboard.mobile_mode.badge_active', [], $currentLanguage);
$mobileModeBadgeInactive = translate('dashboard.mobile_mode.badge_inactive', [], $currentLanguage);
$mobileModeQuickActions = translate('dashboard.mobile_mode.quick_actions', [], $currentLanguage);
$mobileModePhotoButton = translate('dashboard.mobile_mode.capture_photo', [], $currentLanguage);
$mobileModePhotoHelp = translate('dashboard.mobile_mode.capture_photo_help', [], $currentLanguage);
$mobileModeAnnotationButton = translate('dashboard.mobile_mode.annotate_photo', [], $currentLanguage);
$mobileModeAnnotationHelp = translate('dashboard.mobile_mode.annotate_photo_help', [], $currentLanguage);
$mobileModeBarcodeButton = translate('dashboard.mobile_mode.barcode_scan', [], $currentLanguage);
$mobileModeBarcodeHelp = translate('dashboard.mobile_mode.barcode_help', [], $currentLanguage);
$mobileModeSignatureToggle = translate('dashboard.mobile_mode.signature_toggle', [], $currentLanguage);
$mobileModeSignatureClear = translate('dashboard.mobile_mode.signature_clear', [], $currentLanguage);
$mobileModeSignatureSave = translate('dashboard.mobile_mode.signature_save', [], $currentLanguage);
$mobileModeSignatureInstructions = translate('dashboard.mobile_mode.signature_instructions', [], $currentLanguage);
$mobileModeAnnotationTitle = translate('dashboard.mobile_mode.annotation_title', [], $currentLanguage);
$mobileModeAnnotationSelect = translate('dashboard.mobile_mode.annotation_select', [], $currentLanguage);
$mobileModeAnnotationNone = translate('dashboard.mobile_mode.annotation_none', [], $currentLanguage);
$mobileModeAnnotationDrawHint = translate('dashboard.mobile_mode.annotation_draw_hint', [], $currentLanguage);
$mobileModeAnnotationColorLabel = translate('dashboard.mobile_mode.annotation_color_label', [], $currentLanguage);
$mobileModeAnnotationClear = translate('dashboard.mobile_mode.annotation_clear', [], $currentLanguage);
$mobileModeAnnotationSave = translate('dashboard.mobile_mode.annotation_save', [], $currentLanguage);
$mobileModeBarcodeTitle = translate('dashboard.mobile_mode.barcode_title', [], $currentLanguage);
$mobileModeBarcodeClose = translate('dashboard.mobile_mode.barcode_close', [], $currentLanguage);
$mobileModeBarcodeUnsupported = translate('dashboard.mobile_mode.barcode_unsupported', [], $currentLanguage);
$mobileModeBarcodeScanning = translate('dashboard.mobile_mode.barcode_scanning', [], $currentLanguage);
$mobileModeToolsHint = translate('dashboard.mobile_mode.tools_hint', [], $currentLanguage);

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
$attachmentsAcceptAttribute = $attachmentsAccept !== ''
    ? ' accept="' . htmlspecialchars($attachmentsAccept, ENT_QUOTES, 'UTF-8') . '"'
    : '';
$fileUploadClientConfig = [
    'maxSize' => $maxAttachmentSize,
    'maxFilesPerRequest' => $maxAttachmentsPerRequest,
    'allowedExtensions' => $allowedExtensions,
    'allowedMimeTypes' => $fileStorageConfig['allowed_mime_types'] ?? [],
    'accept' => $attachmentsAccept,
];

$formTranslations = getClientTranslations([
    'desembarques.alert.success_title',
    'desembarques.alert.success_message',
    'common.validation_title',
    'desembarques.alert.validation_message',
    'desembarques.alert.error_generic',
    'desembarques.offline.queue_saved_title',
    'desembarques.offline.queue_saved_message',
    'desembarques.offline.sync_success',
    'desembarques.offline.sync_error',
    'desembarques.offline.session_expired',
    'common.session_expired',
    'desembarques.permission_denied',
    'common.warning_title',
    'common.error_title',
    'common.csrf_token_invalid',
    'dashboard.form.customer_assign_placeholder',
    'desembarques.files.error_too_many',
    'desembarques.files.error_missing',
    'desembarques.files.validation_error',
    'dashboard.form.attachments_empty',
    'dashboard.form.attachments_remove',
    'dashboard.form.attachments_error_type',
    'dashboard.form.attachments_error_size',
    'dashboard.mobile_mode.signature_saved',
    'dashboard.mobile_mode.photo_added',
    'dashboard.mobile_mode.signature_empty',
    'dashboard.mobile_mode.annotation_saved',
    'dashboard.mobile_mode.annotation_empty',
    'dashboard.mobile_mode.barcode_unsupported',
    'dashboard.mobile_mode.barcode_scanning',
    'dashboard.mobile_mode.barcode_success',
    'dashboard.mobile_mode.barcode_error',
    'dashboard.mobile_mode.badge_active',
    'dashboard.mobile_mode.badge_inactive',
    'dashboard.mobile_mode.toggle_on',
    'dashboard.mobile_mode.toggle_off',
    'dashboard.form.remove_field',
    'dashboard.form.add_pedimento',
    'dashboard.form.add_manifest',
    'dashboard.form.add_cipl',
    'dashboard.form.pedimentos',
    'dashboard.form.manifests',
    'dashboard.form.cipls',
    'dashboard.form.pedimento_placeholder',
    'dashboard.form.manifest_placeholder',
    'dashboard.form.cipl_placeholder',
    'pedimentos.modal.process_button',
    'pedimentos.modal.processing',
    'pedimentos.modal.feedback.no_file',
    'pedimentos.modal.feedback.server_error',
    'pedimentos.modal.feedback.parse_error',
    'pedimentos.modal.feedback.network_error',
    'pedimentos.modal.selected_summary_none',
    'pedimentos.modal.selected_summary_some',
    'pedimentos.modal.secs_empty',
    'pedimentos.modal.results_count',
    'pedimentos.modal.results_empty',
    'pedimentos.modal.confirm_button_empty',
    'pedimentos.modal.confirm_button_single',
    'pedimentos.modal.confirm_button',
    'pedimentos.modal.confirm_no_selection',
    'pedimentos.modal.confirm_error',
    'pedimentos.modal.sec_label',
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
], $currentLanguage);

$csrfToken = csrf_token();

$clientOptions = [];
$clientOptionsError = '';
$statusOptions = [];
$statusOptionsError = '';
$defaultStatusId = null;

if ($canRegister) {
    $connection = null;

    try {
        /** @var mysqli $connection */
        $connection = getDatabaseConnection();
    } catch (Throwable $exception) {
        $clientOptionsError = $exception->getMessage();
        $statusOptionsError = $exception->getMessage();
    }

    if ($connection instanceof mysqli) {
        try {
            $clientQuery = 'SELECT id, name, email FROM clients ORDER BY name ASC';
            $clientResult = $connection->query($clientQuery);

            if ($clientResult instanceof mysqli_result) {
                while ($row = $clientResult->fetch_assoc()) {
                    $clientName = (string) ($row['name'] ?? '');
                    $clientEmail = (string) ($row['email'] ?? '');
                    $clientOptions[] = [
                        'id' => (int) $row['id'],
                        'name' => $clientName,
                        'email' => $clientEmail,
                        'display' => trim($clientName) !== ''
                            ? sprintf('%s (%s)', $clientName, $clientEmail)
                            : $clientEmail,
                    ];
                }

                $clientResult->free();
            }
        } catch (Throwable $exception) {
            $clientOptionsError = $exception->getMessage();
        }

        try {
            $statusQuery = 'SELECT id, slug, name_es, name_en, is_default FROM desembarque_statuses WHERE is_active = 1 ORDER BY is_default DESC, name_es ASC';
            $statusResult = $connection->query($statusQuery);

            if ($statusResult instanceof mysqli_result) {
                while ($row = $statusResult->fetch_assoc()) {
                    $statusId = (int) ($row['id'] ?? 0);
                    $nameEs = (string) ($row['name_es'] ?? '');
                    $nameEn = (string) ($row['name_en'] ?? '');
                    $slug = (string) ($row['slug'] ?? '');
                    $isDefault = (int) ($row['is_default'] ?? 0) === 1;

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
                        'is_default' => $isDefault,
                    ];

                    if ($isDefault && $defaultStatusId === null) {
                        $defaultStatusId = $statusId;
                    }
                }

                $statusResult->free();
            }
        } catch (Throwable $exception) {
            $statusOptionsError = $exception->getMessage();
        }
    }

    if ($defaultStatusId === null && $statusOptions !== []) {
        $defaultStatusId = (int) ($statusOptions[0]['id'] ?? 0) ?: null;
    }

    foreach ($statusOptions as &$statusOption) {
        $statusOption['selected'] = $defaultStatusId !== null && $statusOption['id'] === $defaultStatusId;
    }
    unset($statusOption);
}

$hasClientOptions = $clientOptions !== [];
$clientAssignSelectedTemplate = translate('dashboard.form.customer_assign_selected', ['name' => '{{name}}', 'email' => '{{email}}'], $currentLanguage);

$pedimentosLabelText = translate('dashboard.form.pedimentos', [], $currentLanguage);
$pedimentoPlaceholder = translate('dashboard.form.pedimento_placeholder', [], $currentLanguage);
$addPedimentoLabel = translate('dashboard.form.add_pedimento', [], $currentLanguage);
$pedimentosHeaderTitle = translate('pedimentos.bridge.header.title', [], $currentLanguage);
$pedimentosHeaderClear = translate('pedimentos.bridge.header.clear', [], $currentLanguage);
$pedimentosHeaderPlaceholder = translate('pedimentos.modal.header.placeholder', [], $currentLanguage);
$pedimentosHeaderEmpty = translate('pedimentos.bridge.header.empty', [], $currentLanguage);
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
$pedimentosTableUrl = '../table/index.html?lang=' . rawurlencode($currentLanguage);
$manifestsRemoveAria = trim($removeFieldLabel) !== ''
    ? $removeFieldLabel . ' ' . trim(mb_strtolower($manifestsLabelText, 'UTF-8'))
    : '';
$ciplsRemoveAria = trim($removeFieldLabel) !== ''
    ? $removeFieldLabel . ' ' . trim(mb_strtolower($ciplsLabelText, 'UTF-8'))
    : '';

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars($currentLanguage === 'en' ? 'New unloading record · Desembarques' : 'Nuevo desembarque · Desembarques', ENT_QUOTES, 'UTF-8') ?></title>
        <?php require __DIR__ . '/partials/styles.php'; ?>
    </head>
    <body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
        <?php require __DIR__ . '/partials/nav.php'; ?>
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card shadow-sm form-card">
                        <div class="card-header bg-primary text-white">
                            <h1 class="h3 mb-0"><?= htmlspecialchars($currentLanguage === 'en' ? 'New unloading record' : 'Nuevo desembarque', ENT_QUOTES, 'UTF-8') ?></h1>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4">
                                <p class="lead mb-2 mb-lg-0"><?= $greetingMessage ?></p>
                            </div>
                            <div id="mobile-mode-banner" class="alert alert-primary mobile-mode-banner d-none" role="status">
                                <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                                    <div>
                                        <h2 class="h5 mb-1"><?= htmlspecialchars($mobileModeBannerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                                        <p class="mb-0 small"><?= htmlspecialchars($mobileModeBannerDescription, ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="badge rounded-pill bg-success ms-lg-auto" id="mobile-mode-badge">
                                        <?= htmlspecialchars($mobileModeBadgeActive, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                            </div>
                            <div id="mobile-mode-feedback" class="mobile-mode-feedback alert d-none" role="status"></div>
                            <?php if (! $canRegister): ?>
                                <div class="alert alert-info">
                                    <?= htmlspecialchars(translate('dashboard.client_notice', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if ($canViewReports): ?>
                                    <div class="d-flex justify-content-end mt-3">
                                        <a class="btn btn-primary" href="reportes.php"><?= htmlspecialchars(translate('dashboard.client_reports_button', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></a>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <form id="desembarque-form" autocomplete="off" enctype="multipart/form-data" data-excel-first="true">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="excel_first" value="1">
                                    <input type="hidden" id="aviso-excel-json" name="aviso_excel_json" value="">

                                    <div class="alert alert-light border d-flex flex-column flex-lg-row align-items-lg-center gap-2 mb-4" role="status">
                                        <div>
                                            <strong><?= htmlspecialchars($currentLanguage === 'en' ? 'Simplified registration' : 'Registro simplificado', ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="small text-muted">
                                                <?= htmlspecialchars($currentLanguage === 'en'
                                                    ? 'Select the client and upload the unloading Excel file. The system reads the manifest, transport, dates, merchandise, and customs entries automatically.'
                                                    : 'Selecciona el cliente y carga el Excel de desembarque. Manifiesto, transporte, fechas, mercancías y pedimentos se leen automáticamente.', ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                        <span class="badge text-bg-primary ms-lg-auto"><?= htmlspecialchars($currentLanguage === 'en' ? 'Excel-first' : 'Excel como fuente', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>

                                    <div class="row g-4">
                                        <div class="col-lg-6">
                                            <label for="cliente_id" class="form-label fw-semibold"><?= htmlspecialchars(translate('dashboard.form.customer_assign_label', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                            <select class="form-select" id="cliente_id" name="cliente_id" <?= $hasClientOptions ? 'required' : 'disabled' ?>>
                                                <option value=""><?= htmlspecialchars(translate('dashboard.form.customer_assign_placeholder', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php foreach ($clientOptions as $clientOption): ?>
                                                    <option value="<?= (int) $clientOption['id'] ?>" data-client-name="<?= htmlspecialchars($clientOption['name'], ENT_QUOTES, 'UTF-8') ?>" data-client-email="<?= htmlspecialchars($clientOption['email'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($clientOption['display'], ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div id="cliente-select-info" class="form-text d-none" data-template="<?= htmlspecialchars($clientAssignSelectedTemplate, ENT_QUOTES, 'UTF-8') ?>"></div>
                                            <?php if ($clientOptionsError !== ''): ?>
                                                <div class="form-text text-danger"><?= htmlspecialchars(translate('dashboard.form.customer_assign_error', ['error' => $clientOptionsError], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-lg-6">
                                            <label for="referencia" class="form-label fw-semibold"><?= htmlspecialchars(translate('dashboard.form.reference', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></label>
                                            <input type="text" class="form-control bg-body-tertiary" id="referencia" name="referencia" maxlength="100" readonly required>
                                            <div class="form-text"><?= htmlspecialchars($currentLanguage === 'en' ? 'Generated automatically from the selected client.' : 'Se genera automáticamente a partir del cliente seleccionado.', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>

                                        <div class="col-12">
                                            <section class="border rounded-3 p-3 p-lg-4" aria-labelledby="excel-first-title">
                                                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-3">
                                                    <div>
                                                        <h2 class="h5 mb-1" id="excel-first-title"><?= htmlspecialchars($currentLanguage === 'en' ? 'Unloading Excel file' : 'Excel de desembarque', ENT_QUOTES, 'UTF-8') ?></h2>
                                                        <p class="small text-muted mb-0"><?= htmlspecialchars($currentLanguage === 'en'
                                                            ? 'This workbook becomes the operational source for the unloading record and the future notice PDF.'
                                                            : 'Este archivo se convierte en la fuente operativa del desembarque y del futuro PDF de aviso.', ENT_QUOTES, 'UTF-8') ?></p>
                                                    </div>
                                                    <span class="badge text-bg-secondary" id="excel-first-status"><?= htmlspecialchars($currentLanguage === 'en' ? 'Waiting for file' : 'Esperando archivo', ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>

                                                <label for="source_excel" class="form-label fw-semibold"><?= htmlspecialchars($currentLanguage === 'en' ? 'Excel file (.xlsx / .xls)' : 'Archivo Excel (.xlsx / .xls)', ENT_QUOTES, 'UTF-8') ?></label>
                                                <input type="file" class="form-control" id="source_excel" name="source_excel" required accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">
                                                <div class="form-text" id="excel-first-file-meta"></div>

                                                <div class="row g-3 mt-2 d-none" id="excel-first-preview" aria-live="polite">
                                                    <div class="col-sm-6 col-xl-3"><div class="border rounded p-2 h-100"><span class="small text-muted d-block"><?= htmlspecialchars($currentLanguage === 'en' ? 'Manifest' : 'Manifiesto', ENT_QUOTES, 'UTF-8') ?></span><strong data-excel-preview="manifest">—</strong></div></div>
                                                    <div class="col-sm-6 col-xl-3"><div class="border rounded p-2 h-100"><span class="small text-muted d-block"><?= htmlspecialchars($currentLanguage === 'en' ? 'Transport' : 'Transporte', ENT_QUOTES, 'UTF-8') ?></span><strong data-excel-preview="transport">—</strong></div></div>
                                                    <div class="col-sm-6 col-xl-3"><div class="border rounded p-2 h-100"><span class="small text-muted d-block">IMO</span><strong data-excel-preview="imo">—</strong></div></div>
                                                    <div class="col-sm-6 col-xl-3"><div class="border rounded p-2 h-100"><span class="small text-muted d-block"><?= htmlspecialchars($currentLanguage === 'en' ? 'Merchandise' : 'Mercancías', ENT_QUOTES, 'UTF-8') ?></span><strong data-excel-preview="items">0</strong></div></div>
                                                    <div class="col-md-6"><div class="small"><strong><?= htmlspecialchars($currentLanguage === 'en' ? 'Shipping date:' : 'Fecha de embarque del aviso:', ENT_QUOTES, 'UTF-8') ?></strong> <span data-excel-preview="departure">—</span></div></div>
                                                    <div class="col-md-6"><div class="small"><strong><?= htmlspecialchars($currentLanguage === 'en' ? 'Unloading / ETA:' : 'Desembarque / ETA:', ENT_QUOTES, 'UTF-8') ?></strong> <span data-excel-preview="landing">—</span></div></div>
                                                </div>

                                                <div class="mt-4 d-none" id="excel-first-importers-wrap">
                                                    <h3 class="h6 mb-1"><?= htmlspecialchars($currentLanguage === 'en' ? 'Importer by customs entry' : 'Importador por pedimento', ENT_QUOTES, 'UTF-8') ?></h3>
                                                    <p class="small text-muted mb-3"><?= htmlspecialchars($currentLanguage === 'en'
                                                        ? 'Known importers are reused automatically. Enter a legal business name only when the customs entry has not been seen before.'
                                                        : 'Los importadores conocidos se reutilizan automáticamente. Sólo captura la razón social cuando el sistema aún no conozca ese pedimento.', ENT_QUOTES, 'UTF-8') ?></p>
                                                    <div class="row g-3" id="excel-first-importers"></div>
                                                </div>
                                            </section>
                                        </div>

                                        <div class="col-lg-5">
                                            <label for="cipl" class="form-label fw-semibold"><?= htmlspecialchars(translate('dashboard.form.cipl', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?> <span class="text-muted fw-normal">(<?= htmlspecialchars($currentLanguage === 'en' ? 'optional' : 'opcional', ENT_QUOTES, 'UTF-8') ?>)</span></label>
                                            <input type="text" class="form-control" id="cipl" name="cipl" maxlength="100">
                                            <div class="form-text"><?= htmlspecialchars($currentLanguage === 'en' ? 'Use it only when this unloading record requires a CIPL reference.' : 'Úsalo sólo cuando este desembarque requiera una referencia CIPL.', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>

                                        <div class="col-lg-7">
                                            <label for="attachments" class="form-label fw-semibold"><?= htmlspecialchars(translate('dashboard.form.attachments', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?> <span class="text-muted fw-normal">(<?= htmlspecialchars($currentLanguage === 'en' ? 'optional' : 'opcional', ENT_QUOTES, 'UTF-8') ?>)</span></label>
                                            <input type="file" class="form-control" id="attachments" name="attachments[]" multiple<?= $attachmentsAcceptAttribute ?>>
                                            <?php if ($allowedExtensionsDisplay !== ''): ?>
                                                <div class="form-text"><?= htmlspecialchars(translate('dashboard.form.attachments_help_types', ['types' => $allowedExtensionsDisplay], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                            <?php if ($maxAttachmentSizeFormatted !== ''): ?>
                                                <div class="form-text"><?= htmlspecialchars(translate('dashboard.form.attachments_help_max', ['max' => $maxAttachmentSizeFormatted], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                            <div class="form-text text-danger d-none" id="attachments-feedback"></div>
                                            <ul class="list-group list-group-flush mt-2 d-none" id="attachments-list" aria-live="polite" aria-relevant="additions removals"></ul>
                                            <div class="form-text" id="attachments-empty"><?= htmlspecialchars(translate('dashboard.form.attachments_empty', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>

                                    <!-- Campos de compatibilidad: el backend los deriva del Excel; se mantienen para no romper módulos legacy. -->
                                    <div class="d-none" aria-hidden="true">
                                        <input type="text" id="cliente" name="cliente" maxlength="150">
                                        <select id="status_id" name="status_id" data-default-value="<?= $defaultStatusId !== null ? (int) $defaultStatusId : '' ?>">
                                            <option value=""></option>
                                            <?php foreach ($statusOptions as $statusOption): ?>
                                                <option value="<?= (int) $statusOption['id'] ?>" <?= ! empty($statusOption['selected']) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($statusOption['display'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" id="folio_aviso" name="folio_aviso" maxlength="100">
                                        <input type="text" id="pedimento" name="pedimento" maxlength="100">
                                        <input type="text" id="manifiesto" name="manifiesto" maxlength="100">
                                        <input type="date" id="fecha_desembarque" name="fecha_desembarque">
                                        <input type="date" id="fecha_embarque" name="fecha_embarque">
                                        <textarea id="descripcion" name="descripcion" maxlength="1000"></textarea>
                                        <input type="text" id="destino" name="destino" maxlength="150">
                                        <input type="text" id="barco" name="barco" maxlength="150">
                                        <input type="number" id="dias_transcurridos_display" value="0">
                                        <input type="number" id="dias_fuera_display" value="0">
                                        <div id="pedimentos-fields" class="dynamic-field-group" data-field-name="pedimentos" data-placeholder="<?= htmlspecialchars($pedimentoPlaceholder, ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($pedimentosLabelText, ENT_QUOTES, 'UTF-8') ?>" data-remove-text="<?= htmlspecialchars($removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-max-length="100" data-next-index="0" data-allow-initial-empty="false"></div>
                                        <div id="manifests-fields" class="dynamic-field-group" data-field-name="manifests" data-placeholder="<?= htmlspecialchars($manifestPlaceholder, ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($manifestsLabelText, ENT_QUOTES, 'UTF-8') ?>" data-remove-text="<?= htmlspecialchars($removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-max-length="100" data-next-index="0" data-allow-initial-empty="false"></div>
                                        <div id="cipls-fields" class="dynamic-field-group" data-field-name="cipls" data-placeholder="<?= htmlspecialchars($ciplPlaceholder, ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($ciplsLabelText, ENT_QUOTES, 'UTF-8') ?>" data-remove-text="<?= htmlspecialchars($removeFieldLabel, ENT_QUOTES, 'UTF-8') ?>" data-max-length="100" data-next-index="0" data-allow-initial-empty="false"></div>
                                        <input type="hidden" id="pedimentos-header-json" name="pedimentos_header_json" value="">
                                        <input type="hidden" id="pedimentos-packages-json" name="pedimentos_packages_json" value="">
                                    </div>
                                    <input type="hidden" id="dias_transcurridos" name="dias_transcurridos" value="0">
                                    <input type="hidden" id="dias_fuera" name="dias_fuera" value="0">

                                    <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mt-4">
                                        <button type="reset" class="btn btn-outline-secondary"><?= htmlspecialchars(translate('dashboard.form.reset', [], $currentLanguage), ENT_QUOTES, 'UTF-8') ?></button>
                                        <button type="submit" class="btn btn-primary px-4" id="excel-first-submit" disabled>
                                            <?= htmlspecialchars($currentLanguage === 'en' ? 'Register unloading operation' : 'Registrar desembarque', ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </div>
                                </form>
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
                translations: <?= json_encode($formTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                theme: <?= json_encode($currentTheme, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                themePreferenceKey: <?= json_encode($themePreferenceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                fileUpload: <?= json_encode($fileUploadClientConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            };
        </script>
        <?php
            $includeSweetAlert = true;
            $pageScripts = [
                'assets/js/theme.js',
                'assets/js/offline-queue.js',
                [
                    'src' => 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
                    'integrity' => 'sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw',
                    'crossorigin' => 'anonymous',
                ],
                'assets/js/aviso-pdf.js?v=42',
                'assets/js/form.js',
                'assets/js/excel-first.js',
            ];
            require __DIR__ . '/partials/scripts.php';
        ?>
    </body>
</html>
