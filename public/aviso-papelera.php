<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/theme.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/database.php';

if (isset($_GET['lang'])) {
    setAppLanguage((string) $_GET['lang']);
}

$currentLanguage = getAppLanguage();
if (! isset($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(404);
    $notAvailableTitle = translateText('No disponible', 'Unavailable', $currentLanguage);
    $notAvailableBody = translateText('Contenido no disponible', 'Content unavailable', $currentLanguage);
    echo '<!doctype html><html lang="' . htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') . '"><meta charset="utf-8"><title>'
        . htmlspecialchars($notAvailableTitle, ENT_QUOTES, 'UTF-8') . '</title><body><h1>'
        . htmlspecialchars($notAvailableBody, ENT_QUOTES, 'UTF-8') . '</h1></body></html>';
    exit;
}

$themePreference = getUserThemePreference($_SESSION['user']);
$currentTheme = $themePreference['theme'];
$themePreferenceKey = $themePreference['cookie_key'];
$themeLabel = translate('common.theme.label', [], $currentLanguage);
$themeLightLabel = translate('common.theme.light', [], $currentLanguage);
$themeDarkLabel = translate('common.theme.dark', [], $currentLanguage);
$currentThemeLabel = $currentTheme === 'dark' ? $themeDarkLabel : $themeLightLabel;
$themeToggleAnnouncement = trim($themeLabel) !== '' ? $themeLabel . ': ' . $currentThemeLabel : $currentThemeLabel;
$navProfileLabel = translate('dashboard.nav.profile', [], $currentLanguage);
$navProfileValue = translateRoleLabel('admin', $currentLanguage);
$navLinks = [
    ['href' => 'dashboard-avisos.php', 'label' => translate('dashboard.nav.dashboard', [], $currentLanguage)],
    ['href' => 'reportes.php', 'label' => translate('dashboard.nav.reports', [], $currentLanguage)],
    ['href' => 'aviso-importar.php', 'label' => translateText('Importar históricos', 'Import history', $currentLanguage)],
    ['href' => 'aviso-papelera.php', 'label' => $currentLanguage === 'en' ? 'Deleted notices' : 'Avisos eliminados'],
    ['href' => 'audit_logs.php', 'label' => translate('dashboard.nav.audit_logs', [], $currentLanguage)],
    ['href' => 'myprofile.php', 'label' => translate('dashboard.nav.profile', [], $currentLanguage)],
    ['href' => 'logout.php', 'label' => translate('reports.nav.logout', [], $currentLanguage)],
];

$csrfToken = csrf_token();
$focusId = isset($_GET['focus']) && ctype_digit((string) $_GET['focus']) ? (int) $_GET['focus'] : 0;
$records = [];
$loadError = '';

try {
    $connection = getDatabaseConnection();
    $result = $connection->query(
        'SELECT d.id, d.referencia, d.cliente, d.deleted_at, d.delete_reason, '
        . 'COALESCE(NULLIF(TRIM(ad.notice_number), \'\'), NULLIF(TRIM(d.folio_aviso), \'\'), NULLIF(TRIM(d.manifiesto), \'\'), d.referencia) AS notice_number, '
        . 'ad.document_code, ad.rig_name, c.name AS client_name, u.name AS deleted_by_name, '
        . '(SELECT COUNT(*) FROM desembarque_aviso_versions v WHERE v.desembarque_id = d.id) AS pdf_versions, '
        . '(SELECT COUNT(*) FROM desembarque_files f WHERE f.desembarque_id = d.id) AS files_count, '
        . '(SELECT COUNT(*) FROM desembarque_aviso_items i WHERE i.desembarque_id = d.id) AS item_count '
        . 'FROM desembarques d '
        . 'LEFT JOIN desembarque_aviso_details ad ON ad.desembarque_id = d.id '
        . 'LEFT JOIN clients c ON c.id = d.client_id '
        . 'LEFT JOIN users u ON u.id = d.deleted_by '
        . 'WHERE d.deleted_at IS NOT NULL '
        . 'ORDER BY d.deleted_at DESC, d.id DESC'
    );
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
    }
} catch (Throwable $exception) {
    $loadError = $exception->getMessage();
}

