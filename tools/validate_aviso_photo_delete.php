<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$passes = 0;

function photoDeleteContents(string $root, string $relative): string
{
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
}

function photoDeleteCheck(bool $condition, string $label, array &$failures, int &$passes): void
{
    if ($condition) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }

    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
}

echo "=== AVISO · ELIMINACIÓN DE FOTOGRAFÍAS ===\n";

$endpoint = photoDeleteContents($root, 'api/desembarques/aviso/photo_delete.php');
$page = photoDeleteContents($root, 'public/aviso-expediente.php');
$script = photoDeleteContents($root, 'public/assets/js/aviso-expediente-photos.js');
$serviceWorker = photoDeleteContents($root, 'public/service-worker.js');

photoDeleteCheck(
    str_contains($endpoint, "REQUEST_METHOD")
        && str_contains($endpoint, "!== 'POST'")
        && str_contains($endpoint, 'aviso_require_internal_user')
        && str_contains($endpoint, 'validate_csrf_token')
        && str_contains($endpoint, 'HTTP_X_CSRF_TOKEN'),
    'Endpoint exige POST, sesión interna y CSRF por formulario o cabecera',
    $failures,
    $passes
);
photoDeleteCheck(
    str_contains($endpoint, 'deleted_at IS NULL')
        && str_contains($endpoint, 'FOR UPDATE')
        && str_contains($endpoint, 'begin_transaction')
        && str_contains($endpoint, 'commit()'),
    'Eliminación bloquea expediente activo dentro de una transacción',
    $failures,
    $passes
);
photoDeleteCheck(
    str_contains($endpoint, 'DELETE FROM desembarque_aviso_images')
        && str_contains($endpoint, 'DELETE FROM desembarque_files')
        && str_contains($endpoint, 'remaining_file_uses')
        && str_contains($endpoint, 'delete_stored_file'),
    'Asociación y archivo se retiran sin borrar archivos compartidos',
    $failures,
    $passes
);
photoDeleteCheck(
    str_contains($endpoint, "'delete', 'desembarque_aviso_photo'")
        && str_contains($endpoint, "'sha256'")
        && str_contains($endpoint, 'INSERT INTO audit_logs'),
    'Auditoría conserva identidad y huella de la fotografía',
    $failures,
    $passes
);
photoDeleteCheck(
    str_contains($page, 'data-photo-delete')
        && str_contains($page, 'data-csrf-token')
        && str_contains($page, 'name="csrf_token"')
        && str_contains($page, "deleteEndpoint: '../api/desembarques/aviso/photo_delete.php'")
        && str_contains($page, 'aviso-expediente-photos.js?v=42'),
    'Expediente muestra botón con token local y configura el endpoint',
    $failures,
    $passes
);
photoDeleteCheck(
    str_contains($script, "closest('[data-photo-delete]')")
        && str_contains($script, "body.append('photo_id'")
        && str_contains($script, "button.dataset.csrfToken")
        && str_contains($script, "'X-CSRF-Token': csrfToken")
        && str_contains($script, "redirectUrl.searchParams.set('id', desembarqueId)")
        && str_contains($script, "redirectUrl.searchParams.set('tab', 'fotos')")
        && ! str_contains($script, 'encodeURIComponent(root.desembarqueId)')
        && str_contains($script, 'Los PDFs históricos o ya emitidos no serán modificados.')
        && strpos($script, "closest('[data-photo-delete]')") < strpos($script, 'if (!form || !config.enabled'),
    'Cliente confirma, elimina la foto y regresa al mismo expediente en Fotos',
    $failures,
    $passes
);
photoDeleteCheck(
    str_contains($serviceWorker, "CACHE_VERSION = 'v43'")
        && str_contains($serviceWorker, "'./assets/js/aviso-expediente-photos.js?v=42'"),
    'Service worker actualizado a v43 y cache-buster de fotos conservado',
    $failures,
    $passes
);

echo PHP_EOL . 'Checks PASS: ' . $passes . PHP_EOL;
if ($failures !== []) {
    echo 'Fallos: ' . count($failures) . PHP_EOL;
    echo 'RESULTADO GLOBAL: FAIL' . PHP_EOL;
    exit(1);
}

echo 'RESULTADO GLOBAL: PASS' . PHP_EOL;
