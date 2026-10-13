<?php
// Builds tests/fixtures/input/edge_cases*.xlsx: rows that exercise every tricky branch of the
// Access logic (trimming, case, de-duplication, number-to-text, rounding, >18 attributes).
// After changing a case, regenerate the Access reference output with tests/tools/access_golden.ps1.
// Run: php tests/fixtures/make_edge_cases.php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

const HEADERS = [
    'Product Category', 'Name', 'Internal Reference', 'Product Description (Copy)', 'Length', 'Width', 'Depth',
    'Size Range', 'Quality', 'Clarity', 'Product Type', 'Sales Price', 'Unit', 'Shape', 'DIAMOND Shape', 'Species',
    'Cutting Style', 'Treatment', 'Colour 1', 'Colour 2', 'Colour 3', 'Market Term', 'Laboratory Name',
    'Certificate #', 'Average Weight', 'Origin', 'DIAMOND Clarity', 'DIAMOND Colour', 'DIAMOND Cut Grade',
    'DIAMOND Fluorescence', 'DIAMOND Polish', 'DIAMOND Species', 'DIAMOND Symmetry', 'Free Text (Jewellery)',
    'Jewellery GOLD Colour', 'Jewellery GOLD Kt', 'Forecasted Quantity', 'Pricing Description',
];

// Every row starts from this base; each case overrides only what it tests.
function base(string $ref): array
{
    return [
        'Product Category' => 'Gemstones', 'Name' => $ref, 'Internal Reference' => $ref,
        'Product Description (Copy)' => 'Edge case ' . $ref, 'Length' => 1, 'Width' => 1, 'Depth' => 1,
        'Product Type' => 'Single Stone', 'Sales Price' => 100, 'Unit' => 'Carats',
        'Average Weight' => 1, 'Forecasted Quantity' => 1,
    ];
}

