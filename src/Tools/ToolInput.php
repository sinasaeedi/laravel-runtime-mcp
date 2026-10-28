<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

/**
 * The validated arguments of one call (RunTool builds it after the rules passed). Only the keys
 * that rules() names reach the tool. 01-37 adds progress().
 */
final readonly class ToolInput
{
    /** @param  array<array-key, mixed>  $arguments */
    public function __construct(private array $arguments) {}

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
}
