<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/historical_import.php';

if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();
$isEnglish = $currentLanguage === 'en';
$importH = static function (string $es, string $en) use ($currentLanguage): string {
    return htmlspecialchars(translateText($es, $en, $currentLanguage), ENT_QUOTES, 'UTF-8');
};
$user = $_SESSION['user'];
$userRole = trim((string) ($user['role'] ?? ''));

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    http_response_code(403);
    header('Location: reportes.php');
    exit;
}

$themePreference = getUserThemePreference($user);
$currentTheme = $themePreference['theme'];
$themePreferenceKey = $themePreference['cookie_key'];
$roleLabel = translateRoleLabel($userRole, $currentLanguage);
$themeLabel = translate('common.theme.label', [], $currentLanguage);
$themeLightLabel = translate('common.theme.light', [], $currentLanguage);
$themeDarkLabel = translate('common.theme.dark', [], $currentLanguage);
$currentThemeLabel = $currentTheme === 'dark' ? $themeDarkLabel : $themeLightLabel;
$themeToggleAnnouncement = trim($themeLabel) !== '' ? $themeLabel . ': ' . $currentThemeLabel : $currentThemeLabel;
$csrfToken = csrf_token();
$historicalUploadConfig = historical_import_upload_config();

$clients = [];
$operationalStatuses = [];
try {
    $connection = getDatabaseConnection();
    $result = $connection->query('SELECT id, name, email FROM clients ORDER BY name ASC, id ASC');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
    }

    $statusResult = $connection->query(
        "SELECT id, slug, name_es, name_en, is_default FROM desembarque_statuses "
        . "WHERE is_active = 1 ORDER BY (slug = 'completed') DESC, is_default DESC, id ASC"
    );
    if ($statusResult instanceof mysqli_result) {
        while ($row = $statusResult->fetch_assoc()) {
            $operationalStatuses[] = $row;
        }
    }
} catch (Throwable $exception) {
    $clientError = $exception->getMessage();
}

