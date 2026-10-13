<?php

declare(strict_types=1);

namespace TransformerX\Engine;

/** One validation finding. Errors block conversion; warnings are shown in the preview. */
final class Issue implements \JsonSerializable
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $row = null,
        public readonly ?string $column = null,
    ) {
    }

    public static function error(string $code, string $message, ?int $row = null, ?string $column = null): self
    {
        return new self(self::ERROR, $code, $message, $row, $column);
    }

    public static function warning(string $code, string $message, ?int $row = null, ?string $column = null): self
    {
        return new self(self::WARNING, $code, $message, $row, $column);
    }

    public function jsonSerialize(): array
    {
        return ['severity' => $this->severity, 'code' => $this->code, 'message' => $this->message, 'row' => $this->row, 'column' => $this->column];
    }
}
