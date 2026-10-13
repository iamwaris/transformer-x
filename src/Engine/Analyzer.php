<?php

declare(strict_types=1);

namespace TransformerX\Engine;

/**
 * Whole-file checks and statistics: blank/duplicate Internal References (blocked), per-row
 * warnings from the converter, and the totals shown in the preview and stored after conversion.
 */
final class Analyzer
{
    public function __construct(private readonly Converter $converter = new Converter())
    {
    }

    /**
     * @param list<array{row: int, v: array<string, string|float|null>}> $rows
     * @param list<Issue> $readerIssues
     * @return array{rows: list<array{row: int, v: array, status: string, issues: list<array>}>, fileIssues: list<Issue>, summary: array}
     */
    public function analyse(array $rows, array $readerIssues, int $blankRows): array
    {
        $rowIssues = [];
        $fileIssues = [];
        foreach ($readerIssues as $issue) {
            if ($issue->row !== null && $issue->row > 1) {
                $rowIssues[$issue->row][] = $issue;
            } else {
                $fileIssues[] = $issue;
            }
        }

        // Internal Reference is the join key between the two Access tables: blanks vanish, duplicates multiply.
        $byKey = [];
        foreach ($rows as $r) {
            $ref = $r['v']['Internal Reference'];
            if ($ref === null || AccessFormat::vbaTrim($ref) === '') {
                $rowIssues[$r['row']][] = Issue::error('blank_reference', 'Internal Reference is empty. Every product needs a unique Internal Reference (it becomes the SKU).', $r['row'], 'Internal Reference');
                continue;
            }
            $byKey[AccessFormat::jetKey($ref)][] = $r['row'];
        }
        foreach ($byKey as $rowNos) {
            if (count($rowNos) < 2) {
                continue;
            }
            foreach ($rowNos as $rowNo) {
                $others = implode(', ', array_diff($rowNos, [$rowNo]));
                $rowIssues[$rowNo][] = Issue::error('duplicate_reference', "Internal Reference is also used in row(s) $others. References must be unique (capitals and trailing spaces are ignored when comparing).", $rowNo, 'Internal Reference');
            }
        }

        $stats = self::emptyStats();
        $stats['rows'] = count($rows);
        $stats['blankRowsSkipped'] = $blankRows;
        $out = [];

        foreach ($rows as $r) {
            $issues = $rowIssues[$r['row']] ?? [];
            $hasError = InputReader::hasErrors($issues);
            if (!$hasError) {
                $c = $this->converter->convert($r['v']);
                foreach ($c['warnings'] as $w) {
                    $issues[] = Issue::warning($w['code'], $w['message'], $r['row']);
                }
                self::accumulate($stats, $r['v'], $c);
            }
            foreach ($issues as $i) {
                $stats[$i->severity === Issue::ERROR ? 'errorsByCode' : 'warningsByCode'][$i->code] = ($stats[$i->severity === Issue::ERROR ? 'errorsByCode' : 'warningsByCode'][$i->code] ?? 0) + 1;
            }
            $status = $hasError ? 'error' : ($issues === [] ? 'ok' : 'warning');
            $stats['rowsBy' . ucfirst($status)]++;
            $out[] = ['row' => $r['row'], 'v' => $r['v'], 'status' => $status, 'issues' => array_map(static fn (Issue $i) => $i->jsonSerialize(), $issues)];
        }

        foreach ($fileIssues as $i) {
            $key = $i->severity === Issue::ERROR ? 'errorsByCode' : 'warningsByCode';
            $stats[$key][$i->code] = ($stats[$key][$i->code] ?? 0) + 1;
        }
        $stats['errorCount'] = array_sum($stats['errorsByCode']);
        $stats['warningCount'] = array_sum($stats['warningsByCode']);
        $stats['canConvert'] = $stats['errorCount'] === 0 && $stats['rows'] > 0;
        ksort($stats['byCategory']);
        ksort($stats['byProductType']);

        return ['rows' => $out, 'fileIssues' => $fileIssues, 'summary' => $stats];
    }

    public static function emptyStats(): array
    {
        return [
            'rows' => 0, 'blankRowsSkipped' => 0, 'rowsByOk' => 0, 'rowsByWarning' => 0, 'rowsByError' => 0,
            'errorCount' => 0, 'warningCount' => 0, 'errorsByCode' => [], 'warningsByCode' => [],
            'totalCents' => 0, 'zeroPrice' => 0, 'blankPrice' => 0, 'negativePrice' => 0, 'parcelPriced' => 0,
            'quantityPriced' => 0, 'attributesDropped' => 0, 'attributeCount' => 0,
            'byCategory' => [], 'byProductType' => [], 'canConvert' => false,
        ];
    }

    /** Adds one converted row to the totals. */
    public static function accumulate(array &$stats, array $v, array $c): void
    {
        $cents = $c['price']['cents'];
        $stats['totalCents'] += $cents ?? 0;
        if ($cents === null) {
            $stats['blankPrice']++;
        } elseif ($cents === 0) {
            $stats['zeroPrice']++;
        } elseif ($cents < 0) {
            $stats['negativePrice']++;
        }
        $stats[$c['price']['formula'] === 'parcel' ? 'parcelPriced' : 'quantityPriced']++;
        $stats['attributeCount'] += count($c['attributes']);
        if ($c['droppedAttributes'] !== []) {
            $stats['attributesDropped']++;
        }
        $category = AccessFormat::vbaTrim($v['Product Category'] ?? '');
        $type = AccessFormat::vbaTrim($v['Product Type'] ?? '');
        foreach ([['byCategory', $category === '' ? '(none)' : $category], ['byProductType', $type === '' ? '(none)' : $type]] as [$key, $name]) {
            $stats[$key][$name] ??= ['rows' => 0, 'cents' => 0, 'zeroPrice' => 0];
            $stats[$key][$name]['rows']++;
            $stats[$key][$name]['cents'] += $cents ?? 0;
            if ($cents === 0) {
                $stats[$key][$name]['zeroPrice']++;
            }
        }
    }
}
