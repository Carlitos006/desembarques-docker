<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/pedimentos.php';
require_once __DIR__ . '/HistoricalAvisoParser.php';

final class HistoricalAvisoExtractor
{
    /** @return array<string,mixed> */
    public static function extractPdf(string $path, string $fileName): array
    {
        $result = [
            'kind' => 'pdf',
            'is_scanned' => false,
            'driver' => null,
            'text' => '',
            'page_count' => self::pdfPageCount($path),
            'parser' => null,
            'error' => null,
        ];

        try {
            $config = pedimentos_parser_config();
            // Para avisos preferimos pdftotext porque preserva mejor tablas/columnas cuando existe capa de texto.
            $config['text_driver'] = 'pdftotext';
            $extraction = pedimentos_extract_text_from_pdf($path, null, null, $config);
            $text = trim((string) ($extraction['text'] ?? ''));
            $result['driver'] = (string) ($extraction['driver'] ?? 'pdf');
            $result['text'] = $text;
            $meaningful = self::meaningfulLength($text);
            $hasAvisoMarkers = preg_match('/AVISO\s+DE\s+DESEMBARQUE|MADE\s*[-–—]|MANIFIESTO/iu', $text) === 1;
            if ($meaningful < 180 || ! $hasAvisoMarkers) {
                $result['is_scanned'] = true;
                $result['parser'] = HistoricalAvisoParser::parse('', $fileName);
                return $result;
            }
            $result['parser'] = HistoricalAvisoParser::parse($text, $fileName);
            return $result;
        } catch (Throwable $exception) {
            $result['is_scanned'] = true;
            $result['error'] = $exception->getMessage();
            $result['parser'] = HistoricalAvisoParser::parse('', $fileName);
            return $result;
        }
    }

    /** @return array<string,mixed> */
    public static function extractWord(string $path, string $fileName, string $extension): array
    {
        $extension = strtolower(trim($extension));
        if ($extension === 'doc') {
            return self::extractDoc($path, $fileName);
        }
        return self::extractDocx($path, $fileName);
    }

