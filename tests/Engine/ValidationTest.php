<?php

declare(strict_types=1);

namespace TransformerX\Tests\Engine;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;
use TransformerX\Engine\Analyzer;
use TransformerX\Engine\InputReader;
use TransformerX\Engine\Issue;
use TransformerX\Engine\Schema;

final class ValidationTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    public function testValidFileHasNoIssues(): void
    {
        $result = $this->analyse(Schema::INPUT_COLUMNS, [$this->row('A1'), $this->row('A2')]);
        self::assertSame(0, $result['summary']['errorCount']);
        self::assertTrue($result['summary']['canConvert']);
        self::assertSame(2, $result['summary']['rows']);
    }

    public function testHeaderProblemsAreReportedByName(): void
    {
        $headers = Schema::INPUT_COLUMNS;
        $headers[array_search('Sales Price', $headers, true)] = 'sales price';   // wrong capitals
        unset($headers[array_search('Origin', $headers, true)]);                   // missing
        $headers[] = 'Barcode';                                                    // unknown
        $headers[] = 'Name';                                                       // duplicate
        $codes = $this->codes($this->analyse(array_values($headers), [])['fileIssues']);
        sort($codes);
        self::assertSame(['duplicate_column', 'missing_column', 'misspelled_column', 'unknown_column'], $codes);
    }

    public function testColumnOrderDoesNotMatter(): void
    {
        $headers = array_reverse(Schema::INPUT_COLUMNS);
        $row = array_reverse($this->row('R1'));
        $result = $this->analyse($headers, [$row]);
        self::assertSame(0, $result['summary']['errorCount']);
        self::assertSame('R1', $result['rows'][0]['v']['Internal Reference']);
    }

    public function testBlankAndDuplicateReferencesBlockConversion(): void
    {
        $result = $this->analyse(Schema::INPUT_COLUMNS, [
            $this->row('ABC'), $this->row('abc '), $this->row('   '), $this->row('UNIQUE'),
        ]);
        self::assertFalse($result['summary']['canConvert']);
        self::assertSame(2, $result['summary']['errorsByCode']['duplicate_reference']);
        self::assertSame(1, $result['summary']['errorsByCode']['blank_reference']);
        self::assertSame('ok', $result['rows'][3]['status']);
        self::assertStringContainsString('row(s) 3', $result['rows'][0]['issues'][0]['message']);
    }

    public function testCellTypeErrors(): void
    {
        $text = $this->row('T1');
        $text[array_search('Sales Price', Schema::INPUT_COLUMNS, true)] = '1195';
        $long = $this->row('T2');
        $long[array_search('Name', Schema::INPUT_COLUMNS, true)] = str_repeat('x', 256);
        $bool = $this->row('T3');
        $bool[array_search('Quality', Schema::INPUT_COLUMNS, true)] = true;
        $result = $this->analyse(Schema::INPUT_COLUMNS, [$text, $long, $bool]);
        self::assertSame(['text_in_number_column' => 1, 'text_too_long' => 1, 'boolean_value' => 1], $result['summary']['errorsByCode']);
    }

    public function testBlankRowsAreSkippedAndRowNumbersStayExcelRows(): void
    {
        $result = $this->analyse(Schema::INPUT_COLUMNS, [$this->row('B1'), array_fill(0, 38, null), $this->row('B1')]);
        self::assertSame(1, $result['summary']['blankRowsSkipped']);
        self::assertSame([2, 4], array_column($result['rows'], 'row'));
    }

    public function testRejectsNonWorkbookFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tx');
        $this->files[] = $path;
        file_put_contents($path, "Product Category,Name\nGemstones,X\n");
        $read = (new InputReader())->read($path);
        self::assertSame(['not_xlsx'], $this->codes($read['issues']));
    }

    public function testEmptyAndHeaderOnlySheets(): void
    {
        self::assertSame(['no_rows'], $this->codes($this->analyse(Schema::INPUT_COLUMNS, [])['fileIssues']));
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<mixed> a valid row in INPUT_COLUMNS order */
    private function row(string $ref): array
    {
        $v = array_fill_keys(Schema::INPUT_COLUMNS, null);
        $v['Product Category'] = 'Gemstones';
        $v['Name'] = $ref;
        $v['Internal Reference'] = $ref;
        $v['Product Type'] = 'Single Stone';
        $v['Sales Price'] = 10;
        $v['Forecasted Quantity'] = 1;
        return array_values($v);
    }

    private function analyse(array $headers, array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tx') . '.xlsx';
        $this->files[] = $path;
        $w = new Writer();
        $w->openToFile($path);
        $w->addRow(self::cells($headers));
        foreach ($rows as $r) {
            $w->addRow(self::cells($r));
        }
        $w->close();
        $read = (new InputReader())->read($path);
        return (new Analyzer())->analyse($read['rows'], $read['issues'], $read['blankRows']);
    }

    private static function cells(array $values): Row
    {
        return new Row(array_map(static fn ($v) => match (true) {
            $v === null => new Cell\EmptyCell(null, null),
            is_bool($v) => new Cell\BooleanCell($v, null),
            is_string($v) => new Cell\StringCell($v, null),
            default => new Cell\NumericCell($v, null),
        }, array_values($values)));
    }

    /** @param list<Issue> $issues */
    private function codes(array $issues): array
    {
        return array_map(static fn (Issue $i) => $i->code, $issues);
    }
}
