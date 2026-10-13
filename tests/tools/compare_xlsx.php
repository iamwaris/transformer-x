<?php
// Usage: php tests/tools/compare_xlsx.php expected.xlsx actual.xlsx
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$diffs = TransformerX\Tests\Tools\XlsxCells::diff($argv[1], $argv[2], 200);
if ($diffs === []) {
    echo "IDENTICAL\n";
    exit(0);
}
echo count($diffs), " difference(s):\n  ", implode("\n  ", $diffs), "\n";
exit(1);
