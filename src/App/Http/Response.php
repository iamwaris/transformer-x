<?php

declare(strict_types=1);

namespace TransformerX\App\Http;

final class Response
{
    /** @param null|callable(): void $stream writes the body itself (file downloads) */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        private readonly mixed $stream = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    public static function error(HttpError $e): self
    {
        return self::json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->details], $e->status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * Streams a file as a download, then deletes it if asked. Nothing is kept on the server.
     */
    public static function download(string $path, string $filename, bool $deleteAfter): self
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self(200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
            'Content-Length' => (string) filesize($path),
            'X-Filename' => $safeName,
        ], '', static function () use ($path, $deleteAfter): void {
            try {
                readfile($path);
            } finally {
                if ($deleteAfter) {
                    @unlink($path);
                }
            }
        });
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
