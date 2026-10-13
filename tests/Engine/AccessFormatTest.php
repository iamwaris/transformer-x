<?php

declare(strict_types=1);

namespace TransformerX\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TransformerX\Engine\AccessFormat;

/** Every expected value below was produced by the real Access application (edge_cases*.xlsx runs). */
final class AccessFormatTest extends TestCase
{
    public static function numberToTextCases(): array
    {
        return [
            [1.0, '1'], [0.0, '0'], [0.8, '0.8'], [1.15, '1.15'], [-1.5, '-1.5'], [10.0, '10'],
            [0.1 + 0.2, '0.3'], [1 / 3, '0.33333333333333'], [2.0, '2'],
            [0.00001, '0.00001'], [0.0001, '0.0001'], [0.000001, '0.000001'], [7.0e-7, '0.0000007'],
            [123456789.123, '123456789.123'], [1e15, '1E+15'], [1e16, '1E+16'],
            [1.23456789012345678, '1.2345678901235'], [100.10, '100.1'],
            [0.123456789012345, '0.12345678901234'], [0.1234567890123456, '0.12345678901235'],
            [123456789012345.0, '123456789012345'], [1234567890.12345, '1234567890.1235'],
            [1e14, '100000000000000'], [999999999999999.0, '999999999999999'], [1.5e-10, '0.00000000015'],
            [12345.678901234567, '12345.678901235'], [0.0025, '0.0025'], [0.0000123, '0.0000123'],
            [99999999999999.9, '100000000000000'], [1e-15, '0.000000000000001'], [1.0E+21, '1E+21'],
            [1e-20, '1E-20'], [1.5e15, '1.5E+15'], [1234567.000001, '1234567.000001'],
            [12345678901234.5, '12345678901234'], [-0.25, '-0.25'], [1e-16, '1E-16'],
            [-1e15, '-1E+15'], [1e100, '1E+100'], [4.56e-30, '4.56E-30'],
            [2.35, '2.35'], [0.00495, '0.00495'], [2.00004999, '2.00004999'], [0.004999999999, '0.004999999999'],
        ];
    }

    #[DataProvider('numberToTextCases')]
    public function testNumberToText(float $in, string $expected): void
    {
        self::assertSame($expected, AccessFormat::numberToText($in));
    }

    public static function importNumberToTextCases(): array
    {
        return [
            [1234567890123.0, '1.2345678901e+12'], [0.5, '0.5'], [18.0, '18'], [100045.0, '100045'],
            [12345678901.0, '12345678901'], [123456789012.0, '1.2345678901e+11'], [100000000000.0, '1e+11'],
            [0.00001, '1e-05'], [1.5e-7, '1.5e-07'], [-5.0, '-5'], [0.1 + 0.2, '0.3'], [1e20, '1e+20'],
            [3.14159265358979, '3.1415926536'], [2.5, '2.5'], [1e-4, '0.0001'], [99999999999.0, '99999999999'],
        ];
    }

    #[DataProvider('importNumberToTextCases')]
    public function testImportNumberToText(float $in, string $expected): void
    {
        self::assertSame($expected, AccessFormat::importNumberToText($in));
    }

    public static function currencyCases(): array
    {
        return [
            [1195 * 0.00625, '$7.47'], [1175 * 0.007, '$8.23'], [0.5 * 0.25, '$0.13'], [10 * 7.0, '$70.00'],
            [10 * 3.0, '$30.00'], [2.675 * 1, '$2.68'], [1.005 * 1, '$1.01'], [0.015 * 1, '$0.02'],
            [0.025 * 1, '$0.03'], [1234567.891 * 1, '$1,234,567.89'], [5 * -1.0, '($5.00)'],
            [0.004 * -1.0, '$0.00'], [999.995 * 1, '$1,000.00'], [0.0, '$0.00'], [12.5 * 3, '$37.50'],
            [1499 * 2.35, '$3,522.65'], [1234567890.12345 * 100, '$123,456,789,012.35'],
            [100 * 12345.678901234567, '$1,234,567.89'], [100 * 0.0000123, '$0.00'], [100 * 3.0, '$300.00'],
            [0.1 * 3, '$0.30'], [1.15 * 3, '$3.45'], [4.35 * 100, '$435.00'], [0.005 * 1, '$0.01'],
            [1e15 * 1, '$1,000,000,000,000,000.00'], [-1234.565 * 1, '($1,234.57)'],
            [1 * 0.00495, '$0.00'], [1 * 1.12449, '$1.12'], [1 * 1.12451, '$1.12'], [1 * 2.00004999, '$2.00'],
            [1 * 0.004999999999, '$0.00'], [1 * 7.0049999999999, '$7.00'], [10 * 2.0, '$20.00'], [10 * 5.0, '$50.00'],
        ];
    }

    #[DataProvider('currencyCases')]
    public function testFormatCurrency(float $in, string $expected): void
    {
        self::assertSame($expected, AccessFormat::formatCurrency($in));
    }

    public function testTrimsAndComparisons(): void
    {
        self::assertSame("\tHeated\t", AccessFormat::vbaTrim("  \tHeated\t "));
        self::assertSame("Madagascar\u{00A0}", AccessFormat::vbaTrim("Madagascar\u{00A0}"));
        self::assertSame('  both sides', AccessFormat::exportText('  both sides  '));
        self::assertTrue(AccessFormat::jetEquals('parcel', 'Parcel'));
        self::assertTrue(AccessFormat::jetEquals('Parcel  ', 'Parcel'));
        self::assertFalse(AccessFormat::jetEquals(' Parcel', 'Parcel'));
        self::assertFalse(AccessFormat::jetEquals("Parcel\t", 'Parcel'));
        self::assertFalse(AccessFormat::jetEquals(null, 'Parcel'));
    }
}
