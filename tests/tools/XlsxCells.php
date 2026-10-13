<?php

declare(strict_types=1);

namespace TransformerX\Tests\Tools;

/**
 * Reads the first sheet of an .xlsx straight from its XML, keeping each cell's real type:
 * text => string, number => float, empty/absent => null. Used to compare our output with Access's.
 */
final class XlsxCells
{
    /** @return array{sheet: string, rows: list<list<string|float|bool|null>>} */
    public static function read(string $file): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException("Cannot open $file");
        }
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($ss)->si as $si) {
                $shared[] = (string) dom_import_simplexml($si)->textContent;
            }
        }
        $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $sheetName = (string) $wb->sheets->sheet[0]['name'];
        $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $rid = (string) $wb->sheets->sheet[0]->attributes('r', true)['id'];
        $target = 'xl/worksheets/sheet1.xml';
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $t = ltrim((string) $rel['Target'], '/');
                $target = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
            }
        }
        $sheet = simplexml_load_string($zip->getFromName($target));
        $zip->close();

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $r = (int) $row['r'] - 1;
            $cells = [];
            foreach ($row->c as $c) {
                $col = self::columnIndex(preg_replace('/\d+/', '', (string) $c['r']));
                $t = (string) $c['t'];
                $cells[$col] = match ($t) {
                    'inlineStr' => (string) dom_import_simplexml($c->is)->textContent,
                    's' => $shared[(int) $c->v],
                    'str' => (string) $c->v,
                    'b' => (string) $c->v === '1',
                    default => isset($c->v) && (string) $c->v !== '' ? (float) (string) $c->v : null,
                };
            }
            $max = $cells === [] ? -1 : max(array_keys($cells));
            $list = [];
            for ($i = 0; $i <= $max; $i++) {
                $v = $cells[$i] ?? null;
                $list[] = $v === '' ? null : $v;
            }
            $rows[$r] = $list;
        }
        ksort($rows);
        return ['sheet' => $sheetName, 'rows' => array_values($rows)];
    }

    /**
     * Compares two workbooks: sheet name, header row, and every cell of every row matched by SKU.
     *
     * @return list<string> human-readable differences (empty = identical)
     */
    public static function diff(string $expectedFile, string $actualFile, int $limit = 50): array
    {
        $e = self::read($expectedFile);
        $a = self::read($actualFile);
        $diffs = [];
        if ($e['sheet'] !== $a['sheet']) {
            $diffs[] = "sheet name: expected \"{$e['sheet']}\", got \"{$a['sheet']}\"";
        }
        $header = $e['rows'][0] ?? [];
        if (($a['rows'][0] ?? []) !== $header) {
            $diffs[] = 'header row differs: ' . json_encode(array_values(array_diff_assoc($a['rows'][0] ?? [], $header)));
            return $diffs;
        }
        $skuCol = array_search('SKU', $header, true);
        $index = static function (array $rows) use ($skuCol): array {
            $out = [];
            foreach (array_slice($rows, 1) as $row) {
                $out[(string) ($row[$skuCol] ?? '')][] = $row;
            }
            return $out;
        };
        $ei = $index($e['rows']);
        $ai = $index($a['rows']);
        if (count($e['rows']) !== count($a['rows'])) {
            $diffs[] = sprintf('row count: expected %d, got %d', count($e['rows']) - 1, count($a['rows']) - 1);
        }
        foreach ($ei as $sku => $eRows) {
            if (!isset($ai[$sku])) {
                $diffs[] = "SKU \"$sku\" missing from output";
                continue;
            }
            foreach ($eRows as $k => $er) {
                $ar = $ai[$sku][$k] ?? [];
                foreach ($header as $col => $name) {
                    $ev = $er[$col] ?? null;
                    $av = $ar[$col] ?? null;
                    if (!self::same($ev, $av)) {
                        $diffs[] = sprintf('SKU "%s" / %s: expected %s, got %s', $sku, $name, self::show($ev), self::show($av));
                        if (count($diffs) >= $limit) {
                            return $diffs;
                        }
                    }
                }
            }
        }
        foreach (array_diff_key($ai, $ei) as $sku => $_) {
            $diffs[] = "unexpected SKU \"$sku\" in output";
        }
        return $diffs;
    }

    private static function same(mixed $e, mixed $a): bool
    {
        if (is_float($e) && is_float($a)) {
            return $e === $a;
        }
        return $e === $a;
    }

    private static function show(mixed $v): string
    {
        return match (true) {
            $v === null => '(empty)',
            is_float($v) => 'number ' . var_export($v, true),
            is_bool($v) => 'boolean ' . ($v ? 'TRUE' : 'FALSE'),
            default => 'text ' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