    /** @return array<string,mixed> */
    public static function extractDocx(string $path, string $fileName): array
    {
        $result = [
            'kind' => 'docx',
            'driver' => null,
            'text' => '',
            'tables' => [],
            'parser' => null,
            'error' => null,
        ];

        try {
            $package = self::docxPackageXml($path);
            $xml = (string) ($package['xml'] ?? '');
            $tables = self::docxTables($xml);
            $text = self::docxTextFromXml($xml, $tables);
            $parser = HistoricalAvisoParser::parse($text, $fileName);

            $operationalRows = self::docxOperationalRows($tables);
            if ($operationalRows !== []) {
                $parser = self::enrichParserFromStructuredRows($parser, $operationalRows);
            }

            $result['driver'] = (string) ($package['driver'] ?? 'docx') . '-structured';
            $result['text'] = $text;
            $result['tables'] = self::docxTableSummary($tables);
            $result['parser'] = $parser;
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();
            $result['parser'] = HistoricalAvisoParser::parse('', $fileName);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public static function extractDoc(string $path, string $fileName): array
    {
        $result = [
            'kind' => 'doc',
            'driver' => null,
            'text' => '',
            'parser' => null,
            'error' => null,
        ];

        try {
            if (! self::commandAvailable('antiword')) {
                throw new RuntimeException('No se encontró antiword. Reconstruye el contenedor app con el hotfix de importación histórica.');
            }

            $xml = self::antiwordDocbook($path);
            $text = self::docbookTextForAviso($xml);
            $parser = HistoricalAvisoParser::parse($text, $fileName);
            $parser = self::enrichParserFromDocbook($parser, $xml);

            $result['driver'] = 'antiword-docbook';
            $result['text'] = $text;
            $result['parser'] = $parser;
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();
            $result['parser'] = HistoricalAvisoParser::parse('', $fileName);
        }

        return $result;
    }

    private static function meaningfulLength(string $text): int
    {
        $plain = preg_replace('/[^\pL\pN]+/u', '', $text) ?? '';
        return function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    }

    private static function commandAvailable(string $command): bool
    {
        if (! function_exists('exec') || ! pedimentos_command_execution_available()) {
            return false;
        }
        $out = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);
        return $code === 0 && $out !== [];
    }

    private static function pdfPageCount(string $path): ?int
    {
        if (! self::commandAvailable('pdfinfo')) {
            return null;
        }
        $out = [];
        $code = 0;
        @exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return null;
        }
        foreach ($out as $line) {
            if (preg_match('/^Pages:\s*(\d+)/i', trim($line), $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /** @return array{xml:string,driver:string} */
    private static function docxPackageXml(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('El archivo DOCX no existe o no puede leerse.');
        }

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $opened = $zip->open($path);
            if ($opened !== true) {
                throw new RuntimeException('El archivo DOCX no pudo abrirse como paquete ZIP. Código: ' . (string) $opened);
            }
            try {
                $xml = $zip->getFromName('word/document.xml');
            } finally {
                $zip->close();
            }
            if (! is_string($xml) || trim($xml) === '') {
                throw new RuntimeException('El DOCX no contiene word/document.xml o está vacío.');
            }
            return ['xml' => $xml, 'driver' => 'ziparchive-docx'];
        }

        // Fallback útil en desarrollo/local. Producción compartida no depende de exec/unzip:
        // cuando ZipArchive existe, esta rama nunca se ejecuta.
        if (! self::commandAvailable('unzip')) {
            throw new RuntimeException('ZipArchive no está disponible y tampoco existe un fallback unzip para leer DOCX.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'docx_');
        if (! is_string($tmp) || $tmp === '') {
            throw new RuntimeException('No fue posible crear el archivo temporal para leer DOCX.');
        }

        $command = 'unzip -p ' . escapeshellarg($path) . ' word/document.xml > ' . escapeshellarg($tmp) . ' 2>/dev/null';
        $output = [];
        $code = 0;
        @exec($command, $output, $code);
        $xml = @file_get_contents($tmp);
        @unlink($tmp);
        if ($code !== 0 || ! is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('El DOCX no contiene un documento Word legible.');
        }

        return ['xml' => $xml, 'driver' => 'unzip-docx'];
    }

    /** @param list<array{type:string,rows:list<list<string>>}>|null $tables */
    private static function docxTextFromXml(string $xml, ?array $tables = null): string
    {
        $tables ??= self::docxTables($xml);

        // El anexo fotográfico repite las mercancías. Se elimina únicamente del texto analítico;
        // el PDF/DOCX original permanece intacto y la tabla sigue disponible en el diagnóstico.
        $xml = preg_replace_callback(
            '/<w:tbl\b[^>]*>.*?<\/w:tbl>/isu',
            static function (array $match): string {
                $rows = self::docxRowsFromTableXml((string) $match[0]);
                return self::classifyWordTable($rows) === 'photo_annex' ? "\n" : (string) $match[0];
            },
            $xml
        ) ?? $xml;

        $xml = preg_replace('/<w:tab\b[^>]*\/>/iu', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<w:(?:br|cr)\b[^>]*\/>/iu', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/iu', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:tr>/iu', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:tc>/iu', "\t", $xml) ?? $xml;
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return self::normalizeExtractedText($text);
    }

    /** @return list<array{type:string,rows:list<list<string>>}> */
    private static function docxTables(string $xml): array
    {
        $tables = [];
        if (! preg_match_all('/<w:tbl\b[^>]*>.*?<\/w:tbl>/isu', $xml, $matches)) {
            return [];
        }

        foreach ($matches[0] as $tableXml) {
            $rows = self::docxRowsFromTableXml((string) $tableXml);
            if ($rows === []) {
                continue;
            }
            $tables[] = [
                'type' => self::classifyWordTable($rows),
                'rows' => $rows,
            ];
        }
        return $tables;
    }

    /** @return list<list<string>> */
    private static function docxRowsFromTableXml(string $tableXml): array
    {
        $rows = [];
        if (! preg_match_all('/<w:tr\b[^>]*>(.*?)<\/w:tr>/isu', $tableXml, $rowMatches)) {
            return [];
        }

        foreach ($rowMatches[1] as $rowXml) {
            if (! preg_match_all('/<w:tc\b[^>]*>(.*?)<\/w:tc>/isu', (string) $rowXml, $cellMatches)) {
                continue;
            }
            $row = [];
            foreach ($cellMatches[1] as $cellXml) {
                $row[] = self::docxCellText((string) $cellXml);
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function docxCellText(string $cellXml): string
    {
        $parts = [];
        if (preg_match_all(
            '/<w:t\b[^>]*>.*?<\/w:t>|<w:tab\b[^>]*\/>|<w:(?:br|cr)\b[^>]*\/>|<\/w:p>/isu',
            $cellXml,
            $tokens
        )) {
            foreach ($tokens[0] as $token) {
                $token = (string) $token;
                if (preg_match('/^<\/w:p>/iu', $token)) {
                    $parts[] = "\n";
                    continue;
                }
                if (preg_match('/^<w:tab\b/iu', $token)) {
                    $parts[] = "\t";
                    continue;
                }
                if (preg_match('/^<w:(?:br|cr)\b/iu', $token)) {
                    $parts[] = "\n";
                    continue;
                }
                if (preg_match('/^<w:t\b[^>]*>(.*?)<\/w:t>$/isu', $token, $textMatch)) {
                    $parts[] = html_entity_decode(strip_tags((string) $textMatch[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }
        return self::normalizeExtractedText(implode('', $parts));
    }

    /** @param list<list<string>> $rows */
    private static function classifyWordTable(array $rows): string
    {
        if ($rows === []) {
            return 'other';
        }
        $header = $rows[0] ?? [];
        $keys = array_map(static fn(string $cell): string => self::headerIdentity($cell), $header);

        if (count($keys) >= 4
            && str_contains($keys[0], 'MANIFIESTO')
            && str_contains($keys[1], 'IMPORTADOR')
            && str_contains($keys[2], 'MERCANCIAS')
            && str_contains($keys[3], 'DESEMBARQUE')) {
            return 'merchandise';
        }

        if (count($keys) >= 3
            && str_contains($keys[0], 'MANIFIESTO')
            && str_contains($keys[1], 'MERCANCIAS')
            && str_contains($keys[2], 'IMAGENES')) {
            return 'photo_annex';
        }

        return 'other';
    }

    private static function headerIdentity(string $value): string
    {
        $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
        $value = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
        return preg_replace('/[^A-Z0-9]+/u', '', $value) ?? $value;
    }

    /** @param list<array{type:string,rows:list<list<string>>}> $tables @return list<list<string>> */
    private static function docxOperationalRows(array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            if (($table['type'] ?? '') !== 'merchandise') {
                continue;
            }
            $tableRows = isset($table['rows']) && is_array($table['rows']) ? $table['rows'] : [];
            if ($tableRows === []) {
                continue;
            }
            if ($rows === []) {
                $rows[] = $tableRows[0];
            }
            foreach (array_slice($tableRows, 1) as $row) {
                if (is_array($row) && count($row) >= 4) {
                    $rows[] = array_slice(array_values($row), 0, 4);
                }
            }
        }
        return $rows;
    }

    /** @param list<array{type:string,rows:list<list<string>>}> $tables @return list<array{type:string,row_count:int,column_count:int}> */
    private static function docxTableSummary(array $tables): array
    {
        $summary = [];
        foreach ($tables as $table) {
            $rows = isset($table['rows']) && is_array($table['rows']) ? $table['rows'] : [];
            $summary[] = [
                'type' => (string) ($table['type'] ?? 'other'),
                'row_count' => count($rows),
                'column_count' => isset($rows[0]) && is_array($rows[0]) ? count($rows[0]) : 0,
            ];
        }
        return $summary;
    }

    private static function antiwordDocbook(string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'antiword_');
        if (! is_string($tmp) || $tmp === '') {
            throw new RuntimeException('No fue posible crear el archivo temporal para leer Word .doc.');
        }

        $command = 'antiword -x db ' . escapeshellarg($path) . ' > ' . escapeshellarg($tmp) . ' 2>/dev/null';
        $output = [];
        $code = 0;
        @exec($command, $output, $code);
        $xml = @file_get_contents($tmp);
        @unlink($tmp);
        if ($code !== 0 || ! is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('El archivo Word .doc no pudo leerse con antiword.');
        }

        if (function_exists('mb_check_encoding') && ! mb_check_encoding($xml, 'UTF-8')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $xml);
            if (is_string($converted) && $converted !== '') {
                $xml = $converted;
            }
        }
        return $xml;
    }

    private static function docbookTextForAviso(string $xml): string
    {
        // Los Word históricos repiten mercancías en una tabla de 3 columnas para el anexo fotográfico.
        // La quitamos del texto analítico para no duplicar ítems/piezas.
        $xml = preg_replace(
            '/<informaltable\b[^>]*>\s*<tgroup\s+cols=[\'\"]3[\'\"][^>]*>.*?<\/tgroup>\s*<\/informaltable>/isu',
            "\n",
            $xml
        ) ?? $xml;

        $xml = preg_replace('/<\/entry\s*>/iu', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/row\s*>/iu', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/para\s*>/iu', "\n\n", $xml) ?? $xml;
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return self::normalizeExtractedText($text);
    }

    private static function normalizeExtractedText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/\[pic\]/iu', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** @return list<list<string>> */
    private static function docbookRows(string $xml, int $columns): array
    {
        $rows = [];
        if (! preg_match_all('/<row>(.*?)<\/row>/isu', $xml, $matches)) {
            return [];
        }
        foreach ($matches[1] as $rowXml) {
            if (! preg_match_all('/<entry>(.*?)<\/entry>/isu', $rowXml, $entries)) {
                continue;
            }
            if (count($entries[1]) !== $columns) {
                continue;
            }
            $row = [];
            foreach ($entries[1] as $entryXml) {
                $entryXml = preg_replace('/<\/para\s*>/iu', "\n", $entryXml) ?? $entryXml;
                $entry = strip_tags($entryXml);
                $entry = html_entity_decode($entry, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $entry = self::normalizeExtractedText($entry);
                $row[] = $entry;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $parser @return array<string,mixed> */
    private static function enrichParserFromDocbook(array $parser, string $xml): array
    {
        return self::enrichParserFromStructuredRows($parser, self::docbookRows($xml, 4));
    }

    /** @param array<string,mixed> $parser @param list<list<string>> $rows @return array<string,mixed> */
    private static function enrichParserFromStructuredRows(array $parser, array $rows): array
    {
        $data = is_array($parser['data'] ?? null) ? $parser['data'] : [];
        $confidence = is_array($parser['confidence'] ?? null) ? $parser['confidence'] : [];

        $items = [];
        $importers = [];
        $rowPedimentoImporters = [];
        $rigArea = null;

        foreach ($rows as $index => $row) {
            if ($index === 0 || count($row) < 4) {
                continue;
            }
            [$manifest, $importer, $merchandise, $operation] = array_slice(array_values($row), 0, 4);
            $importer = trim((string) $importer);
            $merchandise = (string) $merchandise;

            if ($importer !== '') {
                $importers[self::identity($importer)] = $importer;
            }

            if ($rigArea === null && preg_match('/AREA\s+PERTE(?:R)?NECIENTE\s+EN\s+EL\s+RIG\s+[^:]{3,120}:\s*([^\n,;]+)/iu', $merchandise, $areaMatch)) {
                $rigArea = trim($areaMatch[1]);
            }

            $numbers = self::pedimentoNumbersInText($merchandise);
            foreach ($numbers as $number) {
                $rowPedimentoImporters[self::digits($number)] = $importer !== '' ? $importer : null;
            }

            foreach (self::parseMerchandiseCell($merchandise, $numbers, $data['pedimentos'] ?? []) as $item) {
                if ($importer !== '') {
                    $item['importer_name'] = $importer;
                    if (isset($item['pedimentos']) && is_array($item['pedimentos'])) {
                        foreach ($item['pedimentos'] as &$itemPedimento) {
                            if (is_array($itemPedimento) && empty($itemPedimento['importer_name'])) {
                                $itemPedimento['importer_name'] = $importer;
                            }
                        }
                        unset($itemPedimento);
                    }
                }
                $items[] = $item;
            }
        }

        if (($data['comitente'] ?? null) === null && count($importers) === 1) {
            $data['comitente'] = array_values($importers)[0];
            $confidence['comitente'] = 0.86;
        }
        if (($data['rig_area'] ?? null) === null && is_string($rigArea) && $rigArea !== '') {
            $data['rig_area'] = $rigArea;
            $confidence['rig_area'] = 0.88;
        }

        $pedimentos = isset($data['pedimentos']) && is_array($data['pedimentos']) ? $data['pedimentos'] : [];
        foreach ($pedimentos as &$pedimento) {
            if (! is_array($pedimento)) {
                continue;
            }
            $digits = self::digits((string) ($pedimento['number'] ?? ''));
            $importer = $rowPedimentoImporters[$digits] ?? null;
            if (($importer === null || $importer === '') && count($importers) === 1) {
                $importer = array_values($importers)[0];
            }
            if (is_string($importer) && trim($importer) !== '') {
                $pedimento['importer_name'] = trim($importer);
            }
        }
        unset($pedimento);
        $data['pedimentos'] = $pedimentos;

        if ($items !== []) {
            $data['items'] = self::dedupeStructuredItems($items);
            $confidence['items'] = 0.98;
        }

        $parser['data'] = $data;
        $parser['confidence'] = $confidence;
        $criticalKeys = ['notice_number', 'office_date', 'rig_name', 'rig_imo', 'transport_name', 'landing_datetime'];
        $criticalFound = 0;
        foreach ($criticalKeys as $key) {
            if (($data[$key] ?? null) !== null && trim((string) ($data[$key] ?? '')) !== '') {
                $criticalFound++;
            }
        }
        $parser['critical_score'] = round($criticalFound / count($criticalKeys), 3);
        $parser['overall_confidence'] = $confidence !== [] ? round(array_sum($confidence) / count($confidence), 3) : 0.0;
        return $parser;
    }

    /** @param list<string> $rowNumbers @param mixed $globalPedimentos @return list<array<string,mixed>> */
    private static function parseMerchandiseCell(string $text, array $rowNumbers, mixed $globalPedimentos): array
    {
        $text = self::normalizeExtractedText($text);
        if ($text === '' || preg_match('/\bCANTIDAD\s*:/iu', $text) !== 1) {
            return [];
        }

        $blocks = preg_split('/\n\s*\n+/u', $text) ?: [$text];
        $items = [];
        foreach ($blocks as $block) {
            if (preg_match('/\bCANTIDAD\s*:/iu', $block) !== 1) {
                continue;
            }
            // En algunos Word antiguos hay más de una mercancía dentro del mismo bloque. Cortamos por la siguiente descripción
            // usando CANTIDAD como ancla, manteniendo la metadata compartida fuera del contador de ítems.
            if (! preg_match_all('/(?:^|\n)(?:DESCRIPCION\s*\n?)?(.{3,1800}?)\nCANTIDAD\s*:\s*(\d+(?:[.,]\d+)?)\s*(?:PIEZA|PIEZAS|PC|PZA|PZAS)\b(.*?)(?=(?:\n\s*\n)|$)/isu', $block, $matches, PREG_SET_ORDER)) {
                // fallback para bloques compactados por Word
                preg_match_all('/(?:DESCRIPCION\s+)?(.{3,1800}?)\s+CANTIDAD\s*:\s*(\d+(?:[.,]\d+)?)\s*(?:PIEZA|PIEZAS|PC|PZA|PZAS)\b(.*?)(?=(?:\d+\s+(?:USED|NEW)\b|(?:MANGUERA|CONTENEDOR(?:ES)?|CANASTILLA)\b.*?CANTIDAD\s*:)|$)/isu', $block, $matches, PREG_SET_ORDER);
            }
            foreach ($matches as $match) {
                $description = self::cleanDescription((string) ($match[1] ?? ''));
                if ($description === '') {
                    continue;
                }
                $quantity = (float) str_replace(',', '.', (string) ($match[2] ?? '0'));
                if ($quantity <= 0) {
                    continue;
                }
                $tail = (string) ($match[3] ?? '');
                $serial = null;
                if (preg_match('/DATOS\s+DE\s+IDENTIFICACION\s*:\s*(.*?)(?=\n(?:AREA\s+PERTE(?:R)?NECIENTE|PEDIMENTO|PARTIDA|NOTA|EMBARCACION)\b|$)/isu', $tail, $serialMatch)) {
                    $serial = self::cleanSerial((string) $serialMatch[1]);
                }

                $partida = null;
                if (preg_match('/PARTIDAS?\s*:?\s*([^\n]+)/iu', $block, $partidaMatch)) {
                    $partida = trim((string) $partidaMatch[1]);
                }

                $itemNumbers = self::pedimentoNumbersInText($match[0]);
                $relationNumbers = $itemNumbers !== [] ? $itemNumbers : (count($rowNumbers) === 1 ? $rowNumbers : []);
                $itemPedimentos = self::resolvePedimentos($relationNumbers, $globalPedimentos);
                $legacyKey = count($itemPedimentos) === 1 ? ($itemPedimentos[0]['key'] ?? null) : null;
                $legacyNumber = count($itemPedimentos) === 1 ? ($itemPedimentos[0]['number'] ?? null) : null;

                $items[] = [
                    'sort_order' => count($items) + 1,
                    'description' => $description,
                    'quantity' => $quantity,
                    'serial_number' => $serial,
                    'key' => $legacyKey,
                    'pedimento' => $legacyNumber,
                    'pedimentos' => $itemPedimentos,
                    'partida' => $partida,
                ];
            }
        }

        return $items;
    }

    /** @param list<string> $numbers @param mixed $globalPedimentos @return list<array{key:?string,number:string,importer_name:?string}> */
    private static function resolvePedimentos(array $numbers, mixed $globalPedimentos): array
    {
        $resolved = [];
        $seen = [];
        foreach ($numbers as $number) {
            [$key, $normalizedNumber, $importerName] = self::resolvePedimento((string) $number, $globalPedimentos);
            if ($normalizedNumber === null || trim($normalizedNumber) === '') {
                continue;
            }
            $identity = strtoupper((string) $key) . ':' . self::digits($normalizedNumber);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $resolved[] = [
                'key' => $key,
                'number' => $normalizedNumber,
                'importer_name' => $importerName,
            ];
        }
        return $resolved;
    }

    /** @param mixed $globalPedimentos @return array{0:?string,1:?string,2:?string} */
    private static function resolvePedimento(?string $number, mixed $globalPedimentos): array
    {
        if ($number === null || trim($number) === '') {
            return [null, null, null];
        }
        $digits = self::digits($number);
        $matches = [];
        if (is_array($globalPedimentos)) {
            foreach ($globalPedimentos as $pedimento) {
                if (! is_array($pedimento)) {
                    continue;
                }
                if (self::digits((string) ($pedimento['number'] ?? '')) !== $digits) {
                    continue;
                }
                $key = isset($pedimento['key']) && trim((string) $pedimento['key']) !== ''
                    ? strtoupper(trim((string) $pedimento['key']))
                    : null;
                $matches[] = [
                    'key' => $key,
                    'number' => trim((string) ($pedimento['number'] ?? $number)),
                    'importer_name' => isset($pedimento['importer_name']) && trim((string) $pedimento['importer_name']) !== ''
                        ? trim((string) $pedimento['importer_name'])
                        : null,
                ];
            }
        }
        if ($matches !== []) {
            usort($matches, static function (array $a, array $b): int {
                $aKey = (string) ($a['key'] ?? '');
                $bKey = (string) ($b['key'] ?? '');
                $aSpecific = str_contains($aKey, '/') ? 1 : 0;
                $bSpecific = str_contains($bKey, '/') ? 1 : 0;
                return ($bSpecific <=> $aSpecific) ?: (strlen($bKey) <=> strlen($aKey));
            });
            return [$matches[0]['key'], $matches[0]['number'], $matches[0]['importer_name']];
        }
        return [null, self::formatPedimento($number), null];
    }

    /** @return list<string> */
    private static function pedimentoNumbersInText(string $text): array
    {
        $result = [];
        $seen = [];
        if (preg_match_all('/\b(\d{2}\s+\d{2}\s+\d{4}\s+\d{7})\b/u', $text, $matches)) {
            foreach ($matches[1] as $number) {
                $formatted = self::formatPedimento((string) $number);
                $digits = self::digits($formatted);
                if ($digits === '' || isset($seen[$digits])) {
                    continue;
                }
                $seen[$digits] = true;
                $result[] = $formatted;
            }
        }
        return $result;
    }

    private static function formatPedimento(string $number): string
    {
        $digits = self::digits($number);
        if (strlen($digits) === 15) {
            return substr($digits, 0, 2) . ' ' . substr($digits, 2, 2) . ' ' . substr($digits, 4, 4) . ' ' . substr($digits, 8, 7);
        }
        return trim(preg_replace('/\s+/u', ' ', $number) ?? $number);
    }

    private static function cleanDescription(string $value): string
    {
        $value = preg_replace('/^DESCRIPCION\s*/iu', '', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B,;");
    }

    private static function cleanSerial(string $value): ?string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = trim($value, " \t\n\r\0\x0B,;");
        return $value !== '' ? $value : null;
    }

    private static function identity(string $value): string
    {
        $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
        return preg_replace('/[^\pL\pN]+/u', '', $value) ?? $value;
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private static function dedupeStructuredItems(array $items): array
    {
        $result = [];
        $seen = [];
        foreach ($items as $item) {
            $pedimentoIdentityParts = [];
            if (isset($item['pedimentos']) && is_array($item['pedimentos'])) {
                foreach ($item['pedimentos'] as $pedimento) {
                    if (! is_array($pedimento)) {
                        continue;
                    }
                    $pedimentoIdentityParts[] = strtoupper(trim((string) ($pedimento['key'] ?? ''))) . ':' . self::digits((string) ($pedimento['number'] ?? ''));
                }
            } elseif (! empty($item['pedimento'])) {
                $pedimentoIdentityParts[] = strtoupper(trim((string) ($item['key'] ?? ''))) . ':' . self::digits((string) ($item['pedimento'] ?? ''));
            }
            sort($pedimentoIdentityParts, SORT_STRING);
            $identity = implode('|', [
                self::identity((string) ($item['description'] ?? '')),
                self::identity((string) ($item['serial_number'] ?? '')),
                implode(',', $pedimentoIdentityParts),
                number_format((float) ($item['quantity'] ?? 0), 3, '.', ''),
            ]);
            if ($identity !== '|||0.000' && isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $item['sort_order'] = count($result) + 1;
            $result[] = $item;
        }
        return $result;
    }
}
