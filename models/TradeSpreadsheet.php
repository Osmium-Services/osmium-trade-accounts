<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Reads an uploaded customer spreadsheet (CSV or XLSX) into rows of plain text, the first row being the headings.
 * No database, no globals. XLSX is read with ZipArchive and SimpleXML, so there is no library to install.
 */
final class TradeSpreadsheet
{
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 60;
    public const MAX_BYTES = 10 * 1024 * 1024; // a 5,000-row file is about 1 MB
    private const MAX_XML_BYTES = 10 * 1024 * 1024;
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @param string $path The file on disk
     * @param string $extension 'csv' or 'xlsx'
     * @return array<int, array<int, string>> The rows, blank rows dropped, every row as wide as the widest
     * @throws \InvalidArgumentException with a plain-words reason when the file cannot be used
     */
    public static function read(string $path, string $extension): array
    {
        $tooBig = \filesize($path) > self::MAX_BYTES;
        if ($tooBig) throw new \InvalidArgumentException('The file is larger than ' . (self::MAX_BYTES / 1048576) . ' MB. Split it into smaller files');

        $rows = match ($extension) {
            'csv' => self::readCsv($path),
            'xlsx' => self::readXlsx($path),
            default => throw new \InvalidArgumentException('Please upload a CSV or Excel (.xlsx) file'),
        };

        $rows = self::withoutBlankRows($rows);
        $empty = $rows === [];
        if ($empty) throw new \InvalidArgumentException('The file has no rows in it');

        $tooMany = \count($rows) > self::MAX_ROWS + 1;
        if ($tooMany) throw new \InvalidArgumentException('The file has more than ' . \number_format(self::MAX_ROWS) . ' rows. Split it into smaller files');

        return self::squared($rows);
    }

    /** The file type from its name: 'csv', 'xlsx', or null for anything else (an old .xls is refused, not guessed at). */
    public static function extension(string $filename): ?string
    {
        $extension = \strtolower(\pathinfo($filename, PATHINFO_EXTENSION));

        return \in_array($extension, ['csv', 'xlsx'], true) ? $extension : null;
    }

    /** @return array<int, array<int, string>> */
    private static function readCsv(string $path): array
    {
        $content = (string) \file_get_contents($path);
        $content = (string) \preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $notUtf8 = !\mb_check_encoding($content, 'UTF-8');
        if ($notUtf8) $content = \mb_convert_encoding($content, 'UTF-8', 'Windows-1252'); // what Excel writes for "CSV" on Windows

        $stream = \fopen('php://temp', 'r+');
        \fwrite($stream, $content);
        \rewind($stream);

        $delimiter = self::delimiter($content);
        $rows = [];
        while (($fields = \fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = \array_map(static fn($value) => \trim((string) $value), $fields);
            $tooMany = \count($rows) > self::MAX_ROWS + 1;
            if ($tooMany) break; // read() says so; no need to read the rest
        }
        \fclose($stream);

        return $rows;
    }

    /** Comma, semicolon (Excel in much of Europe), tab or pipe: whichever the first line uses most. */
    private static function delimiter(string $content): string
    {
        $firstLine = \strtok($content, "\r\n") ?: '';
        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = \substr_count($firstLine, $candidate);
            $better = $count > $bestCount;
            if ($better) [$best, $bestCount] = [$candidate, $count];
        }

        return $best;
    }

    /** @return array<int, array<int, string>> */
    private static function readXlsx(string $path): array
    {
        $isZip = \str_starts_with((string) \file_get_contents($path, false, null, 0, 2), 'PK');
        $zip = new \ZipArchive();
        $opened = $isZip && $zip->open($path) === true;
        if (!$opened) throw new \InvalidArgumentException('That does not look like a valid Excel (.xlsx) file');

        try {
            $sheet = self::xml($zip, self::firstSheetPath($zip));
            $strings = self::sharedStrings($zip);
        } finally {
            $zip->close();
        }

        return self::sheetRows($sheet, $strings);
    }

    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';
        $workbook = self::xml($zip, 'xl/workbook.xml', required: false);
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels', required: false);
        $missing = $workbook === null || $rels === null;
        if ($missing) return $fallback;

        $sheets = $workbook->children(self::MAIN_NS)->sheets->children(self::MAIN_NS)->sheet;
        $first = $sheets[0] ?? null;
        $noSheet = $first === null;
        if ($noSheet) return $fallback;

        $relId = (string) $first->attributes(self::REL_NS)['id'];
        foreach ($rels->children(self::PACKAGE_REL_NS)->Relationship as $rel) {
            $isIt = (string) $rel->attributes()['Id'] === $relId;
            if ($isIt) return self::partPath((string) $rel->attributes()['Target']);
        }

