<?php
declare(strict_types=1);

/**
 * Parser pedimentos con smalot/pdfparser (sin pdftotext)
 * - Header robusto
 * - Items por dos rutas:
 *    A) Bloques por fracción (######## ##) + línea de descripción que termina en SEC
 *    B) Fallback por "SEC <n>" en cualquier parte (reconstruye descripción y fracción cercanas)
 *
 * Requiere: composer require smalot/pdfparser
 *           require __DIR__ . '/vendor/autoload.php';
 */

class PedimentosTextExtractionException extends \RuntimeException {}
class PedimentosParseException extends \RuntimeException {}

function pedimentos_parser_config(): array {
    return ['driver' => 'smalot', 'details' => []];
}

function pedimentos_extract_text(string $pdfPath, array $config): string {
    if (!is_file($pdfPath)) {
        throw new RuntimeException("No existe el archivo PDF: {$pdfPath}");
    }
    $parser = new \Smalot\PdfParser\Parser();
    $pdf    = $parser->parseFile($pdfPath);

    $all = '';
    foreach ($pdf->getPages() as $page) {
        $t = (string)$page->getText();
        if (mb_strlen(trim($t)) < 20) {                 // fallback posicional si la página viene pobre
            $tm  = $page->getDataTm();                  // [[matrix, "texto"], ...]
            $buf = [];
            foreach ($tm as $row) {
                $frag = isset($row[1]) ? (string)$row[1] : '';
                if ($frag !== '') $buf[] = $frag;
            }
            $t = implode(' ', $buf);
        }
        $all .= "\n".$t;
    }

    if ($all !== '' && !mb_detect_encoding($all, 'UTF-8', true)) {
        $all = mb_convert_encoding($all, 'UTF-8', 'auto');
    }
    $all = preg_replace("/\r\n|\r/", "\n", $all) ?? $all;
    $all = preg_replace("/[ \t]{2,}/", " ", $all) ?? $all; // compacta espacios duplicados, respeta saltos
    return trim($all);
}


