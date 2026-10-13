<?php
// Command-line conversion, mainly for parity checks against Access.
// Usage: php bin/convert.php input.xlsx output.xlsx [--force]
//   --force  convert even when there are row errors, the way Access would (bad cells become empty).

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TransformerX\Engine\Analyzer;
use TransformerX\Engine\Exporter;
use TransformerX\Engine\InputReader;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$in, $out] = [$argv[1] ?? null, $argv[2] ?? null];
$force = in_array('--force', $argv, true);
if ($in === null || $out === null) {
    fwrite(STDERR, "Usage: php bin/convert.php input.xlsx output.xlsx [--force]\n");
    exit(1);
}

$t0 = microtime(true);
$read = (new InputReader())->read($in);
$analysis = (new Analyzer())->analyse($read['rows'], $read['issues'], $read['blankRows']);
$s = $analysis['summary'];

foreach ($analysis['fileIssues'] as $i) {
    fwrite(STDERR, strtoupper($i->severity) . ": {$i->message}\n");
}
printf("rows=%d blank-skipped=%d ok=%d warning=%d error=%d\n", $s['rows'], $s['blankRowsSkipped'], $s['rowsByOk'], $s['rowsByWarning'], $s['rowsByError']);
foreach ($s['errorsByCode'] as $code => $n) {
    echo "  error   $code: $n\n";
}
foreach ($s['warningsByCode'] as $code => $n) {
    echo "  warning $code: $n\n";
}

if (!$s['canConvert'] && !($force && $s['rows'] > 0 && $analysis['fileIssues'] === [])) {
    fwrite(STDERR, "Not converted: fix the errors above (or use --force for a parity check).\n");
    exit(2);
}

$result = (new Exporter())->export($analysis['rows'], $out);
printf("wrote %d rows to %s in %.0f ms\n", $result['rows'], $out, (microtime(true) - $t0) * 1000);