$cases = [
    // ---- Regular price: IIf branch + case-insensitive 'Parcel' + rounding ----
    ['EC-P01', ['Product Type' => 'Parcel', 'Sales Price' => 1195, 'Average Weight' => 0.00625]],
    ['EC-P02', ['Product Type' => 'parcel', 'Sales Price' => 1175, 'Average Weight' => 0.007]],      // 8.225
    ['EC-P03', ['Product Type' => 'PARCEL', 'Sales Price' => 0.5, 'Average Weight' => 0.25]],        // 0.125
    ['EC-P04', ['Product Type' => ' Parcel', 'Sales Price' => 10, 'Average Weight' => 3, 'Forecasted Quantity' => 7]],
    ['EC-P05', ['Product Type' => 'Parcel ', 'Sales Price' => 10, 'Average Weight' => 3, 'Forecasted Quantity' => 7]],
    ['EC-P06', ['Sales Price' => 2.675, 'Forecasted Quantity' => 1]],
    ['EC-P07', ['Sales Price' => 1.005, 'Forecasted Quantity' => 1]],
    ['EC-P08', ['Sales Price' => 0.015, 'Forecasted Quantity' => 1]],
    ['EC-P09', ['Sales Price' => 0.025, 'Forecasted Quantity' => 1]],
    ['EC-P10', ['Sales Price' => 1234567.891, 'Forecasted Quantity' => 1]],
    ['EC-P11', ['Sales Price' => 5, 'Forecasted Quantity' => -1]],                                  // negative
    ['EC-P12', ['Sales Price' => 0.004, 'Forecasted Quantity' => -1]],                              // -0.004
    ['EC-P13', ['Sales Price' => 999.995, 'Forecasted Quantity' => 1]],
    ['EC-P14', ['Sales Price' => 0, 'Forecasted Quantity' => 1]],
    ['EC-P15', ['Product Type' => null, 'Sales Price' => 12.5, 'Forecasted Quantity' => 3]],         // null type -> else branch
    ['EC-P16', ['Sales Price' => 1499, 'Forecasted Quantity' => 2.35]],

    // ---- Size: VBA number-to-text ----
    ['EC-S01', ['Length' => 0.1 + 0.2, 'Width' => 1 / 3, 'Depth' => 2.0]],
    ['EC-S02', ['Length' => 0.00001, 'Width' => 0.0001, 'Depth' => 0.000001]],
    ['EC-S03', ['Length' => 123456789.123, 'Width' => 1e15, 'Depth' => 1e16]],
    ['EC-S04', ['Length' => -1.5, 'Width' => 0, 'Depth' => 10]],
    ['EC-S05', ['Length' => 1.23456789012345678, 'Width' => 100.10, 'Depth' => 7.0e-7]],
    ['EC-S06', ['Length' => 5, 'Width' => 4, 'Depth' => null]],                                     // null depth

    // ---- Categories ----
    ['EC-C01', ['Product Category' => 'gemstones', 'Colour 1' => 'Red', 'Colour 2' => 'Red', 'Colour 3' => ' Red ', 'Species' => 'Ruby', 'Cutting Style' => 'Oval Cut']],
    ['EC-C02', ['Product Category' => ' Gemstones ', 'Colour 1' => 'Red', 'Colour 2' => 'red', 'Colour 3' => null, 'Species' => 'Ruby ', 'Cutting Style' => '  ']],
    ['EC-C03', ['Product Category' => 'Gemstones', 'Colour 1' => null, 'Colour 2' => 'Blue', 'Colour 3' => 'Green', 'Species' => null, 'Cutting Style' => 'Cabochon']],
    ['EC-C04', ['Product Category' => 'Diamonds', 'DIAMOND Shape' => 'Round', 'Product Type' => 'Round', 'Sales Price' => 50]],
    ['EC-C05', ['Product Category' => 'DIAMONDS', 'DIAMOND Shape' => ' Pear ', 'Product Type' => 'Single Stone']],
    ['EC-C06', ['Product Category' => 'Diamonds', 'DIAMOND Shape' => null, 'Product Type' => null]],
    ['EC-C07', ['Product Category' => 'Jewellery', 'Product Type' => 'Ring', 'Unit' => 'Piece']],
    ['EC-C08', ['Product Category' => 'jewellery', 'Product Type' => null, 'Unit' => 'Piece']],
    ['EC-C09', ['Product Category' => 'Watches', 'Product Type' => 'Watch', 'Colour 1' => 'Gold']],
    ['EC-C10', ['Product Category' => null, 'Colour 1' => 'Gold']],
    ['EC-C11', ['Product Category' => 'Gemstones', 'Colour 1' => 'Green, Blue', 'Species' => 'Tourmaline > Paraiba']],

    // ---- Attributes ----
    ['EC-A01', [   // every attribute field filled: 21 candidates, only 18 slots
        'Size Range' => '1-2mm', 'Quality' => 'AAA', 'Clarity' => 'Eye Clean', 'Cutting Style' => 'Brilliant Cut',
        'Treatment' => 'Heated', 'Laboratory Name' => 'GIA', 'Certificate #' => 'C-1001', 'Origin' => 'Sri Lanka',
        'Species' => 'Sapphire', 'DIAMOND Clarity' => 'VS1', 'DIAMOND Colour' => 'G', 'DIAMOND Cut Grade' => 'Excellent',
        'DIAMOND Fluorescence' => 'None', 'DIAMOND Polish' => 'Excellent', 'DIAMOND Species' => 'Natural Diamond',
        'DIAMOND Symmetry' => 'Very Good', 'Free Text (Jewellery)' => 'Free text', 'Jewellery GOLD Colour' => 'Yellow',
        'Jewellery GOLD Kt' => '18KT', 'Pricing Description' => '$100/ct',
    ]],
    ['EC-A02', ['Quality' => '  AA  ', 'Clarity' => '   ', 'Treatment' => "\tHeated\t", 'Origin' => "Madagascar\u{00A0}"]],
    ['EC-A03', ['Certificate #' => 1234567890123, 'Size Range' => 0.5, 'Jewellery GOLD Kt' => 18]],   // numbers in text columns
    ['EC-A04', ['Species' => 'Emerald', 'Pricing Description' => '$11.0/pc Exact']],

    // ---- Text handling ----
    ['EC-T01', ['Name' => 'Café "Rosé" ×2 – 18ct', 'Product Description (Copy)' => "Line1, with comma; and 'quotes'"]],
    ['EC-T02', ['Name' => '=SUM(A1:A2)', 'Product Description (Copy)' => '+44 123', 'Species' => '@Ruby']],
    ['EC-T03', ['Name' => '0012', 'Internal Reference' => 'EC-T03']],
];