function papelera_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function papelera_date(mixed $value): string
{
    try {
        return (new DateTimeImmutable((string) $value))->format('d/m/Y H:i');
    } catch (Throwable) {
        return (string) $value;
    }
}

?><!doctype html>
<html lang="<?= papelera_h($currentLanguage) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= papelera_h($currentLanguage === 'en' ? 'Deleted notices' : 'Papelera administrativa de avisos') ?></title>
    <?php require __DIR__ . '/partials/styles.php'; ?>
</head>
<body class="<?= $currentTheme === 'dark' ? 'theme-dark' : '' ?>">
<?php require __DIR__ . '/partials/nav.php'; ?>
<main class="container py-4 py-lg-5">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-end mb-4">
        <div>
            <span class="badge text-bg-danger mb-2">FASE 7F</span>
            <h1 class="display-6 fw-semibold mb-2"><?= papelera_h($currentLanguage === 'en' ? 'Deleted notices' : 'Papelera administrativa de avisos') ?></h1>
            <p class="text-body-secondary mb-0"><?= papelera_h($currentLanguage === 'en' ? 'Administrative records excluded from operations and reports.' : 'Registros retirados administrativamente de la operación y de los reportes.') ?></p>
        </div>
        <a class="btn btn-outline-secondary" href="reportes.php"><?= papelera_h($currentLanguage === 'en' ? 'Back to reports' : 'Volver a reportes') ?></a>
    </div>

    <div class="alert alert-warning shadow-sm" role="alert">
        <strong><?= papelera_h($currentLanguage === 'en' ? 'No data was permanently deleted.' : 'No se eliminó información permanentemente.') ?></strong>
        <?= papelera_h($currentLanguage === 'en' ? ' PDFs, photos, documents, merchandise, customs entries, addenda, acknowledgments, departure records, and audit history remain intact.' : ' Los PDFs, fotos, documentos, mercancías, pedimentos, Alcances, acuses, finalizaciones y auditoría permanecen intactos.') ?>
    </div>

    <?php if ($loadError !== ''): ?>
        <div class="alert alert-danger"><?= papelera_h($loadError) ?></div>
    <?php elseif ($records === []): ?>
        <div class="card shadow-sm border-0"><div class="card-body p-5 text-center text-body-secondary"><?= papelera_h($currentLanguage === 'en' ? 'There are no deleted notices.' : 'La Papelera administrativa está vacía.') ?></div></div>
    <?php else: ?>
        <div class="card shadow-sm border-0 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th><?= papelera_h($currentLanguage === 'en' ? 'Notice' : 'Aviso') ?></th>
                        <th><?= papelera_h($currentLanguage === 'en' ? 'Client / Rig' : 'Cliente / Rig') ?></th>
                        <th><?= papelera_h($currentLanguage === 'en' ? 'Deleted' : 'Eliminación') ?></th>
                        <th><?= papelera_h($currentLanguage === 'en' ? 'Reason' : 'Motivo') ?></th>
                        <th><?= papelera_h($currentLanguage === 'en' ? 'Preserved' : 'Conservado') ?></th>
                        <th class="text-end"><?= papelera_h($currentLanguage === 'en' ? 'Actions' : 'Acciones') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php $notice = trim((string) ($record['notice_number'] ?? '')) ?: (string) $record['referencia']; ?>
                        <tr<?= (int) $record['id'] === $focusId ? ' class="table-warning"' : '' ?>>
                            <td><strong><?= papelera_h($notice) ?></strong><div class="small text-body-secondary"><?= papelera_h((string) ($record['document_code'] ?? '')) ?> · <?= papelera_h((string) $record['referencia']) ?></div></td>
                            <td><?= papelera_h((string) (($record['client_name'] ?? '') ?: ($record['cliente'] ?? '—'))) ?><div class="small text-body-secondary"><?= papelera_h((string) (($record['rig_name'] ?? '') ?: '—')) ?></div></td>
                            <td><?= papelera_h(papelera_date($record['deleted_at'] ?? '')) ?><div class="small text-body-secondary"><?= papelera_h((string) (($record['deleted_by_name'] ?? '') ?: '—')) ?></div></td>
                            <td class="text-wrap" style="min-width:14rem"><?= papelera_h((string) ($record['delete_reason'] ?? '')) ?></td>
                            <?php $preservedItemCount = (int) ($record['item_count'] ?? 0); $preservedPdfCount = (int) ($record['pdf_versions'] ?? 0); $preservedFileCount = (int) ($record['files_count'] ?? 0); ?>
                            <td class="small text-body-secondary"><?= $preservedItemCount ?> <?= papelera_h($currentLanguage === 'en' ? ($preservedItemCount === 1 ? 'merchandise line' : 'merchandise lines') : 'merc.') ?><br><?= $preservedPdfCount ?> <?= papelera_h($currentLanguage === 'en' && $preservedPdfCount === 1 ? 'PDF' : 'PDFs') ?> · <?= $preservedFileCount ?> <?= papelera_h($currentLanguage === 'en' ? ($preservedFileCount === 1 ? 'file' : 'files') : 'archivos') ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="aviso-expediente.php?id=<?= (int) $record['id'] ?>&amp;deleted=1"><?= papelera_h($currentLanguage === 'en' ? 'View' : 'Ver') ?></a>
                                <button class="btn btn-sm btn-success" type="button" data-bs-toggle="modal" data-bs-target="#avisoRestoreModal" data-soft-delete-restore data-desembarque-id="<?= (int) $record['id'] ?>" data-notice-number="<?= papelera_h($notice) ?>"><?= papelera_h($currentLanguage === 'en' ? 'Restore' : 'Restaurar') ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<div class="modal fade" id="avisoRestoreModal" tabindex="-1" aria-labelledby="avisoRestoreModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form data-soft-delete-form="restore" novalidate>
                <div class="modal-header"><h2 class="modal-title fs-5" id="avisoRestoreModalLabel"><?= papelera_h($currentLanguage === 'en' ? 'Restore notice' : 'Restaurar aviso') ?></h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="<?= papelera_h($currentLanguage === 'en' ? 'Close' : 'Cerrar') ?>"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="desembarque_id">
                    <input type="hidden" name="csrf_token" value="<?= papelera_h($csrfToken) ?>">
                    <div class="alert alert-info small"><?= papelera_h($currentLanguage === 'en' ? 'The same record and all related data will return to operational views and reports.' : 'El mismo registro y todos sus datos conservados volverán a las vistas operativas y reportes.') ?></div>
                    <label class="form-label fw-semibold" for="restore-reason"><?= papelera_h($currentLanguage === 'en' ? 'Restore reason' : 'Motivo de restauración') ?></label>
                    <textarea class="form-control" id="restore-reason" name="reason" maxlength="500" rows="3" required></textarea>
                    <label class="form-label fw-semibold mt-3" for="restore-confirmation"><?= papelera_h($currentLanguage === 'en' ? 'Written confirmation' : 'Confirmación escrita') ?></label>
                    <div class="form-text mb-2"><?= papelera_h($currentLanguage === 'en' ? 'Type exactly:' : 'Escribe exactamente:') ?> <code data-soft-delete-confirmation-example></code></div>
                    <input class="form-control" id="restore-confirmation" name="confirmation" autocomplete="off" required>
                    <div class="alert d-none mt-3 mb-0" data-soft-delete-feedback role="status"></div>
                </div>
                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><?= papelera_h($currentLanguage === 'en' ? 'Cancel' : 'Cancelar') ?></button><button class="btn btn-success" type="submit"><?= papelera_h($currentLanguage === 'en' ? 'Restore notice' : 'Restaurar aviso') ?></button></div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
<script>
window.ReportThemeConfig = {theme: <?= json_encode($currentTheme) ?>, themePreferenceKey: <?= json_encode($themePreferenceKey) ?>};
window.AvisoSoftDeleteConfig = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    restoreEndpoint: '../api/desembarques/admin/restore.php',
    restoreRedirect: 'aviso-papelera.php'
};
</script>
<?php $includeSweetAlert = false; $pageScripts = ['assets/js/theme.js', 'assets/js/aviso-soft-delete.js?v=42']; require __DIR__ . '/partials/scripts.php'; ?>
</body>
</html>
