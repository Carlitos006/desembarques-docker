<?php
// parse.php — smalot/pdfparser + parser de partidas (págs. 2–19) + cabecera robusta (pág. 1)
// Devuelve JSON compatible con el frontend.

declare(strict_types=1);
@ini_set('display_errors','0');
@ini_set('log_errors','1');
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
if (function_exists('ob_get_level')) { while (ob_get_level()) { @ob_end_clean(); } }

require_once __DIR__ . '/../config/pedimentos.php';
require_once __DIR__ . '/../config/i18n.php';

$tableLanguage = normalizeLanguage((string) ($_GET['lang'] ?? ($_SESSION['language'] ?? 'es')));
function table_translate(string $spanish, string $english): string {
  global $tableLanguage;
  return $tableLanguage === 'en' ? $english : $spanish;
}

/* ---------------- Helpers JSON ---------------- */
function jfail(string $msg, array $extra=[]){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$msg]+$extra, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  exit;
}
function jok(array $data=[]){
  echo json_encode(['ok'=>true]+$data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------------- Config ---------------- */
$config = pedimentos_parser_config();

$PAGE_FROM = is_int($config['page_from']) && $config['page_from'] > 0 ? $config['page_from'] : 2;
$PAGE_TO   = is_int($config['page_to']) && $config['page_to'] > 0 ? $config['page_to'] : 19;

$TMP_DIR = '';
$tmpCandidate = $config['tmp_dir'] ?? '';
if (is_string($tmpCandidate)) {
  $tmpCandidate = trim($tmpCandidate);
  if ($tmpCandidate !== '') {
    $TMP_DIR = rtrim($tmpCandidate, DIRECTORY_SEPARATOR);
  }
}
if ($TMP_DIR === '' || !@is_dir($TMP_DIR)) {
  $TMP_DIR = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
}

$TMP_DIR = '';
$tmpCandidate = $config['tmp_dir'] ?? '';
if (is_string($tmpCandidate)) {
  $tmpCandidate = trim($tmpCandidate);
  if ($tmpCandidate !== '') {
    $TMP_DIR = rtrim($tmpCandidate, DIRECTORY_SEPARATOR);
  }
}
if ($TMP_DIR === '' || !@is_dir($TMP_DIR)) {
  $TMP_DIR = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
}

/* ---------------- Entrada ---------------- */
if (empty($_FILES['pdf']['tmp_name']) || !is_uploaded_file($_FILES['pdf']['tmp_name'])) {
  jfail(table_translate('No se recibió el archivo PDF.', 'No PDF file was received.'), ['_files'=>$_FILES]);
}
$inPdf = realpath($_FILES['pdf']['tmp_name']) ?: $_FILES['pdf']['tmp_name'];

/* ---------------- Texto de págs 2–19 (tabla) ---------------- */
$tableDriverAttempts = [];
$tableExtractionFallbackUsed = false;
$extraction = [];

$preferredConfig = $config;
$preferredConfig['text_driver'] = 'pdftotext';
$preferredConfig['text_driver_source'] = 'default';

$primaryExtractionException = null;

try {
  $extraction = pedimentos_extract_text_from_pdf($inPdf, $PAGE_FROM, $PAGE_TO, $preferredConfig);
  $meta = pedimentos_normalize_text_driver_metadata($extraction);
  $attempt = ['driver' => $meta['driver'] ?? 'pdftotext', 'status' => 'ok'];
  if (!empty($meta['details'])) $attempt['details'] = $meta['details'];
  $tableDriverAttempts[] = $attempt;
} catch (PedimentosTextExtractionException $exception) {
  $primaryExtractionException = $exception;
  $tableDriverAttempts[] = [
    'driver' => 'pdftotext',
    'status' => 'error',
    'message' => $exception->getMessage(),
    'context' => $exception->getContext(),
  ];
} catch (Throwable $exception) {
  $tableDriverAttempts[] = [
    'driver' => 'pdftotext',
    'status' => 'error',
    'message' => $exception->getMessage(),
    'context' => ['exception' => get_class($exception)],
  ];
}

if (!is_array($extraction) || !isset($extraction['text'])) {
  try {
    $extraction = pedimentos_extract_text_from_pdf($inPdf, $PAGE_FROM, $PAGE_TO, $config);
    $meta = pedimentos_normalize_text_driver_metadata($extraction);
    $attempt = ['driver' => $meta['driver'] ?? ($config['text_driver'] ?? null), 'status' => 'ok'];
    if (!empty($meta['details'])) $attempt['details'] = $meta['details'];
    $tableDriverAttempts[] = $attempt;
    $tableExtractionFallbackUsed = true;
  } catch (PedimentosTextExtractionException $exception) {
    $tableDriverAttempts[] = [
      'driver' => $config['text_driver'] ?? 'unknown',
      'status' => 'error',
      'message' => $exception->getMessage(),
      'context' => $exception->getContext(),
    ];
    if ($primaryExtractionException instanceof PedimentosTextExtractionException) {
      $context = $primaryExtractionException->getContext();
      $context['fallback_error'] = $exception->getContext();
      jfail($primaryExtractionException->getMessage(), $context);
    }
    jfail($exception->getMessage(), $exception->getContext());
  } catch (Throwable $exception) {
    jfail(table_translate('Ocurrió un error inesperado al extraer el texto del PDF.', 'An unexpected error occurred while extracting text from the PDF.'), ['error' => $exception->getMessage()]);
  }
}

if (!is_array($extraction)) {
  jfail(table_translate('No se obtuvo una extracción de texto válida.', 'No valid text extraction result was obtained.'), ['table_driver_attempts' => $tableDriverAttempts]);
}

$text = isset($extraction['text']) ? (string)$extraction['text'] : '';
if ($text === '') {
  jfail(table_translate('El texto extraído está vacío.', 'The extracted text is empty.'), ['table_driver_attempts' => $tableDriverAttempts]);
}

$textDriverMetadata = pedimentos_normalize_text_driver_metadata($extraction ?? []);
$textDriver = $textDriverMetadata['driver'];
$textDriverDetails = $textDriverMetadata['details'];

$PDFTOTEXT_REAL = '';
$extractionBinary = $extraction['binary'] ?? null;
if (is_string($extractionBinary)) {
  $candidate = trim($extractionBinary);
  if ($candidate !== '') $PDFTOTEXT_REAL = $candidate;
}
if ($PDFTOTEXT_REAL === '') {
  $configBinary = $config['pdftotext_path'] ?? '';
  if (is_string($configBinary)) {
    $candidate = trim($configBinary);
    if ($candidate !== '') $PDFTOTEXT_REAL = $candidate;
  }
}
if ($PDFTOTEXT_REAL === '') {
  $defaultBinary = $config['pdftotext_default'] ?? '';
  if (is_string($defaultBinary)) {
    $candidate = trim($defaultBinary);
    if ($candidate !== '') $PDFTOTEXT_REAL = $candidate;
  }
}

$PDFTOTEXT_REAL = '';
$extractionBinary = $extraction['binary'] ?? null;
if (is_string($extractionBinary)) {
  $candidate = trim($extractionBinary);
  if ($candidate !== '') $PDFTOTEXT_REAL = $candidate;
}
if ($PDFTOTEXT_REAL === '') {
  $configBinary = $config['pdftotext_path'] ?? '';
  if (is_string($configBinary)) {
    $candidate = trim($configBinary);
    if ($candidate !== '') $PDFTOTEXT_REAL = $candidate;
  }
}
if ($PDFTOTEXT_REAL === '') {
  $defaultBinary = $config['pdftotext_default'] ?? '';
  if (is_string($defaultBinary)) {
    $candidate = trim($defaultBinary);
    if ($candidate !== '') $PDFTOTEXT_REAL = $candidate;
  }
}

/* ---------------- Utilidades comunes ---------------- */
function str_contains_ci(string $h, string $n): bool { return stripos($h,$n)!==false; }

function cutFromHeader(string $t): string {
  $t = normalize_unicode_whitespace($t);
  $cands = [
    '/\bMARCA\s+MODELO\s+C[ÓO]DIGO\s+PRODUCTO\b/i',
    '/\bMARCA\b.*\bMODELO\b.*\bC[ÓO]DIGO\b.*\bPRODUCTO\b/i',
  ];
  foreach ($cands as $p) if (preg_match($p, $t, $m, PREG_OFFSET_CAPTURE)) return substr($t, $m[0][1]);
  return $t;
}

function normalize_unicode_whitespace(string $text): string {
  static $search = null;
  static $replace = null;

  if ($search === null) {
    $search = [
      "\u{00A0}", // nbsp
      "\u{1680}", // ogham space mark
      "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}", "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}",
      "\u{202F}", // narrow no-break space
      "\u{205F}", // medium mathematical space
      "\u{3000}", // ideographic space
    ];

    $replace = array_fill(0, count($search), ' ');

    // zero-width and BOM characters that should be removed entirely
    $search = array_merge($search, ["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"]);
    $replace = array_merge($replace, ['', '', '', '']);
  }

  return str_replace($search, $replace, $text);
}

function splitBlocks(string $t): array {
  $t = normalize_unicode_whitespace($t);
  $t = str_replace(["\r\n","\r"], "\n", $t);
  $t = str_replace("\t", ' ', $t);
  $tmp = preg_replace("/\f+/", "\n", $t);
  if (is_string($tmp)) $t = $tmp;

  /*
   * Layout SAT habitual: la fila de una partida comienza con SEC + fracción + NICO,
   * por ejemplo: "001 56081102 99 ...". Esta ruta debe evaluarse primero para no
   * separar la fracción del SEC que aparece a su izquierda.
   */
  $parts = preg_split('/(?=^\s*\d{1,4}\s+\d{8}\s+\d{2}\b)/m', $t);
  $parts = array_values(array_filter(
    $parts,
    fn($p) => preg_match('/^\s*\d{1,4}\s+\d{8}\s+\d{2}\b/', $p) === 1
  ));
  if (!empty($parts)) return $parts;

  /*
   * smalot/pdfparser puede concatenar la fracción de 8 dígitos y el SEC de
   * 3 dígitos: "56081102001 99 ...". Es el formato esperado en hosting
   * compartido cuando pdftotext/exec no están disponibles.
   */
  $parts = preg_split('/(?=^\s*\d{11}\s+\d{2}\b)/m', $t);
  $parts = array_values(array_filter(
    $parts,
    fn($p) => preg_match('/^\s*\d{11}\s+\d{2}\b/', $p) === 1
  ));
  if (!empty($parts)) return $parts;

  /* Compatibilidad con PDFs donde la fila inicia directamente con la fracción. */
  $tmp = preg_replace('/(?<!\n)(\d{8}\s+\d{2}\b)/', "\n$1", $t);
  if (is_string($tmp)) $t = $tmp;

  $parts = preg_split('/(?=^\s*\d{8}\s+\d{2}\b)/m', $t);
  $parts = array_values(array_filter($parts, fn($p)=>preg_match('/^\s*\d{8}\s+\d{2}\b/', $p)));
  if (!empty($parts)) return $parts;

  if (preg_match_all('/(\d{8}\s+\d{2}.*?)(?=\n\s*\d{8}\s+\d{2}\b|$)/s', $t, $matches)) {
    return array_values(array_filter(array_map(fn($b)=>trim($b), $matches[1]), fn($b)=>$b!==''));
  }

  if (preg_match_all('/(SEC\s*\d{1,4}.*?)(?=\n\s*SEC\s*\d{1,4}\b|$)/is', $t, $matches)) {
    return array_values(array_filter(array_map(fn($b)=>trim($b), $matches[1]), fn($b)=>$b!==''));
  }

  return [];
}

function firstNumberHereOrNext(array $lines, int $i, int $look=3): ?string {
  if (preg_match('/\b\d{1,3}(?:[.,]\d{3})*(?:\.\d+)?\b/u', $lines[$i], $m)) return str_replace(',','',$m[0]);
  for ($k=1; $k<=$look; $k++) {
    $j=$i+$k; if (!isset($lines[$j])) break;
    if (preg_match('/\b\d{1,3}(?:[.,]\d{3})*(?:\.\d+)?\b/u', $lines[$j], $m)) return str_replace(',','',$m[0]);
  }
  return null;
}

function clean_description(string $line, ?string &$secRef = null): string {
  $s = trim(normalize_unicode_whitespace($line));

  if ($s === '') return '';

  if (preg_match('/^SEC\s*(\d{1,3})(?:\s*[-:])?\s*(.*)$/iu', $s, $m)) {
    if ($secRef === null) $secRef = $m[1];
    $s = $m[2] !== '' ? $m[2] : '';
  }

  if (preg_match('/^(\d{1,3})\s+(.*)$/u', $s, $m)) {
    if ($secRef === null) $secRef=$m[1];
    $s=$m[2];
  }

  if ($s === '') return '';

  $s = preg_split('/\b(IGI|IVA)\b/u', $s)[0];
  $s = preg_replace('/\s+\d+(?:\.\d+)?(?:\s+\d+){0,6}\s*$/u', '', $s);
  return trim(preg_replace('/\s{2,}/', ' ', $s));
}

/* ---------------- Cabecera: helpers ---------------- */
function normalize_label_scan(string $txt): string {
  $n = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$txt);
  $n = strtolower(preg_replace('/\s+/', ' ', trim($n)));
  return $n;
}
function hasRazonLabel(string $txt): bool {
  // Versión estricta para evitar falsos positivos
  return preg_match('/nombre[, ]+denominacion\s*o\s*razon\s*social\s*:/', normalize_label_scan($txt)) === 1;
}

/**
 * Extrae num_pedimento, cve_pedimento, razon_social
 * y FECHAS {entrada,pago} desde el texto de cabecera.
 * Prioriza razón social a la DERECHA de "CURP: ...."
 */
function extractHeaderFields(string $txt): array {
  $orig  = normalize_unicode_whitespace($txt);
  $orig  = str_replace(["\r\n","\r"], "\n", $orig);
  $lines = preg_split("/\n+/", $orig);

  $norm = fn(string $s)=> normalize_label_scan($s);
  $nlines = array_map($norm, $lines);

  $numPed = null; $cvePed = null; $razon = null;
  $fechaEntrada = null; $fechaPago = null;

  // NUM. PEDIMENTO y CVE. PEDIMENTO
  if (preg_match('/num\.\s*pedimento[:\s]*([0-9][0-9\s]{6,})/i', $orig, $m)) $numPed = preg_replace('/\D/','', $m[1]);
  if (preg_match('/cve\.\s*pedimento[:\s]*([A-Z0-9]{1,3})/i', $orig, $m)) $cvePed = strtoupper($m[1]);

  // ---- Razón social (prioridad CURP:)
  $label = 'nombre, denominacion o razon social:';
  $idx = -1;
  foreach ($nlines as $i => $nl) { if (strpos($nl, $label) !== false) { $idx = $i; break; } }

  if ($idx >= 0) {
    $isStop = function(string $raw): bool {
      $r = trim($raw);
      $labels = [
        '/^clave\s+en\s+el\s+rfc[:\s]/i','/^curp[:\s]/i','/^domicilio[:\s]/i',
        '/^datos\s+del/i','/^valor/i','/^precio/i','/^medios\s+de\s+transporte/i',
        '/^regimen/i','/^aduana/i','/^tasas/i','/^cuadro\s+de\s+liquidacion/i',
      ];
      foreach ($labels as $p) { if (preg_match($p, $r)) return true; }
      return false;
    };

    // 1) CURP: <razón social a la derecha>
    for ($j = $idx + 1; $j <= min($idx + 5, count($lines)-1); $j++) {
      $raw = trim($lines[$j]); if ($raw==='') continue;
      if (preg_match('/^CURP[:\s]+(.+)$/i', $raw, $m)) {
        $candidate = trim($m[1]);
        $candidate = preg_replace('/\s{2,}/',' ', $candidate);
        $candidate = preg_replace('/\s+(DOMICILIO|DATOS DEL|VALOR|PRECIO|MEDIOS DE TRANSPORTE|CUADRO DE LIQUIDACION).*/i', '', $candidate);
        if ($candidate !== '') { $razon = $candidate; break; }
      }
    }
    // 2) primera línea “candidata” (no rótulo ni RFC)
    if ($razon === null) {
      for ($j = $idx + 1; $j <= min($idx + 5, count($lines)-1); $j++) {
        $raw = trim($lines[$j]); if ($raw==='') continue;
        if ($isStop($raw)) {
          if (preg_match('/^CURP[:\s]+(.+)$/i', $raw, $m)) {
            $cand = trim(preg_replace('/\s{2,}/',' ', $m[1]));
            if ($cand !== '') $razon = $cand;
          }
          break;
        }
        if (preg_match('/rfc/i', $raw)) continue;
        if (preg_match('/^[A-ZÁÉÍÓÚÑ0-9 ]{0,15}:\s*/u', $raw)) continue;
        $candidate = preg_replace('/\s{2,}/',' ', $raw);
        if ($candidate !== '') { $razon = $candidate; break; }
      }
    }
  }

  // ---- FECHAS (ENTRADA / PAGO)
  // buscamos el bloque "FECHAS" y escaneamos unas líneas debajo
  $idxFechas = -1;
  foreach ($nlines as $i => $nl) {
    if (strpos($nl, 'fechas') !== false) { $idxFechas = $i; break; }
  }
  if ($idxFechas >= 0) {
    for ($j = $idxFechas; $j <= min($idxFechas + 8, count($lines)-1); $j++) {
      $raw = trim($lines[$j]); if ($raw==='') continue;

      // ENTADA  dd/mm/yyyy
      if ($fechaEntrada === null) {
        if (preg_match('/\bENTRADA\b[:\s]*([0-3]?\d[\/\-][01]?\d[\/\-]\d{2,4})/i', $raw, $m)) {
          $fechaEntrada = $m[1];
        }
      }
      // PAGO  dd/mm/yyyy  (puede estar en la siguiente línea)
      if ($fechaPago === null) {
        if (preg_match('/\bPAGO\b[:\s]*([0-3]?\d[\/\-][01]?\d[\/\-]\d{2,4})/i', $raw, $m)) {
          $fechaPago = $m[1];
        }
      }

      // También contempla el formato "PAGO" en una línea y fecha en la siguiente
      if ($fechaPago === null && preg_match('/^\s*PAGO\b[:\s]*$/i', $raw)) {
        $next = $lines[$j+1] ?? '';
        if (preg_match('/([0-3]?\d[\/\-][01]?\d[\/\-]\d{2,4})/', $next, $m2)) {
          $fechaPago = $m2[1];
        }
      }

      if ($fechaEntrada && $fechaPago) break;
    }
  }

  /*
   * Fallback para smalot/pdfparser. En hosting compartido el orden visual del
   * PDF puede quedar separado de sus etiquetas, aunque los valores sí estén
   * presentes en el texto.
   */
  if (!$numPed && preg_match('/\b(\d{2})\s+(\d{2})\s+(\d{4})\s+(\d{7})\b/u', $orig, $m)) {
    $numPed = $m[1] . $m[2] . $m[3] . $m[4];
  }

  if (!$cvePed || preg_match('/^(REG|IMP|IMD)$/i', $cvePed)) {
    if (preg_match('/^\s*([A-Z][0-9])\s*$/m', $orig, $m)) {
      $cvePed = strtoupper($m[1]);
    }
  }

  $razonInvalida = !$razon || preg_match('/\b(SEGUROS|FLETES|INCREMENTABLES|NOMBRE|DOMICILIO)\b/i', $razon);
  if ($razonInvalida && preg_match('/\b[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}\b[\s\r\n]+([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ0-9 .,&()\-]{4,})/u', $orig, $m)) {
    $candidate = trim(preg_split('/\R/u', trim($m[1]))[0] ?? '');
    if ($candidate !== '') $razon = preg_replace('/\s{2,}/u', ' ', $candidate);
  }

  if (!$fechaEntrada || !$fechaPago) {
    preg_match_all('/\b\d{2}\/\d{2}\/\d{4}\b/', $orig, $dateMatches);
    $dates = [];
    foreach ($dateMatches[0] ?? [] as $date) {
      if (!in_array($date, $dates, true)) $dates[] = $date;
    }
    if (!$fechaEntrada && isset($dates[0])) $fechaEntrada = $dates[0];
    if (!$fechaPago && isset($dates[1])) $fechaPago = $dates[1];
  }

  return [
    'num_pedimento' => $numPed,
    'cve_pedimento' => $cvePed,
    'razon_social'  => $razon,
    'fecha_entrada' => $fechaEntrada,
    'fecha_pago'    => $fechaPago,
  ];
}

/* ---------------- Parser de partida ---------------- */
function parsePartida(string $block): array {
  $out = [
    'sec'=>null,'descripcion'=>null,
    'fraccion8'=>null,'fraccion2'=>null,'modelo_cols'=>null,
    'umc_cant'=>null,'umc_unidad'=>null,'umt_pu'=>null,'umt_unidad'=>null,
    'pais'=>null,'marca'=>null,'igi_tasa'=>null,'iva_tasa'=>null,
    'id_1'=>null,'id_2'=>null,'valor_ref'=>null,
    'identif'=>null,'comp1'=>null,'comp2'=>null,'comp3'=>null
  ];
  $normalizedBlock = normalize_unicode_whitespace($block);
  $lines = array_values(array_filter(array_map(
    fn($s)=>preg_replace('/[ \t]+/',' ',trim($s)),
    preg_split("/\n+/", $normalizedBlock)
  ), fn($s)=>$s!==''));
  if (empty($lines)) return $out;

  $number = '[0-9][0-9,]*(?:\.[0-9]+)?';
  $smalotRowPattern = '/^(\d{8})(\d{3})\s+(\d{2})\s+(\d)\s+(\d)\s+(\d)\s+('.$number.')\s+(\d)\s+('.$number.')\s+([A-Z]{3})\s+([A-Z]{2,4})(.*)$/';
  $rowPattern = '/^(?:(\d{1,4})\s+)?(\d{8})\s+(\d{2})\s+(\d)\s+(\d)\s+(\d)\s+('.$number.')\s+(\d)\s+('.$number.')\s+([A-Z]{3})\s+([A-Z]{2,4})(.*)$/';

  if (preg_match($smalotRowPattern, $lines[0], $m)) {
    $out['fraccion8']=$m[1];
    $out['sec']=str_pad($m[2], 3, '0', STR_PAD_LEFT);
    $out['fraccion2']=$m[3];
    $out['modelo_cols']=$m[6];
    $out['umc_cant']=str_replace(',', '', $m[7]);
    $out['umc_unidad']=$m[8];
    $out['umt_pu']=str_replace(',', '', $m[9]);
    $out['pais']=$m[10];
    $out['marca']=$m[11];
    $tail = strtoupper($m[12]);
    if (str_contains_ci($tail,'IGI') && preg_match('/IGI[^0-9]*([\d\.]+)/i', $tail, $mm)) $out['igi_tasa']=$mm[1];
    if (str_contains_ci($tail,'IVA') && preg_match('/IVA[^0-9]*([\d\.]+)/i', $tail, $mm)) $out['iva_tasa']=$mm[1];
  } elseif (preg_match($rowPattern, $lines[0], $m)) {
    if (($m[1] ?? '') !== '') $out['sec'] = str_pad($m[1], 3, '0', STR_PAD_LEFT);
    $out['fraccion8']=$m[2]; $out['fraccion2']=$m[3];
    $out['modelo_cols']=$m[6];
    $out['umc_cant']=str_replace(',', '', $m[7]);
    $out['umc_unidad']=$m[8];
    $out['umt_pu']=str_replace(',', '', $m[9]);
    $out['pais']=$m[10]; $out['marca']=$m[11];
    $tail = strtoupper($m[12]);
    if (str_contains_ci($tail,'IGI') && preg_match('/IGI[^0-9]*([\d\.]+)/i', $tail, $mm)) $out['igi_tasa']=$mm[1];
    if (str_contains_ci($tail,'IVA') && preg_match('/IVA[^0-9]*([\d\.]+)/i', $tail, $mm)) $out['iva_tasa']=$mm[1];
  }

  foreach ($lines as $line) {
    if ($out['sec'] === null && preg_match('/\bSEC\s*[:\-]?\s*(\d{1,4})\b/i', $line, $m)) {
      $out['sec'] = $m[1];
    }
  }

  for ($i=1; $i<count($lines); $i++) {
    $li = $lines[$i];
    if (preg_match('/^SEC\b/i', $li)) {
      $desc = clean_description($li, $out['sec']);
      if ($desc !== '') $out['descripcion'] = $desc;
      continue;
    }
    if (preg_match('/^(IDENTIF|COMPLEMENTO|UMT|UMC|PA[IÍ]S|MARCA|MODELO)\b/i', $li)) continue;
    if (preg_match('/^\d+(?:\s+\d+){1,3}$/', $li)) continue;
    if (!preg_match('/[A-ZÁÉÍÓÚÑ]/iu', $li)) continue;

    $desc = clean_description($li, $out['sec']);
    if ($desc === '') continue;

    $out['descripcion'] = $desc;
    if ($out['sec'] === null && $i>0 && preg_match('/^\d{1,3}$/', $lines[$i-1])) $out['sec']=$lines[$i-1];
    elseif ($out['sec'] === null && $i+1<count($lines) && preg_match('/^\d{1,3}$/', $lines[$i+1])) $out['sec']=$lines[$i+1];
    break;
  }

  foreach ($lines as $li) {
    if (preg_match('/^(\d+)\s+(\d+)\s+(\d+(?:\.\d+)?)/', $li, $m)) { $out['id_1']=$m[1]; $out['id_2']=$m[2]; $out['valor_ref']=$m[3]; break; }
  }
  foreach ($lines as $li) {
    if (preg_match('/^IDENTIF\.\s+([A-Z0-9\-]+)/i', $li, $m)) $out['identif']=$m[1];
    if (preg_match('/^COMPLEMENTO\s*1\s+([A-Z0-9\-]+)/i', $li, $m)) $out['comp1']=$m[1];
    if (preg_match('/^COMPLEMENTO\s*2\s+([A-Z0-9\-]+)/i', $li, $m)) $out['comp2']=$m[1];
    if (preg_match('/^COMPLEMENTO\s*3\s+([A-Z0-9\-]+)/i', $li, $m)) $out['comp3']=$m[1];
  }
  for ($i=0; $i<count($lines); $i++) {
    $u = strtoupper($lines[$i]);
    if ($out['igi_tasa']===null && str_contains_ci($u,'IGI')) $out['igi_tasa']=firstNumberHereOrNext($lines,$i,3);
    if ($out['iva_tasa']===null && str_contains_ci($u,'IVA')) $out['iva_tasa']=firstNumberHereOrNext($lines,$i,3);
    if ($out['igi_tasa'] && $out['iva_tasa']) break;
  }
  foreach ($lines as $li) if (preg_match('/\bUMT\s+(\d+)\b/i', $li, $m)) { $out['umt_unidad']=$m[1]; break; }

  return $out;
}

/* ---------------- Parseo de tabla ---------------- */
$header_found = (bool)preg_match('/\bMARCA\b.*\bMODELO\b.*\bC[ÓO]DIGO\b.*\bPRODUCTO\b/i', $text);
$text_from    = cutFromHeader($text);
$blocks       = splitBlocks($text_from);

$items = [];
foreach ($blocks as $b) {
  $it = parsePartida($b);
  if ($it['fraccion8'] && $it['descripcion']) $items[] = $it;
}

/* ---------------- Filtrado por SEC (opcional) ---------------- */
$sec_filter = [];
if (!empty($_POST['sec_filter'])) $sec_filter = array_map('intval', explode(',', $_POST['sec_filter']));
if (!empty($sec_filter)) {
  $items = array_values(array_filter($items, fn($it)=> $it['sec'] !== null && in_array((int)$it['sec'], $sec_filter, true)));
}

/* ---------------- Cabecera con estrategia escalonada ---------------- */
$headerStrategy = '';
$headerText = '';

$try = function($args) use ($PDFTOTEXT_REAL,$inPdf,$TMP_DIR): string {
  if (!function_exists('exec')) return '';

  if (is_array($args)) {
    $parts = [];
    foreach ($args as $part) {
      if ($part === null) continue;
      $part = trim((string)$part);
      if ($part !== '') $parts[] = $part;
    }
    $args = implode(' ', $parts);
  } else {
    $args = trim((string)$args);
  }

  if ($args === '' || !$PDFTOTEXT_REAL || !$inPdf || !$TMP_DIR) return '';

  $tmp = tempnam($TMP_DIR, 'pdftxt_hdr_');
  if ($tmp === false) return '';
  $cmd = escapeshellarg($PDFTOTEXT_REAL).' '.$args.' '.escapeshellarg($inPdf).' '.escapeshellarg($tmp);
  $o=[]; $e=0; @exec($cmd,$o,$e);
  if (($e!==0 || !file_exists($tmp) || filesize($tmp)===0)) { @unlink($tmp); return ''; }
  $t = file_get_contents($tmp); @unlink($tmp); return $t ?: '';
};

$steps = [
  ['strategy'=>'layout_1_2',        'args'=>'-q -layout -nopgbrk -enc UTF-8 -f 1 -l 2'],
  ['strategy'=>'raw_1_3',           'args'=>'-q -raw    -enc UTF-8 -f 1 -l 3'],
  // región inferior aprox. donde va “Datos del importador/…”
  ['strategy'=>'layout_region_bot', 'args'=>'-q -layout -enc UTF-8 -f 1 -l 1 -x 0 -y 260 -W 2000 -H 1200'],
  ['strategy'=>'raw_region_bot',    'args'=>'-q -raw    -enc UTF-8 -f 1 -l 1 -x 0 -y 260 -W 2000 -H 1200'],
  ['strategy'=>'raw_1_5',           'args'=>'-q -raw    -enc UTF-8 -f 1 -l 5'],
];

$headerFields = ['num_pedimento'=>null,'cve_pedimento'=>null,'razon_social'=>null,'fecha_entrada'=>null,'fecha_pago'=>null];

foreach ($steps as $st) {
  $args = null;
  $strategyName = '';
  if (is_array($st)) {
    $args = $st['args'] ?? '';
    $strategyName = $st['strategy'] ?? '';
  } elseif (is_string($st)) {
    $args = $st;
  } else {
    continue;
  }

  $txt = $try($args);
  if ($txt === '') continue;

  if (!hasRazonLabel($txt)) {
    if ($headerText === '') { $headerText = $txt; $headerStrategy = $strategyName.' (no-label)'; }
    // aunque no esté el rótulo, intenta FECHAS (a veces ese bloque sí aparece)
    $tmpFields = extractHeaderFields($txt);
    if ($tmpFields['fecha_entrada'] || $tmpFields['fecha_pago']) {
      $headerFields['fecha_entrada'] = $headerFields['fecha_entrada'] ?: $tmpFields['fecha_entrada'];
      $headerFields['fecha_pago']    = $headerFields['fecha_pago']    ?: $tmpFields['fecha_pago'];
    }
    continue;
  }

  $fields = extractHeaderFields($txt);
  $hasAny = $fields['num_pedimento'] || $fields['cve_pedimento'] || $fields['razon_social'] || $fields['fecha_pago'];
  if ($hasAny) {
    $headerText     = $txt;
    $headerFields   = array_merge($headerFields, array_filter($fields, fn($v)=>$v!==null));
    $headerStrategy = $strategyName;
    // si ya conseguimos razón social y fecha de pago, salimos
    if (!empty($headerFields['razon_social']) && !empty($headerFields['fecha_pago'])) break;
  }
}

// fallback final si no hubo ningún texto “válido”
if ($headerText === '') {
  $last = end($steps);
  $fallbackArgs = null;
  $fallbackStrategy = '';
  if (is_array($last)) {
    $fallbackArgs = $last['args'] ?? null;
    $fallbackStrategy = $last['strategy'] ?? '';
  } elseif (is_string($last)) {
    $fallbackArgs = $last;
  }
  if ($fallbackArgs) {
    $txt = $try($fallbackArgs);
    if ($txt !== '') { $headerText = $txt; $headerStrategy = $fallbackStrategy.' (fallback)'; }
  }
}

if ($headerText === '' && pedimentos_pdfparser_available()) {
  try {
    $headerConfig = $config;
    $headerConfig['text_driver'] = 'smalot';
    $headerConfig['text_driver_source'] = 'fallback';
    $smalotHeader = pedimentos_extract_text_from_pdf($inPdf, 1, 1, $headerConfig);
    $smalotText = is_array($smalotHeader) && isset($smalotHeader['text']) ? (string)$smalotHeader['text'] : '';
    if (trim($smalotText) !== '') {
      $fields = extractHeaderFields($smalotText);
      $headerFields = array_merge($headerFields, array_filter($fields, fn($v)=>$v!==null));
      $headerText = $smalotText;
      $headerStrategy = 'smalot_page_1';
    }
  } catch (PedimentosTextExtractionException $e) {
    // ignore, we already attempted header extraction
  } catch (Throwable $e) {
    // ignore unexpected fallback errors
  }
}

/* ---------------- Respuesta ---------------- */
jok([
  'count'=>count($items),
  'items'=>$items,
  'header'=>$headerFields,
  'text_driver'=>$textDriver,
  'text_driver_details'=>$textDriverDetails,
  'debug'=>[
    'text_driver'=>$textDriver,
    'text_driver_details'=>$textDriverDetails,
    'header_found'=>$header_found,
    'preview'=>substr($text_from, 0, 1200),
    'table_blocks_detected'=>count($blocks),
    'table_driver_attempts'=>$tableDriverAttempts,
    'table_fallback_used'=>$tableExtractionFallbackUsed,
    'header_strategy'=>$headerStrategy,
    'header_preview'=> substr($headerText ?? '', 0, 4000)
  ]
]);
