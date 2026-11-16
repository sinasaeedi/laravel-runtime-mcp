<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

/**
 * The validated arguments of one call (RunTool builds it after the rules passed). Only the keys
 * that rules() names reach the tool. progress() is how a long tool reports how far it has got (01-37,
 * spec 5.15); without a progressToken it is a NullProgress, so a tool can always call it.
 */
final readonly class ToolInput
{
    /** @param  array<array-key, mixed>  $arguments */
    public function __construct(
        private array $arguments,
        private Progress $progress = new NullProgress,
    ) {}

    /** @return array<array-key, mixed> */
    public function all(): array
    {
        return $this->arguments;
    }

    /** An argument sent as null gives $default too (`??`); all() keeps the null. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->arguments[$key] ?? $default;
    }

    public function progress(): Progress
    {
        return $this->progress;
    }
}
