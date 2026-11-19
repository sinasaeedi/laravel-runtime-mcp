<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTools;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** Spec 5.11, 5.16 (01-38): runtime-mcp.wrapped_tools is a list, and each entry an array with exactly class, name, tier and open_world. */
final class WrappedToolEntries implements BootRule
{
    public const ID = WrappedTools::ENTRY;

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
