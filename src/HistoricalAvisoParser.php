<?php

declare(strict_types=1);

final class HistoricalAvisoParser
{
    /** @return array<string,mixed> */
    public static function parse(string $text, string $fileName = ''): array
    {
        $raw = self::normalizeText($text);
        $upper = self::upper($raw);
        $fileUpper = self::upper($fileName);

        $data = [
            'notice_number' => null,
            'document_code' => null,
            'office_date' => null,
            'recipient_name' => null,
            'recipient_title' => null,
            'rig_name' => null,
            'rig_imo' => null,
            'rig_field' => null,
            'rig_area' => null,
            'comitente' => null,
            'manifest' => null,
            'transport_name' => null,
            'transport_imo' => null,
            'consignataria' => null,
            'shipping_date' => null,
            'landing_datetime' => null,
            'landing_place' => null,
            'storage_address' => null,
            'repair_address' => null,
            'signer_name' => null,
            'signer_title' => null,
            'pedimentos' => [],
            'items' => [],
        ];

        $confidence = [];

        if (preg_match('/\b(MADE\s*[-–—]\s*\d{2,4}\s*[-–—]\s*\d{2,4})\b/u', $upper, $m)) {
            $data['document_code'] = self::compactDashCode($m[1]);
            $confidence['document_code'] = 1.0;
            if (preg_match('/MADE-(\d{2,4}-\d{2,4})/', (string) $data['document_code'], $n)) {
                $data['notice_number'] = $n[1];
                $confidence['notice_number'] = 1.0;
            }
        }

        if ($data['notice_number'] === null) {
            $notice = self::extractNoticeNumber($upper) ?? self::extractNoticeNumber($fileUpper);
            if ($notice !== null) {
                $data['notice_number'] = $notice;
                $confidence['notice_number'] = str_contains($upper, $notice) ? 0.95 : 0.75;
            }
        }

        if ($data['document_code'] === null && $data['notice_number'] !== null) {
            $data['document_code'] = 'MADE-' . $data['notice_number'];
            $confidence['document_code'] = 0.75;
        }

        if (preg_match('/TAMPICO\s*,?\s*TAMAULIPAS\s+A\s+(\d{1,2})\s+DE\s+([A-ZÁÉÍÓÚÑ]+)\s+DE\s+(20\d{2})/u', $upper, $m)) {
            $date = self::spanishDateToIso((int) $m[1], $m[2], (int) $m[3]);
            if ($date !== null) {
                $data['office_date'] = $date;
                $confidence['office_date'] = 0.98;
            }
        }

        if (preg_match('/\b(C\.?\s*(?:MTRO\.|ING\.|LIC\.)?\s*[A-ZÁÉÍÓÚÑ ]{5,80})\s*\n\s*(TITULAR\s+DE\s+LA\s+ADUANA\s+DE\s+[A-ZÁÉÍÓÚÑ ]+)/u', $upper, $m)) {
            $data['recipient_name'] = self::titleLike($m[1]);
            $data['recipient_title'] = self::cleanInline($m[2]);
            $confidence['recipient_name'] = 0.85;
            $confidence['recipient_title'] = 0.9;
        }

        $rigPattern = '/DENOMINAD[OA]\s+([A-Z0-9 .\-]{4,80}?)\s*,?\s+CON\s+N[ÚU]MERO\s+IMO\s+(\d{6,10})\s*,?\s+POSICIONAD[OA]\s+EN\s+EL\s+CAMPO\s+([A-Z0-9 .\-]{2,60})/u';
        if (preg_match($rigPattern, $upper, $m)) {
            $data['rig_name'] = self::cleanInline($m[1]);
            $data['rig_imo'] = trim($m[2]);
            $data['rig_field'] = self::trimSentenceEnd($m[3]);
            $confidence['rig_name'] = 0.98;
            $confidence['rig_imo'] = 0.99;
            $confidence['rig_field'] = 0.95;
        }

        if (preg_match('/EN\s+NOMBRE\s+DE\s+MI\s+COMITENTE\s+(.{4,180}?)\s*,?\s+(?:PRESENTO\s+)?AVISO\s+DE\s+DESEMBARQUE/isu', $raw, $m)) {
            $data['comitente'] = self::cleanInline($m[1]);
            $confidence['comitente'] = 0.95;
        }

        $manifest = null;
        if (preg_match('/MANIFIESTO\s+DE\s+EMBARQUE\s+N[ÚU]MERO\s*:\s*([0-9]{2,4}\s*[-–—]\s*[0-9]{2,4})/u', $upper, $m)) {
            $manifest = self::compactNotice($m[1]);
            $confidence['manifest'] = 0.98;
        } elseif (preg_match('/\bMANIFIESTO\b.{0,120}?\b([0-9]{2,4}\s*[-–—]\s*[0-9]{2,4})\b/su', $upper, $m)) {
            $manifest = self::compactNotice($m[1]);
            $confidence['manifest'] = 0.82;
        }
        if ($manifest === null && $data['notice_number'] !== null) {
            $manifest = $data['notice_number'];
            $confidence['manifest'] = 0.72;
        }
        $data['manifest'] = $manifest;

        if (preg_match('/MEDIO\s+DE\s+TRANSPORTE\s*:\s*(?:EMBARCACION\s+)?(.{3,120}?)\s*(?:[\.\/|]\s*)?IMO\s+(\d{6,10})/isu', $raw, $m)) {
            $data['transport_name'] = self::cleanInline($m[1]);
            if (! str_starts_with(self::upper((string) $data['transport_name']), 'EMBARCACION')) {
                $data['transport_name'] = 'EMBARCACION ' . $data['transport_name'];
            }
            $data['transport_imo'] = trim($m[2]);
            $confidence['transport_name'] = 0.95;
            $confidence['transport_imo'] = 0.98;
        }

        if (preg_match('/CONSIGNATARIA\s*[:\s]+(.{4,180}?)(?:\s+FECHA\s+DE\s+EMBARQUE|\s+FECHA\s+EMBARQUE)/isu', $raw, $m)) {
            $data['consignataria'] = self::cleanInline($m[1]);
            $confidence['consignataria'] = 0.9;
        }

        if (preg_match('/FECHA\s+DE\s+EMBARQUE\s*:\s*(\d{1,2})\s+DE\s+([A-ZÁÉÍÓÚÑ]+)\s+DE\s+(20\d{2})/u', $upper, $m)) {
            $date = self::spanishDateToIso((int) $m[1], $m[2], (int) $m[3]);
            if ($date !== null) {
                $data['shipping_date'] = $date;
                $confidence['shipping_date'] = 0.95;
            }
        }

        if (preg_match('/FECHA\s+DE\s+DESEMBARQUE\s*:\s*(\d{1,2})\s+DE\s+([A-ZÁÉÍÓÚÑ]+)\s+DE\s+(20\d{2})(?:\s*[\/\-]\s*ETA\s+APROXIMAD[OA]?\s*([0-2]?\d[:.]\d{2})\s*(?:HRS|HR|AM|PM)?)?/u', $upper, $m)) {
            $date = self::spanishDateToIso((int) $m[1], $m[2], (int) $m[3]);
            if ($date !== null) {
                $time = isset($m[4]) && trim($m[4]) !== '' ? str_replace('.', ':', trim($m[4])) : '00:00';
                $data['landing_datetime'] = $date . ' ' . str_pad($time, 5, '0', STR_PAD_LEFT) . ':00';
                $confidence['landing_datetime'] = isset($m[4]) && trim($m[4]) !== '' ? 0.96 : 0.82;
            }
        }

        $data['landing_place'] = self::extractBetween($raw, 'LUGAR DE DESEMBARQUE:', ['FECHA DE DESEMBARQUE:', 'DOMICILIO AL QUE SE TRASLADARA']);
        if ($data['landing_place'] !== null) {
            $confidence['landing_place'] = 0.82;
        }
        $data['storage_address'] = self::extractBetween($raw, 'DOMICILIO AL QUE SE TRASLADARA PARA SU ALMACENAMIENTO:', ['DOMICILIO AL QUE SE TRASLADARA PARA SU REPARACION', 'ESTA MERCANCIA']);
        if ($data['storage_address'] !== null) {
            $confidence['storage_address'] = 0.82;
        }
        $data['repair_address'] = self::extractBetween($raw, 'DOMICILIO AL QUE SE TRASLADARA PARA SU REPARACION Y MANTENIMIENTO:', ['ESTA MERCANCIA', 'POR LO ANTERIOR']);
        if ($data['repair_address'] !== null) {
            $confidence['repair_address'] = 0.8;
        }

        $pedimentos = self::extractPedimentos($upper);
        $data['pedimentos'] = $pedimentos;
        if ($pedimentos !== []) {
            $confidence['pedimentos'] = 0.92;
        }

        $items = self::extractItems($raw, $pedimentos);
        $data['items'] = $items;
        if ($items !== []) {
            $confidence['items'] = 0.82;
        }

        if (preg_match('/ATENTAMENTE\s+([A-ZÁÉÍÓÚÑ .]{5,80})\s+AGENTE\s+ADUANAL\s+PATENTE\s+(\d{3,5})/u', $upper, $m)) {
            $data['signer_name'] = self::titleLike($m[1]);
            $data['signer_title'] = 'AGENTE ADUANAL PATENTE ' . trim($m[2]);
            $confidence['signer_name'] = 0.9;
            $confidence['signer_title'] = 0.98;
        }

        // Los PDFs con tablas pueden entrelazar columnas al extraer texto. Preferimos dejar un campo vacío
        // antes que dar por válido un valor contaminado; el Word compañero suele resolver estos casos.
        $tableNoise = '/DESCRIPCION|CANTIDAD|DATOS DE IDENTIFICACION|AREA PERTENECIENTE|PEDIMENTO|PARTIDA/u';
        if (is_string($data['transport_name']) && preg_match($tableNoise, self::upper($data['transport_name']))) {
            $data['transport_name'] = null;
            $confidence['transport_name'] = 0.2;
        }
        if (is_string($data['consignataria']) && preg_match($tableNoise, self::upper($data['consignataria']))) {
            $data['consignataria'] = null;
            $confidence['consignataria'] = 0.2;
        }
        if (is_string($data['landing_place']) && preg_match($tableNoise, self::upper($data['landing_place']))) {
            $data['landing_place'] = null;
            $confidence['landing_place'] = 0.2;
        }
        if ($data['items'] !== []) {
            foreach ($data['items'] as $item) {
                $desc = self::upper((string) ($item['description'] ?? ''));
                if (preg_match('/NUMERO Y FECHA DE MANIFIESTO|DOMICILIO AL QUE|CONSIGNATARIA|FECHA DE DESEMBARQUE/u', $desc)) {
                    $data['items'] = [];
                    $confidence['items'] = 0.25;
                    break;
                }
            }
        }

        $criticalKeys = ['notice_number', 'office_date', 'rig_name', 'rig_imo', 'transport_name', 'landing_datetime'];
        $criticalFound = 0;
        foreach ($criticalKeys as $key) {
            if (($data[$key] ?? null) !== null && ($data[$key] ?? '') !== '') {
                $criticalFound++;
            }
        }
        $criticalScore = $criticalFound / count($criticalKeys);
        $overall = $confidence !== [] ? array_sum($confidence) / count($confidence) : 0.0;

        return [
            'data' => $data,
            'confidence' => $confidence,
            'critical_score' => round($criticalScore, 3),
            'overall_confidence' => round($overall, 3),
            'text_length' => self::strlenUtf8($raw),
        ];
    }

