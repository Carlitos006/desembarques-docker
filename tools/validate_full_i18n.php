<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/i18n.php';
require_once $root . '/config/operational_i18n.php';

$failures = [];
$passes = [];

function fullI18nCheck(bool $condition, string $label): void
{
    global $failures, $passes;
    if ($condition) {
        $passes[] = $label;
        echo "[OK] {$label}\n";
        return;
    }
    $failures[] = $label;
    echo "[FAIL] {$label}\n";
}

function fullI18nContents(string $root, string $relative): string
{
    $contents = @file_get_contents($root . '/' . $relative);
    return is_string($contents) ? $contents : '';
}

$catalogue = appTranslations();
$spanishKeys = array_keys($catalogue['es'] ?? []);
$englishKeys = array_keys($catalogue['en'] ?? []);
sort($spanishKeys);
sort($englishKeys);
fullI18nCheck($spanishKeys === $englishKeys, 'Los catálogos español e inglés tienen exactamente las mismas claves');
fullI18nCheck(($catalogue['es']['common.close'] ?? '') === 'Cerrar' && ($catalogue['en']['common.close'] ?? '') === 'Close', 'La traducción común Cerrar/Close no está sobrescrita');
fullI18nCheck(translateText('Español', 'English', 'es') === 'Español' && translateText('Español', 'English', 'en') === 'English', 'El selector central resuelve ambos idiomas');

$placeholderMismatches = [];
$englishSpanishLeaks = [];
$englishTerminologyLeaks = [];
foreach (($catalogue['en'] ?? []) as $key => $englishValue) {
    $spanishValue = (string) ($catalogue['es'][$key] ?? '');
    preg_match_all('/\{\{[^}]+\}\}/', $spanishValue, $spanishPlaceholders);
    preg_match_all('/\{\{[^}]+\}\}/', (string) $englishValue, $englishPlaceholders);
    $spanishTokens = $spanishPlaceholders[0] ?? [];
    $englishTokens = $englishPlaceholders[0] ?? [];
    sort($spanishTokens);
    sort($englishTokens);
    if ($spanishTokens !== $englishTokens) {
        $placeholderMismatches[] = (string) $key;
    }
    $withoutPlaceholders = preg_replace('/\{\{[^}]+\}\}/', '', (string) $englishValue) ?? (string) $englishValue;
    if (preg_match('/[áéíóúñ¿¡]/iu', $withoutPlaceholders) === 1) {
        $englishSpanishLeaks[] = (string) $key;
    }
    if (preg_match('/\b(?:landing|landings|pedimento|pedimentos|folio|scope|scopes)\b/i', $withoutPlaceholders) === 1) {
        $englishTerminologyLeaks[] = (string) $key;
    }
}
fullI18nCheck($placeholderMismatches === [], 'Todas las variables {{...}} coinciden entre español e inglés');
fullI18nCheck($englishSpanishLeaks === [], 'El catálogo inglés no contiene fragmentos españoles acentuados');
fullI18nCheck($englishTerminologyLeaks === [], 'El catálogo inglés usa terminología profesional uniforme');

$missingJavascriptKeys = [];
$javascriptIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/assets/js'));
foreach ($javascriptIterator as $file) {
    if (! $file->isFile() || strtolower($file->getExtension()) !== 'js') {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    preg_match_all('/\btranslate\(\s*[\'\"]([a-z0-9_.-]+)[\'\"]/i', $contents, $matches);
    foreach ($matches[1] ?? [] as $translationKey) {
        if (str_contains((string) $translationKey, '.') && ! str_ends_with((string) $translationKey, '.') && ! array_key_exists((string) $translationKey, $catalogue['en'] ?? [])) {
            $missingJavascriptKeys[(string) $translationKey] = true;
        }
    }
}
fullI18nCheck($missingJavascriptKeys === [], 'Todas las claves translate(...) de JavaScript existen en el catálogo inglés');

$missingPhpKeys = [];
foreach (['public', 'api', 'config'] as $sourceDirectory) {
    $phpIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $sourceDirectory));
    foreach ($phpIterator as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        preg_match_all('/\btranslate\(\s*[\'\"]([a-z0-9_.-]+)[\'\"]/i', $contents, $matches);
        foreach ($matches[1] ?? [] as $translationKey) {
            if (str_contains((string) $translationKey, '.') && ! str_ends_with((string) $translationKey, '.') && ! array_key_exists((string) $translationKey, $catalogue['en'] ?? [])) {
                $missingPhpKeys[(string) $translationKey] = true;
            }
        }
    }
}
fullI18nCheck($missingPhpKeys === [], 'Todas las claves translate(...) de PHP existen en el catálogo inglés');