/** ---------- Header ---------- */
function pedimentos_parse_header(string $text): array {
    $out = [
        'num_pedimento' => null,
        'cve_pedimento' => null,
        'razon_social'  => null,
        'regimen'       => null,
        'fecha_entrada' => null,
        'fecha_pago'    => null,
    ];

    // 23 81 3377 3006292 (2-2-4-7)
    // ---------- Número de pedimento (robusto) ----------
    $out['num_pedimento'] = null;

    // 1) Patrón clásico: 2 2 4 7 (a veces el último viene con 6–7 dígitos)
    if (preg_match('/\b(\d{2})\s+(\d{2})\s+(\d{4})\s+(\d{6,7})\b/u', $text, $m)) {
        if (isset($m[1], $m[2], $m[3], $m[4])) {
            $out['num_pedimento'] = trim("{$m[1]} {$m[2]} {$m[3]} {$m[4]}");
        }
    }

    // 2) Fallback: “NÚMERO DE PEDIMENTO: 23 81 3377 3006292” (o similar)
    if (!$out['num_pedimento'] && preg_match('/N(Ú|U)M(?:ERO)?\s+DE\s+PEDIMENTO[:\s]+([0-9\s]{14,24})/iu', $text, $m2)) {
        $raw = preg_replace('/\s+/', ' ', trim($m2[2]));   // compactar espacios
        // Extrae números sueltos y arma 2-2-4-7 (último puede ser 6–7)
        if (preg_match_all('/\d+/', $raw, $parts) && count($parts[0]) >= 4) {
            $a = $parts[0];
            // Usa los primeros 4 componentes
            $p1 = str_pad($a[0], 2, '0', STR_PAD_LEFT);
            $p2 = str_pad($a[1], 2, '0', STR_PAD_LEFT);
            $p3 = str_pad($a[2], 4, '0', STR_PAD_LEFT);
            $p4 = $a[3]; // 6–7 dígitos
            $out['num_pedimento'] = "{$p1} {$p2} {$p3} {$p4}";
        }
    }


    // CVE. PEDIMENTO: BH (con o sin puntos/espacios)
    if (preg_match('/CVE\W*PEDIMENTO\W*([A-Z0-9]{1,3})/i', $text, $m)) {
        $out['cve_pedimento'] = strtoupper($m[1]);
    }

    // REGIMEN: ITR  (con o sin espacio tras :)
    if (preg_match('/REGIMEN\s*:\s*([A-Z]{2,4})/i', $text, $m)
        || preg_match('/REGIMEN:([A-Z]{2,4})/i', $text, $m)) {
        $out['regimen'] = strtoupper($m[1]);
    }

    // RAZÓN SOCIAL: en tu layout aparece tras “CURP: …”
    if (preg_match('/CURP:\s*([^\n]+)\n/i', $text, $m)) {
        $cand = trim($m[1]);
        if ($cand !== '' && !preg_match('/^[A-Z0-9]{10,}$/', $cand)) {
            $out['razon_social'] = $cand;
        }
    }
    if (!$out['razon_social'] && preg_match('/RAZ[ÓO]N\s+SOCIAL:\s*([^\n]+)/i', $text, $m)) {
        $out['razon_social'] = trim($m[1]);
    }

        // Fechas (bloque FECHAS). Algunos layouts tienen los rótulos lejos.
        // 1) Intento con "FECHAS ... ENTRADA ... PAGO"
        if (preg_match('/FECHAS[\s\S]{0,600}?(\d{2}\/\d{2}\/\d{4})[\s\S]{0,120}?\bENTRADA\b[\s\S]{0,600}?(\d{2}\/\d{2}\/\d{4})[\s\S]{0,120}?\bPAGO\b/i', $text, $m)) {
            $out['fecha_entrada'] = $m[1];
            $out['fecha_pago']    = $m[2];
        } else {
            // 2) Toma las DOS fechas más cercanas al texto FECHAS y ordénalas (menor=entrada, mayor=pago)
            if (preg_match('/FECHAS[\s\S]{0,800}/i', $text, $blk)) {
                if (preg_match_all('/\b\d{2}\/\d{2}\/\d{4}\b/', $blk[0], $dm) && count($dm[0]) >= 2) {
                    $d = $dm[0]; sort($d); // orden cronológico simple
                    $out['fecha_entrada'] = $d[0];
                    $out['fecha_pago']    = end($d);
                }
            }
            // 3) Último recurso: toma dos fechas globales del documento
            if (!$out['fecha_entrada'] || !$out['fecha_pago']) {
                if (preg_match_all('/\b\d{2}\/\d{2}\/\d{4}\b/', $text, $dm2) && count($dm2[0]) >= 2) {
                    $d = $dm2[0]; sort($d);
                    $out['fecha_entrada'] = $out['fecha_entrada'] ?: $d[0];
                    $out['fecha_pago']    = $out['fecha_pago']    ?: end($d);
                }
            }
        }


    return $out;
}

/** ---------- Partidas (ruta A: por fracción) ---------- */
function pedimentos_parse_items_by_fraction(string $text, ?array $secFilter = null): array {
    $items = [];

    // Bloques que empiezan con fracción "######## ##"
    $pattern = '/(^|\n)\s*(\d{8})\s+(\d{2})\s*\n([\s\S]*?)(?=(\n\s*\d{8}\s+\d{2}\s*\n)|\Z)/m';
    if (!preg_match_all($pattern, $text, $blocks, PREG_SET_ORDER)) {
        return $items;
    }

    foreach ($blocks as $b) {
        $fr8   = trim($b[2]);
        $fr2   = trim($b[3]);
        $chunk = trim($b[4]);

        $sec = null; $desc = null; $descLineIndex = null;

        // La descripción debe tener letras y terminar en SEC (1-4 dígitos)
        $lines = preg_split('/\n+/', $chunk) ?: [];
        foreach ($lines as $idx => $lnRaw) {
            $ln = trim($lnRaw);
            if ($ln === '') continue;

            // Ignorar renglones típicos de totales/valores
            if (preg_match('/^(IGI|IVA|IDENTIF\.|EN NOM-|XP|UD1|HEADING|VAL\s+ADU|PRECIO\s+UNIT|P\. ?O\/D|P\. ?V\/C|UMC|UMT|USA\s+USA)/i', $ln)) {
                continue;
            }
            if (preg_match('/^(.+?[A-Za-z].*?)\s+(\d{1,4})$/', $ln, $m)) {
                $desc = trim($m[1]);
                $sec  = ltrim($m[2], '0');
                if ($sec === '') $sec = $m[2];
                $descLineIndex = $idx;
                break;
            }
        }

        if ($desc === null) {
            // Fallback: primera línea con letras
            foreach ($lines as $idx => $lnRaw) {
                $ln = trim($lnRaw);
                if ($ln !== '' && preg_match('/[A-Za-z]/', $ln)) {
                    $desc = $ln;
                    $descLineIndex = $idx;
                    break;
                }
            }
        }

        if ($secFilter && $sec !== null && !in_array((int)$sec, $secFilter, true)) {
            continue;
        }

        [$umcInicial, $umcCant] = pedimentos_guess_umc_in_block($lines, $descLineIndex);

        $items[] = [
            'sec'         => $sec,
            'descripcion' => $desc,
            'fraccion8'   => $fr8,
            'fraccion2'   => $fr2,
            'umc_inicial' => $umcInicial,
            'umc_cant'    => $umcCant,
        ];
    }

    return $items;
}

