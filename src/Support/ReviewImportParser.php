<?php

declare(strict_types=1);

namespace ReviewRadar\Support;

use DOMDocument;
use DOMXPath;
use JsonException;
use ReviewRadar\Exceptions\ValidationException;
use ZipArchive;

/**
 * Parses review exports in CSV, TSV, JSON, PDF, XLSX, DOCX, TXT and HTML
 * formats into normalized SubmitReviewRequest rows.
 */
final class ReviewImportParser
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 20_971_520;

    private const FIELD_ALIASES = [
        'text' => ['text', 'review', 'review_text', 'content', 'body', 'comment'],
        'author_name' => ['author_name', 'author', 'reviewer', 'reviewer_name', 'name'],
        'rating' => ['rating', 'stars', 'score'],
        'source' => ['source', 'channel', 'platform'],
        'external_id' => ['external_id', 'id', 'review_id'],
        'source_url' => ['source_url', 'url', 'link', 'review_url'],
    ];

    private function __construct()
    {
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function parse(string $contents, string $filename = ''): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new ValidationException('The import file must be 20 MB or smaller.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => self::parseDelimited($contents, ','),
            'tsv', 'tab' => self::parseDelimited($contents, "\t"),
            'json' => self::parseJson($contents),
            'pdf' => self::parsePdf($contents),
            'xlsx' => self::parseXlsx($contents),
            'docx' => self::parseDocx($contents),
            'txt', 'text', 'md' => self::reviewsFromText($contents, 'txt'),
            'html', 'htm' => self::reviewsFromHtml($contents),
            'xls' => throw new ValidationException('Legacy .xls files are not supported. Save the file as .xlsx or CSV.'),
            'doc' => throw new ValidationException('Legacy .doc files are not supported. Save the file as .docx or PDF.'),
            default => self::parseByContent($contents),
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function fromPayload(array $payload): array
    {
        $list = $payload['reviews'] ?? $payload['rows'] ?? $payload['data'] ?? $payload;
        if (!is_array($list)) {
            throw new ValidationException('The request body must contain a reviews array.');
        }
        if (!array_is_list($list)) {
            $list = [$list];
        }

        return self::normalizeRows($list);
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function normalizeRows(array $items): array
    {
        $rows = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new ValidationException('Review row ' . ($index + 1) . ' must be an object.');
            }
            $rows[] = self::normalizeRow($item);
            if (count($rows) > self::MAX_ROWS) {
                throw new ValidationException('The import file must contain at most ' . self::MAX_ROWS . ' reviews.');
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    public static function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[self::normalizeHeader((string) $key)] = $value;
        }

        $result = [];
        foreach (self::FIELD_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $normalized)) {
                    $result[$field] = $normalized[$alias];
                    break;
                }
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function parseByContent(string $contents): array
    {
        $trimmed = ltrim($contents, "\xEF\xBB\xBF \t\r\n");
        if ($trimmed === '') {
            throw new ValidationException('The import file is empty.');
        }
        if (str_starts_with($trimmed, '%PDF-')) {
            return self::parsePdf($contents);
        }
        if (str_starts_with($trimmed, "\x50\x4B\x03\x04")) {
            $type = self::detectZipType($contents);

            return $type === 'xlsx' ? self::parseXlsx($contents) : self::parseDocx($contents);
        }
        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            return self::parseJson($contents);
        }
        if (str_contains($trimmed, "\t") && !str_contains($trimmed, ',')) {
            return self::parseDelimited($contents, "\t");
        }

        return self::parseDelimited($contents, ',');
    }

    /** @return list<array<string, mixed>> */
    private static function parseDelimited(string $contents, string $delimiter): array
    {
        $contents = ltrim($contents, "\xEF\xBB\xBF");
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new ValidationException('Unable to read the import file.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        $headers = fgetcsv($stream, 0, $delimiter, '"', '');
        if ($headers === false) {
            fclose($stream);
            throw new ValidationException('Unable to read the header row of the import file.');
        }
        $headers = array_map(
            static fn (mixed $header): string => self::normalizeHeader((string) $header),
            $headers,
        );

        $items = [];
        while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($values === [null] || self::isEmptyRow($values)) {
                continue;
            }
            $item = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $item[$header] = $values[$index] ?? '';
            }
            $items[] = $item;
            if (count($items) > self::MAX_ROWS) {
                fclose($stream);
                throw new ValidationException('The import file must contain at most ' . self::MAX_ROWS . ' reviews.');
            }
        }
        fclose($stream);

        $rows = self::normalizeRows($items);
        if ($rows === []) {
            throw new ValidationException('No reviews were found in the import file.');
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function parseJson(string $contents): array
    {
        $contents = ltrim($contents, "\xEF\xBB\xBF \t\r\n");
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ValidationException('The JSON import file is not valid JSON.', [], $e);
        }

        if (!is_array($decoded)) {
            throw new ValidationException('The JSON import file must contain an array of reviews.');
        }

        $rows = self::fromPayload($decoded);
        if ($rows === []) {
            throw new ValidationException('No reviews were found in the import file.');
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function parsePdf(string $contents): array
    {
        if (!function_exists('proc_open')) {
            throw new ValidationException('PDF import requires the proc_open function on the server.');
        }
        $binary = self::pdfToTextBinary();
        if ($binary === null) {
            throw new ValidationException('PDF import requires the pdftotext command (poppler-utils) on the server.');
        }

        $input = self::tempFile($contents, 'pdf');
        try {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open([$binary, '-layout', '-enc', 'UTF-8', $input, '-'], $descriptors, $pipes);
            if (!is_resource($process)) {
                throw new ValidationException('Unable to start PDF text extraction.');
            }
            fclose($pipes[0]);
            $text = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new ValidationException('PDF text extraction failed' . ($errors !== '' ? ': ' . trim($errors) : '.'));
            }
            if (trim($text) === '') {
                throw new ValidationException('The PDF contains no extractable text. Scanned PDFs need OCR before import.');
            }

            return self::reviewsFromText($text, 'pdf');
        } finally {
            @unlink($input);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function parseXlsx(string $contents): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ValidationException('XLSX import requires the PHP zip extension.');
        }
        $path = self::tempFile($contents, 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new ValidationException('The XLSX file could not be opened.');
        }

        try {
            $shared = [];
            $sharedXml = self::zipEntry($zip, 'xl/sharedStrings.xml');
            if ($sharedXml !== null) {
                $shared = self::xlsxSharedStrings($sharedXml);
            }

            $sheetName = 'xl/worksheets/sheet1.xml';
            if ($zip->locateName($sheetName) === false) {
                $sheetName = '';
                for ($index = 1; $index <= 20; $index++) {
                    $candidate = "xl/worksheets/sheet{$index}.xml";
                    if ($zip->locateName($candidate) !== false) {
                        $sheetName = $candidate;
                        break;
                    }
                }
            }
            if ($sheetName === '') {
                throw new ValidationException('The XLSX file does not contain a readable worksheet.');
            }

            $sheetXml = self::zipEntry($zip, $sheetName);
            if ($sheetXml === null) {
                throw new ValidationException('The XLSX worksheet could not be read.');
            }

            $items = self::xlsxRows($sheetXml, $shared);
        } finally {
            $zip->close();
            @unlink($path);
        }

        $rows = self::normalizeRows($items);
        if ($rows === []) {
            throw new ValidationException('No reviews were found in the XLSX file.');
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function parseDocx(string $contents): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ValidationException('DOCX import requires the PHP zip extension.');
        }
        $path = self::tempFile($contents, 'docx');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new ValidationException('The DOCX file could not be opened.');
        }

        try {
            $xml = self::zipEntry($zip, 'word/document.xml');
            if ($xml === null) {
                throw new ValidationException('The DOCX document could not be read.');
            }
        } finally {
            $zip->close();
            @unlink($path);
        }

        $document = new DOMDocument();
        if (!@$document->loadXML($xml)) {
            throw new ValidationException('The DOCX document is not valid XML.');
        }
        $xpath = new DOMXPath($document);

        $tables = $xpath->query('//*[local-name()="tbl"]');
        if ($tables !== false && $tables->length > 0) {
            $lines = [];
            foreach ($tables as $table) {
                $rows = $xpath->query('./*[local-name()="tr"]', $table);
                if ($rows === false) {
                    continue;
                }
                foreach ($rows as $row) {
                    $cells = [];
                    $cellsQuery = $xpath->query('./*[local-name()="tc"]', $row);
                    if ($cellsQuery === false) {
                        continue;
                    }
                    foreach ($cellsQuery as $cell) {
                        $value = '';
                        $texts = $xpath->query('.//*[local-name()="t"]', $cell);
                        if ($texts !== false) {
                            foreach ($texts as $text) {
                                $value .= $text->textContent;
                            }
                        }
                        $cells[] = trim($value);
                    }
                    $lines[] = implode("\t", $cells);
                }
            }

            $joined = trim(implode("\n", $lines));
            if ($joined !== '' && str_contains($joined, "\t")) {
                return self::parseDelimited($joined, "\t");
            }
        }

        $paragraphs = [];
        $nodes = $xpath->query('//*[local-name()="p"]');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $value = '';
                $texts = $xpath->query('.//*[local-name()="t"]', $node);
                if ($texts !== false) {
                    foreach ($texts as $text) {
                        $value .= $text->textContent;
                    }
                }
                $value = trim($value);
                if ($value !== '') {
                    $paragraphs[] = $value;
                }
            }
        }

        return self::reviewsFromText(implode("\n\n", $paragraphs), 'docx');
    }

    /** @return list<array<string, mixed>> */
    private static function reviewsFromHtml(string $contents): array
    {
        $document = new DOMDocument();
        if (@$document->loadHTML('<?xml encoding="UTF-8">' . $contents, LIBXML_NONET)) {
            $xpath = new DOMXPath($document);
            $tables = $xpath->query('//table');
            if ($tables !== false && $tables->length > 0) {
                $lines = [];
                foreach ($tables as $table) {
                    $rows = $xpath->query('.//tr', $table);
                    if ($rows === false) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        $cells = [];
                        $cellNodes = $xpath->query('./th|./td', $row);
                        if ($cellNodes === false) {
                            continue;
                        }
                        foreach ($cellNodes as $cell) {
                            $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? $cell->textContent);
                        }
                        if ($cells !== []) {
                            $lines[] = implode("\t", $cells);
                        }
                    }
                }

                $joined = trim(implode("\n", $lines));
                if ($joined !== '' && str_contains($joined, "\t")) {
                    try {
                        return self::parseDelimited($joined, "\t");
                    } catch (ValidationException) {
                        // Fall back to visible text for tables without review headers.
                    }
                }
            }
        }

        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $contents) ?? $contents;
        $text = preg_replace('/<[^>]+>/', "\n", $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::reviewsFromText($text, 'web');
    }

    /** @return list<array<string, mixed>> */
    private static function reviewsFromText(string $text, string $source): array
    {
        $text = str_replace(["\r\n", "\r", "\f"], ["\n", "\n", "\n\n"], $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            throw new ValidationException('No review text was found in the import file.');
        }

        $paragraphs = preg_split('/\n\s*\n+/', $text) ?: [];
        $blocks = [];
        if (count($paragraphs) > 1) {
            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph !== '') {
                    $blocks[] = $paragraph;
                }
            }
        } else {
            $current = '';
            foreach (preg_split('/\n/', $text) ?: [] as $line) {
                $line = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line);
                if ($line === '') {
                    if ($current !== '') {
                        $blocks[] = $current;
                        $current = '';
                    }
                    continue;
                }
                if (preg_match('/^(page\s+\d+(\s+of\s+\d+)?|\d+)$/i', $line) === 1) {
                    continue;
                }
                $current = $current === '' ? $line : $current . ' ' . $line;
                if (preg_match('/[.!?]["\')\]]?$/u', $line) === 1 && mb_strlen($current) >= 15) {
                    $blocks[] = $current;
                    $current = '';
                }
            }
            if ($current !== '') {
                $blocks[] = $current;
            }
        }

        $rows = [];
        foreach ($blocks as $block) {
            $block = trim(preg_replace('/\s*\n\s*/u', ' ', $block) ?? $block);
            $block = trim(preg_replace('/[ \t]+/u', ' ', $block) ?? $block);
            if (mb_strlen($block) < 5) {
                continue;
            }
            if (preg_match('/^(review|reviews|rating|stars|date|author|customer|source|sentiment)\s*[:\-]?\s*$/i', $block) === 1) {
                continue;
            }
            $words = preg_split('/[^a-z0-9]+/', strtolower($block), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $knownHeadings = [
                'review', 'reviews', 'reviewer', 'author', 'name', 'stars', 'rating',
                'date', 'customer', 'source', 'sentiment', 'text', 'comment', 'url', 'link', 'id',
            ];
            if ($words !== [] && array_diff($words, $knownHeadings) === []) {
                continue;
            }
            $rows[] = ['text' => mb_substr($block, 0, 5000), 'source' => $source];
            if (count($rows) > self::MAX_ROWS) {
                throw new ValidationException('The import file must contain at most ' . self::MAX_ROWS . ' reviews.');
            }
        }

        if ($rows === []) {
            throw new ValidationException('No review text was found in the import file.');
        }

        return $rows;
    }

    /** @return list<string> */
    private static function xlsxSharedStrings(string $xml): array
    {
        $document = new DOMDocument();
        if (!@$document->loadXML($xml)) {
            throw new ValidationException('The XLSX shared strings are not valid XML.');
        }
        $xpath = new DOMXPath($document);
        $strings = [];
        $nodes = $xpath->query('//*[local-name()="si"]');
        if ($nodes === false) {
            return $strings;
        }
        foreach ($nodes as $node) {
            $value = '';
            $texts = $xpath->query('.//*[local-name()="t"]', $node);
            if ($texts !== false) {
                foreach ($texts as $text) {
                    $value .= $text->textContent;
                }
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param list<string> $shared
     *
     * @return list<array<string, mixed>>
     */
    private static function xlsxRows(string $xml, array $shared): array
    {
        $document = new DOMDocument();
        if (!@$document->loadXML($xml)) {
            throw new ValidationException('The XLSX worksheet is not valid XML.');
        }
        $xpath = new DOMXPath($document);
        $rowNodes = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
        if ($rowNodes === false) {
            return [];
        }

        $headers = null;
        $items = [];
        foreach ($rowNodes as $rowNode) {
            $values = [];
            $cells = $xpath->query('./*[local-name()="c"]', $rowNode);
            if ($cells === false) {
                continue;
            }
            foreach ($cells as $cell) {
                $column = self::xlsxColumnIndex($cell->getAttribute('r'));
                $type = $cell->getAttribute('t');
                $value = '';

                if ($type === 'inlineStr') {
                    $texts = $xpath->query('.//*[local-name()="t"]', $cell);
                    if ($texts !== false) {
                        foreach ($texts as $text) {
                            $value .= $text->textContent;
                        }
                    }
                } else {
                    $valueNode = $xpath->query('./*[local-name()="v"]', $cell);
                    $raw = ($valueNode !== false && $valueNode->item(0) !== null) ? $valueNode->item(0)->textContent : '';
                    $value = $type === 's' ? ($shared[(int) $raw] ?? '') : $raw;
                }

                $values[$column] = $value;
            }

            if ($values === []) {
                continue;
            }

            $maxColumn = max(array_keys($values));
            $ordered = [];
            for ($column = 0; $column <= $maxColumn; $column++) {
                $ordered[] = $values[$column] ?? '';
            }

            if ($headers === null) {
                $headers = array_map(
                    static fn (string $header): string => self::normalizeHeader($header),
                    array_map('strval', $ordered),
                );
                continue;
            }
            if (self::isEmptyRow($ordered)) {
                continue;
            }

            $item = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $item[$header] = $ordered[$index] ?? '';
                }
            }
            $items[] = $item;
            if (count($items) > self::MAX_ROWS) {
                throw new ValidationException('The import file must contain at most ' . self::MAX_ROWS . ' reviews.');
            }
        }

        return $items;
    }

    private static function xlsxColumnIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]+)/i', $reference, $matches) !== 1) {
            return 0;
        }

        $index = 0;
        foreach (str_split(strtoupper($matches[1])) as $character) {
            $index = $index * 26 + (ord($character) - 64);
        }

        return $index - 1;
    }

    private static function detectZipType(string $contents): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ValidationException('XLSX and DOCX import require the PHP zip extension.');
        }
        $path = self::tempFile($contents, 'zip');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new ValidationException('The uploaded archive could not be opened.');
        }
        try {
            if ($zip->locateName('xl/workbook.xml') !== false) {
                return 'xlsx';
            }
            if ($zip->locateName('word/document.xml') !== false) {
                return 'docx';
            }
        } finally {
            $zip->close();
            @unlink($path);
        }

        throw new ValidationException('Unsupported ZIP-based file. Upload XLSX or DOCX.');
    }

    private static function pdfToTextBinary(): ?string
    {
        $configured = getenv('PDFTOTEXT_BIN');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $path = getenv('PATH');
        if (is_string($path)) {
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pdftotext';
                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function zipEntry(ZipArchive $zip, string $name, int $maxBytes = 52_428_800): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ((int) ($stat['size'] ?? 0) > $maxBytes) {
            throw new ValidationException('The import file contains an entry that is too large.');
        }

        $contents = $zip->getFromName($name);

        return is_string($contents) ? $contents : null;
    }

    private static function tempFile(string $contents, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rr-' . $suffix . '-');
        if ($path === false || file_put_contents($path, $contents) === false) {
            if (is_string($path)) {
                @unlink($path);
            }
            throw new ValidationException('Unable to create a temporary file for the import.');
        }
        @chmod($path, 0600);

        return $path;
    }

    private static function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? '';

        return trim($header, '_');
    }

    /** @param list<mixed> $values */
    private static function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