$sample = translateOperationalPayload([
    'message' => 'La fotografía fue eliminada del expediente.',
    'detail' => 'Método no permitido.',
    'data' => ['cliente' => 'Compañía de Prueba'],
], 'en');
fullI18nCheck(
    ($sample['message'] ?? '') === 'The photo was deleted from the case file.'
        && ($sample['detail'] ?? '') === 'Method not allowed.'
        && ($sample['data']['cliente'] ?? '') === 'Compañía de Prueba',
    'Las respuestas operativas se traducen sin modificar datos del negocio'
);

$literalCandidates = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/api/desembarques'));
foreach ($iterator as $file) {
    if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    preg_match_all("/'(?:message|detail|error|errors|label|duplicate_reason|import_error)'\\s*=>\\s*'((?:\\\\'|[^'])*)'/u", $contents, $matches);
    foreach ($matches[1] ?? [] as $match) {
        $literal = str_replace("\\'", "'", (string) $match);
        if ($literal !== '') {
            $literalCandidates[$literal] = true;
        }
    }
}
$untranslated = [];
foreach (array_keys($literalCandidates) as $literal) {
    $translated = translateOperationalText($literal, 'en');
    if ($translated === $literal && preg_match('/[áéíóúñ¿¡]|\b(?:Método|sesión|permiso|aviso|desembarque|expediente|fotografía|mercancía|pedimento|Alcance|archivo|solicitud|token|selecciona|captura|indica|válid[oa]|encontró|posible|debe|puede|está|fue|del|para|con)\b/iu', $literal) === 1) {
        $untranslated[] = $literal;
    }
}
if ($untranslated !== []) {
    echo "Mensajes operativos sin traducción exacta:\n - " . implode("\n - ", $untranslated) . "\n";
}
fullI18nCheck($untranslated === [], 'Los mensajes literales de las APIs de Avisos tienen traducción inglesa');

$scriptsPartial = fullI18nContents($root, 'public/partials/scripts.php');
$importPage = fullI18nContents($root, 'public/aviso-importar.php');
$importScript = fullI18nContents($root, 'public/assets/js/aviso-import.js');
$photoScript = fullI18nContents($root, 'public/assets/js/aviso-expediente-photos.js');
$softDeleteScript = fullI18nContents($root, 'public/assets/js/aviso-soft-delete.js');
$alcancesScript = fullI18nContents($root, 'public/assets/js/aviso-expediente-alcances.js');
$serviceWorker = fullI18nContents($root, 'public/service-worker.js');
$tablePage = fullI18nContents($root, 'table/index.html');
$tableI18n = fullI18nContents($root, 'table/i18n.js');
$tableParse = fullI18nContents($root, 'table/parse.php');
$tableParse2 = fullI18nContents($root, 'table/parse2.php');
$expedientePage = fullI18nContents($root, 'public/aviso-expediente.php');
$reportsScript = fullI18nContents($root, 'public/assets/js/reports.js');
$dashboardScript = fullI18nContents($root, 'public/assets/js/dashboard-avisos.js');
$dashboardExportScript = fullI18nContents($root, 'public/assets/js/dashboard-avisos-export.js');
$mailConfig = fullI18nContents($root, 'config/mail.php');