/** ---------- Partidas (ruta B: por “SEC <n>” en cualquier parte) ---------- */
function pedimentos_parse_items_by_sec_scan(string $text, ?array $secFilter = null): array {
    $items = [];
    $lines = preg_split('/\n+/', $text);

    // Preindexa las fracciones para buscar la más cercana hacia arriba
    $fracIdx = [];
    foreach ($lines as $i => $lnRaw) {
        $ln = trim($lnRaw);
        if (preg_match('/\b(\d{8})\s+(\d{2})\b/', $ln, $m)) {
            $fracIdx[$i] = [$m[1], $m[2]];
        }
    }

    for ($i = 0; $i < count($lines); $i++) {
        $ln = trim($lines[$i]);
        if (!preg_match('/\bSEC\s+(\d{1,4})\b/i', $ln, $m)) continue;

        $sec = (int)$m[1];
        if ($secFilter && !in_array($sec, $secFilter, true)) continue;

        // Busca la fracción más cercana hacia arriba (máx 10 líneas)
        $fr8 = $fr2 = null;
        for ($j = $i; $j >= max(0, $i - 10); $j--) {
            if (isset($fracIdx[$j])) { $fr8 = $fracIdx[$j][0]; $fr2 = $fracIdx[$j][1]; break; }
        }

        // Construye una descripción aceptable:
        // - toma la línea actual quitando “SEC n …” delante
        // - si al final queda vacía/o numérica, intenta sumar 1–2 líneas previas con letras
        $desc = preg_replace('/\bSEC\s+\d{1,4}\b\s*/i', '', $ln);
        $desc = trim($desc);
        if ($desc === '' || !preg_match('/[A-Za-z]/', $desc)) {
            // busca hacia arriba hasta 2 líneas con letras
            for ($k = $i - 1; $k >= max(0, $i - 3); $k--) {
                $cand = trim($lines[$k]);
                if ($cand !== '' && preg_match('/[A-Za-z]/', $cand)
                    && !preg_match('/^(IGI|IVA|UMC|UMT|USA\s+USA|VAL\s+ADU|PRECIO\s+UNIT)/i', $cand)) {
                    $desc = $cand; break;
                }
            }
        }

        $items[] = [
            'sec'         => $sec,
            'descripcion' => $desc ?: null,
            'fraccion8'   => $fr8,
            'fraccion2'   => $fr2,
            'umc_inicial' => null,
            'umc_cant'    => null,
        ];
    }

    // Dedup por SEC conservando el primero (por si se repite en la página)
    $seen = [];
    $out  = [];
    foreach ($items as $it) {
        $key = (string)($it['sec'] ?? '');
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $it;
    }
    return $out;
}

/**
 * Determina si un token se asemeja a un número admitiendo separadores de miles o decimales.
 */
function pedimentos_is_number_like(string $token): bool {
    $normalized = trim(normalize_unicode_whitespace($token));
    if ($normalized === '') {
        return false;
    }

    // Elimina espacios finos/no separables que puedan quedar tras la normalización
    $normalized = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $normalized);
    if ($normalized === '') {
        return false;
    }

    $withoutCommas = str_replace(',', '', $normalized);
    if (preg_match('/^-?\d+(?:\.\d+)?$/', $withoutCommas)) {
        return true;
    }

    $withoutDots = str_replace('.', '', $normalized);
    return preg_match('/^-?\d+(?:,\d+)?$/', $withoutDots) === 1;
}

/**
 * Extrae una posible pareja (UMC inicial, cantidad) de una línea con números y rótulos.
 *
 * Devuelve null si la línea no parece corresponder a un renglón de valores UMC.
 *
 * @return array{umc_inicial:string, umc_cant:string, score:int}|null
 */
