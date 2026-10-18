<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

use JsonException;

/**
 * Spec 4.4: what the agent receives. Exactly five keys, in this order. The shape is public API;
 * a change is a major version (spec 10.1). EnvelopeSchema (src/Mcp/) declares it as outputSchema.
 */
final readonly class ResultEnvelope
{
    /**
     * The one encoding of the envelope (01-17 measures with the same flags). An invalid UTF-8 byte
     * becomes U+FFFD: the Redactor keeps such bytes, and laravel/mcp's Response::structured() would
     * throw on them.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct(
        public string $environment,
        public Status $status,
        public ?string $note,
        public int|false $truncated,
        public mixed $data,
    ) {}

    /** $environment is Environment::name() (spec 4.6). */
    public static function fromResult(ToolResult $result, string $environment): self
    {
        return new self($environment, $result->status, $result->note, $result->truncated, $result->data);
    }

    /** @return array{environment: string, status: string, note: ?string, truncated: int|false, data: mixed} */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'status' => $this->status->value,
            'note' => $this->note,
            'truncated' => $this->truncated,
            'data' => $this->data,
        ];
    }

    /** @throws JsonException when the data cannot be JSON (INF, NAN, a resource) */
    public function toJson(): string
    {
        return json_encode($this->toArray(), self::JSON_FLAGS | JSON_THROW_ON_ERROR);
    }
}
