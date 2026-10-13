<?php

declare(strict_types=1);

namespace TransformerX\App\Http;

final class Request
{
    /** @var array<string, string> route parameters */
    public array $params = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        private readonly string $rawBody,
        public readonly array $files,
        public readonly string $ip,
        public readonly string $userAgent,
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rawurldecode($uri);
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !str_starts_with($headers['content-type'] ?? '', 'multipart/')
            ? (string) file_get_contents('php://input', false, null, 0, 1024 * 1024)
            : '';
        return new self(
            $method,
            '/' . ltrim($uri, '/'),
            $_GET,
            $headers,
            $body,
            $_FILES,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** JSON body; anything else on a write request is rejected (also blocks plain HTML form posts). */
    public function json(): array
    {
        if ($this->json === null) {
            if ($this->rawBody === '') {
                $this->json = [];
            } else {
                if (!str_starts_with($this->header('content-type') ?? '', 'application/json')) {
                    throw new HttpError(415, 'unsupported_media_type', 'Requests must be sent as JSON.');
                }
                try {
                    $data = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw HttpError::badRequest('The request body is not valid JSON.');
                }
                $this->json = is_array($data) ? $data : [];
            }
        }
        return $this->json;
    }

    public function str(string $key, int $maxLength = 255): string
    {
        $v = $this->json()[$key] ?? '';
        if (!is_string($v) && !is_int($v)) {
            return '';
        }
        return mb_substr(trim((string) $v), 0, $maxLength);
    }

    public function bool(string $key): ?bool
    {
        $v = $this->json()[$key] ?? null;
        return is_bool($v) ? $v : null;
    }

    public function queryInt(string $key, int $default, int $min, int $max): int
    {
        $v = $this->query[$key] ?? null;
        if (!is_string($v) || !preg_match('/^-?\d{1,9}$/', $v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }

    public function queryStr(string $key, int $maxLength = 100): string
    {
        $v = $this->query[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $maxLength) : '';
    }

    /** A YYYY-MM-DD query parameter, or null. */
    public function queryDate(string $key): ?string
    {
        $v = $this->queryStr($key, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
    }

    public function param(string $name): int
    {
        return (int) ($this->params[$name] ?? 0);
    }
}