        return $fallback;
    }

    /** A relationship target is relative to the xl/ folder ("worksheets/sheet1.xml"), or absolute from the package root when it starts with a slash ("/xl/worksheets/sheet1.xml"). */
    private static function partPath(string $target): string
    {
        $absolute = \str_starts_with($target, '/');

        return $absolute ? \ltrim($target, '/') : 'xl/' . $target;
    }

    /** @return array<int, string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml', required: false);
        $none = $xml === null;
        if ($none) return [];

        $strings = [];
        foreach ($xml->children(self::MAIN_NS)->si as $item) {
            $strings[] = self::richText($item);
        }

        return $strings;
    }

    /** The text of a shared-string or inline-string item: plain <t>, or the runs of rich text (phonetic hints are skipped). */
    private static function richText(\SimpleXMLElement $item): string
    {
        $item = $item->children(self::MAIN_NS);
        $text = (string) $item->t;
        foreach ($item->r as $run) {
            $text .= (string) $run->children(self::MAIN_NS)->t;
        }

        return $text;
    }

    /**
     * @param array<int, string> $strings
     * @return array<int, array<int, string>>
     */
    private static function sheetRows(\SimpleXMLElement $sheet, array $strings): array
    {
        $rows = [];
        foreach ($sheet->children(self::MAIN_NS)->sheetData->children(self::MAIN_NS)->row as $row) {
            $cells = [];
            foreach ($row->children(self::MAIN_NS)->c as $cell) {
                $column = self::columnIndex((string) $cell->attributes()['r']);
                $tooWide = $column >= self::MAX_COLUMNS;
                if ($tooWide) continue;

                $cells[$column] = \trim(self::cellText($cell, $strings));
            }

            $rows[] = self::filled($cells);
            $tooMany = \count($rows) > self::MAX_ROWS + 1000; // blank rows may follow; read() counts the real ones
            if ($tooMany) break;
        }

        return $rows;
    }

    /** @param array<int, string> $strings */
    private static function cellText(\SimpleXMLElement $cell, array $strings): string
    {
        $type = (string) $cell->attributes()['t'];
        $cell = $cell->children(self::MAIN_NS);

        return match ($type) {
            's' => $strings[(int) $cell->v] ?? '',
            'inlineStr' => self::richText($cell->is),
            'b' => (string) $cell->v === '1' ? 'TRUE' : 'FALSE',
            'e' => '',
            default => (string) $cell->v,
        };
    }

    /** "C7" => 2, "AA1" => 26. A cell without a reference gets a high index and is dropped by the width limit. */
    private static function columnIndex(string $reference): int
    {
        $letters = \preg_replace('/[^A-Z]/', '', \strtoupper($reference)) ?? '';
        $index = 0;
        foreach (\str_split($letters) as $letter) {
            $index = $index * 26 + (\ord($letter) - 64);
        }

        return $index > 0 ? $index - 1 : self::MAX_COLUMNS;
    }

    /**
     * @param array<int, string> $cells by column index, gaps where cells were empty
     * @return array<int, string>
     */
    private static function filled(array $cells): array
    {
        $none = $cells === [];
        if ($none) return [];

        $row = [];
        for ($column = 0; $column <= \max(\array_keys($cells)); $column++) {
            $row[] = $cells[$column] ?? '';
        }

        return $row;
    }

    private static function xml(\ZipArchive $zip, string $name, bool $required = true): ?\SimpleXMLElement
    {
        $stat = $zip->statName($name);
        $missing = $stat === false;
        if ($missing) {
            if ($required) throw new \InvalidArgumentException('That Excel file has no readable sheet in it');
            return null;
        }

        $tooBig = $stat['size'] > self::MAX_XML_BYTES;
        if ($tooBig) throw new \InvalidArgumentException('That Excel file is too large. Save it as CSV, or split it');

        $xml = \simplexml_load_string((string) $zip->getFromName($name), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        $broken = $xml === false;
        if ($broken) throw new \InvalidArgumentException('That Excel file could not be read');

        return $xml;
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return array<int, array<int, string>>
     */
    private static function withoutBlankRows(array $rows): array
    {
        $kept = \array_filter($rows, static fn(array $row) => \implode('', $row) !== '');

        return \array_values($kept);
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return array<int, array<int, string>>
     */
    private static function squared(array $rows): array
    {
        $width = \min(self::MAX_COLUMNS, \max(\array_map('count', $rows)));

        return \array_map(static fn(array $row) => \array_pad(\array_slice($row, 0, $width), $width, ''), $rows);
    }
}
