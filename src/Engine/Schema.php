<?php

declare(strict_types=1);

namespace TransformerX\Engine;

/**
 * Column definitions shared by the reader, converter and writer.
 * Mirrors the Access tables tempImportData / tmpProductAttributes and the query qryExportData.
 */
final class Schema
{
    /** Bump when conversion output could change, so stored statistics stay comparable. */
    public const ENGINE_VERSION = '1.0.0';

    /** Sheet name Access gives the exported workbook (it names it after the query). */
    public const OUTPUT_SHEET = 'qryExportData';

    /** Access Text(255) limit on every text column of tempImportData. */
    public const MAX_TEXT_LENGTH = 255;

    /** The 38 columns of the upload sheet, in the order of the reference file. */
    public const INPUT_COLUMNS = [
        'Product Category', 'Name', 'Internal Reference', 'Product Description (Copy)', 'Length', 'Width', 'Depth',
        'Size Range', 'Quality', 'Clarity', 'Product Type', 'Sales Price', 'Unit', 'Shape', 'DIAMOND Shape', 'Species',
        'Cutting Style', 'Treatment', 'Colour 1', 'Colour 2', 'Colour 3', 'Market Term', 'Laboratory Name',
        'Certificate #', 'Average Weight', 'Origin', 'DIAMOND Clarity', 'DIAMOND Colour', 'DIAMOND Cut Grade',
        'DIAMOND Fluorescence', 'DIAMOND Polish', 'DIAMOND Species', 'DIAMOND Symmetry', 'Free Text (Jewellery)',
        'Jewellery GOLD Colour', 'Jewellery GOLD Kt', 'Forecasted Quantity', 'Pricing Description',
    ];

    /** Double columns in tempImportData; every other input column is Text(255). */
    public const NUMERIC_COLUMNS = ['Length', 'Width', 'Depth', 'Sales Price', 'Average Weight', 'Forecasted Quantity'];

    /**
     * Fields BuildAttributesTable tries, in order. 'Size' is the calculated field
     * [Length] & "x" & [Width] & "x" & [Depth] & " mm".
     */
    public const ATTRIBUTE_FIELDS = [
        'Size Range', 'Quality', 'Clarity', 'Cutting Style', 'Treatment', 'Laboratory Name', 'Certificate #', 'Origin',
        'Species', 'DIAMOND Clarity', 'DIAMOND Colour', 'DIAMOND Cut Grade', 'DIAMOND Fluorescence', 'DIAMOND Polish',
        'DIAMOND Species', 'DIAMOND Symmetry', 'Free Text (Jewellery)', 'Jewellery GOLD Colour', 'Jewellery GOLD Kt',
        'Size', 'Pricing Description',
    ];

    /** AddAttr renames this field when it becomes an attribute. */
    public const ATTRIBUTE_RENAMES = ['Species' => 'Gemstone'];

    public const ATTRIBUTE_SLOTS = 18;

    /** @return list<string> the 111 output headers, in qryExportData order. */
    public static function outputColumns(): array
    {
        static $cols = null;
        if ($cols !== null) {
            return $cols;
        }
        $cols = [
            'ID', 'Type', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Name', 'Published', 'Is featured?', 'visibility in catalog',
            'Short description', 'Length', 'Width', 'Height', 'Allow customer reviews?', 'Purchase note', 'Sale price',
            'Regular price', 'Categories', 'Colour 1', 'Colour 2', 'Colour 3', 'Cutting Style', 'Shape', 'DIAMOND Shape',
            'Product Type', 'Species', 'Tags', 'Shipping Class', 'Images', 'Download limit', 'Download expiry days',
            'Parent', 'Grouped products', 'Upsells', 'Cross-sells', 'External URL', 'Button text', 'Position', 'Brands',
            'Stock',
        ];
        for ($i = 1; $i <= self::ATTRIBUTE_SLOTS; $i++) {
            array_push($cols, "Attribute $i name", "Attribute $i value(s)", "Attribute $i visible", "Attribute $i global");
        }
        return $cols;
    }

    public static function isNumericColumn(string $column): bool
    {
        return in_array($column, self::NUMERIC_COLUMNS, true);
    }
}
