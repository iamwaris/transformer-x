<?php

declare(strict_types=1);

namespace TransformerX\App;

/** Server-side error log (storage/logs). Details never reach the browser. */
final class Log
{
    public static function error(string $message, ?\Throwable $e = null, array $context = []): string
    {
        $ref = bin2hex(random_bytes(4));
        $line = sprintf(
            "[%s] [%s] %s%s%s\n",
            date('c'),
            $ref,
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES),
            $e === null ? '' : "\n  " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n  " . str_replace("\n", "\n  ", $e->getTraceAsString()),
        );
        @file_put_contents(Config::storage('logs') . '/app-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
        return $ref;
    }
}
