<?php

declare(strict_types=1);

namespace TransformerX\App\Http;

/** An error whose message is safe to show the user. Anything else becomes a generic 500. */
final class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'bad_request', $message, $details);
    }

    public static function unauthorized(string $message = 'Please sign in.'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }

    public static function validation(array $fields, string $message = 'Some fields need attention.'): self
    {
        return new self(422, 'validation', $message, ['fields' => $fields]);
    }
}