// Second file: probes for number precision, number-to-text conversion on import, and trailing spaces.
$cases2 = [
    // Numeric columns: how many significant digits survive import + export, and where E-notation starts.
    ['EC-N01', ['Length' => 0.123456789012345, 'Width' => 0.1234567890123456, 'Depth' => 123456789012345, 'Forecasted Quantity' => 1234567890.12345]],
    ['EC-N02', ['Length' => 1e14, 'Width' => 999999999999999, 'Depth' => 1.5e-10, 'Forecasted Quantity' => 12345.678901234567]],
    ['EC-N03', ['Length' => 0.1, 'Width' => 100, 'Depth' => 2.5e-3, 'Forecasted Quantity' => 0.0000123]],
    ['EC-N04', ['Length' => 99999999999999.9, 'Width' => 1e-15, 'Depth' => 1.0E+21, 'Forecasted Quantity' => 3]],
    ['EC-N05', ['Sales Price' => 0.1, 'Forecasted Quantity' => 3]],                                  // 0.30000000000000004
    ['EC-N06', ['Sales Price' => 1.15, 'Forecasted Quantity' => 3]],                                 // 3.4499999999999997
    ['EC-N07', ['Sales Price' => 4.35, 'Forecasted Quantity' => 100]],                               // 434.99999999999994
    ['EC-N08', ['Sales Price' => 0.005, 'Forecasted Quantity' => 1]],
    ['EC-N09', ['Sales Price' => 1e15, 'Forecasted Quantity' => 1]],
    ['EC-N10', ['Sales Price' => -1234.565, 'Forecasted Quantity' => 1]],
    // Numbers typed into text columns.
    ['EC-X01', ['Certificate #' => 12345678901, 'Size Range' => 123456789012, 'Quality' => 100000000000, 'Clarity' => 0.00001]],
    ['EC-X02', ['Certificate #' => 1.5e-7, 'Size Range' => -5, 'Quality' => 0.1 + 0.2, 'Clarity' => 1e20]],
    ['EC-X03', ['Certificate #' => 3.14159265358979, 'Size Range' => 2.5, 'Quality' => 1e-4, 'Clarity' => 99999999999]],
    // Numeric text in numeric columns.
    ['EC-X04', ['Sales Price' => '1195', 'Forecasted Quantity' => ' 2.5 ']],
    // Trailing / leading whitespace.
    ['EC-W01', ['Product Category' => 'Jewellery', 'Product Type' => 'Ring ', 'Unit' => 'Piece', 'Name' => 'Name trailing  ', 'Product Description (Copy)' => '  both sides  ']],
    ['EC-W02', ['Species' => 'Ruby  ', 'Cutting Style' => null, 'Colour 1' => null]],
    ['EC-W03', ['Product Category' => 'Watches ', 'Product Type' => 'Parcel  ', 'Sales Price' => 10, 'Average Weight' => 2, 'Forecasted Quantity' => 5]],
    ['EC-W04', ['Product Type' => "Parcel\t", 'Sales Price' => 10, 'Average Weight' => 2, 'Forecasted Quantity' => 5]],
    ['EC-W05', ['Cutting Style' => '   ', 'Colour 1' => "Blue\u{00A0}", 'Species' => "\u{00A0}Topaz"]],
    // Text that looks like a formula, stored as a plain string.
    ['EC-F01', ['Name' => '=SUM(A1:A2)', 'Product Description (Copy)' => '-minus', 'Species' => '+plus']],
];

// Third file: does FormatCurrency round once, or via Currency (4 dp) first? Plus remaining number-format corners.
$cases3 = [
    ['EC-R01', ['Sales Price' => 1, 'Forecasted Quantity' => 0.00495]],
    ['EC-R02', ['Sales Price' => 1, 'Forecasted Quantity' => 1.12449]],
    ['EC-R03', ['Sales Price' => 1, 'Forecasted Quantity' => 1.12451]],
    ['EC-R04', ['Sales Price' => 1, 'Forecasted Quantity' => 2.00004999]],
    ['EC-R05', ['Sales Price' => 1, 'Forecasted Quantity' => 0.004999999999]],
    ['EC-R06', ['Sales Price' => 1, 'Forecasted Quantity' => 7.0049999999999]],
    ['EC-R07', ['Length' => 1e-20, 'Width' => 1.5e15, 'Depth' => 1234567.000001]],
    ['EC-R08', ['Length' => 12345678901234.5, 'Width' => -0.25, 'Depth' => 1e-16]],
    ['EC-R09', ['Length' => -1e15, 'Width' => 1e100, 'Depth' => 4.56e-30]],
];

function writeFixture(string $out, array $cases, bool $extras): int
{
    $writer = new Writer();
    $writer->openToFile($out);
    $writer->addRow(Row::fromValues(HEADERS));
    $n = 0;
    foreach ($cases as [$ref, $overrides]) {
        $row = array_merge(base($ref), $overrides);
        $writer->addRow(fixtureRow($row));
        $n++;
        if ($extras && $ref === 'EC-S03') {
            // A fully blank row in the middle of the data.
            $writer->addRow(fixtureRow([]));
        }
    }
    if ($extras) {
        // A numeric Internal Reference (Excel number cell in a text column).
        $writer->addRow(fixtureRow(array_merge(base('x'), ['Internal Reference' => 100045, 'Name' => 100045])));
        $n++;
    }
    $writer->close();
    return $n;
}

// Strings are always written as text cells, never as formulas (OpenSpout treats "=..." as a formula otherwise).
function fixtureRow(array $row): Row
{
    $cells = [];
    foreach (HEADERS as $h) {
        $v = $row[$h] ?? null;
        $cells[] = match (true) {
            $v === null => new OpenSpout\Common\Entity\Cell\EmptyCell(null, null),
            is_string($v) => new OpenSpout\Common\Entity\Cell\StringCell($v, null),
            default => new OpenSpout\Common\Entity\Cell\NumericCell($v, null),
        };
    }
    return new Row($cells);
}

foreach ([['edge_cases.xlsx', $cases, true], ['edge_cases_2.xlsx', $cases2, false], ['edge_cases_3.xlsx', $cases3, false]] as [$file, $list, $extras]) {
    $n = writeFixture(__DIR__ . '/input/' . $file, $list, $extras);
    echo "Wrote $n data rows to $file\n";
}