function pedimentos_extract_umc_candidate_from_line(string $line): ?array {
    $cleanLine = trim(normalize_unicode_whitespace($line));
    if ($cleanLine === '') {
        return null;
    }

    if (!preg_match('/[A-Za-z]/', $cleanLine)) {
        return null; // debe contener al menos algún texto
    }

    $tokens = preg_split('/\s+/', $cleanLine);
    if (!$tokens) {
        return null;
    }

    $numericTokens = [];
    foreach ($tokens as $index => $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        if (pedimentos_is_number_like($token)) {
            $numericTokens[] = ['index' => $index, 'value' => $token];
        }
    }

    if (count($numericTokens) < 4) {
        return null;
    }

    $third  = $numericTokens[2];
    $fourth = $numericTokens[3];

    if ($fourth['index'] <= $third['index']) {
        return null;
    }

    $hasUpperAfter = false;
    $containsUmcWord = stripos($cleanLine, 'UMC') !== false;
    foreach ($tokens as $index => $token) {
        if ($index <= $fourth['index']) {
            continue;
        }
        if (preg_match('/^[A-Z]{2,5}$/', $token) || stripos($token, 'IGI') === 0 || stripos($token, 'IVA') === 0) {
            $hasUpperAfter = true;
            break;
        }
    }

    if (!$hasUpperAfter && !$containsUmcWord) {
        return null;
    }

    $score = 0;
    if ($hasUpperAfter) {
        $score += 2;
    }
    if ($containsUmcWord) {
        $score += 1;
    }
    if (preg_match('/\b(?:USA|MEX|CAN|CHN|JPN|KOR|DEU|FRA|ESP|GBR|BRA|PER|COL|ARG|PAN|KWT|HKG|TWN|VNM|THA|PHL|SGP|MYS|IDN|POL|TUR|CHL|URY|ECU|CRI)\b/', $cleanLine)) {
        $score += 1;
    }
    $cantToken = str_replace(["\u{00A0}", ' '], '', $fourth['value']);
    if (strpos($cantToken, '.') !== false || strpos($cantToken, ',') !== false) {
        $score += 1;
    }

    return [
        'umc_inicial' => trim($third['value']),
        'umc_cant'    => trim($fourth['value']),
        'score'       => $score,
    ];
}

/**
 * Busca el mejor candidato de UMC en el rango indicado, ponderando la cercanía al SEC.
 *
 * @return array{umc_inicial:string, umc_cant:string}|null
 */
function pedimentos_pick_best_umc_candidate(array $lines, int $start, int $end, int $secLineIndex): ?array {
    $total = count($lines);
    if ($total === 0) {
        return null;
    }

    $start = max(0, min($start, $total - 1));
    $end   = max(0, min($end, $total - 1));
    if ($start > $end) {
        return null;
    }

    $best = null;
    for ($i = $start; $i <= $end; $i++) {
        $raw = (string)($lines[$i] ?? '');
        if (trim($raw) === '') {
            continue;
        }

        $candidate = pedimentos_extract_umc_candidate_from_line($raw);
        if ($candidate === null) {
            continue;
        }

        $distance = abs($i - $secLineIndex);
        $afterSec = $i >= $secLineIndex ? 1 : 0;
        $score    = $candidate['score'];

        $ranking = [
            'umc_inicial' => $candidate['umc_inicial'],
            'umc_cant'    => $candidate['umc_cant'],
            'score'       => $score,
            'after'       => $afterSec,
            'distance'    => $distance,
            'line'        => $i,
        ];

        if ($best === null
            || $ranking['score'] > $best['score']
            || ($ranking['score'] === $best['score'] && $ranking['after'] > $best['after'])
            || ($ranking['score'] === $best['score'] && $ranking['after'] === $best['after'] && $ranking['distance'] < $best['distance'])
            || ($ranking['score'] === $best['score'] && $ranking['after'] === $best['after'] && $ranking['distance'] === $best['distance'] && $ranking['line'] < $best['line'])) {
            $best = $ranking;
        }
    }

    if ($best === null) {
        return null;
    }

    return ['umc_inicial' => $best['umc_inicial'], 'umc_cant' => $best['umc_cant']];
}

/**
 * Intenta estimar los valores de UMC para un SEC específico considerando varias ventanas de búsqueda.
 *
 * @return array{0:?string,1:?string}
 */
