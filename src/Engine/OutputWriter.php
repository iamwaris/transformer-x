<?php

declare(strict_types=1);

namespace TransformerX\Engine;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes the WooCommerce sheet in the shape Access produces: one sheet named qryExportData,
 * 111 headed columns, text cells as text (never formulas), numbers as numbers, blanks as no cell.
 */
final class OutputWriter
{
    private Writer $writer;
    private int $rows = 0;

    public function __construct(string $path)
    {
        $this->writer = new Writer();
        $this->writer->openToFile($path);
        $this->writer->getCurrentSheet()->setName(Schema::OUTPUT_SHEET);
        $this->writer->addRow(self::row(Schema::outputColumns()));
    }

    /** @param list<string|int|float|null> $cells output of Converter::convert()['cells'] */
    public function add(array $cells): void
    {
        $this->writer->addRow(self::row($cells));
        $this->rows++;
    }

    public function close(): int
    {
        $this->writer->close();
        return $this->rows;
    }

    /** Access names the file after the export time: yyyymmdd_hhnnss.xlsx */
    public static function fileName(\DateTimeInterface $at): string
    {
        return $at->format('Ymd_His') . '.xlsx';
    }

    /** @param list<string|int|float|null> $values */
    private static function row(array $values): Row
    {
        $cells = [];
        foreach ($values as $v) {
            $cells[] = match (true) {
                $v === null => new Cell\EmptyCell(null, null),
                is_string($v) => new Cell\StringCell($v, null),
                default => new Cell\NumericCell($v, null),
            };
        }
        return new Row($cells);
    }
}
