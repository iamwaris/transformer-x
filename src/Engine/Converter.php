<?php

declare(strict_types=1);

namespace TransformerX\Engine;

/**
 * Turns one stored input row into one output row, reproducing qryExportData, BuildCategories
 * and BuildAttributesTable exactly. Also explains each calculation for the preview.
 */
final class Converter
{
    /** Columns whose value is the constant "" in qryExportData; Access writes no cell for them. */
    private const BLANK_COLUMNS = [
        'ID', 'GTIN, UPC, EAN, or ISBN', 'Purchase note', 'Sale price', 'Tags', 'Shipping Class', 'Images',
        'Download limit', 'Download expiry days', 'Parent', 'Grouped products', 'Upsells', 'Cross-sells',
        'External URL', 'Button text', 'Position', 'Brands',
    ];

    /**
     * @param array<string, string|float|null> $v input row as stored by InputReader
     * @return array{
     *   cells: list<string|int|float|null>,
     *   price: array{formula: string, a: ?float, b: ?float, aLabel: string, bLabel: string, raw: ?float, text: ?string, cents: ?int},
     *   size: string,
     *   categories: list<string>,
     *   attributes: list<array{name: string, value: string}>,
     *   droppedAttributes: list<string>,
     *   warnings: list<array{code: string, message: string}>
     * }
     */
    public function convert(array $v): array
    {
        $size = self::size($v);
        $price = self::price($v);
        $categories = self::categories($v);
        [$attributes, $dropped] = self::attributes($v, $size);

        $out = [];
        foreach (self::BLANK_COLUMNS as $c) {
            $out[$c] = null;
        }
        $out['Type'] = 'Simple';
        $out['SKU'] = $v['Internal Reference'];
        $out['Name'] = $v['Name'];
        $out['Published'] = '1';
        $out['Is featured?'] = 0;
        $out['visibility in catalog'] = 'visible';
        $out['Short description'] = $v['Product Description (Copy)'];
        $out['Length'] = $v['Length'];
        $out['Width'] = $v['Width'];
        $out['Height'] = $v['Depth'];
        $out['Allow customer reviews?'] = 0;
        $out['Regular price'] = $price['text'];
        $out['Categories'] = $categories === [] ? null : implode(', ', $categories);
        foreach (['Colour 1', 'Colour 2', 'Colour 3', 'Cutting Style', 'Shape', 'DIAMOND Shape', 'Product Type', 'Species'] as $c) {
            $out[$c] = $v[$c];
        }
        $out['Stock'] = $v['Forecasted Quantity'];
        for ($i = 1; $i <= Schema::ATTRIBUTE_SLOTS; $i++) {
            $a = $attributes[$i - 1] ?? null;
            $out["Attribute $i name"] = $a['name'] ?? null;
            $out["Attribute $i value(s)"] = $a['value'] ?? null;
            $out["Attribute $i visible"] = $a === null ? null : 1;
            $out["Attribute $i global"] = $a === null ? null : 1;
        }

        $cells = [];
        foreach (Schema::outputColumns() as $c) {
            $cells[] = self::exportCell($out[$c]);
        }

        return [
            'cells' => $cells,
            'price' => $price,
            'size' => $size,
            'categories' => $categories,
            'attributes' => $attributes,
            'droppedAttributes' => $dropped,
            'warnings' => self::warnings($v, $price, $dropped),
        ];
    }

