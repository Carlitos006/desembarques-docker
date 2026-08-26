<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/i18n.php';
$tableLanguage = normalizeLanguage((string) ($_GET['lang'] ?? ($_SESSION['language'] ?? 'es')));
function table2_translate(string $spanish, string $english): string {
    global $tableLanguage;
    return $tableLanguage === 'en' ? $english : $spanish;
}

try {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>table2_translate('Falta vendor/autoload.php. Ejecuta: composer require smalot/pdfparser', 'The PDF parser dependency is missing. Run: composer require smalot/pdfparser')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require $autoload;
    require __DIR__ . '/pedimentos.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>table2_translate('Método no permitido.', 'Method not allowed.')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!isset($_FILES['pdf']) || !is_array($_FILES['pdf'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>table2_translate('No se recibió el archivo PDF.', 'No PDF file was received.')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)($_FILES['pdf']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>table2_translate('Ocurrió un error al cargar el archivo.', 'An error occurred while uploading the file.')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tmpName = $_FILES['pdf']['tmp_name'];
    $target  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ped_', true) . '.pdf';
    if (!move_uploaded_file($tmpName, $target)) {
        if (!copy($tmpName, $target)) {
            throw new RuntimeException(table2_translate('No fue posible almacenar el PDF temporalmente.', 'The PDF could not be stored temporarily.'));
        }
    }

    // Filtro opcional: sec_filter="1,2,3"
    $secFilter = null;
    if (!empty($_POST['sec_filter'])) {
        $secFilter = array_values(array_filter(array_map('intval', explode(',', (string)$_POST['sec_filter']))));
    }

    $config = pedimentos_parser_config(); // smalot
    $text   = pedimentos_extract_text($target, $config);
    $all    = pedimentos_parse_all($text, $secFilter);

    echo json_encode([
        'ok'     => true,
        'header' => $all['header'],   // tu index.html usa 'header'
        'items'  => $all['items'],    // y 'items'
        'debug'  => [
            'text_driver' => 'smalot',
            'bytes'       => @filesize($target) ?: null,
            'secs_count'  => count($all['items']),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
