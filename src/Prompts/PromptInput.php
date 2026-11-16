<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Prompts;

/**
 * The context plus the validated, redacted argument values of one prompts/get. An optional argument that
 * the client left out, or sent as "" or null, is absent: get() gives $default.
 */
final readonly class PromptInput
{
    /** @param  array<string, string>  $values */
    public function __construct(
        public PromptContext $context,
        private array $values,
    ) {}

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->values[$name] ?? $default;
    }
}