function pedimentos_guess_umc_for_sec(array $lines, int $secLineIndex, int $prevSecLine, int $nextSecLine): array {
    $total = count($lines);
    if ($total === 0) {
        return [null, null];
    }

    $ranges = [];

    if ($prevSecLine >= 0 || $nextSecLine >= 0) {
        $start = $prevSecLine >= 0 ? $prevSecLine + 1 : $secLineIndex - 6;
        $end   = $nextSecLine >= 0 ? $nextSecLine - 1 : $secLineIndex + 8;
        $ranges[] = [$start, $end];
    }

    $ranges[] = [$secLineIndex - 6, $secLineIndex + 8];
    $ranges[] = [$secLineIndex - 10, $secLineIndex + 14];

    foreach ($ranges as [$start, $end]) {
        $candidate = pedimentos_pick_best_umc_candidate($lines, $start, $end, $secLineIndex);
        if ($candidate !== null) {
            $umcInicial = trim($candidate['umc_inicial']);
            $umcCant    = trim($candidate['umc_cant']);
            return [$umcInicial !== '' ? $umcInicial : null, $umcCant !== '' ? $umcCant : null];
        }
    }

    return [null, null];
}

/**
 * Variante utilitaria para bloques locales (como en el parser por fracción).
 *
 * @return array{0:?string,1:?string}
 */
function pedimentos_guess_umc_in_block(array $lines, ?int $secLineIndex = null): array {
    if (!$lines) {
        return [null, null];
    }

    $secIndex = $secLineIndex ?? 0;
    $candidate = pedimentos_pick_best_umc_candidate($lines, max(0, $secIndex - 6), count($lines) - 1, $secIndex);
    if ($candidate !== null) {
        $umcInicial = trim($candidate['umc_inicial']);
        $umcCant    = trim($candidate['umc_cant']);
        return [$umcInicial !== '' ? $umcInicial : null, $umcCant !== '' ? $umcCant : null];
    }

    return [null, null];
}

