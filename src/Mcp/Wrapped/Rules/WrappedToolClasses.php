<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Wrapped\Rules;

use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTools;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** Spec 5.16 (01-38): each entry's class is a concrete laravel/mcp Tool with a handle() method, and not one of laravel/mcp's or ours. */
final class WrappedToolClasses implements BootRule
{
    public const ID = WrappedTools::CLASS_RULE;

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
