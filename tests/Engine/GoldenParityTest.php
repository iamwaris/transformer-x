<?php

declare(strict_types=1);

namespace TransformerX\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TransformerX\Engine\Analyzer;
use TransformerX\Engine\Exporter;
use TransformerX\Engine\InputReader;
use TransformerX\Tests\Tools\XlsxCells;

/**
 * The output must match what the Access application (DataTransformer v3.5) exports for the same input,
 * cell for cell. tests/fixtures/golden/*.xlsx were produced by Access via tests/tools/access_golden.ps1.
 */
final class GoldenParityTest extends TestCase
{
    public static function files(): array
    {
        return [
            'V5 sample (4,259 rows)' => ['sample_v5'],
            'edge cases: price, size, categories, attributes, text' => ['edge_cases'],
            'edge cases: number precision, whitespace, numeric text' => ['edge_cases_2'],
            'edge cases: rounding and E-notation' => ['edge_cases_3'],
        ];
    }

    #[DataProvider('files')]
    public function testOutputMatchesAccess(string $name): void
    {
        $dir = dirname(__DIR__) . '/fixtures';
        $out = tempnam(sys_get_temp_dir(), 'tx') . '.xlsx';
        try {
            $read = (new InputReader())->read("$dir/input/$name.xlsx");
            $analysis = (new Analyzer())->analyse($read['rows'], $read['issues'], $read['blankRows']);
            self::assertSame([], $analysis['fileIssues'], 'file-level issues');
            (new Exporter())->export($analysis['rows'], $out);

            $diffs = XlsxCells::diff("$dir/golden/golden_$name.xlsx", $out);
            self::assertSame([], $diffs, "Output differs from Access:\n" . implode("\n", $diffs));
        } finally {
            @unlink($out);
        }
    }
}