/** ---------- Orquestador ---------- */
function pedimentos_parse_items(string $text, ?array $secFilter = null): array {
    $items = [];
    $lines = preg_split('/\n+/', $text);

    // --- helpers (ACTUALIZADOS) ---
    $startsWith = fn(string $s, string $re) => (bool)preg_match($re, $s);

    $isNoise = function (string $ln) use ($startsWith): bool {
        $ln = trim($ln);
        if ($ln === '') return true;

        // 0) Filas exclusivamente numéricas (cantidades, totales, etc.)
        if (preg_match('/^[0-9.,\s]+$/', $ln)) {
            return true;
        }

        // 0b) Líneas de separación o notas finales (asteriscos, fin de documento, totales globales)
        if (preg_match('/\*{3,}/', $ln)
            || preg_match('/FIN\s+DE\s+PEDIMENTO/i', $ln)
            || preg_match('/NUM\.?\s+TOTAL/i', $ln)
            || preg_match('/TOTAL\s+DE\s+PARTIDAS/i', $ln)) {
            return true;
        }

        // 1) Encabezados / etiquetas de tabla (acepta sufijos como "IVA 0.0000", "IDENTIF. EN", etc.)
        if ($startsWith($ln, '/^(?:IGI|IVA|IDENTIF\.|COMPLEMENTO\b|EN NOM-|XP|UD1|HEADING|'
                           .'VAL\s*\.?\s*ADU(?:\/USD)?|VAL\.?\s*AGREG\.?|PRECIO\s+UNIT|IMP\.?\s*PRECIO|'
                           .'P\.?\s*O\/D|P\.?\s*V\/C|UMC|UMT|TASA|T\.?T\.?|F\.?P\.?|IMPORTE)\b/i')) {
            return true;
        }

        // 2) Línea que COMIENZA con fracción "######## ##"
        if (preg_match('/^\s*\d{8}\s+\d{2}\b/', $ln)) return true;

        // 3) Fila numérica "de valores": muchos números y el token USA (columna país)
        //    Evita confundirla con descripción solo porque tiene letras "USA".
        if (preg_match('/USA\b/i', $ln)) {
            $digits = preg_match_all('/[\d,.]/', $ln);
            $letters = preg_match_all('/[A-Za-z]/', $ln);
            if ($digits >= 6 && $letters <= 6) return true; // típico renglón con varios números y dos "USA"
        }

        // 4) Filas casi numéricas (sin palabras largas)
        $lettersLong = preg_match('/\b[A-Za-z]{4,}\b/', $ln);
        $digitsMany  = preg_match_all('/[\d,.]/', $ln) >= 6;
        if (!$lettersLong && $digitsMany) return true;

        return false;
    };

    // "verdadera" descripción: necesita una palabra de ≥4 letras distinta a USA/EN/X y no ser ruido
    $isGoodDesc = function (string $ln) use ($isNoise): bool {
        $ln = trim($ln);
        if ($isNoise($ln)) return false;
        // palabra alfabética de 4+ letras que no sea USA/EN/X
        return (bool)preg_match('/\b(?!USA\b)(?!EN\b)(?!X\b)[A-Za-z]{4,}\b/', $ln);
    };

    $collectDescription = function (int $start, int $end, int $secLineIndex, int $sec) use ($lines, $isNoise, $isGoodDesc): ?string {
        $total = count($lines);
        if ($total === 0) {
            return null;
        }

        $start = max(0, $start);
        $end   = min($total - 1, $end);
        if ($start > $end) {
            return null;
        }

        $candidates = [];
        $secBase = $sec > 0 ? (string)$sec : null;

        $scoreLine = static function (string $text): int {
            $len      = mb_strlen($text);
            $letters  = preg_match_all('/[A-Za-z]/u', $text);
            $words4   = preg_match_all('/\b[A-Za-z]{4,}\b/u', $text);
            $digits   = preg_match_all('/\d/u', $text);
            $penalty  = 0;

            if (preg_match('/\b(IDENTIF\.|COMPLEMENTO|COMP\s*ELEMENTO|UMC|UMT|USA\b|EN\b|XP\b|UD1\b)\b/i', $text)) {
                $penalty += 25;
            }
            if ($digits > $letters) {
                $penalty += 10;
            }

            return ($letters * 4) + ($words4 * 6) + $len - $penalty;
        };

        for ($j = $start; $j <= $end; $j++) {
            $raw  = (string)($lines[$j] ?? '');
            $cand = trim($raw);
            if ($cand === '') {
                continue;
            }

            if (preg_match('/^\d{1,3}$/', $cand)) {
                continue;
            }

            $secRef = $secBase;
            $clean  = clean_description($cand, $secRef);
            if ($clean === '' && !$isGoodDesc($cand)) {
                continue;
            }
            if ($clean === '') {
                $clean = trim(normalize_unicode_whitespace($cand));
            }

            if ($clean === '' || $isNoise($clean)) {
                continue;
            }

            $distancePenalty = max(0, abs($j - $secLineIndex) - 1);
            $score = $scoreLine($clean) - (int)floor($distancePenalty * 1.5);

            $candidates[] = [
                'text'  => preg_replace('/\s{2,}/', ' ', $clean) ?? $clean,
                'score' => $score,
                'index' => $j,
            ];
        }

        if (!$candidates) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                if ($a['index'] === $b['index']) {
                    return mb_strlen($b['text']) <=> mb_strlen($a['text']);
                }
                return $a['index'] <=> $b['index'];
            }
            return $b['score'] <=> $a['score'];
        });

        $best = $candidates[0];

        $visited = [$best['index'] => true];
        $segments = [];

        $gatherNeighbors = function (int $origin, int $direction) use ($start, $end, $lines, $isNoise, $isGoodDesc, $secBase, &$visited): array {
            $parts = [];
            $steps = 0;
            for ($pos = $origin + $direction; $pos >= $start && $pos <= $end && $steps < 3; $pos += $direction) {
                $raw = (string)($lines[$pos] ?? '');
                $cand = trim($raw);
                if ($cand === '') {
                    break;
                }
                if (preg_match('/^\d{1,3}$/', $cand)) {
                    break;
                }
                if ($isNoise($cand)) {
                    break;
                }

                $secRef = $secBase;
                $clean = clean_description($cand, $secRef);
                if ($clean === '' && !$isGoodDesc($cand)) {
                    break;
                }
                if ($clean === '') {
                    $clean = trim(normalize_unicode_whitespace($cand));
                }
                if ($clean === '' || $isNoise($clean)) {
                    break;
                }

                if (!isset($visited[$pos])) {
                    $parts[] = [
                        'index' => $pos,
                        'text'  => preg_replace('/\s{2,}/', ' ', $clean) ?? $clean,
                    ];
                    $visited[$pos] = true;
                }

                $steps++;
            }

            return $parts;
        };

        $before = $gatherNeighbors($best['index'], -1);
        $after  = $gatherNeighbors($best['index'], +1);

        if ($before) {
            usort($before, static fn(array $a, array $b): int => $a['index'] <=> $b['index']);
            foreach ($before as $seg) {
                $segments[] = $seg;
            }
        }

        $segments[] = ['index' => $best['index'], 'text' => trim($best['text'])];

        if ($after) {
            usort($after, static fn(array $a, array $b): int => $a['index'] <=> $b['index']);
            foreach ($after as $seg) {
                $segments[] = $seg;
            }
        }

        $joined = trim(preg_replace('/\s{2,}/', ' ', implode(' ', array_map(static fn(array $seg) => $seg['text'], $segments))) ?? '');

        if ($joined === '' || !preg_match('/[A-Za-z]{2,}/', $joined)) {
            return null;
        }

        return $joined;
    };

    // 1) Indexa TODAS las fracciones por su índice de línea (para asociarlas a cada SEC)
    $fracIdx = []; // idx => [fr8, fr2]
    foreach ($lines as $i => $raw) {
        $ln = trim($raw);
        if (preg_match('/\b(\d{8})\s+(\d{2})\b/', $ln, $m)) {
            $fracIdx[$i] = [$m[1], $m[2]];
        }
    }
    if (!$fracIdx) return [];

    $fracKeys = array_keys($fracIdx);
    $secEntries = [];
    for ($i = 0; $i < count($lines); $i++) {
        $ln = trim($lines[$i]);
        if (!preg_match('/^\d{1,3}$/', $ln)) {
            continue;
        }
        $sec = (int)$ln;
        if ($sec <= 0) {
            continue;
        }
        if ($secFilter && !in_array($sec, $secFilter, true)) {
            continue;
        }

        $secEntries[] = ['line' => $i, 'sec' => $sec];
    }

    if (!$secEntries) {
        return [];
    }

    $results = [];
    $seen = [];

    foreach ($secEntries as $idx => $entry) {
        $sec = $entry['sec'];
        if (isset($seen[$sec])) {
            continue;
        }

        $lineIndex = $entry['line'];

        // Fracción más cercana hacia arriba (hasta 60 líneas)
        $fr8 = $fr2 = null;
        for ($k = count($fracKeys) - 1; $k >= 0; $k--) {
            $fi = $fracKeys[$k];
            if ($fi < $lineIndex && ($lineIndex - $fi) <= 60) {
                [$fr8, $fr2] = $fracIdx[$fi];
                break;
            }
            if ($fi < $lineIndex && ($lineIndex - $fi) > 60) {
                break;
            }
        }
        if ($fr8 === null) {
            continue;
        }

        $nextLine = $secEntries[$idx + 1]['line'] ?? count($lines);
        $prevLine = $secEntries[$idx - 1]['line'] ?? -1;

        $desc = $collectDescription($lineIndex + 1, min($lineIndex + 12, $nextLine - 1), $lineIndex, $sec);
        if ($desc === null) {
            $beforeStart = max($prevLine + 1, $lineIndex - 4);
            $desc = $collectDescription($beforeStart, $lineIndex - 1, $lineIndex, $sec);
        }

        if ($desc === null) {
            continue;
        }

        [$umcInicial, $umcCant] = pedimentos_guess_umc_for_sec($lines, $lineIndex, $prevLine, $nextLine);

        $results[] = [
            'sec'         => $sec,
            'descripcion' => $desc,
            'fraccion8'   => $fr8,
            'fraccion2'   => $fr2,
            'umc_inicial' => $umcInicial,
            'umc_cant'    => $umcCant,
        ];

        $seen[$sec] = true;
    }

    usort($results, static function (array $a, array $b): int {
        return $a['sec'] <=> $b['sec'];
    });

    return $results;
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


function pedimentos_parse_all(string $text, ?array $secFilter = null): array {
    return [
        'header' => pedimentos_parse_header($text),
        'items'  => pedimentos_parse_items($text, $secFilter),
        'debug'  => [
            'text_driver' => 'smalot',
            'bytes'       => strlen($text),
            'secs_count'  => preg_match_all('/\bSEC\b/i', $text, $m)
        ]
    ];
}
