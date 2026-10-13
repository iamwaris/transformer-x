<?php

declare(strict_types=1);

namespace TransformerX\App\Auth;

final class Passwords
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 128;

    private const COMMON = [
        'password', 'password1', 'password12', 'password123', '1234567890', '0123456789', 'qwertyuiop',
        'letmein123', 'welcome123', 'administrator', 'changeme123', 'iloveyou12', 'transformer', 'transformerx',
    ];

    /** @return ?string why the password is not acceptable, or null */
    public static function problem(string $password, string $username): ?string
    {
        $len = mb_strlen($password);
        if ($len < self::MIN_LENGTH) {
            return 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }
        if ($len > self::MAX_LENGTH) {
            return 'Use at most ' . self::MAX_LENGTH . ' characters.';
        }
        $lower = mb_strtolower($password);
        if ($username !== '' && str_contains($lower, mb_strtolower($username))) {
            return 'The password must not contain the username.';
        }
        if (in_array($lower, self::COMMON, true) || count(array_unique(mb_str_split($lower))) < 4) {
            return 'This password is too easy to guess. Try a short phrase of several words.';
        }
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** A readable one-time password for new accounts and resets, e.g. "kite-Mars-7421-oak". */
    public static function temporary(): string
    {
        $words = ['amber', 'basalt', 'beryl', 'coral', 'garnet', 'jade', 'jasper', 'onyx', 'opal', 'pearl', 'quartz', 'ruby', 'topaz', 'zircon', 'agate', 'spinel'];
        $w = static fn () => $words[random_int(0, count($words) - 1)];
        return ucfirst($w()) . '-' . $w() . '-' . random_int(1000, 9999) . '-' . $w();
    }
}
