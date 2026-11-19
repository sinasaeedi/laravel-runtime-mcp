<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTools;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** Spec 4.3, 5.16 (01-38): each entry's name is lower snake case of at most 64 characters (McpToolAdapter::NAME_PATTERN). */
final class WrappedToolNames implements BootRule
{
    public const ID = WrappedTools::NAME;

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