fullI18nCheck(str_contains($scriptsPartial, 'assets/js/i18n-runtime.js?v=42'), 'El runtime bilingüe se carga en las páginas principales');
fullI18nCheck(str_contains($importPage, "'language' => \$currentLanguage") && str_contains($importPage, 'aviso-import.js?v=42'), 'La importación histórica recibe el idioma y usa recursos v42');
fullI18nCheck(str_contains($importScript, 'const text = (es, en)') && str_contains($importScript, 'Open Deleted Notices to restore') && str_contains($importScript, 'Import as new notice (keep deleted record)'), 'La interfaz dinámica del importador incluye traducciones inglesas');
fullI18nCheck(str_contains($photoScript, 'window.AppI18n') && str_contains($photoScript, 'The selected photo is not valid.'), 'La gestión de fotografías es bilingüe');
fullI18nCheck(str_contains($softDeleteScript, 'window.AppI18n') && str_contains($softDeleteScript, 'Processing…'), 'La baja y restauración administrativa son bilingües');
fullI18nCheck(str_contains($alcancesScript, 'window.AppI18n') && str_contains($alcancesScript, 'The addendum could not be saved.'), 'La interfaz de Addenda es bilingüe y consistente');
fullI18nCheck(str_contains($serviceWorker, "CACHE_VERSION = 'v43'") && str_contains($serviceWorker, 'i18n-runtime.js?v=42') && str_contains($serviceWorker, 'SET_APP_LANGUAGE'), 'El service worker v43 precarga el runtime y localiza el modo sin conexión');
fullI18nCheck(str_contains($tablePage, 'i18n.js?v=42') && str_contains($tableI18n, 'Extract line items') && str_contains($tableI18n, 'MutationObserver'), 'El extractor heredado traduce contenido estático y dinámico');
fullI18nCheck(str_contains($tableParse, "No PDF file was received.") && str_contains($tableParse2, "Method not allowed."), 'Los endpoints del extractor devuelven errores bilingües');
fullI18nCheck(str_contains($expedientePage, "'Addenda'") && str_contains($expedientePage, "'DELETE'") && str_contains($expedientePage, "'RESTORE'"), 'El expediente localiza Addenda y las confirmaciones administrativas');
fullI18nCheck(str_contains($reportsScript, 'authorized Spanish wording') && ! str_contains($reportsScript, 'under your worthy responsibility'), 'El formato oficial conserva el texto español autorizado sin traducción legal apócrifa');
fullI18nCheck(
    str_contains((string) ($catalogue['en']['dashboard.form.days_out'] ?? ''), 'Dwell time')
        && ! str_contains($reportsScript, 'Days away')
        && str_contains($expedientePage, 'notice case file'),
    'La terminología logística y de expediente usa inglés profesional'
);
fullI18nCheck(
    str_contains($dashboardScript, "localized('Unknown date', 'Fecha desconocida')")
        && str_contains($dashboardExportScript, "localized('Summary', 'Resumen')")
        && str_contains($dashboardExportScript, "['Notice', 'Aviso'")
        && str_contains($dashboardExportScript, 'exportStatusLabel')
        && str_contains($dashboardExportScript, 'Executive Unloading Notice Report'),
    'Dashboard, PDF y Excel evitan etiquetas de respaldo en español'
);
fullI18nCheck(
    ! str_contains(strtolower($mailConfig), 'maritime landings')
        && ! str_contains($mailConfig, 'status of landing')
        && str_contains($mailConfig, 'maritime unloading operations')
        && ($catalogue['en']['desembarques.email.footer.notice'] ?? '') === 'You received this notification because your email is registered in the Unloading Registry.',
    'Los correos usan terminología de desembarque y metadatos en inglés'
);

echo sprintf("\nResultado: %d OK, %d error(es).\n", count($passes), count($failures));
exit($failures === [] ? 0 : 1);
