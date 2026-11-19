<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTools;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** Spec 5.16, rule 19 (01-38): no entry's class (or a parent class) has #[RendersApp]: MCP Apps are not supported. */
final class WrappedToolRendersApp implements BootRule
{
    public const ID = WrappedTools::RENDERS_APP;

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
