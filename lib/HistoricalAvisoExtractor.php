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
            'parser' => null,
            'error' => null,
        ];

        try {
            $text = self::docxText($path);
            $result['driver'] = 'unzip-docx';
            $result['text'] = $text;
            $result['parser'] = HistoricalAvisoParser::parse($text, $fileName);
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

    private static function docxText(string $path): string
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException(
                'La extensión PHP ZipArchive no está disponible para leer DOCX.'
            );
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'El archivo DOCX no existe o no puede leerse.'
            );
        }

        $zip = new ZipArchive();

        $openResult = $zip->open($path);

        if ($openResult !== true) {
            throw new RuntimeException(
                'El archivo DOCX no pudo abrirse como paquete ZIP. Código: '
                . (string) $openResult
            );
        }

        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($xml) || trim($xml) === '') {
            throw new RuntimeException(
                'El DOCX no contiene word/document.xml o está vacío.'
            );
        }

        /*
        * Preservamos la estructura básica del Word:
        *
        * - tabulaciones
        * - saltos de línea
        * - párrafos
        * - filas de tablas
        * - celdas de tablas
        *
        * Esto es importante para los Avisos porque las mercancías
        * vienen organizadas dentro de tablas.
        */
        $xml = preg_replace(
            '/<w:tab\b[^>]*\/>/iu',
            "\t",
            $xml
        ) ?? $xml;

        $xml = preg_replace(
            '/<w:br\b[^>]*\/>/iu',
            "\n",
            $xml
        ) ?? $xml;

        $xml = preg_replace(
            '/<\/w:p>/iu',
            "\n",
            $xml
        ) ?? $xml;

        $xml = preg_replace(
            '/<\/w:tr>/iu',
            "\n",
            $xml
        ) ?? $xml;

        $xml = preg_replace(
            '/<\/w:tc>/iu',
            "\t",
            $xml
        ) ?? $xml;

        $text = strip_tags($xml);

        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_XML1,
            'UTF-8'
        );

        return self::normalizeExtractedText($text);
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
        $data = is_array($parser['data'] ?? null) ? $parser['data'] : [];
        $confidence = is_array($parser['confidence'] ?? null) ? $parser['confidence'] : [];
        $rows = self::docbookRows($xml, 4);

        $items = [];
        $importers = [];
        $rowPedimentoImporters = [];
        $rigArea = null;

        foreach ($rows as $index => $row) {
            if ($index === 0 || count($row) !== 4) {
                continue;
            }
            [$manifest, $importer, $merchandise, $operation] = $row;
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
            $confidence['items'] = 0.94;
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

                $itemNumber = null;
                $itemNumbers = self::pedimentoNumbersInText($match[0]);
                if (count($itemNumbers) === 1) {
                    $itemNumber = $itemNumbers[0];
                } elseif (count($rowNumbers) === 1) {
                    $itemNumber = $rowNumbers[0];
                }
                [$key, $normalizedNumber] = self::resolvePedimento($itemNumber, $globalPedimentos);

                $items[] = [
                    'sort_order' => count($items) + 1,
                    'description' => $description,
                    'quantity' => $quantity,
                    'serial_number' => $serial,
                    'key' => $key,
                    'pedimento' => $normalizedNumber,
                    'partida' => $partida,
                ];
            }
        }

        return $items;
    }

    /** @param mixed $globalPedimentos @return array{0:?string,1:?string} */
    private static function resolvePedimento(?string $number, mixed $globalPedimentos): array
    {
        if ($number === null || trim($number) === '') {
            return [null, null];
        }
        $digits = self::digits($number);
        if (is_array($globalPedimentos)) {
            foreach ($globalPedimentos as $pedimento) {
                if (! is_array($pedimento)) {
                    continue;
                }
                if (self::digits((string) ($pedimento['number'] ?? '')) === $digits) {
                    return [
                        isset($pedimento['key']) && trim((string) $pedimento['key']) !== '' ? strtoupper(trim((string) $pedimento['key'])) : null,
                        trim((string) ($pedimento['number'] ?? $number)),
                    ];
                }
            }
        }
        return [null, self::formatPedimento($number)];
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
            $identity = implode('|', [
                self::identity((string) ($item['description'] ?? '')),
                self::identity((string) ($item['serial_number'] ?? '')),
                self::digits((string) ($item['pedimento'] ?? '')),
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
