<?php

declare(strict_types=1);

namespace TransformerX\Engine;

/**
 * Re-implements the text/number behaviour of Access, VBA and the Access Excel import/export,
 * as measured against the real application (see tests/fixtures/golden_*.xlsx).
 */
final class AccessFormat
{
    /**
     * Number -> text the way the Access expression service and the Excel export write a Double
     * (used for the Size calculated field and for numeric output cells).
     *
     *   whole numbers below 1E+15      -> all digits          123456789012345
     *   1E+15 and above, below 1E-15   -> E-notation, 14 sig  1.5E+15, 1E-16
     *   everything else                -> 14 significant digits, plain decimals  0.33333333333333
     */
    public static function numberToText(float $v): string
    {
        if ($v == 0.0) {
            return '0';
        }
        $abs = abs($v);
        if ($abs >= 1e15 || $abs < 1e-15) {
            [$digits, $exp] = self::significantDigits($v, 14);
            return self::scientific($v < 0, $digits, $exp, 'E');
        }
        if ($v == floor($v)) {
            return sprintf('%.0f', $v);
        }
        [$digits, $exp] = self::significantDigits($v, 14);
        return ($v < 0 ? '-' : '') . self::plain($digits, $exp);
    }

    /**
     * Number -> text the way the Access Excel import stores a numeric cell in a Text column.
     * This is exactly C's printf("%.11g"): 1e-05, 1.2345678901e+11, 0.3, 100045.
     */
    public static function importNumberToText(float $v): string
    {
        if ($v == 0.0) {
            return '0';
        }
        [$digits, $exp] = self::significantDigits($v, 11);
        if ($exp < -4 || $exp >= 11) {
            return self::scientific($v < 0, $digits, $exp, 'e');
        }
        return ($v < 0 ? '-' : '') . self::plain($digits, $exp);
    }

    /**
     * VBA FormatCurrency(x, 2) on an en-US machine: "$1,195.00", negatives "($5.00)".
     * The Double is taken to 15 significant digits first, then rounded half away from zero to cents
     * (so 2.675 -> $2.68 and 1.005 -> $1.01, as Access does). A negative that rounds to zero is "$0.00".
     */
    public static function formatCurrency(float $v): string
    {
        if ($v == 0.0) {
            return '$0.00';
        }
        [$digits, $exp] = self::significantDigits($v, 15);
        $decimal = self::plain($digits, $exp);
        [$int, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $frac = str_pad($frac, 3, '0');
        $cents = $int . substr($frac, 0, 2);
        if ($frac[2] >= '5') {
            $cents = self::incrementDigits($cents);
        }
        $cents = ltrim($cents, '0');
        if ($cents === '') {
            return '$0.00';
        }
        $cents = str_pad($cents, 3, '0', STR_PAD_LEFT);
        $intPart = substr($cents, 0, -2);
        $text = '$' . self::groupThousands($intPart) . '.' . substr($cents, -2);
        return $v < 0 ? '(' . $text . ')' : $text;
    }

    /** VBA Trim(): removes spaces (U+0020) only. Tabs and non-breaking spaces stay. */
    public static function vbaTrim(string $s): string
    {
        return trim($s, ' ');
    }

    /** The Excel export drops trailing spaces from every text cell. */
    public static function exportText(string $s): string
    {
        return rtrim($s, ' ');
    }

    /**
     * Jet SQL text equality (WHERE a = b, JOIN ON a = b): case-insensitive and blind to trailing spaces.
     */
    public static function jetKey(string $s): string
    {
        return mb_strtolower(rtrim($s, ' '), 'UTF-8');
    }

    public static function jetEquals(?string $a, string $b): bool
    {
        return $a !== null && self::jetKey($a) === self::jetKey($b);
    }

    /** VBA LCase(). */
    public static function lcase(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Rounds |v| to $precision significant digits (correctly rounded from the binary value).
     *
     * @return array{0: string, 1: int} digits without trailing zeros, and the decimal exponent of the first digit
     */
    private static function significantDigits(float $v, int $precision): array
    {
        $s = sprintf('%.' . ($precision - 1) . 'e', abs($v));
        if (!preg_match('/^(\d)(?:\.(\d+))?e([+-]\d+)$/', $s, $m)) {
            throw new \LogicException("Unexpected number format: $s");
        }
        $digits = rtrim($m[1] . ($m[2] ?? ''), '0');
        return [$digits === '' ? '0' : $digits, (int) $m[3]];
    }

    /** Digits + exponent -> plain decimal text without trailing zeros (no sign). */
    private static function plain(string $digits, int $exp): string
    {
        if ($exp < 0) {
            return '0.' . str_repeat('0', -$exp - 1) . $digits;
        }
        $intLen = $exp + 1;
        if (strlen($digits) <= $intLen) {
            return str_pad($digits, $intLen, '0');
        }
        return substr($digits, 0, $intLen) . '.' . substr($digits, $intLen);
    }

    private static function scientific(bool $negative, string $digits, int $exp, string $e): string
    {
        $mantissa = $digits[0] . (strlen($digits) > 1 ? '.' . substr($digits, 1) : '');
        return ($negative ? '-' : '') . $mantissa . $e . ($exp < 0 ? '-' : '+') . str_pad((string) abs($exp), 2, '0', STR_PAD_LEFT);
    }

    /** Adds one to a non-negative integer held as a digit string. */
    private static function incrementDigits(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0 && $digits[$i] === '9') {
            $digits[$i] = '0';
            $i--;
        }
        return $i < 0 ? '1' . $digits : substr_replace($digits, (string) ((int) $digits[$i] + 1), $i, 1);
    }

    private static function groupThousands(string $int): string
    {
        $int = ltrim($int, '0');
        if ($int === '') {
            return '0';
        }
        $out = '';
        while (strlen($int) > 3) {
            $out = ',' . substr($int, -3) . $out;
            $int = substr($int, 0, -3);
        }
        return $int . $out;
    }
}
