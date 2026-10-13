<?php
// Dumps the first sheet of an .xlsx as raw cells: "<ref> <t-attr> <value>" (one line per non-empty cell).
// Usage: php tests/tools/dump_xlsx.php file.xlsx [maxRows] [--raw-row=N]

declare(strict_types=1);

$file = $argv[1] ?? '';
$max = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : PHP_INT_MAX;
$rawRow = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--raw-row=')) {
        $rawRow = (int) substr($a, 10);
    }
}

$zip = new ZipArchive();
if ($zip->open($file) !== true) {
    fwrite(STDERR, "cannot open $file\n");
    exit(1);
}
$shared = [];
if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
    $x = simplexml_load_string($ss);
    foreach ($x->si as $si) {
        $shared[] = (string) dom_import_simplexml($si)->textContent;
    }
}
$sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));

if (in_array('--summary', $argv, true)) {
    // One line per data row: "Header=value" for every non-constant cell; numbers marked with #.
    $skip = ['Type', 'Published', 'Is featured?', 'visibility in catalog', 'Allow customer reviews?'];
    $headers = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = preg_replace('/\d+/', '', (string) $c['r']);
            $t = (string) $c['t'];
            $v = $t === 'inlineStr' ? (string) dom_import_simplexml($c->is)->textContent
                : ($t === 's' ? $shared[(int) $c->v] : (isset($c->v) ? '#' . $c->v : '<empty>'));
            $cells[$col] = $v;
        }
        if ((int) $row['r'] === 1) {
            $headers = $cells;
            continue;
        }
        $parts = [];
        foreach ($cells as $col => $v) {
            $h = $headers[$col] ?? $col;
            if (in_array($h, $skip, true) || preg_match('/^Attribute \d+ (visible|global)$/', $h)) {
                if (!in_array($v, ['#0', '#1', 'Simple', '1', 'visible'], true)) {
                    $parts[] = "!!$h=$v";
                }
                continue;
            }
            $h = preg_replace('/^Attribute (\d+) (name|value\(s\))$/', 'A$1$2', $h);
            $parts[] = "$h=" . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        echo implode(' | ', $parts), "\n";
    }
    exit;
}

$n = 0;
foreach ($sheet->sheetData->row as $row) {
    $r = (int) $row['r'];
    if ($rawRow !== null) {
        if ($r === $rawRow) {
            echo $row->asXML(), "\n";
        }
        continue;
    }
    if (++$n > $max) {
        break;
    }
    echo "--- row $r\n";
    foreach ($row->c as $c) {
        $t = (string) $c['t'];
        if ($t === 'inlineStr') {
            $v = (string) dom_import_simplexml($c->is)->textContent;
        } elseif ($t === 's') {
            $v = $shared[(int) $c->v];
        } else {
            $v = isset($c->v) ? (string) $c->v : null;
        }
        if ($v === null) {
            echo "  {$c['r']} [$t] <no value>\n";
            continue;
        }
        echo "  {$c['r']} [", ($t === '' ? 'n' : $t), "] ", json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
}
