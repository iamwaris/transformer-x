<?php

declare(strict_types=1);

namespace TransformerX\App;

/** Admin-editable settings (settings table), with validated ranges. */
final class Settings
{
    /** name => [default, min, max, label] */
    public const DEFINITIONS = [
        'max_upload_mb' => [20, 1, 40, 'Largest upload, in MB'],
        'max_rows' => [20000, 100, 100000, 'Most product rows per file'],
        'preview_expiry_hours' => [24, 1, 168, 'Hours before an unconverted preview is discarded'],
        'session_idle_minutes' => [30, 5, 480, 'Minutes of inactivity before sign-out'],
        'lockout_attempts' => [5, 3, 20, 'Wrong passwords before an account locks'],
        'lockout_minutes' => [15, 1, 1440, 'Minutes an account stays locked'],
    ];

    private static ?array $cache = null;

    public static function int(string $name): int
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Db::all('SELECT name, value FROM settings') as $row) {
                    self::$cache[$row['name']] = $row['value'];
                }
            } catch (\Throwable) {
                // fall back to defaults if the table is unavailable
            }
        }
        [$default, $min, $max] = self::DEFINITIONS[$name];
        $v = isset(self::$cache[$name]) && ctype_digit((string) self::$cache[$name]) ? (int) self::$cache[$name] : $default;
        return max($min, min($max, $v));
    }

    /** @return array<string, array{value: int, min: int, max: int, label: string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $name => [$default, $min, $max, $label]) {
            $out[$name] = ['value' => self::int($name), 'min' => $min, 'max' => $max, 'label' => $label];
        }
        return $out;
    }

    public static function set(string $name, int $value, int $userId): void
    {
        Db::exec(
            'INSERT INTO settings (name, value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)',
            [$name, (string) $value, $userId],
        );
        self::$cache = null;
    }
}