    private static function normalizeText(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
        return trim($value);
    }

    private static function upper(string $value): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private static function cleanInline(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return trim($value, " \t\n\r\0\x0B,;/");
    }

    private static function trimSentenceEnd(string $value): string
    {
        $value = self::cleanInline($value);
        $value = preg_replace('/\s+(?:PARA|POR|QUIEN|MANIFIESTO).*$/u', '', $value) ?? $value;
        return trim($value, " .,:;-/");
    }

    private static function titleLike(string $value): string
    {
        $value = self::cleanInline($value);
        if (function_exists('mb_convert_case') && function_exists('mb_strtolower')) {
            return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        return ucwords(strtolower($value));
    }

    private static function compactNotice(string $value): string
    {
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        return str_replace(['–', '—', '_'], '-', $value);
    }

    private static function compactDashCode(string $value): string
    {
        $value = self::upper($value);
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $value = str_replace(['–', '—', '_'], '-', $value);
        return $value;
    }

    private static function extractNoticeNumber(string $value): ?string
    {
        if (preg_match('/\b(\d{2,4})\s*[-–—_]\s*(\d{2,4})\b/u', $value, $m)) {
            return $m[1] . '-' . $m[2];
        }
        return null;
    }

    private static function spanishDateToIso(int $day, string $monthName, int $year): ?string
    {
        $monthName = self::upper($monthName);
        $monthName = strtr($monthName, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
        $months = [
            'ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4,
            'MAYO' => 5, 'JUNIO' => 6, 'JULIO' => 7, 'AGOSTO' => 8,
            'SEPTIEMBRE' => 9, 'SETIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12,
        ];
        $month = $months[$monthName] ?? null;
        if ($month === null || ! checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** @param array<int,string> $endMarkers */
    private static function extractBetween(string $raw, string $startMarker, array $endMarkers): ?string
    {
        $upper = self::upper($raw);
        $start = self::striposUtf8($upper, self::upper($startMarker), 0);
        if ($start === false) {
            return null;
        }
        $start += self::strlenUtf8($startMarker);
        $end = self::strlenUtf8($raw);
        foreach ($endMarkers as $marker) {
            $candidate = self::striposUtf8($upper, self::upper($marker), $start);
            if ($candidate !== false && $candidate < $end) {
                $end = $candidate;
            }
        }
        $length = max(0, $end - $start);
        $slice = self::substrUtf8($raw, $start, $length);
        $slice = self::cleanInline($slice);
        if ($slice === '' || self::strlenUtf8($slice) > 700) {
            return null;
        }
        return $slice;
    }

    private static function strlenUtf8(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function substrUtf8(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($value, $start, null, 'UTF-8') : mb_substr($value, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }

    private static function striposUtf8(string $haystack, string $needle, int $offset = 0): int|false
    {
        return function_exists('mb_stripos')
            ? mb_stripos($haystack, $needle, $offset, 'UTF-8')
            : stripos($haystack, $needle, $offset);
    }

    private static function normalizePedimentoNumber(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 15) {
            return substr($digits, 0, 2) . ' ' . substr($digits, 2, 2) . ' ' . substr($digits, 4, 4) . ' ' . substr($digits, 8, 7);
        }
        return $value;
    }

    /** @return array<int,array{key:string,number:string}> */
    private static function extractPedimentos(string $upper): array
    {
        $results = [];
        $seen = [];

        $add = static function (string $key, string $number) use (&$results, &$seen): void {
            $key = strtoupper(trim($key));
            $key = preg_replace('/\s+/u', '', $key) ?? $key;
            $digits = preg_replace('/\D+/', '', $number) ?? '';
            if ($key === '' || strlen($digits) !== 15) {
                return;
            }
            $normalized = self::normalizePedimentoNumber($number);
            $identity = $key . ':' . $digits;
            if (isset($seen[$identity])) {
                return;
            }
            $seen[$identity] = true;
            $results[] = ['key' => $key, 'number' => $normalized];
        };

        // Captura bloques donde una misma clave ampara uno o varios números, incluso BH/R1.
        if (preg_match_all(
            '/PEDIMENTO(?:\s+DE\s+IMPORTACION(?:\s+TEMPORAL)?)?\s+CLAVE\s+([A-Z0-9\/]{1,12})\s*(?:N[ÚU]MERO\s*)?:?\s*(.{0,800}?)(?=\n(?:PARTIDA|AREA\s+PERTE|DATOS\s+DE\s+IDENTIFICACION|EMBARCACION|DOMICILIO|PEDIMENTO|POR\s+LO\s+ANTERIOR|MANIFIESTO)|$)/isu',
            $upper,
            $blockMatches,
            PREG_SET_ORDER
        )) {
            foreach ($blockMatches as $match) {
                $key = (string) ($match[1] ?? '');
                $segment = (string) ($match[2] ?? '');
                if (preg_match_all('/\b(\d{2}\s+\d{2}\s+\d{4}\s+\d{7})\b/u', $segment, $numbers)) {
                    foreach ($numbers[1] as $number) {
                        $add($key, (string) $number);
                    }
                }
            }
        }

        // Fallback para formas compactas tipo "CLAVE BH/R1 NUMERO: ...".
        $patterns = [
            '/PEDIMENTO(?:\s+DE\s+IMPORTACION(?:\s+TEMPORAL)?)?\s+CLAVE\s+([A-Z0-9\/]{1,12})\s*(?:N[ÚU]MERO\s*:\s*)?(\d{2}\s+\d{2}\s+\d{4}\s+\d{7})/u',
            '/CLAVE\s+([A-Z0-9\/]{1,12})\s*[:\-]?\s*(\d{2}\s+\d{2}\s+\d{4}\s+\d{7})/u',
        ];
        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $upper, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $add((string) ($match[1] ?? ''), (string) ($match[2] ?? ''));
            }
        }

        // Si el mismo número aparece como BH en la tabla y como BH/R1 en la documentación final,
        // conservamos la clasificación más específica para no duplicar el pedimento global.
        $byDigits = [];
        foreach ($results as $result) {
            $digits = preg_replace('/\D+/', '', (string) ($result['number'] ?? '')) ?? '';
            if ($digits === '') {
                continue;
            }
            $byDigits[$digits][] = $result;
        }
        $normalizedResults = [];
        foreach ($byDigits as $entries) {
            $specific = array_values(array_filter($entries, static fn (array $entry): bool => str_contains((string) ($entry['key'] ?? ''), '/')));
            if ($specific !== []) {
                usort($specific, static fn (array $a, array $b): int => strlen((string) ($b['key'] ?? '')) <=> strlen((string) ($a['key'] ?? '')));
                $normalizedResults[] = $specific[0];
                continue;
            }
            foreach ($entries as $entry) {
                $normalizedResults[] = $entry;
            }
        }
        return $normalizedResults;
    }

    /** @param array<int,array{key:string,number:string}> $pedimentos
     *  @return array<int,array<string,mixed>>
     */
    private static function extractItems(string $raw, array $pedimentos): array
    {
        $items = [];
        $upper = self::upper($raw);
        $matches = [];
        preg_match_all('/DESCRIPCION\s+(.{4,1200}?)\s+CANTIDAD\s*:\s*(\d+(?:[.,]\d+)?)\s+(?:PIEZA|PIEZAS|PC|PZA|PZAS)(.{0,900}?)(?=DESCRIPCION|DOMICILIO\s+AL\s+QUE|POR\s+LO\s+ANTERIOR|$)/isu', $raw, $matches, PREG_SET_ORDER);
        foreach ($matches as $index => $match) {
            $description = self::cleanInline($match[1]);
            if ($description === '' || self::strlenUtf8($description) > 1000) {
                continue;
            }
            $quantity = (float) str_replace(',', '.', $match[2]);
            $tail = $match[3] ?? '';
            $serial = null;
            if (preg_match('/DATOS\s+DE\s+IDENTIFICACION\s*:\s*(.{1,300}?)(?=AREA\s+PERTE(?:R)?NECIENTE|PEDIMENTO|PARTIDA|$)/isu', $tail, $sm)) {
                $serial = self::cleanInline($sm[1]);
            }
            $itemPedimentos = [];
            $tailUpper = self::upper($tail);
            if (preg_match('/PEDIMENTO(?:\s+DE\s+IMPORTACION(?:\s+TEMPORAL)?)?\s+CLAVE\s+([A-Z0-9\/]{1,12})\s*:?\s*(.{1,650}?)(?=PARTIDA|AREA\s+PERTE|EMBARCACION|DOMICILIO|$)/isu', $tailUpper, $pm)) {
                $tailKey = strtoupper(trim((string) ($pm[1] ?? '')));
                if (preg_match_all('/(\d{2}\s+\d{2}\s+\d{4}\s+\d{7})/u', (string) ($pm[2] ?? ''), $numberMatches)) {
                    foreach ($numberMatches[1] as $rawNumber) {
                        $digits = preg_replace('/\D+/', '', (string) $rawNumber) ?? '';
                        $resolvedKey = $tailKey;
                        $resolvedNumber = self::normalizePedimentoNumber((string) $rawNumber);
                        foreach ($pedimentos as $globalPedimento) {
                            if ((preg_replace('/\D+/', '', (string) ($globalPedimento['number'] ?? '')) ?? '') === $digits) {
                                $globalKey = strtoupper(trim((string) ($globalPedimento['key'] ?? '')));
                                if (str_contains($globalKey, '/') || ! str_contains($resolvedKey, '/')) {
                                    $resolvedKey = $globalKey !== '' ? $globalKey : $resolvedKey;
                                    $resolvedNumber = (string) ($globalPedimento['number'] ?? $resolvedNumber);
                                }
                            }
                        }
                        $itemPedimentos[] = ['key' => $resolvedKey ?: null, 'number' => $resolvedNumber];
                    }
                }
            } elseif (count($pedimentos) === 1) {
                $itemPedimentos[] = ['key' => $pedimentos[0]['key'], 'number' => $pedimentos[0]['number']];
            }
            $key = count($itemPedimentos) === 1 ? ($itemPedimentos[0]['key'] ?? null) : null;
            $number = count($itemPedimentos) === 1 ? ($itemPedimentos[0]['number'] ?? null) : null;
            $partida = null;
            if (preg_match('/PARTIDA\s*:?\s*([0-9A-Z, Y\-]+)/iu', $tail, $pt)) {
                $partida = self::cleanInline($pt[1]);
            }
            $items[] = [
                'sort_order' => count($items) + 1,
                'description' => $description,
                'quantity' => $quantity,
                'serial_number' => $serial,
                'key' => $key,
                'pedimento' => $number,
                'pedimentos' => $itemPedimentos,
                'partida' => $partida,
            ];
        }

        if ($items === [] && str_contains($upper, 'MERCANCIAS')) {
            // No inventamos mercancías si el formato no se pudo segmentar.
            return [];
        }

        // El Word/PDF de un aviso suele repetir la misma mercancía en el anexo fotográfico.
        // Para métricas y persistencia debemos contar renglones únicos, no repeticiones visuales.
        $deduped = [];
        $seen = [];
        foreach ($items as $item) {
            $descriptionKey = self::upper((string) preg_replace('/[^\pL\pN]+/u', '', (string) ($item['description'] ?? '')));
            $serialKey = self::upper((string) preg_replace('/[^\pL\pN]+/u', '', (string) ($item['serial_number'] ?? '')));
            $pedimentoParts = [];
            if (isset($item['pedimentos']) && is_array($item['pedimentos'])) {
                foreach ($item['pedimentos'] as $pedimento) {
                    if (! is_array($pedimento)) {
                        continue;
                    }
                    $pedimentoParts[] = self::upper((string) ($pedimento['key'] ?? '')) . ':' . (string) preg_replace('/\D+/', '', (string) ($pedimento['number'] ?? ''));
                }
            } else {
                $pedimentoParts[] = self::upper((string) ($item['key'] ?? '')) . ':' . (string) preg_replace('/\D+/', '', (string) ($item['pedimento'] ?? ''));
            }
            sort($pedimentoParts, SORT_STRING);
            $pedimentoKey = implode(',', $pedimentoParts);
            $partidaKey = self::upper((string) preg_replace('/[^A-Z0-9]+/iu', '', (string) ($item['partida'] ?? '')));
            $quantityKey = number_format((float) ($item['quantity'] ?? 0), 3, '.', '');
            $identity = implode('|', [$descriptionKey, $serialKey, $pedimentoKey, $partidaKey, $quantityKey]);

            if ($descriptionKey !== '' && isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $item['sort_order'] = count($deduped) + 1;
            $deduped[] = $item;
        }

        return $deduped;
    }
}
