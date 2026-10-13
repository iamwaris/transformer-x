<?php

declare(strict_types=1);

namespace TransformerX\App;

/** Reads config/config.php (created by bin/install.php; never web-reachable). */
final class Config
{
    private static ?array $values = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$values === null) {
            $file = self::root() . '/config/config.php';
            if (!is_file($file)) {
                throw new \RuntimeException('config/config.php is missing. Run: php bin/install.php');
            }
            self::$values = require $file;
        }
        $node = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function storage(string $sub): string
    {
        $dir = self::root() . '/storage/' . $sub;
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir;
    }
}
