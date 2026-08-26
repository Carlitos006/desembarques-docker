<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$passes = 0;

function partialClosureContents(string $root, string $relative): string
{
    $contents = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($contents) ? $contents : '';
}

function partialClosureHasAll(string $contents, array $needles): bool
{
    foreach ($needles as $needle) {
        if (! str_contains($contents, $needle)) {
            return false;
        }
    }
    return true;
}

function partialClosureCheck(bool $condition, string $label, array &$failures, int &$passes): void
{
    if ($condition) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }

    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
}

echo "=== CIERRE DOCUMENTAL PARCIAL DE MERCANCÍAS ===\n";

$migration = partialClosureContents($root, 'database/migrations/20260826_merchandise_partial_closure.sql');
partialClosureCheck(
    partialClosureHasAll($migration, [
        'closure_status VARCHAR(20)',
        'closure_completed_at DATETIME NULL',
        'closure_completed_by INT UNSIGNED NULL',
        'idx_item_finalizations_closure',
        'fk_item_finalizations_completed_by',
        'ON DELETE SET NULL',
        "closure_status = 'complete'",
    ]) && ! str_contains(strtolower($migration), 'from information_schema'),
    'Migración aditiva sin information_schema y con cierre completo para históricos',
    $failures,
    $passes
);

$createEndpoint = partialClosureContents($root, 'api/desembarques/aviso/merchandise_finalize.php');
partialClosureCheck(
    partialClosureHasAll($createEndpoint, [
        'aviso_require_internal_user',
        'validate_csrf_token',
        "['partial', 'complete']",
        '$isPartialClosure',
        'closure_status, closure_completed_at, closure_completed_by',
        "'closure_status' => \$closureStatus",
    ]),
    'Nueva salida valida rol/CSRF y guarda cierre parcial o completo',
    $failures,
    $passes
);
partialClosureCheck(
    str_contains($createEndpoint, 'if (! $isPartialClosure')
        && str_contains($createEndpoint, 'Para un cierre parcial, indica qué pedimentos o documentos están pendientes')
        && str_contains($createEndpoint, '$closureCompletedAt = $isPartialClosure ? null'),
    'El cierre parcial permite pedimentos pendientes, exige nota y permanece abierto',
    $failures,
    $passes
);

$completeEndpoint = partialClosureContents($root, 'api/desembarques/aviso/merchandise_finalization_complete.php');
partialClosureCheck(
    partialClosureHasAll($completeEndpoint, [
        "!== 'POST'",
        'aviso_require_internal_user',
        'validate_csrf_token',
        'FOR UPDATE',
        "closure_status = 'complete'",
        'closure_completed_at = ?',
        'complete_documentary_closure',
    ]),
    'Completar cierre exige POST, usuario interno, CSRF, bloqueo y auditoría',
    $failures,
    $passes
);
partialClosureCheck(
    str_contains($completeEndpoint, 'UPDATE desembarque_item_finalization_lines SET')
        && ! str_contains($completeEndpoint, 'INSERT INTO desembarque_item_finalization_lines')
        && ! preg_match('/SET\s+quantity_exported\s*=/i', $completeEndpoint),
    'Completar pedimentos actualiza el movimiento original sin duplicar ni cambiar cantidades',
    $failures,
    $passes
);

$page = partialClosureContents($root, 'public/aviso-expediente.php');
partialClosureCheck(
    partialClosureHasAll($page, [
        'name="closure_status"',
        'Cierre parcial — pedimentos pendientes',
        'data-finalization-complete',
        'merchandiseFinalizationCompleteModal',
        'data-completion-line',
        "merchandiseFinalizationCompleteEndpoint: '../api/desembarques/aviso/merchandise_finalization_complete.php'",
        'aviso-expediente-merchandise.js?v=43',
    ]),
    'Expediente ofrece selección, estado visible y modal para completar pedimentos',
    $failures,
    $passes
);

$script = partialClosureContents($root, 'public/assets/js/aviso-expediente-merchandise.js');
partialClosureCheck(
    partialClosureHasAll($script, [
        'selectedClosureStatus',
        "closureStatus === 'complete' && !hasCustomsData",
        'closure_status: closureStatus',
        'merchandiseFinalizationCompleteEndpoint',
        'line_id:',
        "reloadMerchandise('closure_completed')",
    ]),
    'Cliente valida cada modalidad y completa el cierre sobre las mismas líneas',
    $failures,
    $passes
);

$translations = partialClosureContents($root, 'config/operational_i18n.php');
partialClosureCheck(
    partialClosureHasAll($translations, [
        'The documentary closure type is not valid.',
        'The documentary closure could not be completed.',
        'without changing the departure quantities.',
    ]),
    'Mensajes de servidor cuentan con inglés profesional',
    $failures,
    $passes
);

$serviceWorker = partialClosureContents($root, 'public/service-worker.js');
partialClosureCheck(
    str_contains($serviceWorker, "CACHE_VERSION = 'v43'")
        && str_contains($serviceWorker, "'./assets/js/aviso-expediente-merchandise.js?v=43'"),
    'Service worker y recurso de mercancías actualizados a v43',
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