    /** How the Excel export writes a value: numbers via the Double formatter, text without trailing spaces, "" as no cell. */
    public static function exportCell(string|int|float|null $value): string|int|float|null
    {
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            $n = (float) AccessFormat::numberToText($value);
            // Whole numbers go out as ints: PHP's float-to-text (precision=14) would cut 123456789012345 short.
            return ($n == floor($n) && abs($n) < 9.0e15) ? (int) $n : $n;
        }
        $text = AccessFormat::exportText($value);
        return $text === '' ? null : $text;
    }

    /** Calculated field: [Length] & "x" & [Width] & "x" & [Depth] & " mm" (Null concatenates as ""). */
    public static function size(array $v): string
    {
        $part = static fn (?float $n): string => $n === null ? '' : AccessFormat::numberToText($n);
        return $part($v['Length']) . 'x' . $part($v['Width']) . 'x' . $part($v['Depth']) . ' mm';
    }

    /**
     * FormatCurrency(IIf([Product Type]='Parcel',[Sales Price]*[Average Weight],[Sales Price]*[Forecasted Quantity]),2)
     */
    public static function price(array $v): array
    {
        $parcel = AccessFormat::jetEquals($v['Product Type'], 'Parcel');
        $a = $v['Sales Price'];
        $b = $parcel ? $v['Average Weight'] : $v['Forecasted Quantity'];
        $raw = ($a === null || $b === null) ? null : $a * $b;
        $text = $raw === null ? null : AccessFormat::formatCurrency($raw);
        return [
            'formula' => $parcel ? 'parcel' : 'quantity',
            'a' => $a,
            'b' => $b,
            'aLabel' => 'Sales Price',
            'bLabel' => $parcel ? 'Average Weight' : 'Forecasted Quantity',
            'raw' => $raw,
            'text' => $text,
            'cents' => $text === null ? null : self::cents($text),
        ];
    }

    /** BuildCategories([Product Category],[Cutting Style],[DIAMOND Shape],[Species],[Product Type],[Colour 1..3]). */
    public static function categories(array $v): array
    {
        $top = AccessFormat::vbaTrim($v['Product Category'] ?? '');
        if ($top === '') {
            return [];
        }
        // Scripting.Dictionary: insertion order, case-sensitive keys. Prefix keys so numeric strings stay strings.
        $keys = ['k' . $top => $top];
        $add = static function (string $path) use (&$keys): void {
            $keys['k' . $path] = $path;
        };
        $nonBlank = static fn (?string $s): bool => AccessFormat::vbaTrim($s ?? '') !== '';

        switch (AccessFormat::lcase($top)) {
            case 'gemstones':
                foreach (['Colour 1', 'Colour 2', 'Colour 3'] as $c) {
                    $colour = AccessFormat::vbaTrim($v[$c] ?? '');   // AddPath trims the colour
                    if ($colour !== '') {
                        $add("$top > Colour > $colour");
                    }
                }
                if ($nonBlank($v['Species'])) {
                    $add("$top > Species > " . $v['Species']);         // not trimmed, as in the VBA
                }
                if ($nonBlank($v['Cutting Style'])) {
                    $add("$top > Cutting Style > " . $v['Cutting Style']);
                }
                break;
            case 'diamonds':
                if ($nonBlank($v['DIAMOND Shape'])) {
                    $add("$top > " . $v['DIAMOND Shape']);
                }
                if ($nonBlank($v['Product Type'])) {
                    $add("$top > " . $v['Product Type']);
                }
                break;
            case 'jewellery':
                if ($nonBlank($v['Product Type'])) {
                    $add("$top > " . $v['Product Type']);
                }
                break;
        }
        return array_values($keys);
    }

    /**
     * BuildAttributesTable / AddAttr: first 18 non-blank fields, values trimmed, Species renamed to Gemstone.
     *
     * @return array{0: list<array{name: string, value: string}>, 1: list<string>} attributes, and fields dropped for lack of slots
     */
    public static function attributes(array $v, string $size): array
    {
        $attributes = [];
        $dropped = [];
        foreach (Schema::ATTRIBUTE_FIELDS as $field) {
            $raw = $field === 'Size' ? $size : ($v[$field] ?? '');
            $value = AccessFormat::vbaTrim((string) $raw);
            if ($value === '') {
                continue;
            }
            if (count($attributes) >= Schema::ATTRIBUTE_SLOTS) {
                $dropped[] = $field;
                continue;
            }
            $attributes[] = ['name' => Schema::ATTRIBUTE_RENAMES[$field] ?? $field, 'value' => $value];
        }
        return [$attributes, $dropped];
    }

    /** "$1,195.00" / "($5.00)" -> signed cents. */
    public static function cents(string $currency): int
    {
        $negative = str_starts_with($currency, '(');
        $digits = preg_replace('/[^0-9]/', '', $currency);
        $n = (int) $digits;
        return $negative ? -$n : $n;
    }

    /** @return list<array{code: string, message: string}> */
    private static function warnings(array $v, array $price, array $dropped): array
    {
        $w = [];
        if ($price['text'] === null) {
            $missing = $price['a'] === null ? 'Sales Price' : $price['bLabel'];
            $w[] = ['code' => 'blank_price', 'message' => "Regular price will be empty because \"$missing\" is empty."];
        } elseif ($price['cents'] === 0) {
            $zeroInput = $price['a'] == 0.0 ? 'Sales Price' : ($price['b'] == 0.0 ? $price['bLabel'] : null);
            $w[] = ['code' => 'zero_price', 'message' => $zeroInput !== null
                ? "Regular price is \$0.00 because \"$zeroInput\" is 0."
                : 'Regular price rounds to $0.00.'];
        } elseif ($price['cents'] < 0) {
            $w[] = ['code' => 'negative_price', 'message' => 'Regular price is negative.'];
        }
        if ($price['formula'] === 'quantity' && $price['b'] !== null && $price['b'] != 1.0
            && AccessFormat::jetEquals($v['Unit'], 'Piece')) {
            $w[] = ['code' => 'price_times_quantity', 'message' => 'Priced per piece, so the Sales Price is multiplied by a Forecasted Quantity of ' . AccessFormat::numberToText($price['b']) . '.'];
        }
        if ($dropped !== []) {
            $w[] = ['code' => 'attributes_dropped', 'message' => 'Only 18 attributes fit. Left out: ' . implode(', ', $dropped) . '.'];
        }
        $top = AccessFormat::lcase(AccessFormat::vbaTrim($v['Product Category'] ?? ''));
        if ($top === '') {
            $w[] = ['code' => 'no_category', 'message' => 'Product Category is empty, so the product gets no category.'];
        } elseif (!in_array($top, ['gemstones', 'diamonds', 'jewellery'], true)) {
            $w[] = ['code' => 'unknown_category', 'message' => "Product Category \"{$v['Product Category']}\" is not Gemstones, Diamonds or Jewellery, so only the top-level category is set."];
        }
        return $w;
    }
}