$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = $roleLabel;
$navLinks = [
    ['href' => 'desembarque-nuevo.php', 'label' => translateText('Nuevo desembarque', 'New unloading record', $currentLanguage), 'class' => 'btn btn-outline-primary btn-sm'],
    ['href' => 'reportes.php', 'label' => translate('dashboard.nav.reports', [], $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm'],
    ['href' => 'aviso-importar.php', 'label' => translateText('Importar históricos', 'Import history', $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm'],
    ['href' => 'myprofile.php', 'label' => translate('dashboard.nav.profile', [], $currentLanguage), 'class' => 'btn btn-outline-secondary btn-sm'],
    ['href' => 'logout.php', 'label' => translate('reports.nav.logout', [], $currentLanguage), 'class' => 'btn btn-outline-danger btn-sm'],
];
$initialBatch = preg_match('/^[a-f0-9]{32}$/', (string) ($_GET['batch'] ?? '')) ? (string) $_GET['batch'] : '';
$avisoStatuses = [
    'issued' => translateText('Emitido', 'Issued', $currentLanguage),
    'presented' => translateText('Presentado', 'Presented', $currentLanguage),
    'replaced' => translateText('Reemplazado', 'Replaced', $currentLanguage),
    'cancelled' => translateText('Cancelado', 'Cancelled', $currentLanguage),
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>" data-bs-theme="<?= htmlspecialchars($currentTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#003366">
    <title><?= $importH('Revisión e importación histórica · Desembarques', 'Historical review and import · Unloading records') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/navbar-premium.css">
    <link rel="stylesheet" href="assets/css/aviso-import.css">
</head>
<body>
<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="container aviso-import-shell py-4 py-lg-5">
    <section class="aviso-import-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-lg-start">
            <div>
                <div class="text-uppercase small fw-semibold text-primary mb-2"><?= $importH('Fase 5E5 · Aceptación y robustecimiento', 'Phase 5E5 · Acceptance & Hardening') ?></div>
                <h1 class="h2 mb-2"><?= $importH('Importación histórica de Avisos', 'Historical notice import') ?></h1>
                <p class="text-body-secondary mb-0" style="max-width: 900px;">
                    <?= $importH(
                        'Analiza PDFs finales ya realizados, usa Word como fuente auxiliar cuando el PDF sea escaneado, revisa los datos y crea expedientes históricos completos sin repetir el flujo Excel-first. La Fase 5E5 añade una compuerta automática de integridad y una validación posterior por lote antes de considerar los históricos aptos para reportes ejecutivos.',
                        'Analyze existing final PDFs, use Word as a companion source when a PDF is scanned, review the data, and create complete historical case files without repeating the Excel-first workflow. Phase 5E5 adds an automatic integrity gate and post-import batch validation before historical records are accepted for executive reporting.'
                    ) ?>
                </p>
            </div>
            <a class="btn btn-outline-secondary" href="reportes.php">← <?= $importH('Volver a Reportes', 'Back to Reports') ?></a>
        </div>
    </section>

    <div class="aviso-import-callout p-3 p-lg-4 mb-4">
        <div class="d-flex gap-3 align-items-start">
            <div class="fs-4" aria-hidden="true">🛡️</div>
            <div>
                <strong><?= $importH('Revisión humana antes del registro definitivo.', 'Human review before permanent registration.') ?></strong>
                <div class="text-body-secondary small mt-1">
                    <?= $importH('Los datos extraídos permanecen en staging hasta que guardes la revisión y confirmes la importación. La confirmación crea cada grupo seleccionado en una sola transacción: expediente, mercancías, pedimentos, estado documental y PDF original v001.', 'Extracted data remains in staging until you save the review and confirm the import. Confirmation creates each selected group in a single transaction: case file, merchandise, customs entries, document status, and original PDF v001.') ?>
                </div>
            </div>
        </div>
    </div>

    <section class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form id="historical-import-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="row g-3 mb-4">
                    <div class="col-lg-5">
                        <label class="form-label fw-semibold" for="historical-client"><?= $importH('Cliente del sistema', 'System client') ?></label>
                        <select class="form-select" id="historical-client" name="client_id" required>
                            <option value=""><?= $importH('Selecciona un cliente…', 'Select a client…') ?></option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= (int) $client['id'] ?>">
                                    <?= htmlspecialchars(trim((string) $client['name']) . ' · ' . trim((string) $client['email']), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= $importH('Se asigna explícitamente: cliente, importador y comitente son conceptos distintos.', 'Assignment is explicit: client, importer, and principal are separate concepts.') ?></div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-semibold" for="historical-status"><?= $importH('Estado documental inicial', 'Initial document status') ?></label>
                        <select class="form-select" id="historical-status" name="default_aviso_status">
                            <?php foreach ($avisoStatuses as $slug => $label): ?>
                                <option value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" <?= $slug === 'presented' ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= $importH('Se puede ajustar aviso por aviso en la revisión.', 'It can be adjusted for each notice during review.') ?></div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label fw-semibold" for="historical-operational-status"><?= $importH('Estado operativo del desembarque', 'Unloading operational status') ?></label>
                        <select class="form-select" id="historical-operational-status" name="operational_status_id" required>
                            <?php foreach ($operationalStatuses as $index => $status): ?>
                                <?php $label = trim((string) ($status[$isEnglish ? 'name_en' : 'name_es'] ?? '')) ?: trim((string) ($status['slug'] ?? '')); ?>
                                <option value="<?= (int) $status['id'] ?>" <?= $index === 0 ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= $importH('Los trabajos históricos terminados normalmente se registran como Completado.', 'Completed historical jobs are normally registered as Completed.') ?></div>
                    </div>
                </div>

                <div class="aviso-import-dropzone p-4 p-lg-5 text-center" data-import-dropzone>
                    <div class="fs-1 mb-2" aria-hidden="true">📚</div>
                    <h2 class="h5"><?= $importH('PDF final + Word opcional (.doc/.docx)', 'Final PDF + optional Word file (.doc/.docx)') ?></h2>
                    <p class="text-body-secondary mb-3">
                        <?= $importH('Selecciona hasta 20 archivos. Si el PDF es escaneado, agrega también el Word original (.doc o .docx) con el mismo número de Aviso. El sistema los empareja automáticamente y usa Word sólo como fuente de extracción.', 'Select up to 20 files. If a PDF is scanned, also add the original Word file (.doc or .docx) with the same notice number. The system pairs them automatically and uses Word only as an extraction source.') ?>
                    </p>
                    <input class="form-control" type="file" id="historical-files" name="files[]" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple required>
                    <div class="form-text mt-2"><?= $importH('Máximo', 'Maximum') ?> <?= (int) $historicalUploadConfig['max_file_size_mb'] ?> <?= $importH('MB por archivo y', 'MB per file and') ?> <?= (int) $historicalUploadConfig['max_batch_size_mb'] ?> <?= $importH('MB por lote. El PDF es el documento oficial que se archivará; el Word nunca sustituye al PDF.', 'MB per batch. The PDF is the official document that will be archived; Word never replaces the PDF.') ?></div>
                    <div class="aviso-import-file-list small text-start mt-3" data-file-list></div>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
                    <button class="btn btn-primary px-4" type="submit"><?= $importH('Analizar lote', 'Analyze batch') ?></button>
                </div>
            </form>
        </div>
    </section>

    <div class="alert alert-info" data-import-feedback hidden></div>

    <section data-import-results <?= $initialBatch === '' ? 'hidden' : '' ?>>
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3"><div class="aviso-import-stat"><strong data-stat-total>0</strong><span class="text-body-secondary"><?= $importH('Avisos detectados', 'Notices detected') ?></span></div></div>
            <div class="col-6 col-lg-3"><div class="aviso-import-stat"><strong class="text-success" data-stat-ready>0</strong><span class="text-body-secondary"><?= $importH('Revisados y listos', 'Reviewed and ready') ?></span></div></div>
            <div class="col-6 col-lg-3"><div class="aviso-import-stat"><strong class="text-warning" data-stat-review>0</strong><span class="text-body-secondary"><?= $importH('Por revisar', 'Needs review') ?></span></div></div>
            <div class="col-6 col-lg-3"><div class="aviso-import-stat"><strong class="text-danger" data-stat-duplicate>0</strong><span class="text-body-secondary"><?= $importH('Duplicados detectados', 'Duplicates detected') ?></span></div></div>
        </div>

        <div class="aviso-import-batchbar mb-3" data-batch-summary hidden>
            <div>
                <span class="fw-semibold" data-batch-client>—</span>
                <span class="text-body-secondary">·</span>
                <span class="text-body-secondary" data-batch-operational>—</span>
                <span class="text-body-secondary">·</span>
                <span class="text-body-secondary" data-batch-progress>—</span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
                <span class="small text-body-secondary" data-selected-summary><?= $importH('0 seleccionados', '0 selected') ?></span>
                <button class="btn btn-outline-primary" type="button" data-validate-batch disabled><?= $importH('Validar lote 5E5', 'Validate 5E5 batch') ?></button>
                <button class="btn btn-success" type="button" data-import-selected disabled><?= $importH('Importar seleccionados', 'Import selected') ?></button>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-transparent border-0 p-4 pb-2">
                <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-start">
                    <div>
                        <h2 class="h5 mb-1"><?= $importH('Bandeja de revisión', 'Review queue') ?></h2>
                        <p class="text-body-secondary small mb-0"><?= $importH('Revisa cada aviso, corrige únicamente lo necesario y guárdalo. Sólo las filas marcadas como Listo para importar pueden confirmarse.', 'Review each notice, correct only what is necessary, and save it. Only rows marked Ready to import can be confirmed.') ?></p>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-select-all-ready><?= $importH('Seleccionar listos', 'Select ready') ?></button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 aviso-import-table">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:44px">✓</th>
                                <th><?= $importH('Aviso', 'Notice') ?></th><th><?= $importH('Archivos', 'Files') ?></th><th><?= $importH('Fuente', 'Source') ?></th><th><?= $importH('Fecha', 'Date') ?></th><th>Rig</th><th><?= $importH('Mercancía', 'Merchandise') ?></th><th><?= $importH('Pedimentos', 'Customs entries') ?></th><th><?= $importH('Estado', 'Status') ?></th>
                            </tr>
                        </thead>
                        <tbody data-import-rows>
                            <tr><td colspan="9" class="text-center text-body-secondary py-4"><?= $importH('Carga un lote para comenzar.', 'Upload a batch to begin.') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <section class="card border-0 shadow-sm rounded-4 mt-4" data-validation-results hidden>
            <div class="card-header bg-transparent border-0 p-4 pb-2">
                <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-start">
                    <div>
                        <div class="text-uppercase small fw-semibold text-primary mb-1">Fase 5E5</div>
                        <h2 class="h5 mb-1"><?= $importH('Validación de integridad del lote', 'Batch integrity validation') ?></h2>
                        <p class="text-body-secondary small mb-0"><?= $importH('Comprueba estructura, conteos, estados, vínculos de staging, snapshots y SHA-256 de los PDFs archivados.', 'Checks structure, counts, statuses, staging links, snapshots, and SHA-256 values for archived PDFs.') ?></p>
                    </div>
                    <span class="badge rounded-pill" data-validation-badge><?= $importH('Sin ejecutar', 'Not run') ?></span>
                </div>
            </div>
            <div class="card-body p-4 pt-2">
                <div class="row g-3 mb-3" data-validation-summary></div>
                <div data-validation-batch-checks></div>
                <div class="accordion mt-3" id="historicalValidationAccordion" data-validation-rows></div>
            </div>
        </section>
    </section>
</main>

<div class="modal fade" id="historicalCommitModal" tabindex="-1" aria-labelledby="historicalCommitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="historicalCommitModalLabel"><?= $importH('Confirmar importación histórica', 'Confirm historical import') ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $importH('Cerrar', 'Close') ?>"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    <?= $importH('Esta operación creará registros permanentes. Cada PDF original quedará archivado como una versión histórica inmutable y verificable por SHA-256.', 'This operation will create permanent records. Each original PDF will be archived as an immutable historical version verifiable by SHA-256.') ?>
                </div>
                <div data-commit-summary></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= $importH('Cancelar', 'Cancel') ?></button>
                <button type="button" class="btn btn-success" data-confirm-commit><?= $importH('Confirmar importación', 'Confirm import') ?></button>
            </div>
        </div>
    </div>
</div>

<script>
window.AvisoHistoricalImportConfig = <?= json_encode([
    'language' => $currentLanguage,
    'uploadUrl' => '../api/desembarques/aviso/import/upload.php',
    'listUrl' => '../api/desembarques/aviso/import/list.php',
    'reviewUrl' => '../api/desembarques/aviso/import/review.php',
    'commitUrl' => '../api/desembarques/aviso/import/commit.php',
    'validateUrl' => '../api/desembarques/aviso/import/validate.php',
    'csrfToken' => $csrfToken,
    'initialBatch' => $initialBatch,
    'avisoStatuses' => $avisoStatuses,
    'maxFiles' => (int) $historicalUploadConfig['max_files'],
    'maxFileSizeMb' => (int) $historicalUploadConfig['max_file_size_mb'],
    'maxBatchSizeMb' => (int) $historicalUploadConfig['max_batch_size_mb'],
    'canRestoreDeleted' => $userRole === 'admin',
    'canOverrideDeleted' => in_array($userRole, ['admin', 'usuario'], true),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/i18n-runtime.js?v=42"></script>
<script src="assets/js/theme.js"></script>
<script src="assets/js/aviso-import.js?v=42"></script>
</body>
</html>
