<?php

declare(strict_types=1);

namespace TransformerX\Engine;

/** Converts stored rows and writes the output workbook, collecting the conversion statistics. */
final class Exporter
{
    public function __construct(private readonly Converter $converter = new Converter())
    {
    }

    /**
     * @param iterable<array{row: int, v: array<string, string|float|null>}> $rows
     * @return array{rows: int, stats: array}
     */
    public function export(iterable $rows, string $path): array
    {
        $stats = Analyzer::emptyStats();
        $writer = new OutputWriter($path);
        try {
            foreach ($rows as $r) {
                $ref = $r['v']['Internal Reference'];
                if ($ref === null || AccessFormat::vbaTrim($ref) === '') {
                    continue;  // the Access INNER JOIN drops these (uploads with them are blocked anyway)
                }
                $c = $this->converter->convert($r['v']);
                Analyzer::accumulate($stats, $r['v'], $c);
                $stats['rows']++;
                $writer->add($c['cells']);
            }
        } finally {
            $written = $writer->close();
        }
        return ['rows' => $written, 'stats' => $stats];
    }
}
