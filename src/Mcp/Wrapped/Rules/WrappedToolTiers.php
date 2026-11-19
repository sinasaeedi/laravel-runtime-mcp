<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTools;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** Spec 5.10, 5.16, rule 10 (01-38): each entry's tier is read or read_sensitive; write_safe waits for v1.13.0. */
final class WrappedToolTiers implements BootRule
{
    public const ID = WrappedTools::TIER;

    public function __construct(private readonly WrappedTools $tools) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        return $this->tools->issues(self::ID);
    }
}
