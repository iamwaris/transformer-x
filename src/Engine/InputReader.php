<?php

declare(strict_types=1);

namespace TransformerX\Engine;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads the first worksheet of an upload and stores each value the way the Access import
 * (DoCmd.TransferSpreadsheet acImport into tempImportData) would, plus strict format checks.
 */
final class InputReader
{
    public function __construct(
        private readonly int $maxRows = 20000,
        private readonly int $maxUncompressedBytes = 200 * 1024 * 1024,
    ) {
    }

    /**
     * @return array{rows: list<array{row: int, v: array<string, string|float|null>}>, issues: list<Issue>, blankRows: int}
     */
    public function read(string $path): array
    {
        $issues = [];
        $fileIssue = $this->checkPackage($path);
        if ($fileIssue !== null) {
            return ['rows' => [], 'issues' => [$fileIssue], 'blankRows' => 0];
        }

        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_FORMAT_DATES = false;
        $reader = new Reader($options);

        try {
            $reader->open($path);
        } catch (\Throwable) {
            return ['rows' => [], 'issues' => [Issue::error('file_unreadable', 'The file could not be opened as an Excel workbook. Save it from Excel as .xlsx and try again.')], 'blankRows' => 0];
        }

        $rows = [];
        $blankRows = 0;
        $columns = null;  // column index => input column name
        $rowNo = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNo++;
                    $cells = $row->getCells();
                    if ($columns === null) {
                        [$columns, $headerIssues] = $this->mapHeader($cells);
                        array_push($issues, ...$headerIssues);
                        if ($headerIssues !== []) {
                            break 2;  // fix the header first; row-level messages would only be noise
                        }
                        continue;
                    }
                    if (count($rows) >= $this->maxRows) {
                        $issues[] = Issue::error('too_many_rows', "The sheet has more than {$this->maxRows} product rows. Split it into smaller files.");
                        break 2;
                    }
                    [$values, $blank, $cellIssues] = $this->readRow($cells, $columns, $rowNo);
                    if ($blank) {
                        $blankRows++;
                        continue;
                    }
                    array_push($issues, ...$cellIssues);
                    $rows[] = ['row' => $rowNo, 'v' => $values];
                }
                break;  // Access imports the first worksheet only
            }
        } catch (\Throwable) {
            $issues[] = Issue::error('file_unreadable', 'The workbook is damaged or uses a format this tool cannot read. Open it in Excel, save it as .xlsx and try again.');
        } finally {
            $reader->close();
        }

        if ($columns === null && $issues === []) {
            $issues[] = Issue::error('empty_sheet', 'The first worksheet is empty. Row 1 must hold the column headers.');
        } elseif ($rows === [] && !self::hasErrors($issues)) {
            $issues[] = Issue::error('no_rows', 'The sheet has headers but no product rows.');
        }

        return ['rows' => $rows, 'issues' => $issues, 'blankRows' => $blankRows];
    }

    /** @param list<Issue> $issues */
    public static function hasErrors(array $issues): bool
    {
        foreach ($issues as $i) {
            if ($i->severity === Issue::ERROR) {
                return true;
            }
        }
        return false;
    }

    public static function columnLetter(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    /** Rejects anything that is not a sane .xlsx package before the spreadsheet parser touches it. */
    private function checkPackage(string $path): ?Issue
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return Issue::error('not_xlsx', 'This is not an .xlsx file. Save the sheet from Excel as "Excel Workbook (*.xlsx)".');
        }
        try {
            if ($zip->locateName('xl/workbook.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
                return Issue::error('not_xlsx', 'This is not an .xlsx workbook. Save the sheet from Excel as "Excel Workbook (*.xlsx)".');
            }
            if ($zip->locateName('xl/vbaProject.bin') !== false) {
                return Issue::error('macro_workbook', 'Workbooks with macros are not accepted. Save the sheet as a plain .xlsx file.');
            }
            if ($zip->numFiles > 2000) {
                return Issue::error('not_xlsx', 'The file has an unexpected structure and was rejected.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > $this->maxUncompressedBytes) {
                    return Issue::error('too_large', 'The workbook expands to more data than this tool accepts. Remove unused sheets or split the file.');
                }
            }
            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<int, Cell> $cells
     * @return array{0: array<int, string>, 1: list<Issue>}
     */
    private function mapHeader(array $cells): array
    {
        $issues = [];
        $columns = [];
        $seen = [];
        $expectedByKey = [];
        foreach (Schema::INPUT_COLUMNS as $c) {
            $expectedByKey[mb_strtolower(trim($c))] = $c;
        }

        foreach ($cells as $index => $cell) {
            $raw = $cell->getValue();
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                continue;  // empty header cell; data under it is reported per row
            }
            $name = is_string($raw) ? $raw : (string) $raw;
            $letter = self::columnLetter($index);
            if (in_array($name, Schema::INPUT_COLUMNS, true)) {
                if (isset($seen[$name])) {
                    $issues[] = Issue::error('duplicate_column', "Column \"$name\" appears twice (columns {$seen[$name]} and $letter). Keep only one.", 1, $name);
                    continue;
                }
                $seen[$name] = $letter;
                $columns[$index] = $name;
                continue;
            }
            $near = $expectedByKey[mb_strtolower(trim($name))] ?? null;
            $issues[] = $near !== null
                ? Issue::error('misspelled_column', "Column $letter is headed \"$name\". Rename it to \"$near\" exactly (spelling, capitals and spaces must match).", 1, $near)
                : Issue::error('unknown_column', "Column $letter (\"$name\") is not part of the upload format. Remove it.", 1, $name);
        }

        foreach (Schema::INPUT_COLUMNS as $c) {
            if (!isset($seen[$c]) && !$this->mentioned($issues, $c)) {
                $issues[] = Issue::error('missing_column', "Column \"$c\" is missing. Add it to row 1 (it can be left empty).", 1, $c);
            }
        }
        return [$columns, $issues];
    }

    /** @param list<Issue> $issues */
    private function mentioned(array $issues, string $column): bool
    {
        foreach ($issues as $i) {
            if ($i->column === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, Cell> $cells
     * @param array<int, string> $columns
     * @return array{0: array<string, string|float|null>, 1: bool, 2: list<Issue>}
     */
    private function readRow(array $cells, array $columns, int $rowNo): array
    {
        $values = array_fill_keys(Schema::INPUT_COLUMNS, null);
        $issues = [];
        $blank = true;

        foreach ($cells as $index => $cell) {
            $raw = $cell->getValue();
            if ($raw === null || $raw === '') {
                continue;
            }
            $blank = false;
            $column = $columns[$index] ?? null;
            if ($column === null) {
                $issues[] = Issue::error('unheaded_value', 'Cell ' . self::columnLetter($index) . "$rowNo has a value but its column has no header. Clear the cell or remove the column.", $rowNo);
                continue;
            }
            [$value, $issue] = $this->convertCell($raw, $column, $rowNo);
            $values[$column] = $value;
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }
        return [$values, $blank, $issues];
    }

    /** @return array{0: string|float|null, 1: ?Issue} */
    private function convertCell(mixed $raw, string $column, int $rowNo): array
    {
        if ($raw instanceof \DateTimeInterface || $raw instanceof \DateInterval) {
            return [null, Issue::error('date_value', "\"$column\" in row $rowNo is formatted as a date or time. Format the cell as a number or text.", $rowNo, $column)];
        }
        if (is_bool($raw)) {
            return [null, Issue::error('boolean_value', "\"$column\" in row $rowNo is TRUE/FALSE. Enter a number or text instead.", $rowNo, $column)];
        }

        if (Schema::isNumericColumn($column)) {
            if (is_int($raw) || is_float($raw)) {
                if (!is_finite((float) $raw)) {
                    return [null, Issue::error('not_a_number', "\"$column\" in row $rowNo is not a valid number.", $rowNo, $column)];
                }
                return [(float) $raw, null];
            }
            // Access rejects text in a Double column ("Type Conversion Failure") and stores nothing.
            $shown = mb_substr((string) $raw, 0, 40);
            return [null, Issue::error('text_in_number_column', "\"$column\" in row $rowNo must be a number, but the cell holds text (\"$shown\"). In Excel, convert the cell to a number.", $rowNo, $column)];
        }

        $text = is_string($raw) ? $raw : AccessFormat::importNumberToText((float) $raw);
        if (mb_strlen($text, 'UTF-8') > Schema::MAX_TEXT_LENGTH) {
            return [$text, Issue::error('text_too_long', "\"$column\" in row $rowNo is " . mb_strlen($text, 'UTF-8') . ' characters long. The limit is ' . Schema::MAX_TEXT_LENGTH . '.', $rowNo, $column)];
        }
        return [$text, null];
    }
}
